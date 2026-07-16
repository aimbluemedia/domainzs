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

// --- Self-healing scheduler (wp-cron style fallback) ---
// When the host's cron or the daily-run URL is blocked, ordinary page traffic
// keeps the daily fetch running: if the day's batch hasn't been pulled yet, a
// detached background process runs daily-run.php. Throttled, silent, and a
// no-op once the day is done or a real cron already ran. daily-run.php itself
// is excluded to avoid recursion.
if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'daily-run.php') {
    try {
        $cronKey = (string) setting('cron_key', '');
        if ($cronKey !== '' && function_exists('exec')) {
            $dropsCfg = drops_config($config);
            $wantDate = date('Y-m-d', time() - max(0, (int)$dropsCfg['day_offset']) * 86400);
            // Already have that day's batch?
            $done = true;
            try {
                $q = $pdo->prepare('SELECT 1 FROM drops WHERE dropped_date = ? LIMIT 1');
                $q->execute([$wantDate]);
                $done = (bool) $q->fetchColumn();
            } catch (\Throwable $e) {
                $done = true; // tables not migrated yet — do nothing
            }
            $lastKick = (int) (setting('cron_kick_at', '0') ?: 0);
            // Kick at most once every 30 min, only while today's batch is missing.
            if (!$done && time() - $lastKick > 30 * 60) {
                set_setting('cron_kick_at', (string) time());
                $phpBin = null;
                foreach (['/usr/bin/php', PHP_BINDIR . '/php', PHP_BINARY] as $cand) {
                    if ($cand && @is_executable($cand)) { $phpBin = $cand; break; }
                }
                if ($phpBin !== null) {
                    @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg(APP_ROOT . '/daily-run.php')
                        . ' ' . escapeshellarg($cronKey) . ' daily > /dev/null 2>&1 &');
                }
            }
        }
    } catch (\Throwable $e) {
        // The fallback must never break a page.
    }
}
