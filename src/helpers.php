<?php
declare(strict_types=1);

/**
 * Small view/helper functions shared across the public site, member area,
 * and superadmin area.
 */

/** HTML-escape a value for safe output. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Generate (once per session) and return the CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Invalid or expired form token. Go back and try again.');
    }
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/** Format dollars from a float. */
function money(?float $amount): string
{
    if ($amount === null) {
        return '—';
    }
    return '$' . number_format($amount, 2);
}

/** Format dollars from an integer number of cents. */
function money_cents(int $cents): string
{
    return '$' . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
}

/** Read a site setting (key/value table), with a per-request cache. */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    global $pdo;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query('SELECT skey, sval FROM settings')->fetchAll() as $r) {
                $cache[$r['skey']] = $r['sval'];
            }
        } catch (\Throwable $e) {
            // settings table may not exist yet during install
        }
    }
    return $cache[$key] ?? $default;
}

/** Write a site setting (upsert into the key/value table). */
function set_setting(string $key, string $val): void
{
    global $pdo;
    $pdo->prepare('INSERT INTO settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')
        ->execute([$key, $val]);
}

/**
 * Normalise user input into a bare, lowercase, ASCII (punycode) domain name.
 * Accepts "Example.COM", "https://example.com/page", "www.example.com", IDNs.
 * Returns null when the input can't be a registrable domain.
 */
function normalize_domain(string $input): ?string
{
    $d = strtolower(trim($input));
    if ($d === '') {
        return null;
    }
    // Strip scheme, path, port, credentials if a URL was pasted.
    if (str_contains($d, '/') || str_contains($d, '@') || str_contains($d, ':')) {
        $host = parse_url((str_contains($d, '//') ? $d : 'http://' . $d), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $d = $host;
    }
    $d = preg_replace('/^www\./', '', $d) ?? $d;
    $d = rtrim($d, '.');
    if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $d)) {
        $ascii = idn_to_ascii($d, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($ascii)) {
            $d = $ascii;
        }
    }
    if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $d)) {
        return null;
    }
    return $d;
}

/** Split a domain into [sld, tld]: "coolbrand.com" → ["coolbrand", "com"]. */
function split_domain(string $domain): array
{
    $pos = strpos($domain, '.');
    if ($pos === false) {
        return [$domain, ''];
    }
    return [substr($domain, 0, $pos), substr($domain, $pos + 1)];
}

/** CSS badge class for a name length: shorter = more valuable = hotter. */
function length_class(int $len): string
{
    if ($len <= 4) return 'len-hot';   // 3-4 chars: rare & premium
    if ($len <= 6) return 'len-good';  // 5-6 chars: strong
    return 'len-plain';                // 7+ chars
}

/** CSS badge class for a 0-99 score: hot / good / meh / low. */
function score_class(int $score): string
{
    if ($score >= 75) return 'hot';
    if ($score >= 55) return 'good';
    if ($score >= 35) return 'meh';
    return 'low';
}

/**
 * Build the drop-feed config, preferring values saved in admin Settings (DB)
 * and falling back to config.php. Lets the superadmin manage the feed in the UI.
 */
function drops_config(array $config): array
{
    $file = $config['drops'] ?? [];
    return [
        'provider'   => setting('drops_provider', (string)($file['provider'] ?? 'mock')),
        'url'        => setting('drops_url', (string)($file['url'] ?? '')),
        'wf_api_key' => setting('whoisfreaks_api_key', (string)($file['whoisfreaks_api_key'] ?? '')),
        'wf_url'     => setting('whoisfreaks_url', (string)($file['whoisfreaks_url'] ?? '')),
        // Length is a range now. Fall back to the legacy single "exact_len"
        // so existing installs keep their exact-length behaviour until widened.
        'exact_len' => (int)(setting('drops_exact_len', (string)($file['exact_len'] ?? 9)) ?? 9),
        // Default range is 2–9 ("9 and shorter"). min is a fixed low default
        // (not seeded from the legacy exact_len), so an install that only ever
        // set exact_len still starts collecting shorter names after upgrade.
        // max keeps seeding from exact_len so the old 9 ceiling is preserved.
        'min_len'   => (int)(setting('drops_min_len', (string)($file['min_len'] ?? 2)) ?? 2),
        'max_len'   => (int)(setting('drops_max_len', (string)($file['max_len'] ?? setting('drops_exact_len', (string)($file['exact_len'] ?? 9)))) ?? 9),
        'tlds'      => setting('drops_tlds', (string)($file['tlds'] ?? 'com')),
        'max_keep'  => (int)(setting('drops_max_keep', (string)($file['max_keep'] ?? 500)) ?? 500),
        'no_hyphens' => (setting('drops_no_hyphens', !empty($file['no_hyphens']) ? '1' : '0') === '1'),
        'no_digits'  => (setting('drops_no_digits', !empty($file['no_digits']) ? '1' : '0') === '1'),
        // When no date is given (cron), fetch the list from this many days ago.
        // Most feeds publish a completed day the next morning, so 1 = yesterday.
        'day_offset' => (int)(setting('drops_day_offset', (string)($file['day_offset'] ?? 1)) ?? 1),
    ];
}

/** name.com API config: admin Settings first, config.php fallback. */
function namecom_config(array $config): array
{
    $file = $config['namecom'] ?? [];
    return [
        'username' => setting('namecom_username', (string)($file['username'] ?? '')),
        'token'    => setting('namecom_token', (string)($file['token'] ?? '')),
        'test'     => (setting('namecom_test', !empty($file['test']) ? '1' : '0') === '1'),
        'endpoint' => setting('namecom_endpoint', (string)($file['endpoint'] ?? '')),
    ];
}

/** Moz API config: admin Settings first, config.php fallback. */
function moz_config(array $config): array
{
    $file = $config['moz'] ?? [];
    return [
        'access_id'  => setting('moz_access_id', (string)($file['access_id'] ?? '')),
        'secret_key' => setting('moz_secret_key', (string)($file['secret_key'] ?? '')),
        'endpoint'   => setting('moz_endpoint', (string)($file['endpoint'] ?? '')),
    ];
}

/** AI config: admin Settings first, config.php fallback. */
function ai_config(array $config): array
{
    $file = $config['ai'] ?? [];
    return [
        'api_key'       => setting('ai_api_key', (string)($file['api_key'] ?? '')),
        'model'         => setting('ai_model', (string)($file['model'] ?? 'claude-opus-4-8')),
        'max_per_fetch' => (int)(setting('ai_max_per_fetch', (string)($file['max_per_fetch'] ?? 15)) ?? 15),
    ];
}

/** Mail config: admin Settings first, config.php fallback. */
function mail_config(array $config): array
{
    $file = $config['mail'] ?? [];
    return [
        'enabled' => (setting('mail_enabled', !empty($file['enabled']) ? '1' : '0') === '1'),
        'to'      => setting('mail_to', (string)($file['to'] ?? '')),
        'from'    => setting('mail_from', (string)($file['from'] ?? 'domainzs@localhost')),
        'from_name' => setting('mail_from_name', (string)($file['from_name'] ?? 'domainzs')),
        // The morning recap email (on by default once email itself is enabled).
        'recap'   => (setting('mail_recap', '1') === '1'),
        // Authenticated SMTP (recommended on shared hosting). When host+user+pass
        // are set, mail is sent through the mailbox instead of PHP mail().
        'smtp' => [
            'host'   => setting('mail_smtp_host', (string)($file['smtp']['host'] ?? '')),
            'port'   => (int)(setting('mail_smtp_port', (string)($file['smtp']['port'] ?? 465)) ?? 465),
            'secure' => setting('mail_smtp_secure', (string)($file['smtp']['secure'] ?? 'ssl')),
            'user'   => setting('mail_smtp_user', (string)($file['smtp']['user'] ?? '')),
            'pass'   => setting('mail_smtp_pass', (string)($file['smtp']['pass'] ?? '')),
        ],
    ];
}

/**
 * Fire-and-forget a CLI script as a fully detached background process, so the
 * web request never waits on it (fixes page hangs from in-worker pipelines).
 * Returns false if exec() is unavailable or no PHP binary was found.
 */
function spawn_background(string $relPath, array $args = []): bool
{
    if (!function_exists('exec')) {
        return false;
    }
    $phpBin = null;
    foreach (['/usr/bin/php', PHP_BINDIR . '/php', PHP_BINARY] as $cand) {
        if ($cand && @is_executable($cand)) { $phpBin = $cand; break; }
    }
    if ($phpBin === null) {
        return false;
    }
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg(APP_ROOT . '/' . ltrim($relPath, '/'));
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string) $a);
    }
    // nohup + closed stdin + background = the web worker returns immediately.
    @exec('nohup ' . $cmd . ' < /dev/null > /dev/null 2>&1 &');
    return true;
}

/**
 * The full daily job in one call: fetch + filter + score + verify + AI-rate,
 * generate the Daily Recap, email it once, and stamp cron_last_run. Shared by
 * daily-run.php (cron/URL), the admin "Run now" button, and the self-healer.
 *
 * @return array the DropEngine stats (plus 'recap_pick' for messaging)
 */
function run_daily_pipeline(\PDO $pdo, array $config, ?string $date = null, bool $sendEmail = true): array
{
    @set_time_limit(600);
    $stats = (new \Domainzs\DropEngine($pdo, $config))->run($date);
    $d = $stats['date'];

    $stats['recap_pick'] = null;
    if (($stats['matched'] ?? 0) > 0) {
        try {
            $recap = (new \Domainzs\DailyRecap($pdo, $config))->forDate($d);
            if ($recap !== null) {
                $stats['recap_pick'] = $recap['body']['top_pick']['domain'] ?? null;
                if ($sendEmail) {
                    email_recap_once($config, $d, $recap['body']);
                }
            }
        } catch (\Throwable $e) {
            // recap is best-effort; never fail the fetch over it
        }
    }

    set_setting('cron_last_run', date('Y-m-d H:i:s'));
    set_setting('cron_last_summary', "{$d}: {$stats['matched']} matched, {$stats['added']} new");
    return $stats;
}

/**
 * Run the daily pipeline for the target day AND backfill any missed days in
 * the trailing window. Hostinger's cron is flaky and sometimes skips a day or
 * two; because the free WhoisFreaks list keeps per-date archive files, a
 * skipped day can still be recovered the next time the cron fires. Processes
 * missing days oldest→newest (the target day always last), and emails only the
 * newest day's recap so a multi-day catch-up doesn't send a burst of emails.
 *
 * Returns the target day's stats, plus 'catchup' => [dates processed].
 */
function run_daily_catchup(\PDO $pdo, array $config, int $maxDays = 4): array
{
    $drops   = drops_config($config);
    $offset  = max(0, (int) $drops['day_offset']);
    $target  = date('Y-m-d', time() - $offset * 86400);
    $maxDays = max(1, min(14, $maxDays));
    $start   = date('Y-m-d', strtotime($target) - ($maxDays - 1) * 86400);

    // Which days in [start .. target] already have a batch?
    $have = [];
    try {
        $q = $pdo->prepare('SELECT DISTINCT dropped_date FROM drops WHERE dropped_date BETWEEN ? AND ?');
        $q->execute([$start, $target]);
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $d) {
            $have[(string) $d] = true;
        }
    } catch (\Throwable $e) {
        // tables not migrated — just try the target below
    }

    // Missing prior days (oldest first), then always the target day last.
    $todo = [];
    for ($i = $maxDays - 1; $i >= 1; $i--) {
        $d = date('Y-m-d', strtotime($target) - $i * 86400);
        if (!isset($have[$d])) {
            $todo[] = $d;
        }
    }
    $todo[] = $target;

    $last = count($todo) - 1;
    $processed = [];
    $targetStats = null;
    foreach ($todo as $idx => $d) {
        try {
            $stats = run_daily_pipeline($pdo, $config, $d, $idx === $last); // email only newest
            $processed[] = $d;
            if ($d === $target) {
                $targetStats = $stats;
            }
        } catch (\Throwable $e) {
            // one bad day must not abort the rest of the catch-up
        }
    }

    $targetStats ??= ['date' => $target, 'raw' => 0, 'matched' => 0, 'added' => 0,
                      'verified' => 0, 'moz_rated' => 0, 'ai_rated' => 0, 'recap_pick' => null];
    $targetStats['catchup'] = $processed;
    return $targetStats;
}

/**
 * Email the day's Daily Recap at most once per date. Safe to call on every
 * cron run — the recap_emailed_date setting dedupes, so an hourly cron still
 * sends a single morning email.
 */
function email_recap_once(array $config, string $date, array $recapBody): bool
{
    $mail = mail_config($config);
    if (empty($mail['enabled']) || empty($mail['recap'])) {
        return false;
    }
    if (setting('recap_emailed_date', '') === $date) {
        return false; // already sent today's
    }
    $sent = (new \Domainzs\Notifier($config))->sendRecapDigest($date, $recapBody);
    if ($sent) {
        set_setting('recap_emailed_date', $date);
    }
    return $sent;
}
