<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\ApiException;
use ListenIn\Core\Auth;
use ListenIn\Core\Config;
use ListenIn\Core\Database;
use ListenIn\Core\Migrator;
use ListenIn\Core\RateLimiter;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Core\Security;
use ListenIn\Core\Validator;
use ListenIn\Domain\CheckinService;
use ListenIn\Domain\Normalizer;
use ListenIn\Domain\PointsService;
use ListenIn\Domain\SnapshotService;
use ListenIn\Domain\StatsService;
use ListenIn\Domain\Taxonomy;
use ListenIn\Domain\TrustService;
use ListenIn\Domain\UserService;

/** Check-in, logbook, badge, classifiche, statistiche, feed, open data e stato del sistema. */
final class CommunityController
{
    // ------------------------------------------------------------------ check-in
    public static function checkin(Request $r, array $p): void
    {
        $user = Auth::require();
        $in = $r->json();
        if (!Auth::hasCurrentDisclaimer($user)) {
            $v = new Validator($in);
            $v->accepted('disclaimer_ack', 'Conferma di aver preso visione del disclaimer.');
            $v->throwIfErrors();
            UserService::acceptDisclaimer((int) $user['id']);
        }
        $res = CheckinService::create($user, $p['id'], $in);
        $msg = $res['recovered'] ? 'Recupero registrato: grazie! +' . $res['points'] . ' punti.' : 'Ascolto registrato. +' . $res['points'] . ' punti.';
        Auth::forget();
        Response::json($res + ['message' => $msg, 'user' => Auth::publicView(Auth::user())], 201);
    }

    // ------------------------------------------------------------------ logbook, badge
    public static function logbook(Request $r): void
    {
        Response::json(CheckinService::logbook((int) Auth::require()['id'], $r->queryInt('page', 1, 1, 10000)));
    }

    public static function logbookExport(Request $r): void
    {
        $user = Auth::require();
        RateLimiter::hit('export', 'u' . $user['id']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="logbook-swl-' . date('Ymd') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        $first = true;
        foreach (CheckinService::logbookRows((int) $user['id']) as $row) {
            if ($first) {
                fputcsv($out, array_keys($row), ',', '"', '');
                $first = false;
            }
            foreach ($row as $k => $v) {
                if (is_string($v) && preg_match('/^[=+\-@\t\r]/', $v)) {
                    $row[$k] = "'" . $v;
                }
            }
            fputcsv($out, $row, ',', '"', '');
        }
        if ($first) {
            fputcsv($out, ['data', 'frequenza_mhz', 'modo', 'descrizione', 'nominativo', 'rapporto', 'apparato_antenna', 'recupero', 'motivo_recupero'], ',', '"', '');
        }
        fclose($out);
        exit;
    }

    public static function badges(Request $r): void
    {
        $u = Auth::require();
        Response::json([
            'badges' => PointsService::badges((int) $u['id']),
            'points' => (int) $u['points'], 'trust_score' => (int) $u['trust_score'], 'auto_approve_min' => TrustService::threshold(),
            'recoveries' => (int) Database::value('SELECT COUNT(*) FROM listenin_checkins WHERE user_id = :u AND recovered = 1', ['u' => $u['id']]),
        ]);
    }

    // ------------------------------------------------------------------ statistiche e classifiche
    public static function stats(Request $r): void
    {
        RateLimiter::hit('api_read', Security::clientIp());
        Response::json(StatsService::get());
    }

    public static function leaderboard(Request $r): void
    {
        RateLimiter::hit('api_read', Security::clientIp());
        $period = in_array($r->queryString('period', 16), ['punti', 'punti_mese', 'recuperi_mese', 'recuperi'], true) ? $r->queryString('period', 16) : 'punti';
        Response::json(['period' => $period, 'items' => PointsService::leaderboard($period, 20),
            'recoveries_total' => (int) Database::value('SELECT COUNT(*) FROM listenin_checkins WHERE recovered = 1')]);
    }

    // ------------------------------------------------------------------ feed RSS / Atom
    public static function feed(Request $r): void
    {
        RateLimiter::hit('feed', Security::clientIp());
        $atom = $r->queryString('format', 4) === 'atom';
        $event = in_array($r->queryString('evento', 10), ['nuove', 'recuperi'], true) ? $r->queryString('evento', 10) : 'tutte';
        $where = ['f.deleted_at IS NULL'];
        $params = [];
        if (($reg = $r->queryString('regione', 48)) && ($rg = Taxonomy::region($reg))) {
            $where[] = 'f.region_id = :rg';
            $params['rg'] = (int) $rg['id'];
        }
        if (($pv = $r->queryString('provincia', 2)) && ($pr = Taxonomy::province(strtoupper($pv)))) {
            $where[] = 'f.province_id = :pv';
            $params['pv'] = (int) $pr['id'];
        }
        if (($b = $r->queryString('banda', 4)) && isset(Taxonomy::get('ituByCode')[strtoupper($b)])) {
            $where[] = 'f.itu_band_id = :bd';
            $params['bd'] = (int) Taxonomy::get('ituByCode')[strtoupper($b)]['id'];
        }
        $w = implode(' AND ', $where);
        $items = [];
        if ($event !== 'recuperi') {
            foreach (Database::all("SELECT f.id, f.freq_hz, f.label, f.created_at AS at FROM listenin_frequencies f WHERE $w ORDER BY f.created_at DESC, f.id DESC LIMIT 40", $params) as $x) {
                $items[] = ['kind' => 'nuova', 'id' => (int) $x['id'], 'hz' => (int) $x['freq_hz'], 'label' => $x['label'], 'at' => $x['at'], 'by' => null];
            }
        }
        if ($event !== 'nuove') {
            foreach (Database::all("SELECT f.id, f.freq_hz, f.label, c.created_at AS at, u.nickname FROM listenin_checkins c JOIN listenin_frequencies f ON f.id = c.frequency_id
                                      LEFT JOIN listenin_users u ON u.id = c.user_id WHERE c.recovered = 1 AND $w ORDER BY c.created_at DESC, c.id DESC LIMIT 40", $params) as $x) {
                $items[] = ['kind' => 'recuperata', 'id' => (int) $x['id'], 'hz' => (int) $x['freq_hz'], 'label' => $x['label'], 'at' => $x['at'], 'by' => $x['nickname'] ?? 'utente eliminato'];
            }
        }
        usort($items, static fn($a, $b) => strcmp($b['at'], $a['at']));
        $items = array_slice($items, 0, 50);

        $base = rtrim((string) Config::get('app.base_url'), '/');
        $name = (string) Config::get('app.name', 'ListenIn');
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $self = $base . '/api/index.php?route=/feed&amp;format=' . ($atom ? 'atom' : 'rss');
        $title = $name . ' · ' . ($event === 'nuove' ? 'nuove frequenze' : ($event === 'recuperi' ? 'frequenze recuperate' : 'novità'));
        header('Content-Type: ' . ($atom ? 'application/atom+xml' : 'application/rss+xml') . '; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $line = static fn(array $i) => ($i['kind'] === 'nuova' ? 'Nuova frequenza: ' : 'Frequenza recuperata: ') . Normalizer::formatMHz($i['hz']) . ' MHz, ' . $i['label'];
        if ($atom) {
            echo '<feed xmlns="http://www.w3.org/2005/Atom"><title>' . $e($title) . '</title><id>' . $e($base . '/feed') . '</id><link href="' . $e($base . '/') . '"/>'
                . '<updated>' . $e(date('c', strtotime($items[0]['at'] ?? 'now'))) . '</updated>';
            foreach ($items as $i) {
                echo '<entry><title>' . $e($line($i)) . '</title><id>' . $e($base . '/#/f/' . $i['id'] . '@' . $i['kind'] . '-' . $i['at']) . '</id>'
                    . '<link href="' . $e($base . '/#/f/' . $i['id']) . '"/><updated>' . $e(date('c', strtotime($i['at']))) . '</updated>'
                    . '<summary>' . $e($i['by'] ? 'Confermata da ' . $i['by'] : 'Aggiunta al database') . '</summary></entry>';
            }
            echo '</feed>';
        } else {
            echo '<rss version="2.0"><channel><title>' . $e($title) . '</title><link>' . $e($base . '/') . '</link><description>' . $e((string) Config::get('app.tagline')) . '</description><language>it</language>';
            foreach ($items as $i) {
                echo '<item><title>' . $e($line($i)) . '</title><link>' . $e($base . '/#/f/' . $i['id']) . '</link>'
                    . '<guid isPermaLink="false">' . $e($base . '/#/f/' . $i['id'] . '@' . $i['kind'] . '-' . $i['at']) . '</guid>'
                    . '<pubDate>' . $e(date(DATE_RSS, strtotime($i['at']))) . '</pubDate>'
                    . '<description>' . $e($i['by'] ? 'Confermata da ' . $i['by'] : 'Aggiunta al database') . '</description></item>';
            }
            echo '</channel></rss>';
        }
        exit;
    }

    // ------------------------------------------------------------------ open data
    public static function opendata(Request $r): void
    {
        RateLimiter::hit('opendata', Security::clientIp());
        Response::json(['files' => SnapshotService::list(), 'license' => SnapshotService::license()]);
    }

    public static function opendataDownload(Request $r): void
    {
        RateLimiter::hit('opendata', Security::clientIp());
        $path = SnapshotService::path((string) $r->queryString('name', 40));
        if (!$path) {
            throw ApiException::notFound('File non disponibile.');
        }
        $json = str_ends_with($path, '.json');
        header('Content-Type: ' . ($json ? 'application/json' : 'text/csv') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=3600');
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------------ sistema (amministratori)
    public static function system(Request $r): void
    {
        Auth::requireRole('admin');
        Response::json([
            'version' => LISTENIN_VERSION, 'php' => PHP_VERSION,
            'migrations' => ['applied' => Migrator::applied(), 'pending' => Migrator::pending(), 'latest' => Migrator::latest()],
            'opendata' => SnapshotService::list(),
            'geo' => \ListenIn\Domain\GeoService::status(),
            'dup_tolerance_note' => 'Tolleranze del controllo doppioni: ±5 kHz e ±12,5 kHz, nella stessa provincia.',
        ]);
    }

    public static function migrate(Request $r): void
    {
        Auth::requireRole('admin');
        $done = Migrator::run();
        @file_put_contents(LISTENIN_ROOT . '/storage/cache/schema.stamp', Migrator::latest());
        Response::json(['applied' => $done, 'message' => $done ? 'Database aggiornato: ' . implode(', ', $done) . '.' : 'Il database è già aggiornato.']);
    }

    public static function generateSnapshot(Request $r): void
    {
        Auth::requireRole('admin');
        $res = SnapshotService::generate();
        Response::json($res + ['message' => 'Copia open data generata (' . number_format($res['count'], 0, ',', '.') . ' frequenze).', 'files' => SnapshotService::list()]);
    }
}
