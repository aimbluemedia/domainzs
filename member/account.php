<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;

Auth::requireMember();
Auth::refresh($pdo);

$stmt = $pdo->prepare(
    'SELECT u.*, p.name AS plan_name, p.price_cents, p.bill_interval
     FROM users u LEFT JOIN plans p ON p.id = u.sub_plan_id WHERE u.id = ?'
);
$stmt->execute([Auth::userId()]);
$user  = $stmt->fetch();
$plans = $pdo->query('SELECT * FROM plans WHERE is_active = 1 AND price_cents > 0 ORDER BY sort')->fetchAll();
$isPro = Auth::isPro();

// Change password.
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $current = (string)($_POST['current'] ?? '');
    $new     = (string)($_POST['new'] ?? '');
    if (!password_verify($current, $user['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), Auth::userId()]);
        flash('success', 'Password updated.');
        redirect('/member/account.php');
    }
}

layout_header('Account', 'member');
?>
<h1>Account</h1>
<p class="sub">Signed in as <strong><?= e($user['username']) ?></strong> · <?= e($user['email']) ?></p>

<div class="cards-2">
    <div class="panel">
        <h2 style="margin-top:0">Your plan</h2>
        <?php if ($isPro): ?>
            <p><span class="badge-st st-free">PRO</span> &nbsp;<strong><?= e($user['plan_name'] ?? 'Pro') ?></strong>
            <?php if ($user['sub_expires_at']): ?> · renews/expires <?= e(substr($user['sub_expires_at'], 0, 10)) ?><?php endif; ?></p>
            <p class="sub">You have the full drop board, favorites, and every rated name.</p>
        <?php else: ?>
            <p><span class="badge-st st-taken">FREE</span> &nbsp;Top 3 drops per day.</p>
            <?php foreach ($plans as $p): ?>
            <div class="upgrade-row">
                <div>
                    <strong><?= e($p['name']) ?></strong> — <?= money_cents((int)$p['price_cents']) ?>/<?= e($p['bill_interval']) ?>
                    <div class="sub" style="margin:0"><?= e($p['blurb']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <p class="sub" style="margin-top:14px"><?= e(setting('upgrade_note', 'To upgrade: reply to your welcome email or contact the site owner — your account is activated the same day.')) ?></p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">Change password</h2>
        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <label for="current">Current password</label>
            <input id="current" name="current" type="password" autocomplete="current-password" required>
            <label for="new">New password (8+ characters)</label>
            <input id="new" name="new" type="password" autocomplete="new-password" minlength="8" required>
            <button class="btn btn-primary" style="margin-top:14px" type="submit">Update password</button>
        </form>
    </div>
</div>
<?php
layout_footer();
