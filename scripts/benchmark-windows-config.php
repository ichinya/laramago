<?php

declare(strict_types=1);

// Read Composer maps without booting the application. All output remains private.
$root = realpath($argv[1] ?? '');
$out = realpath($argv[2] ?? '');
if (PHP_OS_FAMILY !== 'Windows' || $root === false || $out === false
    || ! is_file($root.'/.laramago-benchmark-case.json')) {
    throw new RuntimeException('A marked disposable Windows case and output directory are required.');
}
$normalize = static fn (string $path): string => str_replace('\\', '/', $path);
$root = $normalize($root);
$vendor = $root.'/vendor';
$inside = static fn (string $path, string $parent): bool => strcasecmp($path, $parent) === 0
    || strncasecmp($path, $parent.'/', strlen($parent) + 1) === 0;
$candidates = [];
foreach (['autoload_psr4.php', 'autoload_namespaces.php', 'autoload_classmap.php', 'autoload_files.php'] as $name) {
    if (! is_file($vendor.'/composer/'.$name)) { continue; }
    foreach (require $vendor.'/composer/'.$name as $locations) {
        foreach ((array) $locations as $location) {
            $resolved = realpath($location);
            if ($resolved === false) { continue; }
            $resolved = $normalize($resolved);
            if ($inside($resolved, $vendor)) { $candidates[$resolved] = true; }
        }
    }
}
$paths = array_keys($candidates);
usort($paths, static fn (string $left, string $right): int => strlen($left) <=> strlen($right));
$includes = [];
foreach ($paths as $path) {
    foreach ($includes as $parent) {
        if ($path === $parent || (is_dir($parent) && $inside($path, $parent))) { continue 2; }
    }
    $includes[] = $path;
}
if ($includes === [] || in_array($vendor, $includes, true)) {
    throw new RuntimeException('Composer dependency scope is absent or expanded to the whole vendor directory.');
}
$roots = ['app', 'tests', 'scripts/testing', 'testo.php'];
$config = ['extends' => [$vendor.'/ichinya/laramago/presets/laravel.toml'],
    'version' => '1.48.1', 'php-version' => '8.5', 'threads' => 2,
    'source' => ['paths' => $roots, 'includes' => $includes,
        'excludes' => ['vendor/laravel/framework/tests/Foundation/Fixtures/bad-syntax-strategy.php',
            'vendor/testo/bridge-symfony-console/resources/stubs/testo.php']],
    'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=2G',
        $vendor.'/ichinya/laramago/bin/laramago-worker.php', $vendor.'/autoload.php', $root],
        'workers' => 1, 'request-timeout-ms' => 1800000]]];
$target = $normalize($out).'/mago.json';
file_put_contents($target, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
require $vendor.'/autoload.php';
$classFile = $normalize((new ReflectionClass(Ichinya\Laramago\Analyzer\LaravelPlugin::class))->getFileName());
if ($classFile !== $vendor.'/ichinya/laramago/src/Analyzer/LaravelPlugin.php') {
    throw new RuntimeException('The worker would load a different package source.');
}
$installed = json_decode(file_get_contents($vendor.'/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR)['packages'];
$versions = [];
foreach ($installed as $package) {
    if (in_array($package['name'], ['laravel/framework', 'larastan/larastan', 'phpstan/phpstan', 'carthage-software/mago', 'nikic/php-parser', 'ichinya/laramago'], true)) {
        $versions[$package['name']] = $package['version'];
    }
}
echo json_encode(['complete' => true, 'php' => PHP_VERSION, 'extensions' => get_loaded_extensions(),
    'opcache' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'heap' => ini_get('memory_limit'),
    'versions' => $versions, 'roots' => $roots, 'dependency_include_count' => count($includes),
    'mago_config' => $target, 'package_source_override_verified' => true], JSON_THROW_ON_ERROR);
