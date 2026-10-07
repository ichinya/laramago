<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
/** Reporting policy for closed standard Collection boundary guards. */
final class CollectionOffsetGuardPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly bool $preserveAdvisories=false){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('ichinya/collection-boundary-advisories','Collection boundary advisories','Certified keyed model Collection Warnings can be omitted without changing native types or Errors.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new CollectionOffsetGuardFilter(new CollectionOffsetGuardProof($this->root),$this->preserveAdvisories));}
}
final class CollectionOffsetGuardFilter implements IssueFilterHook
{
    public function __construct(private readonly CollectionOffsetGuardProof $proof,private readonly bool $preserve){}
    public function getCodes():array{return ['possibly-null-array-index','impossible-condition'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision{return !$this->preserve&&$this->proof->prove($context)['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;}
}
