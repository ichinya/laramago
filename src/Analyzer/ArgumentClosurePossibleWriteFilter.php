<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DirectCallbackReferenceEffects;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{ConditionalType,ScalarType,ScalarTypeKind,StringCasing,StringLiteralKind,StringType,VariableType};
use Mago\Sdk\Reporting\{AnnotationKind,Level};
use PhpParser\Node;
use PhpParser\NodeFinder;

/** PHPStan argument-closure scope merging for one closed boolean reference lifetime. */
final class ArgumentClosurePossibleWriteFilter implements IssueFilterHook
{
    public array $stages=[];
    public array $dependencies=[];
    public array $certificate=[];
    public function __construct(private readonly string $root) {}
    public function getCodes(): array { return ['impossible-type-comparison']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $proof=new self($this->root);$decision=$proof->evaluate($context);
        $this->stages=$proof->stages;$this->dependencies=$proof->dependencies;$this->certificate=$proof->certificate;
        return $decision;
    }
    private function stage(string $name,bool $passed): bool { $this->stages[]=['stage'=>$name,'passed'=>$passed];return $passed; }
    private static function span(Node $node): array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
    private static function variable(Node $node,string $name): bool { return $node instanceof Node\Expr\Variable && $node->name===$name; }
    private static function literal(Node $node): ?bool
    {
        return $node instanceof Node\Expr\ConstFetch?match(strtolower($node->name->toString())) { 'true'=>true,'false'=>false,default=>null }:null;
    }
    private function evaluate(IssueFilterContext $context): IssueFilterDecision
    {
        $issue=$context->issue;
        if (! $this->stage('exact stale false boolean assertion envelope',$issue->code==='impossible-type-comparison' && $issue->level===Level::Error
            && count($issue->annotations)===1 && count($issue->notes)===1 && $issue->edits===[] && $issue->link===null
            && $issue->help==='Check that the correct variable is being passed, or update the assertion type.'
            && preg_match('/^Impossible type assertion: `\$([A-Za-z_][A-Za-z0-9_]*)` of type `false` can never be `true\|true`\.$/D',$issue->message,$match)===1)) { return IssueFilterDecision::Keep; }
        $name=$match[1];$primary=$issue->annotations[0];
        if (! $this->stage('complete native primary and note',$primary->kind===AnnotationKind::Primary && ($primary->file===null || $primary->file==='')
            && $primary->message==='Argument `$'.$name.'` has type `false`'
            && $issue->notes[0]==='The assertion expects `$'.$name.'` to be `true|true`, but no value of type `false` can satisfy this.')) { return IssueFilterDecision::Keep; }
        $source=new GuardedStringCastContracts($this->root);$file=$source->read($context->file,$context->contents);
        if (! $this->stage('current physical caller source',$file!==null)) { return IssueFilterDecision::Keep; }
        $finder=new NodeFinder;$matches=[];
        foreach ($finder->findInstanceOf($file['nodes'],Node\Expr\StaticCall::class) as $call) {
            if (self::span($call)!==[$primary->span->start,$primary->span->end] || ! $call->class instanceof Node\Name
                || strcasecmp($call->class->toString(),'Testo\\Assert')!==0 || ! $call->name instanceof Node\Identifier || strtolower($call->name->name)!=='true'
                || $call->isFirstClassCallable() || count($call->args)!==1 || ! $call->args[0] instanceof Node\Arg || $call->args[0]->name!==null
                || $call->args[0]->byRef || $call->args[0]->unpack || ! self::variable($call->args[0]->value,$name)) { continue; }
            $matches[]=$call;
        }
        if (! $this->stage('one whole physical assertion call',count($matches)===1)) { return IssueFilterDecision::Keep; }
        $assertion=$matches[0];$scope=null;
        foreach ($source->ancestors($assertion,$file) as $parent) { if ($parent instanceof Node\FunctionLike) { $scope=$parent;break; } }
        $owner=$source->owner($context,$assertion,$file);$this->dependencies['owner']=$owner;
        if (! $this->stage('native physical named owner',$scope instanceof Node\Stmt\ClassMethod && $owner!==null && $scope->stmts!==null)) { return IssueFilterDecision::Keep; }
        $assertionProof=new DirectCallbackReferenceEffects($this->root);
        $this->dependencies['assertion']=$context->codebase->getMethod('Testo\\Assert','true');
        if (! $this->stage('genuine physical true assertion contract',$assertionProof->assertion($context->codebase,'true',$context->types))) { return IssueFilterDecision::Keep; }
        // A fresh assignment must dominate the selected assertion in a common statement list.
        $initializers=[];
        foreach ([$scope,...$source->ancestors($assertion,$file)] as $container) {
            if (! isset($container->stmts) || ! is_array($container->stmts) || $container instanceof Node\Expr\Closure) { continue; }
            $selectedIndex=null;
            foreach ($container->stmts as $index=>$statement) { if ($statement->getStartFilePos()<=$assertion->getStartFilePos() && $statement->getEndFilePos()>=$assertion->getEndFilePos()) { $selectedIndex=$index;break; } }
            if ($selectedIndex===null) { continue; }
            foreach (array_slice($container->stmts,0,$selectedIndex) as $statement) {
                $assign=$statement instanceof Node\Stmt\Expression?$statement->expr:null;
                if ($assign instanceof Node\Expr\Assign && self::variable($assign->var,$name) && self::literal($assign->expr)===false) { $initializers[spl_object_id($assign)]=$assign; }
            }
        }
        if (! $this->stage('one dominating fresh false binding',count($initializers)===1)) { return IssueFilterDecision::Keep; }
        $initial=array_values($initializers)[0];$closures=[];$writes=[];$safe=true;
        $walk=function(mixed $value,?Node $parent=null,?Node\Expr\Closure $closure=null) use (&$walk,&$closures,&$writes,&$safe,$name,$initial,$assertion,$scope,$file): void {
            if (! $safe) { return; }
            if (is_array($value)) { foreach ($value as $item) { $walk($item,$parent,$closure); }return; }
            if (! $value instanceof Node || $value->getStartFilePos()>$assertion->getEndFilePos()) { return; }
            if ($value instanceof Node\Expr\Closure) {
                $captures=array_values(array_filter($value->uses,static fn(Node\ClosureUse $use):bool=>self::variable($use->var,$name)));
                if ($captures===[]) {
                    // A nested local with the same spelling cannot bind the outer reference cell.
                    if ((new NodeFinder)->findFirst([$value],static fn(Node $node):bool=>self::variable($node,$name))!==null) { $safe=false; }
                    return;
                }
                if ($captures!==[]) {
                    $call=$parent instanceof Node\Arg?($file['parents'][spl_object_id($parent)]??null):null;
                    if ($closure!==null || count($captures)!==1 || ! $captures[0]->byRef || $value->byRef
                        || ! $parent instanceof Node\Arg || $parent->byRef || $parent->unpack || $parent->name!==null
                        || ! ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\FuncCall || $call instanceof Node\Expr\New_)
                        || $call instanceof Node\Expr\CallLike && $call->isFirstClassCallable() || $value->getStartFilePos()<=$initial->getEndFilePos()
                        || ! $value->returnType instanceof Node\Identifier || strtolower($value->returnType->name)!=='void'
                        || array_filter($value->params,static fn(Node\Param $parameter):bool=>$parameter->byRef || $parameter->variadic || $parameter->default!==null)) { $safe=false;return; }
                    $closures[spl_object_id($value)]=$value;$closure=$value;
                }
            } elseif ($value instanceof Node\FunctionLike && $value!==$scope) {
                if ((new NodeFinder)->findFirst([$value],static fn(Node $node):bool=>self::variable($node,$name))!==null) { $safe=false; }return;
            }
            // Any local-table mechanism can target this cell without an explicit variable node.
            if ($value instanceof Node\Expr\Eval_ || $value instanceof Node\Expr\Include_ || $value instanceof Node\Stmt\Goto_ || $value instanceof Node\Stmt\Label
                || $value instanceof Node\Expr\Variable && (! is_string($value->name) || $value->name==='GLOBALS')
                || $value instanceof Node\Expr\FuncCall && (! $value->name instanceof Node\Name || in_array(strtolower($value->name->getLast()),['extract','parse_str'],true))) { $safe=false;return; }
            if (self::variable($value,$name)) {
                if ($parent instanceof Node\Expr\Assign && $parent->var===$value) {
                    if ($parent!==$initial) {
                        if ($closure===null || self::literal($parent->expr)===null) { $safe=false;return; }
                        $writes[spl_object_id($closure)][]=[$parent,self::literal($parent->expr)];
                    }
                } elseif ($parent instanceof Node\ClosureUse && $parent->var===$value && $closure!==null) {
                    // The selected direct literal argument is the only permitted reference escape.
                } elseif ($closure===null && ! ($parent instanceof Node\Arg && $parent->value===$value && ($file['parents'][spl_object_id($parent)]??null)===$assertion)) { $safe=false;return; }
                elseif ($closure!==null && ($parent instanceof Node\Arg || $parent instanceof Node\Expr\AssignRef || $parent instanceof Node\Expr\AssignOp
                    || $parent instanceof Node\Stmt\Unset_ || $parent instanceof Node\Stmt\Global_ || $parent instanceof Node\Stmt\StaticVar
                    || $parent instanceof Node\Stmt\Foreach_ || $parent instanceof Node\Expr\PreInc || $parent instanceof Node\Expr\PostInc
                    || $parent instanceof Node\Expr\PreDec || $parent instanceof Node\Expr\PostDec)) { $safe=false;return; }
            }
            foreach ($value->getSubNodeNames() as $field) { $walk($value->$field,$value,$closure); }
        };
        $walk($scope);
        if (! $this->stage('closed selected reference lifetime',$safe && $closures!==[])) { return IssueFilterDecision::Keep; }
        $selected=[];
        foreach ($closures as $id=>$closure) {
            $predicateSafe=true;
            foreach ($finder->findInstanceOf($closure->stmts,Node\Expr\FuncCall::class) as $predicate) {
                $target=$predicate->name instanceof Node\Name?strtolower($predicate->name->getLast()):'';
                if (! in_array($target,['str_contains','strtolower'],true)) { continue; }
                $native=$source->builtinBinding($context,$predicate,$target);$this->dependencies[$target]=$native;
                if (! $this->predicateContract($context,$predicate,$target,$native)) { $predicateSafe=false;break; }
            }
            if (! $predicateSafe) { return IssueFilterDecision::Keep; }
            $possible=$this->possibleValues($closure->stmts,$name,[false],$closure,$file);
                if ($possible!==null && in_array(true,$possible,true) && ($writes[$id]??[])!==[]) { $selected[]=[$closure,$possible]; }
        }
        if (! $this->stage('bounded boolean writes including normal and throw exits',$selected!==[])) { return IssueFilterDecision::Keep; }
        if (! $this->stage('literal true belongs to widened boolean domain',$context->types->isContainedBy(Type::true(),Type::bool()))) { return IssueFilterDecision::Keep; }
        $this->certificate=['file'=>$context->file,'sourceSha256'=>$file['hash'],'local'=>$name,'initializationSpan'=>self::span($initial),'assertionSpan'=>self::span($assertion),
            'closureSpans'=>array_map(static fn(array $row):array=>self::span($row[0]),$selected),'domain'=>'bool','policy'=>'PHPStan deferred argument-closure by-reference scope merge',
            'guaranteedExecutionClaimed'=>false,'receiverDispatchClaimed'=>false,'opaqueClosureIdentifierClaimed'=>false,'workerLocalFactsRequired'=>false,
            'afterFileAuthority'=>false,'nativeTypesReplaced'=>false];
        return IssueFilterDecision::Remove;
    }
    /** Bind the exact locked prelude declarations, including strtolower's unresolved conditional. */
    private function predicateContract(IssueFilterContext $context,Node\Expr\FuncCall $call,string $target,?FunctionLikeMetadata $native): bool
    {
        $count=$target==='str_contains'?2:1;
        if (! $this->stage($target.' genuine closed builtin and source arity',$native!==null && count($call->args)===$count
            && ! $call->isFirstClassCallable() && ! array_filter($call->args,static fn($argument):bool=>! $argument instanceof Node\Arg
                || $argument->name!==null || $argument->byRef || $argument->unpack)
            && count($native->parameters)===$count && $native->templates===[] && $native->whereConstraints===[]
            && $native->globalsAccessed===[] && $native->assertions===[] && $native->ifTrueAssertions===[]
            && $native->ifFalseAssertions===[] && ! $native->assertionsInferred)) { return false; }
        $names=$target==='str_contains'?['$haystack','$needle']:['$string'];
        foreach ($native->parameters as $index=>$parameter) {
            if (! $this->stage($target.' exact by-value string formal '.$index,$parameter->name===$names[$index]
                && ! $parameter->flags->contains(MetadataFlags::BY_REFERENCE) && ! $parameter->flags->contains(MetadataFlags::VARIADIC)
                && ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) && $parameter->defaultType===null
                && $parameter->outType===null && $parameter->closureThisType===null
                && $parameter->declaredType!==null && $parameter->type!==null && ! $parameter->declaredType->fromDocblock
                && ! $parameter->declaredType->inferred && ! $parameter->type->inferred
                && $this->same($context,$parameter->declaredType->type,Type::string())
                && $this->same($context,$parameter->type->type,Type::string()))) { return false; }
        }
        $return=$target==='str_contains'?Type::bool():Type::string();
        if (! $this->stage($target.' exact declared return domain',$native->declaredReturnType!==null && $native->returnType!==null
            && ! $native->declaredReturnType->fromDocblock && ! $native->declaredReturnType->inferred && ! $native->returnType->inferred
            && $this->same($context,$native->declaredReturnType->type,$return))) { return false; }
        if ($target==='str_contains') {
            return $this->stage('str_contains effective bool return',! $native->returnType->fromDocblock
                && $this->same($context,$native->returnType->type,Type::bool()));
        }
        $effective=$native->returnType->type;
        $conditional=count($effective->atomicTypes)===1?$effective->atomicTypes[0]:null;
        if (! $this->stage('strtolower genuine conditional on selected string formal',$native->hasDocblock && $native->returnType->fromDocblock
            && ! $effective->flags->byReference && ! $effective->flags->possiblyUndefined && ! $effective->flags->hadTemplate
            && $conditional instanceof ConditionalType && ! $conditional->negated
            && ! $conditional->subject->flags->byReference && ! $conditional->subject->flags->possiblyUndefined
            && count($conditional->subject->atomicTypes)===1 && $conditional->subject->atomicTypes[0] instanceof VariableType
            && $conditional->subject->atomicTypes[0]->name==='$string')) { return false; }
        return $this->stage('strtolower exact nonempty target and two string branches',
            $this->same($context,$conditional->target,Type::nonEmptyString())
            && $this->nonemptyLowercase($conditional->then)
            && $context->types->isContainedBy($conditional->then,Type::string())
            && $this->same($context,$conditional->otherwise,Type::literalString('')));
    }
    private function same(IssueFilterContext $context,Type $actual,Type $expected): bool
    {
        return ! $actual->flags->byReference && ! $actual->flags->possiblyUndefined && $context->types->equals($actual,$expected);
    }
    private function nonemptyLowercase(Type $type): bool
    {
        $atom=count($type->atomicTypes)===1?$type->atomicTypes[0]:null;
        $refinement=$atom instanceof ScalarType?$atom->refinement:null;
        return ! $type->flags->byReference && ! $type->flags->possiblyUndefined && $atom instanceof ScalarType
            && $atom->kind===ScalarTypeKind::String && $refinement instanceof StringType
            && $refinement->literalKind===StringLiteralKind::General && $refinement->literalValue===null
            && ! $refinement->numeric && ! $refinement->truthy && $refinement->nonEmpty && ! $refinement->callable
            && $refinement->casing===StringCasing::Lowercase;
    }
    /** Finite subset: literal boolean writes, conditional branches, unrelated simple effects, and throw exits. */
    private function possibleValues(array $statements,string $name,array $states,Node\Expr\Closure $closure,array $file): ?array
    {
        $exits=[];$finder=new NodeFinder;
        foreach ($statements as $statement) {
            if ($states===[]) { break; }
            if ($statement instanceof Node\Stmt\If_) {
                if ($statement->elseifs!==[] || ! $this->condition($statement->cond,$name,$closure,$file)) { return null; }
                $yes=$this->possibleValues($statement->stmts,$name,$states,$closure,$file);
                $no=$statement->else===null?$states:$this->possibleValues($statement->else->stmts,$name,$states,$closure,$file);
                if ($yes===null || $no===null) { return null; }$states=array_values(array_unique([...$yes,...$no],SORT_REGULAR));continue;
            }
            $expression=$statement instanceof Node\Stmt\Expression?$statement->expr:null;
            if ($expression instanceof Node\Expr\Throw_ || $statement instanceof Node\Stmt\Return_) { $exits=[...$exits,...$states];$states=[];continue; }
            if ($expression instanceof Node\Expr\Assign && self::variable($expression->var,$name)) {
                $literal=self::literal($expression->expr);if ($literal===null) { return null; }$states=[$literal];continue;
            }
            if (! $statement instanceof Node\Stmt\Expression || $finder->findFirst([$statement],static fn(Node $node):bool=>self::variable($node,$name)
                || $node instanceof Node\FunctionLike || $node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom
                || $node instanceof Node\Expr\Exit_ || $node instanceof Node\Expr\AssignRef)!==null) { return null; }
        }
        return array_values(array_unique([...$states,...$exits],SORT_REGULAR));
    }
    private function condition(Node\Expr $condition,string $name,Node\Expr\Closure $closure,array $file): bool
    {
        if ($condition instanceof Node\Expr\BooleanNot) { return self::variable($condition->expr,$name); }
        if ($condition instanceof Node\Expr\BinaryOp\BooleanAnd) { return $this->condition($condition->left,$name,$closure,$file) && $this->condition($condition->right,$name,$closure,$file); }
        if ($condition instanceof Node\Expr\BinaryOp\Identical || $condition instanceof Node\Expr\BinaryOp\Equal) {
            $parameters=array_map(static fn(Node\Param $p):mixed=>$p->var->name,array_filter($closure->params,
                static fn(Node\Param $p):bool=>$p->type instanceof Node\Identifier && strtolower($p->type->name)==='string'));
            return $condition->left instanceof Node\Expr\Variable && in_array($condition->left->name,$parameters,true)
                && ($condition->right instanceof Node\Scalar\String_ || $condition->right instanceof Node\Expr\Variable
                    && $condition->right->name!==$name && $this->capturedStringLoop($condition->right,$closure,$file));
        }
        // The listener form uses two native pure string predicates on a typed callback parameter's field.
        if ($condition instanceof Node\Expr\FuncCall && $condition->name instanceof Node\Name && strtolower($condition->name->getLast())==='str_contains'
            && count($condition->args)===2 && $condition->args[1]->value instanceof Node\Scalar\String_) {
            $inner=$condition->args[0]->value;$field=$inner instanceof Node\Expr\FuncCall && $inner->name instanceof Node\Name
                && strtolower($inner->name->getLast())==='strtolower' && count($inner->args)===1?$inner->args[0]->value:null;
            return $field instanceof Node\Expr\PropertyFetch && $field->var instanceof Node\Expr\Variable && $field->name instanceof Node\Identifier
                && in_array($field->var->name,array_map(static fn(Node\Param $p):mixed=>$p->var->name,$closure->params),true);
        }
        return false;
    }
    private function capturedStringLoop(Node\Expr\Variable $value,Node\Expr\Closure $closure,array $file): bool
    {
        if (! is_string($value->name)) { return false; }
        $uses=array_values(array_filter($closure->uses,static fn(Node\ClosureUse $use):bool=>self::variable($use->var,$value->name)));
        if (count($uses)!==1 || $uses[0]->byRef) { return false; }
        $parent=$closure;
        while (isset($file['parents'][spl_object_id($parent)])) {
            $parent=$file['parents'][spl_object_id($parent)];
            if ($parent instanceof Node\FunctionLike) { return false; }
            if (! $parent instanceof Node\Stmt\Foreach_ || ! self::variable($parent->valueVar,$value->name)) { continue; }
            if ($parent->byRef || ! $parent->expr instanceof Node\Expr\Array_ || $parent->expr->items===[]) { return false; }
            foreach ($parent->expr->items as $item) { if (! $item instanceof Node\ArrayItem || $item->byRef || $item->unpack || ! $item->value instanceof Node\Scalar\String_) { return false; } }
            foreach ((new NodeFinder)->findInstanceOf($parent->stmts,Node\Expr\Variable::class) as $use) {
                if ($use->name===$value->name && $use->getStartFilePos()<$closure->getStartFilePos()) { return false; }
            }
            return true;
        }
        return false;
    }
}
