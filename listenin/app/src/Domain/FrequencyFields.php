<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\ApiException;
use ListenIn\Core\Validator;

/**
 * Definizione canonica dei campi "logici" di una frequenza.
 * Le revisioni, le segnalazioni e l'import usano questi nomi; i codici
 * (modo, servizio, regione, provincia) sono risolti in id solo in scrittura.
 */
final class FrequencyFields
{
    public const LABELS = [
        'freq_hz'       => 'Frequenza',
        'offset_hz'     => 'Shift / offset',
        'input_freq_hz' => 'Frequenza di ingresso',
        'tone_type'     => 'Tipo di tono',
        'ctcss_hz'      => 'Tono CTCSS',
        'dcs_code'      => 'Codice DCS',
        'color_code'    => 'Color Code DMR',
        'dmr_timeslot'  => 'Timeslot DMR',
        'talkgroup'     => 'Talkgroup',
        'ran'           => 'RAN NXDN',
        'nac'           => 'NAC P25',
        'mode'          => 'Modo',
        'bandwidth_hz'  => 'Larghezza di banda',
        'service'       => 'Servizio',
        'label'         => 'Descrizione',
        'channel'       => 'Canale',
        'country'       => 'Nazione',
        'region'        => 'Regione',
        'province'      => 'Provincia',
        'coverage'      => 'Province coperte',
        'municipality'  => 'Comune',
        'area_note'     => 'Area',
        'locator'       => 'Locatore QTH',
        'latitude'      => 'Latitudine',
        'longitude'     => 'Longitudine',
        'coverage_km'   => 'Raggio di copertura (km)',
        'altitude_m'    => 'Altitudine (m s.l.m.)',
        'site'          => 'Postazione o monte',
        'callsign'      => 'Nominativo',
        'entity'        => 'Ente o gestore',
        'status'        => 'Stato operativo',
        'last_heard_at' => 'Ultimo riscontro',
        'notes'         => 'Note',
    ];

    public const REQUIRED_ON_CREATE = ['freq_hz', 'mode', 'service', 'label', 'status'];

    public static function names(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * Valida e normalizza un insieme di campi.
     * @param bool $partial true = aggiornamento (solo i campi presenti)
     * @param array|null $current valori logici attuali, per i controlli di coerenza
     * @return array campi logici normalizzati (valori int|string|null)
     */
    public static function validate(array $in, bool $partial, ?array $current = null): array
    {
        $v = new Validator($in);
        $out = [];
        $present = static fn(string $k): bool => array_key_exists($k, $in);

        // Frequenza: accetta freq_hz intero oppure "freq" testuale (MHz di default)
        if ($present('freq') || $present('freq_hz') || !$partial) {
            $raw = $in['freq_hz'] ?? $in['freq'] ?? null;
            $hz = is_int($raw) ? $raw : Normalizer::parseFrequency(is_scalar($raw) ? (string) $raw : null);
            if ($hz === null || $hz < Normalizer::MIN_HZ || $hz > Normalizer::MAX_HZ) {
                $v->addError('freq_hz', 'Frequenza non valida (es. 145.6000 MHz).');
            } else {
                $out['freq_hz'] = $hz;
            }
        }
        foreach (['input_freq_hz' => 'input_freq'] as $field => $alias) {
            if ($present($field) || $present($alias)) {
                $raw = $in[$field] ?? $in[$alias] ?? null;
                if ($raw === null || $raw === '') {
                    $out[$field] = null;
                } else {
                    $hz = is_int($raw) ? $raw : Normalizer::parseFrequency((string) $raw);
                    $hz === null ? $v->addError($field, 'Frequenza di ingresso non valida.') : $out[$field] = $hz;
                }
            }
        }
        if ($present('offset_hz')) {
            $out['offset_hz'] = $v->int('offset_hz', -2_000_000_000, 2_000_000_000, false);
        }

        // Toni e codici d'accesso
        if ($present('tone_type') || $present('ctcss_hz') || $present('dcs_code')) {
            $type = $v->enum('tone_type', ['none', 'ctcss', 'dcs'], false) ?? 'none';
            $ctcss = $present('ctcss_hz') && $in['ctcss_hz'] !== null && $in['ctcss_hz'] !== ''
                ? Normalizer::ctcss((string) $in['ctcss_hz']) : null;
            $dcs = $present('dcs_code') && $in['dcs_code'] !== null && $in['dcs_code'] !== ''
                ? Normalizer::dcs((string) $in['dcs_code']) : null;
            if ($type === 'none' && $ctcss !== null) {
                $type = 'ctcss';
            } elseif ($type === 'none' && $dcs !== null) {
                $type = 'dcs';
            }
            if ($type === 'ctcss' && $ctcss === null) {
                $v->addError('ctcss_hz', 'Tono CTCSS non standard o mancante.');
            }
            if ($type === 'dcs' && $dcs === null) {
                $v->addError('dcs_code', 'Codice DCS non valido (es. 023N).');
            }
            $out['tone_type'] = $type;
            $out['ctcss_hz'] = $type === 'ctcss' ? $ctcss : null;
            $out['dcs_code'] = $type === 'dcs' ? $dcs : null;
        } elseif (!$partial) {
            $out += ['tone_type' => 'none', 'ctcss_hz' => null, 'dcs_code' => null];
        }

        $ints = ['color_code' => [0, 15], 'dmr_timeslot' => [1, 2], 'ran' => [0, 63],
            'bandwidth_hz' => [100, 10_000_000], 'coverage_km' => [0, 3000], 'altitude_m' => [-430, 9000]];
        foreach ($ints as $field => [$min, $max]) {
            if ($present($field) || !$partial) {
                $out[$field] = $v->int($field, $min, $max, false);
            }
        }
        if ($present('nac') || !$partial) {
            $nac = $v->string('nac', 3, 3, false);
            if ($nac !== null && !preg_match('/^[0-9A-Fa-f]{3}$/', $nac)) {
                $v->addError('nac', 'NAC esadecimale di 3 cifre (es. 293).');
            }
            $out['nac'] = $nac !== null ? strtoupper($nac) : null;
        }
        if ($present('talkgroup') || !$partial) {
            $tg = $v->string('talkgroup', 1, 32, false);
            if ($tg !== null && !preg_match('/^[\p{L}\p{N} ,.#\/\-]+$/u', $tg)) {
                $v->addError('talkgroup', 'Caratteri non ammessi.');
            }
            $out['talkgroup'] = $tg;
        }

        // Classificazione
        if ($present('mode') || !$partial) {
            $code = $v->string('mode', 1, 12, !$partial);
            if ($code !== null && Taxonomy::mode(strtoupper($code)) === null) {
                $v->addError('mode', 'Modo operativo sconosciuto.');
            } elseif ($code !== null) {
                $out['mode'] = strtoupper($code);
            }
        }
        if ($present('service') || !$partial) {
            $slug = $v->string('service', 1, 48, !$partial);
            if ($slug !== null && Taxonomy::service($slug) === null) {
                $v->addError('service', 'Servizio sconosciuto.');
            } elseif ($slug !== null) {
                $out['service'] = $slug;
            }
        }
        $strings = ['label' => [2, 160, !$partial], 'channel' => [1, 20, false], 'area_note' => [1, 120, false],
            'municipality' => [1, 100, false], 'entity' => [1, 120, false], 'site' => [2, 100, false]];
        foreach ($strings as $field => [$min, $max, $req]) {
            if ($present($field) || !$partial) {
                $out[$field] = $v->string($field, $min, $max, $req);
            }
        }
        if ($present('notes') || !$partial) {
            $out['notes'] = $v->string('notes', 1, 2000, false, true);
        }
        if ($present('status') || !$partial) {
            $out['status'] = $v->enum('status', array_keys(Taxonomy::STATUSES), !$partial);
        }
        if ($present('callsign') || !$partial) {
            $cs = $v->string('callsign', 2, 20, false);
            if ($cs !== null && ($cs = Normalizer::callsign($cs)) === null) {
                $v->addError('callsign', 'Nominativo non valido.');
            }
            $out['callsign'] = $cs;
        }
        if ($present('last_heard_at') || !$partial) {
            $d = $v->string('last_heard_at', 10, 10, false);
            if ($d !== null) {
                $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
                if (!$dt || $dt->format('Y-m-d') !== $d || $dt > new \DateTimeImmutable('tomorrow')) {
                    $v->addError('last_heard_at', 'Data non valida o futura.');
                    $d = null;
                }
            }
            $out['last_heard_at'] = $d;
        }

        // Geografia
        if ($present('country') || !$partial) {
            $c = $v->string('country', 2, 2, false);
            if ($c !== null && !isset(Taxonomy::get('countries')[strtoupper($c)])) {
                $v->addError('country', 'Nazione sconosciuta.');
            }
            $out['country'] = $c !== null ? strtoupper($c) : null;
        }
        if ($present('region') || !$partial) {
            $r = $v->string('region', 2, 48, false);
            if ($r !== null && Taxonomy::region($r) === null) {
                $v->addError('region', 'Regione sconosciuta.');
            }
            $out['region'] = $r;
        }
        if ($present('province') || !$partial) {
            $p = $v->string('province', 2, 2, false);
            if ($p !== null && Taxonomy::province($p) === null) {
                $v->addError('province', 'Provincia sconosciuta.');
            }
            $out['province'] = $p !== null ? strtoupper($p) : null;
        }
        if ($present('coverage') || !$partial) {
            $list = $in['coverage'] ?? [];
            if (is_string($list)) {
                $list = preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $codes = [];
            foreach ((array) $list as $code) {
                $code = strtoupper(trim((string) $code));
                if (Taxonomy::province($code) === null) {
                    $v->addError('coverage', 'Sigla provincia sconosciuta: ' . mb_substr($code, 0, 4));
                    break;
                }
                $codes[$code] = true;
            }
            $codes = array_keys($codes);
            sort($codes);
            $out['coverage'] = $codes ? implode(',', $codes) : null;
        }
        if ($present('locator') || !$partial) {
            $l = $v->string('locator', 4, 8, false);
            if ($l !== null && ($l = Normalizer::normalizeLocator($l)) === null) {
                $v->addError('locator', 'Locatore Maidenhead non valido (es. JN65qv).');
            }
            $out['locator'] = $l;
        }
        foreach (['latitude' => 90, 'longitude' => 180] as $field => $limit) {
            if ($present($field) || !$partial) {
                $raw = $in[$field] ?? null;
                if ($raw === null || $raw === '') {
                    $out[$field] = null;
                } elseif (!is_numeric(str_replace(',', '.', (string) $raw)) || abs((float) str_replace(',', '.', (string) $raw)) > $limit) {
                    $v->addError($field, 'Coordinata non valida.');
                } else {
                    $out[$field] = number_format((float) str_replace(',', '.', (string) $raw), 6, '.', '');
                }
            }
        }
        $v->throwIfErrors();

        return self::harmonize($out, $current, $partial);
    }

    /** Coerenza tra campi collegati (ingresso/offset, provincia/regione, coordinate/locatore). */
    private static function harmonize(array $out, ?array $current, bool $partial): array
    {
        $merged = array_merge($current ?? [], $out);
        $errors = [];

        $freq = $merged['freq_hz'] ?? null;
        if ($freq !== null) {
            $touchedInput = array_key_exists('input_freq_hz', $out);
            $touchedOffset = array_key_exists('offset_hz', $out);
            if ($touchedOffset && !$touchedInput) {
                $out['input_freq_hz'] = $out['offset_hz'] !== null ? (int) $freq + (int) $out['offset_hz'] : null;
            } elseif ($touchedInput && !$touchedOffset) {
                $out['offset_hz'] = $out['input_freq_hz'] !== null ? (int) $out['input_freq_hz'] - (int) $freq : null;
            } elseif ($touchedInput && $touchedOffset && $out['input_freq_hz'] !== null && $out['offset_hz'] !== null
                && (int) $out['input_freq_hz'] !== (int) $freq + (int) $out['offset_hz']) {
                $errors['offset_hz'] = 'Offset e frequenza di ingresso non sono coerenti.';
            } elseif (array_key_exists('freq_hz', $out) && !$touchedInput && ($merged['offset_hz'] ?? null) !== null) {
                $out['input_freq_hz'] = (int) $freq + (int) $merged['offset_hz'];
            }
        }

        if (!empty($merged['province'])) {
            $prov = Taxonomy::province((string) $merged['province']);
            $regionSlug = Taxonomy::region((int) $prov['region_id'])['slug'];
            if (array_key_exists('region', $out) && $out['region'] !== null && $out['region'] !== $regionSlug) {
                $errors['region'] = 'La provincia indicata non appartiene a questa regione.';
            } elseif (($merged['region'] ?? null) !== $regionSlug) {
                $out['region'] = $regionSlug;
            }
        }
        $regionNow = $out['region'] ?? ($merged['region'] ?? null);
        if ($regionNow !== null && empty($merged['country']) && empty($out['country'])) {
            $out['country'] = Taxonomy::region((string) $regionNow)['country_iso2'] ?? 'IT';
        }
        if (!empty($out['municipality']) && empty($merged['province']) && empty($out['province'])) {
            $errors['municipality'] = 'Per indicare il comune serve anche la provincia.';
        }
        // Provincia principale sempre inclusa nella copertura; se cambia, sostituisce la precedente
        if (array_key_exists('province', $out) || array_key_exists('coverage', $out)) {
            $codes = array_filter(explode(',', (string) ($out['coverage'] ?? ($merged['coverage'] ?? ''))));
            if (!array_key_exists('coverage', $out) && !empty($current['province'])) {
                $codes = array_diff($codes, [$current['province']]);
            }
            $prov = $out['province'] ?? ($merged['province'] ?? null);
            if ($prov) {
                $codes[] = $prov;
            }
            $codes = array_values(array_unique($codes));
            sort($codes);
            $out['coverage'] = $codes ? implode(',', $codes) : null;
        }
        // Locatore calcolato dalle coordinate se assente
        $lat = $out['latitude'] ?? ($merged['latitude'] ?? null);
        $lon = $out['longitude'] ?? ($merged['longitude'] ?? null);
        if (($lat === null) !== ($lon === null) && (array_key_exists('latitude', $out) || array_key_exists('longitude', $out))) {
            $errors['latitude'] = 'Indica sia la latitudine sia la longitudine.';
        }
        if ($lat !== null && $lon !== null && empty($out['locator']) && empty($merged['locator'])) {
            $out['locator'] = Normalizer::latLonToLocator((float) $lat, (float) $lon, 6);
        }
        // Codici digitali coerenti con il modo (es. niente Color Code su FM analogico)
        $mode = Taxonomy::mode($merged['mode'] ?? null);
        if ($mode) {
            foreach (['color_code' => 'color_code', 'dmr_timeslot' => 'color_code', 'ran' => 'ran', 'nac' => 'nac'] as $field => $needs) {
                if ($mode['access_code'] !== $needs && ($merged[$field] ?? null) !== null) {
                    $out[$field] = null;
                }
            }
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        if ($partial && $current !== null) {
            // Rimuove i campi che non cambiano realmente
            foreach ($out as $k => $val) {
                if (self::same($current[$k] ?? null, $val)) {
                    unset($out[$k]);
                }
            }
        }
        return $out;
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return self::toText($a) === self::toText($b);
    }

    /** Rappresentazione testuale per lo storico (revision_changes). */
    public static function toText(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return is_bool($v) ? ($v ? '1' : '0') : (string) $v;
    }

    /** Converte il testo dello storico nel tipo del campo. */
    public static function fromText(string $field, ?string $v): int|string|null
    {
        if ($v === null) {
            return null;
        }
        return in_array($field, ['freq_hz', 'offset_hz', 'input_freq_hz', 'color_code', 'dmr_timeslot', 'ran', 'bandwidth_hz', 'coverage_km', 'altitude_m'], true)
            ? (int) $v : $v;
    }

    /** Valore leggibile per interfaccia e storico. */
    public static function display(string $field, ?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        return match ($field) {
            'freq_hz', 'input_freq_hz' => Normalizer::formatMHz((int) $v) . ' MHz',
            'offset_hz' => ((int) $v > 0 ? '+' : '') . rtrim(rtrim(number_format((int) $v / 1000, 3, ',', '.'), '0'), ',') . ' kHz',
            'bandwidth_hz' => rtrim(rtrim(number_format((int) $v / 1000, 2, ',', '.'), '0'), ',') . ' kHz',
            'ctcss_hz' => $v . ' Hz',
            'mode' => Taxonomy::mode($v)['code'] ?? $v,
            'service' => Taxonomy::service($v)['name'] ?? $v,
            'region' => Taxonomy::region($v)['name'] ?? $v,
            'country' => Taxonomy::get('countries')[$v]['name'] ?? $v,
            'province' => (Taxonomy::province($v)['name'] ?? $v) . ' (' . $v . ')',
            'status' => Taxonomy::STATUSES[$v] ?? $v,
            'tone_type' => ['none' => 'Nessuno', 'ctcss' => 'CTCSS', 'dcs' => 'DCS'][$v] ?? $v,
            default => $v,
        };
    }
}
