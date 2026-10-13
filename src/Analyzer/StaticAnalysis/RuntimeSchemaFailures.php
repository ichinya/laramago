<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Maps untrusted collector stage codes to fixed redacted diagnostics. */
final class RuntimeSchemaFailures
{
    private const COLLECTION_ERRORS = [
        'runtime-bootstrap-failed' => 'Laravel bootstrap failed while collecting runtime schema.',
        'runtime-model-failed' => 'A selected model could not be inspected by the runtime collector.',
        'runtime-schema-failed' => 'A selected database connection or schema could not be inspected.',
        'runtime-table-missing' => 'A selected model table does not exist on its resolved connection.',
        'runtime-unsupported-driver' => 'The selected database driver is not supported by runtime schema collection.',
        'runtime-source-failed' => 'A runtime input source could not be read.',
        'runtime-source-changed' => 'Runtime sources changed during schema collection.',
    ];

    /** @param array<string, mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): array
    {
        $errors = [];
        foreach ($snapshot['errors'] as $error) {
            $code = $error['code'];
            if ((self::COLLECTION_ERRORS[$code] ?? null) !== null) {
                $errors['runtime-metadata-' . substr($code, strlen('runtime-'))] = self::COLLECTION_ERRORS[$code];
            }
        }
        if ($errors === []) {
            $errors['runtime-metadata-unavailable'] = 'Runtime schema collection did not produce a complete usable snapshot.';
        }
        return $errors;
    }
}
