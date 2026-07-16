<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;
use Domainzs\DailyRecap;

Auth::requireAdmin();

$engine = new DailyRecap($pdo, $config);

// Available recap dates = distinct drop dates.
$dates = $pdo->query('SELECT DISTINCT dropped_date FROM drops ORDER BY dropped_date DESC LIMIT 30')
    ->fetchAll(PDO::FETCH_COLUMN);
$latest = $dates[0] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'context') {
        set_setting('recap_context', trim((string)($_POST['recap_context'] ?? '')));
        flash('success', 'Saved your context — regenerate a recap to use it.');
        redirect('/superadmin/dailyrecap.php?date=' . urlencode((string)($_POST['date'] ?? $latest)));
    }
    $date = (string)($_POST['date'] ?? $latest);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        @set_time_limit(300);
        $r = $engine->generate($date);
        flash($r ? 'success' : 'error', $r
            ? 'Recap generated (' . ($r['is_ai'] ? 'AI' : 'heuristic') . ') over ' . $r['drop_count'] . ' names.'
            : 'No drops for that date to recap.');
    }
    redirect('/superadmin/dailyrecap.php?date=' . urlencode($date));
}

$date  = (string)($_GET['date'] ?? $latest);
$recap = $date !== '' ? $engine->forDate($date) : null;
$b     = $recap['body'] ?? [];

$stars = fn (int $n): string => str_repeat('★', max(0, min(5, $n))) . str_repeat('☆', 5 - max(0, min(5, $n)));

layout_header('Daily Recap', 'admin');
?>
<h1>📊 Daily Recap</h1>
<p class="sub">An AI deep-dive on the day's dropped names — the standout pick, a ranked top 10, an overlooked sleeper,
and a build-a-business angle. Runs automatically after each daily fetch; regenerate any day here.</p>

<div class="scanpanel">
    <form class="inline-form" method="get">
        <div class="scanpanel-field">
            <label for="date">Recap date</label>
            <select id="date" name="date" onchange="this.form.submit()">
                <?php foreach ($dates as $d): ?>
                <option value="<?= e($d) ?>" <?= $d === $date ? 'selected' : '' ?>><?= e($d) ?><?= $d === $latest ? ' (latest)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <form class="inline-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <button class="btn btn-scan" type="submit"><?= $recap ? '♻️ Regenerate' : '✨ Generate recap' ?></button>
    </form>
</div>

<?php if (!$dates): ?>
    <div class="empty">No drops yet — fetch a batch on the <a href="/superadmin/drops.php">Drops</a> page first.</div>
<?php elseif (!$recap): ?>
    <div class="empty">No recap for <?= e($date) ?> yet — click <strong>Generate recap</strong> above.</div>
<?php else: ?>

<?php if (!$recap['is_ai']): ?>
<div class="mock-note">Heuristic recap (no Anthropic API key set). Add one in
<a href="/superadmin/settings.php">Settings → AI</a> for a real deep-dive, then Regenerate.</div>
<?php endif; ?>

<?php if (!empty($b['intro'])): ?><p class="recap-intro"><?= e($b['intro']) ?></p><?php endif; ?>

<?php if (!empty($b['top_pick']['domain'])): $p = $b['top_pick']; ?>
<div class="panel recap-hero">
    <div class="recap-hero-head">
        <span class="recap-medal">🥇</span>
        <div>
            <div class="recap-hero-domain"><?= e($p['domain']) ?></div>
            <div class="recap-stars"><?= e($stars((int)($p['stars'] ?? 0))) ?></div>
        </div>
    </div>
    <?php if (!empty($p['why'])): ?>
    <h3>Why it wins</h3>
    <ul class="recap-list"><?php foreach ((array)$p['why'] as $w): ?><li><?= e((string)$w) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if (!empty($p['positioning'])): ?>
    <h3>Positioning</h3>
    <div class="recap-chips"><?php foreach ((array)$p['positioning'] as $pos): ?><span class="chip"><?= e((string)$pos) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="recap-resale">
        <div><span class="rr-label">Wholesale</span><span class="rr-val"><?= e((string)($p['resale_wholesale'] ?? '—')) ?></span></div>
        <div><span class="rr-label">End user</span><span class="rr-val rr-hi"><?= e((string)($p['resale_enduser'] ?? '—')) ?></span></div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($b['top10'])): ?>
<div class="panel">
    <h2 style="margin-top:0">🏆 Top 10</h2>
    <ol class="recap-top10">
        <?php foreach ((array)$b['top10'] as $i => $t): ?>
        <li>
            <span class="rt-rank"><?= $i + 1 ?></span>
            <div class="rt-body">
                <div class="rt-line">
                    <strong class="rt-domain"><?= e((string)($t['domain'] ?? '')) ?></strong>
                    <span class="rt-stars"><?= e($stars((int)($t['stars'] ?? 0))) ?></span>
                </div>
                <?php if (!empty($t['note'])): ?><div class="rt-note"><?= e((string)$t['note']) ?></div><?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>
</div>
<?php endif; ?>

<?php if (!empty($b['sleeper']['domain'])): ?>
<div class="panel">
    <h2 style="margin-top:0">💤 The sleeper</h2>
    <div class="recap-domain-lg"><?= e((string)$b['sleeper']['domain']) ?></div>
    <p class="recap-under"><?= e((string)($b['sleeper']['why'] ?? '')) ?></p>
</div>
<?php endif; ?>

<?php if (!empty($b['builder_pick']['domain'])): $bp = $b['builder_pick']; ?>
<div class="panel">
    <h2 style="margin-top:0">🚀 Best to build on</h2>
    <div class="recap-domain-lg"><?= e((string)$bp['domain']) ?></div>
    <?php if (!empty($bp['why'])): ?>
    <ul class="recap-list recap-under"><?php foreach ((array)$bp['why'] as $w): ?><li><?= e((string)$w) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if (!empty($bp['business_ideas'])): ?>
    <div class="recap-under"><span class="recap-sublabel">Business ideas</span>
    <div class="recap-chips"><?php foreach ((array)$bp['business_ideas'] as $idea): ?><span class="chip"><?= e((string)$idea) ?></span><?php endforeach; ?></div></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($b['verdict'])): ?>
<div class="panel recap-verdict"><h2 style="margin-top:0">✅ Verdict</h2><p><?= e((string)$b['verdict']) ?></p></div>
<?php endif; ?>

<?php endif; ?>

<details class="recap-context">
    <summary>⚙️ Personalise the recap (optional)</summary>
    <form method="post" class="stack" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="context">
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <label>About you / your business — the AI weaves this into the "build a business" pick</label>
        <textarea name="recap_context" rows="3" placeholder="e.g. I run SearchMonster, focused on AI search, SEO and marketing tools…"><?= e((string) setting('recap_context', '')) ?></textarea>
        <button class="btn btn-primary" style="margin-top:12px" type="submit">Save context</button>
    </form>
</details>
<?php
layout_footer();
