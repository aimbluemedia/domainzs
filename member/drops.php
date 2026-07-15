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
    redirect('/member/drops.php?' . http_build_query(array_intersect_key($_GET, array_flip(['q', 'min', 'len', 'avail', 'sort', 'date']))));
}

$latestDate = (string)($pdo->query('SELECT MAX(dropped_date) FROM drops')->fetchColumn() ?: '');
$dates = $pdo->query('SELECT DISTINCT dropped_date FROM drops ORDER BY dropped_date DESC LIMIT 14')
    ->fetchAll(PDO::FETCH_COLUMN);

$q     = trim((string)($_GET['q'] ?? ''));
$min   = (int)($_GET['min'] ?? 0);
$len   = (int)($_GET['len'] ?? 0);
$avail = (string)($_GET['avail'] ?? '');
$sort  = (string)($_GET['sort'] ?? 'score');
// "All dates" is an explicit empty value; otherwise default to the latest batch.
$date  = isset($_GET['date']) ? (string)$_GET['date'] : $latestDate;

$orderBy = match ($sort) {
    'newest' => 'dropped_date DESC, score DESC',
    'az'     => 'sld ASC',
    'da'     => 'moz_da DESC, score DESC',
    default  => 'score DESC, dropped_date DESC',
};

$drops = [];
$total = 0;
if ($dates) {
    $where  = '1=1';
    $params = [];
    if ($date !== '') {
        $where   .= ' AND dropped_date = ?';
        $params[] = $date;
    }
    if ($q !== '') {
        $where   .= ' AND sld LIKE ?';
        $params[] = '%' . $q . '%';
    }
    if ($len > 0) {
        $where   .= ' AND len = ?';
        $params[] = $len;
    }
    if ($min > 0) {
        $where   .= ' AND score >= ?';
        $params[] = $min;
    }
    if (in_array($avail, ['available', 'registered', 'unknown'], true)) {
        $where   .= ' AND availability = ?';
        $params[] = $avail;
    }
    $count = $pdo->prepare("SELECT COUNT(*) FROM drops WHERE {$where}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $limit = $isPro ? 200 : 3;
    $stmt  = $pdo->prepare("SELECT * FROM drops WHERE {$where} ORDER BY {$orderBy} LIMIT {$limit}");
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

<form class="searchbar" method="get" action="/member/drops.php">
    <input class="searchbar-input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search names — contains…">
    <select class="searchbar-select" name="len" title="Name length">
        <option value="0">Any length</option>
        <?php foreach (range(3, 9) as $l): ?>
        <option value="<?= $l ?>" <?= $len === $l ? 'selected' : '' ?>><?= $l ?> characters</option>
        <?php endforeach; ?>
    </select>
    <select class="searchbar-select" name="date" title="Drop date">
        <option value="">All dates</option>
        <?php foreach ($dates as $d): ?>
        <option value="<?= e($d) ?>" <?= $d === $date ? 'selected' : '' ?>><?= e($d) ?><?= $d === $latestDate ? ' (latest)' : '' ?></option>
        <?php endforeach; ?>
    </select>
    <select class="searchbar-select" name="min" title="Minimum score">
        <?php foreach ([0 => 'Any score', 50 => '50+', 65 => '65+', 75 => '75+ (hot)', 85 => '85+ (🔥)'] as $v => $label): ?>
        <option value="<?= $v ?>" <?= $min === $v ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="searchbar-select" name="avail" title="Availability">
        <?php foreach (['' => 'Any status', 'available' => '✅ Available', 'registered' => '❌ Taken', 'unknown' => 'Unchecked'] as $v => $label): ?>
        <option value="<?= e($v) ?>" <?= $avail === $v ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="searchbar-select" name="sort" title="Sort by">
        <?php foreach (['score' => 'Best score', 'newest' => 'Newest', 'az' => 'A → Z', 'da' => 'Domain Authority'] as $v => $label): ?>
        <option value="<?= e($v) ?>" <?= $sort === $v ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn-search" type="submit">Search</button>
    <a class="btn btn-reset" href="/member/drops.php">Reset</a>
</form>

<?php if (!$drops): ?>
    <div class="empty">Nothing here yet<?= $q !== '' || $min > 0 ? ' — try loosening the filter.' : '.' ?></div>
<?php else: ?>
    <p class="sub"><?= $total ?> drop(s)<?= !$isPro && $total > count($drops) ? ' — free plan shows the top ' . count($drops) : '' ?></p>
    <table>
        <tr><th></th><th>Domain</th><th>Score</th><th>Why</th><th>DA</th><th>Links</th><th>AI says</th><th>Est.</th><th>Availability</th></tr>
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
            <td><?= ($d['moz_da'] ?? null) !== null ? '<strong>' . (int)$d['moz_da'] . '</strong>' : '—' ?></td>
            <td><?= ($d['moz_links'] ?? null) !== null ? number_format((float)$d['moz_links']) : '—' ?></td>
            <td class="notes-cell"><?= $d['ai_comment'] ? e($d['ai_comment']) : '—' ?></td>
            <td><?= $d['est_value'] ? '~$' . number_format((float)$d['est_value']) : '—' ?></td>
            <td><?= $d['availability'] === 'available' ? '<span class="badge-st st-free">Available</span>'
                    : ($d['availability'] === 'registered' ? '<span class="badge-st st-taken">Taken</span>'
                    : '<span class="badge-st st-unknown">Unchecked</span>') ?>
                <?php if (($d['reg_price'] ?? null) !== null): ?><span class="sub-inline">reg <?= e(money((float)$d['reg_price'])) ?></span><?php endif; ?></td>
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
