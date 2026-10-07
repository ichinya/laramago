<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\{GuardedStringCastContracts as Source,SourceArgumentDeclarationContracts as Declaration,SelectedChunkCollectionContracts};
use PhpParser\{Node,NodeFinder};

/** Closed source lifetime for one later model call; no callback execution or generic mutation. */
final class SelectedChunkModelWarningSource
{
    public array $stages=[];
    public function __construct(private readonly string $root){}
    public function lexical(Source $source,array $file,Node\Expr\MethodCall $call):?array
    {
        if(!Declaration::plain($call)||$call->args!==[]||!$call->name instanceof Node\Identifier||!$call->var instanceof Node\Expr\Variable||!is_string($call->var->name)){return null;}
        $loop=null;$closure=null;$scope=null;$class=null;
        foreach($source->ancestors($call,$file) as $parent){
            if($parent instanceof Node\Expr\Closure||$parent instanceof Node\Expr\ArrowFunction){if($closure!==null||!$parent instanceof Node\Expr\Closure){return null;}$closure=$parent;}
            if($loop===null&&$parent instanceof Node\Stmt\Foreach_){$loop=$parent;}
            if($scope===null&&$parent instanceof Node\Stmt\ClassMethod){$scope=$parent;}
            if($parent instanceof Node\Stmt\Class_){$class=$parent;break;}
        }
        if($loop===null||$closure===null||$scope===null||$class===null||$loop->byRef||!$loop->expr instanceof Node\Expr\Variable||!is_string($loop->expr->name)||!$loop->valueVar instanceof Node\Expr\Variable||$loop->valueVar->name!==$call->var->name||($file['parents'][spl_object_id($loop)]??null)!==$closure){return null;}
        $formal=$closure->params[0]??null;if($formal===null||!$formal->var instanceof Node\Expr\Variable||$formal->var->name!==$loop->expr->name){return null;}
        $finder=new NodeFinder;$uses=$finder->findInstanceOf($closure->stmts??[],Node\Expr\Variable::class);$first=null;
        foreach($uses as $use){if($use->name!==$loop->expr->name||$use->getStartFilePos()>=$loop->getStartFilePos()){continue;}if($first===null||$use->getStartFilePos()<$first->getStartFilePos()){$first=$use;}}
        $argument=$first===null?null:($file['parents'][spl_object_id($first)]??null);$helper=$argument instanceof Node\Arg?($file['parents'][spl_object_id($argument)]??null):null;
        if(!$helper instanceof Node\Expr\MethodCall||!Declaration::plain($helper)||count($helper->args)!==1||$helper->args[0]!==$argument||!$helper->name instanceof Node\Identifier||!$helper->var instanceof Node\Expr\Variable||$helper->var->name!=='this'||$helper->getEndFilePos()>=$loop->getStartFilePos()){return null;}
        foreach($uses as $use){
            if(!is_string($use->name)){return null;}
            if($use->name===$loop->expr->name&&$use!==$first&&$use!==$loop->expr){return null;}
            if($use->name===$call->var->name&&$use!==$loop->valueVar){
                $parent=$file['parents'][spl_object_id($use)]??null;
                if($use->getStartFilePos()<$loop->getStartFilePos()||$use->getEndFilePos()>$loop->getEndFilePos()||!($parent instanceof Node\Expr\PropertyFetch&&$parent->var===$use||$parent instanceof Node\Expr\MethodCall&&$parent->var===$use)){return null;}
            }
        }
        if($finder->findFirst([$loop],static fn(Node $n):bool=>$n instanceof Node\Expr\AssignRef||$n instanceof Node\Stmt\Global_||$n instanceof Node\Stmt\Static_||$n instanceof Node\Expr\ClosureUse&&$n->byRef&&$n->var->name===$call->var->name||$n instanceof Node\Stmt\Foreach_&&$n->byRef)!==null){return null;}
        $receiving=array_values(array_filter($class->getMethods(),static fn(Node\Stmt\ClassMethod $m):bool=>strcasecmp($m->name->name,$helper->name->name)===0));$method=$receiving[0]??null;
        if(count($receiving)!==1||$method->isStatic()||$method->isAbstract()||$method->byRef||count($method->params)!==1||!$method->returnType instanceof Node\Identifier||strtolower($method->returnType->name)!=='array'){return null;}
        $parameter=$method->params[0];if(!$parameter->type instanceof Node\Name||$parameter->type->toString()!=='Illuminate\\Database\\Eloquent\\Collection'||!$parameter->var instanceof Node\Expr\Variable||!is_string($parameter->var->name)||$parameter->byRef||$parameter->variadic||$parameter->default!==null||$parameter->flags!==0||$parameter->attrGroups!==[]){return null;}
        $readCount=0;foreach($finder->findInstanceOf($method->stmts??[],Node\Expr\Variable::class) as $use){
            if(!is_string($use->name)){return null;}if($use->name!==$parameter->var->name){continue;}$parent=$file['parents'][spl_object_id($use)]??null;
            if(!$parent instanceof Node\Stmt\Foreach_||$parent->expr!==$use||$parent->byRef||!$parent->valueVar instanceof Node\Expr\Variable||!is_string($parent->valueVar->name)){return null;}$readCount++;
        }
        if($readCount!==1||$finder->findFirst([$method],static fn(Node $n):bool=>$n instanceof Node\Expr\AssignRef||$n instanceof Node\Stmt\Global_||$n instanceof Node\Stmt\Static_||$n instanceof Node\Expr\Eval_||$n instanceof Node\Expr\Include_)!==null){return null;}
        $chunk=new SelectedChunkCollectionContracts($this->root);$lexical=$chunk->lexical($source,$file,$helper,$first);$this->stages=$chunk->stages;if($lexical===null){return null;}
        // Retain only the exact selected source shells; all file-wide nodes and parents are discarded by the caller.
        $classShell=clone $class;$classShell->stmts=[];$scopeShell=clone $scope;$scopeShell->stmts=[];$closureShell=clone $lexical['closure'];$closureShell->stmts=[];$chunkShell=clone $lexical['chunk'];$chunkShell->args=[];
        $lexical['scope']=$scopeShell;$lexical['closure']=$closureShell;$lexical['chunk']=$chunkShell;$lexical['origin']=self::shell($lexical['origin']);unset($lexical['query'],$lexical['start']);
        $compactFile=['path'=>$file['path'],'contents'=>$file['contents'],'hash'=>$file['hash'],'nodes'=>[$classShell],'parents'=>[spl_object_id($chunkShell)=>$scopeShell,spl_object_id($scopeShell)=>$classShell]];
        return ['lexical'=>$lexical,'file'=>$compactFile,'model'=>$lexical['model'],'method'=>strtolower($call->name->name),'call'=>Declaration::span($call),'name'=>Declaration::span($call->name),'receiver'=>Declaration::span($call->var),'loop'=>Declaration::span($loop),'helper'=>self::method($method),'helperReadOnlyFormal'=>true,'callerWholeAstRetained'=>false];
    }
    private static function shell(Node $node):Node{$copy=clone $node;if($copy instanceof Node\Expr\Assign){$copy->expr=new Node\Expr\ConstFetch(new Node\Name('null'));}return $copy;}
    public static function method(Node\Stmt\ClassMethod $method):array
    {
        $starts=[$method->getStartFilePos()];foreach($method->getComments() as $comment){$starts[]=$comment->getStartFilePos();}foreach($method->attrGroups as $group){$starts[]=$group->getStartFilePos();}
        return ['name'=>$method->name->name,'span'=>Declaration::span($method),'starts'=>$starts,'nameSpan'=>Declaration::span($method->name),'static'=>$method->isStatic(),'public'=>$method->isPublic(),'protected'=>$method->isProtected(),'private'=>$method->isPrivate(),'returnSpan'=>$method->returnType===null?null:Declaration::span($method->returnType),'parameters'=>array_map(static fn(Node\Param $p):array=>['name'=>'$'.$p->var->name,'span'=>Declaration::span($p),'nameSpan'=>Declaration::span($p->var),'typeSpan'=>$p->type===null?null:Declaration::span($p->type),'byRef'=>$p->byRef,'variadic'=>$p->variadic],$method->params)];
    }
}
