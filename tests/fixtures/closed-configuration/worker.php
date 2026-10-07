<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
require $argv[1];
[$unused,$autoload,$root,$mode,$package,$candidate]=$argv;
foreach (['SourceArgumentDeclarationContracts','ClosedConfigurationArgumentFilter','ClosedConfigurationArgumentPlugin'] as $class) {
    if (! class_exists('Ichinya\\Laramago\\Analyzer\\'.$class,false)) { require $candidate.'/src/Analyzer/'.$class.'.php'; }
}
require __DIR__.'/NativeHarness.php';
if (str_starts_with($mode,'full-')) { require $root.'/'.$mode.'-worker.php'; }
else {
    $plugins=match($mode) {
        'native'=>[],
        'observe'=>[new ClosedConfigurationNativeHarness($root,$root.'/'.$mode)],
        'isolated'=>[new \Ichinya\Laramago\Analyzer\ClosedConfigurationArgumentPlugin($root)],
        'controls'=>[new ClosedConfigurationNativeHarness($root,$root.'/'.$mode,true)],
        default=>throw new \RuntimeException('Unknown configuration fixture mode.'),
    };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/closed-configuration',name:'Closed configuration arguments',version:'1',analyzerPlugins:$plugins)))->run();
}
