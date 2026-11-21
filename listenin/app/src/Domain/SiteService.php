<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\ApiException;
use ListenIn\Core\Database;
use ListenIn\Core\Validator;

/** Postazioni e monti: un sito geografico che raggruppa i ripetitori e i servizi installati nello stesso posto. */
final class SiteService
{
    public static function slugFor(string $name, ?string $provinceCode): string
    {
        $base = trim(str_replace(' ', '-', Normalizer::key($name)), '-') ?: 'postazione';
        $slug = substr($base . ($provinceCode ? '-' . strtolower($provinceCode) : ''), 0, 110);
        $n = 1;
        $try = $slug;
        while (Database::value('SELECT 1 FROM listenin_sites WHERE slug = :s', ['s' => $try])) {
            $try = $slug . '-' . (++$n);
        }
        return $try;
    }

    /** Trova la postazione con quel nome nella provincia, oppure la crea. */
    public static function resolve(string $name, ?int $provinceId, ?int $municipalityId, ?float $lat, ?float $lon, ?int $altitude): int
    {
        $key = Normalizer::key($name);
        $existing = Database::value('SELECT id FROM listenin_sites WHERE name_key = :k AND province_id <=> :p LIMIT 1', ['k' => $key, 'p' => $provinceId]);
        if ($existing) {
            return (int) $existing;
        }
        $code = $provinceId ? Taxonomy::province($provinceId)['code'] ?? null : null;
        return Database::insert(
            'INSERT INTO listenin_sites (slug, name, name_key, province_id, municipality_id, latitude, longitude, altitude_m)
             VALUES (:s, :n, :k, :p, :m, :la, :lo, :al)',
            ['s' => self::slugFor($name, $code), 'n' => mb_substr(trim($name), 0, 100), 'k' => $key, 'p' => $provinceId, 'm' => $municipalityId,
                'la' => $lat, 'lo' => $lon, 'al' => $altitude]);
    }

    public static function suggest(string $q, int $limit = 8): array
    {
        $key = Normalizer::key($q);
        if (mb_strlen($key) < 2) {
            return [];
        }
        $rows = Database::all(
            'SELECT st.slug, st.name, st.altitude_m, p.code AS province FROM listenin_sites st LEFT JOIN listenin_provinces p ON p.id = st.province_id
              WHERE st.name_key LIKE :q ORDER BY st.name LIMIT ' . (int) $limit, ['q' => '%' . addcslashes($key, '%_\\') . '%']);
        return array_map(static fn($r) => ['slug' => $r['slug'], 'name' => $r['name'], 'altitude_m' => $r['altitude_m'] !== null ? (int) $r['altitude_m'] : null, 'province' => $r['province']], $rows);
    }

    private static function present(array $r, array $services = []): array
    {
        return ['id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'province' => $r['province_code'], 'province_name' => $r['province_name'],
            'region' => $r['region_slug'], 'municipality' => $r['municipality_name'], 'latitude' => $r['latitude'] !== null ? (float) $r['latitude'] : null,
            'longitude' => $r['longitude'] !== null ? (float) $r['longitude'] : null, 'altitude_m' => $r['altitude_m'] !== null ? (int) $r['altitude_m'] : null,
            'locator' => $r['locator'], 'notes' => $r['notes'], 'count' => (int) ($r['n'] ?? 0), 'services' => $services];
    }

    private const SELECT = 'SELECT st.*, p.code AS province_code, p.name AS province_name, r.slug AS region_slug, mu.name AS municipality_name,
            (SELECT COUNT(*) FROM listenin_frequencies f WHERE f.site_id = st.id AND f.deleted_at IS NULL) AS n
        FROM listenin_sites st LEFT JOIN listenin_provinces p ON p.id = st.province_id
        LEFT JOIN listenin_regions r ON r.id = p.region_id LEFT JOIN listenin_municipalities mu ON mu.id = st.municipality_id';

    private static function services(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach (Database::all("SELECT f.site_id, COALESCE(par.name, s.name) AS name, COUNT(*) AS n FROM listenin_frequencies f
                                  JOIN listenin_services s ON s.id = f.service_id LEFT JOIN listenin_services par ON par.id = s.parent_id
                                 WHERE f.deleted_at IS NULL AND f.site_id IN ($in) GROUP BY f.site_id, COALESCE(par.id, s.id), COALESCE(par.name, s.name) ORDER BY n DESC") as $r) {
            $out[(int) $r['site_id']][] = ['name' => $r['name'], 'count' => (int) $r['n']];
        }
        return $out;
    }

    public static function list(array $q, int $page, int $perPage = 24): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($q['q']) && mb_strlen(Normalizer::key($q['q'])) >= 2) {
            $where[] = 'st.name_key LIKE :q';
            $params['q'] = '%' . addcslashes(Normalizer::key($q['q']), '%_\\') . '%';
        }
        if (!empty($q['province']) && ($p = Taxonomy::province(strtoupper($q['province'])))) {
            $where[] = 'st.province_id = :p';
            $params['p'] = (int) $p['id'];
        } elseif (!empty($q['region']) && ($rg = Taxonomy::region($q['region']))) {
            $where[] = 'p.region_id = :rg';
            $params['rg'] = (int) $rg['id'];
        }
        $w = implode(' AND ', $where);
        $from = ' FROM listenin_sites st LEFT JOIN listenin_provinces p ON p.id = st.province_id WHERE ' . $w . ' AND EXISTS (SELECT 1 FROM listenin_frequencies f WHERE f.site_id = st.id AND f.deleted_at IS NULL)';
        $total = (int) Database::value('SELECT COUNT(*)' . $from, $params);
        $rows = Database::all(self::SELECT . ' WHERE ' . $w . ' AND EXISTS (SELECT 1 FROM listenin_frequencies f WHERE f.site_id = st.id AND f.deleted_at IS NULL)
                                ORDER BY n DESC, st.name LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage), $params);
        $svc = self::services(array_column($rows, 'id'));
        return ['items' => array_map(static fn($r) => self::present($r, $svc[(int) $r['id']] ?? []), $rows), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function show(string $slug): array
    {
        $r = Database::one(self::SELECT . ' WHERE st.slug = :s', ['s' => $slug]) ?? throw ApiException::notFound('Postazione non trovata.');
        return self::present($r, self::services([(int) $r['id']])[(int) $r['id']] ?? []);
    }

    /** Modifica dei dati della postazione (moderatori). */
    public static function update(int $id, array $in, int $moderatorId): array
    {
        $row = Database::one('SELECT * FROM listenin_sites WHERE id = :id', ['id' => $id]) ?? throw ApiException::notFound('Postazione non trovata.');
        $v = new Validator($in);
        $name = $v->string('name', 2, 100, false) ?? $row['name'];
        $lat = array_key_exists('latitude', $in) ? $v->float('latitude', -90, 90, false) : ($row['latitude'] !== null ? (float) $row['latitude'] : null);
        $lon = array_key_exists('longitude', $in) ? $v->float('longitude', -180, 180, false) : ($row['longitude'] !== null ? (float) $row['longitude'] : null);
        if (($lat === null) !== ($lon === null)) {
            $v->addError('latitude', 'Indica sia latitudine sia longitudine.');
        }
        $alt = array_key_exists('altitude_m', $in) ? $v->int('altitude_m', -430, 9000, false) : ($row['altitude_m'] !== null ? (int) $row['altitude_m'] : null);
        $loc = $row['locator'];
        if (array_key_exists('locator', $in)) {
            $raw = $v->string('locator', 4, 8, false);
            $loc = $raw !== null ? Normalizer::normalizeLocator($raw) : null;
            if ($raw !== null && $loc === null) {
                $v->addError('locator', 'Locatore Maidenhead non valido.');
            }
        }
        $notes = array_key_exists('notes', $in) ? $v->string('notes', 1, 500, false, true) : $row['notes'];
        $v->throwIfErrors();
        Database::transaction(function () use ($id, $name, $lat, $lon, $alt, $loc, $notes, $moderatorId) {
            Database::run('UPDATE listenin_sites SET name = :n, name_key = :k, latitude = :la, longitude = :lo, altitude_m = :al, locator = :lc, notes = :nt WHERE id = :id',
                ['n' => $name, 'k' => Normalizer::key($name), 'la' => $lat, 'lo' => $lon, 'al' => $alt, 'lc' => $loc, 'nt' => $notes, 'id' => $id]);
            foreach (Database::all('SELECT id FROM listenin_frequencies WHERE site_id = :s', ['s' => $id]) as $f) {
                GeoService::refresh((int) $f['id']);
            }
            ModerationLog::add($moderatorId, 'site.update', 'site', $id, null);
        });
        return self::show((string) Database::value('SELECT slug FROM listenin_sites WHERE id = :id', ['id' => $id]));
    }
}
