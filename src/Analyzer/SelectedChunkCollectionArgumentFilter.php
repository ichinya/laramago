<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook};
use PhpParser\Node;

/** Exact receiving admission for one unmodified typed Eloquent chunk input. */
final class SelectedChunkCollectionArgumentFilter implements IssueFilterHook
{
    public array $stages=[];public array $dependencies=[];public array $certificate=[];
    public function __construct(private readonly string $root) {}
    public function getCodes():array { return ['less-specific-argument']; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $proof=new self($this->root);$decision=$proof->evaluate($context);
        $this->stages=$proof->stages;$this->dependencies=$proof->dependencies;$this->certificate=$proof->certificate;return $decision;
    }
    private function evaluate(IssueFilterContext $context):IssueFilterDecision
    {
        $receivers=new SourceArgumentDeclarationContracts($this->root);$chunks=new SelectedChunkCollectionContracts($this->root);
        try {
            $receiving=$receivers->receiving($context,['less-specific-argument']);if ($receiving===null) { return IssueFilterDecision::Keep; }
            ['source'=>$source,'file'=>$file,'call'=>$call,'argument'=>$argument]=$receiving;$value=$argument->value;
            if (!$receivers->stage('exact native broad collection argument',$receiving['index']===0 && count($call->args)===1 && $value instanceof Node\Expr\Variable && is_string($value->name)
                && str_contains($context->issue->message,'provided type `Illuminate\\Database\\Eloquent\\Collection<array-key, Illuminate\\Database\\Eloquent\\Model>` is less specific.'))) { return IssueFilterDecision::Keep; }
            $lexical=$chunks->lexical($source,$file,$call,$value);if ($lexical===null) { return IssueFilterDecision::Keep; }
            $domain=$chunks->domain($context,$source,$file,$lexical);if ($domain===null) { return IssueFilterDecision::Keep; }
            if (!$receivers->stage('same physical this receiving declaration',$call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\Variable && $call->var->name==='this'
                && !$receiving['native']->static && $receiving['native']->identifier->class===$chunks->dependencies['owner']->identifier->class)) { return IssueFilterDecision::Keep; }
            if (!$receivers->admits($context,$receiving,$domain) || !$chunks->current()) { return IssueFilterDecision::Keep; }
            $this->certificate=$chunks->certificate+['file'=>$context->file,'argumentSpan'=>SourceArgumentDeclarationContracts::span($value),'originalFamily'=>'E024'];
            $this->certificate['bindings']['receiving']=['kind'=>'method','class'=>$receiving['native']->identifier->class,'name'=>$receiving['native']->identifier->name];
            return IssueFilterDecision::Remove;
        } finally { $this->stages=[...$receivers->stages,...$chunks->stages];$this->dependencies=$receivers->dependencies+$chunks->dependencies; }
    }
}
