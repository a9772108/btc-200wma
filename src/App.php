<?php

declare(strict_types=1);

namespace Btc200Wma;

/**
 * Shared bootstrap: config, autoloader, and the pieces the entry points need.
 */

final class App
{
    private static ?array $config = null;

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path = self::root() . '/config/config.php';
        if (!is_file($path)) {
            throw new \RuntimeException(
                'config/config.php is missing. Copy config/config.sample.php to config/config.php and edit it.'
            );
        }

        $cfg = require $path;
        if (!is_array($cfg)) {
            throw new \RuntimeException('config/config.php must return an array.');
        }

        return self::$config = $cfg;
    }

    public static function prices(): PriceRepository
    {
        return new PriceRepository(Database::connect(self::config()['db']));
    }

    public static function meta(): Meta
    {
        return new Meta(Database::connect(self::config()['db']));
    }

    public static function maWeeks(): int
    {
        $weeks = (int) (self::config()['ma_weeks'] ?? 200);

        return $weeks > 0 ? $weeks : 200;
    }
}

/**
 * PSR-4 autoloader for the Btc200Wma\ namespace mapped to src/.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Btc200Wma\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = App::root() . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
