<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
require $argv[1];
[$unused,$autoload,$root,$mode,$package,$candidate]=$argv;
foreach(['SourceArgumentDeclarationContracts','PossibleTupleConstantDomain','PossibleCallbackTupleContracts','PossibleCallbackTupleShapeFilter','PossibleCallbackTupleArgumentFilter','PossibleCallbackTupleCompatibilityPlugin'] as $class) {
    if(!class_exists('Ichinya\\Laramago\\Analyzer\\'.$class,false)) { require $candidate.'/src/Analyzer/'.$class.'.php'; }
}
require __DIR__.'/ConstantDomainControls.php';
require __DIR__.'/NativeHarness.php';
if(str_starts_with($mode,'full-')) { require $root.'/'.$mode.'-worker.php'; }
else {
    $plugins=match($mode) {'native'=>[],'observe'=>[new PossibleTupleNativeHarness($root,$root.'/'.$mode)],'isolated'=>[new \Ichinya\Laramago\Analyzer\PossibleCallbackTupleCompatibilityPlugin($root)],'controls'=>[new PossibleTupleNativeHarness($root,$root.'/'.$mode,true)],default=>throw new \RuntimeException('Unknown tuple fixture mode.')};
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/possible-tuples',name:'Possible callback tuples',version:'1',analyzerPlugins:$plugins)))->run();
}
