<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
require $argv[1];
[$unused,$autoload,$root,$mode,$package,$candidate]=$argv;
foreach (['SourceArgumentDeclarationContracts','DefaultDateArgumentFilter','DefaultDateArgumentPlugin'] as $class) {
    if (! class_exists('Ichinya\\Laramago\\Analyzer\\'.$class,false)) { require $candidate.'/src/Analyzer/'.$class.'.php'; }
}
require __DIR__.'/NativeHarness.php';
if (str_starts_with($mode,'full-')) { require $root.'/'.$mode.'-worker.php'; }
else {
    $plugins=match($mode) {
        'native'=>[],
        'observe'=>[new DefaultDateNativeHarness($root,$root.'/'.$mode)],
        'isolated'=>[new \Ichinya\Laramago\Analyzer\DefaultDateArgumentPlugin($root)],
        'controls'=>[new DefaultDateNativeHarness($root,$root.'/'.$mode,true)],
        default=>throw new \RuntimeException('Unknown default date fixture mode.'),
    };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/default-date',name:'Default date arguments',version:'1',analyzerPlugins:$plugins)))->run();
}
