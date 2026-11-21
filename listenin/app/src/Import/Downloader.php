<?php
declare(strict_types=1);

namespace ListenIn\Import;

use ListenIn\Core\ApiException;
use ListenIn\Core\Config;

/**
 * Scarica un file da un indirizzo web (solo da siti autorizzati in configurazione) nella cartella dei file
 * da importare. Pensato per i dati pubblici di ISTAT: l'amministratore non deve scaricare e ricaricare nulla.
 *
 * Sicurezza (richiesta avviata dall'amministratore, quindi da trattare come potenziale SSRF):
 *  - solo HTTPS e solo host presenti in import.fetch_hosts (predefinito: www.istat.it);
 *  - i reindirizzamenti si seguono a mano e ogni passaggio deve restare su un host autorizzato;
 *  - dimensione massima e durata massima; si salvano solo estensioni ammesse e si controlla il contenuto.
 */
final class Downloader
{
    public const DEFAULT_HOSTS = ['www.istat.it'];

    public static function hosts(): array
    {
        return array_map('strtolower', (array) Config::get('import.fetch_hosts', self::DEFAULT_HOSTS));
    }

    private static function check(string $url): array
    {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower((string) ($p['host'] ?? ''));
        $insecure = (bool) Config::get('import.fetch_allow_insecure', false);   // solo per prove in locale
        if (!$host || !in_array($host, self::hosts(), true)) {
            throw ApiException::validation(['url' => 'Indirizzo non consentito. Si possono scaricare file solo da: ' . implode(', ', self::hosts()) . '.']);
        }
        if ($scheme !== 'https' && !($insecure && $scheme === 'http')) {
            throw ApiException::validation(['url' => 'Sono ammessi solo indirizzi HTTPS.']);
        }
        if (isset($p['user']) || isset($p['pass']) || (isset($p['port']) && !$insecure && (int) $p['port'] !== 443)) {
            throw ApiException::validation(['url' => 'Indirizzo non valido.']);
        }
        return $p;
    }

    /** @return array{name:string,size:int} */
    public static function fetch(string $url, string $destDir, ?int $maxBytes = null): array
    {
        $maxBytes ??= (int) Config::get('import.fetch_max_mb', 30) << 20;
        $tmp = tempnam($destDir, 'dl');
        try {
            $final = $url;
            for ($hop = 0; $hop <= 4; $hop++) {
                self::check($final);
                [$status, $location] = self::request($final, $tmp, $maxBytes);
                if (in_array($status, [301, 302, 303, 307, 308], true) && $location) {
                    $final = self::resolve($final, $location);
                    continue;
                }
                if ($status !== 200) {
                    throw new ApiException(502, 'fetch_failed', "Il sito ha risposto con errore $status. Scarica il file dal browser e caricalo qui.");
                }
                break;
            }
            if ($hop > 4) {
                throw new ApiException(502, 'fetch_failed', 'Troppi reindirizzamenti.');
            }
            $size = (int) filesize($tmp);
            $ext = strtolower(pathinfo((string) parse_url($final, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (!in_array($ext, ImportEngine::EXTENSIONS, true)) {
                throw ApiException::validation(['url' => 'Il file non ha un formato supportato (CSV, XLSX, JSON o HTML).']);
            }
            $head = (string) file_get_contents($tmp, false, null, 0, 4096);
            if ($size < 20 || ($ext === 'xlsx' ? !str_starts_with($head, "PK\x03\x04") : str_contains($head, "\0"))
                || stripos($head, '<!doctype html') === 0 && $ext !== 'html' && $ext !== 'htm') {
                throw new ApiException(502, 'fetch_invalid', 'Il sito non ha restituito il file atteso (forse una pagina di errore). Scaricalo dal browser e caricalo qui.');
            }
            $base = preg_replace('/[^\w.\- ]+/u', '_', pathinfo((string) parse_url($final, PHP_URL_PATH), PATHINFO_FILENAME)) ?: 'download';
            $name = mb_substr($base, 0, 80) . '.' . $ext;
            if (is_file($destDir . '/' . $name)) {
                $name = mb_substr($base, 0, 70) . '-' . date('Ymd-His') . '.' . $ext;
            }
            rename($tmp, $destDir . '/' . $name);
            return ['name' => $name, 'size' => $size];
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        return $origin . rtrim(dirname((string) ($p['path'] ?? '/')), '/') . '/' . $location;
    }

    /** @return array{0:int,1:?string} [codice HTTP, Location] */
    private static function request(string $url, string $dest, int $maxBytes): array
    {
        $ua = 'ListenIn/' . LISTENIN_VERSION . ' (+' . Config::get('app.base_url', '') . ')';
        $out = fopen($dest, 'wb');
        $status = 0;
        $location = null;
        $tooBig = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90,
                CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['Accept: */*'],
                CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$location): int {
                    if (stripos($line, 'location:') === 0) {
                        $location = trim(substr($line, 9));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use ($out, $maxBytes, &$tooBig): int {
                    if (ftell($out) + strlen($chunk) > $maxBytes) {
                        $tooBig = true;
                        return 0;
                    }
                    return (int) fwrite($out, $chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($out);
            if ($tooBig) {
                throw new ApiException(413, 'fetch_too_big', 'Il file supera la dimensione massima consentita.');
            }
            if ($ok === false && $status === 0) {
                throw new ApiException(502, 'fetch_failed', 'Download non riuscito (' . $err . '). Se l\'hosting blocca le connessioni in uscita, scarica il file dal browser e caricalo qui.');
            }
            return [$status, $location];
        }
        // senza cURL: flussi PHP (richiede allow_url_fopen)
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 90, 'follow_location' => 0, 'ignore_errors' => true,
            'header' => "User-Agent: $ua\r\nAccept: */*\r\n"]]);
        $in = @fopen($url, 'rb', false, $ctx);
        if (!$in) {
            fclose($out);
            throw new ApiException(502, 'fetch_failed', 'Download non riuscito: l\'hosting non consente le connessioni in uscita. Scarica il file dal browser e caricalo qui.');
        }
        foreach ((array) ($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }
        while (!feof($in)) {
            $chunk = (string) fread($in, 65536);
            if (ftell($out) + strlen($chunk) > $maxBytes) {
                fclose($in);
                fclose($out);
                throw new ApiException(413, 'fetch_too_big', 'Il file supera la dimensione massima consentita.');
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
        return [$status, $location];
    }
}
