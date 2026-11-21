<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\Database;

/** Statistiche aggregate calcolate direttamente sui dati (con una breve cache su file). */
final class StatsService
{
    private static function cacheFile(): string
    {
        return LISTENIN_ROOT . '/storage/cache/stats.json';
    }

    public static function get(int $ttl = 300): array
    {
        $f = self::cacheFile();
        if (is_file($f) && time() - filemtime($f) < $ttl && ($c = json_decode((string) file_get_contents($f), true))) {
            return $c;
        }
        $data = self::compute();
        @file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $data;
    }

    public static function compute(): array
    {
        $live = 'f.deleted_at IS NULL';
        $rel = Reliability::sql('f');
        $months = static function (string $table, string $where): array {
            $rows = array_column(Database::all("SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) n FROM $table WHERE $where
                                                  AND created_at >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01') GROUP BY ym"), 'n', 'ym');
            $out = [];
            for ($i = 11; $i >= 0; $i--) {
                $ym = date('Y-m', strtotime("first day of -$i month"));
                $out[] = ['month' => $ym, 'value' => (int) ($rows[$ym] ?? 0)];
            }
            return $out;
        };
        $total = (int) Database::value("SELECT COUNT(*) FROM listenin_frequencies f WHERE $live");
        $relRows = array_column(Database::all("SELECT $rel lv, COUNT(*) n FROM listenin_frequencies f WHERE $live GROUP BY lv"), 'n', 'lv');
        return [
            'generated_at' => date('c'),
            'totals' => [
                'frequencies' => $total,
                'users' => (int) Database::value("SELECT COUNT(*) FROM listenin_users WHERE status = 'active'"),
                'checkins' => (int) Database::value('SELECT COUNT(*) FROM listenin_checkins'),
                'recoveries' => (int) Database::value('SELECT COUNT(*) FROM listenin_checkins WHERE recovered = 1'),
                'recoveries_month' => (int) Database::value("SELECT COUNT(*) FROM listenin_checkins WHERE recovered = 1 AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
                'new_month' => (int) Database::value("SELECT COUNT(*) FROM listenin_frequencies f WHERE $live AND f.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
            ],
            'reliability' => array_map(static fn($k, $label) => ['level' => $k, 'label' => $label, 'count' => (int) ($relRows[$k] ?? 0),
                'percent' => $total ? round(100 * (int) ($relRows[$k] ?? 0) / $total, 1) : 0], array_keys(Reliability::LEVELS), Reliability::LEVELS),
            'by_band' => array_map(static fn($r) => ['label' => $r['code'] ?? 'n.d.', 'count' => (int) $r['n']], Database::all(
                "SELECT ib.code, COUNT(*) n FROM listenin_frequencies f LEFT JOIN listenin_itu_bands ib ON ib.id = f.itu_band_id
                  WHERE $live GROUP BY ib.id, ib.code, ib.min_hz ORDER BY ib.min_hz")),
            'by_mode' => array_map(static fn($r) => ['label' => $r['code'], 'count' => (int) $r['n']], Database::all(
                "SELECT m.code, COUNT(*) n FROM listenin_frequencies f JOIN listenin_modes m ON m.id = f.mode_id WHERE $live
                  GROUP BY m.id, m.code ORDER BY n DESC LIMIT 12")),
            'by_region' => array_map(static fn($r) => ['label' => $r['name'] ?? 'Non indicata', 'slug' => $r['slug'], 'count' => (int) $r['n'],
                'verified_percent' => $r['n'] ? round(100 * (int) $r['alta'] / (int) $r['n'], 1) : 0], Database::all(
                "SELECT r.name, r.slug, COUNT(*) n, SUM(($rel) = 'alta') alta FROM listenin_frequencies f
                   LEFT JOIN listenin_regions r ON r.id = f.region_id WHERE $live GROUP BY r.id, r.name, r.slug ORDER BY n DESC")),
            'by_service' => array_map(static fn($r) => ['label' => $r['name'], 'count' => (int) $r['n']], Database::all(
                "SELECT COALESCE(p.name, s.name) name, COUNT(*) n FROM listenin_frequencies f JOIN listenin_services s ON s.id = f.service_id
                   LEFT JOIN listenin_services p ON p.id = s.parent_id WHERE $live GROUP BY COALESCE(p.id, s.id), COALESCE(p.name, s.name) ORDER BY n DESC")),
            'recoveries_by_month' => $months('listenin_checkins', 'recovered = 1'),
            'checkins_by_month' => $months('listenin_checkins', '1 = 1'),
            'top_contributors' => PointsService::leaderboard('punti', 10),
        ];
    }
}
