<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\ApiException;
use ListenIn\Core\Database;

/**
 * Lettura e scrittura del dataset ufficiale.
 * La scrittura avviene SOLO tramite RevisionService::apply (tutto è storicizzato).
 */
final class FrequencyRepository
{
    private const SORTS = [
        'freq'    => 'f.freq_hz',
        'updated' => 'f.updated_at',
        'heard'   => 'f.last_heard_at',
        'status'  => 'f.status',
        'region'  => 'r.name',
        'service' => 's.name',
        'distance' => 'dist',
    ];

    private const SELECT = '
        SELECT f.id, f.freq_hz, f.offset_hz, f.input_freq_hz, f.tone_type, f.ctcss_hz, f.dcs_code,
               f.color_code, f.dmr_timeslot, f.talkgroup, f.ran, f.nac, f.bandwidth_hz,
               f.label, f.channel, f.area_note, f.locator, f.latitude, f.longitude, f.coverage_km,
               f.callsign, f.status, f.last_heard_at, f.notes, f.source, f.version, f.altitude_m, f.geo_lat, f.geo_lon, f.geo_source, f.site_id,
               f.created_at, f.updated_at, f.deleted_at, f.country_iso2,
               m.code AS mode_code, m.default_bandwidth_hz,
               s.id AS service_id, s.slug AS service_slug, s.name AS service_name,
               r.slug AS region_slug, r.name AS region_name,
               p.code AS province_code, p.name AS province_name,
               mu.name AS municipality_name, e.name AS entity_name,
               st.name AS site_name, st.slug AS site_slug, st.altitude_m AS site_altitude_m,
               ib.code AS itu_code, bp.slug AS plan_slug, bp.name AS plan_name,
               (SELECT GROUP_CONCAT(pp.code ORDER BY pp.code SEPARATOR \',\')
                  FROM listenin_frequency_provinces fp JOIN listenin_provinces pp ON pp.id = fp.province_id
                 WHERE fp.frequency_id = f.id) AS coverage_codes
          FROM listenin_frequencies f
          JOIN listenin_modes m     ON m.id = f.mode_id
          JOIN listenin_services s  ON s.id = f.service_id
          LEFT JOIN listenin_regions r         ON r.id = f.region_id
          LEFT JOIN listenin_provinces p       ON p.id = f.province_id
          LEFT JOIN listenin_municipalities mu ON mu.id = f.municipality_id
          LEFT JOIN listenin_entities e        ON e.id = f.entity_id
          LEFT JOIN listenin_sites st          ON st.id = f.site_id
          LEFT JOIN listenin_itu_bands ib      ON ib.id = f.itu_band_id
          LEFT JOIN listenin_band_plans bp     ON bp.id = f.band_plan_id';

    // ------------------------------------------------------------------
    //  Filtri
    // ------------------------------------------------------------------

    /**
     * Normalizza i filtri ricevuti in query string.
     * Tutti i valori sono validati contro le tassonomie: nessun testo libero arriva all'SQL.
     */
    public static function parseFilters(array $q): array
    {
        $str = static fn(string $k, int $max = 120): ?string =>
            isset($q[$k]) && is_string($q[$k]) && trim($q[$k]) !== '' ? mb_substr(trim($q[$k]), 0, $max) : null;
        $list = static fn(string $k): array =>
            ($v = $str($k, 300)) ? array_values(array_filter(array_map('trim', explode(',', $v)))) : [];

        $f = [];
        if ($v = $str('q', 120)) {
            $f['q'] = $v;
        }
        if (($v = $str('service')) && Taxonomy::service($v)) {
            $f['service'] = $v;
        }
        $modes = array_values(array_filter($list('mode'), static fn($m) => Taxonomy::mode(strtoupper($m)) !== null));
        if ($modes) {
            $f['mode'] = array_map('strtoupper', $modes);
        }
        if (($v = $str('band')) && isset(Taxonomy::get('ituByCode')[strtoupper($v)])) {
            $f['band'] = strtoupper($v);
        }
        if (($v = $str('plan')) && isset(Taxonomy::get('plansBySlug')[$v])) {
            $f['plan'] = $v;
        }
        if (($v = $str('country')) && isset(Taxonomy::get('countries')[strtoupper($v)])) {
            $f['country'] = strtoupper($v);
        }
        if (($v = $str('region')) && Taxonomy::region($v)) {
            $f['region'] = $v;
        }
        if (($v = $str('province')) && Taxonomy::province($v)) {
            $f['province'] = strtoupper($v);
        }
        $statuses = array_values(array_intersect($list('status'), array_keys(Taxonomy::STATUSES)));
        if ($statuses) {
            $f['status'] = $statuses;
        }
        foreach (['fmin', 'fmax'] as $k) {
            if (($v = $str($k)) && ($hz = Normalizer::parseFrequency($v)) !== null) {
                $f[$k] = $hz;
            }
        }
        if (($v = $str('locator', 8)) && preg_match('/^[A-R]{2}(\d{2}([A-X]{2}(\d{2})?)?)?$/i', $v)) {
            $f['locator'] = strtoupper(substr($v, 0, 4)) . strtolower(substr($v, 4));
        }
        if (($v = $str('callsign', 20)) && preg_match('/^[A-Z0-9\/\-]{2,20}$/i', $v)) {
            $f['callsign'] = strtoupper($v);
        }
        if (($v = $str('heard_since', 10)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $f['heard_since'] = $v;
        }
        $levels = array_values(array_intersect($list('affidabilita'), array_keys(Reliability::LEVELS)));
        if ($levels) {
            $f['affidabilita'] = $levels;
        }
        if (($v = $str('near', 40)) && preg_match('/^(-?\d{1,2}(?:\.\d{1,6})?),(-?\d{1,3}(?:\.\d{1,6})?)$/', $v, $m)
            && abs((float) $m[1]) <= 90 && abs((float) $m[2]) <= 180) {
            $km = isset($q['km']) && ctype_digit((string) $q['km']) ? max(1, min(500, (int) $q['km'])) : 30;
            $f['near'] = [(float) $m[1], (float) $m[2], $km];
            if (isset($q['approx']) && $q['approx'] === '1') {
                $f['approx'] = true;
            }
        }
        if (($v = $str('site', 120)) && preg_match('/^[a-z0-9-]{1,120}$/', $v)) {
            $f['site'] = $v;
        }
        if (isset($q['missione']) && $q['missione'] === '1') {
            $f['missione'] = true;
        }
        if (isset($q['digital']) && $q['digital'] === '1') {
            $f['digital'] = true;
        }
        return $f;
    }

    /** Costruisce la clausola WHERE con parametri vincolati. */
    private static function where(array $f, array $exclude = []): array
    {
        $w = ['f.deleted_at IS NULL'];
        $p = [];

        if (!empty($f['q']) && !in_array('q', $exclude, true)) {
            $q = $f['q'];
            // Ricerca per frequenza: "145.6", "145,600", "433.075 MHz" → intervallo per prefisso.
            // Un intero senza unità (es. "118") cerca sia la frequenza sia il testo.
            if (preg_match('/^\s*(\d{1,6})(?:[.,](\d{1,6}))?\s*(mhz|khz)?\s*$/i', $q, $m)) {
                $unitExp = strtolower($m[3] ?? 'mhz') === 'khz' ? 3 : 6;
                $decimals = strlen($m[2] ?? '');
                $low = Normalizer::parseFrequency($m[1] . '.' . ($m[2] ?? '0'), $unitExp === 3 ? 'kHz' : 'MHz');
                $range = null;
                if ($low !== null) {
                    $range = '(f.freq_hz >= :q_lo AND f.freq_hz < :q_hi)';
                    $p['q_lo'] = $low;
                    $p['q_hi'] = $low + 10 ** max(0, $unitExp - $decimals);
                }
                $ambiguous = $decimals === 0 && empty($m[3]);
                $text = $ambiguous ? self::textCondition($q, $p) : null;
                if ($range && $text) {
                    $w[] = "($range OR ($text))";
                } elseif ($range || $text) {
                    $w[] = $range ?? $text;
                }
            } elseif ($text = self::textCondition($q, $p)) {
                $w[] = $text;
            }
        }
        if (!empty($f['service'])) {
            $ids = Taxonomy::serviceWithDescendants((int) Taxonomy::service($f['service'])['id']);
            $w[] = 'f.service_id IN (' . self::intList($ids) . ')';
        }
        if (!empty($f['mode'])) {
            $ids = array_map(static fn($c) => (int) Taxonomy::mode($c)['id'], $f['mode']);
            $w[] = 'f.mode_id IN (' . self::intList($ids) . ')';
        }
        if (!empty($f['digital'])) {
            $w[] = "m.family IN ('digitale','dati')";
        }
        if (!empty($f['band']) && !in_array('band', $exclude, true)) {
            $w[] = 'f.itu_band_id = :band';
            $p['band'] = (int) Taxonomy::get('ituByCode')[$f['band']]['id'];
        }
        if (!empty($f['plan']) && !in_array('band', $exclude, true)) {
            $w[] = 'f.band_plan_id = :plan';
            $p['plan'] = (int) Taxonomy::get('plansBySlug')[$f['plan']]['id'];
        }
        if (!empty($f['country'])) {
            $w[] = 'f.country_iso2 = :country';
            $p['country'] = $f['country'];
        }
        if (!empty($f['region'])) {
            $w[] = 'f.region_id = :region';
            $p['region'] = (int) Taxonomy::region($f['region'])['id'];
        }
        if (!empty($f['province'])) {
            $w[] = 'EXISTS (SELECT 1 FROM listenin_frequency_provinces fpx WHERE fpx.frequency_id = f.id AND fpx.province_id = :province)';
            $p['province'] = (int) Taxonomy::province($f['province'])['id'];
        }
        if (!empty($f['status'])) {
            $in = [];
            foreach ($f['status'] as $i => $st) {
                $in[] = ':st' . $i;
                $p['st' . $i] = $st;
            }
            $w[] = 'f.status IN (' . implode(',', $in) . ')';
        }
        if (isset($f['fmin']) && !in_array('band', $exclude, true)) {
            $w[] = 'f.freq_hz >= :fmin';
            $p['fmin'] = $f['fmin'];
        }
        if (isset($f['fmax']) && !in_array('band', $exclude, true)) {
            $w[] = 'f.freq_hz <= :fmax';
            $p['fmax'] = $f['fmax'];
        }
        if (!empty($f['locator'])) {
            $w[] = 'f.locator LIKE :loc';
            $p['loc'] = $f['locator'] . '%';
        }
        if (!empty($f['callsign'])) {
            $w[] = 'f.callsign LIKE :cs';
            $p['cs'] = addcslashes($f['callsign'], '%_\\') . '%';
        }
        if (!empty($f['affidabilita'])) {
            $in = [];
            foreach ($f['affidabilita'] as $i => $lv) {
                $in[] = ':rl' . $i;
                $p['rl' . $i] = $lv;
            }
            $w[] = '(' . Reliability::sql('f') . ') IN (' . implode(',', $in) . ')';
        }
        if (!empty($f['missione'])) {
            $w[] = Reliability::missionSql('f');
        }
        if (!empty($f['near'])) {
            [$nlat, $nlon, $nkm] = $f['near'];
            $dLat = $nkm / 111.0;
            $dLon = $nkm / (111.0 * max(0.2, cos(deg2rad($nlat))));
            $w[] = sprintf('f.geo_lat BETWEEN %.6F AND %.6F AND f.geo_lon BETWEEN %.6F AND %.6F', $nlat - $dLat, $nlat + $dLat, $nlon - $dLon, $nlon + $dLon);
            $w[] = GeoService::haversineSql($nlat, $nlon) . sprintf(' <= %.3F', $nkm);
            if (empty($f['approx'])) {
                $w[] = "f.geo_source IN ('coordinate','sito','locatore','comune')";
            }
        }
        if (!empty($f['site'])) {
            $w[] = 'f.site_id = (SELECT id FROM listenin_sites WHERE slug = :site_slug)';
            $p['site_slug'] = $f['site'];
        }
        if (!empty($f['heard_since'])) {
            $w[] = 'f.last_heard_at >= :heard';
            $p['heard'] = $f['heard_since'];
        }
        return [implode(' AND ', $w), $p];
    }

    /** Condizione full-text (token di almeno 3 caratteri) + LIKE per i token corti. */
    private static function textCondition(string $q, array &$p): ?string
    {
        $terms = preg_split('/\s+/u', Normalizer::key($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ft = [];
        $parts = [];
        foreach (array_slice($terms, 0, 8) as $i => $t) {
            if (mb_strlen($t) >= 3) {
                $ft[] = '+' . $t . '*';
            } else {
                $parts[] = "f.search_text LIKE :q_like{$i}";
                $p['q_like' . $i] = '%' . addcslashes($t, '%_\\') . '%';
            }
        }
        if ($ft) {
            $parts[] = 'MATCH(f.search_text) AGAINST (:q_ft IN BOOLEAN MODE)';
            $p['q_ft'] = implode(' ', $ft);
        }
        return $parts ? implode(' AND ', $parts) : null;
    }

    private static function intList(array $ids): string
    {
        return implode(',', array_map('intval', $ids)) ?: '0';
    }

    // ------------------------------------------------------------------
    //  Lettura
    // ------------------------------------------------------------------

    public static function search(array $filters, int $page, int $perPage, string $sort, string $dir): array
    {
        [$where, $params] = self::where($filters);
        $dist = !empty($filters['near']) ? GeoService::haversineSql($filters['near'][0], $filters['near'][1]) : null;
        if ($sort === 'distance' && $dist === null) {
            $sort = 'freq';
        }
        $order = (self::SORTS[$sort] ?? 'f.freq_hz') . ($dir === 'desc' ? ' DESC' : ' ASC') . ', f.freq_hz ASC, f.id ASC';
        $from = ' FROM listenin_frequencies f JOIN listenin_modes m ON m.id = f.mode_id
                  JOIN listenin_services s ON s.id = f.service_id
                  LEFT JOIN listenin_regions r ON r.id = f.region_id WHERE ' . $where;

        $total = (int) Database::value('SELECT COUNT(*)' . $from, $params);
        $offset = ($page - 1) * $perPage;
        $ids = Database::all('SELECT f.id' . ($dist !== null ? ', ' . $dist . ' AS dist' : '') . $from . ' ORDER BY ' . $order . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset, $params);
        $rows = [];
        if ($ids) {
            $idList = self::intList(array_column($ids, 'id'));
            $byId = array_column(Database::all(self::SELECT . ' WHERE f.id IN (' . $idList . ')'), null, 'id');
            foreach ($ids as $r) {
                $item = self::present($byId[$r['id']], false);
                if (isset($r['dist'])) {
                    $item['distance_km'] = round((float) $r['dist'], 1);
                }
                $rows[] = $item;
            }
        }
        return [
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'spectrum' => self::spectrum($filters),
        ];
    }

    /**
     * Istogramma logaritmico 100 kHz – 3 GHz (64 colonne) con tutti i filtri
     * tranne banda e intervallo: alimenta la striscia spettrale dell'interfaccia.
     */
    public static function spectrum(array $filters): array
    {
        [$where, $params] = self::where($filters, ['band']);
        $sql = 'SELECT LEAST(63, GREATEST(0, FLOOR((LOG10(f.freq_hz) - 5) / (LOG10(3000000000) - 5) * 64))) AS b, COUNT(*) AS n
                  FROM listenin_frequencies f JOIN listenin_modes m ON m.id = f.mode_id
                 WHERE ' . $where . ' GROUP BY b';
        $bins = array_fill(0, 64, 0);
        foreach (Database::all($sql, $params) as $r) {
            $bins[(int) $r['b']] = (int) $r['n'];
        }
        return ['min_hz' => 100000, 'max_hz' => 3000000000, 'bins' => $bins];
    }

    public static function find(int $id, bool $includeDeleted = false): ?array
    {
        $row = Database::one(self::SELECT . ' WHERE f.id = :id' . ($includeDeleted ? '' : ' AND f.deleted_at IS NULL'), ['id' => $id]);
        return $row ?: null;
    }

    public static function findOrFail(int $id, bool $includeDeleted = false): array
    {
        return self::find($id, $includeDeleted) ?? throw ApiException::notFound('Frequenza non trovata.');
    }

    /** Valori logici del record (stesso formato di FrequencyFields::validate). */
    public static function logical(array $row): array
    {
        return [
            'freq_hz' => (int) $row['freq_hz'],
            'offset_hz' => $row['offset_hz'] !== null ? (int) $row['offset_hz'] : null,
            'input_freq_hz' => $row['input_freq_hz'] !== null ? (int) $row['input_freq_hz'] : null,
            'tone_type' => $row['tone_type'],
            'ctcss_hz' => $row['ctcss_hz'] !== null ? number_format((float) $row['ctcss_hz'], 1, '.', '') : null,
            'dcs_code' => $row['dcs_code'],
            'color_code' => $row['color_code'] !== null ? (int) $row['color_code'] : null,
            'dmr_timeslot' => $row['dmr_timeslot'] !== null ? (int) $row['dmr_timeslot'] : null,
            'talkgroup' => $row['talkgroup'],
            'ran' => $row['ran'] !== null ? (int) $row['ran'] : null,
            'nac' => $row['nac'],
            'mode' => $row['mode_code'],
            'bandwidth_hz' => $row['bandwidth_hz'] !== null ? (int) $row['bandwidth_hz'] : null,
            'service' => $row['service_slug'],
            'label' => $row['label'],
            'channel' => $row['channel'],
            'country' => $row['country_iso2'],
            'region' => $row['region_slug'],
            'province' => $row['province_code'],
            'coverage' => $row['coverage_codes'] ?: null,
            'municipality' => $row['municipality_name'],
            'area_note' => $row['area_note'],
            'locator' => $row['locator'],
            'latitude' => $row['latitude'] !== null ? number_format((float) $row['latitude'], 6, '.', '') : null,
            'longitude' => $row['longitude'] !== null ? number_format((float) $row['longitude'], 6, '.', '') : null,
            'coverage_km' => $row['coverage_km'] !== null ? (int) $row['coverage_km'] : null,
            'altitude_m' => $row['altitude_m'] !== null ? (int) $row['altitude_m'] : null,
            'site' => $row['site_name'],
            'callsign' => $row['callsign'],
            'entity' => $row['entity_name'],
            'status' => $row['status'],
            'last_heard_at' => $row['last_heard_at'],
            'notes' => $row['notes'],
        ];
    }

    /** Rappresentazione pubblica per l'API. */
    public static function present(array $row, bool $full = true): array
    {
        $root = Taxonomy::rootService((int) $row['service_id']);
        $hz = (int) $row['freq_hz'];
        $out = [
            'id' => (int) $row['id'],
            'freq_hz' => $hz,
            'freq_khz' => Normalizer::formatFixed($hz, 3),
            'freq_mhz' => Normalizer::formatMHz($hz),
            'offset_hz' => $row['offset_hz'] !== null ? (int) $row['offset_hz'] : null,
            'input_freq_mhz' => $row['input_freq_hz'] !== null ? Normalizer::formatMHz((int) $row['input_freq_hz']) : null,
            'tone' => match ($row['tone_type']) {
                'ctcss' => number_format((float) $row['ctcss_hz'], 1, '.', '') . ' Hz',
                'dcs' => 'D' . $row['dcs_code'],
                default => null,
            },
            'mode' => $row['mode_code'],
            'service' => ['slug' => $row['service_slug'], 'name' => $row['service_name'],
                'root' => $root['slug'] ?? null, 'root_name' => $root['name'] ?? null, 'color' => $root['color'] ?? null],
            'label' => $row['label'],
            'channel' => $row['channel'],
            'callsign' => $row['callsign'],
            'region' => $row['region_name'],
            'province' => $row['province_code'],
            'municipality' => $row['municipality_name'],
            'area_note' => $row['area_note'],
            'locator' => $row['locator'],
            'status' => $row['status'],
            'last_heard_at' => $row['last_heard_at'],
            'reliability' => ($lv = Reliability::level($row['last_heard_at'], (string) $row['status'])),
            'reliability_label' => Reliability::LEVELS[$lv],
            'last_heard_days' => Reliability::days($row['last_heard_at']),
            'geo' => $row['geo_lat'] !== null
                ? ['lat' => (float) $row['geo_lat'], 'lon' => (float) $row['geo_lon'], 'source' => $row['geo_source'],
                    'source_label' => GeoService::SOURCES[$row['geo_source']] ?? null, 'approx' => in_array($row['geo_source'], ['comune', 'provincia'], true)]
                : self::geo($row),
            'altitude_m' => $row['altitude_m'] !== null ? (int) $row['altitude_m'] : ($row['site_altitude_m'] !== null ? (int) $row['site_altitude_m'] : null),
            'site' => $row['site_slug'] ? ['slug' => $row['site_slug'], 'name' => $row['site_name']] : null,
            'itu_band' => $row['itu_code'],
        ];
        if ($full) {
            $out += [
                'logical' => self::logical($row),
                'color_code' => $row['color_code'] !== null ? (int) $row['color_code'] : null,
                'dmr_timeslot' => $row['dmr_timeslot'] !== null ? (int) $row['dmr_timeslot'] : null,
                'talkgroup' => $row['talkgroup'],
                'ran' => $row['ran'] !== null ? (int) $row['ran'] : null,
                'nac' => $row['nac'],
                'bandwidth_hz' => $row['bandwidth_hz'] !== null ? (int) $row['bandwidth_hz']
                    : ($row['default_bandwidth_hz'] !== null ? (int) $row['default_bandwidth_hz'] : null),
                'bandwidth_is_default' => $row['bandwidth_hz'] === null,
                'band_plan' => $row['plan_name'],
                'country' => $row['country_iso2'],
                'region_slug' => $row['region_slug'],
                'province_name' => $row['province_name'],
                'coverage' => $row['coverage_codes'] ? explode(',', $row['coverage_codes']) : [],
                'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
                'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
                'coverage_km' => $row['coverage_km'] !== null ? (int) $row['coverage_km'] : null,
                'entity' => $row['entity_name'],
                'notes' => $row['notes'],
                'source' => $row['source'],
                'version' => (int) $row['version'],
                'updated_at' => $row['updated_at'],
                'deleted' => $row['deleted_at'] !== null,
            ];
        }
        return $out;
    }

    /** Posizione del record: coordinate se presenti, altrimenti il centro del locatore Maidenhead. */
    private static function geo(array $row): ?array
    {
        if ($row['latitude'] !== null && $row['longitude'] !== null) {
            return ['lat' => (float) $row['latitude'], 'lon' => (float) $row['longitude'], 'source' => 'coordinate'];
        }
        if (!empty($row['locator']) && ($c = Normalizer::locatorToLatLon((string) $row['locator']))) {
            return ['lat' => $c[0], 'lon' => $c[1], 'source' => 'locatore'];
        }
        return null;
    }

    /**
     * Controllo in tempo reale dei doppioni: frequenze identiche o vicine (±5 kHz, ±12,5 kHz) nella stessa provincia.
     * Senza provincia si controllano solo le frequenze identiche in tutta Italia.
     * @return array{identical:array,within_5khz:array,within_12_5khz:array}
     */
    public static function neighbors(int $hz, ?string $provinceCode, ?int $excludeId = null): array
    {
        $params = ['lo' => $hz - 12_500, 'hi' => $hz + 12_500, 'ex' => $excludeId ?? 0];
        $sql = 'SELECT f.id, f.freq_hz, f.label, f.status, p.code AS province_code FROM listenin_frequencies f
                  LEFT JOIN listenin_provinces p ON p.id = f.province_id
                 WHERE f.deleted_at IS NULL AND f.freq_hz BETWEEN :lo AND :hi AND f.id <> :ex';
        if ($provinceCode !== null && ($prov = Taxonomy::province($provinceCode))) {
            $sql .= ' AND (f.province_id = :pid OR EXISTS (SELECT 1 FROM listenin_frequency_provinces fp WHERE fp.frequency_id = f.id AND fp.province_id = :pid2))';
            $params['pid'] = $params['pid2'] = (int) $prov['id'];
        } else {
            $sql .= ' AND f.freq_hz = :exact';
            $params['exact'] = $hz;
        }
        $out = ['identical' => [], 'within_5khz' => [], 'within_12_5khz' => []];
        foreach (Database::all($sql . ' ORDER BY ABS(CAST(f.freq_hz AS SIGNED) - ' . (int) $hz . '), f.id LIMIT 30', $params) as $r) {
            $d = abs((int) $r['freq_hz'] - $hz);
            $item = ['id' => (int) $r['id'], 'freq_mhz' => Normalizer::formatMHz((int) $r['freq_hz']), 'label' => $r['label'],
                'status' => $r['status'], 'province' => $r['province_code'], 'delta_khz' => $d / 1000];
            $out[$d === 0 ? 'identical' : ($d <= 5_000 ? 'within_5khz' : 'within_12_5khz')][] = $item;
        }
        return $out;
    }

    /** Possibili duplicati: stessa frequenza nella stessa area. */
    public static function possibleDuplicates(int $hz, ?string $regionSlug, ?int $excludeId = null): array
    {
        $params = ['hz' => $hz, 'ex' => $excludeId ?? 0];
        $sql = 'SELECT f.id, f.freq_hz, f.label FROM listenin_frequencies f
                 WHERE f.deleted_at IS NULL AND f.freq_hz = :hz AND f.id <> :ex';
        if ($regionSlug && ($r = Taxonomy::region($regionSlug))) {
            $sql .= ' AND (f.region_id = :rid OR f.region_id IS NULL)';
            $params['rid'] = (int) $r['id'];
        }
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'freq_mhz' => Normalizer::formatMHz((int) $r['freq_hz']), 'label' => $r['label']],
            Database::all($sql . ' LIMIT 5', $params));
    }

    // ------------------------------------------------------------------
    //  Esportazione (generatore: memoria costante)
    // ------------------------------------------------------------------

    public static function exportRows(array $filters, int $max): \Generator
    {
        [$where, $params] = self::where($filters);
        $stmt = Database::run(self::SELECT . ' WHERE ' . $where . ' ORDER BY f.freq_hz, f.id LIMIT ' . (int) $max, $params);
        while ($row = $stmt->fetch()) {
            $root = Taxonomy::rootService((int) $row['service_id']);
            yield [
                'id' => (int) $row['id'],
                'freq_hz' => (int) $row['freq_hz'],
                'freq_khz' => Normalizer::formatFixed((int) $row['freq_hz'], 3),
                'freq_mhz' => Normalizer::formatFixed((int) $row['freq_hz'], 6),
                'offset_hz' => $row['offset_hz'] !== null ? (int) $row['offset_hz'] : null,
                'input_freq_mhz' => $row['input_freq_hz'] !== null ? Normalizer::formatFixed((int) $row['input_freq_hz'], 6) : null,
                'reliability' => Reliability::level($row['last_heard_at'], (string) $row['status']),
                'tone_type' => $row['tone_type'],
                'ctcss_hz' => $row['ctcss_hz'] !== null ? number_format((float) $row['ctcss_hz'], 1, '.', '') : null,
                'dcs_code' => $row['dcs_code'],
                'color_code' => $row['color_code'] !== null ? (int) $row['color_code'] : null,
                'dmr_timeslot' => $row['dmr_timeslot'] !== null ? (int) $row['dmr_timeslot'] : null,
                'talkgroup' => $row['talkgroup'],
                'ran' => $row['ran'] !== null ? (int) $row['ran'] : null,
                'nac' => $row['nac'],
                'mode' => $row['mode_code'],
                'bandwidth_hz' => $row['bandwidth_hz'] !== null ? (int) $row['bandwidth_hz'] : ($row['default_bandwidth_hz'] !== null ? (int) $row['default_bandwidth_hz'] : null),
                'itu_band' => $row['itu_code'],
                'band_plan' => $row['plan_slug'],
                'scope' => $root['slug'] ?? null,
                'service' => $row['service_slug'],
                'label' => $row['label'],
                'channel' => $row['channel'],
                'country' => $row['country_iso2'],
                'region' => $row['region_name'],
                'province' => $row['province_code'],
                'coverage' => $row['coverage_codes'],
                'municipality' => $row['municipality_name'],
                'area_note' => $row['area_note'],
                'locator' => $row['locator'],
                'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
                'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
                'coverage_km' => $row['coverage_km'] !== null ? (int) $row['coverage_km'] : null,
                'callsign' => $row['callsign'],
                'entity' => $row['entity_name'],
                'status' => $row['status'],
                'last_heard_at' => $row['last_heard_at'],
                'notes' => $row['notes'],
                'updated_at' => $row['updated_at'],
            ];
        }
    }

    // ------------------------------------------------------------------
    //  Scrittura (usata da RevisionService dentro una transazione)
    // ------------------------------------------------------------------

    /** Applica valori logici a un record (nuovo se $id è null). Ritorna l'id. */
    public static function write(?int $id, array $logical, ?int $userId, string $source = 'manuale'): int
    {
        $cols = [];
        foreach ($logical as $field => $value) {
            switch ($field) {
                case 'mode':
                    $cols['mode_id'] = (int) Taxonomy::mode((string) $value)['id'];
                    break;
                case 'service':
                    $cols['service_id'] = (int) Taxonomy::service((string) $value)['id'];
                    break;
                case 'region':
                    $cols['region_id'] = $value !== null ? (int) Taxonomy::region((string) $value)['id'] : null;
                    break;
                case 'province':
                    $cols['province_id'] = $value !== null ? (int) Taxonomy::province((string) $value)['id'] : null;
                    break;
                case 'country':
                    $cols['country_iso2'] = $value;
                    break;
                case 'entity':
                    $cols['entity_id'] = $value !== null ? self::entityId((string) $value) : null;
                    break;
                case 'coverage':
                case 'municipality':
                case 'site':
                    break; // gestiti dopo il salvataggio
                default:
                    $cols[$field] = $value;
            }
        }
        if (array_key_exists('freq_hz', $logical)) {
            $cols['itu_band_id'] = Taxonomy::ituBandId((int) $logical['freq_hz']);
            $cols['band_plan_id'] = Taxonomy::bandPlanId((int) $logical['freq_hz']);
        }

        $created = $id === null;
        if ($id === null) {
            $cols += ['source' => $source, 'created_by' => $userId, 'search_text' => ''];
            $names = array_keys($cols);
            $id = Database::insert(
                'INSERT INTO listenin_frequencies (' . implode(',', $names) . ') VALUES (:' . implode(',:', $names) . ')',
                $cols
            );
        } else {
            $set = implode('', array_map(static fn($c) => "$c = :$c, ", array_keys($cols)));
            $cols['id'] = $id;
            Database::run("UPDATE listenin_frequencies SET {$set}version = version + 1 WHERE id = :id", $cols);
        }

        if (array_key_exists('municipality', $logical)) {
            $provinceCode = $logical['province'] ?? Database::value(
                'SELECT p.code FROM listenin_frequencies f JOIN listenin_provinces p ON p.id = f.province_id WHERE f.id = :id', ['id' => $id]);
            $mid = $logical['municipality'] !== null && $provinceCode
                ? self::municipalityId((string) $logical['municipality'], (string) $provinceCode) : null;
            Database::run('UPDATE listenin_frequencies SET municipality_id = :m WHERE id = :id', ['m' => $mid, 'id' => $id]);
        }
        if (array_key_exists('site', $logical)) {
            $row = Database::one('SELECT f.province_id, f.municipality_id, f.latitude, f.longitude, f.altitude_m FROM listenin_frequencies f WHERE f.id = :id', ['id' => $id]);
            $sid = null;
            if ($logical['site'] !== null) {
                $sid = SiteService::resolve((string) $logical['site'], $row['province_id'] !== null ? (int) $row['province_id'] : null,
                    $row['municipality_id'] !== null ? (int) $row['municipality_id'] : null,
                    $row['latitude'] !== null ? (float) $row['latitude'] : null, $row['longitude'] !== null ? (float) $row['longitude'] : null,
                    $row['altitude_m'] !== null ? (int) $row['altitude_m'] : null);
            }
            Database::run('UPDATE listenin_frequencies SET site_id = :s WHERE id = :id', ['s' => $sid, 'id' => $id]);
        }
        if (array_key_exists('coverage', $logical)) {
            Database::run('DELETE FROM listenin_frequency_provinces WHERE frequency_id = :id', ['id' => $id]);
            foreach (array_filter(explode(',', (string) $logical['coverage'])) as $code) {
                if ($p = Taxonomy::province($code)) {
                    Database::run('INSERT IGNORE INTO listenin_frequency_provinces (frequency_id, province_id) VALUES (:f, :p)',
                        ['f' => $id, 'p' => (int) $p['id']]);
                }
            }
        }
        if (array_intersect(['latitude', 'longitude', 'locator', 'province', 'municipality', 'site'], array_keys($logical)) || $created) {
            GeoService::refresh($id);
        }
        self::refreshSearchText($id);
        return $id;
    }

    public static function setDeleted(int $id, bool $deleted): void
    {
        Database::run('UPDATE listenin_frequencies SET deleted_at = ' . ($deleted ? 'NOW()' : 'NULL') . ', version = version + 1 WHERE id = :id', ['id' => $id]);
    }

    /** Testo denormalizzato per il FULLTEXT: descrizione, nominativo, canale, ente, luoghi, servizio. */
    public static function refreshSearchText(int $id): void
    {
        $r = self::find($id, true);
        if (!$r) {
            return;
        }
        $root = Taxonomy::rootService((int) $r['service_id']);
        $parts = [$r['label'], $r['callsign'], $r['channel'], $r['entity_name'], $r['municipality_name'],
            $r['province_name'], $r['province_code'], $r['region_name'], $r['area_note'], $r['service_name'],
            $root['name'] ?? '', $r['mode_code'], $r['locator'], $r['talkgroup'], mb_substr((string) $r['notes'], 0, 500)];
        $text = Normalizer::key(implode(' ', array_filter($parts, static fn($p) => $p !== null && $p !== '')));
        Database::run('UPDATE listenin_frequencies SET search_text = :t WHERE id = :id', ['t' => $text, 'id' => $id]);
    }

    public static function entityId(string $name): int
    {
        $key = Normalizer::key($name);
        $id = Database::value('SELECT id FROM listenin_entities WHERE name_key = :k', ['k' => $key]);
        return $id ? (int) $id : Database::insert('INSERT INTO listenin_entities (name, name_key) VALUES (:n, :k)', ['n' => $name, 'k' => $key]);
    }

    public static function municipalityId(string $name, string $provinceCode): ?int
    {
        $prov = Taxonomy::province($provinceCode);
        if (!$prov) {
            return null;
        }
        $key = Normalizer::key($name);
        $id = Database::value('SELECT id FROM listenin_municipalities WHERE province_id = :p AND name_key = :k',
            ['p' => (int) $prov['id'], 'k' => $key]);
        return $id ? (int) $id : Database::insert(
            'INSERT INTO listenin_municipalities (province_id, name, name_key) VALUES (:p, :n, :k)',
            ['p' => (int) $prov['id'], 'n' => $name, 'k' => $key]
        );
    }
}
