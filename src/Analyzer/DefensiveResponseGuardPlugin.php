<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
/** Reporting policy for physical Inertia page defensive guards; native types and Errors stay intact. */
final class DefensiveResponseGuardPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly bool $preserveAdvisories=false){ }
    public function getDefinition():PluginDefinition{return new PluginDefinition('ichinya/laramago-defensive-response-guards','Defensive response guards','Bounded reporting policy for the native physical page producer.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new DefensiveResponseGuardFilter(new DefensiveResponseGuardProof($this->root),$this->preserveAdvisories));}
}
final class DefensiveResponseGuardFilter implements IssueFilterHook
{
    public function __construct(private readonly DefensiveResponseGuardProof $proof,private readonly bool $preserve){ }
    public function getCodes():array{return ['redundant-type-comparison','redundant-condition','impossible-condition'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision{return !$this->preserve&&$this->proof->prove($context)['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;}
}