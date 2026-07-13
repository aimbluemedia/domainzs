<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;

Auth::requireMember();
Auth::refresh($pdo);
$isPro = Auth::isPro();

$latestDate = (string)($pdo->query('SELECT MAX(dropped_date) FROM drops')->fetchColumn() ?: '');
$freeLimit  = 3;
$limit      = $isPro ? 10 : $freeLimit;

$top = [];
$todayCount = 0;
if ($latestDate !== '') {
    $stmt = $pdo->prepare('SELECT * FROM drops WHERE dropped_date = ? ORDER BY score DESC LIMIT ' . $limit);
    $stmt->execute([$latestDate]);
    $top = $stmt->fetchAll();
    $q = $pdo->prepare('SELECT COUNT(*) FROM drops WHERE dropped_date = ?');
    $q->execute([$latestDate]);
    $todayCount = (int)$q->fetchColumn();
}
$favStmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
$favStmt->execute([Auth::userId()]);
$favCount = (int)$favStmt->fetchColumn();

layout_header('Dashboard', 'member');
?>
<h1>Hey <?= e(Auth::username()) ?> 👋</h1>
<p class="sub">Latest batch: <?= $latestDate !== '' ? e($latestDate) : 'no drops fetched yet' ?>.</p>

<div class="stat-grid">
    <div class="stat"><span class="stat-num"><?= $todayCount ?></span><span class="stat-label">Drops in latest batch</span></div>
    <div class="stat"><span class="stat-num"><?= $top ? (int)$top[0]['score'] : '—' ?></span><span class="stat-label">Top score</span></div>
    <div class="stat"><span class="stat-num"><?= $favCount ?></span><span class="stat-label">Your favorites</span></div>
    <div class="stat"><span class="stat-num"><?= $isPro ? 'PRO' : 'FREE' ?></span><span class="stat-label">Your plan</span></div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>🔥 Top of the latest batch</h2>
        <a class="btn btn-sm" href="/member/drops.php">Full drop board →</a>
    </div>
    <?php if (!$top): ?>
        <div class="empty">No drops yet — the first fetch hasn't run. Check back soon!</div>
    <?php else: ?>
    <table>
        <tr><th>Domain</th><th>Score</th><th>Why</th><th>AI says</th></tr>
        <?php foreach ($top as $d):
            $notes = json_decode((string)$d['score_notes'], true) ?: []; ?>
        <tr>
            <td><strong><?= e($d['domain']) ?></strong></td>
            <td><span class="scorepill sc-<?= e(score_class((int)$d['score'])) ?>"><?= (int)$d['score'] ?></span></td>
            <td class="notes-cell"><?= e(implode(' · ', array_slice($notes, 0, 2))) ?></td>
            <td class="notes-cell"><?= $d['ai_comment'] ? e($d['ai_comment']) . ($d['est_value'] ? ' <strong>~$' . number_format((float)$d['est_value']) . '</strong>' : '') : '—' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if (!$isPro && $todayCount > $freeLimit): ?>
        <div class="upsell">
            <p><strong><?= $todayCount - $freeLimit ?> more rated drops</strong> in this batch are waiting on the full board.</p>
            <a class="btn btn-primary" href="/member/account.php">Upgrade to Pro</a>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php
layout_footer();
