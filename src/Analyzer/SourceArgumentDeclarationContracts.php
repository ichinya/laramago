<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Reporting\{AnnotationKind,Level};
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Native receiving declarations and complete source arguments; no issue-text type authority. */
final class SourceArgumentDeclarationContracts
{
    public array $stages=[];
    public array $dependencies=[];
    public function __construct(private readonly string $root) {}
    public function stage(string $name,bool $passed): bool { $this->stages[]=['stage'=>$name,'passed'=>$passed];return $passed; }
    public static function span(Node $node): array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
    public static function plain(Node\Expr\CallLike $call): bool
    {
        return ! $call->isFirstClassCallable() && ! array_filter($call->args,static fn($arg):bool=>! $arg instanceof Node\Arg || $arg->name!==null || $arg->byRef || $arg->unpack);
    }
    public function same(IssueFilterContext $context,?Type $actual,Type $expected): bool
    {
        return $actual!==null && ! $actual->flags->byReference && ! $actual->flags->possiblyUndefined && $context->types->equals($actual,$expected);
    }
    /** @return array{source:GuardedStringCastContracts,file:array,call:Node\Expr\CallLike,argument:Node\Arg,index:int,target:string,native:FunctionLikeMetadata}|null */
    public function receiving(IssueFilterContext $context,array $codes,?string $shape=null): ?array
    {
        $issue=$context->issue;
        if (! $this->stage('complete selected argument Error envelope',$issue->level===Level::Error && in_array($issue->code,$codes,true)
            && count($issue->annotations)===2 && count($issue->notes)===1 && $issue->edits===[] && $issue->link===null)) { return null; }
        $pattern=match($issue->code) {
            'less-specific-argument'=>'/^Argument type mismatch for argument #(\d+) of `([^`]+)`: expected `([^`]+)`, but provided type `([^`]+)` is less specific\.$/D',
            'mixed-argument'=>'/^Invalid argument type for argument #(\d+) of `([^`]+)`: expected `([^`]+)`, but found `mixed`\.$/D',
            'possibly-invalid-argument'=>'/^Possible argument type mismatch for argument #(\d+) of `([^`]+)`: expected `([^`]+)`, but possibly received `([^`]+)`\.$/D',
            default=>'//',
        };
        if (! $this->stage('closed native argument diagnostic',preg_match($pattern,$issue->message,$match)===1 && (int)$match[1]>=1)) { return null; }
        [$primary,$secondary]=$issue->annotations;
        $expected=$match[3];$actual=$issue->code==='mixed-argument'?'mixed':$match[4];
        [$primaryText,$help,$note]=match($issue->code) {
            'less-specific-argument'=>['Provided type `'.$actual.'` is too general.','Provide a value that more precisely matches `'.$expected.'` or adjust the parameter type.',
                'The provided type `'.$actual.'` can be assigned to `'.$expected.'`, but is wider (less specific).'],
            'mixed-argument'=>['Argument has type `mixed`','Add specific type hints or assertions to the argument value.',
                'The type `mixed` is too general and does not match the expected type `'.$expected.'`.'],
            default=>['This might not be type `'.$expected.'`','Ensure the argument always has the expected type using checks or assertions.',
                'The provided type `'.$actual.'` overlaps with `'.$expected.'` but is not fully contained.'],
        };
        if (! $this->stage('one local primary and receiving secondary',$primary->kind===AnnotationKind::Primary && $secondary->kind===AnnotationKind::Secondary
            && ($primary->file===null || $primary->file==='') && ($secondary->file===null || $secondary->file==='')
            && $primary->message===$primaryText && $issue->help===$help && $issue->notes[0]===$note
            && $secondary->message==='Arguments to this '.(str_contains($match[2],'::')?'method':'function').' are incorrect')) { return null; }
        // Only reject shapes that this caller's later AST check cannot accept.
        // Unknown syntax still takes the full physical/native proof path below.
        if ($shape!==null && $primary->span->start>=0 && $primary->span->end>$primary->span->start
            && $primary->span->end<=strlen($context->contents)
            && self::rejectsArgumentShape(substr($context->contents,$primary->span->start,$primary->span->end-$primary->span->start),$shape)) {
            $this->stage('selected argument syntax cannot satisfy this policy',false);
            return null;
        }
        $source=new GuardedStringCastContracts($this->root);$file=$source->read($context->file,$context->contents);
        if (! $this->stage('current complete physical caller source',$file!==null)) { return null; }
        $index=(int)$match[1]-1;$target=$match[2];$calls=[];
        foreach ((new NodeFinder)->findInstanceOf($file['nodes'],Node\Expr\CallLike::class) as $call) {
            $argument=$call->args[$index]??null;
            if (! self::plain($call) || ! $argument instanceof Node\Arg || self::span($argument->value)!==[$primary->span->start,$primary->span->end]) { continue; }
            $name=$call instanceof Node\Expr\FuncCall?$call->name:$call;
            if (! $name instanceof Node || self::span($name)!==[$secondary->span->start,$secondary->span->end]) { continue; }
            if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && ! str_contains($target,'::')) {
                $native=$source->builtinBinding($context,$call,$target);
            } elseif (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) && $call->name instanceof Node\Identifier && str_contains($target,'::')) {
                [$class,$method]=explode('::',$target,2);
                $native=strcasecmp($method,$call->name->name)===0?$context->codebase->getMethod($class,$method):null;
                $declaring=$native===null?null:$context->codebase->getDeclaringMethod($class,$method);
                if ($declaring===null || $declaring->identifier!=$native->identifier) { $native=null; }
            } else { $native=null; }
            if ($native===null) { continue; }$calls[]=[$call,$argument,$native];
        }
        if (! $this->stage('one entire argument and genuinely selected receiving declaration',count($calls)===1)) { return null; }
        [$call,$argument,$native]=$calls[0];$this->dependencies['receiving']=$native;
        $formal=$native->parameters[$index]??null;
        if (! $this->stage('selected by-value formal and unchanged receiving type',$formal!==null && $formal->type!==null && $formal->outType===null
            && ! $formal->flags->contains(MetadataFlags::BY_REFERENCE) && ! $formal->flags->contains(MetadataFlags::VARIADIC)
            && ! $native->flags->contains(MetadataFlags::BY_REFERENCE))) { return null; }
        if ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) {
            $physical=$source->read($native->location->file);
            $owners=$physical===null?[]:(new NodeFinder)->findInstanceOf($physical['nodes'],Node\Stmt\ClassMethod::class);
            $owners=array_values(array_filter($owners,static fn(Node\Stmt\ClassMethod $owner):bool=>$source->located($native->location,$owner,$physical)));
            $owner=$owners[0]??null;$parameter=$owner?->params[$index]??null;
            if (! $this->stage('current physical selected receiving formal',count($owners)===1 && $owner->name->name===$native->identifier->name
                && $source->located($native->nameLocation,$owner->name,$physical) && ! $owner->byRef
                && $parameter instanceof Node\Param && $parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)
                && $formal->name==='$'.$parameter->var->name && ! $parameter->byRef && ! $parameter->variadic
                && $source->located($formal->location,$parameter,$physical) && $source->located($formal->nameLocation,$parameter->var,$physical)
                && ($parameter->type===null?$formal->declaredType===null:$source->located($formal->declaredType?->location,$parameter->type,$physical)))) { return null; }
        }
        return compact('source','file','call','argument','index','target','native');
    }

    /** A conservative rejection shortcut, never authority to suppress an issue. */
    private static function rejectsArgumentShape(string $expression,string $shape): bool
    {
        if (! in_array($shape,['variable','property'],true)) { return false; }
        $expression=ltrim($expression);
        if ($expression==='') { return true; }
        // Parentheses, comments and braced/dynamic variables remain undecided.
        if (! in_array($expression[0],['$','(','/','#'],true)) { return true; }
        $variable='\$[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*';
        if ($shape==='property') {
            return preg_match('~^'.$variable.'$~D',$expression)===1;
        }
        return preg_match('~^'.$variable.'\s*(?:\?->|->|\[|\(|::)~',$expression)===1;
    }
    public function admits(IssueFilterContext $context,array $receiving,Type $domain): bool
    {
        return $this->stage('closed source domain contained in genuine receiving formal',
            $context->types->isContainedBy($domain,$receiving['native']->parameters[$receiving['index']]->type->type));
    }
}
