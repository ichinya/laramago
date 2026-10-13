<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Load configuration and facades without registering application providers. */
final class CollectionBootstrappers
{
    public static function run(CollectionState $state, object $app): void
    {
        CollectionSourceSets::application($state, $app);
        foreach ([
            'Illuminate\\Foundation\\Bootstrap\\LoadEnvironmentVariables',
            'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration',
            'Illuminate\\Foundation\\Bootstrap\\RegisterFacades',
        ] as $bootstrapper) {
            $cachedPaths = [
                str_replace('\\', '/', $app->getCachedConfigPath()),
                str_replace('\\', '/', $app->getCachedPackagesPath()),
            ];
            if (
                $bootstrapper === 'Illuminate\\Foundation\\Bootstrap\\LoadEnvironmentVariables'
                || $bootstrapper === 'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration'
            ) {
                $state->sets[] = ['paths' => $cachedPaths];
                CollectionSourceSets::capture($state);
            }
            if ($bootstrapper === 'Illuminate\\Foundation\\Bootstrap\\RegisterFacades') {
                $manifest = $app->make('Illuminate\\Foundation\\PackageManifest');
                if (!is_file($manifest->manifestPath)) {
                    // Avoid RegisterFacades rebuilding packages.php on disk.
                    $manifest->manifest = [];
                }
            }
            (new $bootstrapper())->bootstrap($app);
        }
        $provider = $app->register('Illuminate\\Database\\DatabaseServiceProvider');
        $provider->boot();
    }
}
