<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;
use Domainzs\DropEngine;
use Domainzs\Notifier;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'fetch') {
        $date  = (string)($_POST['date'] ?? '') ?: date('Y-m-d');
        $stats = (new DropEngine($pdo, $config))->run($date);
        $top   = $pdo->prepare('SELECT domain, score FROM drops WHERE dropped_date = ? ORDER BY score DESC LIMIT 5');
        $top->execute([$date]);
        (new Notifier($config))->sendFetchDigest($date, $stats, $top->fetchAll());
        if (!empty($stats['error'])) {
            flash('error', "Fetch problem for {$date}: {$stats['error']}");
        }
        flash($stats['added'] > 0 ? 'success' : 'info',
            "Fetched {$date}: {$stats['raw']} in feed → {$stats['matched']} matched filter → "
            . "{$stats['added']} new · {$stats['verified']} availability-verified · "
            . "{$stats['moz_rated']} Moz-rated · {$stats['ai_rated']} AI-rated.");
        // Land on the batch that was just fetched, not the default listing.
        redirect('/superadmin/drops.php?date=' . urlencode($date));
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM drops WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Drop deleted.');
    } elseif ($action === 'delete_all') {
        // Wipe the whole drops table (e.g. clearing mock data before going live).
        $count = (int)$pdo->query('SELECT COUNT(*) FROM drops')->fetchColumn();
        $pdo->exec('DELETE FROM drops');
        flash('success', "Deleted all {$count} drops. Favorites on them were removed too.");
    } elseif ($action === 'delete_batch') {
        // Wipe a whole day's batch (e.g. to clear out mock sample data).
        $batchDate = (string)($_POST['batch_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDate)) {
            $stmt = $pdo->prepare('DELETE FROM drops WHERE dropped_date = ?');
            $stmt->execute([$batchDate]);
            flash('success', "Deleted the {$batchDate} batch ({$stmt->rowCount()} drops).");
        } else {
            flash('error', 'Pick a batch date to delete.');
        }
    } elseif ($action === 'list') {
        // Push a drop to the public marketplace with a score-based asking price.
        $stmt = $pdo->prepare('SELECT * FROM drops WHERE id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        if ($d = $stmt->fetch()) {
            $price = $d['est_value'] ? (int)$d['est_value'] * 100 : max(4900, (int)$d['score'] * 4000);
            $ins = $pdo->prepare(
                'INSERT IGNORE INTO listings (domain, price_cents, headline, score) VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$d['domain'], $price, $d['ai_comment'], (int)$d['score']]);
            flash($ins->rowCount() ? 'success' : 'error', $ins->rowCount()
                ? "{$d['domain']} listed for sale at " . money_cents($price) . ' — edit it under Listings.'
                : "{$d['domain']} is already listed.");
        }
    }
    redirect('/superadmin/drops.php');
}

$q     = trim((string)($_GET['q'] ?? ''));
$date  = (string)($_GET['date'] ?? '');
$len   = (int)($_GET['len'] ?? 0);
$min   = (int)($_GET['min'] ?? 0);
$avail = (string)($_GET['avail'] ?? '');
$sort  = (string)($_GET['sort'] ?? 'len');

$dates = $pdo->query('SELECT DISTINCT dropped_date FROM drops ORDER BY dropped_date DESC LIMIT 30')
    ->fetchAll(PDO::FETCH_COLUMN);

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

$orderBy = match ($sort) {
    'score'   => 'score DESC, dropped_date DESC',
    'newest'  => 'dropped_date DESC, score DESC',
    'oldest'  => 'dropped_date ASC, score DESC',
    'az'      => 'sld ASC',
    'da'      => 'moz_da DESC, score DESC',
    default   => 'len ASC, score DESC',   // shortest first, best score within a length
};

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM drops WHERE {$where}");
$countStmt->execute($params);
$totalMatching = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM drops WHERE {$where} ORDER BY {$orderBy} LIMIT 200");
$stmt->execute($params);
$drops = $stmt->fetchAll();

$dropsCfg = drops_config($config);

layout_header('Drops', 'admin');
?>
<?php
$extraFilters = [];
if (!empty($dropsCfg['no_hyphens'])) { $extraFilters[] = 'no hyphens'; }
if (!empty($dropsCfg['no_digits']))  { $extraFilters[] = 'no digits'; }
?>
<h1>Drops</h1>
<p class="sub">Filter: <strong><?= (int)$dropsCfg['min_len'] === (int)$dropsCfg['max_len']
    ? (int)$dropsCfg['min_len'] . '-character'
    : (int)$dropsCfg['min_len'] . '–' . (int)$dropsCfg['max_len'] . ' character' ?></strong> SLDs on
<strong>.<?= e(str_replace(',', ', .', $dropsCfg['tlds'])) ?></strong><?php
if ($extraFilters): ?>, <strong><?= e(implode(', ', $extraFilters)) ?></strong><?php endif; ?> — change it in
<a href="/superadmin/settings.php">Settings</a>. Cron runs <code>bin/fetch.php</code> daily; you can also fetch on demand.</p>

<div class="scanpanel">
    <form class="inline-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="fetch">
        <div class="scanpanel-field">
            <label for="fdate">Drop date</label>
            <input id="fdate" name="date" type="date"
                   value="<?= e(date('Y-m-d', time() - max(0, (int)$dropsCfg['day_offset']) * 86400)) ?>">
        </div>
        <button class="btn btn-scan" type="submit">📡 Fetch &amp; rate now</button>
    </form>
</div>

<form class="searchbar" method="get" action="/superadmin/drops.php">
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
        <option value="<?= e($d) ?>" <?= $d === $date ? 'selected' : '' ?>><?= e($d) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="searchbar-select" name="min" title="Minimum score">
        <?php foreach ([0 => 'Any score', 50 => '50+', 65 => '65+', 75 => '75+ (hot)', 85 => '85+ (🔥)'] as $v => $lbl): ?>
        <option value="<?= $v ?>" <?= $min === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="searchbar-select" name="avail" title="Availability">
        <?php foreach (['' => 'Any status', 'available' => '✅ Available', 'registered' => '❌ Taken', 'unknown' => 'Unchecked'] as $v => $lbl): ?>
        <option value="<?= e($v) ?>" <?= $avail === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="searchbar-select" name="sort" title="Sort by">
        <?php foreach (['len' => 'Shortest first', 'score' => 'Best score', 'newest' => 'Newest', 'oldest' => 'Oldest', 'az' => 'A → Z', 'da' => 'Domain Authority'] as $v => $lbl): ?>
        <option value="<?= e($v) ?>" <?= $sort === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn-search" type="submit">Search</button>
    <a class="btn btn-reset" href="/superadmin/drops.php">Reset</a>
</form>
<?php if ($date !== ''): ?>
<form class="inline" method="post" style="margin:-8px 0 18px;display:block"
      onsubmit="return confirm('Delete ALL drops from the <?= e($date) ?> batch? Members\' favorites on them are removed too.')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_batch">
    <input type="hidden" name="batch_date" value="<?= e($date) ?>">
    <button class="btn btn-sm btn-danger" type="submit">🗑 Delete entire <?= e($date) ?> batch</button>
</form>
<?php elseif ($drops): ?>
<form class="inline" method="post" style="margin:-8px 0 18px;display:block"
      onsubmit="return confirm('Delete EVERY drop in the database? Use this to clear mock/sample data. Members\' favorites are removed too. This cannot be undone.')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_all">
    <button class="btn btn-sm btn-danger" type="submit">🗑 Delete ALL drops</button>
</form>
<?php endif; ?>

<?php if (!$drops): ?>
    <div class="empty">No drops match. Run a fetch above to pull the latest list.</div>
<?php else: ?>
    <p class="sub" style="margin-bottom:10px"><?= $totalMatching ?> drop(s)<?=
        $totalMatching > count($drops) ? ' — showing the top ' . count($drops) . ' by date &amp; score; use the date filter to see a specific batch' : '' ?></p>
    <table>
        <tr><th>Domain</th><th>Len</th><th>Dropped</th><th>Score</th><th>Why</th><th>DA</th><th>Links</th><th>AI</th><th>Est.</th><th>Avail.</th><th></th></tr>
        <?php foreach ($drops as $d):
            $notes = json_decode((string)$d['score_notes'], true) ?: []; ?>
        <tr>
            <td><strong><?= e($d['domain']) ?></strong></td>
            <td><span class="lenpill <?= e(length_class((int)$d['len'])) ?>"><?= (int)$d['len'] ?></span></td>
            <td><?= e($d['dropped_date']) ?></td>
            <td><span class="scorepill sc-<?= e(score_class((int)$d['score'])) ?>"><?= (int)$d['score'] ?></span></td>
            <td class="notes-cell"><?= e(implode(' · ', array_slice($notes, 0, 3))) ?></td>
            <td><?= ($d['moz_da'] ?? null) !== null ? '<strong>' . (int)$d['moz_da'] . '</strong>' : '—' ?></td>
            <td><?= ($d['moz_links'] ?? null) !== null ? number_format((float)$d['moz_links']) : '—' ?></td>
            <td><?= $d['ai_rating'] !== null ? (int)$d['ai_rating'] : '—' ?></td>
            <td><?= $d['est_value'] ? '~$' . number_format((float)$d['est_value']) : '—' ?></td>
            <td><?= $d['availability'] === 'available' ? '✅' : ($d['availability'] === 'registered' ? '❌' : '—') ?><?php
                if (($d['reg_price'] ?? null) !== null): ?> <span class="sub-inline"><?= e(money((float)$d['reg_price'])) ?></span><?php endif; ?></td>
            <td class="row-actions">
                <form class="inline" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="list">
                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-sm" type="submit" title="Push to the public marketplace">💰 List</button>
                </form>
                <form class="inline" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">✕</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php
layout_footer();
