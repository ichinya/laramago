<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook};
use Mago\Sdk\Reporting\{AnnotationKind,Level};
use PhpParser\{Node,NodeFinder};

/** Literal row shape only; unknown fields retain their native argument Errors. */
final class PossibleCallbackTupleShapeFilter implements IssueFilterHook
{
    public array $stages=[];public array $dependencies=[];public array $certificate=[];
    public function __construct(private readonly string $root) {}
    public function getCodes():array { return ['invalid-destructuring-source']; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $proof=new self($this->root);$decision=$proof->evaluate($context);
        $this->stages=$proof->stages;$this->dependencies=$proof->dependencies;$this->certificate=$proof->certificate;return $decision;
    }
    private function evaluate(IssueFilterContext $context):IssueFilterDecision
    {
        $tuple=new PossibleCallbackTupleContracts($this->root);$envelope=new SourceArgumentDeclarationContracts($this->root);
        try {
            $issue=$context->issue;$primary=$issue->annotations[0]??null;
            if(!$envelope->stage('complete native mixed-row destructuring Error',$issue->level===Level::Error && $issue->code==='invalid-destructuring-source'
                && $issue->message==='Invalid destructuring assignment: Cannot unpack type `mixed` into variables.'
                && $issue->notes===['Array destructuring (`[...] = $value;`) requires `$value` to be an array or an object that implements `ArrayAccess`.','Attempting to destructure a non-array type like `mixed` is an undefined behavior in PHP.']
                && $issue->help==='Ensure the value on the right-hand side is an array before attempting to destructure it.' && $issue->link===null && $issue->edits===[]
                && count($issue->annotations)===1 && $primary->kind===AnnotationKind::Primary && ($primary->file===null || $primary->file==='')
                && $primary->message==='Attempting to destructure a value of type `mixed`, which is not an array.')) { return IssueFilterDecision::Keep; }
            $source=new GuardedStringCastContracts($this->root);$file=$source->read($context->file,$context->contents);
            if(!$envelope->stage('current complete row destructuring source',$file!==null)) { return IssueFilterDecision::Keep; }
            $matches=(new NodeFinder)->find($file['nodes'],static fn(Node $node):bool=>($node instanceof Node\Expr\List_ || $node instanceof Node\Expr\Array_)
                && SourceArgumentDeclarationContracts::span($node)===[$primary->span->start,$primary->span->end]);
            if(!$envelope->stage('one full selected destructuring span',count($matches)===1)) { return IssueFilterDecision::Keep; }
            $point=$matches[0];$lexical=$tuple->lexical($source,$file,$point);if($lexical===null) { return IssueFilterDecision::Keep; }
            if($tuple->bindOwner($context,$source,$file,$lexical,$point)===null) { return IssueFilterDecision::Keep; }
            $tuple->certify($file,$lexical,['domain'=>'literal positional array row','fieldTypesReplaced'=>false]);
            $this->certificate=$tuple->certificate;return IssueFilterDecision::Remove;
        } finally { $this->stages=array_merge($envelope->stages,$tuple->stages);$this->dependencies=$tuple->dependencies; }
    }
}
