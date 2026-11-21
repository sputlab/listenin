<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\Config;
use ListenIn\Core\Database;

/** Tabelle di riferimento caricate una volta per richiesta. */
final class Taxonomy
{
    private static ?array $cache = null;

    public const STATUSES = [
        'attivo'        => 'Attivo',
        'inattivo'      => 'Inattivo',
        'sperimentale'  => 'Sperimentale',
        'da_verificare' => 'Da verificare',
        'contestata'    => 'Contestata',
    ];

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $services = Database::all('SELECT id, parent_id, slug, name, color, sort_order FROM listenin_services ORDER BY sort_order, name');
        $modes = Database::all('SELECT id, code, name, family, default_bandwidth_hz, access_code FROM listenin_modes ORDER BY sort_order');
        $itu = Database::all('SELECT id, code, name, min_hz, max_hz FROM listenin_itu_bands ORDER BY min_hz');
        $plans = Database::all('SELECT id, slug, name, min_hz, max_hz, service_id FROM listenin_band_plans ORDER BY min_hz');
        $countries = Database::all('SELECT iso2, name FROM listenin_countries ORDER BY name');
        $regions = Database::all('SELECT id, country_iso2, slug, name FROM listenin_regions ORDER BY name');
        $provinces = Database::all('SELECT p.id, p.region_id, p.code, p.name FROM listenin_provinces p ORDER BY p.name');

        $byId = static fn(array $rows, string $k = 'id') => array_column($rows, null, $k);
        $c = [
            'services' => $byId($services),
            'servicesBySlug' => $byId($services, 'slug'),
            'modes' => $byId($modes),
            'modesByCode' => $byId($modes, 'code'),
            'itu' => $byId($itu),
            'ituByCode' => $byId($itu, 'code'),
            'plans' => $byId($plans),
            'plansBySlug' => $byId($plans, 'slug'),
            'countries' => $byId($countries, 'iso2'),
            'regions' => $byId($regions),
            'regionsBySlug' => $byId($regions, 'slug'),
            'provinces' => $byId($provinces),
            'provincesByCode' => $byId($provinces, 'code'),
        ];
        return self::$cache = $c;
    }

    public static function get(string $table): array
    {
        return self::load()[$table];
    }

    public static function service(int|string|null $key): ?array
    {
        if ($key === null) {
            return null;
        }
        $t = self::load();
        return is_int($key) ? ($t['services'][$key] ?? null) : ($t['servicesBySlug'][$key] ?? null);
    }

    /** Ambito di primo livello di un servizio. */
    public static function rootService(int $serviceId): ?array
    {
        $s = self::service($serviceId);
        while ($s && $s['parent_id'] !== null) {
            $s = self::service((int) $s['parent_id']);
        }
        return $s;
    }

    /** Id del servizio e di tutti i discendenti (per filtrare un ambito). */
    public static function serviceWithDescendants(int $id): array
    {
        $ids = [$id];
        foreach (self::get('services') as $s) {
            if ((int) $s['parent_id'] === $id) {
                $ids = array_merge($ids, self::serviceWithDescendants((int) $s['id']));
            }
        }
        return $ids;
    }

    public static function mode(int|string|null $key): ?array
    {
        if ($key === null) {
            return null;
        }
        $t = self::load();
        return is_int($key) ? ($t['modes'][$key] ?? null) : ($t['modesByCode'][strtoupper($key)] ?? null);
    }

    public static function region(int|string|null $key): ?array
    {
        if ($key === null) {
            return null;
        }
        $t = self::load();
        return is_int($key) ? ($t['regions'][$key] ?? null) : ($t['regionsBySlug'][$key] ?? null);
    }

    public static function province(int|string|null $key): ?array
    {
        if ($key === null) {
            return null;
        }
        $t = self::load();
        return is_int($key) ? ($t['provinces'][$key] ?? null) : ($t['provincesByCode'][strtoupper($key)] ?? null);
    }

    public static function ituBandId(int $hz): ?int
    {
        $code = Normalizer::ituBandCode($hz);
        if ($code === null || !isset(self::load()['ituByCode'][$code])) {
            return null;
        }
        return (int) self::load()['ituByCode'][$code]['id'];
    }

    /** Segmento di piano più stretto che contiene la frequenza. */
    public static function bandPlanId(int $hz): ?int
    {
        $best = null;
        foreach (self::get('plans') as $p) {
            if ($hz >= (int) $p['min_hz'] && $hz < (int) $p['max_hz']) {
                $width = (int) $p['max_hz'] - (int) $p['min_hz'];
                if ($best === null || $width < $best[1]) {
                    $best = [(int) $p['id'], $width];
                }
            }
        }
        return $best[0] ?? null;
    }

    /** Dati di riferimento per il frontend. */
    public static function forClient(): array
    {
        $t = self::load();
        $tree = [];
        foreach ($t['services'] as $s) {
            if ($s['parent_id'] === null) {
                $tree[(int) $s['id']] = ['slug' => $s['slug'], 'name' => $s['name'], 'color' => $s['color'], 'children' => []];
            }
        }
        foreach ($t['services'] as $s) {
            if ($s['parent_id'] !== null && isset($tree[(int) $s['parent_id']])) {
                $tree[(int) $s['parent_id']]['children'][] = ['slug' => $s['slug'], 'name' => $s['name']];
            }
        }
        $provincesByRegion = [];
        foreach ($t['provinces'] as $p) {
            $provincesByRegion[$t['regions'][(int) $p['region_id']]['slug']][] = ['code' => $p['code'], 'name' => $p['name'], 'slug' => trim(str_replace(' ', '-', Normalizer::key($p['name'])), '-')];
        }
        return [
            'services' => array_values($tree),
            'modes' => array_map(static fn($m) => [
                'code' => $m['code'], 'name' => $m['name'], 'family' => $m['family'],
                'bandwidth_hz' => $m['default_bandwidth_hz'] !== null ? (int) $m['default_bandwidth_hz'] : null,
                'access_code' => $m['access_code'],
            ], array_values($t['modes'])),
            'itu_bands' => array_map(static fn($b) => [
                'code' => $b['code'], 'name' => $b['name'], 'min_hz' => (int) $b['min_hz'], 'max_hz' => (int) $b['max_hz'],
            ], array_values($t['itu'])),
            'band_plans' => array_map(static fn($b) => [
                'slug' => $b['slug'], 'name' => $b['name'], 'min_hz' => (int) $b['min_hz'], 'max_hz' => (int) $b['max_hz'],
            ], array_values($t['plans'])),
            'countries' => array_values(array_map(static fn($c) => ['iso2' => $c['iso2'], 'name' => $c['name']], $t['countries'])),
            'regions' => array_values(array_map(static fn($r) => ['slug' => $r['slug'], 'name' => $r['name'], 'country' => $r['country_iso2']], $t['regions'])),
            'provinces' => $provincesByRegion,
            'statuses' => array_map(static fn($k, $v) => ['value' => $k, 'label' => $v], array_keys(self::STATUSES), self::STATUSES),
            'ctcss' => array_map(static fn($t) => number_format($t, 1, '.', ''), Normalizer::CTCSS_TONES),
            'fields' => FrequencyFields::LABELS,
            'reliability' => array_map(static fn($k, $v) => ['value' => $k, 'label' => $v], array_keys(Reliability::LEVELS), Reliability::LEVELS),
            'bandplan_rules' => BandPlanRules::all(),
            'sdr' => (array) \ListenIn\Core\Config::get('sdr.receivers', SdrLinks::DEFAULT),
            'map' => ['tile_url' => (string) \ListenIn\Core\Config::get('map.tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
                'attribution' => (string) \ListenIn\Core\Config::get('map.attribution', '© contributori di OpenStreetMap')],
            'disclaimer_version' => (string) Config::get('legal.disclaimer_version'),
        ];
    }
}
