<?php

declare(strict_types=1);

namespace Btc200Wma;

use PDO;
use RuntimeException;

/**
 * Thin PDO wrapper. One connection per request; the app is read-mostly.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** @param array<string,mixed> $cfg */
    public static function connect(array $cfg): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 3306),
            $cfg['database'] ?? 'btc_200wma',
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (\PDOException $e) {
            throw new RuntimeException(
                'Database connection failed: ' . $e->getMessage()
                . ' — check config.php and that the schema has been imported.',
                0,
                $e
            );
        }

        return self::$pdo;
    }
}
