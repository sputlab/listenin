<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\ApiException;
use ListenIn\Core\Config;
use ListenIn\Core\Database;
use ListenIn\Core\RateLimiter;
use ListenIn\Core\Security;
use ListenIn\Core\Validator;

/**
 * Webhook: avvisi automatici (POST JSON firmato) quando una frequenza viene aggiunta o recuperata in una regione,
 * provincia o banda scelta.
 *
 * Sicurezza (l'indirizzo lo sceglie l'utente, quindi è una potenziale richiesta verso reti interne, "SSRF"):
 *  - solo HTTPS sulla porta 443 (configurabile) e solo nomi di dominio, mai indirizzi IP o credenziali nell'URL;
 *  - il nome deve risolvere esclusivamente in indirizzi pubblici (niente reti private, loopback, link-local, ecc.);
 *  - alla consegna si risolve di nuovo e si "fissa" l'indirizzo con CURLOPT_RESOLVE (nessun DNS rebinding);
 *  - nessun reindirizzamento, timeout brevi, risposta scartata: all'utente si restituisce solo il codice HTTP.
 * Firma: intestazione X-ListenIn-Signature "t=<timestamp>,v1=<HMAC-SHA256 di "<timestamp>.<corpo>">".
 * Le consegne si elaborano a fine richiesta (e a ogni minuto circa) senza cron; 5 tentativi con attesa crescente,
 * disattivazione dopo 10 errori consecutivi.
 */
final class WebhookService
{
    public const EVENTS = ['created' => 'Nuova frequenza', 'recovered' => 'Frequenza recuperata'];
    private const BACKOFF = [0, 60, 300, 1800, 7200];     // attesa (s) prima del tentativo successivo
    private static bool $dirty = false;

    public static function available(): bool
    {
        return function_exists('curl_init') && function_exists('openssl_encrypt');
    }

    // ------------------------------------------------------------------ validazione dell'indirizzo
    private static function insecureAllowed(): bool
    {
        return (bool) Config::get('webhooks.allow_private', false);   // solo per prove in locale
    }

    public static function isPublicIp(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
                return self::isPublicIp($m[1]);
            }
        } else {
            $n = ip2long($ip);
            if ($n === false) {
                return false;
            }
            foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($n & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
        }
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && !in_array(strtolower($ip), ['::', '::1'], true) && !preg_match('/^(fe[89ab]|fc|fd|ff)/i', $ip);
    }

    /** @return string[] indirizzi IP su cui risolve il nome */
    private static function resolve(string $host): array
    {
        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }
        if (!$ips) {
            $ips = (array) @gethostbynamel($host);
        }
        return array_values(array_filter($ips));
    }

    /** @return array{url:string,host:string,port:int} */
    public static function validateUrl(string $url): array
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $port = (int) ($p['port'] ?? ($scheme === 'http' ? 80 : 443));
        $fail = static fn(string $m) => throw ApiException::validation(['url' => $m]);
        $loose = self::insecureAllowed();
        if (strlen($url) > 500 || !$host || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {
            $fail('Indirizzo non valido: usa un URL https://dominio/percorso senza credenziali.');
        }
        if ($scheme !== 'https' && !($loose && $scheme === 'http')) {
            $fail('Sono ammessi solo indirizzi HTTPS.');
        }
        if (!in_array($port, array_map('intval', (array) Config::get('webhooks.allowed_ports', [443])), true) && !$loose) {
            $fail('Porta non consentita (solo 443).');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) || str_starts_with($host, '[')) {
            if (!$loose) {
                $fail('Indica un nome di dominio, non un indirizzo IP.');
            }
        } else {
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) && !($loose && $host === 'localhost')) {
                $fail('Nome di dominio non valido.');
            }
            if (!$loose) {
                $ips = self::resolve($host);
                if (!$ips) {
                    $fail('Il dominio non è risolvibile.');
                }
                foreach ($ips as $ip) {
                    if (!self::isPublicIp($ip)) {
                        $fail('Il dominio punta a una rete non pubblica: non è consentito.');
                    }
                }
            }
        }
        return ['url' => $url, 'host' => $host, 'port' => $port];
    }

    // ------------------------------------------------------------------ gestione dei webhook
    private static function present(array $r): array
    {
        $host = parse_url($r['url'], PHP_URL_HOST);
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'url' => $r['url'], 'host' => $host, 'events' => array_values(array_filter(explode(',', (string) $r['events']))),
            'region' => $r['region_slug'] ?? null, 'province' => $r['province_code'] ?? null, 'band' => $r['band_code'] ?? null,
            'active' => (bool) $r['active'], 'failures' => (int) $r['failures'], 'last_status' => $r['last_status'] !== null ? (int) $r['last_status'] : null,
            'last_attempt_at' => $r['last_attempt_at'], 'disabled_reason' => $r['disabled_reason'], 'created_at' => $r['created_at'],
            'user' => $r['nickname'] ?? null];
    }

    private const SELECT = 'SELECT w.*, r.slug AS region_slug, p.code AS province_code, ib.code AS band_code, u.nickname
        FROM listenin_webhooks w LEFT JOIN listenin_regions r ON r.id = w.region_id LEFT JOIN listenin_provinces p ON p.id = w.province_id
        LEFT JOIN listenin_itu_bands ib ON ib.id = w.itu_band_id JOIN listenin_users u ON u.id = w.user_id';

    public static function listFor(int $userId): array
    {
        return array_map([self::class, 'present'], Database::all(self::SELECT . ' WHERE w.user_id = :u ORDER BY w.id', ['u' => $userId]));
    }

    public static function listAll(): array
    {
        return array_map([self::class, 'present'], Database::all(self::SELECT . ' ORDER BY w.active DESC, w.failures DESC, w.id DESC LIMIT 200'));
    }

    private static function parse(array $in, ?array $current = null): array
    {
        $v = new Validator($in);
        $name = $v->string('name', 2, 80, $current === null) ?? $current['name'];
        $url = $current !== null && !array_key_exists('url', $in) ? $current['url'] : (string) $v->string('url', 8, 500, true);
        $events = $in['events'] ?? ($current !== null ? array_filter(explode(',', (string) $current['events'])) : array_keys(self::EVENTS));
        $events = array_values(array_intersect(array_map('strval', (array) $events), array_keys(self::EVENTS)));
        if (!$events) {
            $v->addError('events', 'Scegli almeno un evento.');
        }
        $region = $province = $band = null;
        $pick = static function (string $key, callable $find) use (&$v, $in, $current) {
            if (!array_key_exists($key, $in)) {
                return $current[$key . '_id'] ?? null;
            }
            $val = trim((string) ($in[$key] ?? ''));
            if ($val === '') {
                return null;
            }
            $row = $find($val);
            if ($row === null) {
                $v->addError($key, 'Valore sconosciuto.');
                return null;
            }
            return (int) $row['id'];
        };
        $region = $pick('region', static fn($s) => Taxonomy::region($s));
        $province = $pick('province', static fn($s) => Taxonomy::province(strtoupper($s)));
        $band = $pick('band', static fn($s) => Taxonomy::get('ituByCode')[strtoupper($s)] ?? null);
        $v->throwIfErrors();
        if ($current === null || array_key_exists('url', $in)) {
            self::validateUrl($url);
        }
        return [$name, $url, implode(',', $events), $region, $province, $band];
    }

    /** @return array{webhook:array,secret:string} il segreto si mostra una sola volta */
    public static function create(int $userId, array $in): array
    {
        if (!self::available()) {
            throw new ApiException(501, 'webhooks_unavailable', 'I webhook richiedono le estensioni PHP cURL e OpenSSL, non disponibili su questo server.');
        }
        RateLimiter::hit('webhook_write', 'u' . $userId);
        $max = (int) Config::get('webhooks.max_per_user', 5);
        if ((int) Database::value('SELECT COUNT(*) FROM listenin_webhooks WHERE user_id = :u', ['u' => $userId]) >= $max) {
            throw ApiException::validation(['name' => "Hai raggiunto il limite di $max webhook."]);
        }
        [$name, $url, $events, $region, $province, $band] = self::parse($in);
        $secret = bin2hex(random_bytes(24));
        $id = Database::insert('INSERT INTO listenin_webhooks (user_id, name, url, secret_sealed, events, region_id, province_id, itu_band_id) VALUES (:u, :n, :url, :s, :e, :r, :p, :b)',
            ['u' => $userId, 'n' => $name, 'url' => $url, 's' => Security::seal($secret), 'e' => $events, 'r' => $region, 'p' => $province, 'b' => $band]);
        return ['webhook' => self::get($id), 'secret' => $secret];
    }

    public static function get(int $id): array
    {
        return self::present(Database::one(self::SELECT . ' WHERE w.id = :id', ['id' => $id]) ?? throw ApiException::notFound('Webhook non trovato.'));
    }

    private static function owned(int $id, int $userId, bool $admin = false): array
    {
        $row = Database::one('SELECT * FROM listenin_webhooks WHERE id = :id', ['id' => $id]);
        if (!$row || (!$admin && (int) $row['user_id'] !== $userId)) {
            throw ApiException::notFound('Webhook non trovato.');
        }
        return $row;
    }

    public static function update(int $id, int $userId, array $in): array
    {
        $row = self::owned($id, $userId);
        [$name, $url, $events, $region, $province, $band] = self::parse($in, $row);
        $active = array_key_exists('active', $in) ? (bool) $in['active'] : (bool) $row['active'];
        Database::run('UPDATE listenin_webhooks SET name = :n, url = :u, events = :e, region_id = :r, province_id = :p, itu_band_id = :b, active = :a, failures = :f, disabled_reason = :d WHERE id = :id',
            ['n' => $name, 'u' => $url, 'e' => $events, 'r' => $region, 'p' => $province, 'b' => $band, 'a' => $active ? 1 : 0,
                'f' => $active && !$row['active'] ? 0 : $row['failures'], 'd' => $active ? null : ($row['disabled_reason'] ?? 'Disattivato dal proprietario'), 'id' => $id]);
        return self::get($id);
    }

    public static function delete(int $id, int $userId, bool $admin = false): void
    {
        self::owned($id, $userId, $admin);
        Database::run('DELETE FROM listenin_webhooks WHERE id = :id', ['id' => $id]);
    }

    public static function adminDisable(int $id, int $moderatorId, string $reason): array
    {
        self::owned($id, 0, true);
        Database::run('UPDATE listenin_webhooks SET active = 0, disabled_reason = :d WHERE id = :id', ['d' => mb_substr($reason, 0, 120), 'id' => $id]);
        ModerationLog::add($moderatorId, 'webhook.disable', 'webhook', $id, $reason);
        return self::get($id);
    }

    public static function rotateSecret(int $id, int $userId): string
    {
        self::owned($id, $userId);
        $secret = bin2hex(random_bytes(24));
        Database::run('UPDATE listenin_webhooks SET secret_sealed = :s WHERE id = :id', ['s' => Security::seal($secret), 'id' => $id]);
        return $secret;
    }

    public static function deliveries(int $id, int $userId, int $limit = 20): array
    {
        self::owned($id, $userId);
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'event' => $r['event'], 'status' => $r['status'], 'attempts' => (int) $r['attempts'], 'http_status' => $r['http_status'] !== null ? (int) $r['http_status'] : null,
            'error' => $r['last_error'], 'created_at' => $r['created_at'], 'delivered_at' => $r['delivered_at'], 'next_attempt_at' => $r['status'] === 'pending' ? $r['next_attempt_at'] : null],
            Database::all('SELECT * FROM listenin_webhook_deliveries WHERE webhook_id = :w ORDER BY id DESC LIMIT ' . (int) $limit, ['w' => $id]));
    }

    // ------------------------------------------------------------------ eventi e consegne
    public static function payload(string $event, int $frequencyId): ?array
    {
        $row = FrequencyRepository::find($frequencyId);
        if (!$row) {
            return null;
        }
        $f = FrequencyRepository::present($row, false);
        return ['event' => 'frequency.' . $event, 'occurred_at' => date('c'), 'frequency' => [
            'id' => $f['id'], 'freq_mhz' => $f['freq_mhz'], 'mode' => $f['mode'], 'label' => $f['label'], 'service' => $f['service']['name'] ?? null,
            'region' => $f['region'], 'province' => $f['province'], 'municipality' => $f['municipality'], 'status' => $f['status'],
            'reliability' => $f['reliability'], 'last_heard_at' => $f['last_heard_at'],
            'url' => rtrim((string) Config::get('app.base_url'), '/') . '/#/f/' . $f['id']]];
    }

    /** Accoda l'evento per i webhook i cui filtri corrispondono alla frequenza. */
    public static function dispatch(string $event, int $frequencyId): void
    {
        if (!isset(self::EVENTS[$event])) {
            return;
        }
        $f = Database::one('SELECT region_id, province_id, itu_band_id FROM listenin_frequencies WHERE id = :id AND deleted_at IS NULL', ['id' => $frequencyId]);
        if (!$f) {
            return;
        }
        $hooks = Database::all(
            "SELECT w.id FROM listenin_webhooks w WHERE w.active = 1 AND FIND_IN_SET(:ev, w.events)
                AND (w.region_id IS NULL OR w.region_id <=> :r) AND (w.province_id IS NULL OR w.province_id <=> :p) AND (w.itu_band_id IS NULL OR w.itu_band_id <=> :b) LIMIT 200",
            ['ev' => $event, 'r' => $f['region_id'], 'p' => $f['province_id'], 'b' => $f['itu_band_id']]);
        if (!$hooks) {
            return;
        }
        $payload = self::payload($event, $frequencyId);
        if (!$payload) {
            return;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($hooks as $h) {
            Database::run('INSERT INTO listenin_webhook_deliveries (webhook_id, event, frequency_id, payload) VALUES (:w, :e, :f, :p)',
                ['w' => $h['id'], 'e' => 'frequency.' . $event, 'f' => $frequencyId, 'p' => $json]);
        }
        self::$dirty = true;
    }

    /** Prova di consegna: un evento fittizio immediato. @return array{ok:bool,status:?int,error:?string} */
    public static function ping(int $id, int $userId): array
    {
        $row = self::owned($id, $userId);
        RateLimiter::hit('webhook_write', 'u' . $userId);
        $payload = ['event' => 'ping', 'occurred_at' => date('c'), 'message' => 'Webhook di prova da ListenIn.'];
        $did = Database::insert('INSERT INTO listenin_webhook_deliveries (webhook_id, event, payload, attempts, status) VALUES (:w, :e, :p, 1, :s)',
            ['w' => $id, 'e' => 'ping', 'p' => json_encode($payload, JSON_UNESCAPED_UNICODE), 's' => 'pending']);
        [$ok, $status, $err] = self::send($row, json_encode($payload, JSON_UNESCAPED_UNICODE), 'ping', $did);
        Database::run('UPDATE listenin_webhook_deliveries SET status = :s, http_status = :h, last_error = :e, delivered_at = :d WHERE id = :id',
            ['s' => $ok ? 'sent' : 'failed', 'h' => $status, 'e' => $err, 'd' => $ok ? date('Y-m-d H:i:s') : null, 'id' => $did]);
        return ['ok' => $ok, 'status' => $status, 'error' => $err];
    }

    /** @return array{0:bool,1:?int,2:?string} [riuscita, codice HTTP, errore] */
    private static function send(array $hook, string $body, string $event, int $deliveryId): array
    {
        try {
            $target = self::validateUrl($hook['url']);
        } catch (ApiException $e) {
            return [false, null, mb_substr((string) ($e->details['fields']['url'] ?? $e->getMessage()), 0, 160)];
        }
        $secret = Security::unseal((string) $hook['secret_sealed']);
        if ($secret === null) {
            return [false, null, 'Segreto non leggibile: rigenera il segreto.'];
        }
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
        $ch = curl_init($hook['url']);
        $opts = [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'ListenIn-Webhook/' . LISTENIN_VERSION, CURLOPT_PROTOCOLS => self::insecureAllowed() ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'X-ListenIn-Event: ' . $event, 'X-ListenIn-Delivery: ' . $deliveryId,
                'X-ListenIn-Signature: t=' . $ts . ',v1=' . $sig],
            CURLOPT_WRITEFUNCTION => static fn($c, $d) => strlen($d),                                  // la risposta si scarta
        ];
        if (!self::insecureAllowed() && !filter_var($target['host'], FILTER_VALIDATE_IP)) {
            $ips = array_values(array_filter(self::resolve($target['host']), [self::class, 'isPublicIp']));
            if (!$ips) {
                curl_close($ch);
                return [false, null, 'Il dominio non risolve su un indirizzo pubblico.'];
            }
            $opts[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $ips[0]];        // indirizzo fissato: niente rebinding
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false && $status === 0) {
            return [false, null, mb_substr($err ?: 'Connessione non riuscita', 0, 160)];
        }
        return [$status >= 200 && $status < 300, $status, $status >= 200 && $status < 300 ? null : 'Risposta HTTP ' . $status];
    }

    /** Elabora le consegne dovute. @return array{sent:int,failed:int,retry:int} */
    public static function process(int $limit = 25, float $budget = 8.0): array
    {
        $t0 = microtime(true);
        $out = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        $rows = Database::all("SELECT d.*, w.url, w.secret_sealed, w.active FROM listenin_webhook_deliveries d JOIN listenin_webhooks w ON w.id = d.webhook_id
                                WHERE d.status = 'pending' AND d.next_attempt_at <= NOW() ORDER BY d.id LIMIT " . (int) $limit);
        $max = (int) Config::get('webhooks.max_failures', 10);
        foreach ($rows as $d) {
            if (microtime(true) - $t0 > $budget) {
                break;
            }
            if (!$d['active']) {
                Database::run("UPDATE listenin_webhook_deliveries SET status = 'failed', last_error = 'Webhook disattivato' WHERE id = :id", ['id' => $d['id']]);
                $out['failed']++;
                continue;
            }
            [$ok, $status, $err] = self::send($d, $d['payload'], $d['event'], (int) $d['id']);
            $attempts = (int) $d['attempts'] + 1;
            if ($ok) {
                Database::run("UPDATE listenin_webhook_deliveries SET status = 'sent', attempts = :a, http_status = :h, last_error = NULL, delivered_at = NOW() WHERE id = :id", ['a' => $attempts, 'h' => $status, 'id' => $d['id']]);
                Database::run('UPDATE listenin_webhooks SET failures = 0, last_status = :h, last_attempt_at = NOW() WHERE id = :id', ['h' => $status, 'id' => $d['webhook_id']]);
                $out['sent']++;
                continue;
            }
            $final = $attempts >= count(self::BACKOFF);
            Database::run('UPDATE listenin_webhook_deliveries SET status = :s, attempts = :a, http_status = :h, last_error = :e, next_attempt_at = DATE_ADD(NOW(), INTERVAL :w SECOND) WHERE id = :id',
                ['s' => $final ? 'failed' : 'pending', 'a' => $attempts, 'h' => $status, 'e' => $err, 'w' => self::BACKOFF[min($attempts, count(self::BACKOFF) - 1)], 'id' => $d['id']]);
            Database::run('UPDATE listenin_webhooks SET failures = failures + 1, last_status = :h, last_attempt_at = NOW() WHERE id = :id', ['h' => $status, 'id' => $d['webhook_id']]);
            Database::run("UPDATE listenin_webhooks SET active = 0, disabled_reason = 'Disattivato dopo troppi errori consecutivi' WHERE id = :id AND failures >= :m AND active = 1", ['id' => $d['webhook_id'], 'm' => $max]);
            $out[$final ? 'failed' : 'retry']++;
        }
        return $out;
    }

    /** A fine richiesta: elabora le consegne se ce ne sono di nuove o se è passato circa un minuto. */
    public static function tick(): void
    {
        $stamp = LISTENIN_ROOT . '/storage/cache/webhook.stamp';
        if (!self::$dirty && is_file($stamp) && time() - (int) filemtime($stamp) < 60) {
            return;
        }
        @touch($stamp);
        if (!self::available() || !Database::value("SELECT 1 FROM listenin_webhook_deliveries WHERE status = 'pending' AND next_attempt_at <= NOW() LIMIT 1")) {
            return;
        }
        self::process();
    }

    public static function purge(): int
    {
        return Database::run("DELETE FROM listenin_webhook_deliveries WHERE status <> 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->rowCount();
    }
}
