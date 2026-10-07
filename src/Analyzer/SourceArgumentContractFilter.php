<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{ConditionalType,ScalarType,ScalarTypeKind,VariableType};
use Mago\Sdk\Reporting\{AnnotationKind,Level};
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Two explicit receiving contracts; no general mixed or narrower-type admission. */
final class SourceArgumentContractFilter implements IssueFilterHook
{
    public array $stages = [];
    public array $dependencies = [];
    public array $certificate = [];
    public function __construct(private readonly string $root) {}
    public function getCodes(): array { return ['possibly-invalid-argument']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        // SDK queries can reenter this registered hook. Debug state never carries authority.
        $proof = new self($this->root);
        $decision = $proof->evaluate($context);
        $this->stages = $proof->stages; $this->dependencies = $proof->dependencies; $this->certificate = $proof->certificate;
        return $decision;
    }
    private function stage(string $name,bool $passed): bool { $this->stages[]=['stage'=>$name,'passed'=>$passed];return $passed; }
    private function same(IssueFilterContext $context,?Type $actual,Type $expected): bool
    {
        return $actual !== null && ! $actual->flags->byReference && ! $actual->flags->possiblyUndefined
            && $context->types->equals($actual,$expected);
    }
    private function evaluate(IssueFilterContext $context): IssueFilterDecision
    {
        $issue=$context->issue;
        if (! $this->stage('exact error envelope',$issue->code==='possibly-invalid-argument' && $issue->level===Level::Error
            && count($issue->annotations)===2 && count($issue->notes)===1 && $issue->edits===[] && $issue->link===null
            && $issue->help==='Ensure the argument always has the expected type using checks or assertions.')) { return IssueFilterDecision::Keep; }
        $family=match($issue->message) {
            'Possible argument type mismatch for argument #1 of `usleep`: expected `non-negative-int`, but possibly received `int`.'=>'decimal',
            'Possible argument type mismatch for argument #4 of `proc_open`: expected `non-empty-string|null`, but possibly received `string`.'=>'directory',
            default=>null,
        };
        if (! $this->stage('selected receiving contract',$family!==null)) { return IssueFilterDecision::Keep; }
        $expected=$family==='decimal'?'non-negative-int':'non-empty-string|null';$actual=$family==='decimal'?'int':'string';
        [$primary,$secondary]=$issue->annotations;
        if (! $this->stage('exact annotations and note',$primary->kind===AnnotationKind::Primary && $secondary->kind===AnnotationKind::Secondary
            && ($primary->file===null || $primary->file==='') && ($secondary->file===null || $secondary->file==='')
            && $primary->message==='This might not be type `'.$expected.'`' && $secondary->message==='Arguments to this function are incorrect'
            && $issue->notes[0]==='The provided type `'.$actual.'` overlaps with `'.$expected.'` but is not fully contained.')) { return IssueFilterDecision::Keep; }
        $source=new GuardedStringCastContracts($this->root);$file=$source->read($context->file,$context->contents);
        if (! $this->stage('current physical caller bytes',$file!==null)) { return IssueFilterDecision::Keep; }
        $found=[];
        foreach ((new NodeFinder)->findInstanceOf($file['nodes'],Node\Expr\FuncCall::class) as $call) {
            $index=$family==='decimal'?0:3;$argument=$call->args[$index]??null;
            if (! $call->name instanceof Node\Name || ! $argument instanceof Node\Arg || ! self::plain($call)
                || [$primary->span->start,$primary->span->end]!==self::span($argument->value)
                || [$secondary->span->start,$secondary->span->end]!==self::span($call->name)) { continue; }
            $found[]=[$call,$argument];
        }
        if (! $this->stage('one full argument and callable name span',count($found)===1)) { return IssueFilterDecision::Keep; }
        [$call,$argument]=$found[0];$target=$family==='decimal'?'usleep':'proc_open';
        $receiver=$source->builtinBinding($context,$call,$target);$this->dependencies[$target]=$receiver;
        if (! $this->stage('genuine builtin identity',$receiver!==null && $receiver->templates===[]
            && $receiver->whereConstraints===[] && $receiver->globalsAccessed===[] && ! $receiver->assertionsInferred)) { return IssueFilterDecision::Keep; }
        $passed=$family==='decimal'?$this->decimal($context,$source,$file,$call,$receiver):$this->directory($context,$source,$file,$call,$receiver);
        if (! $passed) { return IssueFilterDecision::Keep; }
        $this->certificate+=['family'=>$family,'file'=>$context->file,'sourceSha256'=>$file['hash'],'argumentSpan'=>self::span($argument->value),
            'callableNameSpan'=>self::span($call->name),'selectedNativeIdentifier'=>$receiver->identifier,
            'nativeTypesReplaced'=>false,'afterFileAuthority'=>false,'workerLocalFactsRequired'=>false];
        return IssueFilterDecision::Remove;
    }
    private function decimal(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,Node\Expr\FuncCall $call,FunctionLikeMetadata $receiver): bool
    {
        $parameter=$receiver->parameters[0]??null;
        if (! $this->stage('usleep formal declared int and effective nonnegative',count($call->args)===1 && count($receiver->parameters)===1
            && $parameter!==null && self::byValue($parameter) && ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
            && $this->same($context,$parameter->declaredType?->type,Type::int())
            && $this->same($context,$parameter->type?->type,Type::nonNegativeInt())
            && $this->same($context,$receiver->declaredReturnType?->type,Type::void())
            && $this->same($context,$receiver->returnType?->type,Type::void()))) { return false; }
        $product=$call->args[0]->value;$cast=$product instanceof Node\Expr\BinaryOp\Mul?$product->left:null;$factor=$product instanceof Node\Expr\BinaryOp\Mul?$product->right:null;
        if (! $this->stage('positive integer multiplier of one local decimal cast',$cast instanceof Node\Expr\Cast\Int_
            && $cast->expr instanceof Node\Expr\Variable && is_string($cast->expr->name) && self::local($cast->expr->name)
            && $factor instanceof Node\Scalar\Int_ && $factor->value>0)) { return false; }
        $variable=$cast->expr->name;$scope=null;
        foreach ($source->ancestors($call,$file) as $parent) { if ($parent instanceof Node\FunctionLike) { $scope=$parent;break; } }
        if ($scope!==null && ! $scope instanceof Node\Stmt\Function_ && ! $scope instanceof Node\Stmt\ClassMethod) { return $this->stage('named owner or top level',false); }
        if ($scope!==null) {
            $owner=$source->owner($context,$call,$file);$this->dependencies['owner']=$owner;
            if (! $this->stage('native named caller binding',$owner!==null)) { return false; }
        }
        $statements=$scope?->getStmts()??$file['nodes'];
        // A namespace is a lexical container, not a runtime function owner.
        if ($scope===null) { foreach ($source->ancestors($call,$file) as $parent) { if ($parent instanceof Node\Stmt\Namespace_) { $statements=$parent->stmts;break; } } }
        $callIndex=null;
        foreach ($statements as $index=>$statement) { if ($statement instanceof Node\Stmt\Expression && $statement->expr===$call) { $callIndex=$index;break; } }
        if (! $this->stage('standalone dominated call',$callIndex!==null)) { return false; }
        $prefix=array_slice($statements,0,$callIndex+1);$finder=new NodeFinder;
        if (! $this->stage('no references or dynamic local table in preceding lifetime',$finder->findFirst($prefix,static fn(Node $node):bool=>
            $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\ClosureUse || $node instanceof Node\Stmt\Foreach_ && $node->byRef
            || $node instanceof Node\Arg && $node->byRef || $node instanceof Node\Expr\Variable && (! is_string($node->name) || $node->name==='GLOBALS')
            || $node instanceof Node\Expr\FuncCall && (! $node->name instanceof Node\Name || in_array(strtolower($node->name->getLast()),['extract','parse_str'],true)))===null
            && ($scope===null || ! $scope->returnsByRef() && ! array_filter($scope->getParams(),static fn(Node\Param $parameter):bool=>$parameter->byRef)))) { return false; }
        for ($guardIndex=$callIndex-1;$guardIndex>=0;$guardIndex--) {
            $guard=$statements[$guardIndex];
            if (! $guard instanceof Node\Stmt\If_ || $guard->else!==null || $guard->elseifs!==[] || count($guard->stmts)!==1
                || ! $guard->stmts[0] instanceof Node\Stmt\Expression || ! $guard->stmts[0]->expr instanceof Node\Expr\Exit_) { continue; }
            $parts=self::disjunction($guard->cond);$string=null;$digits=null;$upper=null;$safe=true;
            foreach ($parts as $part) {
                if ($part instanceof Node\Expr\BooleanNot && $part->expr instanceof Node\Expr\FuncCall && self::plain($part->expr)
                    && count($part->expr->args)===1 && $part->expr->args[0]->value instanceof Node\Expr\Variable && $part->expr->args[0]->value->name===$variable) {
                    $name=strtolower($part->expr->name->getLast());if ($name==='is_string') { $string=$part->expr; }elseif ($name==='ctype_digit') { $digits=$part->expr; }
                }
                if ($part instanceof Node\Expr\BinaryOp\Greater && $part->left instanceof Node\Expr\Cast\Int_
                    && $part->left->expr instanceof Node\Expr\Variable && $part->left->expr->name===$variable && $part->right instanceof Node\Scalar\Int_) { $upper=$part->right->value; }
            }
            // This certificate also holds on a 32-bit target; host width supplies no authority.
            if ($string===null || $digits===null || $upper===null || $upper<0 || $factor->value>2_147_483_647 || $upper>intdiv(2_147_483_647,$factor->value)) { continue; }
            if ($finder->findFirst([$guard->cond],static fn(Node $node):bool=>$node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp
                || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec
                || $node instanceof Node\Expr\CallLike && ! $node instanceof Node\Expr\FuncCall)!==null) { continue; }
            foreach ($finder->findInstanceOf([$guard->cond],Node\Expr\FuncCall::class) as $predicate) {
                $name=strtolower($predicate->name instanceof Node\Name?$predicate->name->getLast():'');
                if (! in_array($name,['is_string','ctype_digit','basename'],true)) { $safe=false;break; }
                $native=$source->builtinBinding($context,$predicate,$name);$this->dependencies[$name]=$native;
                if ($native===null || $native->templates!==[] || $native->globalsAccessed!==[] || array_filter($native->parameters,static fn($p):bool=>!self::byValue($p))) { $safe=false;break; }
                if ($predicate===$string && ! $this->stringPredicate($context,$native)) { $safe=false;break; }
                if ($predicate===$digits && ! $this->digitPredicate($context,$native)) { $safe=false;break; }
            }
            if (! $safe) { continue; }
            foreach (array_slice($statements,$guardIndex+1,$callIndex-$guardIndex-1) as $between) {
                $assign=$between instanceof Node\Stmt\Expression?$between->expr:null;
                if (! $assign instanceof Node\Expr\Assign || ! $assign->var instanceof Node\Expr\Variable || ! is_string($assign->var->name)
                    || $assign->var->name===$variable || ! $assign->expr instanceof Node\Expr\FuncCall || ! self::plain($assign->expr)
                    || strtolower($assign->expr->name->getLast())!=='dirname' || count($assign->expr->args)!==1
                    || ! $assign->expr->args[0]->value instanceof Node\Expr\Variable || $assign->expr->args[0]->value->name===$variable) { $safe=false;break; }
                $native=$source->builtinBinding($context,$assign->expr,'dirname');$this->dependencies['dirname']=$native;
                if ($native===null || $native->templates!==[] || $native->globalsAccessed!==[] || array_filter($native->parameters,static fn($p):bool=>!self::byValue($p))) { $safe=false;break; }
            }
            if ($safe) {
                $range=Type::fromAtomics(new ScalarType(ScalarTypeKind::Integer,new \Mago\Sdk\Analyzer\Type\IntegerType(\Mago\Sdk\Analyzer\Type\IntegerTypeKind::Range,0,$upper*$factor->value)));
                if (! $this->stage('proved product fits actual selected formal',$context->types->isContainedBy($range,$parameter->type->type))) { return false; }
                $this->certificate=['guardSpan'=>self::span($guard),'selectedVariable'=>$variable,'upperBound'=>$upper,'multiplier'=>$factor->value,
                    'provedIntegerRange'=>[0,$upper*$factor->value],'sourceGuardProof'=>true,'phpstanPolicyAdmission'=>false];return true;
            }
        }
        return $this->stage('unchanged bounded decimal guard',false);
    }
    private function stringPredicate(IssueFilterContext $context,FunctionLikeMetadata $metadata): bool
    {
        $parameter=$metadata->parameters[0]??null;$return=$metadata->returnType?->type;
        $conditional=count($return?->atomicTypes??[])===1?$return->atomicTypes[0]:null;
        return count($metadata->parameters)===1 && $parameter!==null && self::byValue($parameter)
            && $this->same($context,$parameter->declaredType?->type,Type::mixed()) && $this->same($context,$parameter->type?->type,Type::mixed())
            && $this->same($context,$metadata->declaredReturnType?->type,Type::bool()) && $conditional instanceof ConditionalType && ! $conditional->negated
            && count($conditional->subject->atomicTypes)===1 && $conditional->subject->atomicTypes[0] instanceof VariableType
            && $conditional->subject->atomicTypes[0]->name===$parameter->name && $this->same($context,$conditional->target,Type::string())
            && $this->same($context,$conditional->then,Type::true()) && $this->same($context,$conditional->otherwise,Type::false());
    }
    private function digitPredicate(IssueFilterContext $context,FunctionLikeMetadata $metadata): bool
    {
        $parameter=$metadata->parameters[0]??null;
        return count($metadata->parameters)===1 && $parameter!==null && self::byValue($parameter)
            && ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) && $this->same($context,$metadata->declaredReturnType?->type,Type::bool())
            && $this->same($context,$metadata->returnType?->type,Type::bool()) && $parameter->declaredType!==null && $parameter->type!==null
            && $context->types->isContainedBy(Type::string(),$parameter->declaredType->type) && $context->types->isContainedBy(Type::string(),$parameter->type->type);
    }
    private function directory(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,Node\Expr\FuncCall $call,FunctionLikeMetadata $receiver): bool
    {
        $parameter=$receiver->parameters[3]??null;$nullableString=Type::union(Type::null(),Type::string());$refined=Type::union(Type::null(),Type::nonEmptyString());
        if (! $this->stage('proc_open fourth formal has exact declared/refined string contracts',count($call->args)===4 && $parameter!==null && self::byValue($parameter)
            && $this->same($context,$parameter->declaredType?->type,$nullableString) && $this->same($context,$parameter->type?->type,$refined))) { return false; }
        $value=$call->args[3]->value;
        if (! $this->stage('direct this property source',$value instanceof Node\Expr\PropertyFetch && $value->var instanceof Node\Expr\Variable
            && $value->var->name==='this' && $value->name instanceof Node\Identifier)) { return false; }
        $class=null;
        foreach ($source->ancestors($call,$file) as $parent) { if ($parent instanceof Node\Stmt\Class_) { $class=$parent;break; } }
        $owner=$source->owner($context,$call,$file);$this->dependencies['owner']=$owner;
        $nativeClass=$class?->namespacedName!==null?$context->codebase->getClass($class->namespacedName->toString()):null;$this->dependencies['class']=$nativeClass;
        if (! $this->stage('final physical named caller',$class!==null && $class->isFinal() && $class->extends===null && $class->namespacedName!==null
            && $owner!==null && $nativeClass!==null && $nativeClass->flags->contains(MetadataFlags::FINAL)
            && ! $nativeClass->hasIncompleteHierarchy() && (new NodeFinder)->findInstanceOf($class->stmts,Node\Stmt\TraitUse::class)===[])) { return false; }
        $selected=null;$constructor=$class->getMethod('__construct');
        foreach ($constructor?->params??[] as $candidate) {
            if ($candidate->var instanceof Node\Expr\Variable && $candidate->var->name===$value->name->name && $candidate->isPromoted()
                && $candidate->isReadonly() && $candidate->type instanceof Node\Identifier && $candidate->type->name==='string'
                && ! $candidate->byRef && ! $candidate->variadic && $candidate->getDocComment()===null) { $selected=$candidate; }
        }
        // Native member names include the variable sigil; source identifiers do not.
        $property=$context->codebase->getProperty($class->namespacedName->toString(),'$'.$value->name->name);$this->dependencies['property']=$property;
        if (! $this->stage('physical promoted readonly string and native property contract',$selected!==null && $property!==null
            && $property->flags->contains(MetadataFlags::READONLY) && ! $property->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)
            && $property->hooks===[] && $property->attributes===[] && $source->located($property->nameLocation,$selected->var,$file)
            && $source->located($property->declaredType?->location,$selected->type,$file)
            && $this->same($context,$property->declaredType?->type,Type::string()) && $this->same($context,$property->type?->type,Type::string()))) { return false; }
        // Earlier arguments cannot evaluate calls, reference aliases, or writes before the selected property read.
        if (! $this->stage('no intervening argument evaluation effects',(new NodeFinder)->findFirst(array_slice($call->args,0,3),static fn(Node $node):bool=>
            $node instanceof Node\Expr\CallLike || $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\AssignRef
            || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec
            || $node instanceof Node\Expr\Variable && ! is_string($node->name))===null)) { return false; }
        if (! $this->stage('declared policy contains exact source property',$context->types->isContainedBy($property->type->type,$parameter->declaredType->type))) { return false; }
        $this->certificate=['property'=>$class->namespacedName->toString().'::$'.$value->name->name,'propertyDeclarationSpan'=>self::span($selected),
            'sourceGuardProof'=>false,'phpstanPolicyAdmission'=>true,'phpstanReceivingSignature'=>'proc_open parameter 4: ?string',
            'nonEmptyClaimed'=>false,'policySource'=>'PHPStan resources/functionMap.php and bundled PHP 8 proc_open.stub'];return true;
    }
    private static function byValue($parameter): bool { return ! $parameter->flags->contains(MetadataFlags::BY_REFERENCE) && ! $parameter->flags->contains(MetadataFlags::VARIADIC) && $parameter->outType===null && $parameter->closureThisType===null; }
    private static function plain(Node\Expr\FuncCall $call): bool
    {
        return $call->name instanceof Node\Name && ! $call->isFirstClassCallable()
            && ! array_filter($call->args,static fn($argument):bool=>! $argument instanceof Node\Arg || $argument->name!==null || $argument->unpack || $argument->byRef);
    }
    private static function disjunction(Node\Expr $condition): array { return $condition instanceof Node\Expr\BinaryOp\BooleanOr?[...self::disjunction($condition->left),...self::disjunction($condition->right)]:[$condition]; }
    private static function span(Node $node): array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
    private static function local(string $name): bool { return ! in_array($name,['this','GLOBALS','_SERVER','_GET','_POST','_REQUEST','_ENV','_SESSION','_COOKIE','_FILES'],true); }
}
