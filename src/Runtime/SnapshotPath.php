<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Paths in snapshots use one canonical spelling and cannot traverse directories. */
final class SnapshotPath
{
    public static function absolute(mixed $value): bool
    {
        return (
            SnapshotValue::name($value, 4096)
            && !str_contains($value, '\\')
            && preg_match('~\A(?:[A-Za-z]:/|/)[^\x00]*\z~', $value) === 1
            && !preg_match('~(?:^|/)\.\.?(/|$)~', $value)
        );
    }

    public static function canonical(string $path): ?string
    {
        $resolved = realpath($path);

        return $resolved === false ? null : rtrim(str_replace('\\', '/', $resolved), '/');
    }
}
