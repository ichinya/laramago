<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** A failed filesystem read must not expose path-bearing PHP warnings. */
final class RuntimeFiles
{
    public static function read(string $path, int $limit): string|false
    {
        return self::attempt(static fn(): string|false => file_get_contents($path, false, null, 0, $limit));
    }

    public static function digest(string $path): string|false
    {
        return self::attempt(static fn(): string|false => hash_file('sha256', $path));
    }

    public static function size(string $path): int|false
    {
        return self::attempt(static fn(): int|false => filesize($path));
    }

    private static function attempt(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $operation();
        } catch (\Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }
}
