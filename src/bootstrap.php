<?php
declare(strict_types=1);

/**
 * App bootstrap: load config, register a tiny autoloader, start the session,
 * connect to the database, and expose shared helpers.
 *
 * Every entry point (*.php in the web root, bin/*.php) requires this file first.
 */

define('APP_ROOT', dirname(__DIR__));

// --- Config ---
// config.php lives in the application root (next to index.php).
$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Missing config.php. Run: cp config.sample.php config.php\n");
        exit(1);
    }
    http_response_code(500);
    exit('Missing config.php — copy config.sample.php to config.php and set your values.');
}
$config = require $configFile;

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// --- Autoloader for Domainzs\* classes ---
spl_autoload_register(function (string $class): void {
    $prefix = 'Domainzs\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require __DIR__ . '/helpers.php';

// --- Database ---
$pdo = \Domainzs\Database::connect($config['db']);

// --- Session (web only) ---
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('domainzs');
    session_start();
}

// --- Self-healing scheduler (never blocks the page) ---
// When the host's cron/URL is blocked, ordinary page traffic keeps the daily
// fetch running. Critically, the pipeline runs only AFTER the response has been
// flushed to the browser (LiteSpeed/FPM finish-request), so the visitor's page
// never waits on it. Throttled to once / 30 min; disable with auto_run = 0.
// daily-run.php is excluded to avoid recursion.
if (PHP_SAPI !== 'cli'
    && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'daily-run.php'
    && setting('auto_run', '1') === '1') {
    try {
        $cronKey = (string) setting('cron_key', '');
        if ($cronKey !== '') {
            $dropsCfg = drops_config($config);
            $wantDate = date('Y-m-d', time() - max(0, (int)$dropsCfg['day_offset']) * 86400);
            $done = true;
            try {
                $q = $pdo->prepare('SELECT 1 FROM drops WHERE dropped_date = ? LIMIT 1');
                $q->execute([$wantDate]);
                $done = (bool) $q->fetchColumn();
            } catch (\Throwable $e) {
                $done = true; // tables not migrated yet — do nothing
            }
            $lastKick = (int) (setting('cron_kick_at', '0') ?: 0);
            if (!$done && time() - $lastKick > 30 * 60) {
                set_setting('cron_kick_at', (string) time()); // throttle before running

                $hasFinish = function_exists('litespeed_finish_request')
                    || function_exists('fastcgi_finish_request');

                if ($hasFinish) {
                    // Preferred on Hostinger (LiteSpeed): render the page, close the
                    // connection, THEN run the pipeline in the same worker. Zero wait.
                    register_shutdown_function(function () use ($pdo, $config): void {
                        if (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
                        elseif (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                        @ignore_user_abort(true);
                        @set_time_limit(600);
                        try { run_daily_pipeline($pdo, $config); } catch (\Throwable $e) { /* silent */ }
                    });
                } elseif (function_exists('exec')) {
                    // Fallback: a FULLY detached background process (nohup + closed
                    // stdin) so the request can't wait on it.
                    $phpBin = null;
                    foreach (['/usr/bin/php', PHP_BINDIR . '/php', PHP_BINARY] as $cand) {
                        if ($cand && @is_executable($cand)) { $phpBin = $cand; break; }
                    }
                    if ($phpBin !== null) {
                        @exec('nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg(APP_ROOT . '/daily-run.php')
                            . ' ' . escapeshellarg($cronKey) . ' daily < /dev/null > /dev/null 2>&1 &');
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // The fallback must never break a page.
    }
}
