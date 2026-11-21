<?php
declare(strict_types=1);

namespace ListenIn\Import;

/**
 * Lettore ZIP minimale in PHP puro (solo lettura, voci "stored" o "deflate"): serve a leggere i file Excel (.xlsx)
 * anche sui server senza l'estensione PHP "zip". Richiede soltanto zlib (gzinflate), presente quasi ovunque.
 */
final class ZipFile
{
    /** @var array<string, array{method:int, csize:int, size:int, offset:int}> */
    private array $entries = [];
    /** @var resource */
    private $fh;

    public function __construct(string $path)
    {
        if (!function_exists('gzinflate')) {
            throw new \RuntimeException('Manca l\'estensione PHP "zlib": apri il file in Excel, salvalo come CSV e caricalo di nuovo.');
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            throw new \RuntimeException('File non leggibile.');
        }
        $this->fh = $fh;
        $this->readDirectory();
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    private function readDirectory(): void
    {
        $size = (int) fstat($this->fh)['size'];
        $tail = min($size, 65557);
        fseek($this->fh, $size - $tail);
        $buf = (string) fread($this->fh, $tail);
        $pos = strrpos($buf, "PK\x05\x06");
        if ($pos === false || $size < 22) {
            throw new \RuntimeException('Il file non è un documento Excel valido (archivio ZIP non riconosciuto).');
        }
        $eocd = unpack('vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset', substr($buf, $pos + 4, 16));
        fseek($this->fh, $eocd['cdOffset']);
        $cd = (string) fread($this->fh, $eocd['cdSize']);
        $p = 0;
        for ($i = 0; $i < $eocd['entries'] && $p + 46 <= strlen($cd); $i++) {
            if (substr($cd, $p, 4) !== "PK\x01\x02") {
                break;
            }
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/Voffset', substr($cd, $p + 4, 42));
            $name = substr($cd, $p + 46, $h['nameLen']);
            $this->entries[$name] = ['method' => $h['method'], 'csize' => $h['csize'], 'size' => $h['size'], 'offset' => $h['offset']];
            $p += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
        }
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** @return string[] nomi delle voci */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function read(string $name): ?string
    {
        $e = $this->entries[$name] ?? null;
        if ($e === null) {
            return null;
        }
        fseek($this->fh, $e['offset']);
        $lh = (string) fread($this->fh, 30);
        if (strlen($lh) < 30 || substr($lh, 0, 4) !== "PK\x03\x04") {
            throw new \RuntimeException('Archivio ZIP danneggiato.');
        }
        $l = unpack('vnameLen/vextraLen', substr($lh, 26, 4));
        fseek($this->fh, $e['offset'] + 30 + $l['nameLen'] + $l['extraLen']);
        $data = $e['csize'] > 0 ? (string) fread($this->fh, $e['csize']) : '';
        return match ($e['method']) {
            0 => $data,
            8 => (string) gzinflate($data),
            default => throw new \RuntimeException('Compressione ZIP non supportata nel file Excel.'),
        };
    }
}
