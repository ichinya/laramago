<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use DomainException;
use PDO;
use RuntimeException;

/** Interpret only SQLite catalog descriptors; no table rows, defaults, or comments. */
final class CollectionColumns
{
    public static function read(PDO $pdo, string $table, bool $strict): array
    {
        $query = $pdo->prepare('select name, type, "notnull", pk, hidden from pragma_table_xinfo(?) order by name');
        $query->execute([$table]);
        $columns = [];
        while (($column = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ((int) $column['hidden'] !== 0) {
                throw new DomainException('Unsupported generated or hidden columns.');
            }
            if (
                count($columns) >= RuntimeSchemaSnapshot::MAX_COLUMNS
                || !CollectionPaths::identifier($column['name'])
                || strlen((string) $column['type']) > 256
            ) {
                throw new RuntimeException('Schema limit exceeded.');
            }
            $type = (string) $column['type'];
            $notNull = (int) $column['notnull'] === 1 || (int) $column['pk'] > 0 && $strict;
            $columns[$column['name']] = ['typeName' => $type, 'nullable' => !$notNull, 'strict' => $strict];
        }
        if ($columns === []) {
            throw new RuntimeException('Schema inspection was incomplete.');
        }
        ksort($columns, SORT_STRING);

        return $columns;
    }
}
