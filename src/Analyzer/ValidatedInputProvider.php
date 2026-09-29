<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\RequestRuleFields;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Refines validated results from literal rules without typing raw request input. */
final class ValidatedInputProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const FORM_REQUEST = 'Illuminate\\Foundation\\Http\\FormRequest';

    private ?PhpSource $source = null;
    private ?ContainerBindings $bindings = null;

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->bindings = null;
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::FORM_REQUEST, 'validated')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $receiver->atomicTypes[0] instanceof NamedObjectType
            || ! $this->nativeMethod($context->codebase, $receiver->atomicTypes[0]->name)
        ) {
            return null;
        }
        $bindings = $this->bindings ??= new ContainerBindings($this->root);
        if ($bindings->configured('validator') || $bindings->configured('Illuminate\\Contracts\\Validation\\Factory')) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $key = $call->getArgument(0, 'key');
        $keyType = $key?->type;
        $fields = RequestRuleFields::resolve(
            $receiver->atomicTypes[0]->name,
            $context->codebase,
            $this->source ??= new PhpSource($this->root),
        );
        // data_get returns the entire validated array for an omitted or null key.
        if ($key !== null && ($keyType === null || ! $context->types->isContainedBy($keyType, Type::null()))) {
            $name = $keyType?->getLiteralString();
            $field = $name === null || $fields === null ? null : RequestRuleFields::select($fields, $name);
            if ($field === null) {
                return null;
            }
            $default = $call->getArgument(1, 'default');
            if (
                $field->optional
                && $default !== null
                && (
                    $default->type === null
                    || ! $context->types->isContainedBy(
                        $default->type,
                        Type::union(
                            Type::null(),
                            Type::string(),
                            Type::int(),
                            Type::float(),
                            Type::bool(),
                            Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
                        ),
                    )
                )
            ) {
                // data_get evaluates Closure defaults. Unknown or object defaults
                // need callable return analysis and therefore defer to Laravel.
                return null;
            }

            return $field->optional ? Type::union($field->type, $default?->type ?? Type::null()) : $field->type;
        }

        // The installed Laravel validator builds top-level keys from rule names.
        // Preserve Larastan's array<string, mixed> contract only when source
        // analysis proves those names are strings; dynamic rules remain broad.
        if ($fields !== null) {
            return RequestRuleFields::shape($fields);
        }
        $class = $receiver->atomicTypes[0]->name;
        $source = $this->source ??= new PhpSource($this->root);
        $namedKeys = RequestRuleFields::hasLiteralStringKeys($class, $context->codebase, $source)
            || $this->documentedRuleKeys($class, $context, $source);

        return Type::array(
            $namedKeys ? Type::string() : Type::union(Type::int(), Type::string()),
            Type::mixed(),
        );
    }

    private function documentedRuleKeys(string $class, ReturnTypeProviderContext $context, PhpSource $source): bool
    {
        if (! $this->nativeValidationPipeline($class, $context->codebase, $source)) {
            return false;
        }
        $method = $context->codebase->getMethod($class, 'rules')
            ?? $context->codebase->getDeclaringMethod($class, 'rules');

        return $method !== null
            && ! $method->abstract
            && ! $method->static
            && $this->ruleKeys($method, $context, $source, []);
    }

    /** @param array<string, true> $seen */
    private function ruleKeys(
        FunctionLikeMetadata $method,
        ReturnTypeProviderContext $context,
        PhpSource $source,
        array $seen,
    ): bool {
        $owner = $method->identifier->class ?? '';
        if ($owner === '' || isset($seen[strtolower($owner)])) {
            return false;
        }
        $seen[strtolower($owner)] = true;
        $node = (new ModelReflection($context->codebase, $source))->methodNode($method);
        $statements = $node?->stmts ?? [];
        $last = $statements === [] ? null : $statements[array_key_last($statements)];
        if (! $last instanceof Node\Stmt\Return_ || ! $last->expr instanceof Node\Expr\Array_) {
            return false;
        }
        $documented = $method->returnType;
        $atom = $documented?->type->atomicTypes[0] ?? null;
        $hasStringKeyContract = $documented?->fromDocblock === true
            && count($documented->type->atomicTypes) === 1
            && $atom instanceof KeyedArrayType
            && $atom->keyType !== null
            && $context->types->isContainedBy($atom->keyType, Type::string());
        $inheritedContract = false;
        foreach ($last->expr->items as $item) {
            if ($item->unpack) {
                if (
                    $item->key !== null
                    || ! $item->value instanceof Node\Expr\StaticCall
                    || ! $item->value->class instanceof Node\Name
                    || strcasecmp($item->value->class->toString(), 'parent') !== 0
                    || ! $item->value->name instanceof Node\Identifier
                    || strcasecmp($item->value->name->toString(), 'rules') !== 0
                    || $item->value->args !== []
                ) {
                    return false;
                }
                $parent = $context->codebase->getClass($owner)?->directParentClass;
                $parentMethod = $parent === null ? null : $context->codebase->getMethod($parent, 'rules')
                    ?? $context->codebase->getDeclaringMethod($parent, 'rules');
                if ($parentMethod === null || ! $this->ruleKeys($parentMethod, $context, $source, $seen)) {
                    return false;
                }
                $inheritedContract = true;
                continue;
            }
            $key = PhpSource::value($item->key);
            if ($item->byRef || ! is_string($key)
                || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.(?:[A-Za-z_][A-Za-z0-9_]*|\*))*$/D', $key)) {
                return false;
            }
        }

        return $hasStringKeyContract || $inheritedContract;
    }

    private function nativeValidationPipeline(string $class, Codebase $codebase, PhpSource $source): bool
    {
        foreach ([
            'getValidatorInstance', 'createDefaultValidator', 'validator',
            'validationRules', 'withValidator', 'setValidator',
            'validateResolved', 'passedValidation', 'validationData',
        ] as $name) {
            $method = $codebase->getMethod($class, $name) ?? $codebase->getDeclaringMethod($class, $name);
            if ($method !== null && ! in_array(strtolower($method->identifier->class ?? ''), [
                'illuminate\\foundation\\http\\formrequest',
                'illuminate\\validation\\validateswhenresolvedtrait',
            ], true)) {
                return false;
            }
        }
        $reflection = new ModelReflection($codebase, $source);
        $prepare = $codebase->getMethod($class, 'prepareForValidation')
            ?? $codebase->getDeclaringMethod($class, 'prepareForValidation');
        if ($prepare !== null
            && ! in_array(strtolower($prepare->identifier->class ?? ''), [
                'illuminate\\foundation\\http\\formrequest',
                'illuminate\\validation\\validateswhenresolvedtrait',
            ], true)
            && ! $this->onlyPreparesData($class, $prepare, $codebase, $reflection)) {
            return false;
        }
        $after = $codebase->getMethod($class, 'after') ?? $codebase->getDeclaringMethod($class, 'after');

        return $after === null
            || strcasecmp($after->identifier->class ?? '', self::FORM_REQUEST) === 0
            || $this->afterOnlyAddsErrors($class, $after, $codebase, $reflection);
    }

    private function onlyPreparesData(
        string $class,
        FunctionLikeMetadata $method,
        Codebase $codebase,
        ModelReflection $reflection,
    ): bool
    {
        $statements = $reflection->methodNode($method)?->stmts ?? [];
        if (count($statements) !== 1 || ! $statements[0] instanceof Node\Stmt\If_) {
            return false;
        }
        $if = $statements[0];
        if ($if->else !== null || $if->elseifs !== [] || count($if->stmts) !== 1
            || ! $if->cond instanceof Node\Expr\BooleanNot
            || ! $if->cond->expr instanceof Node\Expr\MethodCall
            || ! $this->nativeRequestCall($class, $if->cond->expr, 'filled', $codebase)
            || ! $if->stmts[0] instanceof Node\Stmt\Expression
            || ! $if->stmts[0]->expr instanceof Node\Expr\MethodCall
            || ! $this->nativeRequestCall($class, $if->stmts[0]->expr, 'merge', $codebase)) {
            return false;
        }
        $array = $if->stmts[0]->expr->args[0]->value;
        foreach ($array->items as $item) {
            $key = PhpSource::value($item->key);
            if ($item->unpack || $item->byRef || ! is_string($key)
                || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key)) {
                return false;
            }
            if ($item->value instanceof Node\Scalar\String_) {
                continue;
            }
            if (! $item->value instanceof Node\Expr\Ternary
                || ! $item->value->cond instanceof Node\Expr\MethodCall
                || ! $this->nativeRequestCall($class, $item->value->cond, 'has', $codebase)
                || ! $item->value->if instanceof Node\Scalar\String_
                || ! $item->value->else instanceof Node\Scalar\String_) {
                return false;
            }
        }

        return true;
    }

    private function nativeRequestCall(
        string $class,
        Node\Expr\MethodCall $call,
        string $name,
        Codebase $codebase,
    ): bool {
        if (! $call->var instanceof Node\Expr\Variable || $call->var->name !== 'this'
            || ! $call->name instanceof Node\Identifier
            || strcasecmp($call->name->toString(), $name) !== 0
            || count($call->args) !== 1 || ! $call->args[0] instanceof Node\Arg
            || $call->args[0]->name !== null || $call->args[0]->unpack) {
            return false;
        }
        $owner = $codebase->getMethod($class, $name)?->identifier->class
            ?? $codebase->getDeclaringMethod($class, $name)?->identifier->class;
        if (! in_array(strtolower($owner ?? ''), match ($name) {
            'merge' => ['illuminate\\http\\request'],
            'filled', 'has' => ['illuminate\\http\\request', 'illuminate\\support\\traits\\interactswithdata'],
            'input' => ['illuminate\\http\\request', 'illuminate\\http\\concerns\\interactswithinput'],
            default => [],
        }, true)) {
            return false;
        }

        return $name === 'merge'
            ? $call->args[0]->value instanceof Node\Expr\Array_
            : $call->args[0]->value instanceof Node\Scalar\String_;
    }

    private function afterOnlyAddsErrors(
        string $class,
        FunctionLikeMetadata $method,
        Codebase $codebase,
        ModelReflection $reflection,
    ): bool {
        $returned = $reflection->returnExpression($method);
        if (! $returned instanceof Node\Expr\Array_ || $returned->items === []) {
            return false;
        }
        $finder = new NodeFinder;
        foreach ($returned->items as $item) {
            if ($item->unpack || $item->byRef || ! $item->value instanceof Node\Expr\Closure
                || $item->value->uses !== [] || count($item->value->params) !== 1
                || ! $item->value->params[0]->var instanceof Node\Expr\Variable
                || $item->value->params[0]->var->name !== 'validator'
                || $item->value->params[0]->byRef || $item->value->params[0]->variadic
                || $item->value->params[0]->default !== null
                || ! $item->value->params[0]->type instanceof Node\Name
                || strcasecmp(
                    ($item->value->params[0]->type->getAttribute('resolvedName') instanceof Node\Name
                        ? $item->value->params[0]->type->getAttribute('resolvedName')
                        : $item->value->params[0]->type)->toString(),
                    'Illuminate\\Validation\\Validator',
                ) !== 0
                || $finder->findInstanceOf($item->value->stmts, Node\Expr\Closure::class) !== []
                || $finder->findInstanceOf($item->value->stmts, Node\Expr\ArrowFunction::class) !== []) {
                return false;
            }
            $allowed = [];
            $allowedThis = [];
            $errorCalls = [];
            foreach ($finder->findInstanceOf($item->value->stmts, Node\Expr\MethodCall::class) as $call) {
                if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'validator') {
                    if (! $call->name instanceof Node\Identifier
                        || strcasecmp($call->name->toString(), 'errors') !== 0 || $call->args !== []) {
                        return false;
                    }
                    $allowed[spl_object_id($call->var)] = true;
                    $errorCalls[spl_object_id($call)] = true;
                } elseif ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
                    if (! $call->name instanceof Node\Identifier
                        || ! in_array(strtolower($call->name->toString()), ['has', 'input'], true)
                        || ! $this->nativeRequestCall($class, $call, strtolower($call->name->toString()), $codebase)) {
                        return false;
                    }
                    $allowedThis[spl_object_id($call->var)] = true;
                }
                if ($call->name instanceof Node\Identifier
                    && in_array(strtolower($call->name->toString()), ['setrules', 'addrules', 'setdata', 'setvalidator'], true)) {
                    return false;
                }
            }
            foreach ($finder->findInstanceOf($item->value->stmts, Node\Expr\MethodCall::class) as $call) {
                if ($call->var instanceof Node\Expr\MethodCall
                    && isset($errorCalls[spl_object_id($call->var)])
                    && (! $call->name instanceof Node\Identifier
                    || ! in_array(strtolower($call->name->toString()), ['add', 'isnotempty'], true))) {
                    return false;
                }
            }
            foreach ($finder->findInstanceOf($item->value->stmts, Node\Expr\Variable::class) as $variable) {
                if ($variable->name === 'validator' && ! isset($allowed[spl_object_id($variable)])
                    || $variable->name === 'this' && ! isset($allowedThis[spl_object_id($variable)])) {
                    return false;
                }
            }
            foreach ($finder->findInstanceOf($item->value->stmts, Node\Expr\PropertyFetch::class) as $property) {
                if ($property->var instanceof Node\Expr\Variable && $property->var->name === 'this') {
                    return false;
                }
            }
        }

        return true;
    }

    private function nativeMethod(Codebase $codebase, string $class): bool
    {
        $method = $codebase->getMethod($class, 'validated') ?? $codebase->getDeclaringMethod($class, 'validated');
        if ($method === null || strcasecmp($method->identifier->class ?? '', self::FORM_REQUEST) !== 0) {
            return false;
        }
        $returnType = $method->returnType->type ?? $method->declaredReturnType?->type;
        if ($returnType !== null && (string) $returnType !== 'mixed') {
            return false;
        }
        $validator = $codebase->getDeclaringProperty($class, '$validator') ?? $codebase->getProperty(
            $class,
            '$validator',
        );
        $native = $codebase->getProperty(self::FORM_REQUEST, '$validator');
        $location = $validator->nameLocation ?? $validator?->location;
        $nativeLocation = $native->nameLocation ?? $native?->location;
        if (
            $location === null
            || $nativeLocation === null
            || $location->file !== $nativeLocation->file
            || $location->span->start !== $nativeLocation->span->start
        ) {
            return false;
        }
        foreach ($codebase->getMultipleClasses([$class, ...$codebase->getClassAncestors($class)]) as $metadata) {
            foreach ([...($metadata->pseudoMethods ?? []), ...($metadata->staticPseudoMethods ?? [])] as $name) {
                if (strcasecmp($name, 'validated') === 0) {
                    return false;
                }
            }
        }

        return true;
    }
}
