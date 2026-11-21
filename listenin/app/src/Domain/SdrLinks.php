<?php
declare(strict_types=1);

namespace ListenIn\Domain;

/**
 * Ricevitori pubblici WebSDR/KiwiSDR su cui sintonizzare una frequenza. Ogni voce ha un modello di indirizzo con
 * {khz} e {mode}. Formati: WebSDR "?tune=<kHz><modo>" (modi usb, lsb, cw, am, fm); KiwiSDR "?f=<kHz><modo>".
 * Solo Twente è predefinito (0–29,16 MHz); gli altri si aggiungono in configurazione ("sdr.receivers") oppure
 * dall'utente nel browser, con l'indirizzo del proprio ricevitore.
 */
final class SdrLinks
{
    public const DEFAULT = [
        ['name' => 'WebSDR Università di Twente (0–29 MHz)', 'kind' => 'websdr', 'url' => 'http://websdr.ewi.utwente.nl:8901/?tune={khz}{mode}',
            'min_hz' => 100_000, 'max_hz' => 29_160_000],
    ];
}
