<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\ApiException;
use ListenIn\Core\Auth;
use ListenIn\Core\Database;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Core\Validator;
use ListenIn\Domain\ModerationLog;

final class AdminController
{
    public static function users(Request $r): void
    {
        Auth::requireRole('admin');
        $q = $r->queryString('q', 80);
        $params = [];
        $where = '';
        if ($q !== null) {
            $where = 'WHERE nickname LIKE :q OR email LIKE :q2';
            $params = ['q' => '%' . addcslashes($q, '%_\\') . '%', 'q2' => '%' . addcslashes($q, '%_\\') . '%'];
        }
        $rows = Database::all(
            "SELECT id, nickname, email, role, status, created_at, last_login_at,
                    (SELECT COUNT(*) FROM listenin_revisions r WHERE r.author_id = u.id AND r.status = 'approved') AS approved
               FROM listenin_users u $where ORDER BY created_at DESC LIMIT 100", $params);
        Response::json(['items' => array_map(static fn($u) => [
            'id' => (int) $u['id'], 'nickname' => $u['nickname'], 'email' => $u['email'], 'role' => $u['role'],
            'status' => $u['status'], 'created_at' => $u['created_at'], 'last_login_at' => $u['last_login_at'],
            'approved' => (int) $u['approved'],
        ], $rows)]);
    }

    public static function updateUser(Request $r, array $p): void
    {
        $admin = Auth::requireRole('admin');
        if ($p['id'] === (int) $admin['id']) {
            throw ApiException::forbidden('Non puoi modificare il tuo ruolo o stato.');
        }
        $v = new Validator($r->json());
        $role = $v->enum('role', ['user', 'moderator', 'admin'], false);
        $status = $v->enum('status', ['active', 'suspended'], false);
        $v->throwIfErrors();
        $target = Database::one('SELECT id, status FROM listenin_users WHERE id = :id', ['id' => $p['id']]) ?? throw ApiException::notFound();
        if ($status !== null && $target['status'] === 'pending') {
            throw new ApiException(409, 'pending', 'L\'utente non ha ancora verificato l\'email.');
        }
        if ($role !== null) {
            Database::run('UPDATE listenin_users SET role = :r WHERE id = :id', ['r' => $role, 'id' => $p['id']]);
            ModerationLog::add((int) $admin['id'], 'user.role.' . $role, 'user', $p['id']);
        }
        if ($status !== null) {
            Database::run('UPDATE listenin_users SET status = :s WHERE id = :id', ['s' => $status, 'id' => $p['id']]);
            ModerationLog::add((int) $admin['id'], 'user.status.' . $status, 'user', $p['id']);
        }
        Response::json(['message' => 'Utente aggiornato.']);
    }
}
