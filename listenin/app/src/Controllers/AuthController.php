<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\Auth;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Core\Security;
use ListenIn\Domain\UserService;

final class AuthController
{
    public static function register(Request $r): void { Response::json(UserService::register($r->json()), 202); }
    public static function verify(Request $r): void { Response::json(UserService::verifyEmail((string) ($r->json()['token'] ?? ''))); }
    public static function resend(Request $r): void { Response::json(UserService::resendVerification($r->json()), 202); }
    public static function login(Request $r): void { Response::json(UserService::login($r->json())); }
    public static function logout(Request $r): void { Response::json(UserService::logout()); }
    public static function forgot(Request $r): void { Response::json(UserService::forgotPassword($r->json()), 202); }
    public static function reset(Request $r): void { Response::json(UserService::resetPassword($r->json())); }

    public static function me(Request $r): void
    {
        Response::json(['user' => Auth::publicView(Auth::user()), 'csrf' => Security::csrfToken()]);
    }
}
