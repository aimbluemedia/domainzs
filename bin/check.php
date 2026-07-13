<?php
declare(strict_types=1);

/**
 * Cron checker — refreshes stale domains and sends alert emails.
 *
 * Usage:
 *   php bin/check.php           # check domains whose data is stale
 *   php bin/check.php --force   # re-check everything now
 *
 * Suggested crontab (hourly):
 *   0 * * * *  php /path/to/domainzs/bin/check.php >> /var/log/domainzs.log 2>&1
 */

require __DIR__ . '/../src/bootstrap.php';

use Domainzs\DomainChecker;
use Domainzs\Notifier;

$force  = in_array('--force', $argv, true);
$result = (new DomainChecker($pdo, $rdap, $config))->run($force);

$stamp = date('Y-m-d H:i:s');
echo "[{$stamp}] Checked {$result['checked']} domain(s)"
    . ($rdap->isMock() ? ' [MOCK mode]' : '') . "\n";

foreach ($result['alerts'] as $alert) {
    echo "  ALERT: {$alert['message']}\n";
}
if ($result['alerts'] && !(new Notifier($config))->enabled()) {
    echo "  (email disabled — set mail.enabled and mail.to in config.php to get these by email)\n";
}
