<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\ApiException;
use ListenIn\Core\Auth;
use ListenIn\Core\Config;
use ListenIn\Core\Database;
use ListenIn\Core\RateLimiter;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Core\Security;
use ListenIn\Core\Validator;
use ListenIn\Domain\CheckinService;
use ListenIn\Domain\FrequencyFields;
use ListenIn\Domain\TrustService;
use ListenIn\Export\RadioFormats;
use ListenIn\Domain\FrequencyRepository;
use ListenIn\Domain\ModerationLog;
use ListenIn\Domain\Normalizer;
use ListenIn\Domain\RevisionService;
use ListenIn\Domain\Taxonomy;
use ListenIn\Domain\UserService;

final class FrequencyController
{
    public static function index(Request $r): void
    {
        RateLimiter::hit('api_read', Security::clientIp());
        $filters = FrequencyRepository::parseFilters($r->query);
        $result = FrequencyRepository::search(
            $filters,
            $r->queryInt('page', 1, 1, 100000),
            $r->queryInt('per_page', 50, 10, 100),
            (string) ($r->queryString('sort', 12) ?? 'freq'),
            $r->queryString('dir', 4) === 'desc' ? 'desc' : 'asc'
        );
        $result['filters'] = $filters;
        Response::json($result);
    }

    public static function show(Request $r, array $p): void
    {
        RateLimiter::hit('api_read', Security::clientIp());
        $row = FrequencyRepository::findOrFail($p['id'], Auth::isModerator());
        $data = FrequencyRepository::present($row, true);
        $data['open_reports'] = (int) Database::value(
            "SELECT COUNT(*) FROM listenin_reports WHERE frequency_id = :f AND status IN ('aperta','in_revisione')", ['f' => $p['id']]);
        $data['sources'] = array_map(static fn($r) => ['source' => $r['source'], 'ref' => $r['source_ref']], Database::all(
            'SELECT source, source_ref FROM listenin_frequency_sources WHERE frequency_id = :f ORDER BY merged, source_ref LIMIT 20', ['f' => $p['id']]));
        $data['checkins'] = CheckinService::recent($p['id']);
        $data['pending_revisions'] = (int) Database::value(
            "SELECT COUNT(*) FROM listenin_revisions WHERE frequency_id = :f AND status = 'pending'", ['f' => $p['id']]);
        Response::json($data);
    }

    /** Controllo in tempo reale dei doppioni mentre si digita una nuova frequenza. */
    public static function check(Request $r): void
    {
        RateLimiter::hit('dup_check', Security::clientIp());
        $hz = Normalizer::parseFrequency((string) ($r->queryString('freq', 20) ?? ''));
        if ($hz === null) {
            throw ApiException::validation(['freq' => 'Frequenza non valida.']);
        }
        $province = $r->queryString('province', 2);
        $exclude = $r->queryInt('exclude', 0, 0, PHP_INT_MAX >> 1);
        Response::json(FrequencyRepository::neighbors($hz, $province !== null ? strtoupper($province) : null, $exclude ?: null)
            + ['freq_mhz' => Normalizer::formatMHz($hz), 'scoped_to_province' => $province !== null && Taxonomy::province(strtoupper($province)) !== null]);
    }

    public static function history(Request $r, array $p): void
    {
        RateLimiter::hit('api_read', Security::clientIp());
        FrequencyRepository::findOrFail($p['id'], true);
        Response::json(['items' => RevisionService::history($p['id'])]);
    }

    /** Proposta di nuova frequenza (approvazione immediata per i moderatori che lo chiedono). */
    public static function create(Request $r): void
    {
        $user = self::contributor($r);
        $in = $r->json();
        $logical = FrequencyFields::validate((array) ($in['data'] ?? []), false);
        $apply = Auth::isModerator($user) && !empty($in['apply']);
        $res = RevisionService::propose((int) $user['id'], 'create', null, $logical, self::comment($in),
            $apply ? 'moderator' : 'user', $apply, $apply ? (int) $user['id'] : null);
        Response::json($res + ['message' => $apply ? 'Frequenza pubblicata.' : 'Proposta inviata: sarà visibile dopo la revisione.'], 201);
    }

    /** Proposta di modifica di un record esistente. */
    public static function update(Request $r, array $p): void
    {
        $user = self::contributor($r);
        $in = $r->json();
        $row = FrequencyRepository::findOrFail($p['id']);
        $current = FrequencyRepository::logical($row);
        $logical = FrequencyFields::validate((array) ($in['data'] ?? []), true, $current);
        if (!$logical) {
            throw new ApiException(422, 'no_changes', 'Nessuna differenza rispetto ai dati attuali.');
        }
        $apply = Auth::isModerator($user) && !empty($in['apply']);
        $res = RevisionService::propose((int) $user['id'], 'update', $p['id'], $logical, self::comment($in),
            $apply ? 'moderator' : 'user', $apply, $apply ? (int) $user['id'] : null);
        $message = $apply ? 'Modifica applicata.' : 'Proposta di modifica inviata ai moderatori.';
        if (!$apply && TrustService::canAutoApprove($user, array_keys($logical))) {
            // contributore affidabile e modifica minore: pubblicazione immediata, comunque registrata e annullabile
            RevisionService::approve($res['revision_id'], null, 'Approvazione automatica (Trust Score)');
            $res['status'] = 'approved';
            $message = 'Modifica pubblicata subito: il tuo Trust Score consente l\'approvazione automatica delle modifiche minori.';
        }
        Response::json($res + ['message' => $message]);
    }

    /** Rimozione logica (solo moderatori; gli utenti usano la segnalazione di rimozione). */
    public static function destroy(Request $r, array $p): void
    {
        $user = Auth::requireRole('moderator');
        $in = $r->json();
        $res = RevisionService::propose((int) $user['id'], 'delete', $p['id'], [], self::comment($in), 'moderator', true, (int) $user['id']);
        ModerationLog::add((int) $user['id'], 'frequency.delete', 'frequency', $p['id'], self::comment($in));
        Response::json($res + ['message' => 'Frequenza rimossa dal dataset.']);
    }

    public static function restore(Request $r, array $p): void
    {
        $user = Auth::requireRole('moderator');
        $res = RevisionService::propose((int) $user['id'], 'restore', $p['id'], [], self::comment($r->json()), 'moderator', true, (int) $user['id']);
        ModerationLog::add((int) $user['id'], 'frequency.restore', 'frequency', $p['id']);
        Response::json($res + ['message' => 'Frequenza ripristinata.']);
    }

    /** Esportazione della vista filtrata in CSV o JSON (stream, memoria costante). */
    public static function export(Request $r): void
    {
        RateLimiter::hit('export', Security::clientIp());
        $fmt = (string) $r->queryString('format', 12);
        $format = in_array($fmt, ['json', 'chirp', 'opengd77'], true) ? $fmt : 'csv';
        $filters = FrequencyRepository::parseFilters($r->query);
        $max = (int) Config::get('export.max_rows', 20000);
        $name = ($format === 'chirp' || $format === 'opengd77' ? 'listenin-' . $format : 'frequenze') . '-' . date('Ymd-His') . '.' . ($format === 'json' ? 'json' : 'csv');

        header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        set_time_limit(120);

        if ($format === 'chirp' || $format === 'opengd77') {
            header('Content-Type: text/csv; charset=utf-8');
            $out = fopen('php://output', 'wb');
            $rows = FrequencyRepository::exportRows($filters, $max);
            $res = $format === 'chirp' ? RadioFormats::chirp($rows, $out) : RadioFormats::opengd77($rows, $out);
            fclose($out);
            exit;
        }

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            $meta = ['generator' => Config::get('app.name') . ' ' . LISTENIN_VERSION, 'generated_at' => date('c'),
                'filters' => $filters, 'units' => ['freq_hz' => 'Hz', 'offset_hz' => 'Hz', 'bandwidth_hz' => 'Hz', 'coverage_km' => 'km']];
            echo '{"meta":' . json_encode($meta, \ListenIn\Core\Response::JSON_FLAGS) . ',"data":[';
            $first = true;
            foreach (FrequencyRepository::exportRows($filters, $max) as $row) {
                echo ($first ? '' : ',') . "\n" . json_encode($row, \ListenIn\Core\Response::JSON_FLAGS);
                $first = false;
            }
            echo "\n]}";
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: apertura corretta in Excel
        $header = false;
        $numeric = ['id', 'freq_hz', 'offset_hz', 'color_code', 'dmr_timeslot', 'ran', 'bandwidth_hz', 'latitude', 'longitude', 'coverage_km'];
        foreach (FrequencyRepository::exportRows($filters, $max) as $row) {
            if (!$header) {
                fputcsv($out, array_keys($row), ',', '"', '');
                $header = true;
            }
            // Protezione da CSV/formula injection sui campi testuali
            foreach ($row as $k => $v) {
                if (is_string($v) && !in_array($k, $numeric, true) && preg_match('/^[=+\-@\t\r]/', $v)) {
                    $row[$k] = "'" . $v;
                }
            }
            fputcsv($out, $row, ',', '"', '');
        }
        if (!$header) {
            fputcsv($out, ['nessun_risultato'], ',', '"', '');
        }
        fclose($out);
        exit;
    }

    // ------------------------------------------------------------------

    /** Utente autenticato abilitato a contribuire; registra la presa visione del disclaimer al primo invio. */
    public static function contributor(Request $r): array
    {
        $user = Auth::require();
        RateLimiter::hit('proposal', 'u' . $user['id']);
        if (!Auth::hasCurrentDisclaimer($user)) {
            $v = new Validator($r->json());
            $v->accepted('disclaimer_ack', 'Conferma di aver preso visione del disclaimer.');
            $v->throwIfErrors();
            UserService::acceptDisclaimer((int) $user['id']);
        }
        return $user;
    }

    private static function comment(array $in): ?string
    {
        $c = $in['comment'] ?? null;
        if (!is_string($c)) {
            return null;
        }
        $c = Validator::clean($c, true);
        return $c === '' ? null : mb_substr($c, 0, 500);
    }
}
