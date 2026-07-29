<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Env;
use PDO;

final class Connection
{
    public static function create(): PDO
    {
        $pdo = new PDO(
            Env::require('DB_DSN'),
            Env::get('DB_USER', ''),
            Env::get('DB_PASS', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        if (str_starts_with(Env::require('DB_DSN'), 'mysql:')) {
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET time_zone = '+00:00'"); // Store all timestamps in UTC
        }

        return $pdo;
    }
}
