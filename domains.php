<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use Domainzs\Notifier;

// --- "Make an offer" form ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $listingId = (int)($_POST['listing_id'] ?? 0);
    $name      = trim((string)($_POST['name'] ?? ''));
    $email     = trim((string)($_POST['email'] ?? ''));
    $amount    = trim((string)($_POST['amount'] ?? ''));
    $message   = trim((string)($_POST['message'] ?? ''));

    $stmt = $pdo->prepare("SELECT * FROM listings WHERE id = ? AND status = 'active'");
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch();

    if (!$listing) {
        flash('error', 'That listing is no longer available.');
    } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please give your name and a valid email so we can reply.');
    } else {
        $amountCents = $amount === '' ? null : (int)round((float)$amount * 100);
        $pdo->prepare(
            'INSERT INTO offers (listing_id, name, email, amount_cents, message) VALUES (?, ?, ?, ?, ?)'
        )->execute([$listingId, mb_substr($name, 0, 120), mb_substr($email, 0, 190), $amountCents, mb_substr($message, 0, 1000)]);
        (new Notifier($config))->sendOfferAlert($listing['domain'], $name, $email, $amountCents, $message);
        flash('success', "Offer sent for {$listing['domain']} — we'll get back to you at {$email}.");
    }
    redirect('/domains.php');
}

$q    = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'score');
$orderBy = match ($sort) {
    'price_low'  => 'price_cents ASC',
    'price_high' => 'price_cents DESC',
    'newest'     => 'created_at DESC',
    default      => 'score DESC, price_cents DESC',
};

$params = [];
$where  = "status = 'active'";
if ($q !== '') {
    $where   .= ' AND domain LIKE ?';
    $params[] = '%' . $q . '%';
}
$stmt = $pdo->prepare("SELECT * FROM listings WHERE {$where} ORDER BY {$orderBy} LIMIT 200");
$stmt->execute($params);
$listings = $stmt->fetchAll();

layout_header('Domains for sale', 'public');
?>
<h1>Domains for sale</h1>
<p class="sub">Hand-picked from the daily drop lists, rated, and registered — ready to move to your registrar.</p>

<form class="searchbar" method="get">
    <input class="searchbar-input" name="q" value="<?= e($q) ?>" placeholder="Search names…">
    <select class="searchbar-select" name="sort">
        <option value="score" <?= $sort === 'score' ? 'selected' : '' ?>>Best rated</option>
        <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: low → high</option>
        <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: high → low</option>
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
    </select>
    <button class="btn-search" type="submit">Search</button>
</form>

<?php if (!$listings): ?>
    <div class="empty"><?= $q !== '' ? 'Nothing matches that search.' : 'No domains listed right now — check back soon.' ?></div>
<?php else: ?>
<div class="sale-grid">
    <?php foreach ($listings as $l): ?>
    <div class="sale-card" id="d<?= (int)$l['id'] ?>">
        <div class="sale-top">
            <span class="listing-domain"><?= e($l['domain']) ?></span>
            <?php if ($l['score'] !== null): ?><span class="scorepill sc-<?= e(score_class((int)$l['score'])) ?>" title="domainzs score"><?= (int)$l['score'] ?></span><?php endif; ?>
        </div>
        <?php if ($l['headline']): ?><p class="sale-head"><?= e($l['headline']) ?></p><?php endif; ?>
        <?php if ($l['description']): ?><p class="sale-desc"><?= e($l['description']) ?></p><?php endif; ?>
        <div class="sale-price"><?= money_cents((int)$l['price_cents']) ?></div>
        <details class="offer-box">
            <summary class="btn btn-primary">Buy / make an offer</summary>
            <form method="post" class="stack" style="margin-top:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="listing_id" value="<?= (int)$l['id'] ?>">
                <label>Your name</label>
                <input name="name" required maxlength="120">
                <label>Your email</label>
                <input name="email" type="email" required maxlength="190">
                <label>Offer (USD, optional — leave blank to pay asking price)</label>
                <input name="amount" type="number" min="1" step="1" placeholder="<?= (int)($l['price_cents'] / 100) ?>">
                <label>Message (optional)</label>
                <input name="message" maxlength="1000" placeholder="I'd like to buy this domain…">
                <button class="btn btn-primary" style="margin-top:12px" type="submit">Send offer</button>
            </form>
        </details>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
layout_footer();
