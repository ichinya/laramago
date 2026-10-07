<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use PhpParser\{Node,NodeFinder};

/** Declared Closure keys under the default physical ServiceProvider/Container graph. */
final class FactoryFakerClosureBindingSource
{
    private const PROVIDER='Illuminate\\Support\\ServiceProvider';
    private const APPLICATION='Illuminate\\Foundation\\Application';
    private const CONTAINER='Illuminate\\Container\\Container';

    /** Source recipe only. Native receiver/library admission is still required by the caller. */
    public static function classify(Node\Expr\CallLike $call,array $nodes):?array
    {
        if(!$call instanceof Node\Expr\MethodCall||$call->isFirstClassCallable()||!$call->name instanceof Node\Identifier
            ||!in_array(strtolower($call->name->name),['bind','singleton'],true)||count($call->args)!==1
            ||!$call->args[0] instanceof Node\Arg||$call->args[0]->name!==null||$call->args[0]->byRef||$call->args[0]->unpack
            ||!$call->args[0]->value instanceof Node\Expr\Closure){return null;}
        $closure=$call->args[0]->value;
        // Reflection's single non-builtin named return key is independent of PHPDoc
        // or the values eventually returned when the factory is invoked.
        if($closure->byRef||!$closure->returnType instanceof Node\Name||$closure->returnType instanceof Node\Name\Relative
            ||in_array(strtolower($closure->returnType->toString()),['self','static','parent'],true)){return null;}
        if($closure->attrGroups!==[]){return null;}
        // A doc immediately before the Closure argument is attached to Arg by
        // the installed parser, rather than to the Closure expression itself.
        foreach([$call->args[0],$closure] as $documented){
            foreach($documented->getComments() as $comment){
                if($comment instanceof \PhpParser\Comment\Doc&&preg_match('/@(return|param|template|(?:phpstan|psalm)-)\b/',$comment->getText())){return null;}
            }
        }
        $key=$closure->returnType->toString();
        if(preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$~D',$key)!==1){return null;}
        $receiver=$call->var;
        if(!$receiver instanceof Node\Expr\PropertyFetch||!$receiver->name instanceof Node\Identifier||$receiver->name->name!=='app'
            ||!$receiver->var instanceof Node\Expr\Variable||$receiver->var->name!=='this'){return null;}
        $finder=new NodeFinder;$parents=[];
        foreach($finder->find($nodes,static fn(Node $node):bool=>true) as $node){
            foreach($node->getSubNodeNames() as $name){$values=$node->$name;
                foreach(is_array($values)?$values:[$values] as $child){if($child instanceof Node){$parents[spl_object_id($child)]=$node;}}
            }
        }
        $scope=$call;
        while(isset($parents[spl_object_id($scope)])&&!$scope instanceof Node\FunctionLike){$scope=$parents[spl_object_id($scope)];}
        if(!$scope instanceof Node\Stmt\ClassMethod||$scope->isStatic()||$scope->byRef||$scope->params!==[]
            ||strtolower($scope->name->name)!=='register'||$scope->stmts===null){return null;}
        $class=$scope;
        while(isset($parents[spl_object_id($class)])&&!$class instanceof Node\Stmt\ClassLike){$class=$parents[spl_object_id($class)];}
        if(!$class instanceof Node\Stmt\Class_||$class->isAnonymous()||!$class->extends instanceof Node\Name
            ||strcasecmp($class->extends->toString(),self::PROVIDER)!==0||$class->namespacedName===null){return null;}
        $doc=$class->getDocComment()?->getText()??'';
        if(preg_match('/@(property(?:-read|-write)?|mixin|template|(?:phpstan|psalm)-(?:import-)?type)\b/',$doc)){return null;}
        foreach($class->stmts as $member){
            if($member instanceof Node\Stmt\TraitUse){return null;}
            if($member instanceof Node\Stmt\Property){foreach($member->props as $property){if(strcasecmp($property->name->name,'app')===0){return null;}}}
            if($member instanceof Node\Stmt\ClassMethod&&in_array(strtolower($member->name->name),['__construct','__get','__set','__call','__callstatic'],true)){return null;}
        }
        foreach($finder->findInstanceOf([$class],Node\Expr\Variable::class) as $variable){
            if($variable->name!=='this'){continue;}$parent=$parents[spl_object_id($variable)]??null;
            if($parent instanceof Node\Expr\PropertyFetch&&$parent->var===$variable){
                if(!$parent->name instanceof Node\Identifier){return null;}
                if($parent->name->name!=='app'){continue;}
                $use=$parents[spl_object_id($parent)]??null;
                // Container method calls and an instanceof test do not replace the field.
                if($use instanceof Node\Expr\MethodCall&&$use->var===$parent&&$use->name instanceof Node\Identifier){continue;}
                if($use instanceof Node\Expr\Instanceof_&&$use->expr===$parent){continue;}
                return null;
            }
            if($parent instanceof Node\Expr\MethodCall&&$parent->var===$variable&&$parent->name instanceof Node\Identifier){continue;}
            return null;
        }
        $span=static fn(Node $node):array=>[$node->getStartFilePos(),$node->getEndFilePos()+1];
        return ['role'=>'declared-container-closure-key','key'=>$key,'method'=>$call->name->name,'span'=>$span($call),
            'closureReturnSpan'=>$span($closure->returnType),'callerClass'=>$class->namespacedName->toString(),
            'callerClassSpan'=>$span($class),'callerClassNameSpan'=>$span($class->name),'callerMethod'=>$scope->name->name,
            'callerMethodSpan'=>$span($scope),'callerMethodNameSpan'=>$span($scope->name),
            'requiredClasses'=>[self::PROVIDER,self::APPLICATION,self::CONTAINER],
            'requiredMethods'=>[
                [self::PROVIDER,self::PROVIDER,'__construct'],[self::APPLICATION,self::APPLICATION,'register'],
                [self::APPLICATION,self::APPLICATION,'resolveProvider'],[self::APPLICATION,self::CONTAINER,$call->name->name],
                [self::APPLICATION,self::CONTAINER,'bind'],[self::APPLICATION,self::CONTAINER,'bindBasedOnClosureReturnTypes'],
                [self::CONTAINER,'Illuminate\\Support\\Traits\\ReflectsClosures','closureReturnTypes'],
            ],
            'requiredProperty'=>[self::PROVIDER,'$app'],
            'nativeAdmissionClaimed'=>false,'sourceBodiesExecuted'=>false];
    }
}
