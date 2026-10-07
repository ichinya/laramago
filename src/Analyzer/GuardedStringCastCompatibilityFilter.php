<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\VariableType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Two explicit PHPStan compatibility policies; native object types stay intact. */
final class GuardedStringCastCompatibilityFilter implements IssueFilterHook
{
    public array $stages = [];
    public array $dependencies = [];
    public array $checks = [];
    private readonly GuardedStringCastContracts $source;
    private const REQUEST = 'Illuminate\\Http\\Request';
    private const ROUTE_BODY = <<<'PHP'
    public function route($param = null, $default = null)
    {
        $route = call_user_func($this->getRouteResolver());
        if (is_null($route) || is_null($param)) {
            return $route;
        }
        return $route->parameter($param, $default);
    }
    PHP;

    public function __construct(private readonly string $root) { $this->source = new GuardedStringCastContracts($root); }
    public function getCodes(): array { return ['invalid-type-cast']; }
    public function proofReceipts(): array { return ['sourceAndRoute'=>$this->checks,'builtins'=>$this->source->builtinReceipts]; }
    private function check(string $step,bool $passed): bool { $this->checks[] = ['step'=>$step,'passed'=>$passed]; return $passed; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        // Codebase queries can dispatch another hook while this invocation is suspended.
        // Publish receipts only after evaluating this call with its own local proof state.
        $proof = new self($this->root);
        $decision = $proof->evaluateIssue($context);
        $this->stages = $proof->stages;
        $this->dependencies = $proof->dependencies;
        $this->checks = $proof->checks;
        $this->source->builtinReceipts = $proof->source->builtinReceipts;
        return $decision;
    }

    private function evaluateIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $this->stages = []; $this->dependencies = []; $this->checks = []; $this->source->builtinReceipts = []; $issue = $context->issue;
        if ($context->cancellation->isCancelled() || $issue->level !== Level::Error || $issue->code !== 'invalid-type-cast'
            || $issue->message !== 'Cannot reliably cast generic `object` to `string`.' || $issue->edits !== [] || $issue->link !== null
            || $issue->help !== 'Ensure the object is stringable before casting, use a more specific object type, or avoid the cast.'
            || $issue->notes !== ['The object might implement `Stringable` or have a `__toString()` method, but this cannot be determined statically for a generic `object` type.',
                'If the object is not stringable at runtime, this cast will cause a fatal error.'] || count($issue->annotations) !== 1) { return IssueFilterDecision::Keep; }
        $primary = $issue->annotations[0];
        if ($primary->kind !== AnnotationKind::Primary || $primary->file !== null || $primary->message !== 'Casting generic `object` to `string`') { return IssueFilterDecision::Keep; }
        $this->stages[] = 'exact native generic-object envelope';
        $file = $this->source->read($context->file,$context->contents);
        if ($file === null) { return IssueFilterDecision::Keep; }
        $casts = (new NodeFinder)->find($file['nodes'],static fn (Node $node): bool => $node instanceof Node\Expr\Cast\String_
            && [$node->getStartFilePos(),$node->getEndFilePos()+1] === [$primary->span->start,$primary->span->end]);
        if (count($casts) !== 1) { return IssueFilterDecision::Keep; }
        $cast = $casts[0]; $this->stages[] = 'current physical full string-cast span';
        $owner = $this->source->owner($context,$cast,$file);
        if ($owner === null) { return IssueFilterDecision::Keep; }
        $this->dependencies['owner'] = $owner; $this->stages[] = 'genuine native physical caller owner';
        if ($this->callableGuard($context,$cast,$file)) { $this->stages[] = 'PHPStan declared callable-method guard policy'; return IssueFilterDecision::Remove; }
        if ($this->requestRoute($context,$cast,$file)) { $this->stages[] = 'Larastan benevolent route conversion policy'; return IssueFilterDecision::Remove; }
        return IssueFilterDecision::Keep;
    }

    private function callableGuard(IssueFilterContext $context, Node\Expr\Cast\String_ $cast, array $file): bool
    {
        if (! $cast->expr instanceof Node\Expr\Variable || ! is_string($cast->expr->name)) { return false; }
        $variable = $cast->expr->name; $branch = null; $scope = null;
        foreach ($this->source->ancestors($cast,$file) as $node) {
            if ($branch === null && ($node instanceof Node\Stmt\If_ || $node instanceof Node\Stmt\ElseIf_)) { $branch = $node; }
            if ($node instanceof Node\FunctionLike) { $scope = $node; break; }
        }
        if ($branch === null || $scope === null || ! $this->source->noAliases($scope,$variable)
            || ! $branch->cond instanceof Node\Expr\BinaryOp\BooleanAnd || count($branch->stmts) !== 1
            || ! $this->safeCastStatement($branch->stmts[0],$cast,$variable)) { return false; }
        $object = $branch->cond->left; $callable = $branch->cond->right;
        if (! $object instanceof Node\Expr\FuncCall || ! $callable instanceof Node\Expr\FuncCall
            || ! $this->singleArgument($object) || ! $this->singleArgument($callable)
            || ! $this->variable($object->args[0]->value,$variable) || ! $callable->args[0]->value instanceof Node\Expr\Array_) { return false; }
        $items = $callable->args[0]->value->items;
        if (count($items) !== 2 || $items[0] === null || $items[1] === null
            || $items[0]->key !== null || $items[1]->key !== null || $items[0]->byRef || $items[1]->byRef || $items[0]->unpack || $items[1]->unpack
            || ! $this->variable($items[0]->value,$variable) || ! $items[1]->value instanceof Node\Scalar\String_
            || strtolower($items[1]->value->value) !== '__tostring') { return false; }
        if (! $this->check('guard: source conjunction and selected-variable lifetime',true)) { return false; }
        if (! $this->source->builtin($context,$object,'is_object') || ! $this->source->builtin($context,$callable,'is_callable')) { return false; }
        $this->dependencies['is_object'] = $context->codebase->getFunction('is_object');
        $this->dependencies['is_callable'] = $context->codebase->getFunction('is_callable');
        $this->stages[] = 'actual builtin guard binding and no intervening variable evaluation';
        return true;
    }

    private function singleArgument(Node\Expr\FuncCall $call): bool
    {
        return count($call->args) === 1 && $call->args[0] instanceof Node\Arg && $call->args[0]->name === null && ! $call->args[0]->unpack;
    }

    /** Only a direct cast, or its first evaluated named-call argument, then return/assignment. */
    private function safeCastStatement(Node\Stmt $statement, Node\Expr\Cast\String_ $cast, string $variable): bool
    {
        $expr = $statement instanceof Node\Stmt\Return_ ? $statement->expr : ($statement instanceof Node\Stmt\Expression ? $statement->expr : null);
        if ($expr instanceof Node\Expr\Assign) {
            if (! $this->pureTarget($expr->var,$variable)) { return false; }
            $expr = $expr->expr;
        }
        if ($expr === $cast) { return true; }
        if ($expr instanceof Node\Expr\FuncCall && ! $expr->name instanceof Node\Name) { return false; }
        if ($expr instanceof Node\Expr\StaticCall && (! $expr->class instanceof Node\Name || ! $expr->name instanceof Node\Identifier)) { return false; }
        if (! $expr instanceof Node\Expr\FuncCall && ! $expr instanceof Node\Expr\StaticCall) { return false; }
        return count($expr->args) === 1 && $expr->args[0] instanceof Node\Arg && ! $expr->args[0]->unpack
            && $expr->args[0]->name === null && $expr->args[0]->value === $cast;
    }

    private function pureTarget(Node\Expr $expr, string $selected): bool
    {
        if ($expr instanceof Node\Expr\Variable) { return is_string($expr->name) && $expr->name !== $selected; }
        return $expr instanceof Node\Expr\ArrayDimFetch && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)
            && $expr->var->name !== $selected && ($expr->dim instanceof Node\Expr\Variable && is_string($expr->dim->name)
                || $expr->dim instanceof Node\Scalar\String_ || $expr->dim instanceof Node\Scalar\Int_);
    }

    private function variable(Node $node, string $expected): bool { return $node instanceof Node\Expr\Variable && $node->name === $expected; }

    private function requestRoute(IssueFilterContext $context, Node\Expr\Cast\String_ $cast, array $file): bool
    {
        $call = $cast->expr;
        if (! $call instanceof Node\Expr\MethodCall || ! $call->var instanceof Node\Expr\Variable || ! is_string($call->var->name)
            || ! $call->name instanceof Node\Identifier || strtolower($call->name->name) !== 'route' || count($call->args) !== 2) { return false; }
        foreach ($call->args as $arg) { if (! $arg instanceof Node\Arg || $arg->name !== null || $arg->unpack || ! $arg->value instanceof Node\Scalar\String_) { return false; } }
        // Only literal non-null parameter plus literal string default: default closures and arrays remain native.
        if ($call->args[0]->value->value === '') { return false; }
        $scope = null;
        foreach ($this->source->ancestors($cast,$file) as $node) { if ($node instanceof Node\FunctionLike) { $scope = $node; break; } }
        if ($scope === null || ! $this->source->noAliases($scope,$call->var->name) || $scope->getDocComment() !== null) { return false; }
        $formal = null;
        foreach ($scope->getParams() as $parameter) { if ($parameter->var instanceof Node\Expr\Variable && $parameter->var->name === $call->var->name) { $formal = $parameter; } }
        if ($formal === null || ! $formal->type instanceof Node\Name || strcasecmp($formal->type->toString(),self::REQUEST) !== 0
            || $formal->byRef || $formal->variadic || $formal->default !== null) { return false; }
        if ((new NodeFinder)->findFirst([$scope],function (Node $node) use ($call): bool {
            return ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\PreInc
                || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec)
                && (new NodeFinder)->findFirst([$node],fn (Node $child): bool => $this->variable($child,$call->var->name)) !== null;
        }) !== null) { return false; }
        $this->check('route: two literal arguments and stable exact Request formal',true);
        $request = $context->codebase->getClass(self::REQUEST); $method = $context->codebase->getMethod(self::REQUEST,'route');
        $declaring = $context->codebase->getDeclaringMethod(self::REQUEST,'route');
        if ($request === null || $request->kind !== ClassLikeKind::Class_ || $request->hasIncompleteHierarchy() || $request->templates !== []
            || $method === null || $declaring === null || $method != $declaring || strcasecmp($method->identifier->class ?? '',self::REQUEST) !== 0
            || strcasecmp($declaring->identifier->class ?? '',self::REQUEST) !== 0 || $method->abstract || $method->static || $method->visibility !== Visibility::Public
            || $method->flags->contains(MetadataFlags::BUILTIN) || $method->flags->contains(MetadataFlags::BY_REFERENCE) || $method->templates !== []
            || count($method->parameters) !== 2 || $method->declaredReturnType !== null || $method->returnType === null
            || ! $method->returnType->fromDocblock || $method->returnType->inferred) { $this->check('route: complete native Request and matching direct public method',false); return false; }
        $this->check('route: complete native Request and matching direct public method',true);
        $physical = $this->source->read($method->location->file);
        if ($physical === null || ! str_ends_with($physical['path'],'/laravel/framework/src/illuminate/http/request.php')
            && ! str_ends_with($physical['path'],'/laravel/framework/src/Illuminate/Http/Request.php')) { $this->check('route: current physical framework Request source',false); return false; }
        $this->check('route: current physical framework Request source',true);
        $classes = (new NodeFinder)->find($physical['nodes'],static fn (Node $node): bool => $node instanceof Node\Stmt\Class_
            && strcasecmp($node->namespacedName?->toString() ?? '',self::REQUEST) === 0);
        if (count($classes) !== 1 || ! $this->source->located($request->location,$classes[0],$physical)
            || ! $this->source->located($request->nameLocation,$classes[0]->name,$physical)) { $this->check('route: native Request declaration locations',false); return false; }
        $this->check('route: native Request declaration locations',true);
        $nodes = array_values(array_filter($classes[0]->getMethods(),static fn (Node\Stmt\ClassMethod $node): bool => strtolower($node->name->name) === 'route'));
        if (count($nodes) !== 1) { $this->check('route: one physical route declaration',false); return false; } $node = $nodes[0]; $doc = $node->getDocComment();
        if (! $this->source->located($method->location,$node,$physical) || ! $this->source->located($declaring->location,$node,$physical)
            || ! $this->source->located($method->nameLocation,$node->name,$physical)
            || $this->source->tokens(substr($physical['contents'],$node->getStartFilePos(),$node->getEndFilePos()+1-$node->getStartFilePos())) !== $this->source->tokens(self::ROUTE_BODY)
            || $doc === null || ! str_contains($doc->getText(),'@return ($param is null ? \Illuminate\Routing\Route : object|string|null)')
            || preg_match_all('/@(?:phpstan-|psalm-)?return\b/',$doc->getText()) !== 1
            || $method->returnType->location->file === null || $this->source->path($method->returnType->location->file) !== $physical['path']
            || $method->returnType->location->span->start < $doc->getStartFilePos() || $method->returnType->location->span->end > $doc->getEndFilePos()+1) { $this->check('route: physical body, native locations, and exact return tag',false); return false; }
        $this->check('route: physical body, native locations, and exact return tag',true);
        foreach ($method->parameters as $index=>$parameter) {
            if (ltrim($parameter->name,'$') !== ['param','default'][$index] || $parameter->declaredType !== null
                || ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC) || $parameter->defaultType?->type->__toString() !== 'null'
                || ! $this->source->located($parameter->location,$node->params[$index],$physical)
                || ! $this->source->located($parameter->nameLocation,$node->params[$index]->var,$physical)
                || $parameter->outType !== null || $parameter->closureThisType !== null || $parameter->type === null
                || ! $parameter->type->fromDocblock || $parameter->type->inferred
                || ! $this->sameType($context,$parameter->type->type,$index === 0 ? Type::union(Type::null(),Type::string()) : Type::mixed())) {
                $this->check('route: physical and native parameter '.$index,false); return false;
            }
            $this->check('route: physical and native parameter '.$index,true);
        }
        $helperCalls = (new NodeFinder)->findInstanceOf($node->stmts,Node\Expr\FuncCall::class);
        if (count($helperCalls) !== 3) { $this->check('route: exactly three physical builtin calls',false); return false; }
        foreach ($helperCalls as $index=>$helperCall) {
            $expected = $index === 0 ? 'call_user_func' : 'is_null';
            $native = $this->source->builtinBinding($context,$helperCall,$expected);
            if (! $this->check('route: genuine helper binding '.$index.' '.$expected,$native !== null)) { return false; }
            $this->dependencies[$expected] = $native;
            if ($expected === 'is_null' && ! $this->source->builtin($context,$helperCall,$expected)) { return false; }
            if ($expected === 'call_user_func' && ! $this->source->routeInvoker($context,$native)) { return false; }
        }
        $return = $method->returnType->type;
        // The installed native conditional must retain both object and string alternatives.
        $atom = count($return->atomicTypes) === 1 ? $return->atomicTypes[0] : null;
        if (! $this->check('route: exact param conditional retaining object|string|null',
            $atom instanceof ConditionalType && ! $atom->negated && ! $return->flags->byReference && ! $return->flags->possiblyUndefined
            && count($atom->subject->atomicTypes) === 1 && $atom->subject->atomicTypes[0] instanceof VariableType
            && $atom->subject->atomicTypes[0]->name === '$param' && ! $atom->subject->flags->byReference && ! $atom->subject->flags->possiblyUndefined
            && $this->sameType($context,$atom->target,Type::null()) && $this->source->plainObject($atom->then,'Illuminate\\Routing\\Route')
            && $this->sameType($context,$atom->otherwise,Type::union(Type::object(),Type::string(),Type::null())))) { return false; }
        $this->dependencies['request'] = $request; $this->dependencies['route'] = $method;
        $this->dependencies['declaring_route'] = $declaring; $this->stages[] = 'complete physical Request route and native conditional alternatives';
        return true;
    }

    private function sameType(IssueFilterContext $context,Type $actual,Type $expected): bool
    {
        return ! $actual->flags->byReference && ! $actual->flags->possiblyUndefined
            && $context->types->isContainedBy($actual,$expected) && $context->types->isContainedBy($expected,$actual);
    }
}
