<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use RuntimeException;

/** Scalar constraints shared by the versioned snapshot schema. */
final class SnapshotValue
{
    /** Only a proven predicate is accepted; truthy values are not proofs. */
    public static function require(mixed $valid): void
    {
        if ($valid !== true) {
            throw new RuntimeException('Invalid runtime schema snapshot.');
        }
    }

    public static function name(mixed $value, int $limit = 256): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $limit && !str_contains($value, "\0");
    }

    public static function className(mixed $value): bool
    {
        return (
            is_string($value)
            && strlen($value) <= 256
            && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $value) === 1
        );
    }

    public static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
