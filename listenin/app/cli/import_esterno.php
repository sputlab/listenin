<?php
/**
 * Importazione e normalizzazione dati da un export di una fonte esterna.
 *
 * Uso:
 *   php cli/import_esterno.php --file=export.csv [--format=csv|json|html] [--dry-run]
 *        [--limit=N] [--no-update] [--source=esterna] [--map=campo:"Colonna"] [--map=...]
 *
 * Campi mappabili con --map: id, freq, mode, service, genere, region, area, status,
 * heard, notes, tone, locator, callsign, city.
 *
 * Consigliato: prima una simulazione (--dry-run), poi verificare il CSV dei duplicati in app/storage/import/.
 * Usare file esportati da fonti esterne con il consenso dei gestori del sito.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use ListenIn\Import\ExternalImporter;

$opts = getopt('', ['file:', 'format:', 'dry-run', 'limit:', 'no-update', 'source:', 'map:', 'help']);
if (isset($opts['help']) || empty($opts['file'])) {
    fwrite(STDOUT, (string) preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', (string) file_get_contents(__FILE__))[1])[0]));
    exit(isset($opts['help']) ? 0 : 1);
}

$map = [];
foreach ((array) ($opts['map'] ?? []) as $m) {
    [$field, $column] = array_pad(explode(':', (string) $m, 2), 2, '');
    if ($field !== '' && $column !== '') {
        $map[trim($field)] = trim($column, " \"'");
    }
}

$importer = new ExternalImporter(
    source: (string) ($opts['source'] ?? 'esterna'),
    dryRun: isset($opts['dry-run']),
    updateExisting: !isset($opts['no-update']),
    limit: isset($opts['limit']) ? max(1, (int) $opts['limit']) : null,
    columnMap: $map,
    log: static fn(string $m) => fwrite(STDOUT, $m . PHP_EOL)
);

$t0 = microtime(true);
try {
    $report = $importer->run((string) $opts['file'], $opts['format'] ?? null);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Errore: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$s = $report['stats'];
printf("\n%s completato in %.1f s (run #%d)\n\n", isset($opts['dry-run']) ? 'SIMULAZIONE' : 'Importazione', microtime(true) - $t0, $report['run_id']);
$labels = [
    'righe_lette' => 'Righe lette', 'righe_scartate' => 'Righe scartate', 'righe_unificate' => 'Righe unificate (duplicati certi)',
    'record_risultanti' => 'Record armonizzati', 'inseriti' => 'Nuovi record', 'aggiornati' => 'Record aggiornati',
    'invariati' => 'Record invariati', 'completati' => 'Record completati nei campi vuoti',
    'protetti' => 'Record curati dalla comunità (non sovrascritti)', 'collegati_a_esistenti' => 'Collegati a record già presenti',
    'rimossi_dai_moderatori' => 'Già rimossi dai moderatori (ignorati)', 'possibili_duplicati' => 'Possibili duplicati segnalati',
    'errori' => 'Errori di validazione',
];
foreach ($labels as $k => $label) {
    printf("  %-48s %6d\n", $label, $s[$k] ?? 0);
}
if ($report['per_servizio']) {
    echo "\nNuovi record per servizio:\n";
    foreach (array_slice($report['per_servizio'], 0, 30, true) as $slug => $n) {
        printf("  %-32s %6d\n", $slug, $n);
    }
}
if ($report['da_classificare']) {
    echo "\nDescrizioni classificate solo per banda di frequenza (aggiungere regole in app/src/Import/mappings.php):\n";
    foreach (array_slice($report['da_classificare'], 0, 20, true) as $label => $n) {
        printf("  %4d  %s\n", $n, $label);
    }
}
if ($report['errori']) {
    echo "\nPrimi errori:\n  " . implode("\n  ", array_slice($report['errori'], 0, 10)) . "\n";
}
if ($report['duplicates_csv']) {
    echo "\nElenco unificazioni e duplicati da verificare: {$report['duplicates_csv']}\n";
}
$out = LISTENIN_ROOT . '/storage/import/report-' . date('Ymd-His') . '.json';
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nReport completo: $out\n";
