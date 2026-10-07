<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use PhpParser\{Node,NodeFinder,NodeTraverser,ParserFactory,PrettyPrinter\Standard};
use PhpParser\NodeVisitor\NameResolver;

/** A by-reference scalar-list accumulator is local storage, never an item-storage lifetime promise. */
final class ScopedAccumulatorBorrow
{
    public static function sourceWitness(Node\Stmt\ClassLike $owner,Node\Param $parameter):?array {
        if(!$parameter->byRef||$parameter->variadic||$parameter->type!==null||!$parameter->var instanceof Node\Expr\Variable||!is_string($parameter->var->name)) { return null; }
        $finder=new NodeFinder;$matches=[];
        foreach($finder->findInstanceOf($owner->stmts,Node\Expr\MethodCall::class) as $call) {
            if(!$call->var instanceof Node\Expr\Variable||$call->var->name!=='this'||!$call->name instanceof Node\Identifier||count($call->args)!==2
                ||$call->args[0]->byRef||$call->args[0]->unpack||$call->args[0]->name!==null||$call->args[1]->byRef||$call->args[1]->unpack||$call->args[1]->name!==null) { continue; }
            $closure=$call->args[0]->value;$seed=$call->args[1]->value;
            if(!$closure instanceof Node\Expr\Closure||!$closure->static||$closure->byRef||$closure->returnType!==null||$closure->attrGroups!==[]||$closure->getDocComment()!==null||$parameter->attrGroups!==[]||count($closure->params)!==2||$closure->params[0]!==$parameter
                ||$closure->params[1]->byRef||$closure->params[1]->variadic||$closure->params[1]->type!==null||!$closure->params[1]->var instanceof Node\Expr\Variable
                ||!is_string($closure->params[1]->var->name)||$closure->params[1]->attrGroups!==[]||!$seed instanceof Node\Expr\Array_||count($seed->items)!==2) { continue; }
            foreach($seed->items as $item) { if($item===null||$item->byRef||$item->unpack||$item->key!==null||!$item->value instanceof Node\Scalar\Int_||$item->value->value!==0) { continue 2; } }
            if(count($closure->uses)!==1||$closure->uses[0]->byRef||!is_string($closure->uses[0]->var->name)) { continue; }
            $borrow=$parameter->var->name;$value=$closure->params[1]->var->name;$callback=$closure->uses[0]->var->name;
            if(count(array_unique([$borrow,$value,$callback]))!==3||array_intersect([$borrow,$value,$callback],['resolved','this','GLOBALS'])!==[]||$parameter->default!==null||$closure->params[1]->default!==null) { continue; }
            $forbidden=$finder->findFirst($closure->stmts,static fn(Node $node):bool=>$node instanceof Node\Expr\AssignRef||$node instanceof Node\Stmt\Global_||$node instanceof Node\Expr\Eval_||$node instanceof Node\Expr\Include_
                ||$node instanceof Node\Expr\Closure||$node instanceof Node\Expr\ArrowFunction||$node instanceof Node\Expr\PropertyFetch||$node instanceof Node\Expr\StaticPropertyFetch||$node instanceof Node\Stmt\Static_
                ||$node instanceof Node\Expr\Variable&&(!is_string($node->name)||in_array($node->name,['this','GLOBALS'],true))||$node instanceof Node\ArrayItem&&$node->byRef||$node instanceof Node\Arg&&$node->byRef);
            if($forbidden!==null||count($closure->stmts)!==2||!$closure->stmts[0] instanceof Node\Stmt\If_||!$closure->stmts[1] instanceof Node\Stmt\Return_
                ||!$closure->stmts[1]->expr instanceof Node\Expr\Variable||$closure->stmts[1]->expr->name!==$borrow) { continue; }
            $printer=new Standard;$actual=$printer->prettyPrint($closure->stmts);
            $expected='if (!is_null($resolved = $'.$callback.'($'.$value.'))) {' . "\n".'    $'.$borrow.'[0] += $resolved;' . "\n".'    $'.$borrow.'[1]++;' . "\n".'}' . "\n".'return $'.$borrow.';';
            if($actual!==$expected) { continue; }
            $matches[]=['owner'=>$owner->namespacedName?->toString(),'bridgeMethod'=>$call->name->name,'parameterSpan'=>self::span($parameter),'closureSpan'=>self::span($closure),'callSpan'=>self::span($call),
                'freshScalarSeedSpan'=>self::span($seed),'freshSeedContainsNoReferences'=>true,'staticClosureCapturesNoStorage'=>true,'borrowNeverPassedToCapturedCallback'=>true];
        }
        return count($matches)===1?$matches[0]:null;
    }
    public static function nativeWitness(IssueFilterContext $context,array $witness,callable $path):?array {
        $name=$witness['owner'];if(!is_string($name)||$name==='') { return null; }$native=$context->codebase->getDeclaringMethod($name,$witness['bridgeMethod']);
        if($native===null||$native->nameLocation===null||$native->location->file===null||strcasecmp($native->identifier->name,$witness['bridgeMethod'])!==0
            ||strcasecmp($native->identifier->class??'',$name)!==0
            ||$native->static||$native->abstract||$native->constructor||$native->flags->contains(MetadataFlags::BUILTIN)||$native->flags->contains(MetadataFlags::BY_REFERENCE)||count($native->parameters)!==2) { return null; }
        $file=$path($native->location->file);$bytes=@file_get_contents($file);if($bytes===false||strlen($bytes)>1048576) { return null; }
        try { $nodes=(new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes)??[]); }catch(\PhpParser\Error) { return null; }
        $matches=(new NodeFinder)->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\ClassMethod&&self::span($node->name)===[$native->nameLocation->span->start,$native->nameLocation->span->end]);
        if(count($matches)!==1) { return null; }$method=$matches[0];
        if($native->location->span->start>$method->getStartFilePos()||$native->location->span->end!==$method->getEndFilePos()+1||count($method->params)!==2||$method->byRef||$method->isStatic()
            ||!$method->params[0]->type instanceof Node\Identifier||strtolower($method->params[0]->type->name)!=='callable'||$method->params[1]->type!==null
            ||$native->parameters[0]->declaredType===null||$native->parameters[0]->declaredType->location===null
            ||self::span($method->params[0]->type)!==[$native->parameters[0]->declaredType->location->span->start,$native->parameters[0]->declaredType->location->span->end]) { return null; }
        $names=[];foreach($method->params as $index=>$param) { $formal=$native->parameters[$index];if(!$param->var instanceof Node\Expr\Variable||!is_string($param->var->name)||$param->byRef||$param->variadic
            ||$formal->name!=='$'.$param->var->name||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null||$formal->closureThisType!==null
            ||$formal->flags->contains(MetadataFlags::HAS_DEFAULT)!==($param->default!==null)
            ||self::span($param->var)!==[$formal->nameLocation->span->start,$formal->nameLocation->span->end]) { return null; }$names[]=$param->var->name; }
        if($names[0]===$names[1]||array_intersect($names,['result','key','value','this','GLOBALS'])!==[]||$method->params[0]->default!==null||$native->parameters[0]->defaultType!==null
            ||!$method->params[1]->default instanceof Node\Expr\ConstFetch||strtolower($method->params[1]->default->name->toString())!=='null'
            ||$native->parameters[1]->defaultType===null||!$context->types->equals($native->parameters[1]->defaultType->type,Type::null())) { return null; }
        $expected='$result = $'.$names[1].';' . "\n".'foreach ($this as $key => $value) {' . "\n".'    $result = $'.$names[0].'($result, $value, $key);' . "\n".'}' . "\n".'return $result;';
        if((new Standard)->prettyPrint($method->stmts??[])!==$expected||hash('sha256',$bytes)!==hash_file('sha256',$file)) { return null; }
        $namespace=str_contains($name,'\\')?substr($name,0,strrpos($name,'\\')):'';
        if($namespace!==''&&$context->codebase->getFunction($namespace.'\\is_null')!==null) { return null; }$isNull=$context->codebase->getFunction('is_null');
        if($isNull===null||!$isNull->flags->contains(MetadataFlags::BUILTIN)||count($isNull->parameters)!==1||$isNull->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE)||$isNull->parameters[0]->outType!==null) { return null; }
        return $witness+['actualNativeBridge'=>$native,'bridgeSourceSha256'=>hash('sha256',$bytes),'bridgeParametersByValue'=>true,'freshSeedCopiedToLocalResult'=>true,'noStorageReferenceEscape'=>true,'actualBuiltinNullCheck'=>$isNull];
    }
    private static function span(Node $node):array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
}
