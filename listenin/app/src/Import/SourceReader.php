<?php
declare(strict_types=1);

namespace ListenIn\Import;

/**
 * Lettori di file sorgente: CSV (delimitatore e codifica rilevati), JSON, tabelle HTML salvate.
 * Restituiscono righe associative con le intestazioni originali.
 */
final class SourceReader
{
    public static function read(string $path, ?string $format = null): \Generator
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("File non leggibile: $path");
        }
        $format ??= strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($format) {
            'csv', 'txt', 'tsv' => self::csv($path),
            'xlsx' => self::xlsx($path),
            'json' => self::json($path),
            'html', 'htm' => self::html($path),
            default => throw new \RuntimeException("Formato non supportato: $format (usa csv, xlsx, json o html)"),
        };
    }

    private static function csv(string $path): \Generator
    {
        $fh = fopen($path, 'rb');
        $first = (string) fgets($fh);
        rewind($fh);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delims = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"), '|' => substr_count($first, '|')];
        arsort($delims);
        $delim = (string) array_key_first($delims);

        $headers = null;
        while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $cells = array_map([self::class, 'toUtf8'], $cells);
            if ($headers === null) {
                $cells[0] = preg_replace('/^\x{FEFF}/u', '', $cells[0]);
                $headers = array_map('trim', $cells);
                continue;
            }
            if (count(array_filter($cells, static fn($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            yield self::combine($headers, $cells);
        }
        fclose($fh);
    }

    /**
     * Primo foglio di un file Excel (.xlsx). Intestazioni = prima riga con almeno tre celle compilate
     * (alcuni file hanno un titolo sopra la tabella). Lettura in PHP puro: non serve l'estensione "zip".
     */
    private static function xlsx(string $path): \Generator
    {
        $zip = new ZipFile($path);
        if (!$zip->has('xl/workbook.xml')) {
            throw new \RuntimeException('Il file non è un documento Excel (.xlsx) valido.');
        }
        // foglio da leggere: il primo in ordine di cartella di lavoro
        $sheetPath = null;
        $wb = (string) $zip->read('xl/workbook.xml');
        $rels = (string) $zip->read('xl/_rels/workbook.xml.rels');
        if (preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $wb, $m)) {
            $rid = preg_quote($m[1], '/');
            if (preg_match('/<Relationship\b[^>]*\bId="' . $rid . '"[^>]*\bTarget="([^"]+)"/', $rels, $r)
                || preg_match('/<Relationship\b[^>]*\bTarget="([^"]+)"[^>]*\bId="' . $rid . '"/', $rels, $r)) {
                $t = $r[1];
                $sheetPath = ltrim(str_starts_with($t, '/') ? $t : 'xl/' . $t, '/');
            }
        }
        if ($sheetPath === null || !$zip->has($sheetPath)) {
            $sheetPath = null;
            foreach ($zip->names() as $n) {
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) {
                    $sheetPath = $n;
                    break;
                }
            }
        }
        if ($sheetPath === null) {
            throw new \RuntimeException('Nel file Excel non è stato trovato nessun foglio.');
        }

        // stringhe condivise
        $strings = [];
        $ss = $zip->read('xl/sharedStrings.xml');
        if ($ss !== null && $ss !== '') {
            $x = new \XMLReader();
            $x->XML($ss, null, LIBXML_NONET | LIBXML_COMPACT);
            $cur = null;
            while ($x->read()) {
                if ($x->nodeType === \XMLReader::ELEMENT) {
                    if ($x->localName === 'si') {
                        $cur = '';
                    } elseif ($x->localName === 'rPh') {
                        $x->next();           // lettura fonetica: non fa parte del testo
                        continue;
                    } elseif ($x->localName === 't' && $cur !== null) {
                        $cur .= $x->readString();
                    }
                } elseif ($x->nodeType === \XMLReader::END_ELEMENT && $x->localName === 'si') {
                    $strings[] = $cur ?? '';
                    $cur = null;
                }
            }
            $x->close();
            unset($ss);
        }

        $xml = (string) $zip->read($sheetPath);
        $x = new \XMLReader();
        $x->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        unset($xml);
        $headers = null;
        while ($x->read()) {
            if ($x->nodeType !== \XMLReader::ELEMENT || $x->localName !== 'row' || $x->isEmptyElement) {
                continue;
            }
            $depth = $x->depth;
            $cells = [];
            while ($x->read() && !($x->nodeType === \XMLReader::END_ELEMENT && $x->localName === 'row' && $x->depth === $depth)) {
                if ($x->nodeType !== \XMLReader::ELEMENT || $x->localName !== 'c') {
                    continue;
                }
                $ref = (string) $x->getAttribute('r');
                $type = (string) $x->getAttribute('t');
                $cdepth = $x->depth;
                $v = null;
                $inline = '';
                if (!$x->isEmptyElement) {
                    while ($x->read() && !($x->nodeType === \XMLReader::END_ELEMENT && $x->localName === 'c' && $x->depth === $cdepth)) {
                        if ($x->nodeType === \XMLReader::ELEMENT) {
                            if ($x->localName === 'v') {
                                $v = $x->readString();
                            } elseif ($x->localName === 't') {
                                $inline .= $x->readString();
                            }
                        }
                    }
                }
                $val = match ($type) {
                    's' => $strings[(int) $v] ?? '',
                    'inlineStr' => $inline,
                    default => (string) ($v ?? ''),
                };
                $cells[self::columnIndex($ref)] = trim($val);
            }
            if (!$cells) {
                continue;
            }
            $max = max(array_keys($cells));
            $row = [];
            for ($i = 0; $i <= $max; $i++) {
                $row[] = $cells[$i] ?? '';
            }
            if (count(array_filter($row, static fn($c) => $c !== '')) === 0) {
                continue;
            }
            if ($headers === null) {
                if (count(array_filter($row, static fn($c) => $c !== '')) >= 3) {
                    $headers = array_map('trim', $row);
                }
                continue;
            }
            yield self::combine($headers, $row);
        }
        $x->close();
    }

    /** "AB12" → 27 (indice di colonna a partire da 0). */
    private static function columnIndex(string $ref): int
    {
        $n = 0;
        foreach (str_split(strtoupper((string) preg_replace('/[^A-Za-z]/', '', $ref))) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    private static function json(string $path): \Generator
    {
        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $rows = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
        if (!is_array($rows)) {
            throw new \RuntimeException('JSON: atteso un array di oggetti o {"data": [...]}');
        }
        foreach ($rows as $row) {
            if (is_array($row)) {
                yield array_map(static fn($v) => is_scalar($v) ? (string) $v : null, $row);
            }
        }
    }

    /** Prima tabella con intestazioni <th> trovata nel documento. */
    private static function html(string $path): \Generator
    {
        $html = (string) file_get_contents($path);
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        foreach ($dom->getElementsByTagName('table') as $table) {
            $headers = [];
            foreach ($table->getElementsByTagName('th') as $th) {
                $headers[] = trim($th->textContent);
            }
            if (!$headers) {
                continue;
            }
            foreach ($table->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->getElementsByTagName('td') as $td) {
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent) ?? '');
                }
                if ($cells) {
                    yield self::combine($headers, $cells);
                }
            }
            return;
        }
    }

    private static function combine(array $headers, array $cells): array
    {
        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h !== '' ? $h : 'col' . $i] = isset($cells[$i]) ? trim((string) $cells[$i]) : null;
        }
        return $row;
    }

    private static function toUtf8(?string $s): string
    {
        $s = (string) $s;
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
