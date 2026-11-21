<?php
declare(strict_types=1);

namespace ListenIn\Domain;

/**
 * Funzioni pure di normalizzazione RF e geografica.
 * Tutte le frequenze sono gestite come interi in Hz: nessun errore di virgola mobile.
 */
final class Normalizer
{
    public const MIN_HZ = 1000;
    public const MAX_HZ = 300_000_000_000;

    /** Toni CTCSS standard EIA/TIA-603 */
    public const CTCSS_TONES = [
        67.0, 69.3, 71.9, 74.4, 77.0, 79.7, 82.5, 85.4, 88.5, 91.5, 94.8, 97.4, 100.0, 103.5,
        107.2, 110.9, 114.8, 118.8, 123.0, 127.3, 131.8, 136.5, 141.3, 146.2, 150.0, 151.4,
        156.7, 159.8, 162.2, 165.5, 167.9, 171.3, 173.8, 177.3, 179.9, 183.5, 186.2, 189.9,
        192.8, 196.6, 199.5, 203.5, 206.5, 210.7, 218.1, 225.7, 229.1, 233.6, 241.8, 250.3, 254.1,
    ];

    private const UNIT_EXP = ['hz' => 0, 'khz' => 3, 'mhz' => 6, 'ghz' => 9];

    /**
     * Converte una frequenza testuale in Hz.
     * Gestisce virgola o punto decimale, separatori delle migliaia ("74,337.5"), unità esplicite.
     */
    public static function parseFrequency(string|int|float|null $input, string $defaultUnit = 'MHz'): ?int
    {
        if ($input === null) {
            return null;
        }
        if (is_float($input)) {
            $input = rtrim(rtrim(number_format($input, 9, '.', ''), '0'), '.');
        }
        $s = strtolower(str_replace([' ', "\u{00A0}"], '', trim((string) $input)));
        if ($s === '') {
            return null;
        }
        $explicit = null;
        if (preg_match('/(ghz|mhz|khz|hz)$/', $s, $m)) {
            $explicit = $m[1];
            $s = substr($s, 0, -strlen($m[1]));
        }
        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');
        $grouped = false;
        if ($hasComma && $hasDot) {
            $grouped = true;
            $decimal = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $s = str_replace([$thousands, $decimal], ['', '.'], $s);
        } elseif ($hasComma) {
            $s = str_replace(',', '.', $s);
        }
        if (!preg_match('/^\d{1,12}(\.\d{1,12})?$/', $s)) {
            return null;
        }
        $unit = $explicit ?? strtolower($defaultUnit);
        // Separatore delle migliaia senza unità (es. "74,337.5"): convenzione degli elenchi in kHz
        if ($explicit === null && $grouped && $unit === 'mhz') {
            $unit = 'khz';
        }
        $hz = self::scale($s, self::UNIT_EXP[$unit] ?? 6);
        // Valori fuori scala senza unità esplicita: probabile notazione in kHz (es. "74,337.5")
        if ($explicit === null && $hz > self::MAX_HZ) {
            $hz = self::scale($s, 3);
        }
        return ($hz >= self::MIN_HZ && $hz <= self::MAX_HZ) ? $hz : null;
    }

    /** Moltiplica un decimale testuale per 10^exp senza passare dai float. */
    private static function scale(string $num, int $exp): int
    {
        [$int, $frac] = array_pad(explode('.', $num, 2), 2, '');
        $digits = $int . substr(str_pad($frac, $exp, '0'), 0, $exp);
        $value = (int) (ltrim($digits, '0') ?: '0');
        if (strlen($frac) > $exp && (int) $frac[$exp] >= 5) {
            $value++;
        }
        return $value;
    }

    /** Formato MHz uniforme: almeno 4 decimali, fino a 6 se necessari (es. 146.84375). */
    public static function formatMHz(?int $hz): ?string
    {
        if ($hz === null) {
            return null;
        }
        $frac = str_pad((string) ($hz % 1_000_000), 6, '0', STR_PAD_LEFT);
        $frac = rtrim($frac, '0');
        $frac = str_pad($frac, 4, '0');
        return intdiv($hz, 1_000_000) . '.' . $frac;
    }

    /** Formato a decimali fissi (export): kHz con 3 decimali, MHz con 6. */
    public static function formatFixed(?int $hz, int $exp): ?string
    {
        if ($hz === null) {
            return null;
        }
        $sign = $hz < 0 ? '-' : '';
        $hz = abs($hz);
        $div = 10 ** $exp;
        return $sign . intdiv($hz, $div) . '.' . str_pad((string) ($hz % $div), $exp, '0', STR_PAD_LEFT);
    }

    public static function ituBandCode(int $hz): ?string
    {
        return match (true) {
            $hz < 3000 => null,
            $hz < 30_000 => 'VLF',
            $hz < 300_000 => 'LF',
            $hz < 3_000_000 => 'MF',
            $hz < 30_000_000 => 'HF',
            $hz < 300_000_000 => 'VHF',
            $hz < 3_000_000_000 => 'UHF',
            $hz < 30_000_000_000 => 'SHF',
            default => null,
        };
    }

    /** Restituisce il tono CTCSS standard più vicino (tolleranza 0,6 Hz) o null. */
    public static function ctcss(string|float|int|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = (float) str_replace(',', '.', preg_replace('/[^\d.,]/', '', (string) $value) ?? '');
        foreach (self::CTCSS_TONES as $tone) {
            if (abs($tone - $v) <= 0.6) {
                return number_format($tone, 1, '.', '');
            }
        }
        return null;
    }

    /** DCS: tre cifre ottali + polarità (N normale, I invertita). */
    public static function dcs(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = strtoupper(preg_replace('/^D/i', '', trim($value)) ?? '');
        if (!preg_match('/^([0-7]{3})([NI])?$/', $v, $m)) {
            return null;
        }
        return $m[1] . ($m[2] ?? 'N');
    }

    // ---------------- Maidenhead ----------------

    public static function normalizeLocator(?string $loc): ?string
    {
        if ($loc === null) {
            return null;
        }
        $loc = trim($loc);
        if (!preg_match('/^([A-R]{2})([0-9]{2})(?:([A-X]{2})([0-9]{2})?)?$/i', $loc, $m)) {
            return null;
        }
        return strtoupper($m[1]) . $m[2] . strtolower($m[3] ?? '') . ($m[4] ?? '');
    }

    public static function latLonToLocator(float $lat, float $lon, int $chars = 6): string
    {
        $lon += 180.0;
        $lat += 90.0;
        $loc = chr(65 + (int) ($lon / 20)) . chr(65 + (int) ($lat / 10));
        $lon = fmod($lon, 20.0);
        $lat = fmod($lat, 10.0);
        $loc .= (int) ($lon / 2) . (int) $lat;
        if ($chars >= 6) {
            $lon = fmod($lon, 2.0);
            $lat = fmod($lat, 1.0);
            $loc .= chr(97 + (int) ($lon * 12)) . chr(97 + (int) ($lat * 24));
            if ($chars >= 8) {
                $lon = fmod($lon, 2.0 / 24.0);
                $lat = fmod($lat, 1.0 / 24.0);
                $loc .= (int) ($lon * 120) . (int) ($lat * 240);
            }
        }
        return $loc;
    }

    /** Centro del quadrato indicato dal locatore: [lat, lon] */
    public static function locatorToLatLon(string $loc): ?array
    {
        $loc = self::normalizeLocator($loc);
        if ($loc === null) {
            return null;
        }
        $lon = (ord($loc[0]) - 65) * 20 - 180;
        $lat = (ord($loc[1]) - 65) * 10 - 90;
        $lon += (int) $loc[2] * 2;
        $lat += (int) $loc[3];
        $w = 2.0;
        $h = 1.0;
        if (strlen($loc) >= 6) {
            $lon += (ord($loc[4]) - 97) * (2 / 24);
            $lat += (ord($loc[5]) - 97) * (1 / 24);
            $w = 2 / 24;
            $h = 1 / 24;
            if (strlen($loc) === 8) {
                $lon += (int) $loc[6] * (2 / 240);
                $lat += (int) $loc[7] * (1 / 240);
                $w = 2 / 240;
                $h = 1 / 240;
            }
        }
        return [round($lat + $h / 2, 6), round($lon + $w / 2, 6)];
    }

    // ---------------- Testo ----------------

    /** Chiave di confronto: minuscolo, senza accenti, solo alfanumerici separati da spazio. */
    public static function key(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = mb_strtolower($s);
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII');
            $s = $t ? (string) $t->transliterate($s) : $s;
        } else {
            // Senza estensione intl: tabella di traslitterazione per le lettere latine accentate
            static $map = null;
            $map ??= array_combine(
                preg_split('//u', 'àáâãäåāăąçćčďèéêëēėęěìíîïīįłñńňòóôõöøōőŕřśšşťùúûüūůűųýÿžźżđ', -1, PREG_SPLIT_NO_EMPTY),
                str_split('aaaaaaaaacccdeeeeeeeeiiiiiilnnnoooooooorrssstuuuuuuuuyyzzzd')
            );
            $s = strtr($s, $map + ['æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss', 'þ' => 'th']);
        }
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
        return trim($s);
    }

    public static function callsign(?string $cs): ?string
    {
        if ($cs === null) {
            return null;
        }
        $cs = strtoupper(preg_replace('/\s+/', '', $cs) ?? '');
        return preg_match('/^[A-Z0-9][A-Z0-9\/\-]{1,19}$/', $cs) ? $cs : null;
    }
}
