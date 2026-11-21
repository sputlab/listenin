<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\Config;

/** Archivio open data: copie complete e periodiche del database armonizzato, in CSV e JSON. */
final class SnapshotService
{
    public static function dir(): string
    {
        $d = LISTENIN_ROOT . '/storage/opendata';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function license(): array
    {
        return ['name' => (string) Config::get('opendata.license', 'Open Database License (ODbL) 1.0'),
            'url' => (string) Config::get('opendata.license_url', 'https://opendatacommons.org/licenses/odbl/1-0/')];
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/listenin-*.{csv,json}', GLOB_BRACE) ?: [] as $f) {
            if (preg_match('/^listenin-(\d{8})\.(csv|json)$/', basename($f), $m)) {
                $out[] = ['name' => basename($f), 'date' => substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2),
                    'format' => strtoupper($m[2]), 'size' => filesize($f)];
            }
        }
        usort($out, static fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    public static function path(string $name): ?string
    {
        if (!preg_match('/^listenin-\d{8}\.(csv|json)$/', $name)) {
            return null;
        }
        $f = self::dir() . '/' . $name;
        return is_file($f) ? $f : null;
    }

    /** @return array{count:int,date:string} */
    public static function generate(): array
    {
        @set_time_limit(300);
        $stamp = date('Ymd');
        $filters = FrequencyRepository::parseFilters([]);
        $csv = fopen(self::dir() . "/listenin-$stamp.csv.tmp", 'wb');
        fwrite($csv, "\xEF\xBB\xBF");
        $json = fopen(self::dir() . "/listenin-$stamp.json.tmp", 'wb');
        $lic = self::license();
        fwrite($json, '{"meta":' . json_encode(['generator' => Config::get('app.name') . ' ' . LISTENIN_VERSION, 'generated_at' => date('c'),
            'license' => $lic['name'], 'license_url' => $lic['url'], 'units' => ['freq_hz' => 'Hz', 'offset_hz' => 'Hz', 'bandwidth_hz' => 'Hz']],
            \ListenIn\Core\Response::JSON_FLAGS) . ',"data":[');
        $n = 0;
        $header = false;
        foreach (FrequencyRepository::exportRows($filters, 10_000_000) as $row) {
            if (!$header) {
                fputcsv($csv, array_keys($row), ',', '"', '');
                $header = true;
            }
            foreach ($row as $k => $v) {
                if (is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && !in_array($k, ['freq_hz', 'offset_hz', 'bandwidth_hz', 'latitude', 'longitude'], true)) {
                    $row[$k] = "'" . $v;
                }
            }
            fputcsv($csv, $row, ',', '"', '');
            fwrite($json, ($n ? ',' : '') . "\n" . json_encode($row, \ListenIn\Core\Response::JSON_FLAGS));
            $n++;
        }
        fwrite($json, "\n]}");
        fclose($csv);
        fclose($json);
        foreach (['csv', 'json'] as $ext) {
            rename(self::dir() . "/listenin-$stamp.$ext.tmp", self::dir() . "/listenin-$stamp.$ext");
        }
        // conserva le ultime 6 copie
        $dates = array_values(array_unique(array_map(static fn($x) => substr($x['name'], 10, 8), self::list())));
        foreach (array_slice($dates, 6) as $d) {
            @unlink(self::dir() . "/listenin-$d.csv");
            @unlink(self::dir() . "/listenin-$d.json");
        }
        return ['count' => $n, 'date' => date('Y-m-d')];
    }

    /** Genera una nuova copia se l'ultima è più vecchia di opendata.auto_days (predefinito 30). */
    public static function maybeGenerate(): bool
    {
        $days = (int) Config::get('opendata.auto_days', 30);
        if ($days <= 0) {
            return false;
        }
        $last = self::list()[0]['date'] ?? null;
        if ($last !== null && strtotime($last) > time() - $days * 86400) {
            return false;
        }
        self::generate();
        return true;
    }
}
