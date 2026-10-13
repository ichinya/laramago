<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use RuntimeException;

/** Enter the trusted application only after the collection plan has been captured. */
final class CollectionBootstrap
{
    public static function load(CollectionState $state): ?CollectionModels
    {
        $autoload = $state->plan->resolvedAutoload;
        if ($autoload === null || !is_file($autoload)) {
            $state->error('runtime-bootstrap-failed', 'Application dependencies could not be loaded.');
            return null;
        }
        $loader = require $autoload;
        if (
            !class_exists('Illuminate\\Foundation\\Application')
            || !class_exists('Illuminate\\Database\\SQLiteConnection')
        ) {
            throw new RuntimeException('Missing Laravel runtime dependencies.');
        }
        require_once __DIR__ . '/RuntimeQueryGuard.php';
        RuntimeQueryGuard::$blocked = 0;
        CollectionGuards::install();
        $bootstrap = $state->plan->root . '/bootstrap/app.php';
        if (!is_file($bootstrap)) {
            throw new RuntimeException('Missing application bootstrap.');
        }
        $app = require $bootstrap;
        if (!$app instanceof \Illuminate\Foundation\Application) {
            throw new RuntimeException('Invalid application bootstrap.');
        }
        CollectionGuards::install();
        CollectionBootstrappers::run($state, $app);
        if (RuntimeQueryGuard::$blocked !== 0) {
            throw new RuntimeException('Blocked application query.');
        }
        CollectionSourceSets::capture($state);

        return new CollectionModels($app, $loader, $state->plan->root);
    }
}
