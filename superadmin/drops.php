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
        flash('success', "Fetched {$date}: {$stats['raw']} in feed → {$stats['matched']} matched filter → "
            . "{$stats['added']} new · {$stats['verified']} availability-verified · {$stats['ai_rated']} AI-rated.");
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM drops WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Drop deleted.');
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

$q    = trim((string)($_GET['q'] ?? ''));
$date = (string)($_GET['date'] ?? '');
$dates = $pdo->query('SELECT DISTINCT dropped_date FROM drops ORDER BY dropped_date DESC LIMIT 30')
    ->fetchAll(PDO::FETCH_COLUMN);

$where  = '1=1';
$params = [];
if ($date !== '') {
    $where   .= ' AND dropped_date = ?';
    $params[] = $date;
}
if ($q !== '') {
    $where   .= ' AND domain LIKE ?';
    $params[] = '%' . $q . '%';
}
$stmt = $pdo->prepare("SELECT * FROM drops WHERE {$where} ORDER BY dropped_date DESC, score DESC LIMIT 200");
$stmt->execute($params);
$drops = $stmt->fetchAll();

$dropsCfg = drops_config($config);

layout_header('Drops', 'admin');
?>
<h1>Drops</h1>
<p class="sub">Filter: exactly <strong><?= (int)$dropsCfg['exact_len'] ?></strong>-character SLDs on
<strong>.<?= e(str_replace(',', ', .', $dropsCfg['tlds'])) ?></strong> — change it in
<a href="/superadmin/settings.php">Settings</a>. Cron runs <code>bin/fetch.php</code> daily; you can also fetch on demand.</p>

<div class="scanpanel">
    <form class="inline-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="fetch">
        <div class="scanpanel-field">
            <label for="fdate">Drop date</label>
            <input id="fdate" name="date" type="date" value="<?= e(date('Y-m-d')) ?>">
        </div>
        <button class="btn btn-scan" type="submit">📡 Fetch &amp; rate now</button>
    </form>
</div>

<form class="filterrow" method="get">
    <select name="date">
        <option value="">All dates</option>
        <?php foreach ($dates as $d): ?>
        <option value="<?= e($d) ?>" <?= $d === $date ? 'selected' : '' ?>><?= e($d) ?></option>
        <?php endforeach; ?>
    </select>
    <input name="q" value="<?= e($q) ?>" placeholder="Contains…">
    <button class="btn" type="submit">Filter</button>
</form>

<?php if (!$drops): ?>
    <div class="empty">No drops match. Run a fetch above to pull the latest list.</div>
<?php else: ?>
    <table>
        <tr><th>Domain</th><th>Dropped</th><th>Score</th><th>Why</th><th>AI</th><th>Est.</th><th>Avail.</th><th></th></tr>
        <?php foreach ($drops as $d):
            $notes = json_decode((string)$d['score_notes'], true) ?: []; ?>
        <tr>
            <td><strong><?= e($d['domain']) ?></strong></td>
            <td><?= e($d['dropped_date']) ?></td>
            <td><span class="scorepill sc-<?= e(score_class((int)$d['score'])) ?>"><?= (int)$d['score'] ?></span></td>
            <td class="notes-cell"><?= e(implode(' · ', array_slice($notes, 0, 3))) ?></td>
            <td><?= $d['ai_rating'] !== null ? (int)$d['ai_rating'] : '—' ?></td>
            <td><?= $d['est_value'] ? '~$' . number_format((float)$d['est_value']) : '—' ?></td>
            <td><?= $d['availability'] === 'available' ? '✅' : ($d['availability'] === 'registered' ? '❌' : '—') ?><?php
                if ($d['reg_price'] !== null): ?> <span class="sub-inline"><?= e(money((float)$d['reg_price'])) ?></span><?php endif; ?></td>
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
