<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\ApiException;
use ListenIn\Core\Auth;
use ListenIn\Core\Database;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Core\Validator;
use ListenIn\Domain\Normalizer;
use ListenIn\Domain\ReportService;
use ListenIn\Domain\RevisionService;

final class ModerationController
{
    public static function stats(Request $r): void
    {
        Auth::requireRole('moderator');
        Response::json([
            'pending_revisions' => (int) Database::value("SELECT COUNT(*) FROM listenin_revisions WHERE status = 'pending'"),
            'open_reports' => (int) Database::value("SELECT COUNT(*) FROM listenin_reports WHERE status IN ('aperta','in_revisione')"),
            'frequencies' => (int) Database::value('SELECT COUNT(*) FROM listenin_frequencies WHERE deleted_at IS NULL'),
            'to_verify' => (int) Database::value("SELECT COUNT(*) FROM listenin_frequencies WHERE deleted_at IS NULL AND status = 'da_verificare'"),
        ]);
    }

    public static function revisions(Request $r): void
    {
        Auth::requireRole('moderator');
        $status = in_array($r->queryString('status', 12), ['pending', 'approved', 'rejected', 'superseded'], true)
            ? $r->queryString('status', 12) : 'pending';
        $page = $r->queryInt('page', 1, 1, 10000);
        $per = 25;
        $total = (int) Database::value('SELECT COUNT(*) FROM listenin_revisions WHERE status = :s', ['s' => $status]);
        $rows = Database::all(
            'SELECT r.id, r.frequency_id, r.action, r.origin, r.author_id, r.author_comment, r.created_at, r.base_version,
                    a.nickname, f.freq_hz, f.label, f.version AS current_version,
                    (SELECT c.new_value FROM listenin_revision_changes c WHERE c.revision_id = r.id AND c.field = \'freq_hz\') AS proposed_hz,
                    (SELECT c.new_value FROM listenin_revision_changes c WHERE c.revision_id = r.id AND c.field = \'label\') AS proposed_label,
                    (SELECT COUNT(*) FROM listenin_revision_changes c WHERE c.revision_id = r.id) AS n_changes
               FROM listenin_revisions r
               LEFT JOIN listenin_users a ON a.id = r.author_id
               LEFT JOIN listenin_frequencies f ON f.id = r.frequency_id
              WHERE r.status = :s ORDER BY r.created_at ' . ($status === 'pending' ? 'ASC' : 'DESC') .
            ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            ['s' => $status]
        );
        Response::json(['items' => array_map(static fn($x) => [
            'id' => (int) $x['id'],
            'frequency_id' => $x['frequency_id'] !== null ? (int) $x['frequency_id'] : null,
            'action' => $x['action'],
            'origin' => $x['origin'],
            'author' => RevisionService::authorName($x['author_id'], $x['nickname'], $x['origin']),
            'comment' => $x['author_comment'],
            'freq_mhz' => Normalizer::formatMHz($x['freq_hz'] !== null ? (int) $x['freq_hz'] : ($x['proposed_hz'] !== null ? (int) $x['proposed_hz'] : null)),
            'label' => $x['label'] ?? $x['proposed_label'],
            'changes' => (int) $x['n_changes'],
            'outdated' => $x['current_version'] !== null && $x['base_version'] !== null && (int) $x['current_version'] !== (int) $x['base_version'],
            'created_at' => $x['created_at'],
        ], $rows), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per))]);
    }

    public static function revision(Request $r, array $p): void
    {
        Auth::requireRole('moderator');
        Response::json(RevisionService::detail($p['id']));
    }

    public static function approve(Request $r, array $p): void
    {
        $u = Auth::requireRole('moderator');
        $in = $r->json();
        $fid = RevisionService::approve($p['id'], (int) $u['id'], self::note($in), !empty($in['force']));
        Response::json(['message' => 'Revisione approvata e pubblicata.', 'frequency_id' => $fid]);
    }

    public static function reject(Request $r, array $p): void
    {
        $u = Auth::requireRole('moderator');
        $in = $r->json();
        $note = self::note($in);
        if ($note === null) {
            throw ApiException::validation(['note' => 'Indica il motivo del rifiuto: l\'autore lo vedrà nel suo storico.']);
        }
        RevisionService::reject($p['id'], (int) $u['id'], $note);
        Response::json(['message' => 'Revisione rifiutata.']);
    }

    public static function reports(Request $r): void
    {
        Auth::requireRole('moderator');
        $status = in_array($r->queryString('status', 12), ['aperta', 'in_revisione', 'risolta', 'archiviata'], true)
            ? $r->queryString('status', 12) : null;
        Response::json(ReportService::list($status, $r->queryInt('page', 1, 1, 10000)));
    }

    public static function claim(Request $r, array $p): void
    {
        $u = Auth::requireRole('moderator');
        ReportService::claim($p['id'], (int) $u['id']);
        Response::json(['message' => 'Segnalazione presa in carico.']);
    }

    public static function resolve(Request $r, array $p): void
    {
        $u = Auth::requireRole('moderator');
        $in = $r->json();
        $v = new Validator($in);
        $value = $v->string('value', 1, 255, false);
        $v->throwIfErrors();
        $res = ReportService::resolve($p['id'], (int) $u['id'], self::note($in), !empty($in['apply']), $value);
        Response::json($res + ['message' => !empty($in['apply']) ? 'Correzione applicata e segnalazione risolta.' : 'Segnalazione risolta.']);
    }

    public static function archive(Request $r, array $p): void
    {
        $u = Auth::requireRole('moderator');
        ReportService::archive($p['id'], (int) $u['id'], self::note($r->json()));
        Response::json(['message' => 'Segnalazione archiviata.']);
    }

    private static function note(array $in): ?string
    {
        $n = is_string($in['note'] ?? null) ? Validator::clean($in['note'], true) : '';
        return $n === '' ? null : mb_substr($n, 0, 500);
    }
}
