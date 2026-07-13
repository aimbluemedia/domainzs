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
        'provider'  => setting('drops_provider', (string)($file['provider'] ?? 'mock')),
        'url'       => setting('drops_url', (string)($file['url'] ?? '')),
        'exact_len' => (int)(setting('drops_exact_len', (string)($file['exact_len'] ?? 9)) ?? 9),
        'tlds'      => setting('drops_tlds', (string)($file['tlds'] ?? 'com')),
        'max_keep'  => (int)(setting('drops_max_keep', (string)($file['max_keep'] ?? 500)) ?? 500),
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
    ];
}
