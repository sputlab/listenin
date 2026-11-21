<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\Auth;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Domain\ReportService;

final class ReportController
{
    public static function store(Request $r, array $p): void
    {
        $res = ReportService::create($p['id'], $r->json(), Auth::user());
        Response::json($res + ['message' => 'Segnalazione inviata, grazie. Un moderatore la esaminerà.'], 201);
    }
}
