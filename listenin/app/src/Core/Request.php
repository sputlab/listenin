<?php
declare(strict_types=1);

namespace ListenIn\Core;

final class Request
{
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
    ) {}

    /**
     * Il percorso della rotta arriva da ?route=/... (funziona ovunque, anche senza mod_rewrite)
     * oppure dalla parte di URL successiva alla cartella dello script (es. /webapp/listenin/api/...).
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Alcuni hosting condivisi filtrano PUT/PATCH/DELETE: supporto all'override via header su POST.
        if ($method === 'POST') {
            $override = strtoupper((string) ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        if (isset($_GET['route']) && is_string($_GET['route'])) {
            $path = '/' . trim($_GET['route'], '/');
        } else {
            $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            $rest = str_starts_with($uri, $base) ? substr($uri, strlen($base)) : $uri;
            $rest = preg_replace('#^/index\.php#', '', $rest) ?? $rest;
            $path = '/' . trim($rest, '/');
        }
        if (!preg_match('#^/[a-z0-9/_\-]*$#', $path)) {
            $path = '/__invalid__';
        }
        $query = $_GET;
        unset($query['route']);
        return new self($method, $path, $query);
    }

    public function isMutating(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /** Corpo JSON della richiesta (max 64 KB). */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if ($this->isMutating() && !str_starts_with(strtolower($type), 'application/json')) {
            throw new ApiException(415, 'unsupported_media_type', 'Il corpo della richiesta deve essere JSON.');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 65537);
        if (strlen($raw) > 65536) {
            throw new ApiException(413, 'payload_too_large', 'Richiesta troppo grande.');
        }
        if ($raw === '') {
            return $this->json = [];
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'invalid_json', 'JSON non valido.');
        }
        if (!is_array($data)) {
            throw new ApiException(400, 'invalid_json', 'Era atteso un oggetto JSON.');
        }
        return $this->json = $data;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    public function queryString(string $key, int $maxLen = 200): ?string
    {
        $v = $this->query[$key] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, $maxLen);
    }

    public function queryInt(string $key, int $default, int $min, int $max): int
    {
        $v = $this->query[$key] ?? null;
        if (!is_string($v) || !preg_match('/^\d{1,9}$/', $v)) {
            return $default;
        }
        return max($min, min($max, (int) $v));
    }
}
