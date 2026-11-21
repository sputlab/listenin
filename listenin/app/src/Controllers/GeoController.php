<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\ApiException;
use ListenIn\Core\Auth;
use ListenIn\Core\Database;
use ListenIn\Core\RateLimiter;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Core\Security;
use ListenIn\Domain\GeoService;
use ListenIn\Domain\Normalizer;
use ListenIn\Domain\SiteService;

/** Cartina, ricerca di luoghi e postazioni. */
final class GeoController
{
    /** Conteggi per provincia e regione per la cartina coropletica. */
    public static function summary(Request $r): void
    {
        RateLimiter::hit('api_read', Security::clientIp());
        Response::json(GeoService::summary());
    }

    /** Ricerca di un comune (con coordinate) come punto di partenza del filtro di prossimità. */
    public static function place(Request $r): void
    {
        RateLimiter::hit('dup_check', Security::clientIp());
        $q = Normalizer::key((string) $r->queryString('q', 60));
        if (mb_strlen($q) < 2) {
            Response::json(['items' => []]);
        }
        $rows = Database::all(
            'SELECT mu.name, p.code AS province, mu.latitude, mu.longitude FROM listenin_municipalities mu JOIN listenin_provinces p ON p.id = mu.province_id
              WHERE mu.latitude IS NOT NULL AND mu.name_key LIKE :q ORDER BY (mu.name_key = :exact) DESC, CHAR_LENGTH(mu.name_key), mu.name LIMIT 8',
            ['q' => addcslashes($q, '%_\\') . '%', 'exact' => $q]);
        Response::json(['items' => array_map(static fn($x) => ['name' => $x['name'], 'province' => $x['province'], 'lat' => (float) $x['latitude'], 'lon' => (float) $x['longitude']], $rows),
            'has_coordinates' => (bool) Database::value('SELECT 1 FROM listenin_municipalities WHERE latitude IS NOT NULL LIMIT 1')]);
    }

    public static function sites(Request $r): void
    {
        RateLimiter::hit('api_read', Security::clientIp());
        Response::json(SiteService::list(['q' => $r->queryString('q', 60), 'province' => $r->queryString('province', 2), 'region' => $r->queryString('region', 48)],
            $r->queryInt('page', 1, 1, 10000)));
    }

    public static function siteSuggest(Request $r): void
    {
        RateLimiter::hit('dup_check', Security::clientIp());
        Response::json(['items' => SiteService::suggest((string) $r->queryString('q', 60))]);
    }

    public static function siteShow(Request $r): void
    {
        RateLimiter::hit('api_read', Security::clientIp());
        $slug = (string) $r->queryString('slug', 120);
        if (!preg_match('/^[a-z0-9-]{1,120}$/', $slug)) {
            throw ApiException::notFound('Postazione non trovata.');
        }
        Response::json(SiteService::show($slug));
    }

    public static function siteUpdate(Request $r, array $p): void
    {
        $user = Auth::requireRole('moderator');
        Response::json(SiteService::update($p['id'], $r->json(), (int) $user['id']) + ['message' => 'Postazione aggiornata.']);
    }
}
