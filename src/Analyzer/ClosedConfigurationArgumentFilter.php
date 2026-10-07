<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Close an otherwise unannotated array formal from every nonescaping lexical invocation. */
final class ClosedConfigurationArgumentFilter implements IssueFilterHook
{
    public array $stages=[];
    public array $dependencies=[];
    public array $certificate=[];
    public function __construct(private readonly string $root) {}
    public function getCodes(): array { return ['less-specific-argument']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $proof=new self($this->root);$decision=$proof->evaluate($context);
        $this->stages=$proof->stages;$this->dependencies=$proof->dependencies;$this->certificate=$proof->certificate;
        return $decision;
    }
    private function evaluate(IssueFilterContext $context): IssueFilterDecision
    {
        $contracts=new SourceArgumentDeclarationContracts($this->root);
        try {
            $receiving=$contracts->receiving($context,['less-specific-argument']);
            if ($receiving===null) { return IssueFilterDecision::Keep; }
            ['source'=>$source,'file'=>$file,'call'=>$call,'argument'=>$argument,'native'=>$native]=$receiving;
            if (! $contracts->stage('exact physical configuration parser construction',$receiving['target']==='Illuminate\\Support\\ConfigurationUrlParser::parseConfiguration'
                && str_contains($context->issue->message,'provided type `array<array-key, mixed>` is less specific.')
                && $receiving['index']===0 && count($call->args)===1 && $call instanceof Node\Expr\MethodCall
                && $call->var instanceof Node\Expr\New_ && $call->var->class instanceof Node\Name
                && $call->var->class->toString()==='Illuminate\\Support\\ConfigurationUrlParser' && $call->var->args===[]
                && $argument->value instanceof Node\Expr\Variable && is_string($argument->value->name))) { return IssueFilterDecision::Keep; }
            $name=$argument->value->name;$closure=null;
            foreach ($source->ancestors($call,$file) as $parent) { if ($parent instanceof Node\FunctionLike) { $closure=$parent;break; } }
            $binding=$closure===null?null:($file['parents'][spl_object_id($closure)]??null);
            $formal=$closure?->params[0]??null;
            if (! $contracts->stage('one closed static lexical callback with array formal',$closure instanceof Node\Expr\Closure && $closure->static && ! $closure->byRef
                && $closure->uses===[] && count($closure->params)===1 && $formal instanceof Node\Param
                && $formal->var instanceof Node\Expr\Variable && $formal->var->name===$name && ! $formal->byRef && ! $formal->variadic && $formal->default===null
                && $formal->type instanceof Node\Identifier && strtolower($formal->type->name)==='array'
                && $binding instanceof Node\Expr\Assign && $binding->expr===$closure && $binding->var instanceof Node\Expr\Variable && is_string($binding->var->name))) { return IssueFilterDecision::Keep; }
            foreach ($source->ancestors($binding,$file) as $parent) {
                if ($parent instanceof Node\FunctionLike || $parent instanceof Node\Stmt\ClassLike) {
                    $contracts->stage('top-level lexical configuration binding',false);return IssueFilterDecision::Keep;
                }
            }
            $callbackName=$binding->var->name;$finder=new NodeFinder;$invocations=[];$safe=true;
            foreach ($finder->findInstanceOf($file['nodes'],Node\Expr\Variable::class) as $variable) {
                if ($variable->name!==$callbackName) { continue; }
                $parent=$file['parents'][spl_object_id($variable)]??null;
                if ($variable===$binding->var) { continue; }
                if (! $parent instanceof Node\Expr\FuncCall || $parent->name!==$variable || $parent->getStartFilePos()<=$binding->getEndFilePos()
                    || ! SourceArgumentDeclarationContracts::plain($parent) || count($parent->args)!==1 || ! $parent->args[0]->value instanceof Node\Expr\Array_
                    || ! $this->stringKeys($parent->args[0]->value)) { $safe=false;break; }
                $invocations[]=$parent;
            }
            if (! $contracts->stage('all callback uses are string-key literal invocations',$safe && $invocations!==[])) { return IssueFilterDecision::Keep; }
            foreach ($finder->findInstanceOf($file['nodes'],Node::class) as $node) {
                if ($node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Stmt\Goto_
                    || $node instanceof Node\Expr\Variable && (! is_string($node->name) || $node->name==='GLOBALS')
                    || $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array(strtolower($node->name->getLast()),['extract','parse_str'],true)) {
                    $contracts->stage('closed lexical local table',false);return IssueFilterDecision::Keep;
                }
            }
            // Only the selected receiving argument may read the formal before this call.
            foreach ($finder->findInstanceOf($closure->stmts,Node\Expr\Variable::class) as $variable) {
                if ($variable->name!==$name || $variable->getStartFilePos()>$argument->getEndFilePos()) { continue; }
                $parent=$file['parents'][spl_object_id($variable)]??null;
                if ($variable===$argument->value || $parent instanceof Node\Expr\Assign && $parent->var===$variable && $parent->expr===$call) { continue; }
                $contracts->stage('unchanged selected callback formal before receiving call',false);return IssueFilterDecision::Keep;
            }
            $domain=Type::array(Type::string(),Type::mixed());
            if (! $contracts->admits($context,$receiving,$domain)) { return IssueFilterDecision::Keep; }
            $physical=$source->read($native->location->file);$methods=$physical===null?[]:$finder->findInstanceOf($physical['nodes'],Node\Stmt\ClassMethod::class);
            $matches=array_values(array_filter($methods,static fn(Node\Stmt\ClassMethod $method):bool=>$method->name->name==='parseConfiguration' && $source->located($native->location,$method,$physical)));
            $method=$matches[0]??null;$class=$method===null?null:($physical['parents'][spl_object_id($method)]??null);
            $parameter=$method?->params[0]??null;$nativeParameter=$native->parameters[0]??null;
            if (! $contracts->stage('current physical native receiving parser declaration',count($matches)===1
                && $class instanceof Node\Stmt\Class_ && $class->namespacedName?->toString()==='Illuminate\\Support\\ConfigurationUrlParser'
                && $native->identifier->class==='Illuminate\\Support\\ConfigurationUrlParser' && $native->identifier->name==='parseConfiguration'
                && $source->located($native->nameLocation,$method->name,$physical) && count($method->params)===1
                && $parameter instanceof Node\Param && ! $parameter->byRef && ! $parameter->variadic && $parameter->default===null
                && $parameter->type===null && $parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)
                && $nativeParameter->name==='$'.$parameter->var->name && $nativeParameter->declaredType===null
                && ! $nativeParameter->flags->contains(MetadataFlags::HAS_DEFAULT) && $nativeParameter->defaultType===null
                && $source->located($nativeParameter->location,$parameter,$physical)
                && $source->located($nativeParameter->nameLocation,$parameter->var,$physical))) { return IssueFilterDecision::Keep; }
            $this->certificate=['file'=>$context->file,'sourceSha256'=>$file['hash'],'callbackSpan'=>SourceArgumentDeclarationContracts::span($closure),
                'bindingSpan'=>SourceArgumentDeclarationContracts::span($binding),'argumentSpan'=>SourceArgumentDeclarationContracts::span($argument->value),
                'invocationSpans'=>array_map(SourceArgumentDeclarationContracts::span(...),$invocations),'domain'=>'array<string,mixed>',
                'opaqueClosureIdentifierClaimed'=>false,'guaranteedCallbackExecutionClaimed'=>false,'nativeTypesReplaced'=>false,'afterFileAuthority'=>false];
            return IssueFilterDecision::Remove;
        } finally { $this->stages=$contracts->stages;$this->dependencies=$contracts->dependencies; }
    }
    private function stringKeys(Node\Expr\Array_ $array): bool
    {
        foreach ($array->items as $item) {
            if (! $item instanceof Node\ArrayItem || $item->unpack || $item->byRef || ! $item->key instanceof Node\Scalar\String_
                || preg_match('/^(?:0|-?[1-9][0-9]*)$/D',$item->key->value)===1) { return false; }
        }
        return true;
    }
}
