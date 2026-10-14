<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use PDO;
use RuntimeException;

/** File-backed catalog access with fixed readonly options, never application PRAGMAs. */
final class CollectionDatabase
{
    public static function path(RuntimeQueryGuard $connection, string $root): string
    {
        $path = $connection->getConfig('database');
        if (!is_string($path) || $path === ':memory:' || str_starts_with($path, 'file:')) {
            throw new RuntimeException('A file-backed SQLite database is required.');
        }
        if (!CollectionPaths::absolute($path)) {
            $path = $root . '/' . $path;
        }
        $path = realpath($path);
        if ($path === false || !is_file($path)) {
            throw new RuntimeException('SQLite database is unavailable.');
        }

        return $path;
    }

    public static function open(string $path): PDO
    {
        $flagsAttribute = defined('Pdo\\Sqlite::ATTR_OPEN_FLAGS')
            ? constant('Pdo\\Sqlite::ATTR_OPEN_FLAGS')
            : PDO::SQLITE_ATTR_OPEN_FLAGS;
        $readonlyFlag = defined('Pdo\\Sqlite::OPEN_READONLY')
            ? constant('Pdo\\Sqlite::OPEN_READONLY')
            : PDO::SQLITE_OPEN_READONLY;

        return new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            $flagsAttribute => $readonlyFlag,
            PDO::ATTR_TIMEOUT => 1,
        ]);
    }
}
