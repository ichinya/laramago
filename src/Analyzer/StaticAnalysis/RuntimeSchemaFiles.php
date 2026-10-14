<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Closure;
use ErrorException;
use Throwable;

/** Converts expected operating-system warnings to a failed local file operation. */
final class RuntimeSchemaFiles
{
    public static function attempt(Closure $operation): mixed
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException('Runtime metadata file operation failed.', 0, $severity);
        });
        try {
            return $operation();
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
