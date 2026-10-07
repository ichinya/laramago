<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
/** Compatibility for selected literal formatter values from the declared default Factory. */
final class FactoryFakerPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly bool $preserveDiagnostics=false){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('ichinya/factory-faker-contracts','Default Factory formatter contracts','Source-bound literal formatter domains with current native declarations and registered-source override priority.');}
    public function register(PluginRegistry $registry):void{$contracts=new FactoryFakerContracts($this->root);$registry->registerInitializationHook($contracts);$registry->registerCodebaseScanHook($contracts);$registry->registerIssueFilterHook(new FactoryFakerFilter(new FactoryFakerProof($contracts,$this->root),$this->preserveDiagnostics));}
}
final class FactoryFakerFilter implements IssueFilterHook
{
    public function __construct(private readonly FactoryFakerProof $proof,private readonly bool $preserve){}
    public function getCodes():array{return ['array-to-string-conversion','mixed-argument'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision{return !$this->preserve&&$this->proof->prove($context)['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;}
}