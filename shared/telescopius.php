<?php
// ============================================================
// shared/telescopius.php — Telescopius API client (v2.2)
//
// One place for every Telescopius call the app makes, so that:
//   * the API key is read server-side only (TELESCOPIUS_PROD_API_KEY in
//     astro/.env.prod, the folder above the web root -- or the same name
//     as an environment variable, which wins)
//   * every real call is timed and logged (cache/telescopius/calls_YYYY-MM.jsonl)
//   * the 6,000-calls-a-month limit is guarded: past 80% only calls
//     marked essential go out; at 100% nothing does
//   * Telescopius's own limit of 60 calls a minute is respected: when its
//     rate-limit headers say the minute is used up, the next call waits
//     for the reset (up to 65 s from the command line, 5 s in a web page)
//     instead of failing; past that it throws TelescopiusRateLimitException
//   * results can be cached on disk with a time-to-live, so pages read
//     the cache instead of waiting on the API
//
// ON THE PC: Telescopius only accepts the VPS's IP address, so the PC
// sends its calls to the relay page on the VPS (public/api/telescopius.php),
// which makes the real call. Turned on by astro/.env.local (PC only --
// never upload it):
//     TELESCOPIUS_RELAY=https://astro.wiibopp.com/api/telescopius.php
// and both machines need the same relay password in .env.prod:
//     TELESCOPIUS_RELAY_KEY=<long random string>
// In relay mode the VPS does the logging, caching and budget check, so
// there is one monthly counter for both machines. No .env.local (the VPS)
// = calls go straight to Telescopius.
//
// Usage:
//     require_once __DIR__ . '/../../shared/telescopius.php';   // adjust depth
//     $t = new Telescopius();
//     $r = $t->get('/solar-system/times', Telescopius::site(), 86400);
//     $r['data']         decoded JSON
//     $r['cached']       true when served from the disk cache (no call made)
//     $r['ms']           total time as seen from this machine
//     $r['upstream_ms']  time the Telescopius call itself took (from the VPS
//                        in relay mode); null when served from cache
//     $r['timing']       curl breakdown of the Telescopius call
// ============================================================

class TelescopiusException extends RuntimeException {}
class TelescopiusBudgetException extends TelescopiusException {}

/** Telescopius's per-minute limit; retryAfter = seconds until it resets. */
class TelescopiusRateLimitException extends TelescopiusException
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message);
    }
}

final class Telescopius
{
    public const BASE_URL      = 'https://api.telescopius.com/v2.2';
    public const MONTHLY_LIMIT = 6000;
    public const SOFT_CAP      = 0.80;   // past this share, only essential calls
    public const MAX_WAIT_CLI  = 65;     // seconds a script may wait for the per-minute limit
    public const MAX_WAIT_WEB  = 5;      // ... and a web page (the relay included)

    // Endpoints the relay will pass on (all GETs; /mcp is not included).
    public const RELAY_PATHS = [
        '/solar-system/times', '/targets/search', '/targets/highlights', '/targets/lists',
        '/target-lists', '/events/calendar', '/events/highlights', '/weather/forecast',
        '/equipment/user', '/quote-of-the-day', '/news/global',
    ];

    // Observing site (Star, Idaho). Always sent: without lat/lon the API
    // guesses from the caller's IP, which on the VPS is the data centre.
    public const SITE = [
        'lat'      => 43.6921,
        'lon'      => -116.4935,
        'timezone' => 'America/Boise',
    ];

    // Carl's DSO visibility profiles, as Telescopius search parameters.
    public const PROFILES = [
        'ne_se_20' => ['label' => 'NE + SE quadrants, 20° min', 'min_alt' => 20, 'az_quadrants' => 'NE,SE'],
        'open_35'  => ['label' => 'No blocks, 35° min',         'min_alt' => 35],
        // 'open_45'  => ['label' => 'No blocks, 45° min',      'min_alt' => 45],   // off for now
    ];

    private ?string $apiKey;
    private string $dataDir;
    private ?string $relay = null;
    private ?string $relayKey = null;
    /** @var CurlHandle|null */
    private $curl = null;
    private float $waited = 0.0;         // seconds spent waiting on the per-minute limit

    /**
     * @param bool $allowRelay false = always call Telescopius directly, even
     *                         if .env.local names a relay (the relay page
     *                         itself uses this, so it can never call itself)
     */
    public function __construct(?string $apiKey = null, ?string $dataDir = null, bool $allowRelay = true)
    {
        $this->dataDir = $dataDir ?? dirname(__DIR__) . '/cache/telescopius';
        $this->apiKey  = $apiKey ?? self::setting('TELESCOPIUS_PROD_API_KEY');

        if ($allowRelay && ($relay = self::setting('TELESCOPIUS_RELAY')) !== null) {
            $this->relay    = $relay;
            $this->relayKey = self::setting('TELESCOPIUS_RELAY_KEY')
                ?? throw new TelescopiusException('TELESCOPIUS_RELAY is set but TELESCOPIUS_RELAY_KEY is not (.env.prod)');
            return;
        }

        if ($this->apiKey === null) {
            throw new TelescopiusException('TELESCOPIUS_PROD_API_KEY not found (environment or .env.prod)');
        }
        if (!is_dir($this->dataDir . '/responses') && !@mkdir($this->dataDir . '/responses', 0775, true)) {
            throw new TelescopiusException("Can't create {$this->dataDir}/responses");
        }
    }

    public function __destruct()
    {
        $this->resetConnection();
    }

    /** lat/lon/timezone for the observing site, plus any extra parameters. */
    public static function site(array $extra = []): array
    {
        return self::SITE + $extra;
    }

    /** Search parameters for one of the visibility profiles (see PROFILES). */
    public static function profile(string $name, array $extra = []): array
    {
        if (!isset(self::PROFILES[$name])) {
            throw new TelescopiusException("Unknown visibility profile '$name'");
        }
        $p = self::PROFILES[$name];
        unset($p['label']);
        return self::site($p + $extra);
    }

    /**
     * GET an endpoint.
     *
     * @param string $path      e.g. '/targets/search'
     * @param array  $params    query parameters
     * @param int    $ttl       seconds a cached copy stays good; 0 = always call
     * @param bool   $essential still allowed past the 80% soft cap
     * @return array{data:mixed, cached:bool, ms:float, upstream_ms:?float, bytes:int,
     *               status:int, timing:array, limit_headers:array, age:int}
     */
    public function get(string $path, array $params = [], int $ttl = 0, bool $essential = false): array
    {
        if ($this->relay !== null) {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $t0 = hrtime(true);
                    $r = $this->relayPost(['path' => $path, 'params' => $params, 'ttl' => $ttl, 'essential' => $essential]);
                    $r['ms'] = round((hrtime(true) - $t0) / 1e6, 1);   // round trip from this machine
                    return $r;
                } catch (TelescopiusRateLimitException $e) {
                    // The VPS waited as long as a web page may; wait out the rest here.
                    if ($attempt >= 3 || $e->retryAfter > self::maxWait()) throw $e;
                    $this->pause($e->retryAfter);
                }
            }
        }

        ksort($params);
        $cacheFile = $this->dataDir . '/responses/' . sha1($path . '?' . http_build_query($params)) . '.json';

        if ($ttl > 0 && is_file($cacheFile)) {
            $t0 = hrtime(true);
            $stored = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($stored) && time() - $stored['stored_at'] < $ttl) {
                return [
                    'data' => $stored['data'], 'cached' => true,
                    'ms' => (hrtime(true) - $t0) / 1e6, 'upstream_ms' => null, 'bytes' => filesize($cacheFile),
                    'status' => 200, 'timing' => [], 'limit_headers' => [],
                    'age' => time() - $stored['stored_at'],
                ];
            }
        }

        $this->checkBudget($essential);
        $this->waitForMinuteLimit();

        $url = self::BASE_URL . $path . ($params ? '?' . http_build_query($params) : '');
        $headers = [];
        $ch = $this->handle();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => '',          // accept gzip
            CURLOPT_HTTPHEADER     => ['Authorization: Key ' . $this->apiKey, 'Accept: application/json'],
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        $err  = curl_error($ch);

        $timing = [
            // PHP fills the *_us (microsecond) fields; appconnect = secure connection ready.
            'dns_ms'     => round(($info['namelookup_time_us'] ?? 0) / 1000, 1),
            'connect_ms' => round(($info['connect_time_us'] ?? 0) / 1000, 1),
            'tls_ms'     => round(($info['appconnect_time_us'] ?? 0) / 1000, 1),
            'ttfb_ms'    => round(($info['starttransfer_time_us'] ?? 0) / 1000, 1),
            'total_ms'   => round(($info['total_time_us'] ?? 0) / 1000, 1),
        ];
        $status = (int) $info['http_code'];
        $bytes  = is_string($body) ? strlen($body) : 0;
        $limitHeaders = array_filter(
            $headers,
            fn($k) => preg_match('/rate|limit|remaining|quota|retry/i', $k),
            ARRAY_FILTER_USE_KEY
        );

        // Every attempt that reached the network counts against the budget.
        $this->logCall($path, $params, $status, $timing['total_ms'], $bytes, $limitHeaders, $err);
        $this->saveMinuteLimit($headers, $status);

        if ($body === false) {
            throw new TelescopiusException("Telescopius $path: network error: $err");
        }
        if ($status === 429) {
            $retry = max(1, (int) ($headers['retry-after'] ?? 60));
            throw new TelescopiusRateLimitException("Telescopius $path: per-minute limit reached (429), retry in {$retry}s", $retry);
        }
        if ($status < 200 || $status >= 300) {
            throw new TelescopiusException("Telescopius $path: HTTP $status: " . substr($body, 0, 300));
        }
        $data = json_decode($body, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new TelescopiusException("Telescopius $path: response isn't JSON");
        }

        if ($ttl > 0) {
            @file_put_contents($cacheFile, json_encode(['stored_at' => time(), 'data' => $data]), LOCK_EX);
        }

        return [
            'data' => $data, 'cached' => false, 'ms' => $timing['total_ms'], 'upstream_ms' => $timing['total_ms'],
            'bytes' => $bytes, 'status' => $status, 'timing' => $timing, 'limit_headers' => $limitHeaders, 'age' => 0,
        ];
    }

    /** Calls made this calendar month (UTC). In relay mode, the VPS's count. */
    public function callsThisMonth(): int
    {
        if ($this->relay !== null) {
            return (int) $this->relayPost(['action' => 'usage'])['calls_this_month'];
        }
        $f = $this->logFile();
        if (!is_file($f)) return 0;
        $n = 0;
        $h = fopen($f, 'r');
        while (($line = fgets($h)) !== false) {
            if (trim($line) !== '') $n++;
        }
        fclose($h);
        return $n;
    }

    /** Close the connection, so the next call connects afresh (a "cold" call). */
    public function resetConnection(): void
    {
        if ($this->curl !== null) {
            curl_close($this->curl);
            $this->curl = null;
        }
    }

    /** Seconds this client has spent waiting on Telescopius's per-minute limit. */
    public function waited(): float
    {
        return $this->waited;
    }

    /** The relay URL calls go through, or null when they go straight to Telescopius. */
    public function relay(): ?string
    {
        return $this->relay;
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    /**
     * A setting from the environment, else astro/.env.local (PC only), else
     * astro/.env.prod. Lines are NAME=value; # comments, optional quotes.
     */
    public static function setting(string $name): ?string
    {
        $v = getenv($name);
        if (is_string($v) && $v !== '') return $v;

        foreach (['.env.local', '.env.prod'] as $file) {
            $path = dirname(__DIR__) . '/' . $file;
            if (!is_file($path)) continue;
            // Strip a UTF-8 byte-order mark (some Windows editors add one) --
            // otherwise the first line's name never matches.
            $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path));
            foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (str_starts_with($line, 'export ')) $line = substr($line, 7);
                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                if (trim($key) !== $name) continue;
                $value = trim($value);
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                    $value = substr($value, 1, -1);
                }
                if ($value !== '') return $value;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------

    private function handle()
    {
        if ($this->curl === null) {
            $this->curl = curl_init();
        }
        return $this->curl;
    }

    /** POST a request to the relay page and return its answer. */
    private function relayPost(array $payload): array
    {
        $ch = $this->handle();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->relay,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Relay-Key: ' . $this->relayKey],
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            throw new TelescopiusException("Relay {$this->relay}: network error: " . curl_error($ch));
        }
        $r = json_decode($body, true);
        if (!is_array($r)) {
            throw new TelescopiusException("Relay {$this->relay}: HTTP $status, not a relay answer: " . substr($body, 0, 200));
        }
        if (empty($r['ok'])) {
            $msg = 'Relay (on the VPS): ' . ($r['error'] ?? "HTTP $status");
            if (!empty($r['rate_limited'])) throw new TelescopiusRateLimitException($msg, (int) ($r['retry_after'] ?? 60));
            throw !empty($r['budget']) ? new TelescopiusBudgetException($msg) : new TelescopiusException($msg);
        }
        unset($r['ok']);
        return $r;
    }

    private static function maxWait(): int
    {
        return PHP_SAPI === 'cli' ? self::MAX_WAIT_CLI : self::MAX_WAIT_WEB;
    }

    private function pause(int $seconds): void
    {
        $t0 = microtime(true);
        sleep($seconds);
        $this->waited += microtime(true) - $t0;
    }

    private function minuteFile(): string
    {
        return $this->dataDir . '/minute_limit.json';
    }

    /**
     * Wait if Telescopius's last answer said this minute's calls are used up.
     * The state is a file, so separate requests (every relay call is its own
     * PHP process) all see it.
     */
    private function waitForMinuteLimit(): void
    {
        $state = @json_decode((string) @file_get_contents($this->minuteFile()), true);
        if (!is_array($state) || ($state['remaining'] ?? 99) > 1) return;
        $wait = (int) ($state['reset_at'] ?? 0) - time() + 1;
        if ($wait <= 0) return;
        if ($wait > self::maxWait()) {
            throw new TelescopiusRateLimitException("Telescopius: per-minute limit used up, resets in {$wait}s", $wait);
        }
        $this->pause($wait);
    }

    /** Remember what Telescopius said about this minute's remaining calls. */
    private function saveMinuteLimit(array $headers, int $status): void
    {
        $remaining = $headers['x-ratelimit-remaining'] ?? null;
        $reset     = $headers['x-ratelimit-reset'] ?? null;     // Unix time
        if ($status === 429) {
            $remaining = 0;
            $reset = time() + max(1, (int) ($headers['retry-after'] ?? 60));
        }
        if ($remaining === null || $reset === null) return;
        $reset = (int) $reset;
        if ($reset < 1000000000) $reset += time();                 // seconds-from-now form
        @file_put_contents($this->minuteFile(),
            json_encode(['remaining' => (int) $remaining, 'reset_at' => $reset]), LOCK_EX);
    }

    private function checkBudget(bool $essential): void
    {
        $used = $this->callsThisMonth();
        if ($used >= self::MONTHLY_LIMIT) {
            throw new TelescopiusBudgetException("Telescopius: monthly limit used up ($used of " . self::MONTHLY_LIMIT . ')');
        }
        $soft = (int) floor(self::MONTHLY_LIMIT * self::SOFT_CAP);
        if (!$essential && $used >= $soft) {
            throw new TelescopiusBudgetException(
                "Telescopius: $used of " . self::MONTHLY_LIMIT . ' calls used this month -- past '
                . (self::SOFT_CAP * 100) . '%, only essential calls go out'
            );
        }
    }

    private function logFile(): string
    {
        return $this->dataDir . '/calls_' . gmdate('Y-m') . '.jsonl';
    }

    private function logCall(string $path, array $params, int $status, float $ms, int $bytes, array $limitHeaders, string $err): void
    {
        $row = [
            'at'     => gmdate('c'),
            'host'   => gethostname(),
            'path'   => $path,
            'params' => $params,
            'status' => $status,
            'ms'     => $ms,
            'bytes'  => $bytes,
        ];
        if ($limitHeaders) $row['limit_headers'] = $limitHeaders;
        if ($err !== '')   $row['error'] = $err;
        @file_put_contents($this->logFile(), json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
    }
}
