<?php
declare(strict_types=1);

/**
 * Generate the Daily Recap for a date (AI deep-dive on that day's drops).
 *
 * Usage:
 *   php bin/recap.php                # yesterday (matches the fetch default)
 *   php bin/recap.php 2026-07-14     # a specific date
 *   php bin/recap.php --force        # regenerate even if one exists
 *
 * Usually run right after bin/fetch.php on the same cron line.
 */

require __DIR__ . '/../src/bootstrap.php';

use Domainzs\DailyRecap;

@set_time_limit(300);

$force = in_array('--force', $argv, true);
$dateArg = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $a)) { $dateArg = $a; }
}

$drops  = drops_config($config);
$date   = $dateArg ?: date('Y-m-d', time() - max(0, (int)$drops['day_offset']) * 86400);

$recap = (new DailyRecap($pdo, $config))->generate($date);
set_setting('recap_generating_' . $date, '0'); // clears the "Generating…" banner
$stamp = date('Y-m-d H:i:s');
if ($recap === null) {
    echo "[{$stamp}] {$date}: no drops for that date — nothing to recap.\n";
    exit;
}
$mode = $recap['is_ai'] ? 'AI' : 'heuristic';
$pick = $recap['body']['top_pick']['domain'] ?? '(none)';
echo "[{$stamp}] {$date}: recap generated ({$mode}) over {$recap['drop_count']} names — top pick: {$pick}\n";
