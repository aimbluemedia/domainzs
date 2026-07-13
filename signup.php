<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use Domainzs\Auth;

if (Auth::check()) {
    redirect(Auth::isAdmin() ? '/superadmin/' : '/member/');
}

$plan  = (string)($_GET['plan'] ?? '');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) {
        $error = 'Username must be 3–60 characters (letters, numbers, . _ -).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address doesn\'t look right.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        [$id, $error] = Auth::create($pdo, $username, $email, $password);
        if ($id !== null) {
            Auth::attempt($pdo, $username, $password);
            flash('success', 'Welcome to domainzs! Here are the latest rated drops.');
            redirect('/member/');
        }
    }
}

layout_header('Create account', 'public');
?>
<div class="auth-wrap">
    <h1>Create your free account</h1>
    <div class="card">
        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <label for="username">Username</label>
            <input id="username" name="username" value="<?= e((string)($_POST['username'] ?? '')) ?>" autocomplete="username" autofocus required>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?= e((string)($_POST['email'] ?? '')) ?>" autocomplete="email" required>
            <label for="password">Password (8+ characters)</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
            <button class="btn btn-primary" style="width:100%;margin-top:18px" type="submit">Create account</button>
        </form>
        <?php if ($plan !== '' && $plan !== 'free'): ?>
        <p class="sub" style="margin-top:14px">After signing up, upgrade to <strong><?= e(ucfirst($plan)) ?></strong> from your Account page.</p>
        <?php endif; ?>
        <p class="sub" style="text-align:center;margin-top:16px">Already a member? <a href="/login.php">Log in</a></p>
    </div>
</div>
<?php
layout_footer();
