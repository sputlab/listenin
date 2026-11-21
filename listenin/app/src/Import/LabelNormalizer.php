<?php
declare(strict_types=1);

namespace ListenIn\Import;

use ListenIn\Domain\Normalizer;

/**
 * Uniforma le descrizioni libere della fonte:
 * entità HTML, refusi ricorrenti, maiuscole, punteggiatura, marcatori di incertezza e di cessazione.
 * La descrizione originale resta registrata in listenin_frequency_sources.raw_label.
 */
final class LabelNormalizer
{
    /** Sigle e acronimi che restano in maiuscolo. */
    private const KEEP_UPPER = ['ACC', 'AFIS', 'AIB', 'AM', 'ANAS', 'ANPAS', 'APP', 'ARI', 'ATC', 'ATIS', 'ATM', 'CB',
        'CC', 'CFS', 'CISAR', 'CNSAS', 'COA', 'CRI', 'CTR', 'DAB', 'DMR', 'DSTAR', 'ENAV', 'ENEL', 'ENI', 'FCI', 'FIR', 'FIS',
        'FM', 'GDF', 'GND', 'GTT', 'ILS', 'ISS', 'LPD', 'NCC', 'NXDN', 'PL', 'PMR', 'PS', 'RAI', 'RDR', 'RFI', 'SAR',
        'SCCAM', 'SNAM', 'TETRA', 'TG', 'TV', 'TWR', 'UHF', 'VDF', 'VFR', 'VHF', 'VOR', 'VTS', 'VU', 'VVF', 'VVFF',
        'VVUU', 'NATO', 'USAF', 'RAF', 'C4FM', 'APRS', 'ACARS', 'POCSAG', 'SOREU', 'AREU', 'ARES', 'ATIVA', 'SATAP',
        'TAV', 'AMT', 'AMA', 'ATAF', 'GPL', 'SKY', 'DAZN', 'NASA', 'NOAA', 'ESA', 'ADS-B'];

    /** Parole brevi comuni che non sono sigle. */
    private const COMMON_SHORT = ['sud', 'nord', 'est', 'bus', 'via', 'san', 'del', 'dei', 'per', 'con', 'tra', 'mar', 'sci',
        'ski', 'bar', 'col', 'gas', 'ch', 'mil', 'ex', 'di', 'da', 'in', 'al', 'il', 'lo', 'la', 'le', 'e', 'a', 'o', 'ed', 'su', 'ai'];

    /** Nomi propri (province, regioni) da scrivere con l'iniziale maiuscola. */
    private static array $properNouns = [];

    public static function setProperNouns(array $names): void
    {
        foreach ($names as $n) {
            self::$properNouns[mb_strtolower($n)] = $n;
        }
    }

    /** @return array{label: string, notes: string[], uncertain: bool, inactive: bool, empty: bool} */
    public static function clean(string $raw, array $fixes = []): array
    {
        $notes = [];
        $s = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace(["\u{00A0}", '“', '”', '«', '»'], [' ', '"', '"', '"', '"'], $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        // Descrizioni vuote o fatte solo di punti interrogativi/simboli
        if ($s === '' || preg_match('/^[?\s*.\-_!]+$/u', $s)) {
            return ['label' => 'Servizio non identificato', 'notes' => [], 'uncertain' => true, 'inactive' => false, 'empty' => true];
        }

        // Marcatori di incertezza ("Vigilanza ?", "forse taxi", "probabile 118") → nota, non testo
        $uncertain = false;
        if (preg_match('/\?/', $s)) {
            $uncertain = true;
            $s = trim(preg_replace('/\s*\?+\s*/u', ' ', $s) ?? $s);
        }
        if (preg_match('/^(forse|probabile|probabilmente|sembra|quasi sicuramente|possibile)\s+/iu', $s)) {
            $uncertain = true;
            $s = (string) preg_replace('/^(forse|probabile|probabilmente|sembra|quasi sicuramente|possibile)\s+/iu', '', $s);
        }

        // Marcatori di cessazione all'inizio ("CESSATO Arma dei Carabinieri", "SPENTO ex ANPAS")
        $inactive = false;
        if (preg_match('/^(cessat[oa]|spent[oa]|dismess[oa])\b[\s:\-]*/iu', $s)) {
            $inactive = true;
            $s = (string) preg_replace('/^(cessat[oa]|spent[oa]|dismess[oa])\b[\s:\-]*/iu', '', $s);
            $notes[] = 'Indicato come cessato dalla fonte';
        }

        $rawLetters = preg_replace('/[^\p{L}]/u', '', $s) ?? '';
        $allCaps = $rawLetters !== '' && mb_strlen(preg_replace('/[^\p{Lu}]/u', '', $s) ?? '') / mb_strlen($rawLetters) > 0.7;

        // Maiuscole uniformi prima delle sostituzioni, così le forme estese mantengono la loro grafia
        $s = self::fixCase($s, $allCaps);

        // Refusi ricorrenti (dizionario in mappings.php)
        foreach ($fixes as $re => $replacement) {
            $s = (string) preg_replace_callback($re, static function ($m) use ($replacement) {
                $first = mb_substr($m[0], 0, 1);
                return $first === mb_strtoupper($first) ? mb_strtoupper(mb_substr($replacement, 0, 1)) . mb_substr($replacement, 1) : $replacement;
            }, $s);
        }

        // Punteggiatura e separatori
        $s = (string) preg_replace('/\s*([,;:])\s*/u', '$1 ', $s);
        $s = (string) preg_replace('/\s+([).])/u', '$1', $s);
        $s = (string) preg_replace('/\(\s+/u', '(', $s);
        $s = (string) preg_replace('/\s+[-–—]{1,3}\s*|\s*[-–—]{1,3}\s+|\s*[–—]\s*/u', ' - ', $s);
        $s = (string) preg_replace_callback('/\b(RU|R)\s?(\d{1,2})\s?(alfa|a)\b/iu', static fn($m) => strtoupper($m[1]) . $m[2] . 'a', $s);
        $s = (string) preg_replace_callback('/\b(RU|R)\s(\d{1,2})\b/u', static fn($m) => $m[1] . $m[2], $s);
        $s = (string) preg_replace('/\bch\.?\s*(\d)/iu', 'Ch $1', $s);
        $s = (string) preg_replace('/\s*\+\s*/u', ' + ', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s), " -,;:");
        $s = (string) preg_replace('/\(\s*\)/u', '', $s);

        $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
        if ($s === '') {
            return ['label' => 'Servizio non identificato', 'notes' => [], 'uncertain' => true, 'inactive' => $inactive, 'empty' => true];
        }
        return ['label' => mb_substr($s, 0, 160), 'notes' => $notes, 'uncertain' => $uncertain, 'inactive' => $inactive, 'empty' => false];
    }

    /** Maiuscole uniformi: niente testi tutto maiuscolo o tutto minuscolo, sigle e nomi propri preservati. */
    private static function fixCase(string $s, bool $allCaps): string
    {
        $prev = '';
        $parts = preg_split('/([\s\/(),\-]+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($parts as $i => $w) {
            if ($w === '' || preg_match('/^[\s\/(),\-]+$/u', $w)) {
                continue;
            }
            $bare = preg_replace('/[^\p{L}\p{N}\-.]/u', '', $w) ?? '';
            $lower = mb_strtolower(trim($bare, '.'));
            $upper = mb_strtoupper(trim($bare, '.'));
            $isAfterCh = $prev === 'CH';
            $prev = $upper;
            if ($upper === 'CH') {
                $parts[$i] = 'Ch';
            } elseif ($isAfterCh && mb_strlen($bare) <= 2) {
                $parts[$i] = mb_strtoupper($w);                             // canali con lettera: Ch A
            } elseif (in_array($upper, self::KEEP_UPPER, true)) {
                $parts[$i] = mb_strtoupper($w);
            } elseif (preg_match('/^(R[U]?\d+)(A|ALFA)$/i', $bare, $m)) {
                $parts[$i] = strtoupper($m[1]) . 'a';                       // canali "alfa": R7a, RU3a
            } elseif (preg_match('/^(I[A-Z]?\d[A-Z]{1,4}|IQ\d[A-Z]+|IR\d[A-Z]+|HB9[A-Z]+)(-[A-Z0-9]+)?$/i', $bare)) {
                $parts[$i] = mb_strtoupper($w);                             // nominativi
            } elseif (preg_match('/\d/', $bare)) {
                continue;                                                   // codici e numeri restano come sono
            } elseif (preg_match('/^(\p{L}\.)+\p{L}?\.?$/u', $bare)) {
                $parts[$i] = mb_strtoupper($w);                             // sigle puntate: A.N.P.A.S., FF.SS.
            } elseif (isset(self::$properNouns[$lower])) {
                $parts[$i] = self::$properNouns[$lower];
            } elseif ($allCaps) {
                $vowels = preg_match_all('/[aeiouàèéìòù]/u', $lower);
                if (mb_strlen($lower) <= 4 && $vowels <= 1 && !in_array($lower, self::COMMON_SHORT, true)) {
                    $parts[$i] = mb_strtoupper($w);                         // probabile sigla: ODV, ETS, CRI
                } elseif (in_array($lower, self::COMMON_SHORT, true) && $i > 0) {
                    $parts[$i] = mb_strtolower($w);
                } else {
                    $parts[$i] = mb_strtoupper(mb_substr($w, 0, 1)) . mb_strtolower(mb_substr($w, 1));
                }
            }
        }
        $s = implode('', $parts);
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /**
     * Gettoni significativi per il confronto tra descrizioni (deduplicazione):
     * senza parole vuote né riferimenti geografici, che sono confrontati a parte.
     */
    public static function tokens(string $label, array $geoWords): array
    {
        static $stop = ['di', 'del', 'della', 'dei', 'delle', 'dello', 'da', 'dal', 'e', 'ed', 'il', 'la', 'lo', 'le', 'i',
            'gli', 'a', 'al', 'ai', 'in', 'per', 'con', 'su', 'the', 'of', 'ch', 'canale', 'can', 'frequenza', 'freq',
            'servizio', 'servizi', 'zona', 'area', 'citta', 'prov', 'provincia', 'provinciale', 'regione', 'regionale',
            'comune', 'sez', 'sezione', 'nr', 'n', 'ex', 'arma', 'corpo'];
        $out = [];
        foreach (preg_split('/\s+/', Normalizer::key($label), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
            if (in_array($t, $stop, true) || isset($geoWords[$t])) {
                continue;
            }
            $out[self::stem($t)] = true;
        }
        return array_map('strval', array_keys($out)); // le chiavi numeriche diventano int: si riportano a stringa
    }

    /** Riduzione minima delle desinenze italiane (ambulanza/ambulanze, vigile/vigili). */
    private static function stem(string $t): string
    {
        if (ctype_digit($t) || mb_strlen($t) <= 4) {
            return $t;
        }
        return (string) preg_replace('/(i|e|a|o)$/', '', $t);
    }
}
