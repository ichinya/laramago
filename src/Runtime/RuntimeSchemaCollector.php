<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use Throwable;

/**
 * Explicitly trusted application PHP, with no normal provider/kernel boot.
 * Framework queries are rejected; catalog reads use a separate readonly PDO.
 * This is an execution boundary and not a sandbox for arbitrary application PHP.
 */
final class RuntimeSchemaCollector
{
    public static function collect(string $root, ?string $autoload, array $models): array
    {
        $state = new CollectionState(new CollectionPlan($root, $autoload, $models));
        CollectionSourceSets::capture($state);
        $revision = new CollectionRevision($state);
        $collection = null;
        try {
            $collection = CollectionBootstrap::load($state);
            if ($collection !== null) {
                foreach ($state->plan->models as $model) {
                    $collection->collect($state, $model);
                }
            }
        } catch (Throwable) {
            $state->error('runtime-bootstrap-failed', 'Application configuration bootstrap failed.');
        } finally {
            CollectionGuards::clearFacades();
            unset($collection);
        }

        return $revision->finish($state);
    }
}
