<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\Database;

final class ModerationLog
{
    public static function add(?int $actorId, string $action, string $targetType, int $targetId, ?string $note = null): void
    {
        Database::run(
            'INSERT INTO listenin_moderation_log (actor_id, action, target_type, target_id, note) VALUES (:a, :ac, :t, :id, :n)',
            ['a' => $actorId, 'ac' => $action, 't' => $targetType, 'id' => $targetId, 'n' => $note !== null ? mb_substr($note, 0, 500) : null]
        );
    }
}
