<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use RuntimeException;

/** Path and identifier rules shared by the collection boundary. */
final class CollectionPaths
{
    public static function absolute(string $path): bool
    {
        return preg_match('~\A(?:[A-Za-z]:[/\\\\]|/)~', $path) === 1;
    }

    public static function identifier(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 256 && !str_contains($value, "\0");
    }

    public static function defaultAutoload(string $root): string
    {
        $composer = RuntimeFiles::read($root . '/composer.json', 1_048_577);
        $manifest = is_string($composer) && strlen($composer) <= 1_048_576 ? json_decode($composer, true) : null;
        $vendor = is_array($manifest) ? $manifest['config']['vendor-dir'] ?? 'vendor' : 'vendor';
        if (!is_string($vendor) || $vendor === '' || str_contains($vendor, "\0")) {
            throw new RuntimeException('Invalid Composer vendor directory.');
        }

        return (self::absolute($vendor) ? $vendor : $root . '/' . $vendor) . '/autoload.php';
    }
}
