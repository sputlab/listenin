<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\Auth;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Domain\WebhookService;

/** Webhook personali (utenti con email verificata) e controllo da parte degli amministratori. */
final class WebhookController
{
    public static function index(Request $r): void
    {
        $u = Auth::require();
        Response::json(['items' => WebhookService::listFor((int) $u['id']), 'available' => WebhookService::available(), 'events' => WebhookService::EVENTS]);
    }

    public static function create(Request $r): void
    {
        $u = Auth::require();
        $res = WebhookService::create((int) $u['id'], $r->json());
        Response::json($res + ['message' => 'Webhook creato. Copia ora il segreto di firma: non verrà più mostrato.'], 201);
    }

    public static function update(Request $r, array $p): void
    {
        $u = Auth::require();
        Response::json(['webhook' => WebhookService::update($p['id'], (int) $u['id'], $r->json()), 'message' => 'Webhook aggiornato.']);
    }

    public static function delete(Request $r, array $p): void
    {
        $u = Auth::require();
        WebhookService::delete($p['id'], (int) $u['id']);
        Response::json(['message' => 'Webhook eliminato.']);
    }

    public static function test(Request $r, array $p): void
    {
        $u = Auth::require();
        $res = WebhookService::ping($p['id'], (int) $u['id']);
        Response::json($res + ['message' => $res['ok'] ? 'Consegna di prova riuscita.' : 'Consegna di prova non riuscita: ' . ($res['error'] ?? 'errore sconosciuto')]);
    }

    public static function rotate(Request $r, array $p): void
    {
        $u = Auth::require();
        Response::json(['secret' => WebhookService::rotateSecret($p['id'], (int) $u['id']), 'message' => 'Nuovo segreto generato: copialo ora, non verrà più mostrato.']);
    }

    public static function deliveries(Request $r, array $p): void
    {
        $u = Auth::require();
        Response::json(['items' => WebhookService::deliveries($p['id'], (int) $u['id'])]);
    }

    public static function adminList(Request $r): void
    {
        Auth::requireRole('admin');
        Response::json(['items' => WebhookService::listAll()]);
    }

    public static function adminDisable(Request $r, array $p): void
    {
        $u = Auth::requireRole('admin');
        Response::json(['webhook' => WebhookService::adminDisable($p['id'], (int) $u['id'], (string) ($r->json()['reason'] ?? 'Disattivato da un amministratore')), 'message' => 'Webhook disattivato.']);
    }
}
