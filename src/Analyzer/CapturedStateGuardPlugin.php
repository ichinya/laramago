<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
/** Intentional reporting policy for source and native certified captured-state guards. */
final class CapturedStateGuardPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly bool $preserveAdvisories=false){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('ichinya/laramago-captured-state-guards','Captured state guards','Explicit bounded defensive reporting policy.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new CapturedStateGuardFilter(new CapturedStateGuardProof($this->root),$this->preserveAdvisories));}
}
final class CapturedStateGuardFilter implements IssueFilterHook
{
    public function __construct(private readonly CapturedStateGuardProof $proof,private readonly bool $preserve){}
    public function getCodes():array{return ['impossible-condition','redundant-condition','redundant-type-comparison'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision{return !$this->preserve && $this->proof->prove($context)['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;}
}
