<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;
use Domainzs\Compounds;

Auth::requireAdmin();

$len  = (int)($_GET['len'] ?? 0);              // 0 = all (5–9), else exact
$q    = trim((string)($_GET['q'] ?? ''));
$date = (string)($_GET['date'] ?? '');
$min  = (int)($_GET['min'] ?? 0);
$sort = (string)($_GET['sort'] ?? 'balance');

$dates = $pdo->query('SELECT DISTINCT dropped_date FROM drops ORDER BY dropped_date DESC LIMIT 30')
    ->fetchAll(PDO::FETCH_COLUMN);

$rows = (new Compounds($pdo))->find([
    'len' => $len, 'q' => $q, 'date' => $date, 'min' => $min, 'sort' => $sort,
]);
$shown = array_slice($rows, 0, 300);

layout_header('Word Pairs', 'admin');
?>
<h1>Word Pairs</h1>
<p class="sub">Two-word .com names — an SLD that's <strong>two real words</strong> stuck together
(<em>volt&nbsp;+&nbsp;get</em>, <em>jeep&nbsp;+&nbsp;pup</em>). These read as brandable and pronounceable,
which is what makes short compounds resell. Scanning <strong>5–9 character</strong> drops; both halves
must be dictionary words.</p>

<div class="lentabs">
    <?php
    $tabs = [0 => 'All 5–9', 5 => '5 chars', 6 => '6 chars', 7 => '7 chars', 8 => '8 chars', 9 => '9 chars'];
    foreach ($tabs as $v => $lbl):
        $qs = http_build_query(array_filter(['len' => $v ?: null, 'q' => $q, 'date' => $date, 'min' => $min ?: null, 'sort' => $sort !== 'balance' ? $sort : null]));
    ?>
    <a class="lentab <?= $len === $v ? 'active' : '' ?>" href="/superadmin/compounds.php<?= $qs ? '?' . e($qs) : '' ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
</div>

<form class="searchbar" method="get" action="/superadmin/compounds.php">
    <input type="hidden" name="len" value="<?= (int)$len ?>">
    <input class="searchbar-input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search names — contains…">
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
    <select class="searchbar-select" name="sort" title="Sort by">
        <?php foreach (['balance' => 'Most balanced', 'score' => 'Best score', 'len' => 'Shortest first', 'az' => 'A → Z'] as $v => $lbl): ?>
        <option value="<?= e($v) ?>" <?= $sort === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn-search" type="submit">Search</button>
    <a class="btn btn-reset" href="/superadmin/compounds.php">Reset</a>
</form>

<?php if (!$shown): ?>
    <div class="empty">No two-word names found in this range yet. Run a fetch on the
    <a href="/superadmin/drops.php">Drops</a> page to pull more names, or widen the filters.</div>
<?php else: ?>
    <p class="sub" style="margin-bottom:10px"><?= count($rows) ?> two-word name(s)<?=
        count($rows) > count($shown) ? ' — showing the top ' . count($shown) : '' ?>.</p>
    <table>
        <tr><th>Domain</th><th>Word pair</th><th>Len</th><th>Score</th><th>AI</th><th>Est.</th><th>Avail.</th></tr>
        <?php foreach ($shown as $d): ?>
        <tr>
            <td><strong><?= e($d['domain']) ?></strong></td>
            <td><span class="wordpair"><?= e($d['word_a']) ?></span><span class="wp-plus">+</span><span class="wordpair"><?= e($d['word_b']) ?></span></td>
            <td><span class="lenpill <?= e(length_class((int)$d['len'])) ?>"><?= (int)$d['len'] ?></span></td>
            <td><span class="scorepill sc-<?= e(score_class((int)$d['score'])) ?>"><?= (int)$d['score'] ?></span></td>
            <td><?= $d['ai_rating'] !== null ? (int)$d['ai_rating'] : '—' ?></td>
            <td><?= $d['est_value'] ? '~$' . number_format((float)$d['est_value']) : '—' ?></td>
            <td><?= $d['availability'] === 'available' ? '✅' : ($d['availability'] === 'registered' ? '❌' : '—') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php
layout_footer();
