<?php
declare(strict_types=1);

/**
 * Small view/helper functions shared across the app.
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
function money(?float $amount, string $currency = 'USD'): string
{
    if ($amount === null) {
        return '—';
    }
    $symbol = ['USD' => '$', 'GBP' => '£', 'EUR' => '€', 'CAD' => 'C$', 'AUD' => 'A$'][$currency] ?? '';
    return $symbol . number_format($amount, 2);
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
    // IDN → punycode so RDAP and DNS both understand it.
    if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $d)) {
        $ascii = idn_to_ascii($d, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($ascii)) {
            $d = $ascii;
        }
    }
    // Must be label(.label)+ with a letter-ish TLD.
    if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $d)) {
        return null;
    }
    return $d;
}

/** Whole days from now until a datetime string (negative if past), null if unset. */
function days_until(?string $datetime): ?int
{
    if (!$datetime) {
        return null;
    }
    try {
        $end = new DateTime($datetime);
    } catch (\Exception $e) {
        return null;
    }
    return (int) floor(($end->getTimestamp() - time()) / 86400);
}

/** Human-friendly expiry countdown, e.g. "in 42 days", "in 3 days!", "expired". */
function expiry_label(?string $expiresAt): string
{
    $days = days_until($expiresAt);
    if ($days === null) {
        return '—';
    }
    if ($days < 0) {
        return 'expired ' . abs($days) . 'd ago';
    }
    if ($days === 0) {
        return 'expires today!';
    }
    return "in {$days}d";
}

/** CSS badge class for an expiry horizon: ok / warn / danger. */
function expiry_class(?string $expiresAt): string
{
    $days = days_until($expiresAt);
    if ($days === null) {
        return 'unknown';
    }
    if ($days <= 7) {
        return 'danger';
    }
    if ($days <= 30) {
        return 'warn';
    }
    return 'ok';
}

/** Display label + badge class for a domain registration status. */
function status_meta(string $status): array
{
    return match ($status) {
        'registered'     => ['Registered', 'taken'],
        'available'      => ['Available!', 'free'],
        'pending_delete' => ['Pending delete', 'drop'],
        default          => ['Unknown', 'unknown'],
    };
}
