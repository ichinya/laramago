<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use DomainException;
use Throwable;

/** Translate per-model failures into the existing redacted partial-result envelope. */
final class CollectionModels
{
    private readonly object $database;

    public function __construct(
        private readonly object $app,
        private readonly mixed $loader,
        private readonly string $root,
    ) {
        $this->database = $app->make('db');
    }

    public function collect(CollectionState $state, string $name): void
    {
        $instance = null;
        try {
            $configuration = $this->app->make('config');
            $metadata = CollectionModelMetadata::resolve($state, $this->loader, $this->database, $name, $instance);
            $connection = CollectionConnection::resolve(
                $state,
                $configuration,
                $this->database,
                $metadata['connection'],
            );
            if ($connection === null) {
                return;
            }
            $casts = CollectionCasts::sanitize($state, $this->loader, $metadata['casts']);
            $schema = $this->schema($state, $connection, $metadata['table']);
            if ($schema === null) {
                return;
            }
            $state->snapshot['models'][$name] = [
                'connection' => $metadata['connection'],
                'table' => $metadata['table'],
                'casts' => $casts,
                'columns' => $schema['columns'],
                'databaseIdentity' => $schema['databaseIdentity'],
            ];
        } catch (Throwable) {
            $state->error(
                'runtime-model-failed',
                'A selected model could not be resolved without application queries.',
            );
        }
    }

    private function schema(CollectionState $state, RuntimeQueryGuard $connection, string $table): ?array
    {
        try {
            $schema = CollectionCatalog::read($connection, $table, $this->root);
        } catch (DomainException) {
            $state->error('runtime-unsupported-column', 'A selected table uses unsupported generated columns.');
            return null;
        } catch (Throwable) {
            $state->error('runtime-schema-failed', 'Database schema inspection failed.');
            return null;
        }
        if ($schema === null) {
            $state->error('runtime-table-missing', 'A selected model table does not exist.');
        }

        return $schema;
    }
}
