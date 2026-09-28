<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

class Role
{
    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    public static function all(): array
    {
        return self::db()->fetchAll('SELECT id, name, description FROM roles ORDER BY id');
    }
}