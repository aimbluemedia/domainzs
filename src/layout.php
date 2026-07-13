<?php
declare(strict_types=1);

/**
 * Shared HTML layout. One top bar for the whole app — nav shows only for
 * logged-in users.
 */

function layout_header(string $title): void
{
    global $rdap;
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $loggedIn = \Domainzs\Auth::check();
    $links = [
        ['/', 'Dashboard'],
        ['/portfolio.php', 'Portfolio'],
        ['/watchlist.php', 'Watchlist'],
    ];
    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · domainzs</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">🌐 domain<span>zs</span></a>
    <?php if ($loggedIn): ?>
    <nav>
        <?php foreach ($links as [$href, $label]):
            $isActive = $path === $href || ($href === '/' && $path === '/index.php'); ?>
            <a class="<?= $isActive ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <span class="user"><?= e(\Domainzs\Auth::username()) ?></span>
        <a href="/logout.php">Log out</a>
    </nav>
    <?php endif; ?>
</header>
<main class="container">
<?php
    if ($loggedIn && isset($rdap) && $rdap instanceof \Domainzs\RdapClient && $rdap->isMock()) {
        echo '<div class="mock-note">MOCK mode — showing sample registration data. '
            . 'Set <code>rdap.mock = false</code> in config.php for live RDAP lookups.</div>';
    }
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $f) {
            echo '<div class="flash flash-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
        }
        unset($_SESSION['flash']);
    }
}

function layout_footer(): void
{
    ?>
</main>
<footer class="foot">domainzs — never lose a domain, never miss a drop · <a href="/">domainzs.com</a></footer>
</body>
</html>
<?php
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
