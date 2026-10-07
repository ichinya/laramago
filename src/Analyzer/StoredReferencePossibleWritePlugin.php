<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Ichinya\Laramago\Analyzer\StaticAnalysis\StoredReferencePossibleWrites;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
/** PHPStan possible-write scope policy for a closed literal reference lifetime. */
final class StoredReferencePossibleWritePlugin implements Plugin
{
    public function __construct(private readonly string $root='.'){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('ichinya/laramago-stored-reference-possible-writes','Stored reference possible writes','Preserve independently admissible writes to captured cells without assuming callback execution.');}
    public function register(PluginRegistry $registry):void
    {
        $proofs=new StoredReferencePossibleWrites($this->root);
        $registry->registerInitializationHook($proofs);$registry->registerCodebaseScanHook($proofs);
        $registry->registerIssueFilterHook(new StoredReferencePossibleWriteFilter($proofs));
    }
}
