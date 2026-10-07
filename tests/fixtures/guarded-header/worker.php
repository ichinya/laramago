<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
require $argv[1];
[$unused,$autoload,$root,$mode,$package,$candidate]=$argv;
foreach(['SourceArgumentDeclarationContracts','GuardedRequestHeaderArgumentFilter','GuardedRequestHeaderArgumentPlugin'] as $class) {
    if(!class_exists('Ichinya\\Laramago\\Analyzer\\'.$class,false)) { require $candidate.'/src/Analyzer/'.$class.'.php'; }
}
require __DIR__.'/NativeHarness.php';
if(str_starts_with($mode,'full-')) { require $root.'/'.$mode.'-worker.php'; }
else {
    $plugins=match($mode) {
        'native'=>[],
        'observe'=>[new GuardedHeaderNativeHarness($root,$root.'/'.$mode)],
        'isolated'=>[new \Ichinya\Laramago\Analyzer\GuardedRequestHeaderArgumentPlugin($root)],
        'controls'=>[new GuardedHeaderNativeHarness($root,$root.'/'.$mode,true)],
        default=>throw new \RuntimeException('Unknown header fixture mode.'),
    };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/guarded-header',name:'Guarded header arguments',version:'1',analyzerPlugins:$plugins)))->run();
}
