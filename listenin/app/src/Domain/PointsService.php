<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\Config;
use ListenIn\Core\Database;

/** Punti reputazione, badge e classifiche. */
final class PointsService
{
    public const DEFAULT_POINTS = ['checkin' => 2, 'recupero' => 10, 'proposta' => 5, 'segnalazione' => 3];

    public static function value(string $reason): int
    {
        return (int) Config::get('points.' . $reason, self::DEFAULT_POINTS[$reason] ?? 0);
    }

    public static function award(?int $userId, string $reason, ?int $refId = null, ?int $points = null): int
    {
        $points ??= self::value($reason);
        if ($userId === null || $points === 0) {
            return 0;
        }
        Database::run('INSERT INTO listenin_points (user_id, points, reason, ref_id) VALUES (:u, :p, :r, :f)',
            ['u' => $userId, 'p' => $points, 'r' => $reason, 'f' => $refId]);
        Database::run('UPDATE listenin_users SET points = points + :p WHERE id = :u', ['p' => $points, 'u' => $userId]);
        return $points;
    }

    /** Assegna i badge maturati. @return array<int, array{code:string,name:string}> nuovi badge */
    public static function evaluateBadges(int $userId): array
    {
        $have = array_column(Database::all('SELECT badge_code FROM listenin_user_badges WHERE user_id = :u', ['u' => $userId]), 'badge_code');
        $checkins = (int) Database::value('SELECT COUNT(*) FROM listenin_checkins WHERE user_id = :u', ['u' => $userId]);
        $ponti = (int) Database::value(
            "SELECT COUNT(DISTINCT c.frequency_id) FROM listenin_checkins c
               JOIN listenin_frequencies f ON f.id = c.frequency_id
               JOIN listenin_services s ON s.id = f.service_id
               LEFT JOIN listenin_services p ON p.id = s.parent_id
              WHERE c.user_id = :u AND COALESCE(p.slug, s.slug) = 'ponti-ripetitori'", ['u' => $userId]);
        $recuperi = (int) Database::value("SELECT COUNT(*) FROM listenin_checkins WHERE user_id = :u AND recovered = 1 AND recovery_reason IN ('mai','scaduta','inattiva')", ['u' => $userId]);
        $approved = (int) Database::value("SELECT COUNT(*) FROM listenin_revisions WHERE author_id = :u AND status = 'approved' AND origin IN ('user','report')", ['u' => $userId]);
        $regions = (int) Database::value('SELECT COUNT(DISTINCT f.region_id) FROM listenin_checkins c JOIN listenin_frequencies f ON f.id = c.frequency_id WHERE c.user_id = :u AND f.region_id IS NOT NULL', ['u' => $userId]);
        $allRecoveries = (int) Database::value('SELECT COUNT(*) FROM listenin_checkins WHERE user_id = :u AND recovered = 1', ['u' => $userId]);
        $earned = [];
        $rules = ['primo-ascolto' => $checkins >= 1, 'sentinella-spettro' => $checkins >= 50, 'cacciatore-ponti' => $ponti >= 10,
            'archeologo-rf' => $recuperi >= 10, 'collaboratore' => $approved >= 5,
            'esploratore' => $regions >= 5, 'veterano' => $checkins >= 250, 'recuperatore' => $allRecoveries >= 50, 'redattore' => $approved >= 25,
            'maratoneta' => $checkins >= 7 && self::longestStreak($userId) >= 7];
        foreach ($rules as $code => $ok) {
            if ($ok && !in_array($code, $have, true)) {
                Database::run('INSERT IGNORE INTO listenin_user_badges (user_id, badge_code) VALUES (:u, :b)', ['u' => $userId, 'b' => $code]);
                $name = (string) Database::value('SELECT name FROM listenin_badges WHERE code = :c', ['c' => $code]);
                $earned[] = ['code' => $code, 'name' => $name];
            }
        }
        return $earned;
    }

    /** Giorni consecutivi più lunghi con almeno un ascolto (ultimi 400 giorni). */
    public static function longestStreak(int $userId): int
    {
        $days = array_column(Database::all('SELECT DISTINCT heard_on FROM listenin_checkins WHERE user_id = :u AND heard_on >= DATE_SUB(CURDATE(), INTERVAL 400 DAY) ORDER BY heard_on', ['u' => $userId]), 'heard_on');
        $best = $run = 0;
        $prev = null;
        foreach ($days as $d) {
            $run = ($prev !== null && (new \DateTimeImmutable($prev))->modify('+1 day')->format('Y-m-d') === $d) ? $run + 1 : 1;
            $best = max($best, $run);
            $prev = $d;
        }
        return $best;
    }

    /** Assegna "Campione del mese" al primo in classifica recuperi del mese precedente (almeno 3 recuperi). */
    public static function awardMonthlyChampion(): ?string
    {
        $stamp = LISTENIN_ROOT . '/storage/cache/champion.stamp';
        $month = date('Y-m');
        if (is_file($stamp) && trim((string) file_get_contents($stamp)) === $month) {
            return null;
        }
        $top = Database::one("SELECT c.user_id, COUNT(*) n FROM listenin_checkins c JOIN listenin_users u ON u.id = c.user_id
                               WHERE c.recovered = 1 AND u.status = 'active' AND c.created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01') AND c.created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')
                               GROUP BY c.user_id ORDER BY n DESC, MIN(c.created_at) LIMIT 1");
        @file_put_contents($stamp, $month);
        if (!$top || (int) $top['n'] < 3) {
            return null;
        }
        Database::run("INSERT IGNORE INTO listenin_user_badges (user_id, badge_code) VALUES (:u, 'campione-del-mese')", ['u' => $top['user_id']]);
        return (string) Database::value('SELECT nickname FROM listenin_users WHERE id = :u', ['u' => $top['user_id']]);
    }

    public static function badges(int $userId): array
    {
        $rows = Database::all(
            'SELECT b.code, b.name, b.description, ub.awarded_at FROM listenin_badges b
               LEFT JOIN listenin_user_badges ub ON ub.badge_code = b.code AND ub.user_id = :u ORDER BY b.sort_order', ['u' => $userId]);
        return array_map(static fn($r) => ['code' => $r['code'], 'name' => $r['name'], 'description' => $r['description'],
            'earned' => $r['awarded_at'] !== null, 'awarded_at' => $r['awarded_at']], $rows);
    }

    /** @param string $period punti | recuperi_mese | recuperi */
    public static function leaderboard(string $period, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        if ($period === 'punti_mese') {
            $rows = Database::all("SELECT u.nickname, SUM(p.points) AS value FROM listenin_points p JOIN listenin_users u ON u.id = p.user_id
                                    WHERE u.status = 'active' AND p.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') GROUP BY u.id, u.nickname HAVING value > 0 ORDER BY value DESC, u.nickname LIMIT $limit");
        } elseif ($period === 'punti') {
            $rows = Database::all("SELECT nickname, points AS value FROM listenin_users WHERE status = 'active' AND points > 0 ORDER BY points DESC, nickname LIMIT $limit");
        } else {
            $where = $period === 'recuperi_mese' ? "AND c.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')" : '';
            $rows = Database::all("SELECT u.nickname, COUNT(*) AS value FROM listenin_checkins c JOIN listenin_users u ON u.id = c.user_id
                                    WHERE c.recovered = 1 AND u.status = 'active' $where GROUP BY u.id, u.nickname ORDER BY value DESC, u.nickname LIMIT $limit");
        }
        return array_map(static fn($r, $i) => ['rank' => $i + 1, 'nickname' => $r['nickname'], 'value' => (int) $r['value']], $rows, array_keys($rows));
    }
}
