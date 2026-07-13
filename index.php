<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

$featured = $pdo->query(
    "SELECT * FROM listings WHERE status = 'active' ORDER BY score DESC, price_cents DESC LIMIT 6"
)->fetchAll();
$plans = $pdo->query('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort, price_cents')->fetchAll();

$dropCount = (int)$pdo->query('SELECT COUNT(*) FROM drops')->fetchColumn();
$topToday  = (int)$pdo->query(
    "SELECT COUNT(*) FROM drops WHERE dropped_date = (SELECT MAX(dropped_date) FROM drops) AND score >= 70"
)->fetchColumn();

layout_header(setting('hero_title', 'Rated dropped domains, every day'), 'public');
?>
<section class="hero">
    <h1><?= e(setting('hero_title', 'The best dropped 9-letter .coms — found and rated for you, daily.')) ?></h1>
    <p class="hero-sub"><?= e(setting('hero_subtitle', 'domainzs pulls every freshly dropped domain, keeps the 9-character .coms, and scores each one for brandability and resale value — so you only look at names worth registering.')) ?></p>
    <div class="hero-cta">
        <a class="btn btn-primary btn-lg" href="/signup.php">Join free — see today's drops</a>
        <a class="btn btn-lg" href="/domains.php">Browse domains for sale</a>
    </div>
    <p class="hero-note"><?= number_format($dropCount) ?> drops rated so far<?= $topToday > 0 ? ' · ' . $topToday . ' hot names in the latest batch' : '' ?></p>
</section>

<section class="features">
    <div class="feature"><span class="fi">📡</span><h3>Every drop, every day</h3>
        <p>We ingest the full daily list of deleted domains and keep exactly what you hunt: 9-character .coms.</p></div>
    <div class="feature"><span class="fi">🧠</span><h3>Rated, not raw</h3>
        <p>Each name is scored 0–99 for pronounceability, real words, and brandable patterns — with an AI second opinion on the best.</p></div>
    <div class="feature"><span class="fi">💎</span><h3>Registered-and-ready deals</h3>
        <p>The keepers we register ourselves go straight to the marketplace — browse and make an offer.</p></div>
</section>

<?php if ($featured): ?>
<h2 style="text-align:center">Fresh on the marketplace</h2>
<div class="listing-grid">
    <?php foreach ($featured as $l): ?>
    <a class="listing" href="/domains.php#d<?= (int)$l['id'] ?>">
        <span class="listing-domain"><?= e($l['domain']) ?></span>
        <?php if ($l['headline']): ?><span class="listing-head"><?= e($l['headline']) ?></span><?php endif; ?>
        <span class="listing-price"><?= money_cents((int)$l['price_cents']) ?></span>
        <?php if ($l['score'] !== null): ?><span class="scorepill sc-<?= e(score_class((int)$l['score'])) ?>"><?= (int)$l['score'] ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<p style="text-align:center;margin-top:16px"><a href="/domains.php">See everything for sale →</a></p>
<?php endif; ?>

<section class="pricing" id="pricing">
    <h2>Pricing</h2>
    <div class="plan-grid">
        <?php foreach ($plans as $i => $p): ?>
        <div class="plan <?= $p['price_cents'] > 0 ? 'plan-featured' : '' ?>">
            <h3><?= e($p['name']) ?></h3>
            <div class="plan-price"><?= money_cents((int)$p['price_cents']) ?><span>/<?= e($p['bill_interval']) ?></span></div>
            <p class="plan-blurb"><?= e($p['blurb']) ?></p>
            <ul class="plan-features">
                <?php foreach (array_filter(array_map('trim', explode("\n", (string)$p['features']))) as $f): ?>
                <li><?= e($f) ?></li>
                <?php endforeach; ?>
            </ul>
            <a class="btn <?= $p['price_cents'] > 0 ? 'btn-primary' : '' ?>" href="/signup.php?plan=<?= e($p['slug']) ?>">
                <?= $p['price_cents'] > 0 ? 'Get ' . e($p['name']) : 'Start free' ?>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php
layout_footer();
