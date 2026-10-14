<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Reject framework queries independently of the PDO used for catalog reads. */
final class CollectionGuards
{
    public static function install(): void
    {
        \Illuminate\Database\Connection::resolverFor(
            'sqlite',
            static fn($pdo, $database, $prefix, $config): RuntimeQueryGuard => new RuntimeQueryGuard(
                $pdo,
                $database,
                $prefix,
                $config,
            ),
        );
        foreach (['mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
            \Illuminate\Database\Connection::resolverFor($driver, static function (): never {
                RuntimeQueryGuard::reject();
            });
        }
    }

    public static function clearFacades(): void
    {
        if (class_exists('Illuminate\\Support\\Facades\\Facade', false)) {
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        }
    }
}
