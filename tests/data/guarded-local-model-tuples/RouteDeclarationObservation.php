<?php
declare(strict_types=1);
namespace Example\LocalModelTests;

use Mago\Sdk\Analyzer\IssueFilterContext;
use PhpParser\{Node,NodeFinder,NodeTraverser};
use PhpParser\NodeVisitor\NameResolver;

/** Test-only collection after candidate evaluation. It never authorizes a decision. */
final class RouteDeclarationObservation
{
    public static function collect(IssueFilterContext $context,callable $project):array
    {
        $nodes=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($context->contents)??[];
        $nodes=(new NodeTraverser(new NameResolver))->traverse($nodes);
        $models=[];$receiver=null;$receiverMethods=[];
        foreach((new NodeFinder)->findInstanceOf($nodes,Node\Expr\StaticCall::class) as $call){
            if($call->class instanceof Node\Name&&!$call->class->isSpecialClassName()
                &&$call->name instanceof Node\Identifier&&strtolower($call->name->name)==='query'){
                $models[$call->class->toString()]=true;
            }
        }
        $primary=array_values(array_filter($context->issue->annotations,
            static fn(object $annotation):bool=>$annotation->kind===\Mago\Sdk\Reporting\AnnotationKind::Primary));
        if(count($primary)===1){
            foreach((new NodeFinder)->findInstanceOf($nodes,Node\Stmt\Class_::class) as $class){
                foreach($class->getMethods() as $method){
                    if($method->getStartFilePos()>$primary[0]->span->start||$method->getEndFilePos()+1<$primary[0]->span->end){continue;}
                    $receiver=$class->namespacedName?->toString();
                    foreach((new NodeFinder)->findInstanceOf($method->stmts??[],Node\Expr\MethodCall::class) as $call){
                        if($call->var instanceof Node\Expr\Variable&&$call->var->name==='this'&&$call->name instanceof Node\Identifier){
                            $receiverMethods[$call->name->name]=true;
                        }
                    }
                }
            }
        }
        // Release all observation-only ASTs before SDK requests can reenter a hook.
        unset($nodes,$call,$class,$method);
        if(count($models)>8||count($receiverMethods)>32){throw new \RuntimeException('Selected route observation exceeded its model or receiver bound.');}
        $pairs=[
            ['builderFirst','Illuminate\\Database\\Eloquent\\Builder','first'],
            ['builderGet','Illuminate\\Database\\Eloquent\\Builder','get'],
            ['builderWhere','Illuminate\\Database\\Eloquent\\Builder','where'],
            ['builderForwarder','Illuminate\\Database\\Eloquent\\Builder','__call'],
            ['builderForwardedLock','Illuminate\\Database\\Eloquent\\Builder','lockForUpdate'],
            ['builderLockScope','Illuminate\\Database\\Eloquent\\Builder','scopeLockForUpdate'],
            ['queryLockForUpdate','Illuminate\\Database\\Query\\Builder','lockForUpdate'],
            ['queryLock','Illuminate\\Database\\Query\\Builder','lock'],
            ['modelQuery','Illuminate\\Database\\Eloquent\\Model','query'],
            ['collectionFirst','Illuminate\\Support\\Collection','first'],
            ['newCollection','Illuminate\\Database\\Eloquent\\HasCollection','newCollection'],
            ['resolveCollection','Illuminate\\Database\\Eloquent\\HasCollection','resolveCollectionFromAttribute'],
        ];
        foreach(array_keys($models) as $model){
            foreach(['query','newCollection','newQuery','newModelQuery','newEloquentBuilder','lockForUpdate','scopeLockForUpdate','getKey'] as $method){
                $pairs[]=['model:'.$model.'::'.$method,$model,$method];
            }
        }
        if(is_string($receiver)&&$receiver!==''){
            foreach(array_keys($receiverMethods) as $method){$pairs[]=['receiver:'.$receiver.'::'.$method,$receiver,$method];}
        }
        $methods=[];
        foreach($pairs as [$role,$class,$method]){
            $methods[$role]=['class'=>$class,'method'=>$method,
                'bound'=>$project($context->codebase->getMethod($class,$method)),
                'declaring'=>$project($context->codebase->getDeclaringMethod($class,$method))];
        }
        $classes=[];
        foreach(array_unique(['Illuminate\\Database\\Eloquent\\Builder','Illuminate\\Database\\Query\\Builder',
            'Illuminate\\Database\\Eloquent\\Model','Illuminate\\Database\\Eloquent\\Collection',
            'Illuminate\\Support\\Collection','Illuminate\\Database\\Concerns\\BuildsQueries',
            'Illuminate\\Database\\Eloquent\\HasCollection',...array_keys($models),...($receiver===null?[]:[$receiver])]) as $class){
            $classes[$class]=$project($context->codebase->getClassLike($class));
        }
        return ['authority'=>false,'afterCandidateEvaluation'=>true,'usesActualIssueFilterContext'=>true,
            'fakeContexts'=>0,'methodPairs'=>$methods,'classes'=>$classes,'modelBound'=>8];
    }
}
