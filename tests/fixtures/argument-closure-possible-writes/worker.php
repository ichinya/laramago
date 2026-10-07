<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
require $argv[1];
[$unused,$autoload,$root,$mode,$candidate]=$argv;
if (! class_exists(\Ichinya\Laramago\Analyzer\ArgumentClosurePossibleWriteFilter::class,false)) { require $candidate.'/src/Analyzer/ArgumentClosurePossibleWriteFilter.php'; }
if (! class_exists(\Ichinya\Laramago\Analyzer\ArgumentClosurePossibleWritePlugin::class,false)) { require $candidate.'/src/Analyzer/ArgumentClosurePossibleWritePlugin.php'; }
require __DIR__.'/NativeHarness.php';
if (str_starts_with($mode,'full-')) { require $root.'/'.$mode.'-worker.php'; }
else {
    $plugins=match($mode) {
        'native'=>[],
        'observe'=>[new ArgumentClosureWriteNativeHarness($root,$root.'/'.$mode)],
        'isolated'=>[new \Ichinya\Laramago\Analyzer\ArgumentClosurePossibleWritePlugin($root)],
        'controls'=>[new ArgumentClosureWriteNativeHarness($root,$root.'/'.$mode,true)],
        default=>throw new \RuntimeException('Unknown argument closure fixture mode.'),
    };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/argument-closure-possible-writes',name:'Argument closure possible writes',version:'1',analyzerPlugins:$plugins)))->run();
}
