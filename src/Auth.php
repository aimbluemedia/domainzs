<?php
declare(strict_types=1);

namespace Domainzs;

use PDO;

/**
 * Session authentication. Single-tier: every user sees the whole app.
 * Accounts are created by the installer (bin/install.php) — there is no
 * public signup.
 */
final class Auth
{
    /** Authenticate by username OR email. */
    public static function attempt(PDO $pdo, string $login, string $password): bool
    {
        $stmt = $pdo->prepare(
            'SELECT id, username, email, password_hash
             FROM users WHERE username = ? OR email = ? LIMIT 1'
        );
        $stmt->execute([$login, $login]);
        $u = $stmt->fetch();

        if (!$u || !password_verify($password, $u['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['uid']      = (int)$u['id'];
        $_SESSION['username'] = $u['username'];
        $_SESSION['email']    = $u['email'];
        return true;
    }

    public static function check(): bool      { return !empty($_SESSION['uid']); }
    public static function userId(): int      { return (int)($_SESSION['uid'] ?? 0); }
    public static function username(): string { return (string)($_SESSION['username'] ?? ''); }

    /** Guard a page — redirect anonymous visitors to the login form. */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Idempotent helper used by the installer to seed the admin user. */
    public static function ensureUser(PDO $pdo, string $username, string $password, ?string $email = null): int
    {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($id = $stmt->fetchColumn()) {
            return (int)$id;
        }
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        return (int)$pdo->lastInsertId();
    }
}
