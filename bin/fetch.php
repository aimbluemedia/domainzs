<?php
declare(strict_types=1);

/**
 * Cron fetcher — pulls the dropped-domain list, filters (9-char .coms by
 * default), scores everything, verifies + AI-rates the best, and emails a
 * digest when new drops land.
 *
 * Usage:
 *   php bin/fetch.php               # today's list
 *   php bin/fetch.php 2026-07-12    # a specific date
 *
 * Suggested crontab (daily, after the registries publish their drop lists):
 *   30 6 * * *  php /path/to/domainzs/bin/fetch.php >> /var/log/domainzs.log 2>&1
 */

require __DIR__ . '/../src/bootstrap.php';

use Domainzs\DropEngine;
use Domainzs\DropsClient;
use Domainzs\Notifier;

// With no argument, the engine fetches the most recent *published* list
// (yesterday by default — the "Daily fetch pulls" setting controls it).
$date = isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1]) ? $argv[1] : null;

$stats = (new DropEngine($pdo, $config))->run($date);
$date  = $stats['date'];

$top = $pdo->prepare('SELECT domain, score FROM drops WHERE dropped_date = ? ORDER BY score DESC LIMIT 5');
$top->execute([$date]);
$topDrops = $top->fetchAll();

(new Notifier($config))->sendFetchDigest($date, $stats, $topDrops);

$mock  = (new DropsClient(drops_config($config)))->isMock() ? ' [MOCK feed]' : '';
$stamp = date('Y-m-d H:i:s');
echo "[{$stamp}] {$date}: {$stats['raw']} in feed → {$stats['matched']} matched filter → {$stats['added']} new"
    . " · {$stats['verified']} availability-verified · {$stats['moz_rated']} Moz-rated · {$stats['ai_rated']} AI-rated{$mock}\n";
if (!empty($stats['error'])) {
    echo "  FEED PROBLEM: {$stats['error']}\n";
}
foreach ($topDrops as $drop) {
    echo "  {$drop['score']}  {$drop['domain']}\n";
}

// Generate the day's Daily Recap (best-effort — never let it break the fetch).
// Only creates one if today's is missing; regenerate from the admin page.
if ($stats['added'] > 0 || $stats['matched'] > 0) {
    try {
        $recap = (new \Domainzs\DailyRecap($pdo, $config))->forDate($date);
        if ($recap !== null) {
            echo '  recap: ' . ($recap['is_ai'] ? 'AI' : 'heuristic')
                . ' — top pick ' . ($recap['body']['top_pick']['domain'] ?? '(none)') . "\n";
            if (email_recap_once($config, $date, $recap['body'])) {
                echo "  recap email sent\n";
            }
        }
    } catch (\Throwable $e) {
        echo "  recap skipped: {$e->getMessage()}\n";
    }
}

// Record the run so the admin can confirm the cron is firing.
set_setting('cron_last_run', date('Y-m-d H:i:s'));
set_setting('cron_last_summary', "{$date}: {$stats['matched']} matched, {$stats['added']} new");
