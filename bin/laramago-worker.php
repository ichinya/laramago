<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\LaravelPlugin;
use Ichinya\Laramago\Analyzer\AggregateProjectionPlugin;
use Ichinya\Laramago\Analyzer\ArrayAssertionPlugin;
use Ichinya\Laramago\Analyzer\IntegerValidationPlugin;
use Ichinya\Laramago\Analyzer\KernelIntersectionPlugin;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

$autoload = $argv[1] ?? null;
if ($autoload === null || ! is_file($autoload)) {
    fwrite(STDERR, "Usage: php laramago-worker.php <vendor/autoload.php> [project-root]\n");
    exit(2);
}

require $autoload;

// Long analysis runs accumulate parsed syntax beyond PHP's conservative CLI
// default; raise the worker's own ceiling unless the operator already chose a
// larger (or unlimited) one.
$limit = ini_get('memory_limit');
if (is_string($limit) && $limit !== '-1') {
    $unit = strtolower(substr($limit, -1));
    $bytes = (int) $limit;
    if (in_array($unit, ['k', 'm', 'g'], true)) {
        $bytes *= ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][$unit];
    }
    if ($bytes < 512 * 1048576) {
        ini_set('memory_limit', '512M');
    }
}

$projectRoot = $argv[2] ?? Composer\InstalledVersions::getRootPackage()['install_path'] ?? getcwd();
if (! is_string($projectRoot) || ! is_dir($projectRoot)) {
    fwrite(STDERR, "Cannot locate the application root for static model metadata.\n");
    exit(2);
}

(new Worker(new Extension(
    identifier: 'ichinya/laramago',
    name: 'Laramago',
    version: '0.0.21',
    analyzerPlugins: [
        new AggregateProjectionPlugin($projectRoot),
        new ArrayAssertionPlugin($projectRoot),
        new IntegerValidationPlugin,
        new KernelIntersectionPlugin($projectRoot),
        new LaravelPlugin($projectRoot),
    ],
)))->run();
