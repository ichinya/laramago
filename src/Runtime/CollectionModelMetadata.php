<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use ReflectionClass;
use RuntimeException;

/** Resolve model metadata while guarding construction and inherited source contracts. */
final class CollectionModelMetadata
{
    public static function resolve(
        CollectionState $state,
        mixed $loader,
        object $database,
        string $name,
        ?object &$instance,
    ): array {
        $before = RuntimeQueryGuard::$blocked;
        CollectionAutoloadSources::capture($state, $loader, $name);
        if (!class_exists($name) || !is_subclass_of($name, 'Illuminate\\Database\\Eloquent\\Model')) {
            throw new RuntimeException('Invalid model selection.');
        }
        CollectionSources::hierarchy($state, new ReflectionClass($name));
        CollectionSources::included($state);
        // The caller retains the instance through its catch blocks and catalog collection.
        $model = $instance = new $name();
        $table = $model->getTable();
        $connection = $model->getConnectionName() ?? $database->getDefaultConnection();
        $casts = $model->getCasts();
        if (
            !CollectionPaths::identifier($table)
            || !CollectionPaths::identifier($connection)
            || !is_array($casts)
            || count($casts) > RuntimeSchemaSnapshot::MAX_COLUMNS
            || RuntimeQueryGuard::$blocked !== $before
        ) {
            throw new RuntimeException('Invalid model metadata.');
        }

        return ['connection' => $connection, 'table' => $table, 'casts' => $casts];
    }
}
