<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId === Auth::userId() && in_array($action, ['toggle_status'], true)) {
        flash('error', "You can't disable your own account.");
        redirect('/superadmin/members.php');
    }

    if ($action === 'activate') {
        // Activate/extend a paid subscription and log the payment.
        $planId = (int)($_POST['plan_id'] ?? 0);
        $months = max(1, min(36, (int)($_POST['months'] ?? 1)));
        $plan   = null;
        if ($planId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM plans WHERE id = ?');
            $stmt->execute([$planId]);
            $plan = $stmt->fetch();
        }
        if (!$plan) {
            flash('error', 'Pick a plan.');
        } else {
            $pdo->prepare(
                "UPDATE users SET sub_status = 'active', sub_plan_id = ?,
                 sub_expires_at = GREATEST(COALESCE(sub_expires_at, NOW()), NOW()) + INTERVAL ? MONTH
                 WHERE id = ? AND role = 'member'"
            )->execute([$planId, $months, $userId]);
            $pdo->prepare(
                'INSERT INTO payments (user_id, plan_id, amount_cents, note) VALUES (?, ?, ?, ?)'
            )->execute([
                $userId, $planId,
                (int)$plan['price_cents'] * $months,
                trim((string)($_POST['note'] ?? '')) ?: "{$months} month(s) of {$plan['name']}",
            ]);
            flash('success', 'Subscription activated and payment logged.');
        }
    } elseif ($action === 'cancel_sub') {
        $pdo->prepare("UPDATE users SET sub_status = 'canceled' WHERE id = ? AND role = 'member'")
            ->execute([$userId]);
        flash('success', 'Subscription canceled (access runs until the paid-through date).');
    } elseif ($action === 'toggle_status') {
        $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'disabled', 'active') WHERE id = ?")
            ->execute([$userId]);
        flash('success', 'Account status toggled.');
    }
    redirect('/superadmin/members.php');
}

$members = $pdo->query(
    'SELECT u.*, p.name AS plan_name,
        (SELECT COALESCE(SUM(amount_cents), 0) FROM payments pay WHERE pay.user_id = u.id) AS paid_cents
     FROM users u LEFT JOIN plans p ON p.id = u.sub_plan_id
     ORDER BY u.created_at DESC'
)->fetchAll();
$plans = $pdo->query('SELECT * FROM plans WHERE price_cents > 0 ORDER BY sort')->fetchAll();

$revenue = (int)$pdo->query('SELECT COALESCE(SUM(amount_cents), 0) FROM payments')->fetchColumn();

layout_header('Members', 'admin');
?>
<h1>Members</h1>
<p class="sub">Subscriptions are activated manually: collect payment however you like (PayPal, Stripe link, bank), then
activate the member here — the payment is logged below. Lifetime revenue: <strong><?= money_cents($revenue) ?></strong>.</p>

<?php if (!$members): ?>
    <div class="empty">No users yet.</div>
<?php else: ?>
    <table>
        <tr><th>User</th><th>Role</th><th>Subscription</th><th>Paid</th><th>Joined</th><th>Actions</th></tr>
        <?php foreach ($members as $m): ?>
        <tr>
            <td>
                <strong><?= e($m['username']) ?></strong><?= $m['status'] === 'disabled' ? ' <span class="badge-st st-taken">disabled</span>' : '' ?><br>
                <span class="sub-inline"><?= e($m['email']) ?></span>
            </td>
            <td><?= e($m['role']) ?></td>
            <td>
                <?php if ($m['role'] === 'superadmin'): ?>—
                <?php elseif (in_array($m['sub_status'], ['active', 'trialing'], true)): ?>
                    <span class="badge-st st-free"><?= e($m['plan_name'] ?? 'Pro') ?></span>
                    <?php if ($m['sub_expires_at']): ?><span class="sub-inline"> until <?= e(substr($m['sub_expires_at'], 0, 10)) ?></span><?php endif; ?>
                <?php else: ?>
                    <span class="badge-st st-taken"><?= e($m['sub_status']) ?></span>
                <?php endif; ?>
            </td>
            <td><?= money_cents((int)$m['paid_cents']) ?></td>
            <td class="notes-cell"><?= e(substr($m['created_at'], 0, 10)) ?></td>
            <td class="row-actions">
                <?php if ($m['role'] === 'member'): ?>
                <form class="inline-form" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="activate">
                    <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                    <select name="plan_id">
                        <?php foreach ($plans as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= money_cents((int)$p['price_cents']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <select name="months">
                        <?php foreach ([1, 3, 6, 12] as $mo): ?><option value="<?= $mo ?>"><?= $mo ?> mo</option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-primary" type="submit">Activate</button>
                </form>
                <?php if (in_array($m['sub_status'], ['active', 'trialing'], true)): ?>
                <form class="inline" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel_sub">
                    <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                    <button class="btn btn-sm" type="submit">Cancel sub</button>
                </form>
                <?php endif; ?>
                <form class="inline" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit"><?= $m['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php
layout_footer();
