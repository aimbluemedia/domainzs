<?php
declare(strict_types=1);

namespace Domainzs;

use PDO;
use PDOException;

/**
 * Thin PDO wrapper. Holds a single shared connection for the request.
 */
final class Database
{
    private static ?PDO $pdo = null;
    /** Remembered so reconnect() can rebuild the same connection. */
    private static array $cfg = [];

    public static function connect(array $cfg): PDO
    {
        self::$cfg = $cfg;
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // The recap makes long AI + availability calls between DB queries;
            // keep the server from closing an idle connection mid-job ("MySQL
            // server has gone away"). Best-effort — ignored if the host caps it.
            try {
                self::$pdo->exec('SET SESSION wait_timeout = 600, interactive_timeout = 600');
            } catch (\Throwable $e) {
                // not fatal — reconnect() covers the case where it still drops
            }
        } catch (PDOException $e) {
            http_response_code(500);
            exit(
                "Database connection failed: " . $e->getMessage() . "\n\n" .
                "Check your config.php database settings and make sure MySQL is running\n" .
                "and the schema has been imported (mysql -u USER DBNAME < schema.sql).\n"
            );
        }

        return self::$pdo;
    }

    /** Force a fresh connection (after "server has gone away"). */
    public static function reconnect(): PDO
    {
        self::$pdo = null;
        return self::connect(self::$cfg);
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            throw new \RuntimeException('Database not connected. Call Database::connect() first.');
        }
        return self::$pdo;
    }
}
