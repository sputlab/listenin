<?php
declare(strict_types=1);

namespace ListenIn\Import;

use ListenIn\Core\Config;
use ListenIn\Domain\Normalizer;
use ListenIn\Domain\Taxonomy;

/**
 * Converte una riga della sorgente (tassonomia libera) nei campi logici
 * a schema rigido di ListenIn, annotando avvisi e campi non riconosciuti.
 */
final class RecordMapper
{
    private array $map;
    private array $columns = [];

    public function __construct(?array $mappings = null, array $columnOverrides = [])
    {
        $this->map = $mappings ?? require __DIR__ . '/mappings.php';
        $this->columns = $columnOverrides; // es. ['freq' => 'Freq. (MHz)']
    }

    /** Associa le intestazioni del file ai campi sorgente canonici. */
    public function bindHeaders(array $headers): array
    {
        $bound = $this->columns;
        foreach ($headers as $h) {
            $k = Normalizer::key((string) $h);
            foreach ($this->map['headers'] as $field => $synonyms) {
                if (!isset($bound[$field]) && in_array($k, $synonyms, true)) {
                    $bound[$field] = $h;
                }
            }
        }
        if (!isset($bound['freq'])) {
            throw new \RuntimeException('Colonna della frequenza non trovata. Usa --map=freq:"Nome colonna".');
        }
        return $this->columns = $bound;
    }

    /**
     * @return array{logical: array, ref: string, warnings: string[], unclassified: bool}|array{error: string}
     */
    public function map(array $row): array
    {
        $get = function (string $field) use ($row): ?string {
            $col = $this->columns[$field] ?? null;
            if ($col === null || !isset($row[$col])) {
                return null;
            }
            $v = trim(html_entity_decode((string) $row[$col], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
            return $v === '' ? null : $v;
        };
        $warnings = [];

        $freqHz = Normalizer::parseFrequency($get('freq'), 'MHz');
        if ($freqHz === null) {
            return ['error' => 'frequenza non valida: ' . mb_substr((string) $get('freq'), 0, 30)];
        }

        $rawLabel = $get('service');
        $clean = LabelNormalizer::clean((string) $rawLabel, $this->map['label_fixes'] ?? []);
        $label = $clean['label'];
        $notes = $clean['notes'];
        if ($clean['empty']) {
            $warnings[] = 'descrizione assente';
        } elseif ($clean['uncertain']) {
            $notes[] = 'Identificazione incerta secondo la fonte';
        }
        if ($rawLabel !== null && mb_strlen($rawLabel) > 160) {
            $notes[] = $rawLabel;
        }
        $key = Normalizer::key($label);
        if (!empty($this->map['skip_labels']) && preg_match($this->map['skip_labels'], Normalizer::key((string) $rawLabel))) {
            return ['error' => 'record di prova o marcato per l\'eliminazione dalla fonte: ' . mb_substr((string) $rawLabel, 0, 40)];
        }

        // ---- Modo ----
        $mode = null;
        $rawMode = $get('mode');
        if ($rawMode !== null) {
            $mode = $this->map['modes'][Normalizer::key($rawMode)] ?? null;
            if ($mode === null) {
                $warnings[] = 'modo sconosciuto: ' . $rawMode;
            }
        }
        foreach ($this->map['mode_from_label'] as $re => $code) {
            if (preg_match($re, $key)) {
                $mode = $code;
                break;
            }
        }
        if ($mode === null && $this->inRange($freqHz, 'aero')) {
            $mode = 'AM';
        }
        $mode ??= 'ND';

        // ---- Servizio ----
        $explicit = $get('service_slug');
        if ($explicit !== null && Taxonomy::service($explicit) !== null) {
            [$service, $ruleNote, $classifiedBy] = [$explicit, null, 'explicit'];
        } else {
            [$service, $ruleNote, $classifiedBy] = $this->classify($key, $get('genere'), $freqHz, $mode);
        }
        $unclassified = in_array($classifiedBy, ['range', 'none'], true);
        if ($ruleNote) {
            $notes[] = $ruleNote;
        }

        // ---- Geografia ----
        [$region, $country, $areaNote] = $this->region($get('region'));
        [$province, $coverage, $areaText, $areaRegion, $areaCountry, $areaMunicipality] = $this->area($get('area'));
        $region ??= $areaRegion;
        if ($areaCountry !== null && $areaCountry !== 'IT' && !$coverage) {
            $country = $areaCountry;
            $region = null;
        } elseif ($areaCountry === 'IT') {
            $country ??= 'IT';
        }
        if ($areaText !== null) {
            $areaNote = trim(($areaNote ? $areaNote . '; ' : '') . $areaText);
        }
        if ($region === null && $coverage) {
            $regions = array_unique(array_map(static fn($c) => (int) Taxonomy::province($c)['region_id'], $coverage));
            if (count($regions) === 1) {
                $region = Taxonomy::region((int) $regions[0])['slug'];
            }
        }
        if ($province !== null && $region !== null && Taxonomy::region((int) Taxonomy::province($province)['region_id'])['slug'] !== $region) {
            $warnings[] = "provincia $province incoerente con la regione $region: provincia ignorata";
            $province = null;
            $coverage = array_values(array_filter($coverage, fn($c) => Taxonomy::region((int) Taxonomy::province($c)['region_id'])['slug'] === $region));
        }
        $municipality = $get('city') ?? $areaMunicipality;
        if ($municipality !== null && !$province) {
            // senza provincia il comune non è registrabile: la località resta leggibile (e confrontabile) nell'area
            $areaNote = trim(($areaNote ? $areaNote . '; ' : '') . 'Località: ' . mb_substr($municipality, 0, 80));
            $municipality = null;
        }

        // ---- Stato ----
        $status = 'da_verificare';
        $rawStatus = $get('status');
        if ($rawStatus !== null) {
            $status = $this->map['statuses'][Normalizer::key($rawStatus)] ?? 'da_verificare';
            if (Normalizer::key($rawStatus) === 'contestata') {
                $notes[] = 'Dato contestato nella fonte originale';
            }
        }
        if ($clean['inactive'] || preg_match($this->map['inactive_markers'], Normalizer::key((string) $rawLabel))) {
            $status = 'inattivo';
        }

        // ---- Parametri RF ricavati dal testo ----
        $toneSrc = trim(($get('tone') ?? '') . ' ' . $label);
        $ctcss = null;
        if (preg_match('/(?<![\d.,])(\d{2,3}(?:[.,]\d)?)\s*hz\b/i', $toneSrc, $m)) {
            $ctcss = Normalizer::ctcss($m[1]);
        } elseif (($t = $get('tone')) !== null && preg_match('/^\d{2,3}([.,]\d)?$/', $t)) {
            $ctcss = Normalizer::ctcss($t);
        }
        $dcs = null;
        if ($ctcss === null && preg_match('/\b(?:dcs\s*)?d?([0-7]{3}[ni])\b/i', (string) $get('tone'), $m)) {
            $dcs = Normalizer::dcs($m[1]);
        }
        $colorCode = preg_match('/\bcc\s?(\d{1,2})\b/i', $label, $m) && (int) $m[1] <= 15 ? (int) $m[1] : null;
        $talkgroup = preg_match('/\btg\s?:?\s?(\d{1,8})\b/i', $label, $m) ? $m[1] : null;
        $ran = preg_match('/\bran\s?(\d{1,2})\b/i', $label, $m) && (int) $m[1] <= 63 ? (int) $m[1] : null;
        $nac = preg_match('/\bnac\s?([0-9a-f]{3})\b/i', $label, $m) ? strtoupper($m[1]) : null;
        if (preg_match_all('/(?:nodo\s*(?:e\.?l\.?|echolink)?\s*#?|#)(\d{4,7})/i', $label, $mm)) {
            $notes[] = 'Nodo Echolink ' . implode(', ', array_unique($mm[1]));
        }
        $callsign = null;
        if (preg_match('/\b(I[A-Z]?\d[A-Z]{1,4}|IQ\d[A-Z]{2,3}|IR\d[A-Z]{1,5}|HB9[A-Z]{1,3}|T7\d[A-Z]{1,3})(?:[\/\-][A-Z0-9]{1,3})?\b/', strtoupper($label), $m)) {
            $callsign = Normalizer::callsign($m[0]);
        }
        $channel = null;
        if (preg_match('/\b(RU?)\s?(\d{1,2})\s?(A|ALFA)?\b/i', $label, $m) && str_starts_with($service, 'ponti')) {
            $channel = strtoupper($m[1]) . $m[2] . (!empty($m[3]) ? 'a' : '');
        } elseif (preg_match('/\b(?:CH|CANALE(?: NAUTICO)?(?: VHF)?)\.?\s?(\d{1,3}[A-Z]?)\b/i', $label, $m)) {
            $channel = 'CH' . strtoupper($m[1]);
        }

        if (($v = $get('channel')) !== null) {
            $channel = mb_substr($v, 0, 20);
        }
        if (($v = Normalizer::callsign($get('callsign'))) !== null) {
            $callsign = $v;
        }
        $inputHz = ($v = $get('input')) !== null ? Normalizer::parseFrequency($v) : null;

        // ---- Offset dei ripetitori ----
        $offset = null;
        if (str_starts_with($service, 'ponti') && $service !== 'ponti-link') {
            foreach ((array) Config::get('import.repeater_offsets', []) as $rule) {
                if ($freqHz >= $rule['min'] && $freqHz < $rule['max']) {
                    $offset = (int) $rule['offset'];
                    $warnings[] = 'shift dedotto dal band plan';
                }
            }
        }

        // ---- Ente ----
        $entity = null;
        foreach ($this->map['entities'] as $re => $name) {
            if (preg_match($re, $key)) {
                $entity = $name;
                break;
            }
        }

        // ---- Data ultimo ascolto ----
        $heard = null;
        if (($h = $get('heard')) !== null) {
            foreach (['!d/m/Y', '!d-m-Y', '!Y-m-d', '!d/m/y', '!d.m.Y'] as $fmt) {
                $dt = \DateTimeImmutable::createFromFormat($fmt, substr($h, 0, 10));
                if ($dt && $dt <= new \DateTimeImmutable('tomorrow')) {
                    $heard = $dt->format('Y-m-d');
                    break;
                }
            }
        }
        if (($n = $get('notes')) !== null) {
            $notes[] = $n;
        }
        if (($v = $get('entity')) !== null) {
            $entity = mb_substr($v, 0, 120);
        }
        $loc = Normalizer::normalizeLocator($get('locator'));

        $logical = [
            'freq_hz' => $freqHz,
            'offset_hz' => $inputHz === null ? $offset : null,
            'input_freq_hz' => $inputHz,
            'tone_type' => $ctcss ? 'ctcss' : ($dcs ? 'dcs' : 'none'),
            'ctcss_hz' => $ctcss,
            'dcs_code' => $ctcss ? null : $dcs,
            'color_code' => $mode === 'DMR' ? $colorCode : null,
            'talkgroup' => $talkgroup,
            'ran' => $mode === 'NXDN' ? $ran : null,
            'nac' => $mode === 'P25' ? $nac : null,
            'mode' => $mode,
            'service' => $service,
            'label' => $label,
            'channel' => $channel,
            'country' => $country ?? (($region || $coverage || $get('region') !== null) ? 'IT' : null),
            'region' => $region,
            'province' => $province,
            'coverage' => $coverage ? implode(',', $coverage) : null,
            'municipality' => $province ? ($municipality !== null ? mb_substr($municipality, 0, 100) : null) : null,
            'area_note' => $areaNote !== null ? mb_substr($areaNote, 0, 120) : null,
            'locator' => $loc,
            'callsign' => $callsign,
            'entity' => $entity,
            'status' => $status,
            'last_heard_at' => $heard,
            'notes' => $notes ? mb_substr(implode("\n", array_unique($notes)), 0, 2000) : null,
        ];

        // Riferimento stabile alla riga sorgente: id della fonte, altrimenti impronta dei campi identificativi
        $ref = $get('id') ?? 'h' . substr(sha1(implode('|', [$freqHz, Normalizer::key((string) $rawLabel), $get('region'), $get('area')])), 0, 39);
        $rawHash = sha1(json_encode(array_map(static fn($v) => is_string($v) ? trim($v) : $v, $row), JSON_UNESCAPED_UNICODE));
        return ['logical' => $logical, 'ref' => mb_substr($ref, 0, 64), 'raw_hash' => $rawHash,
            'raw_label' => $rawLabel !== null ? mb_substr($rawLabel, 0, 255) : null,
            'warnings' => $warnings, 'unclassified' => $unclassified, 'classified_by' => $classifiedBy];
    }

    /** @return array{0:string,1:?string,2:string} [slug servizio, nota della regola, criterio: rule|genere|range|none] */
    private function classify(string $key, ?string $genere, int $hz, string $mode): array
    {
        foreach ($this->map['rules'] as $rule) {
            if (isset($rule['in']) && !$this->inRange($hz, $rule['in'])) {
                continue;
            }
            if (!preg_match($rule['re'], $key)) {
                continue;
            }
            if (!empty($rule['repeater'])) {
                return [match ($mode) {
                    'DMR' => 'ponti-dmr', 'DSTAR' => 'ponti-dstar', 'C4FM' => 'ponti-c4fm', default => 'ponti-analogici',
                }, null, 'rule'];
            }
            return [$rule['service'], $rule['note'] ?? null, 'rule'];
        }
        if ($genere !== null && isset($this->map['genere'][Normalizer::key($genere)])) {
            return [$this->map['genere'][Normalizer::key($genere)], null, 'genere'];
        }
        foreach ($this->map['range_fallback'] as [$min, $max, $slug]) {
            if ($hz >= $min && $hz < $max) {
                return [$slug, null, 'range'];
            }
        }
        return ['utility-altro', null, 'none'];
    }

    private function inRange(int $hz, string $name): bool
    {
        foreach ($this->map['ranges'][$name] ?? [] as [$min, $max]) {
            if ($hz >= $min && $hz < $max) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:?string,1:?string,2:?string} [slug regione, nazione, nota area] */
    private function region(?string $raw): array
    {
        if ($raw === null) {
            return [null, null, null];
        }
        $k = Normalizer::key($raw);
        if (in_array($k, $this->map['region_none'], true)) {
            return [null, null, null];
        }
        if (in_array($k, $this->map['region_national'], true)) {
            return [null, 'IT', 'Nazionale'];
        }
        if (isset($this->map['regions'][$k])) {
            return [$this->map['regions'][$k], 'IT', null];
        }
        return [null, null, mb_substr($raw, 0, 60)];
    }

    /** Comuni ISTAT (se caricati): chiave normalizzata → elenco di [sigla provincia, nome]. */
    private ?array $municipalities = null;

    private function municipalityIndex(): array
    {
        if ($this->municipalities === null) {
            $this->municipalities = [];
            try {
                foreach (\ListenIn\Core\Database::all(
                    'SELECT m.name, m.name_key, p.code FROM listenin_municipalities m JOIN listenin_provinces p ON p.id = m.province_id'
                ) as $r) {
                    $this->municipalities[$r['name_key']][] = [$r['code'], $r['name']];
                }
            } catch (\Throwable) {
                // database non disponibile (es. calibrazione offline): nessun comune
            }
        }
        return $this->municipalities;
    }

    /**
     * Interpreta il campo "Provincia / area": sigle singole o multiple, nomi di provincia o regione,
     * "Comune (XX)", comuni ISTAT, nazioni estere, testo libero.
     * @return array{0:?string,1:string[],2:?string,3:?string,4:?string,5:?string}
     *         [provincia principale, copertura, testo residuo, regione, nazione, comune]
     */
    private function area(?string $raw): array
    {
        if ($raw === null || preg_match('/^[?\s.]+$/', $raw) || mb_strlen(trim($raw)) < 2) {
            return [null, [], null, null, null, null];
        }
        $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $k = Normalizer::key($raw);
        $region = null;
        $country = null;
        $municipality = null;

        // Nazioni estere e copertura nazionale
        foreach ($this->map['area_countries'] as $re => $iso) {
            if (preg_match($re, $k)) {
                $country = $iso;
            }
        }
        if (in_array($k, $this->map['region_national'], true) || preg_match('/\b(nazionale|tutta italia|in tutta italia)\b/', $k)) {
            return [null, [], 'Nazionale', null, 'IT', null];
        }

        $tokens = preg_split('/[\s,;\/\-+().]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $allShort = $tokens && count(array_filter($tokens, static fn($t) => mb_strlen($t) === 2)) === count($tokens);
        $codes = [];
        $afterProv = false;
        foreach ($tokens as $t) {
            $tl = mb_strtolower($t);
            // Sigle valide se maiuscole, se tutto il campo è fatto di sigle o se precedute da "prov"
            if (mb_strlen($t) === 2 && ($allShort || $afterProv || $t === mb_strtoupper($t))) {
                $code = strtoupper($t);
                $code = $this->map['province_legacy'][$code] ?? $code;
                if (Taxonomy::province($code)) {
                    $codes[$code] = true;
                }
            }
            $afterProv = in_array($tl, ['prov', 'provincia', 'province'], true);
        }

        // "Comune (XX)": comune esplicito con sigla
        if (preg_match('/^([\p{L}\' .]{3,60}?)\s*\(([A-Za-z]{2})\)$/u', $raw, $m) && Taxonomy::province(strtoupper($m[2]))) {
            $municipality = trim($m[1]);
        }

        // Nomi di provincia e di regione all'inizio di ciascun segmento
        $segments = preg_split('#[/,;]|\s-\s|-#u', $raw) ?: [];
        $names = [];
        foreach (Taxonomy::get('provinces') as $p) {
            $names[Normalizer::key($p['name'])] = $p['code'];
        }
        $names += $this->map['province_names'];
        $regionsFound = [];
        foreach ($segments as $segment) {
            $sk = Normalizer::key($segment);
            if ($sk === '') {
                continue;
            }
            if (!$codes || count($segments) > 1) {
                foreach ($names as $name => $code) {
                    if ($sk === $name || str_starts_with($sk, $name . ' ')) {
                        $codes[$code] = true;
                        break;
                    }
                }
            }
            $rk = preg_replace('/^(tutta|tutto|bassa|basso|alta|alto) /', '', $sk) ?? $sk;
            foreach ($this->map['regions'] + $this->map['area_regions'] as $alias => $slug) {
                if ($rk === $alias || str_starts_with($rk, $alias . ' ')) {
                    $regionsFound[$slug] = true;
                }
            }
        }

        // Comuni ISTAT (se caricati): nome univoco → comune + provincia
        if (!$codes && $municipality === null) {
            $idx = $this->municipalityIndex();
            foreach ($segments as $segment) {
                $sk = Normalizer::key($segment);
                if (isset($idx[$sk]) && count($idx[$sk]) === 1) {
                    [$code, $name] = $idx[$sk][0];
                    $codes[$code] = true;
                    $municipality ??= $name;
                }
            }
        }

        $codes = array_keys($codes);
        sort($codes);
        if (count($regionsFound) === 1) {
            $region = array_key_first($regionsFound);
        }
        $primary = count($codes) === 1 ? $codes[0] : null;
        // Testo libero conservato se aggiunge informazione rispetto a sigle e nomi riconosciuti
        $residual = null;
        $plain = $allShort || ($primary && Normalizer::key(Taxonomy::province($primary)['name']) === $k)
            || ($region && count($regionsFound) === 1 && isset($this->map['regions'][$k]));
        if (!$plain && $municipality === null) {
            $residual = mb_substr($raw, 0, 80);
        }
        return [$primary, $codes, $residual, $region, $country, $municipality];
    }
}
