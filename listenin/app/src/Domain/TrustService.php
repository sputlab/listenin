<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\Config;
use ListenIn\Core\Database;

/**
 * Trust Score dei contributori: cresce con le proposte approvate e cala con quelle respinte.
 * Chi supera la soglia ottiene l'approvazione automatica delle sole modifiche "minori".
 */
final class TrustService
{
    /** Campi la cui modifica non cambia l'identità del record. */
    public const MINOR_FIELDS = ['last_heard_at', 'notes', 'channel', 'callsign', 'entity', 'area_note', 'locator', 'latitude', 'longitude',
        'coverage_km', 'municipality', 'ctcss_hz', 'dcs_code', 'tone_type', 'talkgroup', 'color_code', 'dmr_timeslot', 'bandwidth_hz'];

    public static function threshold(): int
    {
        return (int) Config::get('trust.auto_approve_min', 20);
    }

    public static function onApproved(?int $authorId, string $origin): void
    {
        if ($authorId !== null && in_array($origin, ['user', 'report'], true)) {
            Database::run('UPDATE listenin_users SET trust_score = trust_score + :d WHERE id = :u', ['d' => $origin === 'user' ? 2 : 1, 'u' => $authorId]);
        }
    }

    public static function onRejected(?int $authorId): void
    {
        if ($authorId !== null) {
            Database::run('UPDATE listenin_users SET trust_score = GREATEST(0, trust_score - 3) WHERE id = :u', ['u' => $authorId]);
        }
    }

    /** Approvazione automatica: punteggio sufficiente e modifica composta solo da campi minori. */
    public static function canAutoApprove(array $user, array $changedFields): bool
    {
        if ((int) ($user['trust_score'] ?? 0) < self::threshold() || !$changedFields) {
            return false;
        }
        $allowed = (array) Config::get('trust.minor_fields', self::MINOR_FIELDS);
        return count(array_diff($changedFields, $allowed)) === 0;
    }
}
