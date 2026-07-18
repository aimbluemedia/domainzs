<?php
declare(strict_types=1);

/**
 * Cron entry point — runs the full daily pipeline: fetch the drop list,
 * filter, score, verify (name.com / RDAP), Moz, AI-rate, then build the
 * Daily Recap. Named daily-run.php (not cron.php) because many hosts —
 * Hostinger included — block direct web access to files named cron.php.
 * Works two ways, both protected by the secret key in Settings:
 *
 *   Command cron (recommended — also does the free RDAP availability check):
 *     /usr/bin/php /home/USER/domains/your-domain.com/public_html/daily-run.php <KEY> daily
 *
 *   URL cron (no SSH needed):
 *     https://your-domain.com/daily-run.php?key=<KEY>
 *
 * The trailing "daily" (or any task word) is accepted and ignored — there is
 * one job. An optional YYYY-MM-DD argument overrides which day is fetched.
 */

require __DIR__ . '/src/bootstrap.php';

use Domainzs\DropEngine;
use Domainzs\DailyRecap;
use Domainzs\Notifier;

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

/** End the run with a message; HTTP status for web, exit code for CLI. */
$fail = function (int $status, string $msg) use ($isCli): never {
    if (!$isCli) {
        http_response_code($status);
    }
    fwrite($isCli ? STDERR : STDOUT, $msg . "\n");
    exit($isCli ? 1 : 0);
};

// --- Auth: key from the CLI argument, or ?key= on the URL (constant-time) ---
$configured = (string) setting('cron_key', '');
$provided   = $isCli
    ? (string) ($argv[1] ?? '')
    : (string) ($_GET['key'] ?? $_GET['token'] ?? '');

if ($configured === '') {
    $fail(503, 'Cron key not set. Open Settings → Automation and save a key first.');
}
if ($provided === '' || !hash_equals($configured, $provided)) {
    $fail(403, 'Forbidden — bad or missing cron key.');
}

@set_time_limit(600);
ignore_user_abort(true);

// Optional explicit date (a YYYY-MM-DD arg on the CLI, or ?date= on the URL).
$dateArg = null;
$candidates = $isCli ? array_slice($argv, 1) : [(string) ($_GET['date'] ?? '')];
foreach ($candidates as $a) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $a)) {
        $dateArg = (string) $a;
    }
}

// One shared pipeline: fetch → rate → recap → email → stamp last-run.
// With no explicit date, catch up on any days the (flaky) cron skipped —
// the free WhoisFreaks archive keeps per-date files, so gaps self-heal.
$stats = $dateArg !== null
    ? run_daily_pipeline($pdo, $config, $dateArg)
    : run_daily_catchup($pdo, $config);
$date  = $stats['date'];

$top = $pdo->prepare('SELECT domain, score FROM drops WHERE dropped_date = ? ORDER BY score DESC LIMIT 5');
$top->execute([$date]);
(new Notifier($config))->sendFetchDigest($date, $stats, $top->fetchAll());

$stamp = date('Y-m-d H:i:s');
if (!empty($stats['catchup']) && count($stats['catchup']) > 1) {
    echo "[{$stamp}] caught up on " . count($stats['catchup']) . ' day(s): '
        . implode(', ', $stats['catchup']) . "\n";
}
echo "[{$stamp}] {$date}: {$stats['raw']} in feed → {$stats['matched']} matched → {$stats['added']} new"
    . " · {$stats['verified']} availability-verified · {$stats['moz_rated']} Moz-rated · {$stats['ai_rated']} AI-rated\n";
if (!empty($stats['error'])) {
    echo "FEED PROBLEM: {$stats['error']}\n";
}
if (!empty($stats['recap_pick'])) {
    echo "recap top pick: {$stats['recap_pick']}\n";
}

echo "OK\n";
