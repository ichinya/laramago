<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
require $argv[1];
$root=$argv[2];$mode=$argv[3];$package=$argv[4];$candidate=$argv[5];


require __DIR__.'/SourceArgumentNativeHarness.php';
if (str_starts_with($mode,'full-')) { require $root.'/'.$mode.'-worker.php'; }
else {
    $plugins=match($mode) {
        'native'=>[],
        'observe'=>[new SourceArgumentNativeHarness($root,$root.'/'.$mode)],
        'isolated'=>[new \Ichinya\Laramago\Analyzer\SourceArgumentContractPlugin($root)],
        'controls'=>[new SourceArgumentNativeHarness($root,$root.'/'.$mode,true)],
        default=>throw new \RuntimeException('Unknown source argument fixture mode.'),
    };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/source-argument-contracts',name:'Source argument contracts',version:'1',analyzerPlugins:$plugins)))->run();
}
