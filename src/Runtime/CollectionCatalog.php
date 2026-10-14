<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use PDO;
use RuntimeException;

/** Resolve one physical table and preserve the database/prefix identity in the snapshot. */
final class CollectionCatalog
{
    public static function read(RuntimeQueryGuard $connection, string $table, string $root): ?array
    {
        $path = CollectionDatabase::path($connection, $root);
        $table = $connection->getTablePrefix() . $table;
        if (!CollectionPaths::identifier($table)) {
            throw new RuntimeException('Invalid model table.');
        }
        $pdo = CollectionDatabase::open($path);
        $exists = $pdo->prepare(
            "select strict from pragma_table_list where schema = 'main' and name = ? and type = 'table'",
        );
        $exists->execute([$table]);
        $row = $exists->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'columns' => CollectionColumns::read($pdo, $table, (int) $row['strict'] === 1),
            'databaseIdentity' => hash('sha256', str_replace('\\', '/', $path) . "\0" . $connection->getTablePrefix()),
        ];
    }
}
