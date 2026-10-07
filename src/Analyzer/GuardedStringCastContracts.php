<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\Variance;
use Mago\Sdk\Analyzer\Type\VariableType;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Fresh physical source plus exact declaration locations; no after-file authority. */
final class GuardedStringCastContracts
{
    public array $builtinReceipts = [];
    public function __construct(private readonly string $root) {}

    public function path(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        if (str_starts_with($name, '//?/')) { $name = substr($name, 4); }
        if (! str_starts_with($name, '/') && preg_match('~^[A-Za-z]:/~', $name) !== 1) { $name = $this->root.'/'.$name; }
        $name = realpath($name);
        return $name === false ? '' : (PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $name)) : $name);
    }

    /** @return array{path:string,contents:string,hash:string,nodes:array,parents:array<int,Node>}|null */
    public function read(?string $name, ?string $analyzed = null): ?array
    {
        if ($name === null) { return null; }
        $path = $this->path($name); $root = $this->path($this->root);
        if ($path === '' || $root === '' || ! str_starts_with($path, rtrim($root, '/').'/')) { return null; }
        $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) > 1024 * 1024 || $analyzed !== null && $contents !== $analyzed) { return null; }
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        } catch (\PhpParser\Error) { return null; }
        $parents = [];
        $visit = static function (mixed $value, ?Node $parent) use (&$visit, &$parents): void {
            if (is_array($value)) { foreach ($value as $item) { $visit($item, $parent); } }
            elseif ($value instanceof Node) {
                if ($parent !== null) { $parents[spl_object_id($value)] = $parent; }
                foreach ($value->getSubNodeNames() as $field) { $visit($value->$field, $value); }
            }
        };
        $visit($nodes, null);
        $visit = null;
        return ['path'=>$path,'contents'=>$contents,'hash'=>hash('sha256',$contents),'nodes'=>$nodes,'parents'=>$parents];
    }

    public function located(?SourceLocation $location, Node $node, array $file): bool
    {
        return $location !== null && $location->file !== null && $this->path($location->file) === $file['path']
            && [$location->span->start,$location->span->end] === [$node->getStartFilePos(),$node->getEndFilePos()+1]
            && hash_file('sha256',$file['path']) === $file['hash'];
    }

    /** @return list<Node> */
    public function ancestors(Node $node, array $file): array
    {
        $result = [];
        while (isset($file['parents'][spl_object_id($node)])) { $node = $file['parents'][spl_object_id($node)]; $result[] = $node; }
        return $result;
    }

    public function tokens(string $source): string
    {
        $parts = [];
        foreach (token_get_all('<?php '.$source) as $token) {
            if (! is_array($token)) { $parts[] = $token; }
            elseif (! in_array($token[0],[T_OPEN_TAG,T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) { $parts[] = $token[1]; }
        }
        return implode('', $parts);
    }

    public function plainObject(?Type $type, string $name): bool
    {
        if ($type === null || count($type->atomicTypes) !== 1 || $type->flags->byReference || $type->flags->possiblyUndefined) { return false; }
        $atom = $type->atomicTypes[0];
        return $atom instanceof NamedObjectType && strcasecmp($atom->name,$name) === 0 && ($atom->parameters ?? []) === []
            && ($atom->variances ?? []) === [] && ($atom->intersections ?? []) === [] && ! $atom->static && ! $atom->isThis && ! $atom->remappedParameters;
    }

    /** Exact callable namespace resolution and actual builtin native metadata. */
    public function builtin(IssueFilterContext $context, Node\Expr\FuncCall $call, string $expected): bool
    {
        $function = $this->builtinBinding($context,$call,$expected);
        if (! $this->receipt($expected,'resolved genuine builtin binding',$function !== null)) { return false; }
        $parameter = $function->parameters[0];
        if (! $this->receipt($expected,'closed predicate metadata',
            in_array($expected,['is_object','is_callable','is_null'],true) && $function->templates === []
            && $function->whereConstraints === [] && $function->globalsAccessed === [] && $function->assertions === []
            && $function->ifFalseAssertions === [] && ! $function->assertionsInferred && $function->hasDocblock
            && $function->declaredReturnType !== null && ! $function->declaredReturnType->fromDocblock && ! $function->declaredReturnType->inferred
            && $this->same($context,$function->declaredReturnType->type,Type::bool())
            && $function->returnType !== null && ! $function->returnType->inferred
            && ! $function->returnType->type->flags->byReference && ! $function->returnType->type->flags->possiblyUndefined)) { return false; }
        if (! $this->receipt($expected,'selected value parameter and no reference/default effects',
            $parameter->name === '$value' && ! $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
            && ! $parameter->flags->contains(MetadataFlags::VARIADIC) && ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
            && $parameter->defaultType === null && $parameter->outType === null && $parameter->closureThisType === null
            && $parameter->declaredType !== null && ! $parameter->declaredType->fromDocblock && ! $parameter->declaredType->inferred
            && $parameter->type !== null && ! $parameter->type->inferred
            && $this->same($context,$parameter->declaredType->type,Type::mixed()) && $this->same($context,$parameter->type->type,Type::mixed()))) { return false; }
        $assertions = $function->ifTrueAssertions;
        $assertion = $assertions['$value'][0] ?? null;
        if (! $this->receipt($expected,'one declared positive type assertion',array_keys($assertions) === ['$value']
            && count($assertions['$value']) === 1 && $assertion instanceof TypeAssertion && $assertion->kind === TypeAssertionKind::IsType)) { return false; }
        if ($expected === 'is_callable') {
            if (! $this->receipt($expected,'ordinary bool return and generic callable assertion',
                count($function->parameters) === 3 && ! $function->returnType->fromDocblock && $parameter->type->fromDocblock
                && $this->same($context,$function->returnType->type,Type::bool()) && $this->genericCallable($context,$assertion->type))) { return false; }
            $syntax = $function->parameters[1]; $output = $function->parameters[2];
            // The selected source call supplies only $value. The optional reference output is never passed.
            return $this->receipt($expected,'omitted syntax flag false and omitted reference string output',
                $syntax->name === '$syntax_only' && $syntax->flags->contains(MetadataFlags::HAS_DEFAULT)
                && ! $syntax->flags->contains(MetadataFlags::BY_REFERENCE) && ! $syntax->flags->contains(MetadataFlags::VARIADIC)
                && $syntax->declaredType !== null && $syntax->type !== null && $syntax->defaultType !== null
                && ! $syntax->declaredType->fromDocblock && $syntax->type->fromDocblock
                && ! $syntax->declaredType->inferred && ! $syntax->type->inferred && ! $syntax->defaultType->fromDocblock && $syntax->defaultType->inferred
                && $this->same($context,$syntax->declaredType->type,Type::bool()) && $this->same($context,$syntax->type->type,Type::bool())
                && $this->same($context,$syntax->defaultType->type,Type::false()) && $syntax->outType === null && $syntax->closureThisType === null
                && $output->name === '$callable_name' && $output->flags->contains(MetadataFlags::HAS_DEFAULT)
                && $output->flags->contains(MetadataFlags::BY_REFERENCE) && ! $output->flags->contains(MetadataFlags::VARIADIC)
                && $output->declaredType === null && $output->type === null && $output->defaultType !== null
                && ! $output->defaultType->fromDocblock && $output->defaultType->inferred && $this->same($context,$output->defaultType->type,Type::null())
                && $output->outType !== null && $output->outType->fromDocblock && ! $output->outType->inferred
                && $this->same($context,$output->outType->type,Type::string()) && $output->closureThisType === null);
        }
        $target = $expected === 'is_object' ? Type::object() : Type::null();
        $return = $function->returnType->type;
        $conditional = count($return->atomicTypes) === 1 ? $return->atomicTypes[0] : null;
        return $this->receipt($expected,'exact value conditional and matching assertion',count($function->parameters) === 1
            && ! $parameter->type->fromDocblock && $function->returnType->fromDocblock
            && $conditional instanceof ConditionalType && ! $conditional->negated
            && count($conditional->subject->atomicTypes) === 1 && $conditional->subject->atomicTypes[0] instanceof VariableType
            && $conditional->subject->atomicTypes[0]->name === '$value' && ! $conditional->subject->flags->byReference
            && ! $conditional->subject->flags->possiblyUndefined && $this->same($context,$conditional->target,$target)
            && $this->same($context,$conditional->then,Type::true()) && $this->same($context,$conditional->otherwise,Type::false())
            && $this->same($context,$assertion->type,$target));
    }

    private function genericCallable(IssueFilterContext $context,Type $type,bool $optionalVariadic = false): bool
    {
        $atom = count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;
        if ($type->flags->byReference || $type->flags->possiblyUndefined || ! $atom instanceof CallableType || $atom->alias !== null || $atom->signature === null) { return false; }
        $signature = $atom->signature; $parameter = $signature->parameters[0] ?? null;
        return ! $signature->pure && ! $signature->closure && $signature->source === null && $signature->constraints === []
            && count($signature->parameters) === 1 && $parameter !== null && $parameter->name === null && $parameter->type !== null
            && $this->same($context,$parameter->type,Type::mixed()) && $parameter->closureThisType === null
            && ! $parameter->byReference && $parameter->variadic && $parameter->hasDefault === $optionalVariadic
            && $signature->returnType !== null && $this->same($context,$signature->returnType,Type::mixed());
    }

    /** Exact builtin contract confirmed by genuine SDK observation and mutation controls. */
    public function routeInvoker(IssueFilterContext $context,FunctionLikeMetadata $function): bool
    {
        $name = 'call_user_func';
        if (! $this->receipt($name,'closed invoker metadata',
            $function->name === $name && $function->originalName === $name && count($function->parameters) === 2
            && count($function->templates) === 2 && $function->whereConstraints === [] && $function->globalsAccessed === []
            && $function->assertions === [] && $function->ifTrueAssertions === [] && $function->ifFalseAssertions === []
            && ! $function->assertionsInferred && $function->hasDocblock && $function->declaredReturnType !== null
            && ! $function->declaredReturnType->fromDocblock && ! $function->declaredReturnType->inferred
            && $this->same($context,$function->declaredReturnType->type,Type::mixed())
            && $function->returnType !== null && $function->returnType->fromDocblock && ! $function->returnType->inferred)) { return false; }
        foreach ($function->templates as $index=>$template) {
            if (! $this->receipt($name,'template declaration '.['I','R'][$index],
                $template->name === ['I','R'][$index] && $template->definingEntity->kind === GenericParentKind::FunctionLike
                && $template->definingEntity->name === '' && $template->definingEntity->member === $name
                && $this->same($context,$template->constraint,Type::mixed()) && $template->default === null
                && $template->variance === Variance::Invariant && ! $template->readonly)) { return false; }
        }
        $callback = $function->parameters[0]; $args = $function->parameters[1];
        if (! $this->receipt($name,'declared callback and unused variadic arguments',
            $callback->name === '$callback' && $callback->declaredType !== null && $callback->type !== null
            && ! $callback->declaredType->fromDocblock && ! $callback->declaredType->inferred
            && $callback->type->fromDocblock && ! $callback->type->inferred
            && ! $callback->flags->contains(MetadataFlags::BY_REFERENCE) && ! $callback->flags->contains(MetadataFlags::VARIADIC)
            && ! $callback->flags->contains(MetadataFlags::HAS_DEFAULT) && $callback->defaultType === null
            && $callback->outType === null && $callback->closureThisType === null
            && $this->callableAndString($context,$callback->declaredType->type,null)
            && $this->callableAndString($context,$callback->type->type,$function->templates)
            && $args->name === '$args' && $args->declaredType !== null && $args->type !== null
            && ! $args->declaredType->fromDocblock && ! $args->declaredType->inferred && $args->type->fromDocblock && ! $args->type->inferred
            && $this->same($context,$args->declaredType->type,Type::mixed()) && $this->genericParameter($context,$args->type->type,$function->templates[0])
            && $args->flags->contains(MetadataFlags::VARIADIC) && ! $args->flags->contains(MetadataFlags::BY_REFERENCE)
            && ! $args->flags->contains(MetadataFlags::HAS_DEFAULT) && $args->defaultType === null && $args->outType === null && $args->closureThisType === null)) { return false; }
        return $this->receipt($name,'effective return R belongs to the exact invoker template',
            $this->genericParameter($context,$function->returnType->type,$function->templates[1]));
    }

    private function genericParameter(IssueFilterContext $context,Type $type,\Mago\Sdk\Analyzer\Metadata\TemplateMetadata $template): bool
    {
        $atom = count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;
        return ! $type->flags->byReference && ! $type->flags->possiblyUndefined && $atom instanceof GenericParameterType
            && $atom->name === $template->name && $atom->definingEntity == $template->definingEntity
            && ($atom->intersections ?? []) === [] && $this->same($context,$atom->constraint,$template->constraint);
    }

    private function callableAndString(IssueFilterContext $context,Type $type,?array $templates): bool
    {
        if ($type->flags->byReference || $type->flags->possiblyUndefined) { return false; }
        $callables = []; $strings = []; $callableStrings = [];
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof CallableType) { $callables[] = $atom; }
            elseif ($atom instanceof ScalarType && $this->same($context,Type::fromAtomics($atom),Type::string())) { $strings[] = $atom; }
            elseif ($templates !== null && $atom instanceof ScalarType
                && $atom->kind === \Mago\Sdk\Analyzer\Type\ScalarTypeKind::String
                && $atom->refinement instanceof \Mago\Sdk\Analyzer\Type\StringType
                && $atom->refinement->literalKind === \Mago\Sdk\Analyzer\Type\StringLiteralKind::General
                && $atom->refinement->literalValue === null && ! $atom->refinement->numeric
                && $atom->refinement->truthy && $atom->refinement->nonEmpty && $atom->refinement->callable
                && $atom->refinement->casing === \Mago\Sdk\Analyzer\Type\StringCasing::Unspecified) { $callableStrings[] = $atom; }
            else { return false; }
        }
        if (count($callables) !== 1 || count($strings) !== 1 || count($callableStrings) !== ($templates === null ? 0 : 1)) { return false; }
        $atom = $callables[0];
        if ($templates === null) { return $this->genericCallable($context,Type::fromAtomics($atom),true); }
        $signature = $atom->signature; $parameter = $signature?->parameters[0] ?? null;
        return $atom->alias === null && $signature !== null && ! $signature->pure && ! $signature->closure
            && $signature->source === null && $signature->constraints === [] && count($signature->parameters) === 1
            && $parameter !== null && $parameter->name === null && $parameter->type !== null
            && $this->genericParameter($context,$parameter->type,$templates[0]) && $parameter->closureThisType === null
            && ! $parameter->byReference && $parameter->variadic && ! $parameter->hasDefault
            && $signature->returnType !== null && $this->genericParameter($context,$signature->returnType,$templates[1]);
    }

    private function same(IssueFilterContext $context,Type $actual,Type $expected): bool
    {
        return ! $actual->flags->byReference && ! $actual->flags->possiblyUndefined && $context->types->equals($actual,$expected);
    }

    private function receipt(string $function,string $step,bool $passed): bool
    {
        $this->builtinReceipts[] = ['function'=>$function,'step'=>$step,'passed'=>$passed];
        return $passed;
    }

    public function builtinBinding(IssueFilterContext $context, Node\Expr\FuncCall $call, string $expected): ?FunctionLikeMetadata
    {
        if (! $call->name instanceof Node\Name) { return null; }
        $resolved = $call->name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) { $name = $resolved->toString(); }
        elseif ($call->name instanceof Node\Name\FullyQualified) { $name = $call->name->toString(); }
        else {
            $namespaced = $call->name->getAttribute('namespacedName');
            if ($namespaced instanceof Node\Name && strcasecmp($namespaced->toString(),$expected) !== 0
                && $context->codebase->getFunction($namespaced->toString()) !== null) { return null; }
            $name = $call->name->toString();
        }
        if (strcasecmp($name,$expected) !== 0) { return null; }
        $function = $context->codebase->getFunction($expected);
        return $function !== null && $function->kind === FunctionLikeKind::Function_
            && $function->identifier->kind === \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Function_ && $function->identifier->class === null
            && strcasecmp($function->identifier->name,$expected) === 0 && strcasecmp($function->name,$expected) === 0
            && strcasecmp($function->originalName,$expected) === 0
            && $function->flags->contains(MetadataFlags::BUILTIN) && ! $function->flags->contains(MetadataFlags::USER_DEFINED)
            && ! $function->flags->contains(MetadataFlags::BY_REFERENCE) && $function->parameters !== []
            && ! $function->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE) ? $function : null;
    }

    /** Bind the enclosing named owner to a genuine physical method/function. */
    public function owner(IssueFilterContext $context, Node $node, array $file): ?FunctionLikeMetadata
    {
        $owner = null; $class = null;
        foreach ($this->ancestors($node,$file) as $parent) {
            if ($owner === null && ($parent instanceof Node\Stmt\Function_ || $parent instanceof Node\Stmt\ClassMethod)) { $owner = $parent; }
            if ($parent instanceof Node\Stmt\ClassLike) { $class = $parent; break; }
        }
        if ($owner instanceof Node\Stmt\Function_) { $metadata = $context->codebase->getFunction($owner->namespacedName->toString()); }
        elseif ($owner instanceof Node\Stmt\ClassMethod && $class instanceof Node\Stmt\Class_ && $class->namespacedName !== null) {
            $name = $class->namespacedName->toString(); $metadata = $context->codebase->getMethod($name,$owner->name->toString());
            $declaring = $context->codebase->getDeclaringMethod($name,$owner->name->toString()); $nativeClass = $context->codebase->getClass($name);
            if ($nativeClass === null || $nativeClass->kind !== ClassLikeKind::Class_ || $nativeClass->hasIncompleteHierarchy()
                || ! $this->located($nativeClass->location,$class,$file) || ! $this->located($nativeClass->nameLocation,$class->name,$file)
                || $declaring === null || $metadata === null || $declaring->identifier->class !== $metadata->identifier->class
                || strcasecmp($metadata->identifier->class ?? '',$name) !== 0 || ! $this->located($declaring->location,$owner,$file)) { return null; }
        } else { return null; }
        if ($metadata === null || ! $this->located($metadata->location,$owner,$file) || ! $this->located($metadata->nameLocation,$owner->name,$file)
            || $owner->returnsByRef() || $metadata->flags->contains(MetadataFlags::BY_REFERENCE) || $metadata->flags->contains(MetadataFlags::BUILTIN)
            || $metadata->templates !== [] || count($metadata->parameters) !== count($owner->params)) { return null; }
        foreach ($owner->params as $index=>$parameter) {
            $native = $metadata->parameters[$index];
            if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name) || $parameter->byRef || $parameter->variadic
                || ltrim($native->name,'$') !== $parameter->var->name || $native->flags->contains(MetadataFlags::BY_REFERENCE)
                || $native->flags->contains(MetadataFlags::VARIADIC) || ! $this->located($native->location,$parameter,$file)
                || ! $this->located($native->nameLocation,$parameter->var,$file)) { return null; }
            if ($parameter->type === null ? $native->declaredType !== null : ! $this->located($native->declaredType?->location,$parameter->type,$file)) { return null; }
            if ($parameter->type instanceof Node\Name && ! $this->plainObject($native->declaredType?->type,$parameter->type->toString())) { return null; }
            if ($parameter->type instanceof Node\Identifier) {
                $expected = $this->primitive($parameter->type->toString());
                if ($expected === null || $native->declaredType === null || ! $context->types->isContainedBy($native->declaredType->type,$expected)
                    || ! $context->types->isContainedBy($expected,$native->declaredType->type)) { return null; }
            } elseif ($parameter->type !== null && ! $parameter->type instanceof Node\Name) { return null; }
            if ($owner->getDocComment() === null && $native->declaredType !== null && ($native->type === null
                || ! $context->types->isContainedBy($native->type->type,$native->declaredType->type)
                || ! $context->types->isContainedBy($native->declaredType->type,$native->type->type))) { return null; }
        }
        return $metadata;
    }

    private function primitive(string $name): ?Type
    {
        return match (strtolower($name)) { 'array'=>Type::array(Type::union(Type::int(),Type::string()),Type::mixed()),'object'=>Type::object(),'string'=>Type::string(),'int'=>Type::int(),'bool'=>Type::bool(),'float'=>Type::float(),'mixed'=>Type::mixed(),default=>null };
    }

    public function noAliases(Node\FunctionLike $scope, string $variable): bool
    {
        if ($scope->returnsByRef()) { return false; }
        foreach ($scope->getParams() as $parameter) { if ($parameter->byRef) { return false; } }
        return (new NodeFinder)->findFirst([$scope],static fn (Node $node): bool => $node instanceof Node\Expr\AssignRef
            || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Variable && ! is_string($node->name)
            || $node instanceof Node\Expr\ClosureUse && $node->byRef
            || $node instanceof Node\Stmt\Foreach_ && $node->byRef) === null;
    }
}
