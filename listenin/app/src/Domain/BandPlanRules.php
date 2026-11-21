<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\Config;

/**
 * Suggerimenti di shift per i ripetitori, in base al piano di banda. Sono solo suggerimenti: nel modulo
 * restano sempre modificabili. Le regole si possono sostituire in configurazione ("bandplan.rules").
 *
 * ATTENZIONE: i valori sui 70 cm (+1,6 MHz e +5 MHz) seguono le indicazioni del progetto e vanno confrontati
 * con il piano di banda in uso (ARI/IARU Regione 1) prima di essere considerati definitivi.
 */
final class BandPlanRules
{
    public const DEFAULT = [
        ['name' => '2 m: uscite dei ripetitori (R0–R7a)', 'min_hz' => 145_600_000, 'max_hz' => 145_800_000,
            'offsets' => [['hz' => -600_000, 'label' => '−600 kHz']]],
        ['name' => '70 cm: ripetitori', 'min_hz' => 430_000_000, 'max_hz' => 431_999_999,
            'offsets' => [['hz' => 1_600_000, 'label' => '+1,6 MHz'], ['hz' => 5_000_000, 'label' => '+5 MHz']]],
    ];

    public static function all(): array
    {
        return (array) Config::get('bandplan.rules', self::DEFAULT);
    }

    /** @return array{name:string,offsets:array}|null */
    public static function forFrequency(int $hz): ?array
    {
        foreach (self::all() as $r) {
            if ($hz >= (int) $r['min_hz'] && $hz <= (int) $r['max_hz']) {
                return $r;
            }
        }
        return null;
    }
}
