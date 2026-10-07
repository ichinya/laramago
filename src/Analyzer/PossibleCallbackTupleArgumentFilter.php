<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook};
use PhpParser\{Node,NodeFinder};

/** Typed formal projections from closed literal possible-write tuples. */
final class PossibleCallbackTupleArgumentFilter implements IssueFilterHook
{
    public array $stages=[];public array $dependencies=[];public array $certificate=[];
    public function __construct(private readonly string $root) {}
    public function getCodes():array { return ['mixed-argument']; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $proof=new self($this->root);$decision=$proof->evaluate($context);
        $this->stages=$proof->stages;$this->dependencies=$proof->dependencies;$this->certificate=$proof->certificate;return $decision;
    }
    private function evaluate(IssueFilterContext $context):IssueFilterDecision
    {
        $receivingProof=new SourceArgumentDeclarationContracts($this->root);$tuple=new PossibleCallbackTupleContracts($this->root);
        try {
            $receiving=$receivingProof->receiving($context,['mixed-argument']);if($receiving===null) { return IssueFilterDecision::Keep; }
            ['source'=>$source,'file'=>$file,'argument'=>$argument,'call'=>$call]=$receiving;
            if(!$tuple->receivingDeclaration($context,$source,$receiving)) { return IssueFilterDecision::Keep; }
            $value=$argument->value;
            if(!$receivingProof->stage('one local selected tuple projection',$value instanceof Node\Expr\Variable && is_string($value->name))) { return IssueFilterDecision::Keep; }
            $lexical=$tuple->lexical($source,$file,$call);if($lexical===null) { return IssueFilterDecision::Keep; }
            $position=array_search($value->name,$lexical['names'],true);
            if(!$receivingProof->stage('one exact positional receiving projection',$position!==false)) { return IssueFilterDecision::Keep; }
            $owner=$tuple->bindOwner($context,$source,$file,$lexical,$call);if($owner===null) { return IssueFilterDecision::Keep; }
            foreach((new NodeFinder)->findInstanceOf($lexical['loop']->stmts??[],Node\Expr\Variable::class) as $use) {
                if($use->name===$value->name && $use->getStartFilePos()<$value->getStartFilePos()) {
                    $receivingProof->stage('projected local unchanged before receiving argument',false);return IssueFilterDecision::Keep;
                }
            }
            $domains=[];
            foreach($lexical['appends'] as [$append]) {
                $domain=$tuple->capturedFormal($context,$source,$file,$lexical,$owner,$append->expr->items[$position]->value,$append);
                if($domain===null || !$receivingProof->admits($context,$receiving,$domain)) { return IssueFilterDecision::Keep; }
                $domains[]=(string)$domain;
            }
            $tuple->certify($file,$lexical,['projection'=>$position,'domains'=>$domains,'argumentSpan'=>SourceArgumentDeclarationContracts::span($value)]);
            $this->certificate=$tuple->certificate;return IssueFilterDecision::Remove;
        } finally {
            $this->stages=array_merge($receivingProof->stages,$tuple->stages);
            $this->dependencies=$receivingProof->dependencies+$tuple->dependencies;
        }
    }
}
