<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'update') {
        $domain = normalize_domain((string)($_POST['domain'] ?? ''));
        $price  = (int)round((float)($_POST['price'] ?? 0) * 100);
        if ($domain === null) {
            flash('error', 'That doesn\'t look like a valid domain name.');
        } elseif ($price <= 0) {
            flash('error', 'Set an asking price.');
        } elseif ($action === 'add') {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO listings (domain, price_cents, headline, description, score, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $domain, $price,
                trim((string)($_POST['headline'] ?? '')) ?: null,
                trim((string)($_POST['description'] ?? '')) ?: null,
                ($_POST['score'] ?? '') !== '' ? max(0, min(99, (int)$_POST['score'])) : null,
                in_array($_POST['status'] ?? '', ['active', 'sold', 'hidden'], true) ? $_POST['status'] : 'active',
            ]);
            flash($stmt->rowCount() ? 'success' : 'error',
                $stmt->rowCount() ? "{$domain} listed." : "{$domain} is already listed.");
        } else {
            $pdo->prepare(
                'UPDATE listings SET domain = ?, price_cents = ?, headline = ?, description = ?, score = ?, status = ?
                 WHERE id = ?'
            )->execute([
                $domain, $price,
                trim((string)($_POST['headline'] ?? '')) ?: null,
                trim((string)($_POST['description'] ?? '')) ?: null,
                ($_POST['score'] ?? '') !== '' ? max(0, min(99, (int)$_POST['score'])) : null,
                in_array($_POST['status'] ?? '', ['active', 'sold', 'hidden'], true) ? $_POST['status'] : 'active',
                (int)($_POST['id'] ?? 0),
            ]);
            flash('success', 'Listing updated.');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM listings WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Listing deleted (and its offers).');
    }
    redirect('/superadmin/listings.php');
}

$editId = (int)($_GET['edit'] ?? 0);
$edit   = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM listings WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

$listings = $pdo->query(
    'SELECT l.*, (SELECT COUNT(*) FROM offers o WHERE o.listing_id = l.id) AS offer_count
     FROM listings l ORDER BY FIELD(l.status, "active", "sold", "hidden"), l.created_at DESC'
)->fetchAll();

layout_header('Listings', 'admin');
?>
<h1>Listings</h1>
<p class="sub">Domains shown for sale on the public site. Push drops here with one click from the Drops page, or add any domain manually.</p>

<div class="card" style="margin-bottom:24px">
    <h2 style="margin-top:0"><?= $edit ? 'Edit ' . e($edit['domain']) : 'Add a listing' ?></h2>
    <form method="post" class="row-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="rf-grow">
            <label>Domain</label>
            <input name="domain" value="<?= e($edit['domain'] ?? '') ?>" placeholder="brandable.com" required>
        </div>
        <div>
            <label>Price (USD)</label>
            <input name="price" type="number" min="1" step="0.01" value="<?= $edit ? e(number_format($edit['price_cents'] / 100, 2, '.', '')) : '' ?>" required>
        </div>
        <div>
            <label>Score (0-99, optional)</label>
            <input name="score" type="number" min="0" max="99" value="<?= $edit && $edit['score'] !== null ? (int)$edit['score'] : '' ?>">
        </div>
        <div class="rf-grow">
            <label>Headline</label>
            <input name="headline" maxlength="190" value="<?= e($edit['headline'] ?? '') ?>" placeholder="Short, punchy pitch">
        </div>
        <div class="rf-grow">
            <label>Description</label>
            <input name="description" maxlength="500" value="<?= e($edit['description'] ?? '') ?>">
        </div>
        <div>
            <label>Status</label>
            <select name="status">
                <?php foreach (['active', 'sold', 'hidden'] as $s): ?>
                <option value="<?= $s ?>" <?= ($edit['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit"><?= $edit ? 'Save' : 'Add listing' ?></button>
        <?php if ($edit): ?><a class="btn" href="/superadmin/listings.php">Cancel</a><?php endif; ?>
    </form>
</div>

<?php if (!$listings): ?>
    <div class="empty">Nothing listed yet.</div>
<?php else: ?>
    <table>
        <tr><th>Domain</th><th>Price</th><th>Score</th><th>Status</th><th>Offers</th><th>Listed</th><th></th></tr>
        <?php foreach ($listings as $l): ?>
        <tr>
            <td><strong><?= e($l['domain']) ?></strong></td>
            <td><?= money_cents((int)$l['price_cents']) ?></td>
            <td><?= $l['score'] !== null ? (int)$l['score'] : '—' ?></td>
            <td><span class="badge-st <?= $l['status'] === 'active' ? 'st-free' : ($l['status'] === 'sold' ? 'st-drop' : 'st-taken') ?>"><?= e($l['status']) ?></span></td>
            <td><?= (int)$l['offer_count'] ?></td>
            <td class="notes-cell"><?= e(substr($l['created_at'], 0, 10)) ?></td>
            <td class="row-actions">
                <a class="btn btn-sm" href="?edit=<?= (int)$l['id'] ?>">Edit</a>
                <form class="inline" method="post" onsubmit="return confirm('Delete <?= e($l['domain']) ?> and its offers?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">✕</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php
layout_footer();
