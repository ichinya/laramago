<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
/** Reporting policy for a closed external XML cardinality rejection guard. */
final class XmlCardinalityGuardPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly bool $preserveAdvisories=false){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('ichinya/xml-cardinality-advisories','XML cardinality advisories','Current physical XML declarations can certify a defensive Warning policy without changing native types or Errors.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new XmlCardinalityGuardFilter(new XmlCardinalityProof($this->root),$this->preserveAdvisories));}
}
final class XmlCardinalityGuardFilter implements IssueFilterHook
{
    public function __construct(private readonly XmlCardinalityProof $proof,private readonly bool $preserve){}
    public function getCodes():array{return ['impossible-type-comparison'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision{return !$this->preserve&&$this->proof->prove($context)['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;}
}
