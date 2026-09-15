<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\LaravelPlugin;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

$autoload = $argv[1] ?? null;
if ($autoload === null || ! is_file($autoload)) {
    fwrite(STDERR, "Usage: php laramago-worker.php <vendor/autoload.php> [project-root]\n");
    exit(2);
}

require $autoload;

$projectRoot = $argv[2] ?? Composer\InstalledVersions::getRootPackage()['install_path'] ?? getcwd();
if (! is_string($projectRoot) || ! is_dir($projectRoot)) {
    fwrite(STDERR, "Cannot locate the application root for static model metadata.\n");
    exit(2);
}

(new Worker(new Extension(
    identifier: 'ichinya/laramago',
    name: 'Laramago',
    version: '0.0.4',
    analyzerPlugins: [new LaravelPlugin($projectRoot)],
)))->run();
