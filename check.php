<?php
declare(strict_types=1);

/** "Check all now" action — refreshes every tracked domain, then returns to the dashboard. */

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use Domainzs\Auth;
use Domainzs\DomainChecker;

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}
csrf_verify();

$result = (new DomainChecker($pdo, $rdap, $config))->run(true);

$alerts = count($result['alerts']);
flash('success', "Checked {$result['checked']} domain(s)"
    . ($alerts > 0 ? " — {$alerts} new alert(s)!" : ' — nothing new.'));
redirect('/');
