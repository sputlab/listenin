<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\Database;

/**
 * Posizione di ogni record, memorizzata in geo_lat/geo_lon con la sua origine, per filtri di prossimità e distanze.
 * Ordine di precisione: coordinate proprie, postazione, locatore Maidenhead, baricentro del comune, baricentro della provincia.
 * Le ultime due sono approssimate: l'interfaccia lo segnala e il filtro di prossimità le esclude se non richieste.
 */
final class GeoService
{
    public const SOURCES = ['coordinate' => 'coordinate del record', 'sito' => 'postazione', 'locatore' => 'locatore Maidenhead',
        'comune' => 'centro del comune', 'provincia' => 'centro della provincia'];

    /** @return array{0:float,1:float,2:string}|null [lat, lon, origine] */
    public static function position(array $r): ?array
    {
        if ($r['latitude'] !== null && $r['longitude'] !== null) {
            return [(float) $r['latitude'], (float) $r['longitude'], 'coordinate'];
        }
        if ($r['site_lat'] !== null && $r['site_lon'] !== null) {
            return [(float) $r['site_lat'], (float) $r['site_lon'], 'sito'];
        }
        if (!empty($r['locator']) && ($c = Normalizer::locatorToLatLon((string) $r['locator']))) {
            return [$c[0], $c[1], 'locatore'];
        }
        if ($r['mu_lat'] !== null && $r['mu_lon'] !== null) {
            return [(float) $r['mu_lat'], (float) $r['mu_lon'], 'comune'];
        }
        if ($r['pr_lat'] !== null && $r['pr_lon'] !== null) {
            return [(float) $r['pr_lat'], (float) $r['pr_lon'], 'provincia'];
        }
        return null;
    }

    /** Ricalcola e salva la posizione di un record. */
    public static function refresh(int $id): void
    {
        $r = Database::one(
            'SELECT f.latitude, f.longitude, f.locator, st.latitude AS site_lat, st.longitude AS site_lon,
                    mu.latitude AS mu_lat, mu.longitude AS mu_lon, pr.latitude AS pr_lat, pr.longitude AS pr_lon
               FROM listenin_frequencies f
               LEFT JOIN listenin_sites st ON st.id = f.site_id
               LEFT JOIN listenin_municipalities mu ON mu.id = f.municipality_id
               LEFT JOIN listenin_provinces pr ON pr.id = f.province_id
              WHERE f.id = :id', ['id' => $id]);
        if (!$r) {
            return;
        }
        $p = self::position($r);
        Database::run('UPDATE listenin_frequencies SET geo_lat = :la, geo_lon = :lo, geo_source = :s WHERE id = :id',
            ['la' => $p[0] ?? null, 'lo' => $p[1] ?? null, 's' => $p[2] ?? null, 'id' => $id]);
    }

    /** Dopo l'importazione dei baricentri dei comuni: migliora i record che ricadevano sul centro provincia. */
    public static function upgradeFromMunicipalities(): int
    {
        return Database::run(
            "UPDATE listenin_frequencies f JOIN listenin_municipalities mu ON mu.id = f.municipality_id
                SET f.geo_lat = mu.latitude, f.geo_lon = mu.longitude, f.geo_source = 'comune'
              WHERE mu.latitude IS NOT NULL AND (f.geo_source IS NULL OR f.geo_source = 'provincia')")->rowCount();
    }

    /** Frequenze per provincia e regione (con quota di affidabilità alta), in cache per pochi minuti. */
    public static function summary(int $ttl = 300): array
    {
        $f = LISTENIN_ROOT . '/storage/cache/geo-summary.json';
        if (is_file($f) && time() - filemtime($f) < $ttl && ($c = json_decode((string) file_get_contents($f), true))) {
            return $c;
        }
        $rel = Reliability::sql('f');
        $mk = static function (array $rows, string $key): array {
            $o = [];
            foreach ($rows as $r) {
                $o[$r[$key]] = ['count' => (int) $r['n'], 'alta' => (int) $r['alta'], 'verified_percent' => $r['n'] ? round(100 * (int) $r['alta'] / (int) $r['n'], 1) : 0];
            }
            return $o;
        };
        $data = [
            'provinces' => $mk(Database::all("SELECT p.code, COUNT(*) n, SUM(($rel) = 'alta') alta FROM listenin_frequencies f JOIN listenin_provinces p ON p.id = f.province_id
                                               WHERE f.deleted_at IS NULL GROUP BY p.code"), 'code'),
            'regions' => $mk(Database::all("SELECT r.slug, COUNT(*) n, SUM(($rel) = 'alta') alta FROM listenin_frequencies f JOIN listenin_regions r ON r.id = f.region_id
                                             WHERE f.deleted_at IS NULL GROUP BY r.slug"), 'slug'),
            'total' => (int) Database::value('SELECT COUNT(*) FROM listenin_frequencies f WHERE f.deleted_at IS NULL'),
            'unlocated' => (int) Database::value('SELECT COUNT(*) FROM listenin_frequencies f WHERE f.deleted_at IS NULL AND f.province_id IS NULL AND f.region_id IS NULL'),
        ];
        @file_put_contents($f, json_encode($data), LOCK_EX);
        return $data;
    }

    /** Stato dei dati geografici (per l'amministratore). */
    public static function status(): array
    {
        return [
            'municipalities' => (int) Database::value('SELECT COUNT(*) FROM listenin_municipalities'),
            'municipalities_located' => (int) Database::value('SELECT COUNT(*) FROM listenin_municipalities WHERE latitude IS NOT NULL'),
            'by_source' => array_column(Database::all('SELECT COALESCE(geo_source, \'nessuna\') s, COUNT(*) n FROM listenin_frequencies WHERE deleted_at IS NULL GROUP BY s'), 'n', 's'),
        ];
    }

    /** Espressione SQL della distanza (km, formula di Haversine) dal punto dato. I valori sono numeri già validati. */
    public static function haversineSql(float $lat, float $lon, string $alias = 'f'): string
    {
        $la = sprintf('%.6F', $lat);
        $lo = sprintf('%.6F', $lon);
        return "(6371.0088 * 2 * ASIN(SQRT(LEAST(1, POW(SIN(RADIANS({$alias}.geo_lat - {$la}) / 2), 2)
                + COS(RADIANS({$la})) * COS(RADIANS({$alias}.geo_lat)) * POW(SIN(RADIANS({$alias}.geo_lon - {$lo}) / 2), 2)))))";
    }
}
