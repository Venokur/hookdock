<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Config::get('DB_HOST', 'db'),
            Config::get('DB_PORT', '5432'),
            Config::get('DB_NAME', 'webhooks'),
        );

        return new PDO($dsn, Config::get('DB_USER', 'webhooks'), Config::get('DB_PASSWORD', 'webhooks'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }
}
