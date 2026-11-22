<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\ApiException;
use ListenIn\Core\Auth;
use ListenIn\Core\Config;
use ListenIn\Core\Maintenance;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Core\Validator;
use ListenIn\Import\Downloader;
use ListenIn\Import\ImportEngine;

/** Importazione dei dati dal pannello di amministrazione (nessun accesso a terminale necessario). */
final class ImportController
{
    private static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    }

    public static function overview(Request $r): void
    {
        Auth::requireRole('admin');
        $bundled = is_file(ImportEngine::dataDir() . '/frequenze_da_rivedere.csv');
        Response::json([
            'files' => ImportEngine::listFiles(),
            'jobs' => ImportEngine::recentJobs(),
            'review_file' => $bundled,
            'limits' => [
                'upload_bytes' => min(self::iniBytes('upload_max_filesize') ?: PHP_INT_MAX, self::iniBytes('post_max_size') ?: PHP_INT_MAX,
                    (int) Config::get('import.max_upload_mb', 25) << 20),
            ],
            'defaults' => [
                'istat_url' => (string) Config::get('import.istat_url', 'https://www.istat.it/storage/codici-unita-amministrative/Elenco-comuni-italiani.xlsx'),
                'fetch_hosts' => Downloader::hosts(),
            ],
            'maintenance_last_run' => ($t = Maintenance::lastRun()) ? date('Y-m-d H:i', $t) : null,
        ]);
    }

    public static function upload(Request $r): void
    {
        Auth::requireRole('admin');
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
            $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'Il file supera il limite di caricamento del server (' . round(self::iniBytes('upload_max_filesize') / 1048576, 1) . ' MB). Caricalo via FTP in app/storage/import/inbox/.'
                : 'Caricamento non riuscito. Riprova.';
            throw ApiException::validation(['file' => $msg]);
        }
        $max = (int) Config::get('import.max_upload_mb', 25) << 20;
        if ((int) $f['size'] > $max) {
            throw ApiException::validation(['file' => 'File troppo grande (massimo ' . ($max >> 20) . ' MB).']);
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ImportEngine::EXTENSIONS, true)) {
            throw ApiException::validation(['file' => 'Formato non supportato: usa CSV, Excel (XLSX), JSON o HTML.']);
        }
        $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 4096);
        if ($ext === 'xlsx' ? !str_starts_with($head, "PK\x03\x04") : str_contains($head, "\0")) {
            throw ApiException::validation(['file' => $ext === 'xlsx' ? 'Il file non sembra un documento Excel (.xlsx) valido.' : 'Il file non sembra un file di testo.']);
        }
        $base = preg_replace('/[^\w.\- ]+/u', '_', pathinfo((string) $f['name'], PATHINFO_FILENAME)) ?: 'importazione';
        $name = mb_substr($base, 0, 80) . '.' . $ext;
        $dest = ImportEngine::inboxDir() . '/' . $name;
        if (is_file($dest)) {
            $name = mb_substr($base, 0, 70) . '-' . date('Ymd-His') . '.' . $ext;
            $dest = ImportEngine::inboxDir() . '/' . $name;
        }
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            throw new ApiException(500, 'upload_failed', 'Impossibile salvare il file: controlla i permessi di scrittura su app/storage.');
        }
        Response::json(['message' => 'File caricato.', 'name' => $name, 'files' => ImportEngine::listFiles()], 201);
    }

    public static function deleteFile(Request $r): void
    {
        Auth::requireRole('admin');
        $name = (string) ($r->json()['name'] ?? '');
        $path = ImportEngine::resolveFile('caricato', $name);   // solo i file caricati, mai quelli del pacchetto
        unlink($path);
        Response::json(['message' => 'File eliminato.', 'files' => ImportEngine::listFiles()]);
    }

    public static function start(Request $r): void
    {
        Auth::requireRole('admin');
        $in = $r->json();
        $v = new Validator($in);
        $kind = $v->enum('kind', ['incluso', 'caricato']);
        $name = $v->string('name', 1, 120);
        $source = $v->string('source', 3, 32, false) ?? 'esterna';
        if (!preg_match('/^[a-z0-9\-]{3,32}$/', $source)) {
            $v->addError('source', 'Nome della fonte non valido (lettere minuscole, numeri e trattini).');
        }
        $v->throwIfErrors();
        foreach (ImportEngine::recentJobs(3) as $j) {
            if ($j['status'] === 'running' && strtotime((string) $j['started_at']) > time() - 900) {
                throw new ApiException(409, 'busy', 'C\'è già un\'importazione in corso: attendi che termini o annullala.');
            }
        }
        $st = ImportEngine::start([
            'path' => ImportEngine::resolveFile($kind, $name), 'file' => $name, 'source' => $source,
            'dry_run' => !empty($in['dry_run']), 'update' => !isset($in['update']) || !empty($in['update']),
            'limit' => isset($in['limit']) && (int) $in['limit'] > 0 ? (int) $in['limit'] : null,
        ]);
        Response::json(ImportEngine::publicView($st), 201);
    }

    public static function step(Request $r): void
    {
        Auth::requireRole('admin');
        $id = (string) ($r->json()['job'] ?? '');
        Response::json(ImportEngine::publicView(ImportEngine::step($id, (float) Config::get('import.step_seconds', 12))));
    }

    public static function job(Request $r): void
    {
        Auth::requireRole('admin');
        Response::json(ImportEngine::publicView(ImportEngine::load((string) $r->queryString('id', 16))));
    }

    public static function cancel(Request $r): void
    {
        Auth::requireRole('admin');
        Response::json(ImportEngine::publicView(ImportEngine::cancel((string) ($r->json()['job'] ?? ''))));
    }

    public static function download(Request $r): void
    {
        Auth::requireRole('admin');
        $what = $r->queryString('what', 12);
        if ($what === 'rivedere') {
            $path = ImportEngine::dataDir() . '/frequenze_da_rivedere.csv';
            $name = 'frequenze_da_rivedere.csv';
        } else {
            $path = ImportEngine::duplicatesCsvPath((string) $r->queryString('id', 16));
            $name = 'duplicati-' . date('Ymd-His') . '.csv';
        }
        if (!$path || !is_file($path)) {
            throw ApiException::notFound('File non disponibile.');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /** Comuni ISTAT: elaborazione a blocchi, richiamata finché done è true. */
    public static function comuni(Request $r): void
    {
        Auth::requireRole('admin');
        $in = $r->json();
        $path = ImportEngine::resolveFile('caricato', (string) ($in['name'] ?? ''));
        Response::json(ImportEngine::importComuni($path, max(0, (int) ($in['offset'] ?? 0))));
    }

    /** Importa a blocchi i baricentri dei comuni inclusi nel pacchetto. */
    public static function geoComuni(Request $r): void
    {
        Auth::requireRole('admin');
        @set_time_limit(60);
        Response::json(ImportEngine::importComuniCoordinates((int) ($r->json()['offset'] ?? 0)));
    }

    /** Scarica un file da un sito autorizzato (es. l'elenco dei comuni ISTAT) nella cartella dei file da importare. */
    public static function fetch(Request $r): void
    {
        Auth::requireRole('admin');
        $url = trim((string) ($r->json()['url'] ?? ''));
        if ($url === '' || mb_strlen($url) > 500) {
            throw ApiException::validation(['url' => 'Indica l\'indirizzo del file.']);
        }
        $res = Downloader::fetch($url, ImportEngine::inboxDir());
        Response::json(['message' => 'File scaricato.', 'name' => $res['name'], 'size' => $res['size'], 'files' => ImportEngine::listFiles()], 201);
    }

    public static function maintenance(Request $r): void
    {
        Auth::requireRole('admin');
        Response::json(['result' => Maintenance::run(), 'message' => 'Manutenzione eseguita.', 'last_run' => date('Y-m-d H:i')]);
    }
}
