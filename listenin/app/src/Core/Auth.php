<?php
declare(strict_types=1);

namespace ListenIn\Core;

/** Utente corrente e controlli di autorizzazione basati sui ruoli. */
final class Auth
{
    private static array|false|null $user = null;

    public static function user(): ?array
    {
        if (self::$user === null) {
            $id = Session::userId();
            $row = $id ? Database::one(
                'SELECT id, email, nickname, role, status, email_verified_at, disclaimer_version, disclaimer_accepted_at, created_at, trust_score, points, default_locator
                   FROM listenin_users WHERE id = :id',
                ['id' => $id]
            ) : null;
            if ($row && $row['status'] !== 'active') {
                Session::logout();
                $row = null;
            }
            self::$user = $row ?: false;
        }
        return self::$user ?: null;
    }

    public static function forget(): void
    {
        self::$user = null;
    }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) {
            throw ApiException::unauthorized();
        }
        return $u;
    }

    public static function requireRole(string $role): array
    {
        $u = self::require();
        $rank = ['user' => 1, 'moderator' => 2, 'admin' => 3];
        if (($rank[$u['role']] ?? 0) < ($rank[$role] ?? 99)) {
            throw ApiException::forbidden();
        }
        return $u;
    }

    public static function isModerator(?array $u = null): bool
    {
        $u ??= self::user();
        return $u !== null && in_array($u['role'], ['moderator', 'admin'], true);
    }

    public static function hasCurrentDisclaimer(?array $u): bool
    {
        return $u !== null && $u['disclaimer_version'] === (string) Config::get('legal.disclaimer_version');
    }

    /** Rappresentazione pubblica dell'utente per il frontend. */
    public static function publicView(?array $u): ?array
    {
        if (!$u) {
            return null;
        }
        return [
            'id' => (int) $u['id'],
            'nickname' => $u['nickname'],
            'email' => $u['email'],
            'role' => $u['role'],
            'disclaimer_current' => self::hasCurrentDisclaimer($u),
            'member_since' => substr((string) $u['created_at'], 0, 10),
            'points' => (int) ($u['points'] ?? 0),
            'trust_score' => (int) ($u['trust_score'] ?? 0),
            'default_locator' => $u['default_locator'] ?? null,
        ];
    }
}
