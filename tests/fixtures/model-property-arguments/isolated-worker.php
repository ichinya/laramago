<?php
declare(strict_types=1);
require $argv[1].'/vendor/autoload.php';require __DIR__.'/worker-classes.php';
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('example/model-schema-contract','Selected primitive model schema contract','1',
    analyzerPlugins:[new ModelPropertyArgumentNativePlugin($argv[2],$argv[3],$argv[4])])))->run();
