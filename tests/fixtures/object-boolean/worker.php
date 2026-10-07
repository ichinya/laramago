<?php
declare(strict_types=1);
require $argv[1];
require __DIR__.'/NativeObjectBooleanObserver.php';
$root=$argv[2];$mode=$argv[3];
if(str_starts_with($mode,'full-')) { require $argv[4]; }
else {
    $plugins=match($mode) {
        'native'=>[],
        'observe'=>[new Ichinya\Laramago\Tests\ObjectBoolean\NativeObjectBooleanObserver($root.'/observe',false)],
        default=>[new Ichinya\Laramago\Analyzer\ObjectBooleanCompatibilityPlugin],
    };
    (new Mago\Sdk\Worker(new Mago\Sdk\Extension('fixture/object-boolean','Object boolean fixtures','1',analyzerPlugins:$plugins)))->run();
}