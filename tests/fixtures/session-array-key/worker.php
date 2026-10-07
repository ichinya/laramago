<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
require $argv[1];
[$unused,$autoload,$root,$mode,$package,$candidate]=$argv;
foreach(['SourceArgumentDeclarationContracts','SessionArrayKeyArgumentFilter','SessionArrayKeyArgumentPlugin'] as $class) {
    if(!class_exists('Ichinya\\Laramago\\Analyzer\\'.$class,false)) { require $candidate.'/src/Analyzer/'.$class.'.php'; }
}
require __DIR__.'/NativeHarness.php';
if(str_starts_with($mode,'full-')) { require $root.'/'.$mode.'-worker.php'; }
else {
    $plugins=match($mode) {
        'native'=>[],
        'observe'=>[new SessionArrayKeyNativeHarness($root,$root.'/'.$mode)],
        'isolated'=>[new \Ichinya\Laramago\Analyzer\SessionArrayKeyArgumentPlugin($root)],
        'controls'=>[new SessionArrayKeyNativeHarness($root,$root.'/'.$mode,true)],
        default=>throw new \RuntimeException('Unknown Session array-key fixture mode.'),
    };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/session-array-key',name:'Session array-key arguments',version:'1',analyzerPlugins:$plugins)))->run();
}
