<?php
declare(strict_types=1);

namespace ListenIn\Import;

use ListenIn\Core\ApiException;
use ListenIn\Core\Database;
use ListenIn\Domain\FrequencyFields;
use ListenIn\Domain\FrequencyRepository;
use ListenIn\Domain\Normalizer;
use ListenIn\Domain\RevisionService;
use ListenIn\Domain\Taxonomy;

/**
 * Importazione a passi brevi, pensata per hosting condivisi: ogni chiamata a step() lavora al massimo
 * per qualche secondo e salva lo stato su disco, così l'operazione può essere guidata da una pagina web
 * (o dalla riga di comando) senza superare i limiti di tempo del server, e può essere ripresa.
 *
 * Fasi:  map  → lettura e armonizzazione delle righe (RecordMapper)
 *        cluster → deduplicazione interna al file (Deduplicator)
 *        write → confronto con il database, fusione e scrittura dei record
 *        finish → segnalazioni dei possibili duplicati e report finale
 *
 * Con la simulazione (dry_run) tutto viene calcolato ma ogni passo di scrittura è annullato.
 */
final class ImportEngine
{
    public const EXTENSIONS = ['csv', 'xlsx', 'json', 'html', 'htm', 'txt', 'tsv'];

    // ------------------------------------------------------------------ file e cartelle
    public static function baseDir(): string
    {
        return self::ensure(LISTENIN_ROOT . '/storage/import');
    }

    public static function inboxDir(): string
    {
        return self::ensure(self::baseDir() . '/inbox');
    }

    public static function dataDir(): string
    {
        return LISTENIN_ROOT . '/data';
    }

    private static function jobDir(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            throw ApiException::notFound('Operazione di importazione non trovata.');
        }
        return self::ensure(self::baseDir() . '/jobs/' . $id);
    }

    private static function ensure(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossibile creare la cartella ' . $dir . ': controllare i permessi di scrittura su app/storage.');
        }
        return $dir;
    }

    /** File disponibili: dati inclusi nel pacchetto (app/data) e file caricati (app/storage/import/inbox). */
    public static function listFiles(): array
    {
        $out = [];
        foreach (['incluso' => self::dataDir(), 'caricato' => self::inboxDir()] as $kind => $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (!is_file($f) || !in_array($ext, self::EXTENSIONS, true) || preg_match('/^(frequenze_da_rivedere|comuni_centroidi|geo_province)/', basename($f))) {
                    continue;
                }
                $out[] = ['kind' => $kind, 'name' => basename($f), 'size' => filesize($f), 'modified' => date('Y-m-d H:i', (int) filemtime($f)),
                    'rows' => $ext === 'csv' ? self::countCsvRows($f) : null];
            }
        }
        return $out;
    }

    public static function resolveFile(string $kind, string $name): string
    {
        $dir = match ($kind) {
            'incluso' => self::dataDir(),
            'caricato' => self::inboxDir(),
            default => throw ApiException::validation(['file' => 'Origine del file non valida.']),
        };
        if ($name !== basename($name) || !preg_match('/^[\w.\- ]{1,120}$/u', $name)
            || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            throw ApiException::validation(['file' => 'Nome del file non valido.']);
        }
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            throw ApiException::notFound('File non trovato.');
        }
        return $path;
    }

    /** Righe di dati di un CSV (intestazioni escluse, celle su più righe comprese), con piccola cache. */
    public static function countCsvRows(string $path): int
    {
        $cacheFile = self::ensure(LISTENIN_ROOT . '/storage/cache') . '/rowcounts.json';
        $cache = is_file($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];
        $key = md5($path) . '-' . filesize($path) . '-' . filemtime($path);
        if (isset($cache[$key])) {
            return (int) $cache[$key];
        }
        $n = 0;
        foreach (SourceReader::read($path, 'csv') as $_) {
            $n++;
        }
        $cache[$key] = $n;
        @file_put_contents($cacheFile, json_encode(array_slice($cache, -50, null, true)), LOCK_EX);
        return $n;
    }

    // ------------------------------------------------------------------ stato
    public static function load(string $id): array
    {
        $f = self::jobDir($id) . '/state.json';
        if (!is_file($f)) {
            throw ApiException::notFound('Operazione di importazione non trovata.');
        }
        return json_decode((string) file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function save(array $st): void
    {
        $st['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents(self::jobDir($st['id']) . '/state.json', json_encode($st, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
    }

    public static function recentJobs(int $n = 8): array
    {
        $jobs = [];
        foreach (glob(self::baseDir() . '/jobs/*/state.json') ?: [] as $f) {
            $st = json_decode((string) file_get_contents($f), true);
            if (is_array($st)) {
                $jobs[] = self::publicView($st);
            }
        }
        usort($jobs, static fn($a, $b) => strcmp((string) $b['started_at'], (string) $a['started_at']));
        return array_slice($jobs, 0, $n);
    }

    /** Rappresentazione per l'interfaccia (senza i dati interni). */
    public static function publicView(array $st): array
    {
        $total = max(1, (int) ($st['total_rows'] ?: 1));
        $progress = match ($st['phase']) {
            'map' => 0.55 * min(1, $st['row_offset'] / $total),
            'cluster' => 0.57,
            'write' => 0.6 + 0.38 * ($st['clusters_total'] ? $st['clusters_done'] / $st['clusters_total'] : 1),
            'finish' => 0.99,
            default => 1.0,
        };
        return [
            'id' => $st['id'], 'status' => $st['status'], 'phase' => $st['phase'], 'progress' => round($progress, 3),
            'dry_run' => $st['dry_run'], 'source' => $st['source'], 'file' => $st['file'], 'message' => $st['message'],
            'started_at' => $st['started_at'], 'stats' => $st['stats'],
            'rows' => ['read' => $st['row_offset'], 'total' => $st['total_rows']],
            'clusters' => ['done' => $st['clusters_done'], 'total' => $st['clusters_total']],
            'report' => $st['report'] ?? null,
            'has_duplicates_csv' => is_file(self::jobDir($st['id']) . '/duplicati.csv'),
        ];
    }

    // ------------------------------------------------------------------ avvio
    /**
     * @param array{path:string,file?:string,source?:string,dry_run?:bool,update?:bool,limit?:?int,columns?:array,format?:?string} $o
     */
    public static function start(array $o): array
    {
        $id = bin2hex(random_bytes(8));
        $path = $o['path'];
        $format = $o['format'] ?? null;
        $ext = strtolower($format ?? pathinfo($path, PATHINFO_EXTENSION));
        $source = (string) ($o['source'] ?? 'esterna');
        $dry = !empty($o['dry_run']);
        $runId = Database::insert('INSERT INTO listenin_import_runs (source, input_name, dry_run) VALUES (:s, :n, :d)',
            ['s' => $source, 'n' => mb_substr((string) ($o['file'] ?? basename($path)), 0, 255), 'd' => $dry ? 1 : 0]);
        self::jobDir($id);
        $st = [
            'id' => $id, 'status' => 'running', 'phase' => 'map', 'message' => 'Lettura del file…',
            'path' => $path, 'file' => (string) ($o['file'] ?? basename($path)), 'format' => $format,
            'source' => $source, 'dry_run' => $dry, 'update' => $o['update'] ?? true, 'limit' => $o['limit'] ?? null,
            'columns' => $o['columns'] ?? [], 'run_id' => $runId,
            'row_offset' => 0, 'total_rows' => $ext === 'csv' ? self::countCsvRows($path) : null,
            'clusters_total' => 0, 'clusters_done' => 0, 'cluster_pos' => 0,
            'probable' => [], 'db_probable' => [], 'skipped' => [], 'errors' => [], 'unclassified' => [], 'by_service' => [],
            'stats' => [
                'righe_lette' => 0, 'righe_scartate' => 0, 'righe_unificate' => 0, 'record_risultanti' => 0,
                'inseriti' => 0, 'aggiornati' => 0, 'invariati' => 0, 'completati' => 0, 'protetti' => 0,
                'collegati_a_esistenti' => 0, 'rimossi_dai_moderatori' => 0, 'possibili_duplicati' => 0, 'errori' => 0,
            ],
            'started_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($st['limit'] !== null && $st['total_rows'] !== null) {
            $st['total_rows'] = min($st['total_rows'], (int) $st['limit']);
        }
        self::save($st);
        return $st;
    }

    // ------------------------------------------------------------------ avanzamento
    public static function step(string $id, float $budget = 15.0): array
    {
        $st = self::load($id);
        if ($st['status'] !== 'running') {
            return $st;
        }
        @set_time_limit((int) ceil($budget) + 30);
        $t0 = microtime(true);
        try {
            self::prepareLookups($st);
            do {
                match ($st['phase']) {
                    'map' => self::stepMap($st, $t0, $budget),
                    'cluster' => self::stepCluster($st),
                    'write' => self::stepWrite($st, $t0, $budget),
                    'finish' => self::stepFinish($st),
                    default => null,
                };
            } while ($st['status'] === 'running' && $st['phase'] !== 'write' && $st['phase'] !== 'map' && microtime(true) - $t0 < $budget);
        } catch (\Throwable $e) {
            $st['status'] = 'error';
            $st['message'] = 'Errore: ' . $e->getMessage();
            error_log('[listenin-import] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
        self::save($st);
        return $st;
    }

    public static function cancel(string $id): array
    {
        $st = self::load($id);
        if ($st['status'] === 'running') {
            $st['status'] = 'cancelled';
            $st['message'] = 'Operazione annullata.';
            self::save($st);
        }
        return $st;
    }

    private static function prepareLookups(array $st): void
    {
        LabelNormalizer::setProperNouns(array_merge(
            array_column(Taxonomy::get('provinces'), 'name'),
            array_column(Taxonomy::get('regions'), 'name')
        ));
    }

    // ------------------------------------------------------------------ fase 1: lettura e armonizzazione
    private static function stepMap(array &$st, float $t0, float $budget): void
    {
        $dir = self::jobDir($st['id']);
        $mapper = new RecordMapper(null, $st['columns']);
        $bound = false;
        $buffer = '';
        $i = 0;
        $finished = true;
        foreach (SourceReader::read($st['path'], $st['format']) as $row) {
            if (!$bound) {
                $st['columns'] = $mapper->bindHeaders(array_keys($row));
                $bound = true;
            }
            if ($i++ < $st['row_offset']) {
                continue;
            }
            if ($st['limit'] !== null && $st['row_offset'] >= (int) $st['limit']) {
                break;
            }
            $line = $st['row_offset'] + 2;
            $st['row_offset']++;
            $st['stats']['righe_lette']++;
            $m = $mapper->map($row);
            if (isset($m['error'])) {
                self::skip($st, $line, $m['error']);
            } else {
                if ($m['unclassified'] && (count($st['unclassified']) < 1500 || isset($st['unclassified'][$m['logical']['label']]))) {
                    $l = $m['logical']['label'];
                    $st['unclassified'][$l] = ($st['unclassified'][$l] ?? 0) + 1;
                }
                $buffer .= json_encode(['line' => $line, 'ref' => $m['ref'], 'raw_hash' => $m['raw_hash'], 'raw_label' => $m['raw_label'],
                    'logical' => $m['logical']], JSON_UNESCAPED_UNICODE) . "\n";
            }
            if ($st['row_offset'] % 20 === 0 && microtime(true) - $t0 > $budget) {
                $finished = false;
                break;
            }
        }
        if ($buffer !== '') {
            file_put_contents($dir . '/records.jsonl', $buffer, FILE_APPEND | LOCK_EX);
        }
        if ($finished) {
            $st['phase'] = 'cluster';
            $st['message'] = 'Righe lette: ' . $st['stats']['righe_lette'] . '. Ricerca dei duplicati…';
        } else {
            $st['message'] = 'Lettura e armonizzazione: ' . $st['row_offset'] . ($st['total_rows'] ? ' di ' . $st['total_rows'] : '') . ' righe…';
        }
    }

    // ------------------------------------------------------------------ fase 2: deduplicazione nel file
    private static function stepCluster(array &$st): void
    {
        $dir = self::jobDir($st['id']);
        $dedup = new Deduplicator();

        // 1. Passaggio sul file dei record: in memoria restano solo i profili di confronto e la posizione di ogni riga
        $profiles = [];
        $offsets = [];
        $seen = [];
        $fh = is_file($dir . '/records.jsonl') ? fopen($dir . '/records.jsonl', 'rb') : null;
        while ($fh) {
            $pos = ftell($fh);
            $ln = fgets($fh);
            if ($ln === false) {
                break;
            }
            $r = json_decode($ln, true);
            if (!is_array($r)) {
                continue;
            }
            if (isset($seen[$r['ref']])) {
                self::skip($st, (int) $r['line'], 'riga ripetuta con lo stesso identificativo ' . $r['ref']);
                continue;
            }
            $seen[$r['ref']] = true;
            $profiles[$r['line']] = $dedup->profile($r['logical']);
            $offsets[$r['line']] = $pos;
        }
        unset($seen);
        $result = $dedup->clusterProfiles($profiles);
        unset($profiles);

        $load = static function (int $line) use ($fh, $offsets): array {
            fseek($fh, $offsets[$line]);
            return json_decode((string) fgets($fh), true);
        };

        // 2. Scrittura dei raggruppamenti; le righe singole vengono copiate così come sono, senza ridecodificarle
        $csv = fopen($dir . '/duplicati.csv', 'wb');
        fwrite($csv, "\xEF\xBB\xBF");
        fputcsv($csv, ['esito', 'frequenza_mhz', 'id_a', 'originale_a', 'armonizzata_a', 'regione_a', 'province_a',
            'id_b', 'originale_b', 'armonizzata_b', 'regione_b', 'province_b', 'somiglianza', 'motivo'], ';', '"', '');
        $out = fopen($dir . '/clusters.jsonl', 'wb');
        $total = 0;
        foreach ($result['clusters'] as $members) {
            $total++;
            if (count($members) === 1) {
                fseek($fh, $offsets[$members[0]]);
                fwrite($out, '[' . rtrim((string) fgets($fh), "\r\n") . "]\n");
                continue;
            }
            $recs = [];
            foreach ($members as $m) {
                $recs[$m] = $load($m);
            }
            $merged = $dedup->merge($recs, $members);
            foreach ($members as $m) {
                if ($m !== $merged['primary']) {
                    fputcsv($csv, self::csvPair('unificato', $recs[$merged['primary']], $recs[$m], null, 'stessa area, descrizione equivalente'), ';', '"', '');
                }
            }
            fwrite($out, json_encode(array_values($recs), JSON_UNESCAPED_UNICODE) . "\n");
        }
        fclose($out);
        foreach ($result['probable'] as [$a, $b, $sim, $why]) {
            $ra = $load($a);
            $rb = $load($b);
            fputcsv($csv, self::csvPair('da verificare', $ra, $rb, $sim, $why), ';', '"', '');
            $st['probable'][] = [$ra['ref'], $rb['ref'], $sim, $why];
        }
        fclose($csv);
        if ($fh) {
            fclose($fh);
        }

        $read = count($offsets);
        $st['stats']['record_risultanti'] = $total;
        $st['stats']['righe_unificate'] = $read - $total;
        $st['clusters_total'] = $total;
        $st['phase'] = $total > 0 ? 'write' : 'finish';
        $st['message'] = sprintf('%d righe → %d record (%d unificate). Scrittura nel database…', $read, $total, $read - $total);
        @unlink($dir . '/records.jsonl');
    }

    private static function csvPair(string $outcome, array $a, array $b, ?float $sim, string $why): array
    {
        $la = $a['logical'];
        $lb = $b['logical'];
        return [$outcome, Normalizer::formatMHz((int) $la['freq_hz']),
            $a['ref'], $a['raw_label'], $la['label'], $la['region'], $la['coverage'],
            $b['ref'], $b['raw_label'], $lb['label'], $lb['region'], $lb['coverage'],
            $sim !== null ? (int) round($sim * 100) . '%' : '', $why];
    }

    // ------------------------------------------------------------------ fase 3: scrittura
    private static function stepWrite(array &$st, float $t0, float $budget): void
    {
        $dir = self::jobDir($st['id']);
        $fh = fopen($dir . '/clusters.jsonl', 'rb');
        fseek($fh, (int) $st['cluster_pos']);
        $dedup = new Deduplicator();
        $dbProbable = [];

        Database::begin();
        try {
            $n = 0;
            while (($ln = fgets($fh)) !== false) {
                $members = json_decode($ln, true);
                if (is_array($members) && $members) {
                    self::writeCluster($st, $members, $dedup, $dbProbable);
                }
                $st['cluster_pos'] = ftell($fh);
                $st['clusters_done']++;
                if (++$n % 10 === 0 && microtime(true) - $t0 > $budget) {
                    break;
                }
            }
            $st['dry_run'] ? Database::rollback() : Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            fclose($fh);
            throw $e;
        }
        $reachedEnd = feof($fh) || $st['clusters_done'] >= $st['clusters_total'];
        fclose($fh);
        foreach ($dbProbable as $p) {
            $st['db_probable'][] = $p;
        }
        $st['message'] = sprintf('Scrittura: %d di %d record…', $st['clusters_done'], $st['clusters_total']);
        if ($reachedEnd) {
            $st['phase'] = 'finish';
        }
    }

    /** Scrive (o aggiorna) il record risultante da un cluster di righe sorgente. */
    private static function writeCluster(array &$st, array $recs, Deduplicator $dedup, array &$dbProbable): void
    {
        $records = [];
        foreach ($recs as $r) {
            $records[$r['line']] = $r;
        }
        $members = array_keys($records);
        $merged = count($members) > 1 ? $dedup->merge($records, $members) : ['logical' => $records[$members[0]]['logical'], 'primary' => $members[0]];
        $refs = array_map(static fn($m) => $records[$m]['ref'], $members);
        $comment = 'Import ' . $st['source'] . (count($members) > 1
            ? ': unificati ' . count($members) . ' record della fonte (' . implode(', ', array_slice($refs, 0, 8)) . ')' : '');
        $source = $st['source'];

        try {
            Database::transaction(function () use (&$st, $records, $members, $merged, $refs, $comment, $dedup, $source, &$dbProbable) {
                $logical = FrequencyFields::validate($merged['logical'], false);
                $knownId = self::knownFrequency($source, $refs);
                $attached = false;
                if ($knownId !== null) {
                    $fid = self::updateKnown($st, $knownId, $logical, $records, $members, $comment);
                } else {
                    $profile = $dedup->profile($logical);
                    $match = null;
                    $probable = [];
                    foreach (self::existingProfiles($dedup, $profile['hz']) as [$id, $p]) {
                        [$verdict, $sim, $why] = $dedup->compare($profile, $p);
                        if ($verdict === Deduplicator::CERTAIN) {
                            $match = $id;
                            break;
                        }
                        if ($verdict === Deduplicator::PROBABLE) {
                            $probable[] = [$id, $sim, $why];
                        }
                    }
                    if ($match !== null) {
                        $fid = $match;
                        $attached = true;
                        self::fillGaps($st, $fid, $logical, $comment . ' (collegato a un record esistente)');
                        $st['stats']['collegati_a_esistenti']++;
                    } else {
                        $res = RevisionService::propose(null, 'create', null, $logical, $comment, 'import', true, null, null,
                            ['source' => $source === 'esterna' ? 'esterna' : 'import']);
                        $fid = (int) $res['frequency_id'];
                        $st['stats']['inseriti']++;
                        $st['by_service'][$logical['service']] = ($st['by_service'][$logical['service']] ?? 0) + 1;
                        foreach ($probable as [$oldId, $sim, $why]) {
                            $dbProbable[] = [$fid, $oldId, $sim, $why . ' (rispetto a un record già presente)'];
                        }
                    }
                }
                foreach ($members as $m) {
                    $r = $records[$m];
                    Database::run(
                        'INSERT INTO listenin_frequency_sources (frequency_id, source, source_ref, raw_hash, raw_label, merged)
                         VALUES (:f, :s, :r, :h, :l, :m)
                         ON DUPLICATE KEY UPDATE frequency_id = VALUES(frequency_id), raw_hash = VALUES(raw_hash),
                             raw_label = VALUES(raw_label), merged = VALUES(merged), last_seen_at = NOW()',
                        ['f' => $fid, 's' => $source, 'r' => $r['ref'], 'h' => $r['raw_hash'], 'l' => $r['raw_label'],
                            'm' => ($m !== $merged['primary'] || $attached) ? 1 : 0]
                    );
                }
            });
        } catch (ApiException $e) {
            $st['stats']['errori']++;
            $fields = $e->details['fields'] ?? [];
            if (count($st['errors']) < 300) {
                $st['errors'][] = 'riga ' . $records[$members[0]]['line'] . ': ' . $e->getMessage() . ($fields ? ' ' . json_encode($fields, JSON_UNESCAPED_UNICODE) : '');
            }
        }
    }

    private static function updateKnown(array &$st, int $fid, array $logical, array $records, array $members, string $comment): int
    {
        $row = FrequencyRepository::find($fid, true);
        if ($row === null) {
            return $fid;
        }
        if ($row['deleted_at'] !== null) {
            $st['stats']['rimossi_dai_moderatori']++;   // rimosso da un moderatore: non viene ricreato
            return $fid;
        }
        $stored = array_column(Database::all(
            'SELECT source_ref, raw_hash FROM listenin_frequency_sources WHERE frequency_id = :f AND source = :s',
            ['f' => $fid, 's' => $st['source']]
        ), 'raw_hash', 'source_ref');
        $changed = false;
        foreach ($members as $m) {
            if (($stored[$records[$m]['ref']] ?? null) !== $records[$m]['raw_hash']) {
                $changed = true;
            }
        }
        if (!$changed || !$st['update']) {
            $st['stats']['invariati']++;
            return $fid;
        }
        if (self::touchedByCommunity($fid)) {
            $st['stats']['protetti']++;
            self::fillGaps($st, $fid, $logical, $comment . ' (solo campi vuoti: record curato dalla comunità)');
            return $fid;
        }
        $diff = FrequencyFields::validate($logical, true, FrequencyRepository::logical($row));
        if (!$diff) {
            $st['stats']['invariati']++;
            return $fid;
        }
        RevisionService::propose(null, 'update', $fid, $diff, 'Aggiornamento ' . $comment, 'import', true);
        $st['stats']['aggiornati']++;
        return $fid;
    }

    /** Completa i soli campi vuoti di un record esistente, senza sovrascrivere nulla. */
    private static function fillGaps(array &$st, int $fid, array $logical, string $comment): void
    {
        $row = FrequencyRepository::find($fid, true);
        if ($row === null || $row['deleted_at'] !== null) {
            return;
        }
        $current = FrequencyRepository::logical($row);
        $gaps = [];
        foreach ($logical as $field => $value) {
            if ($value === null || in_array($field, ['status', 'label', 'service', 'mode', 'tone_type', 'ctcss_hz', 'dcs_code', 'coverage', 'last_heard_at'], true)) {
                continue;
            }
            if (($current[$field] ?? null) === null) {
                $gaps[$field] = $value;
            }
        }
        if (($current['tone_type'] ?? 'none') === 'none' && ($logical['tone_type'] ?? 'none') !== 'none') {
            $gaps += ['tone_type' => $logical['tone_type'], 'ctcss_hz' => $logical['ctcss_hz'], 'dcs_code' => $logical['dcs_code']];
        }
        $cov = array_values(array_unique(array_filter(array_merge(
            explode(',', (string) ($current['coverage'] ?? '')), explode(',', (string) ($logical['coverage'] ?? ''))))));
        sort($cov);
        if (implode(',', $cov) !== (string) ($current['coverage'] ?? '')) {
            $gaps['coverage'] = implode(',', $cov);
        }
        if (($logical['last_heard_at'] ?? null) && $logical['last_heard_at'] > (string) ($current['last_heard_at'] ?? '')) {
            $gaps['last_heard_at'] = $logical['last_heard_at'];
        }
        // Un dato "da verificare" (es. dal manuale) viene aggiornato da una fonte che conferma o dismette la frequenza,
        // purché nessun utente o moderatore l'abbia già curato.
        if (($current['status'] ?? '') === 'da_verificare' && in_array($logical['status'] ?? '', ['attivo', 'inattivo'], true)
            && !self::touchedByCommunity($fid)) {
            $gaps['status'] = $logical['status'];
        }
        if (!$gaps) {
            return;
        }
        $diff = FrequencyFields::validate($gaps, true, $current);
        if ($diff) {
            RevisionService::propose(null, 'update', $fid, $diff, $comment, 'import', true);
            $st['stats']['completati']++;
        }
    }

    private static function touchedByCommunity(int $fid): bool
    {
        return (bool) Database::value(
            "SELECT 1 FROM listenin_revisions WHERE frequency_id = :f AND status = 'approved' AND origin IN ('user','moderator','report','checkin') LIMIT 1",
            ['f' => $fid]
        );
    }

    /** Record già collegato a una delle righe sorgente (importazioni precedenti), se esiste. */
    private static function knownFrequency(string $source, array $refs): ?int
    {
        $ph = implode(',', array_fill(0, count($refs), '?'));
        $id = Database::value("SELECT frequency_id FROM listenin_frequency_sources WHERE source = ? AND source_ref IN ($ph) LIMIT 1",
            array_merge([$source], array_values($refs)));
        return $id !== null ? (int) $id : null;
    }

    /** Profili dei record pubblicati sulla stessa frequenza (interrogazione indicizzata: nessuna mappa globale in memoria). */
    private static function existingProfiles(Deduplicator $dedup, int $hz): array
    {
        $out = [];
        $rows = Database::all(
            "SELECT f.id, f.freq_hz, f.label, f.channel, f.callsign, f.area_note, s.slug AS service, r.slug AS region, mu.name AS municipality,
                    (SELECT GROUP_CONCAT(p.code ORDER BY p.code) FROM listenin_frequency_provinces fp
                       JOIN listenin_provinces p ON p.id = fp.province_id WHERE fp.frequency_id = f.id) AS coverage
               FROM listenin_frequencies f JOIN listenin_services s ON s.id = f.service_id
               LEFT JOIN listenin_regions r ON r.id = f.region_id
               LEFT JOIN listenin_municipalities mu ON mu.id = f.municipality_id
              WHERE f.deleted_at IS NULL AND f.freq_hz = :hz",
            ['hz' => $hz]
        );
        foreach ($rows as $r) {
            $out[] = [(int) $r['id'], $dedup->profile([
                'freq_hz' => (int) $r['freq_hz'], 'label' => $r['label'], 'region' => $r['region'], 'coverage' => $r['coverage'],
                'service' => $r['service'], 'channel' => $r['channel'], 'callsign' => $r['callsign'],
                'municipality' => $r['municipality'], 'area_note' => $r['area_note'],
            ])];
        }
        return $out;
    }

    // ------------------------------------------------------------------ fase 4: chiusura
    private static function stepFinish(array &$st): void
    {
        $flagged = 0;
        if (!$st['dry_run']) {
            Database::transaction(function () use (&$st, &$flagged) {
                foreach ($st['probable'] as [$refA, $refB, $sim, $why]) {
                    $a = Database::value('SELECT frequency_id FROM listenin_frequency_sources WHERE source = :s AND source_ref = :r', ['s' => $st['source'], 'r' => $refA]);
                    $b = Database::value('SELECT frequency_id FROM listenin_frequency_sources WHERE source = :s AND source_ref = :r', ['s' => $st['source'], 'r' => $refB]);
                    $flagged += self::flagPair($a !== null ? (int) $a : null, $b !== null ? (int) $b : null, (float) $sim, (string) $why) ? 1 : 0;
                }
                foreach ($st['db_probable'] as [$newId, $oldId, $sim, $why]) {
                    $flagged += self::flagPair((int) $newId, (int) $oldId, (float) $sim, (string) $why) ? 1 : 0;
                }
            });
        } else {
            $flagged = count($st['probable']) + count($st['db_probable']);
        }
        $st['stats']['possibili_duplicati'] = $flagged;
        arsort($st['unclassified']);
        arsort($st['by_service']);
        $report = [
            'stats' => $st['stats'], 'per_servizio' => $st['by_service'],
            'da_classificare' => array_slice($st['unclassified'], 0, 100, true),
            'scartate' => $st['skipped'], 'errori' => $st['errors'],
        ];
        $st['report'] = $report;
        $st['status'] = 'done';
        $st['phase'] = 'done';
        $st['message'] = $st['dry_run'] ? 'Simulazione completata: nessun dato è stato salvato.' : 'Importazione completata.';
        file_put_contents(self::baseDir() . '/report-' . $st['id'] . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        Database::run(
            'UPDATE listenin_import_runs SET rows_read = :r, inserted = :i, updated = :u, unchanged = :un, skipped = :s,
                    report_json = :j, finished_at = NOW() WHERE id = :id',
            ['r' => $st['stats']['righe_lette'], 'i' => $st['stats']['inseriti'], 'u' => $st['stats']['aggiornati'] + $st['stats']['completati'],
                'un' => $st['stats']['invariati'], 's' => $st['stats']['righe_scartate'],
                'j' => json_encode($report, JSON_UNESCAPED_UNICODE), 'id' => $st['run_id']]
        );
        // file temporanei non più necessari
        @unlink(self::jobDir($st['id']) . '/clusters.jsonl');
    }

    /** Segnalazione di possibile duplicato ai moderatori, una sola per coppia di record. */
    private static function flagPair(?int $a, ?int $b, float $sim, string $why): bool
    {
        if ($a === null || $b === null || $a === $b) {
            return false;
        }
        [$newer, $older] = $a > $b ? [$a, $b] : [$b, $a];
        $exists = Database::value(
            "SELECT 1 FROM listenin_reports WHERE type = 'rimozione' AND removal_reason = 'duplicato'
               AND ((frequency_id = :n AND duplicate_of_id = :o) OR (frequency_id = :o2 AND duplicate_of_id = :n2))",
            ['n' => $newer, 'o' => $older, 'o2' => $older, 'n2' => $newer]
        );
        if ($exists) {
            return false;
        }
        Database::insert(
            "INSERT INTO listenin_reports (frequency_id, type, origin, removal_reason, duplicate_of_id, message)
             VALUES (:f, 'rimozione', 'import', 'duplicato', :d, :m)",
            ['f' => $newer, 'd' => $older, 'm' => sprintf(
                'Possibile duplicato rilevato in importazione: %s, somiglianza delle descrizioni %d%%. Se confermato, rimuovere questo record: le righe della fonte verranno collegate al record n. %d.',
                $why, (int) round($sim * 100), $older)]
        );
        return true;
    }

    private static function skip(array &$st, int $line, string $reason): void
    {
        $st['stats']['righe_scartate']++;
        if (count($st['skipped']) < 300) {
            $st['skipped'][] = "riga $line: $reason";
        }
    }

    public static function duplicatesCsvPath(string $id): ?string
    {
        $f = self::jobDir($id) . '/duplicati.csv';
        return is_file($f) ? $f : null;
    }

    // ------------------------------------------------------------------ comuni ISTAT (CSV o Excel, a blocchi)
    /**
     * @return array{offset:int,done:bool,processed:int,imported:int,skipped:int,message?:string}
     */
    public static function importComuni(string $path, int $offset, int $count = 400): array
    {
        $n = 0;
        $i = 0;
        $imported = 0;
        $skipped = 0;
        $done = true;
        $unknownColumns = false;
        Database::transaction(function () use ($path, $offset, $count, &$n, &$i, &$imported, &$skipped, &$done, &$unknownColumns) {
            foreach (SourceReader::read($path) as $row) {
                if ($i++ < $offset) {
                    continue;
                }
                if ($n >= $count) {
                    $done = false;
                    break;
                }
                $n++;
                $cols = [];
                foreach ($row as $h => $v) {
                    $cols[Normalizer::key((string) $h)] = $v;
                }
                // le intestazioni ISTAT cambiano leggermente da una versione all'altra: si cerca per parole chiave
                $pick = static function (array $needles) use ($cols): ?string {
                    foreach ($needles as $needle) {
                        foreach ($cols as $k => $v) {
                            if (str_contains((string) $k, $needle) && trim((string) $v) !== '') {
                                return trim((string) $v);
                            }
                        }
                    }
                    return null;
                };
                $istat = $pick(['codice comune formato alfanumerico']);
                $name = $pick(['denominazione in italiano', 'denominazione italiana e straniera']);
                $sigla = $pick(['sigla automobilistica']);
                if ($n === 1 && ($istat === null || $name === null || $sigla === null)) {
                    $unknownColumns = true;
                }
                $prov = $sigla !== null ? Taxonomy::province($sigla) : null;
                if ($istat && $name && $prov) {
                    Database::run(
                        'INSERT INTO listenin_municipalities (province_id, istat_code, name, name_key) VALUES (:p, :i, :n, :k)
                         ON DUPLICATE KEY UPDATE istat_code = VALUES(istat_code), name = VALUES(name)',
                        ['p' => (int) $prov['id'], 'i' => $istat, 'n' => $name, 'k' => Normalizer::key($name)]
                    );
                    $imported++;
                } else {
                    $skipped++;
                }
            }
        });
        $out = ['offset' => $offset + $n, 'done' => $done, 'processed' => $n, 'imported' => $imported, 'skipped' => $skipped];
        if ($unknownColumns) {
            $out['message'] = 'Colonne non riconosciute: servono "Codice Comune formato alfanumerico", "Denominazione in italiano" e "Sigla automobilistica".';
        }
        return $out;
    }

    // ------------------------------------------------------------------ baricentri dei comuni (dati ISTAT/CC BY inclusi nel pacchetto)
    /**
     * Carica app/data/comuni_centroidi.csv: aggiorna le coordinate dei comuni esistenti (per codice ISTAT o per nome nella
     * provincia), sposta nelle nuove province i comuni sardi prima assegnati a "Sud Sardegna" e crea quelli mancanti.
     * @return array{offset:int,done:bool,processed:int,updated:int,created:int,skipped:int,upgraded?:int}
     */
    public static function importComuniCoordinates(int $offset, int $count = 700): array
    {
        $file = LISTENIN_ROOT . '/data/comuni_centroidi.csv';
        if (!is_file($file)) {
            throw new \RuntimeException('File dei baricentri non trovato (app/data/comuni_centroidi.csv).');
        }
        $n = $i = $updated = $created = $skipped = 0;
        $done = true;
        Database::transaction(function () use ($file, $offset, $count, &$n, &$i, &$updated, &$created, &$skipped, &$done) {
            foreach (SourceReader::read($file, 'csv') as $row) {
                if ($i++ < $offset) {
                    continue;
                }
                if ($n >= $count) {
                    $done = false;
                    break;
                }
                $n++;
                $prov = Taxonomy::province((string) ($row['provincia'] ?? ''));
                $name = trim((string) ($row['nome'] ?? ''));
                if (!$prov || $name === '' || !is_numeric($row['lat'] ?? null) || !is_numeric($row['lon'] ?? null)) {
                    $skipped++;
                    continue;
                }
                $key = Normalizer::key($name);
                if (Taxonomy::get('regions')[(int) $prov['region_id']]['slug'] === 'sardegna') {
                    Database::run("UPDATE listenin_municipalities mu JOIN listenin_provinces pp ON pp.id = mu.province_id
                                      SET mu.province_id = :np WHERE pp.code = 'SU' AND mu.name_key = :k", ['np' => (int) $prov['id'], 'k' => $key]);
                }
                // si cerca prima per codice ISTAT, poi per nome nella provincia
                $existing = Database::value('SELECT id FROM listenin_municipalities WHERE istat_code = :i', ['i' => $row['istat']])
                    ?: Database::value('SELECT id FROM listenin_municipalities WHERE province_id = :p AND name_key = :k', ['p' => (int) $prov['id'], 'k' => $key]);
                if ($existing) {
                    // il codice ISTAT si assegna solo se non è già in uso da un altro comune
                    $codeFree = !Database::value('SELECT 1 FROM listenin_municipalities WHERE istat_code = :i AND id <> :id', ['i' => $row['istat'], 'id' => (int) $existing]);
                    Database::run('UPDATE listenin_municipalities SET latitude = :la, longitude = :lo' . ($codeFree ? ', istat_code = COALESCE(istat_code, :i)' : '') . ' WHERE id = :id',
                        ['la' => $row['lat'], 'lo' => $row['lon'], 'id' => (int) $existing] + ($codeFree ? ['i' => $row['istat']] : []));
                    $updated++;
                } else {
                    Database::run('INSERT INTO listenin_municipalities (province_id, istat_code, name, name_key, latitude, longitude) VALUES (:p, :i, :n, :k, :la, :lo)',
                        ['p' => (int) $prov['id'], 'i' => $row['istat'], 'n' => $name, 'k' => $key, 'la' => $row['lat'], 'lo' => $row['lon']]);
                    $created++;
                }
            }
        });
        $out = ['offset' => $offset + $n, 'done' => $done, 'processed' => $n, 'updated' => $updated, 'created' => $created, 'skipped' => $skipped];
        if ($done) {
            $out['upgraded'] = \ListenIn\Domain\GeoService::upgradeFromMunicipalities();
            @unlink(LISTENIN_ROOT . '/storage/cache/geo-summary.json');
        }
        return $out;
    }
}
