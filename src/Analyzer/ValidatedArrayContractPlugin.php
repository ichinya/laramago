<?php
declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\{ConditionalArrayGuards,DiagnosticArrayTypes,ValidatedSortedArrayProofs};
use Mago\Sdk\Analyzer\{FunctionReturnTypeProvider,FunctionTarget,InitializationContext,InitializationHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,ReturnTypeProviderContext,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{ArrayItem,ArrayKey,ArrayKeyKind,CallableType,KeyedArrayType,ListType};
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\{Node,NodeFinder,NodeTraverser,ParserFactory};
use PhpParser\NodeVisitor\NameResolver;


/** Worker-independent lexical arrow proof with genuine named caller anchors; native types stay intact. */
final class ValidatedArrayContractPlugin implements Plugin,InitializationHook,FunctionReturnTypeProvider,IssueFilterHook
{
    private array $callbacks=[];
    private array $sources=[];
    public array $lastProof=[];
    public array $lastTrace=[];
    public function __construct(private readonly string $root = '') {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('ichinya/validated-array-contracts','Validated array contracts','Source-bound validated list and conditional array contracts.'); }
    public function register(PluginRegistry $registry):void {
        $registry->registerInitializationHook($this);
        $registry->registerFunctionReturnTypeProvider($this);
        $registry->registerIssueFilterHook($this);
    }
    public function initialize(InitializationContext $context):void { $this->callbacks=$this->sources=[]; }
    public function getTargets():array { return [FunctionTarget::exact('usort')]; }
    public function getCodes():array { return ['mixed-array-access','less-specific-nested-return-statement']; }

    public function getReturnType(ReturnTypeProviderContext $context):?Type {
        $call=$context->invocation;
        if(strcasecmp($call->name,'usort')!==0||count($call->arguments)!==2) { return null; }
        foreach($call->arguments as $argument) { if($argument->name!==null||$argument->unpacked||$argument->placeholder) { return null; } }
        foreach($call->arguments[1]->type?->atomicTypes??[] as $atom) {
            if(!$atom instanceof CallableType||$atom->signature===null||$atom->signature->source===null) { continue; }
            $identifier=$atom->alias??$atom->signature->source;
            $callback=$context->codebase->getFunctionLike($identifier);
            if($callback===null||!$callback->identifier->equals($identifier)||!$callback->identifier->equals($atom->signature->source)
                ||count($callback->parameters)!==2||count($atom->signature->parameters)!==2||$callback->templates!==[]||$callback->globalsAccessed!==[]
                ||$callback->flags->contains(MetadataFlags::BY_REFERENCE)||$callback->location->span->start!==$call->arguments[1]->span->start
                ||$callback->location->span->end!==$call->arguments[1]->span->end) { continue; }
            $valid=$atom->signature->returnType!==null&&$context->types->isContainedBy($atom->signature->returnType,Type::int());
            foreach($callback->parameters as $index=>$parameter) {
                $signature=$atom->signature->parameters[$index];
                $valid=$valid&&!$parameter->flags->contains(MetadataFlags::BY_REFERENCE)&&!$parameter->flags->contains(MetadataFlags::VARIADIC)
                    &&$parameter->outType===null&&$parameter->closureThisType===null&&$parameter->declaredType===null
                    &&!$signature->byReference&&!$signature->variadic&&!$signature->hasDefault&&$signature->closureThisType===null;
            }
            $bytes=$this->bytes($callback->location->file);
            if(!$valid||$bytes===null||substr($bytes,$call->arguments[1]->span->start,$call->arguments[1]->span->end-$call->arguments[1]->span->start)!==$call->arguments[1]->expression) { continue; }
            $key=self::callbackKey($callback->location->file,$callback->location->span->start,$callback->location->span->end);
            if(count($this->callbacks)>=128&&!isset($this->callbacks[$key])) { unset($this->callbacks[array_key_first($this->callbacks)]); }
            $this->callbacks[$key]=['sourceHash'=>hash('sha256',$bytes),'sortSpan'=>[$call->span->start,$call->span->end],
                'callbackSpan'=>[$callback->location->span->start,$callback->location->span->end],
                'leftExpression'=>$call->arguments[0]->expression,'parameters'=>array_map(static fn($parameter):array=>['name'=>$parameter->name,'span'=>[$parameter->nameLocation->span->start,$parameter->nameLocation->span->end]],$callback->parameters),
                'nativeIntegerReturn'=>true];
        }
        return null;
    }

    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        // RPCs can reenter the registered hook. This evaluator is never registered and owns this call's source and receipts.
        $evaluation=new self($this->root);
        $decision=$evaluation->evaluateIssue($context);
        $this->lastProof=$evaluation->lastProof;$this->lastTrace=$evaluation->lastTrace;
        return $decision;
    }
    private function evaluateIssue(IssueFilterContext $context):IssueFilterDecision {
        $this->lastProof=$this->lastTrace=[];
        $primary=null;
        foreach($context->issue->annotations as $annotation) {
            if($annotation->kind!==AnnotationKind::Primary) { continue; }
            if($primary!==null||$annotation->file!==null&&$annotation->file!=='') { return IssueFilterDecision::Keep; }
            $primary=$annotation;
        }
        if($primary===null||$context->issue->level->name!=='Error'||strlen($context->contents)>1024*1024
            ||$primary->span->start<0||$primary->span->end>strlen($context->contents)||!$this->current($context->file,$context->contents)) { return IssueFilterDecision::Keep; }
        if(count($context->issue->annotations)!==1 || $context->issue->edits!==[] || $context->issue->link!==null) { return IssueFilterDecision::Keep; }
        $mixed=$context->issue->code==='mixed-array-access'&&$context->issue->message==='Unsafe array access on type `mixed`.'
            &&$primary->message==='Cannot safely access index because base type is `mixed`.'
            &&$context->issue->notes===['The variable being accessed might not be an array at runtime.']
            &&$context->issue->help==='Ensure the variable holds an array before accessing an index, potentially using type checks or assertions.';
        $return=$context->issue->code==='less-specific-nested-return-statement'
            &&preg_match('/^Returned type `([^`]+)` is less specific than the declared return type `([^`]+)` for function `([^`]+)` due to nested \'mixed\'\.$/D',$context->issue->message,$returnMatch)===1;
        if($return && ($primary->message!=="Returned value's type is too general here due to nested mixed"
            || $context->issue->notes!==["The analysis detected 'mixed' within the structure of the returned value, making the overall type less specific than what the function declared."]
            || $context->issue->help!=="Ensure the structure returned by `{$returnMatch[3]}` strictly adheres to the types specified in the `{$returnMatch[2]}` return type declaration.")) { return IssueFilterDecision::Keep; }
        if(!$mixed&&!$return) { return IssueFilterDecision::Keep; }
        foreach($this->source($context->file,$context->contents)['candidates']??[] as $proof) {
            if(($proof['sourceStructureAccepted']??false)!==true) { continue; }
            $span=[$primary->span->start,$primary->span->end];
            if($mixed&&!in_array($span,$proof['comparatorReadSpans'],true)||$return&&$span!==$proof['returnExpressionSpan']) { continue; }
            // Recomputed in this issue worker from exact current bytes. A provider worker's callback map has no authority.
            if(($proof['lexicalCallback']['authority']??null)!=='closed lexical arrow integer grammar'
                ||($proof['lexicalCallback']['integerResult']??false)!==true||$proof['sourceSha256']!==hash('sha256',$context->contents)) { continue; }
            $certificate=$this->sortedNative($context,$proof);
            if($certificate===null) { continue; }
            if($return) {
                $name=($proof['owner']===null?'':$proof['owner'].'::').$proof['name'];$expected=DiagnosticArrayTypes::parse($returnMatch[2]);
                if(strcasecmp($name,$returnMatch[3])!==0||$expected===null||!DiagnosticArrayTypes::same($expected,$certificate['caller']->returnType->type,$context->types)) { continue; }
                $relaxed=false;
                if(!DiagnosticArrayTypes::contains($certificate['output'],$expected,$context->types,$relaxed)) { continue; }
            }
            $this->lastProof=['authority'=>'closed lexical arrow integer grammar with genuine named caller anchors',
                'sourceSha256'=>hash('sha256',$context->contents),'file'=>$context->file,'sortSpan'=>$proof['sortSpan'],
                'callbackSpan'=>$proof['callbackSpan'],'callbackExpressionSpan'=>$proof['lexicalCallback']['expressionSpan'],
                'callbackParameters'=>$proof['callbackParameters'],'integerResult'=>true,
                'nativeCaller'=>$certificate['caller']->identifier,'nativeCallerLocation'=>$certificate['caller']->location,
                'nativeCallerNameLocation'=>$certificate['caller']->nameLocation,'nativeFormalLocation'=>$certificate['caller']->parameters[0]->location,
                'providerCacheUsed'=>false,'nativeClosureIdentityClaimed'=>false];
            return IssueFilterDecision::Remove;
        }
        if($mixed) {
            foreach(ConditionalArrayGuards::lexicalScriptProofs($context->contents) as $proof) {
                if(ValidatedSortedArrayProofs::span($proof['access'])!==[$primary->span->start,$primary->span->end]) { continue; }
                $proof+=['file'=>$context->file,'diskFile'=>$this->disk($context->file),'hash'=>hash('sha256',$context->contents)];
                if(!ConditionalArrayGuards::current($proof)||!ConditionalArrayGuards::valid($context->codebase,$proof)||!$this->nativeGetopt($context,$proof)) { continue; }
                return IssueFilterDecision::Remove;
            }
        }
        return IssueFilterDecision::Keep;
    }

    private function sortedNative(IssueFilterContext $context,array $proof):?array {
        $caller=$proof['owner']===null?$context->codebase->getFunction($proof['name']):$context->codebase->getDeclaringMethod($proof['owner'],$proof['name']);
        $this->lastTrace[]=['stage'=>'genuine named caller anchors','bound'=>$this->bound($caller,$proof,$context->file),
            'nativeIdentifier'=>$caller?->identifier,'nativeLocation'=>$caller?->location,'nativeNameLocation'=>$caller?->nameLocation,
            'sourceScopeSpan'=>$proof['scopeSpan'],'sourceDeclarationStarts'=>$proof['sourceDeclarationStarts'],'sourceNameSpan'=>$proof['nameSpan']];
        if(!$this->bound($caller,$proof,$context->file)||$caller->templates!==[]||count($caller->parameters)!==1||$caller->returnType===null
            ||$caller->declaredReturnType===null||$caller->flags->contains(MetadataFlags::BY_REFERENCE)) { return null; }
        $formal=$caller->parameters[0];$input=$formal->type?->type;
        $this->lastTrace[]=['stage'=>'genuine native caller formal','nativeLocation'=>$formal->location,'nativeNameLocation'=>$formal->nameLocation,
            'sourceFormalSpan'=>$proof['formalSpan'],'sourceFormalVariableSpan'=>$proof['formalVariableSpan'],'nativeDeclaredType'=>$formal->declaredType,'nativeEffectiveType'=>$formal->type];
        if($formal->name!=='$'.$proof['root']||[$formal->nameLocation->span->start,$formal->nameLocation->span->end]!==$proof['formalVariableSpan']
            ||\Ichinya\Laramago\Analyzer\StaticAnalysis\ConditionalArrayGuards::path($formal->location->file)!==\Ichinya\Laramago\Analyzer\StaticAnalysis\ConditionalArrayGuards::path($context->file)
            ||[$formal->location->span->start,$formal->location->span->end]!==$proof['formalSpan']
            ||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null||$formal->closureThisType!==null
            ||$formal->declaredType===null||$formal->declaredType->fromDocblock||$formal->type===null||!$formal->type->fromDocblock
            ||count($input->atomicTypes)!==1||!$input->atomicTypes[0] instanceof ListType) { return null; }
        foreach($proof['guardCalls'] as $call) {
            $name=ltrim(strtolower($call['name']),'\\');$native=$context->codebase->getFunction($name);
            if(!$this->builtin($context,$native,$name,$call['namespacedName'])||count($native->parameters)<$call['arguments']) { return null; }
            foreach(array_slice($native->parameters,0,$call['arguments']) as $parameter) {
                if($parameter->flags->contains(MetadataFlags::BY_REFERENCE)||$parameter->flags->contains(MetadataFlags::VARIADIC)||$parameter->outType!==null) { return null; }
            }
        }
        $sort=$context->codebase->getFunction('usort');
        if(!$this->builtin($context,$sort,'usort',$proof['sortNamespacedName'])||count($sort->parameters)!==2
            ||!$sort->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE)||$sort->parameters[0]->flags->contains(MetadataFlags::VARIADIC)
            ||$sort->parameters[1]->flags->contains(MetadataFlags::BY_REFERENCE)||$sort->parameters[1]->flags->contains(MetadataFlags::VARIADIC)||$sort->parameters[1]->outType!==null) { return null; }
        foreach($proof['scalarHelperCalls'] as $helper) {
            $method=$context->codebase->getDeclaringMethod($helper['class'],$helper['method']);
            if($method===null||!$method->static||$method->templates!==[]||$method->globalsAccessed!==[]||$method->flags->contains(MetadataFlags::BY_REFERENCE)
                ||count($method->parameters)!==count($helper['arguments'])||!$this->helperSourceBound($method,$helper)) { return null; }
            foreach($helper['arguments'] as $index=>$argument) {
                $parameter=$method->parameters[$index];$type=DiagnosticArrayTypes::parse($argument['type']);
                if($type===null||$parameter->declaredType===null||$parameter->declaredType->fromDocblock||$parameter->outType!==null||$parameter->closureThisType!==null
                    ||$parameter->flags->contains(MetadataFlags::BY_REFERENCE)||$parameter->flags->contains(MetadataFlags::VARIADIC)
                    ||!$context->types->isContainedBy($type,$parameter->declaredType->type)) { return null; }
            }
        }
        $items=[];
        foreach($proof['fields'] as $field=>$type) { $parsed=DiagnosticArrayTypes::parse($type);if($parsed===null) { return null; }$items[]=new ArrayItem(new ArrayKey(ArrayKeyKind::String,$field),false,$parsed); }
        $output=Type::list(Type::fromAtomic(new KeyedArrayType($items,Type::union(Type::int(),Type::string()),Type::mixed(),true)));
        $relaxed=false;
        if(!DiagnosticArrayTypes::contains($output,$caller->returnType->type,$context->types,$relaxed)) { return null; }
        $physicalRelaxed=false;
        if(!DiagnosticArrayTypes::contains($output,$caller->declaredReturnType->type,$context->types,$physicalRelaxed)) { return null; }
        return ['caller'=>$caller,'output'=>$output];
    }

    private function helperSourceBound(FunctionLikeMetadata $method,array $helper):bool {
        $bytes=$this->bytes($method->location->file);if($bytes===null) { return false; }
        try { $nodes=(new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes)??[]); }
        catch(\PhpParser\Error) { return false; }
        foreach((new NodeFinder)->findInstanceOf($nodes,Node\Stmt\Class_::class) as $class) {
            if(strcasecmp($class->namespacedName?->toString()??'',$helper['class'])!==0) { continue; }
            foreach($class->getMethods() as $syntax) {
                if(strcasecmp($syntax->name->toString(),$helper['method'])!==0||!$syntax->isStatic()||$syntax->byRef||count($syntax->params)!==count($helper['arguments'])) { continue; }
                if(!$this->bound($method,['owner'=>$helper['class'],'name'=>$helper['method'],'scopeSpan'=>ValidatedSortedArrayProofs::span($syntax),
                    'sourceDeclarationStarts'=>[$syntax->getStartFilePos(),$syntax->getDocComment()?->getStartFilePos()??$syntax->getStartFilePos()],
                    'nameSpan'=>ValidatedSortedArrayProofs::span($syntax->name)],$method->location->file)) { return false; }
                foreach($syntax->params as $index=>$parameter) {
                    $native=$method->parameters[$index];
                    if($parameter->byRef||$parameter->variadic||$parameter->flags!==0||!$parameter->var instanceof Node\Expr\Variable||!is_string($parameter->var->name)
                        ||!$parameter->type instanceof Node\Identifier||!in_array(strtolower($parameter->type->name),['string','int','float','bool'],true)
                        ||$native->name!=='$'.$parameter->var->name||[$native->nameLocation->span->start,$native->nameLocation->span->end]!==ValidatedSortedArrayProofs::span($parameter->var)) { return false; }
                }
                return true;
            }
        }
        return false;
    }

    private function nativeGetopt(IssueFilterContext $context,array $proof):bool {
        $calls=array_values(array_filter($proof['calls'],static fn(Node\Expr\FuncCall $call):bool=>strcasecmp($call->name->toString(),'getopt')===0));
        if(count($calls)!==1||count($calls[0]->args)!==2) { return false; }
        $native=$context->codebase->getFunction('getopt');
        if(!$this->builtin($context,$native,'getopt',$calls[0]->name->getAttribute('namespacedName')?->toString())||count($native->parameters)<2) { return false; }
        foreach(array_slice($native->parameters,0,2) as $parameter) {
            if($parameter->flags->contains(MetadataFlags::BY_REFERENCE)||$parameter->flags->contains(MetadataFlags::VARIADIC)||$parameter->outType!==null) { return false; }
        }
        return true;
    }
    private function builtin(IssueFilterContext $context,?FunctionLikeMetadata $native,string $name,?string $namespaced):bool {
        return $native!==null&&$native->flags->contains(MetadataFlags::BUILTIN)&&$native->identifier->class===null&&strcasecmp($native->identifier->name,$name)===0
            &&($namespaced===null||strcasecmp($namespaced,$name)===0||$context->codebase->getFunction($namespaced)===null);
    }
    private function bound(?FunctionLikeMetadata $metadata,array $proof,string $file):bool {
        return $metadata!==null&&strcasecmp($metadata->identifier->class??'',$proof['owner']??'')===0&&strcasecmp($metadata->identifier->name,$proof['name'])===0
            &&!$metadata->flags->contains(MetadataFlags::BUILTIN)&&$metadata->flags->contains(MetadataFlags::USER_DEFINED)
            &&$metadata->kind===($proof['owner']===null?\Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Function_:\Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Method)
            &&$metadata->identifier->kind===($proof['owner']===null?\Mago\Sdk\Analyzer\Type\FunctionLikeKind::Function_:\Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method)
            &&ConditionalArrayGuards::path($metadata->location->file)===ConditionalArrayGuards::path($file)
            &&in_array($metadata->location->span->start,$proof['sourceDeclarationStarts']??[$proof['scopeSpan'][0]],true)&&$metadata->location->span->end===$proof['scopeSpan'][1]
            &&$metadata->nameLocation!==null&&[$metadata->nameLocation->span->start,$metadata->nameLocation->span->end]===$proof['nameSpan'];
    }
    private function source(string $file,string $contents):array {
        $key=hash('sha256',$file."\0".$contents);
        if(!isset($this->sources[$key])) {
            if(count($this->sources)>=8) { unset($this->sources[array_key_first($this->sources)]); }
            try { $this->sources[$key]=(new ValidatedSortedArrayProofs)->inspect($contents); }
            catch(\PhpParser\Error) { $this->sources[$key]=[]; }
        }
        return $this->sources[$key];
    }
    private function disk(string $file):string {
        $path=ConditionalArrayGuards::path($file);
        return str_starts_with($path,'/')||preg_match('~^[a-z]:/~i',$path)===1?$path:ConditionalArrayGuards::path($this->root).'/'.$path;
    }
    private function bytes(string $file):?string { $disk=$this->disk($file);$size=@filesize($disk);$bytes=$size!==false&&$size<=1024*1024?@file_get_contents($disk):false;return $bytes===false?null:$bytes; }
    private function current(string $file,string $contents):bool { $bytes=$this->bytes($file);return $bytes!==null&&hash('sha256',$bytes)===hash('sha256',$contents); }
    private static function callbackKey(string $file,int $start,int $end):string { return ConditionalArrayGuards::path($file).':'.$start.':'.$end; }
}
