<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Structured failures expose only bounded messages and canonical file references. */
final class SnapshotErrors
{
    public static function validate(array $errors): void
    {
        foreach ($errors as $error) {
            SnapshotFields::validate($error, [
                'code' => SnapshotValue::name(...),
                'file' => static fn(mixed $v): bool => $v === null || SnapshotPath::absolute($v),
                'message' => static fn(mixed $v): bool => SnapshotValue::name($v, 1024),
            ]);
        }
    }
}
