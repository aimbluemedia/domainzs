<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use Domainzs\Auth;

Auth::requireLogin();

$portfolio = $pdo->query(
    "SELECT * FROM domains WHERE kind = 'portfolio'
     ORDER BY expires_at IS NULL, expires_at ASC"
)->fetchAll();
$watchlist = $pdo->query(
    "SELECT * FROM domains WHERE kind = 'watchlist'
     ORDER BY FIELD(status, 'available', 'pending_delete', 'registered', 'unknown'), domain"
)->fetchAll();

$expiring30 = count(array_filter($portfolio, function (array $d): bool {
    $days = days_until($d['expires_at']);
    return $days !== null && $days <= 30;
}));
$availableNow = count(array_filter($watchlist, fn (array $d): bool => $d['status'] === 'available'));

$lastCheck = $pdo->query('SELECT MAX(last_checked_at) FROM domains')->fetchColumn();

layout_header('Dashboard');
?>
<h1>Dashboard</h1>
<p class="sub">Everything you own, everything you want — and what needs attention today.</p>

<div class="stat-grid">
    <div class="stat"><span class="stat-num"><?= count($portfolio) ?></span><span class="stat-label">Domains owned</span></div>
    <div class="stat"><span class="stat-num <?= $expiring30 > 0 ? 'stat-warn' : '' ?>"><?= $expiring30 ?></span><span class="stat-label">Expiring ≤ 30 days</span></div>
    <div class="stat"><span class="stat-num"><?= count($watchlist) ?></span><span class="stat-label">Domains watched</span></div>
    <div class="stat"><span class="stat-num <?= $availableNow > 0 ? 'stat-good' : '' ?>"><?= $availableNow ?></span><span class="stat-label">Available now</span></div>
</div>

<div class="scanbar">
    <form class="inline" method="post" action="/check.php">
        <?= csrf_field() ?>
        <button class="btn btn-scan" type="submit">🔄 Check all now</button>
    </form>
    <span class="scanbar-label">Last check: <?= $lastCheck ? e((string)$lastCheck) : 'never — run your first check!' ?></span>
</div>

<h2>⏳ Portfolio — renewals coming up</h2>
<?php if (!$portfolio): ?>
    <div class="empty">No domains yet. <a href="/portfolio.php">Add your first domain</a> and domainzs will pull its expiry date automatically.</div>
<?php else: ?>
    <table>
        <tr><th>Domain</th><th>Expires</th><th>Countdown</th><th>Registrar</th><th>Auto-renew</th></tr>
        <?php foreach (array_slice($portfolio, 0, 10) as $d): ?>
        <tr>
            <td><strong><?= e($d['domain']) ?></strong></td>
            <td><?= $d['expires_at'] ? e(substr($d['expires_at'], 0, 10)) : '—' ?></td>
            <td><span class="badge-exp exp-<?= e(expiry_class($d['expires_at'])) ?>"><?= e(expiry_label($d['expires_at'])) ?></span></td>
            <td><?= e($d['registrar'] ?? '—') ?></td>
            <td><?= $d['auto_renew'] ? '✅ on' : '⚠️ off' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if (count($portfolio) > 10): ?>
        <p class="sub" style="margin-top:10px"><a href="/portfolio.php">See all <?= count($portfolio) ?> domains →</a></p>
    <?php endif; ?>
<?php endif; ?>

<h2>👀 Watchlist — drops &amp; availability</h2>
<?php if (!$watchlist): ?>
    <div class="empty">Not watching anything yet. <a href="/watchlist.php">Watch a domain</a> and get an email the moment it becomes available.</div>
<?php else: ?>
    <table>
        <tr><th>Domain</th><th>Status</th><th>Current expiry</th><th>Last checked</th></tr>
        <?php foreach (array_slice($watchlist, 0, 10) as $d): [$label, $class] = status_meta($d['status']); ?>
        <tr>
            <td><strong><?= e($d['domain']) ?></strong></td>
            <td><span class="badge-st st-<?= e($class) ?>"><?= e($label) ?></span></td>
            <td><?= $d['expires_at'] ? e(substr($d['expires_at'], 0, 10)) . ' <span class="sub-inline">(' . e(expiry_label($d['expires_at'])) . ')</span>' : '—' ?></td>
            <td><?= e($d['last_checked_at'] ?? 'never') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if (count($watchlist) > 10): ?>
        <p class="sub" style="margin-top:10px"><a href="/watchlist.php">See all <?= count($watchlist) ?> watched domains →</a></p>
    <?php endif; ?>
<?php endif; ?>
<?php
layout_footer();
