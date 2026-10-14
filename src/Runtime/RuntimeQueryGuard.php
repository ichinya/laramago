<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Loaded only after the application's Illuminate autoloader is installed. */
final class RuntimeQueryGuard extends \Illuminate\Database\SQLiteConnection
{
    public static int $blocked = 0;

    protected function run($query, $bindings, \Closure $callback)
    {
        self::reject();
    }

    public function getPdo()
    {
        self::reject();
    }

    public function getReadPdo()
    {
        self::reject();
    }

    public static function reject(): never
    {
        ++self::$blocked;
        throw new \RuntimeException('Runtime application queries are blocked.');
    }
}
