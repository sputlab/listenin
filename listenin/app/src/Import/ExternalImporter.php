<?php
declare(strict_types=1);

namespace ListenIn\Import;

/**
 * Importazione da riga di comando: stessa logica del pannello web (ImportEngine),
 * eseguita a passi fino al completamento.
 */
final class ExternalImporter
{
    public function __construct(
        private readonly string $source = 'esterna',
        private readonly bool $dryRun = false,
        private readonly bool $updateExisting = true,
        private readonly ?int $limit = null,
        private readonly array $columnMap = [],
        private $log = null
    ) {
        $this->log ??= static fn(string $m) => null;
    }

    public function run(string $path, ?string $format = null): array
    {
        $st = ImportEngine::start([
            'path' => $path, 'format' => $format, 'source' => $this->source, 'dry_run' => $this->dryRun,
            'update' => $this->updateExisting, 'limit' => $this->limit, 'columns' => $this->columnMap,
        ]);
        $last = '';
        do {
            $st = ImportEngine::step($st['id'], 25.0);
            if ($st['message'] !== $last) {
                ($this->log)($st['message']);
                $last = $st['message'];
            }
        } while ($st['status'] === 'running');
        if ($st['status'] !== 'done') {
            throw new \RuntimeException($st['message']);
        }
        $csv = ImportEngine::duplicatesCsvPath($st['id']);
        $copy = null;
        if ($csv) {
            $copy = ImportEngine::baseDir() . '/duplicati-' . date('Ymd-His') . ($this->dryRun ? '-simulazione' : '') . '.csv';
            copy($csv, $copy);
        }
        return $st['report'] + ['run_id' => $st['run_id'], 'duplicates_csv' => $copy];
    }
}
