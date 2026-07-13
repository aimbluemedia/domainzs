<?php
declare(strict_types=1);

/**
 * Shared HTML layout for all three areas: public site, /member, /superadmin.
 * Uses root-absolute URLs so it works from any folder depth.
 */

function nav_links(string $area): array
{
    return match ($area) {
        'member' => [
            ['/member/', 'Dashboard'],
            ['/member/drops.php', 'Drop Board'],
            ['/member/favorites.php', 'Favorites'],
            ['/member/account.php', 'Account'],
        ],
        'admin' => [
            ['/superadmin/', 'Dashboard'],
            ['/superadmin/drops.php', 'Drops'],
            ['/superadmin/listings.php', 'Listings'],
            ['/superadmin/offers.php', 'Offers'],
            ['/superadmin/members.php', 'Members'],
            ['/superadmin/pricing.php', 'Pricing'],
            ['/superadmin/settings.php', 'Settings'],
        ],
        default => [
            ['/', 'Home'],
            ['/domains.php', 'Domains for sale'],
            ['/#pricing', 'Pricing'],
            ['/login.php', 'Log in'],
        ],
    };
}

function layout_header(string $title, string $area = 'public'): void
{
    global $config;
    $home = $area === 'admin' ? '/superadmin/' : ($area === 'member' ? '/member/' : '/');
    // Logged-in areas use a left sidebar; the public site keeps the top bar.
    $sidebar = in_array($area, ['admin', 'member'], true);
    $GLOBALS['__layout_sidebar'] = $sidebar;
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · domainzs</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="area-<?= e($area) ?><?= $sidebar ? ' has-sidebar' : '' ?>">
<?php if ($sidebar): ?>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="<?= e($home) ?>">🌐 domain<span>zs</span><?php if ($area === 'admin'): ?> <small>admin</small><?php endif; ?></a>
        <nav class="side-nav">
            <?php foreach (nav_links($area) as [$href, $label]):
                $isActive = ($path === $href)
                    || (in_array($href, ['/superadmin/', '/member/'], true) && in_array($path, [$href, $href . 'index.php'], true)); ?>
                <a class="<?= $isActive ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">
            <span class="user"><?= e(\Domainzs\Auth::username()) ?></span>
            <?php if (\Domainzs\Auth::isAdmin() && $area === 'member'): ?><a href="/superadmin/">→ admin</a><?php endif; ?>
            <a class="logout" href="/logout.php">Log out</a>
        </div>
    </aside>
    <div class="content">
    <main class="container">
<?php else: ?>
<header class="topbar">
    <a class="brand" href="/">🌐 domain<span>zs</span></a>
    <nav>
        <?php foreach (nav_links($area) as [$href, $label]): ?>
            <a href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php if (\Domainzs\Auth::check()): ?>
            <a class="btn btn-primary btn-sm" href="<?= \Domainzs\Auth::isAdmin() ? '/superadmin/' : '/member/' ?>">My dashboard</a>
        <?php else: ?>
            <a class="btn btn-primary btn-sm" href="/signup.php">Join free</a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
<?php endif; ?>
<?php
    if ($sidebar && isset($config)) {
        $drops = drops_config($config);
        if ((new \Domainzs\DropsClient($drops))->isMock()) {
            echo '<div class="mock-note">MOCK feed — showing generated sample drops.'
                . (\Domainzs\Auth::isAdmin()
                    ? ' Point the app at a real drop list in <a href="/superadmin/settings.php">Settings</a>.'
                    : '')
                . '</div>';
        }
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
    $sidebar = $GLOBALS['__layout_sidebar'] ?? false;
    ?>
</main>
<footer class="foot">domainzs — rated dropped domains, daily · <a href="/domains.php">domains for sale</a></footer>
<?php if ($sidebar): ?>
    </div><!-- .content -->
</div><!-- .shell -->
<?php endif; ?>
</body>
</html>
<?php
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
