<?php

declare(strict_types=1);

namespace Ichinya\Laramago;

use RuntimeException;

final class ConfigInstaller
{
    public function install(string $root, string $preset, ?string $vendor = null): string
    {
        $root = str_replace('\\', '/', $root);
        $preset = str_replace('\\', '/', $preset);
        // Use a relocatable path for normal and custom vendor-dir installations.
        $reference = $this->relativePath($root, $preset);
        $vendorReference = $vendor === null ? 'vendor' : $this->relativePath($root, str_replace('\\', '/', $vendor));
        $workerCommand = [
            'php',
            $this->relativePath($root, dirname($preset, 2).'/bin/laramago-worker.php'),
            $vendorReference.'/autoload.php',
        ];

        foreach (['mago', 'mago.dist'] as $name) {
            foreach (['toml', 'yaml', 'yml', 'json'] as $extension) {
                $file = $root.'/'.$name.'.'.$extension;
                if (file_exists($file) || is_link($file)) {
                    return (
                        'Existing '
                        .basename($file)
                        .' preserved. Ensure its extends includes: '
                        .$reference
                        .'. For analyzer support, set extension-hosts.laramago.command to: '
                        .json_encode($workerCommand, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    );
                }
            }
        }

        $paths = array_values(array_filter(
            ['app', 'bootstrap', 'config', 'database', 'routes', 'tests'],
            static fn (string $path): bool => is_dir($root.'/'.$path),
        ));
        $config = [
            'extends' => $reference,
            'source' => [
                'paths' => $paths === [] ? ['.'] : $paths,
                'includes' => [$vendorReference],
            ],
            'extension-hosts' => ['laramago' => ['command' => $workerCommand]],
        ];
        $contents = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        // Exclusive creation: never overwrite a concurrent user's config.
        $handle = @fopen($root.'/mago.dist.json', 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot create mago.dist.json. Existing files were not overwritten.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('Could not write the complete mago.dist.json.');
            }
        } finally {
            fclose($handle);
        }

        return 'Created mago.dist.json. Commit it and run vendor/bin/mago lint.';
    }

    private function relativePath(string $root, string $target): string
    {
        $from = explode('/', rtrim($root, '/'));
        $to = explode('/', $target);
        // A path repository may be symlinked to another drive on Windows.
        if (str_contains($from[0], ':') && strcasecmp($from[0], $to[0]) !== 0) {
            return $target;
        }
        while (
            $from !== []
            && $to !== []
            && (PHP_OS_FAMILY === 'Windows' ? strcasecmp($from[0], $to[0]) === 0 : $from[0] === $to[0])
        ) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)).implode('/', $to);
    }
}
