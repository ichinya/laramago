<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\{MetadataFlags,PropertyMetadata};
use PhpParser\{Node,NodeFinder};

/** A backed property read does not execute its setter-only hook. Native/source hooks must agree. */
final class PhysicalSetOnlyPropertyRead
{
    public static function source(Node\Stmt\Property $property,string $name):?array {
        if(count($property->hooks)!==1||count($property->props)!==1||$property->attrGroups!==[]||$property->isStatic()||$property->isAbstract()||$property->type===null) { return null; }
        $hook=$property->hooks[0];
        if(strtolower($hook->name->name)!=='set'||$hook->byRef||$hook->params!==[]||$hook->attrGroups!==[]||$hook->getDocComment()!==null||!is_array($hook->body)) { return null; }
        $finder=new NodeFinder;
        if($finder->findFirst($hook->body,static fn(Node $node):bool=>$node instanceof Node\Expr\AssignRef||$node instanceof Node\Stmt\Global_||$node instanceof Node\Stmt\Static_
            ||$node instanceof Node\Expr\Eval_||$node instanceof Node\Expr\Include_||$node instanceof Node\Expr\Closure||$node instanceof Node\Expr\ArrowFunction
            ||$node instanceof Node\Expr\Variable&&!is_string($node->name)||$node instanceof Node\Arg&&$node->byRef||$node instanceof Node\Stmt\Return_)!==null) { return null; }
        $writes=$finder->find($hook->body,static fn(Node $node):bool=>$node instanceof Node\Expr\Assign&&$node->var instanceof Node\Expr\PropertyFetch
            &&$node->var->var instanceof Node\Expr\Variable&&$node->var->var->name==='this'&&$node->var->name instanceof Node\Identifier&&$node->var->name->name===$name
            &&$node->expr instanceof Node\Expr\Variable&&$node->expr->name==='value');
        if(count($writes)!==1) { return null; }
        return ['name'=>'set','span'=>[$hook->getStartFilePos(),$hook->getEndFilePos()+1],'backingWriteSpan'=>[$writes[0]->getStartFilePos(),$writes[0]->getEndFilePos()+1],
            'sourceHasNoGetHook'=>true,'sourceBackedByOwnProperty'=>true,'implicitValueParameter'=>true];
    }
    public static function native(IssueFilterContext $context,PropertyMetadata $property,array $source,string $file):bool {
        if(array_keys($property->hooks)!==['set']||$property->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)||$property->attributes!==[]
            ||$property->declaredType===null||$property->declaredType->fromDocblock||$property->declaredType->inferred||$property->type===null||$property->type->inferred
            ||!$context->types->equals($property->declaredType->type,$property->type->type)) { return false; }
        $hook=$property->hooks['set'];
        if(strtolower($hook->name)!=='set'||$hook->abstract||$hook->returnsByReference||$hook->attributes!==[]||$hook->hasDocblock
            ||$hook->flags->contains(MetadataFlags::BY_REFERENCE)||!NullFlowNativeContracts::sameFile($hook->location->file,$file)
            ||[$hook->location->span->start,$hook->location->span->end]!==$source['span']) { return false; }
        $formal=$hook->parameter;
        return $formal===null||($formal->name==='$value'&&!$formal->flags->contains(MetadataFlags::BY_REFERENCE)&&!$formal->flags->contains(MetadataFlags::VARIADIC)
            &&!$formal->flags->contains(MetadataFlags::HAS_DEFAULT)&&$formal->outType===null&&$formal->closureThisType===null&&$formal->defaultType===null);
    }
}
