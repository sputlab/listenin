<?php
declare(strict_types=1);

namespace ListenIn\Export;

use ListenIn\Domain\Normalizer;

/**
 * Esportazione per i software di programmazione degli apparati radio.
 *
 * ATTENZIONE: i tracciati sono ricostruiti dalla documentazione dei programmi e NON sono stati provati con CHIRP
 * o con la CPS di OpenGD77 reali: prima di caricarli su un apparato verificare l'importazione su pochi canali.
 * Si esportano solo i modi supportati: CHIRP gli analogici (FM, NFM, WFM, AM, SSB, CW) e D-STAR;
 * OpenGD77 gli analogici FM/NFM e il DMR.
 */
final class RadioFormats
{
    public const CHIRP_HEADER = ['Location', 'Name', 'Frequency', 'Duplex', 'Offset', 'Tone', 'rToneFreq', 'cToneFreq', 'DtcsCode',
        'DtcsPolarity', 'RxDtcsCode', 'CrossMode', 'Mode', 'TStep', 'Skip', 'Power', 'Comment', 'URCALL', 'RPT1CALL', 'RPT2CALL', 'DVCODE'];

    public const OPENGD77_HEADER = ['Channel Number', 'Channel Name', 'Channel Type', 'Rx Frequency', 'Tx Frequency', 'Bandwidth (kHz)',
        'Colour Code', 'Timeslot', 'Contact', 'TG List', 'DMR ID', 'TS1_TA_Tx ID', 'TS2_TA_Tx ID', 'RX Tone', 'TX Tone', 'Squelch', 'Power',
        'Rx Only', 'Zone Skip', 'All Skip', 'TOT', 'VOX', 'No Beep', 'No Eco', 'APRS', 'Latitude', 'Longitude', 'Use location'];

    private const CHIRP_MODES = ['FM' => 'FM', 'NFM' => 'NFM', 'WFM' => 'WFM', 'AM' => 'AM', 'USB' => 'USB', 'LSB' => 'LSB', 'CW' => 'CW', 'DSTAR' => 'DV'];

    private static function name(array $r, int $max): string
    {
        $base = (string) ($r['callsign'] ?: ($r['channel'] ?: $r['label']));
        return rtrim(substr(trim(strtoupper(Normalizer::key($base))), 0, $max));
    }

    private static function dcsParts(?string $code): array
    {
        $digits = (string) preg_replace('/\D/', '', (string) $code);
        return [$digits === '' ? '023' : str_pad(substr($digits, 0, 3), 3, '0', STR_PAD_LEFT), str_ends_with((string) $code, 'I') ? 'I' : 'N'];
    }

    /** Frequenza di trasmissione in Hz: ingresso se noto, altrimenti ricezione + shift. */
    private static function txHz(array $r): int
    {
        $rx = (int) $r['freq_hz'];
        if ($r['input_freq_mhz'] !== null) {
            return (int) round((float) $r['input_freq_mhz'] * 1_000_000);
        }
        return $rx + (int) ($r['offset_hz'] ?? 0);
    }

    /** @return array{written:int,skipped:int} */
    public static function chirp(iterable $rows, $out): array
    {
        fputcsv($out, self::CHIRP_HEADER, ',', '"', '');
        $n = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $mode = self::CHIRP_MODES[$r['mode']] ?? null;
            if ($mode === null) {
                $skipped++;
                continue;
            }
            $rx = (int) $r['freq_hz'];
            $tx = self::txHz($r);
            $delta = $tx - $rx;
            $duplex = '';
            $offset = '0.600000';
            if ($delta !== 0) {
                if (abs($delta) > 70_000_000) {
                    [$duplex, $offset] = ['split', Normalizer::formatFixed($tx, 6)];
                } else {
                    [$duplex, $offset] = [$delta < 0 ? '-' : '+', Normalizer::formatFixed(abs($delta), 6)];
                }
            }
            $tone = '';
            $ctcss = '88.5';
            [$dcs, $pol] = self::dcsParts($r['dcs_code']);
            if ($r['tone_type'] === 'ctcss' && $r['ctcss_hz'] !== null) {
                $tone = $duplex !== '' ? 'Tone' : 'TSQL';
                $ctcss = number_format((float) $r['ctcss_hz'], 1, '.', '');
            } elseif ($r['tone_type'] === 'dcs') {
                $tone = 'DTCS';
            }
            fputcsv($out, [$n, self::name($r, 8), Normalizer::formatFixed($rx, 6), $duplex, $offset, $tone, $ctcss, $ctcss, $dcs,
                $pol . $pol, $dcs, 'Tone->Tone', $mode, '5.00', '', '', mb_substr((string) $r['label'], 0, 60), '', '', '', ''], ',', '"', '');
            $n++;
        }
        return ['written' => $n, 'skipped' => $skipped];
    }

    /** @return array{written:int,skipped:int} */
    public static function opengd77(iterable $rows, $out): array
    {
        fputcsv($out, self::OPENGD77_HEADER, ',', '"', '');
        $n = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $digital = $r['mode'] === 'DMR';
            if (!$digital && !in_array($r['mode'], ['FM', 'NFM'], true)) {
                $skipped++;
                continue;
            }
            $rx = (int) $r['freq_hz'];
            $tx = self::txHz($r);
            $tone = 'None';
            if ($r['tone_type'] === 'ctcss' && $r['ctcss_hz'] !== null) {
                $tone = number_format((float) $r['ctcss_hz'], 1, '.', '');
            } elseif ($r['tone_type'] === 'dcs') {
                [$dcs, $pol] = self::dcsParts($r['dcs_code']);
                $tone = 'D' . $dcs . $pol;
            }
            $n++;
            fputcsv($out, [$n, self::name($r, 16), $digital ? 'Digital' : 'Analogue', number_format($rx / 1_000_000, 5, '.', ''), number_format($tx / 1_000_000, 5, '.', ''),
                $digital ? '' : ($r['mode'] === 'NFM' ? '12.5' : '25'), $digital ? ($r['color_code'] ?? 1) : '', $digital ? ($r['dmr_timeslot'] ?? 1) : '',
                'None', 'None', 'None', 'Off', 'Off', $digital ? '' : $tone, $digital ? '' : $tone, $digital ? '' : 'Disabled', 'Master',
                'No', 'No', 'No', 0, 'Off', 'No', 'No', 'None', 0, 0, 'No'], ',', '"', '');
        }
        return ['written' => $n, 'skipped' => $skipped];
    }
}
