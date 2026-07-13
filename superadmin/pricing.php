<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim((string)($_POST['name'] ?? ''));
        $price    = (int)round((float)($_POST['price'] ?? 0) * 100);
        $interval = in_array($_POST['bill_interval'] ?? '', ['month', 'year'], true) ? $_POST['bill_interval'] : 'month';
        $blurb    = trim((string)($_POST['blurb'] ?? ''));
        $features = trim((string)($_POST['features'] ?? ''));
        $sort     = (int)($_POST['sort'] ?? 0);
        if ($name === '') {
            flash('error', 'Plan name is required.');
        } elseif ($id > 0) {
            $pdo->prepare(
                'UPDATE plans SET name = ?, price_cents = ?, bill_interval = ?, blurb = ?, features = ?, sort = ? WHERE id = ?'
            )->execute([$name, $price, $interval, $blurb, $features, $sort, $id]);
            flash('success', 'Plan updated.');
        } else {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'plan');
            $pdo->prepare(
                'INSERT INTO plans (name, slug, price_cents, bill_interval, blurb, features, sort) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$name, trim($slug, '-') ?: 'plan-' . time(), $price, $interval, $blurb, $features, $sort]);
            flash('success', 'Plan created.');
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE plans SET is_active = 1 - is_active WHERE id = ?')
            ->execute([(int)($_POST['id'] ?? 0)]);
    }
    redirect('/superadmin/pricing.php');
}

$plans  = $pdo->query('SELECT * FROM plans ORDER BY sort, price_cents')->fetchAll();
$editId = (int)($_GET['edit'] ?? 0);
$edit   = null;
foreach ($plans as $p) {
    if ((int)$p['id'] === $editId) {
        $edit = $p;
    }
}

layout_header('Pricing', 'admin');
?>
<h1>Pricing</h1>
<p class="sub">The plans shown on the public homepage. Free plan = top 3 drops per day; paid plans unlock the full board.</p>

<div class="card" style="margin-bottom:24px">
    <h2 style="margin-top:0"><?= $edit ? 'Edit ' . e($edit['name']) : 'Add a plan' ?></h2>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="row">
            <div><label>Name</label><input name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
            <div><label>Price (USD)</label><input name="price" type="number" min="0" step="0.01" value="<?= $edit ? e(number_format($edit['price_cents'] / 100, 2, '.', '')) : '' ?>" required></div>
            <div><label>Billing</label>
                <select name="bill_interval">
                    <option value="month" <?= ($edit['bill_interval'] ?? '') === 'month' ? 'selected' : '' ?>>monthly</option>
                    <option value="year" <?= ($edit['bill_interval'] ?? '') === 'year' ? 'selected' : '' ?>>yearly</option>
                </select>
            </div>
            <div><label>Sort</label><input name="sort" type="number" value="<?= (int)($edit['sort'] ?? 0) ?>"></div>
        </div>
        <label>Blurb</label>
        <input name="blurb" maxlength="190" value="<?= e($edit['blurb'] ?? '') ?>">
        <label>Features (one per line)</label>
        <textarea name="features" rows="5"><?= e($edit['features'] ?? '') ?></textarea>
        <button class="btn btn-primary" style="margin-top:14px" type="submit"><?= $edit ? 'Save plan' : 'Add plan' ?></button>
        <?php if ($edit): ?><a class="btn" href="/superadmin/pricing.php">Cancel</a><?php endif; ?>
    </form>
</div>

<table>
    <tr><th>Plan</th><th>Price</th><th>Blurb</th><th>Active</th><th></th></tr>
    <?php foreach ($plans as $p): ?>
    <tr>
        <td><strong><?= e($p['name']) ?></strong> <span class="sub-inline">(<?= e($p['slug']) ?>)</span></td>
        <td><?= money_cents((int)$p['price_cents']) ?>/<?= e($p['bill_interval']) ?></td>
        <td class="notes-cell"><?= e($p['blurb']) ?></td>
        <td><?= $p['is_active'] ? '✅' : '—' ?></td>
        <td class="row-actions">
            <a class="btn btn-sm" href="?edit=<?= (int)$p['id'] ?>">Edit</a>
            <form class="inline" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $p['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php
layout_footer();
