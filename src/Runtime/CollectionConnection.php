<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Resolve only guarded SQLite connections, before opening any catalog PDO. */
final class CollectionConnection
{
    public static function resolve(
        CollectionState $state,
        object $configuration,
        object $database,
        string $name,
    ): ?RuntimeQueryGuard {
        $driver = $configuration->get('database.connections.' . $name . '.driver');
        if ($driver !== 'sqlite') {
            $state->error('runtime-unsupported-driver', 'The selected database driver is not supported.');
            return null;
        }
        $resolved = $database->connection($name);
        $resolved->beforeExecuting(static function (): never {
            RuntimeQueryGuard::reject();
        });
        if (!$resolved instanceof RuntimeQueryGuard || $resolved->getDriverName() !== 'sqlite') {
            $state->error('runtime-unsupported-driver', 'The selected database driver is not supported.');
            return null;
        }

        return $resolved;
    }
}
