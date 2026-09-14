<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\LaravelPlugin;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

$autoload = $argv[1] ?? null;
if ($autoload === null || ! is_file($autoload)) {
    fwrite(STDERR, "Usage: php laramago-worker.php <vendor/autoload.php>\n");
    exit(2);
}

require $autoload;

(new Worker(new Extension(
    identifier: 'ichinya/laramago',
    name: 'Laramago',
    version: '0.0.1',
    analyzerPlugins: [new LaravelPlugin],
)))->run();
