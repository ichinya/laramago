<?php

declare(strict_types=1);

require __DIR__.'/../src/ConfigInstaller.php';

use Ichinya\Laramago\ConfigInstaller;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir().'/laramago-test-'.bin2hex(random_bytes(8));
mkdir($root);
$installer = new ConfigInstaller;
$count = 0;

try {
    mkdir($root.'/app');
    mkdir($root.'/routes');
    $preset = $root.'/dependencies/ichinya/laramago/presets/laravel.toml';
    $installer->install($root, $preset, $root.'/dependencies');
    $generated = file_get_contents($root.'/mago.dist.json');
    $config = json_decode($generated, true, flags: JSON_THROW_ON_ERROR);
    check($config['extends'] === 'dependencies/ichinya/laramago/presets/laravel.toml', 'Relocatable preset reference');
    check($config['source']['paths'] === ['app', 'routes'], 'Only existing Laravel paths');
    check($config['source']['includes'] === ['dependencies'], 'Custom vendor-dir');
    check(
        $config['extension-hosts']['laramago']['command'] === [
            'php',
            'dependencies/ichinya/laramago/bin/laramago-worker.php',
            'dependencies/autoload.php',
        ],
        'Relocatable worker and custom vendor autoloader',
    );
    $count += 4;
    $installer->install($root, $preset);
    check(file_get_contents($root.'/mago.dist.json') === $generated, 'Repeated install preserves config');
    $count++;
    unlink($root.'/mago.dist.json');

    foreach (['mago', 'mago.dist'] as $name) {
        foreach (['toml', 'yaml', 'yml', 'json'] as $extension) {
            $file = $root.'/'.$name.'.'.$extension;
            file_put_contents($file, 'user-owned content');
            $message = $installer->install($root, $preset);
            check(str_contains($message, 'preserved'), 'Existing config detected: '.$file);
            check(
                str_contains($message, 'extension-hosts.laramago.command'),
                'Existing config receives worker setup instructions',
            );
            check(file_get_contents($file) === 'user-owned content', 'Existing config untouched');
            check(
                $extension === 'json' && $name === 'mago.dist' || ! file_exists($root.'/mago.dist.json'),
                'No shadow config',
            );
            unlink($file);
            $count++;
        }
    }
    rmdir($root.'/app');
    rmdir($root.'/routes');
    $installer->install($root, $preset);
    $config = json_decode(file_get_contents($root.'/mago.dist.json'), true, flags: JSON_THROW_ON_ERROR);
    check($config['source']['paths'] === ['.'], 'Non-standard project fallback');
    $count++;
    echo "Passed {$count} installer scenarios.\n";
} finally {
    foreach (glob($root.'/*') ?: [] as $file) {
        is_dir($file) ? rmdir($file) : unlink($file);
    }
    rmdir($root);
}
