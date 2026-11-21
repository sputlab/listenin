<?php
declare(strict_types=1);

namespace ListenIn\Import;

use ListenIn\Domain\Normalizer;
use ListenIn\Domain\Taxonomy;

/**
 * Deduplicazione a tre esiti, sempre sulla stessa frequenza esatta (Hz):
 *
 *  - CERTO     stessa area (regione uguale o province in comune) e descrizione equivalente
 *              (stessi gettoni significativi, o somiglianza alta con stesso servizio)  → fusione automatica
 *  - PROBABILE area compatibile e descrizioni simili ma non abbastanza per fondere     → segnalazione ai moderatori
 *  - DISTINTO  regioni diverse, ambiti diversi o descrizioni non correlate             → record separati
 *
 * Due record con la stessa frequenza in regioni diverse NON sono duplicati: in Italia
 * le stesse frequenze sono riassegnate su base territoriale.
 */
final class Deduplicator
{
    public const CERTAIN = 'certo';
    public const PROBABLE = 'probabile';

    private array $geoWords;

    public function __construct()
    {
        // Parole geografiche escluse dal confronto delle descrizioni (il confronto geografico è separato)
        $geo = [];
        foreach (Taxonomy::get('provinces') as $p) {
            $geo[strtolower($p['code'])] = true;
            foreach (explode(' ', Normalizer::key($p['name'])) as $w) {
                if (strlen($w) > 2) {
                    $geo[$w] = true;
                }
            }
        }
        foreach (Taxonomy::get('regions') as $r) {
            foreach (explode(' ', Normalizer::key($r['name'])) as $w) {
                if (strlen($w) > 2) {
                    $geo[$w] = true;
                }
            }
        }
        $this->geoWords = $geo;
    }

    /** Profilo di confronto di un record logico. */
    public function profile(array $logical): array
    {
        return [
            'hz' => (int) $logical['freq_hz'],
            'tokens' => LabelNormalizer::tokens((string) $logical['label'], $this->geoWords),
            'region' => $logical['region'] ?? null,
            'provinces' => array_filter(explode(',', (string) ($logical['coverage'] ?? ''))),
            'service' => $logical['service'] ?? null,
            'root' => ($s = Taxonomy::service((string) ($logical['service'] ?? ''))) ? (Taxonomy::rootService((int) $s['id'])['slug'] ?? null) : null,
            'channel' => isset($logical['channel']) ? strtoupper((string) $logical['channel']) : null,
            'callsign' => $logical['callsign'] ?? null,
            'family' => ($m = Taxonomy::mode((string) ($logical['mode'] ?? 'ND'))) && $m['code'] !== 'ND' ? $m['family'] : null,
            'nogeo' => empty($logical['region']) && empty($logical['coverage']),
            'place' => $this->placeKeyOf($logical),
            'labelKey' => Normalizer::key((string) $logical['label']),
        ];
    }

    /**
     * Località puntuale del record (comune o "Località: …" in area), per distinguere impianti diversi.
     * Se coincide con il capoluogo della provincia non è una località "più piccola" della provincia: si ignora.
     */
    private function placeKeyOf(array $logical): ?string
    {
        $p = $logical['municipality'] ?? null;
        if (($p === null || $p === '') && !empty($logical['area_note']) && preg_match('/Località: ([^;]+)/u', (string) $logical['area_note'], $m)) {
            $p = $m[1];
        }
        $k = $p !== null ? Normalizer::key((string) $p) : '';
        if ($k === '') {
            return null;
        }
        foreach (array_filter(explode(',', (string) ($logical['coverage'] ?? ''))) as $code) {
            if (($prov = Taxonomy::province($code)) && Normalizer::key($prov['name']) === $k) {
                return null;
            }
        }
        return $k;
    }

    /**
     * Confronta due profili della stessa frequenza.
     * @return array{0: ?string, 1: float, 2: string} [esito, somiglianza, motivo]
     */
    public function compare(array $a, array $b): array
    {
        if ($a['hz'] !== $b['hz']) {
            return [null, 0.0, 'frequenza diversa'];
        }
        // Nominativi o canali diversi e valorizzati: sicuramente impianti diversi
        if ($a['callsign'] && $b['callsign'] && $a['callsign'] !== $b['callsign']) {
            return [null, 0.0, 'nominativi diversi'];
        }
        if ($a['channel'] && $b['channel'] && $a['channel'] !== $b['channel']) {
            return [null, 0.0, 'canali diversi'];
        }
        // Località puntuale: due località diverse sono impianti diversi; una località contro un'area più ampia
        // (es. "Frascati" contro "provincia di Roma") lo sono pure, salvo che l'altra descrizione citi la stessa località.
        $place = null;
        if (!empty($a['place']) && !empty($b['place'])) {
            if ($a['place'] !== $b['place']) {
                return [null, 0.0, 'località diverse'];
            }
            $place = $a['place'];
        } elseif (!empty($a['place']) || !empty($b['place'])) {
            $p = $a['place'] ?: $b['place'];
            $other = !empty($a['place']) ? $b : $a;
            if (!str_contains(' ' . $other['labelKey'] . ' ', ' ' . $p . ' ')) {
                return [null, 0.0, 'una località e un\'area più ampia'];
            }
            $place = $p;
        }
        $geo = $this->geo($a, $b);
        if ($geo === 'different') {
            return [null, 0.0, 'aree diverse'];
        }
        if ($geo === 'coarse') {
            return [null, 0.0, 'area provinciale e area regionale: livelli diversi'];
        }
        // La località è già confrontata sopra: si toglie dal testo per non farla pesare nella somiglianza
        if ($place !== null) {
            $pt = LabelNormalizer::tokens($place, $this->geoWords);
            $a['tokens'] = array_values(array_diff($a['tokens'], $pt));
            $b['tokens'] = array_values(array_diff($b['tokens'], $pt));
        }
        $sim = $this->similarity($a['tokens'], $b['tokens']);
        $sameService = $a['service'] !== null && $a['service'] === $b['service'];
        $compatibleRoot = $a['root'] === $b['root'] || in_array('utility-altro', [$a['service'], $b['service']], true);
        if (!$compatibleRoot && $sim < 0.8) {
            return [null, $sim, 'ambiti diversi'];
        }
        // Numeri nella descrizione (canali, sezioni) devono coincidere
        $na = array_filter($a['tokens'], 'ctype_digit');
        $nb = array_filter($b['tokens'], 'ctype_digit');
        if ($na && $nb && !array_intersect($na, $nb)) {
            return [null, $sim, 'numeri di canale diversi'];
        }

        // Analogico e digitale sulla stessa frequenza: spesso è una migrazione, non un doppione
        $familyClash = $a['family'] && $b['family'] && $a['family'] !== $b['family'];
        $sameChannel = $a['channel'] && $a['channel'] === $b['channel'];

        if ($geo === 'same') {
            if ($familyClash) {
                return $sim >= 0.34 ? [self::PROBABLE, $sim, 'stessa area, modo analogico e digitale diversi'] : [null, $sim, 'modi diversi'];
            }
            if ($sim >= 0.999 || ($sameService && $sim >= 0.6) || ($a['callsign'] && $a['callsign'] === $b['callsign'])) {
                return [self::CERTAIN, $sim, 'stessa area, descrizione equivalente'];
            }
            // serve almeno una parte di testo in comune: stesso servizio e stessa zona non bastano
            // (es. due diverse pubbliche assistenze nella stessa città sulla stessa frequenza)
            if ($sim >= 0.34) {
                return [self::PROBABLE, $sim, 'stessa area, descrizioni simili'];
            }
            return [null, $sim, 'descrizioni diverse'];
        }
        // Area sconosciuta per almeno uno dei due
        if ($a['nogeo'] && $b['nogeo']) {
            if ($sameService && !$familyClash && ($sameChannel || $sim >= 0.75)) {
                return [self::CERTAIN, $sim, 'stesso servizio e canale, entrambi senza area (nazionale)'];
            }
            return [null, $sim, 'descrizioni diverse'];
        }
        // Un record localizzato e uno no (es. frequenza nazionale e istanza provinciale): hanno granularità diversa,
        // quindi non sono doppioni; solo se il testo è identico si chiede una verifica.
        if ($sim >= 0.999 && $sameService && !$familyClash) {
            return [self::PROBABLE, $sim, 'descrizione identica, area non indicata in uno dei due'];
        }
        return [null, $sim, 'area non confrontabile'];
    }

    private function geo(array $a, array $b): string
    {
        if ($a['provinces'] && $b['provinces']) {
            return array_intersect($a['provinces'], $b['provinces']) ? 'same' : 'different';
        }
        if ($a['provinces'] xor $b['provinces']) {
            // province contro sola regione (es. canale regionale e istanza provinciale): granularità diversa
            return ($a['region'] && $b['region'] && $a['region'] !== $b['region']) ? 'different' : 'coarse';
        }
        if ($a['region'] && $b['region']) {
            return $a['region'] === $b['region'] ? 'same' : 'different';
        }
        return 'unknown';
    }

    /** Somiglianza tra insiemi di gettoni (Jaccard, con contenimento come caso pieno). */
    public function similarity(array $x, array $y): float
    {
        if (!$x && !$y) {
            return 1.0;
        }
        if (!$x || !$y) {
            return 0.0;
        }
        $inter = count(array_intersect($x, $y));
        $union = count(array_unique(array_merge($x, $y)));
        $jaccard = $inter / $union;
        // "118" contro "118 soccorso": contenimento totale ma con un gettone in più.
        // Con un solo gettone in comune ("Volontari" contro "Volontari San Giorgio") è troppo poco per fondere.
        $containment = $inter / min(count($x), count($y));
        $bonus = $containment >= 1.0 ? (min(count($x), count($y)) >= 2 ? 0.75 : 0.5) : 0.0;
        return round(max($jaccard, $bonus), 3);
    }

    /**
     * Raggruppa i record (stessa frequenza) e restituisce i cluster da fondere
     * e le coppie probabili da far verificare.
     * @param array<int, array> $records indice → record mappato (con chiave 'logical')
     * @return array{clusters: array<int, int[]>, probable: array<int, array{0:int,1:int,2:float,3:string}>}
     */
    public function cluster(array $records): array
    {
        $profiles = [];
        foreach ($records as $i => $r) {
            $profiles[$i] = $this->profile($r['logical']);
        }
        return $this->clusterProfiles($profiles);
    }

    /**
     * Come cluster(), ma a partire da profili già calcolati: serve a non tenere in memoria i record completi
     * (l'importazione di file grandi su hosting con poca memoria).
     * @param array<int, array> $profiles indice → profile()
     */
    public function clusterProfiles(array $profiles): array
    {
        $byHz = [];
        foreach ($profiles as $i => $p) {
            $byHz[$p['hz']][] = $i;
        }
        $parent = array_combine(array_keys($profiles), array_keys($profiles));
        $find = function (int $i) use (&$parent, &$find): int {
            return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
        };
        $probable = [];
        foreach ($byHz as $group) {
            $n = count($group);
            for ($x = 0; $x < $n; $x++) {
                for ($y = $x + 1; $y < $n; $y++) {
                    [$verdict, $sim, $why] = $this->compare($profiles[$group[$x]], $profiles[$group[$y]]);
                    if ($verdict === self::CERTAIN) {
                        $parent[$find($group[$y])] = $find($group[$x]);
                    } elseif ($verdict === self::PROBABLE) {
                        $probable[] = [$group[$x], $group[$y], $sim, $why];
                    }
                }
            }
        }
        $clusters = [];
        foreach (array_keys($profiles) as $i) {
            $clusters[$find($i)][] = $i;
        }
        // Le coppie probabili già finite nello stesso cluster non vanno segnalate
        $probable = array_values(array_filter($probable, fn($p) => $find($p[0]) !== $find($p[1])));
        return ['clusters' => array_values($clusters), 'probable' => $probable];
    }

    /**
     * Fonde i record di un cluster in un unico record logico.
     * Primario: ascolto più recente, poi stato confermato, poi più campi compilati.
     * I campi vuoti del primario si completano con quelli degli altri; le province si uniscono.
     * @return array{logical: array, primary: int, conflicts: string[]}
     */
    public function merge(array $records, array $members): array
    {
        $statusRank = ['attivo' => 3, 'sperimentale' => 2, 'da_verificare' => 1, 'inattivo' => 0];
        usort($members, static function ($a, $b) use ($records, $statusRank) {
            $la = $records[$a]['logical'];
            $lb = $records[$b]['logical'];
            return [(string) ($lb['last_heard_at'] ?? ''), $statusRank[$lb['status']] ?? 0, count(array_filter($lb, static fn($v) => $v !== null))]
                <=> [(string) ($la['last_heard_at'] ?? ''), $statusRank[$la['status']] ?? 0, count(array_filter($la, static fn($v) => $v !== null))];
        });
        $primary = $members[0];
        $out = $records[$primary]['logical'];
        $conflicts = [];
        $coverage = array_filter(explode(',', (string) ($out['coverage'] ?? '')));
        $notes = array_filter(explode("\n", (string) ($out['notes'] ?? '')));
        $statuses = [];
        foreach ($members as $m) {
            $l = $records[$m]['logical'];
            $statuses[$l['status']] = (string) ($l['last_heard_at'] ?? '');
            if ($m === $primary) {
                continue;
            }
            foreach ($l as $field => $value) {
                if ($value === null || in_array($field, ['coverage', 'notes', 'status', 'last_heard_at'], true)) {
                    continue;
                }
                if (($out[$field] ?? null) === null) {
                    $out[$field] = $value;
                } elseif ((string) $out[$field] !== (string) $value && in_array($field, ['ctcss_hz', 'dcs_code', 'mode', 'color_code', 'locator'], true)) {
                    $conflicts[] = $field;
                }
            }
            $coverage = array_merge($coverage, array_filter(explode(',', (string) ($l['coverage'] ?? ''))));
            $notes = array_merge($notes, array_filter(explode("\n", (string) ($l['notes'] ?? ''))));
            if (($l['last_heard_at'] ?? null) && ($l['last_heard_at'] > ($out['last_heard_at'] ?? ''))) {
                $out['last_heard_at'] = $l['last_heard_at'];
            }
        }
        // Stati discordanti senza date che li ordinino: da verificare
        if (count($statuses) > 1 && isset($statuses['inattivo']) && isset($statuses['attivo'])
            && $statuses['inattivo'] === '' && $statuses['attivo'] === '') {
            $out['status'] = 'da_verificare';
            $notes[] = 'Stato discordante tra i record unificati della fonte';
        }
        $coverage = array_values(array_unique($coverage));
        sort($coverage);
        $out['coverage'] = $coverage ? implode(',', $coverage) : null;
        if ($out['province'] === null && count($coverage) === 1) {
            $out['province'] = $coverage[0];
        }
        if ($conflicts) {
            $notes[] = 'Valori discordanti tra i record unificati: ' . implode(', ', array_unique($conflicts));
        }
        $notes = array_values(array_unique(array_map('trim', $notes)));
        $out['notes'] = $notes ? mb_substr(implode("\n", $notes), 0, 2000) : null;
        return ['logical' => $out, 'primary' => $primary, 'conflicts' => array_values(array_unique($conflicts))];
    }
}
