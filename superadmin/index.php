<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;

Auth::requireAdmin();

$stats = [
    'drops'    => (int)$pdo->query('SELECT COUNT(*) FROM drops')->fetchColumn(),
    'members'  => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn(),
    'pro'      => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND sub_status IN ('trialing','active')")->fetchColumn(),
    'listings' => (int)$pdo->query("SELECT COUNT(*) FROM listings WHERE status = 'active'")->fetchColumn(),
    'offers'   => (int)$pdo->query('SELECT COUNT(*) FROM offers WHERE is_read = 0')->fetchColumn(),
];
$latestDate = (string)($pdo->query('SELECT MAX(dropped_date) FROM drops')->fetchColumn() ?: '');
$topToday   = [];
if ($latestDate !== '') {
    $stmt = $pdo->prepare('SELECT * FROM drops WHERE dropped_date = ? ORDER BY score DESC LIMIT 8');
    $stmt->execute([$latestDate]);
    $topToday = $stmt->fetchAll();
}
$recentOffers = $pdo->query(
    'SELECT o.*, l.domain FROM offers o JOIN listings l ON l.id = o.listing_id
     ORDER BY o.created_at DESC LIMIT 5'
)->fetchAll();

layout_header('Admin dashboard', 'admin');
?>
<h1>Dashboard</h1>
<p class="sub">Latest batch: <?= $latestDate !== '' ? e($latestDate) : 'nothing fetched yet — run your first fetch on the Drops page' ?>.</p>

<div class="stat-grid">
    <div class="stat"><span class="stat-num"><?= number_format($stats['drops']) ?></span><span class="stat-label">Drops rated</span></div>
    <div class="stat"><span class="stat-num"><?= $stats['members'] ?></span><span class="stat-label">Members</span></div>
    <div class="stat"><span class="stat-num"><?= $stats['pro'] ?></span><span class="stat-label">Paid subs</span></div>
    <div class="stat"><span class="stat-num"><?= $stats['listings'] ?></span><span class="stat-label">Active listings</span></div>
    <div class="stat"><span class="stat-num <?= $stats['offers'] > 0 ? 'stat-warn' : '' ?>"><?= $stats['offers'] ?></span><span class="stat-label">Unread offers</span></div>
</div>

<div class="cards-2">
    <div class="panel">
        <div class="panel-head"><h2>🔥 Latest batch — top rated</h2><a class="btn btn-sm" href="/superadmin/drops.php">All drops →</a></div>
        <?php if (!$topToday): ?><div class="empty">No drops yet.</div><?php else: ?>
        <table>
            <tr><th>Domain</th><th>Score</th><th>AI</th></tr>
            <?php foreach ($topToday as $d): ?>
            <tr>
                <td><strong><?= e($d['domain']) ?></strong></td>
                <td><span class="scorepill sc-<?= e(score_class((int)$d['score'])) ?>"><?= (int)$d['score'] ?></span></td>
                <td><?= $d['ai_rating'] !== null ? (int)$d['ai_rating'] : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <div class="panel">
        <div class="panel-head"><h2>💌 Recent offers</h2><a class="btn btn-sm" href="/superadmin/offers.php">All offers →</a></div>
        <?php if (!$recentOffers): ?><div class="empty">No offers yet.</div><?php else: ?>
        <table>
            <tr><th>Domain</th><th>From</th><th>Offer</th><th>When</th></tr>
            <?php foreach ($recentOffers as $o): ?>
            <tr <?= !$o['is_read'] ? 'class="row-unread"' : '' ?>>
                <td><strong><?= e($o['domain']) ?></strong></td>
                <td><?= e($o['name']) ?></td>
                <td><?= $o['amount_cents'] !== null ? money_cents((int)$o['amount_cents']) : '—' ?></td>
                <td class="notes-cell"><?= e($o['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php
layout_footer();
