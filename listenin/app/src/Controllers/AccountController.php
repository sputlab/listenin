<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\Auth;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Domain\UserService;

final class AccountController
{
    public static function show(Request $r): void
    {
        Response::json(['user' => Auth::publicView(Auth::require())]);
    }

    public static function update(Request $r): void
    {
        Response::json(UserService::updateProfile(Auth::require(), $r->json()));
    }

    public static function email(Request $r): void
    {
        Response::json(UserService::requestEmailChange(Auth::require(), $r->json()), 202);
    }

    /** Conferma dal link email: non richiede la sessione (il token è sufficiente). */
    public static function emailConfirm(Request $r): void
    {
        Response::json(UserService::confirmEmailChange((string) ($r->json()['token'] ?? '')));
    }

    public static function password(Request $r): void
    {
        Response::json(UserService::changePassword(Auth::require(), $r->json()));
    }

    public static function disclaimer(Request $r): void
    {
        $u = Auth::require();
        UserService::acceptDisclaimer((int) $u['id']);
        Response::json(['user' => Auth::publicView(Auth::user())]);
    }

    public static function contributions(Request $r): void
    {
        Response::json(UserService::contributions((int) Auth::require()['id'], $r->queryInt('page', 1, 1, 10000)));
    }

    public static function reports(Request $r): void
    {
        Response::json(UserService::reports((int) Auth::require()['id'], $r->queryInt('page', 1, 1, 10000)));
    }

    public static function delete(Request $r): void
    {
        Response::json(UserService::deleteAccount(Auth::require(), $r->json()));
    }
}
