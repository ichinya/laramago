<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Runtime\RuntimeSchemaSnapshot;
use Throwable;

/** Compares a supplied snapshot with fresh, bounded live schema evidence. */
final class RuntimeSchemaVerifier
{
    /** @return array<string, string> */
    public static function check(RuntimeSchemaInput $input, string $root): array
    {
        try {
            $result = RuntimeSchemaProcess::collect($input->snapshot, $root);
            if ($result['errors'] !== [] || $result['truncated']) {
                return RuntimeSchemaFailures::fromSnapshot($result);
            }
            if (RuntimeSchemaSnapshot::fingerprint($result) !== RuntimeSchemaSnapshot::fingerprint($input->snapshot)) {
                return [
                    'runtime-metadata-schema-changed' => 'Runtime database schema or model metadata changed; collect a new snapshot.',
                ];
            }
        } catch (RuntimeSchemaCollectionFailure $failure) {
            return [
                $failure->diagnostic => 'Runtime schema revalidation failed; no runtime property refinements are available.',
            ];
        } catch (Throwable) {
            return ['runtime-metadata-unavailable' => 'Runtime schema revalidation failed.'];
        }

        return [];
    }
}
