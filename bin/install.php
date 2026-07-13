<?php
declare(strict_types=1);

/**
 * One-time setup:
 *   - imports schema.sql
 *   - creates the SUPERADMIN account
 *   - seeds default plans + settings
 *
 * Usage:
 *   php bin/install.php <admin_username> <admin_password> [admin_email]
 */

require __DIR__ . '/../src/bootstrap.php';

use Domainzs\Auth;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/install.php <admin_username> <admin_password> [admin_email]\n");
    exit(1);
}
[$_, $username, $password] = $argv;
$email = $argv[3] ?? null;

$schema = file_get_contents(APP_ROOT . '/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Could not read schema.sql\n");
    exit(1);
}
$pdo->exec($schema);
echo "Schema imported.\n";

$adminId = Auth::ensureUser($pdo, $username, $password, $email, 'superadmin');
echo "Superadmin '{$username}' ready (id {$adminId}). Log in at /login.php\n";

// Seed plans (only if none exist).
if ((int)$pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn() === 0) {
    $plan = $pdo->prepare(
        'INSERT INTO plans (name, slug, price_cents, bill_interval, blurb, features, is_active, sort) VALUES (?,?,?,?,?,?,1,?)'
    );
    $plan->execute(['Free', 'free', 0, 'month', 'A taste of the drop board.',
        "Top 3 rated drops per day\nBrowse the marketplace\nMake offers on listed domains", 0]);
    $plan->execute(['Pro', 'pro', 1900, 'month', 'The full board, every day.',
        "Every rated drop, every day\nAI ratings & value estimates\nLive availability checks\nFavorites watchlist\nSearch & score filters", 1]);
    echo "Seeded Free + Pro plans.\n";
}

// Seed settings.
$set = $pdo->prepare('INSERT IGNORE INTO settings (skey, sval) VALUES (?, ?)');
foreach ([
    'hero_title'    => 'The best dropped 9-letter .coms — found and rated for you, daily.',
    'hero_subtitle' => 'domainzs pulls every freshly dropped domain, keeps the 9-character .coms, and scores each one for brandability and resale value — so you only look at names worth registering.',
    'drops_provider'  => '',
    'drops_url'       => '',
] as $k => $v) {
    if ($v !== '') {
        $set->execute([$k, $v]);
    }
}
echo "Seeded settings.\n";

echo "Done. Visit / (homepage), /signup.php (members), /login.php (your admin login).\n";
echo "Next: fetch your first drop list — php bin/fetch.php\n";
