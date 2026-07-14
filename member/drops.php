<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;

Auth::requireMember();
Auth::refresh($pdo);
$isPro = Auth::isPro();

// --- Favorite / unfavorite ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $dropId = (int)($_POST['drop_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'fav' && $isPro) {
        $pdo->prepare('INSERT IGNORE INTO favorites (user_id, drop_id) VALUES (?, ?)')
            ->execute([Auth::userId(), $dropId]);
    } elseif (($_POST['action'] ?? '') === 'unfav') {
        $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND drop_id = ?')
            ->execute([Auth::userId(), $dropId]);
    }
    redirect('/member/drops.php?' . http_build_query(array_intersect_key($_GET, array_flip(['q', 'min', 'date']))));
}

$latestDate = (string)($pdo->query('SELECT MAX(dropped_date) FROM drops')->fetchColumn() ?: '');
$dates = $pdo->query('SELECT DISTINCT dropped_date FROM drops ORDER BY dropped_date DESC LIMIT 14')
    ->fetchAll(PDO::FETCH_COLUMN);

$q    = trim((string)($_GET['q'] ?? ''));
$min  = (int)($_GET['min'] ?? 0);
$date = (string)($_GET['date'] ?? $latestDate);

$drops = [];
$total = 0;
if ($date !== '') {
    $where  = 'dropped_date = ?';
    $params = [$date];
    if ($q !== '') {
        $where   .= ' AND domain LIKE ?';
        $params[] = '%' . $q . '%';
    }
    if ($min > 0) {
        $where   .= ' AND score >= ?';
        $params[] = $min;
    }
    $count = $pdo->prepare("SELECT COUNT(*) FROM drops WHERE {$where}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $limit = $isPro ? 200 : 3;
    $stmt  = $pdo->prepare("SELECT * FROM drops WHERE {$where} ORDER BY score DESC LIMIT {$limit}");
    $stmt->execute($params);
    $drops = $stmt->fetchAll();
}

$favIds = [];
if ($drops) {
    $favStmt = $pdo->prepare('SELECT drop_id FROM favorites WHERE user_id = ?');
    $favStmt->execute([Auth::userId()]);
    $favIds = array_flip($favStmt->fetchAll(PDO::FETCH_COLUMN));
}

layout_header('Drop Board', 'member');
?>
<h1>Drop Board</h1>
<p class="sub">Every dropped name that matched the filter, rated and sorted. Favorite the ones you like before someone else registers them.</p>

<form class="filterrow" method="get">
    <select name="date">
        <?php foreach ($dates as $d): ?>
        <option value="<?= e($d) ?>" <?= $d === $date ? 'selected' : '' ?>><?= e($d) ?><?= $d === $latestDate ? ' (latest)' : '' ?></option>
        <?php endforeach; ?>
    </select>
    <input name="q" value="<?= e($q) ?>" placeholder="Contains…">
    <select name="min">
        <?php foreach ([0 => 'Any score', 50 => '50+', 65 => '65+', 75 => '75+ (hot)'] as $v => $label): ?>
        <option value="<?= $v ?>" <?= $min === $v ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filter</button>
</form>

<?php if (!$drops): ?>
    <div class="empty">Nothing here yet<?= $q !== '' || $min > 0 ? ' — try loosening the filter.' : '.' ?></div>
<?php else: ?>
    <p class="sub"><?= $total ?> drop(s)<?= !$isPro && $total > count($drops) ? ' — free plan shows the top ' . count($drops) : '' ?></p>
    <table>
        <tr><th></th><th>Domain</th><th>Score</th><th>Why</th><th>AI says</th><th>Est.</th><th>Availability</th></tr>
        <?php foreach ($drops as $d):
            $notes = json_decode((string)$d['score_notes'], true) ?: [];
            $isFav = isset($favIds[$d['id']]); ?>
        <tr>
            <td>
                <form class="inline" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="drop_id" value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="action" value="<?= $isFav ? 'unfav' : 'fav' ?>">
                    <button class="favbtn <?= $isFav ? 'on' : '' ?>" type="submit" title="<?= $isPro || $isFav ? 'Toggle favorite' : 'Favorites are a Pro feature' ?>" <?= $isPro || $isFav ? '' : 'disabled' ?>><?= $isFav ? '★' : '☆' ?></button>
                </form>
            </td>
            <td><strong><?= e($d['domain']) ?></strong></td>
            <td><span class="scorepill sc-<?= e(score_class((int)$d['score'])) ?>"><?= (int)$d['score'] ?></span></td>
            <td class="notes-cell"><?= e(implode(' · ', $notes)) ?></td>
            <td class="notes-cell"><?= $d['ai_comment'] ? e($d['ai_comment']) : '—' ?></td>
            <td><?= $d['est_value'] ? '~$' . number_format((float)$d['est_value']) : '—' ?></td>
            <td><?= $d['availability'] === 'available' ? '<span class="badge-st st-free">Available</span>'
                    : ($d['availability'] === 'registered' ? '<span class="badge-st st-taken">Taken</span>'
                    : '<span class="badge-st st-unknown">Unchecked</span>') ?>
                <?php if ($d['reg_price'] !== null): ?><span class="sub-inline">reg <?= e(money((float)$d['reg_price'])) ?></span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if (!$isPro && $total > count($drops)): ?>
        <div class="upsell">
            <p><strong><?= $total - count($drops) ?> more drops</strong> match — Pro members see the whole board, every day.</p>
            <a class="btn btn-primary" href="/member/account.php">Upgrade to Pro</a>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php
layout_footer();
