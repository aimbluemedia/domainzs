<?php
declare(strict_types=1);

/**
 * Web-triggerable cron endpoint (for Hostinger URL cron, UptimeRobot, etc.).
 *
 *   https://your-domain.com/cron.php?key=YOUR_SECRET_KEY
 *
 * Runs the same daily pipeline as bin/fetch.php — fetch the drop list, filter,
 * score, verify (name.com), AI-rate — then generates the Daily Recap. Protected
 * by a secret key stored in Settings (regenerate it there anytime).
 *
 * Prefer the command cron if you can:  php .../bin/fetch.php
 * (that path also does the free RDAP availability fallback, which is skipped
 *  here to keep the web request fast).
 */

require __DIR__ . '/src/bootstrap.php';

use Domainzs\DropEngine;
use Domainzs\DailyRecap;
use Domainzs\DropsClient;
use Domainzs\Notifier;

header('Content-Type: text/plain; charset=utf-8');

// --- Auth: constant-time key check ---
$configured = (string) setting('cron_key', '');
$provided   = (string) ($_GET['key'] ?? $_GET['token'] ?? '');
if ($configured === '' ) {
    http_response_code(503);
    exit("Cron key not set. Open Settings → Automation and save a key first.\n");
}
if ($provided === '' || !hash_equals($configured, $provided)) {
    http_response_code(403);
    exit("Forbidden — bad or missing key.\n");
}

@set_time_limit(300);
ignore_user_abort(true);

// Optional explicit date (?date=YYYY-MM-DD); otherwise the configured default.
$dateArg = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
    ? (string)$_GET['date'] : null;

$stats = (new DropEngine($pdo, $config))->run($dateArg);
$date  = $stats['date'];

$top = $pdo->prepare('SELECT domain, score FROM drops WHERE dropped_date = ? ORDER BY score DESC LIMIT 5');
$top->execute([$date]);
$topDrops = $top->fetchAll();
(new Notifier($config))->sendFetchDigest($date, $stats, $topDrops);

$stamp = date('Y-m-d H:i:s');
echo "[{$stamp}] {$date}: {$stats['raw']} in feed → {$stats['matched']} matched → {$stats['added']} new"
    . " · {$stats['verified']} availability-verified · {$stats['moz_rated']} Moz-rated · {$stats['ai_rated']} AI-rated\n";
if (!empty($stats['error'])) {
    echo "FEED PROBLEM: {$stats['error']}\n";
}

// Daily Recap (best-effort — never 500 the cron over it).
if ($stats['matched'] > 0) {
    try {
        $recap = (new DailyRecap($pdo, $config))->forDate($date);
        if ($recap !== null) {
            echo 'recap: ' . ($recap['is_ai'] ? 'AI' : 'heuristic')
                . ' — top pick ' . ($recap['body']['top_pick']['domain'] ?? '(none)') . "\n";
        }
    } catch (\Throwable $e) {
        echo "recap skipped: {$e->getMessage()}\n";
    }
}

echo "OK\n";
