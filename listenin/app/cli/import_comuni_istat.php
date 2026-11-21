<?php
/**
 * (Opzionale) Carica l'elenco ufficiale dei comuni ISTAT per migliorare il riconoscimento dei luoghi.
 * Scaricare "Elenco comuni italiani" in CSV dal sito ISTAT, poi:
 *   php cli/import_comuni_istat.php --file=Elenco-comuni-italiani.csv
 * Colonne usate: "Codice Comune formato alfanumerico", "Denominazione in italiano", "Sigla automobilistica".
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use ListenIn\Core\Database;
use ListenIn\Domain\Normalizer;
use ListenIn\Domain\Taxonomy;
use ListenIn\Import\SourceReader;

$o = getopt('', ['file:']);
if (empty($o['file'])) {
    fwrite(STDERR, "Uso: php cli/import_comuni_istat.php --file=elenco.csv\n");
    exit(1);
}
$n = 0;
Database::transaction(function () use ($o, &$n) {
    foreach (SourceReader::read((string) $o['file'], 'csv') as $row) {
        $cols = [];
        foreach ($row as $h => $v) {
            $cols[Normalizer::key((string) $h)] = $v;
        }
        $istat = $cols['codice comune formato alfanumerico'] ?? null;
        $name = $cols['denominazione in italiano'] ?? $cols['denominazione italiana e straniera'] ?? null;
        $sigla = $cols['sigla automobilistica'] ?? null;
        $prov = $sigla ? Taxonomy::province($sigla) : null;
        if (!$istat || !$name || !$prov) {
            continue;
        }
        Database::run(
            'INSERT INTO listenin_municipalities (province_id, istat_code, name, name_key) VALUES (:p, :i, :n, :k)
             ON DUPLICATE KEY UPDATE istat_code = VALUES(istat_code), name = VALUES(name)',
            ['p' => (int) $prov['id'], 'i' => $istat, 'n' => $name, 'k' => Normalizer::key($name)]
        );
        $n++;
    }
});
echo "Comuni caricati o aggiornati: $n\n";
