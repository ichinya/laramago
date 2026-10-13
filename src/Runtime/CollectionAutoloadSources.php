<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Record autoload targets without executing them, including custom cast classes. */
final class CollectionAutoloadSources
{
    public static function capture(CollectionState $state, mixed $loader, string $class): void
    {
        if (is_object($loader) && method_exists($loader, 'findFile')) {
            $file = $loader->findFile($class);
            if (is_string($file)) {
                CollectionSources::file($state, $file);
            }
        }
    }
}
