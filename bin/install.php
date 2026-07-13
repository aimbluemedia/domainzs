<?php
declare(strict_types=1);

/**
 * One-time setup:
 *   - imports schema.sql
 *   - creates (or keeps) your login
 *
 * Usage:
 *   php bin/install.php <username> <password> [email]
 */

require __DIR__ . '/../src/bootstrap.php';

use Domainzs\Auth;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/install.php <username> <password> [email]\n");
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

$userId = Auth::ensureUser($pdo, $username, $password, $email);
echo "User '{$username}' ready (id {$userId}).\n";

echo "Done. Serve the app (php -S 127.0.0.1:8000) and log in at /login.php\n";
