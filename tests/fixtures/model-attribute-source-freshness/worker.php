<?php
declare(strict_types=1);
require $argv[1];
require __DIR__.'/NativeFreshnessControls.php';
$plugin=new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition():\Mago\Sdk\Analyzer\PluginDefinition
    {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/model-source-freshness','Model source freshness','Current retained unscanned source certificates after actual AST eviction.');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry):void
    {
        $index=new \Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties($this->root);
        $registry->registerInitializationHook($index);$registry->registerCodebaseScanHook($index);
        $registry->registerIssueFilterHook(new \Example\ModelFreshnessTests\NativeFreshnessControls(
            new \Ichinya\Laramago\Analyzer\RefreshedModelPropertyIssueFilter($index),$this->root));
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('fixture/model-source-freshness','Model source freshness','1',analyzerPlugins:[$plugin])))->run();
