<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterHook,IssueFilterDecision,Plugin,PluginDefinition,PluginRegistry};

/** Reporting policy for a source-bound declared method on a standard hydrated chunk model. */
final class SelectedChunkModelWarningPlugin implements Plugin
{
    public function __construct(private readonly string $projectRoot,private readonly bool $preserveAdvisories=false){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('laramago/selected-chunk-model-warning','Selected chunk model method','Current declared method after a closed Collection helper and foreach lifetime.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new SelectedChunkModelWarningFilter(new SelectedChunkModelWarningProof($this->projectRoot),$this->preserveAdvisories));}
}

final class SelectedChunkModelWarningFilter implements IssueFilterHook
{
    public function __construct(private readonly SelectedChunkModelWarningProof $proof,private readonly bool $preserveAdvisories=false){}
    public function getCodes():array{return ['non-documented-method'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision{return !$this->preserveAdvisories&&$this->proof->prove($context)['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;}
}