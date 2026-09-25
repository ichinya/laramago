<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;

/** A deliberately small syntax-only model of successful Laravel validation. */
final class RequestRuleFields
{
    /** @return array<string, ArrayItem>|null */
    public static function resolve(string $class, Codebase $codebase, PhpSource $source): ?array
    {
        if (! self::hasNativeValidation($class, $codebase, $source)) {
            return null;
        }
        $method = $codebase->getMethod($class, 'rules') ?? $codebase->getDeclaringMethod($class, 'rules');
        if ($method === null || $method->abstract || $method->static) {
            return null;
        }
        $expression = (new ModelReflection($codebase, $source))->returnExpression($method);
        if (! $expression instanceof Node\Expr\Array_) {
            return null;
        }
        $paths = [];
        foreach ($expression->items as $item) {
            $key = PhpSource::value($item->key);
            if (
                $item->unpack
                || $item->byRef
                || ! is_string($key)
                || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.(?:[A-Za-z_][A-Za-z0-9_]*|\*))*$/D', $key)
            ) {
                return null;
            }
            $rules = self::rules($item->value, $codebase, $source);
            if ($rules === null) {
                return null;
            }
            $paths[$key] = $rules;
        }
        // Every array boundary must be explicit. In particular, a wildcard
        // rule does not prove that a missing parent or any elements exist.
        foreach (array_keys($paths) as $path) {
            $segments = explode('.', $path);
            array_pop($segments);
            while ($segments !== []) {
                $parent = implode('.', $segments);
                if (! in_array('array', $paths[$parent] ?? [], true)) {
                    return null;
                }
                array_pop($segments);
            }
        }

        return self::level($paths);
    }

    public static function hasLiteralStringKeys(string $class, Codebase $codebase, PhpSource $source): bool
    {
        if (! self::hasNativeValidation($class, $codebase, $source)) {
            return false;
        }
        $method = $codebase->getMethod($class, 'rules') ?? $codebase->getDeclaringMethod($class, 'rules');
        if ($method === null || $method->abstract || $method->static) {
            return false;
        }
        $expression = self::guardedRulesArray($class, $method, $codebase, $source);
        if (! $expression instanceof Node\Expr\Array_) {
            return false;
        }
        foreach ($expression->items as $item) {
            $key = PhpSource::value($item->key);
            if (
                $item->unpack
                || $item->byRef
                || ! is_string($key)
                || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.(?:[A-Za-z_][A-Za-z0-9_]*|\*))*$/D', $key)
            ) {
                return false;
            }
        }

        return true;
    }

    private static function guardedRulesArray(
        string $class,
        FunctionLikeMetadata $method,
        Codebase $codebase,
        PhpSource $source,
    ): ?Node\Expr\Array_ {
        $reflection = new ModelReflection($codebase, $source);
        $simple = $reflection->returnExpression($method);
        if ($simple instanceof Node\Expr\Array_) {
            return $simple;
        }
        $statements = $reflection->methodNode($method)?->stmts ?? [];
        if (
            count($statements) !== 3
            || ! $statements[0] instanceof Node\Stmt\Expression
            || ! $statements[0]->expr instanceof Node\Expr\Assign
            || ! $statements[0]->expr->var instanceof Node\Expr\Variable
            || $statements[0]->expr->var->name !== 'user'
            || ! $statements[0]->expr->expr instanceof Node\Expr\MethodCall
            || ! $statements[0]->expr->expr->var instanceof Node\Expr\Variable
            || $statements[0]->expr->expr->var->name !== 'this'
            || ! $statements[0]->expr->expr->name instanceof Node\Identifier
            || strcasecmp($statements[0]->expr->expr->name->toString(), 'user') !== 0
            || $statements[0]->expr->expr->args !== []
            || ! $statements[1] instanceof Node\Stmt\If_
            || $statements[1]->elseifs !== []
            || $statements[1]->else !== null
            || count($statements[1]->stmts) !== 1
            || ! $statements[1]->stmts[0] instanceof Node\Stmt\Expression
            || ! $statements[1]->stmts[0]->expr instanceof Node\Expr\Throw_
            || ! self::throwsForMissingUser($statements[1]->cond)
            || ! $statements[2] instanceof Node\Stmt\Return_
            || ! $statements[2]->expr instanceof Node\Expr\Array_
        ) {
            return null;
        }
        $user = $codebase->getMethod($class, 'user') ?? $codebase->getDeclaringMethod($class, 'user');
        if (strcasecmp($user?->identifier->class ?? '', 'Illuminate\\Http\\Request') !== 0) {
            return null;
        }

        return $statements[2]->expr;
    }

    private static function throwsForMissingUser(Node\Expr $condition): bool
    {
        if (
            $condition instanceof Node\Expr\BooleanNot
            && $condition->expr instanceof Node\Expr\Instanceof_
            && $condition->expr->expr instanceof Node\Expr\Variable
            && $condition->expr->expr->name === 'user'
        ) {
            return true;
        }
        if (! $condition instanceof Node\Expr\BinaryOp\Identical) {
            return false;
        }

        return self::userVariable($condition->left) && self::nullLiteral($condition->right)
            || self::nullLiteral($condition->left) && self::userVariable($condition->right);
    }

    private static function userVariable(Node\Expr $expression): bool
    {
        return $expression instanceof Node\Expr\Variable && $expression->name === 'user';
    }

    private static function nullLiteral(Node\Expr $expression): bool
    {
        return $expression instanceof Node\Expr\ConstFetch
            && strcasecmp($expression->name->toString(), 'null') === 0;
    }

    private static function hasNativeValidation(string $class, Codebase $codebase, PhpSource $source): bool
    {
        // These hooks can replace rules, validators, or validated data. Their
        // presence prevents assuming that rules() describes the returned values.
        foreach ([
            'getValidatorInstance',
            'createDefaultValidator',
            'validator',
            'validationRules',
            'withValidator',
            'after',
            'prepareForValidation',
            'passedValidation',
            'validationData',
            'setValidator',
            'validateResolved',
        ] as $name) {
            $method = $codebase->getMethod($class, $name) ?? $codebase->getDeclaringMethod($class, $name);
            $owner = strtolower($method?->identifier->class ?? '');
            if (
                $method !== null
                && ! in_array(
                    $owner,
                    [
                        'illuminate\\foundation\\http\\formrequest',
                        'illuminate\\validation\\validateswhenresolvedtrait',
                    ],
                    true,
                )
                && ($name !== 'after' || ! self::onlyAddsValidationErrors($class, $method, $codebase, $source))
            ) {
                return false;
            }
        }

        return true;
    }

    private static function onlyAddsValidationErrors(
        string $class,
        FunctionLikeMetadata $method,
        Codebase $codebase,
        PhpSource $source,
    ): bool {
        $returned = (new ModelReflection($codebase, $source))->returnExpression($method);
        if (! $returned instanceof Node\Expr\Array_ || count($returned->items) !== 1) {
            return false;
        }
        $item = $returned->items[0];
        $callback = $item->value;
        if (
            $item->key !== null
            || $item->unpack
            || $item->byRef
            || ! $callback instanceof Node\Expr\Closure
            || $callback->static
            || $callback->byRef
            || $callback->uses !== []
            || count($callback->params) !== 1
            || count($callback->stmts) !== 1
            || ! $callback->params[0]->var instanceof Node\Expr\Variable
            || $callback->params[0]->var->name !== 'validator'
            || $callback->params[0]->byRef
            || $callback->params[0]->variadic
            || $callback->params[0]->default !== null
            || ! $callback->params[0]->type instanceof Node\Name
            || strcasecmp(
                ($callback->params[0]->type->getAttribute('resolvedName') instanceof Node\Name
                    ? $callback->params[0]->type->getAttribute('resolvedName')
                    : $callback->params[0]->type)->toString(),
                'Illuminate\\Validation\\Validator',
            ) !== 0
            || ! $callback->returnType instanceof Node\Identifier
            || strcasecmp($callback->returnType->toString(), 'void') !== 0
            || ! $callback->stmts[0] instanceof Node\Stmt\If_
        ) {
            return false;
        }
        $if = $callback->stmts[0];
        if (
            $if->else !== null
            || $if->elseifs !== []
            || count($if->stmts) !== 1
            || ! self::nativeExistsCondition($if->cond, $class, $codebase)
            || ! $if->stmts[0] instanceof Node\Stmt\Expression
        ) {
            return false;
        }
        $add = $if->stmts[0]->expr;
        if (
            ! $add instanceof Node\Expr\MethodCall
            || ! $add->name instanceof Node\Identifier
            || strcasecmp($add->name->toString(), 'add') !== 0
            || count($add->args) !== 2
            || ! self::literalStringArgument($add->args[0])
            || ! self::literalStringArgument($add->args[1])
            || ! $add->var instanceof Node\Expr\MethodCall
            || ! $add->var->name instanceof Node\Identifier
            || strcasecmp($add->var->name->toString(), 'errors') !== 0
            || $add->var->args !== []
            || ! $add->var->var instanceof Node\Expr\Variable
            || $add->var->var->name !== 'validator'
        ) {
            return false;
        }

        return true;
    }

    private static function nativeExistsCondition(Node\Expr $condition, string $class, Codebase $codebase): bool
    {
        if ($condition instanceof Node\Expr\BinaryOp\BooleanAnd) {
            return self::nativeExistsCondition($condition->left, $class, $codebase)
                && self::nativeExistsCondition($condition->right, $class, $codebase);
        }
        if (
            ! $condition instanceof Node\Expr\MethodCall
            || ! $condition->var instanceof Node\Expr\Variable
            || $condition->var->name !== 'this'
            || ! $condition->name instanceof Node\Identifier
            || strcasecmp($condition->name->toString(), 'exists') !== 0
            || count($condition->args) !== 1
            || ! self::literalStringArgument($condition->args[0])
        ) {
            return false;
        }
        // exists() delegates through has(), all(), input() and the native
        // request readers. A subclass override anywhere in that path can
        // mutate the validator while evaluating the condition.
        foreach ([
            'exists', 'has', 'all', 'input', 'allFiles', 'convertUploadedFiles',
            'getInputSource', 'isJson', 'json', 'getRealMethod', 'getContent',
        ] as $name) {
            $method = $codebase->getMethod($class, $name) ?? $codebase->getDeclaringMethod($class, $name);
            $owner = strtolower($method?->identifier->class ?? '');
            if (! in_array($owner, match ($name) {
                'exists', 'has' => ['illuminate\\http\\request', 'illuminate\\support\\traits\\interactswithdata'],
                'all', 'input', 'allFiles', 'convertUploadedFiles' => [
                    'illuminate\\http\\request',
                    'illuminate\\http\\concerns\\interactswithinput',
                ],
                'isJson' => ['illuminate\\http\\request', 'illuminate\\http\\concerns\\interactswithcontenttypes'],
                'getRealMethod', 'getContent' => [
                    'illuminate\\http\\request',
                    'symfony\\component\\httpfoundation\\request',
                ],
                default => ['illuminate\\http\\request'],
            }, true)) {
                return false;
            }
        }

        return true;
    }

    private static function literalStringArgument(Node\Arg|Node\VariadicPlaceholder $argument): bool
    {
        return $argument instanceof Node\Arg
            && $argument->name === null
            && ! $argument->unpack
            && $argument->value instanceof Node\Scalar\String_;
    }

    /**
     * @param array<string, list<string>> $paths
     * @return array<string, ArrayItem>|null
     */
    private static function level(array $paths, string $prefix = ''): ?array
    {
        $fields = [];
        foreach ($paths as $path => $rules) {
            if (! str_starts_with($path, $prefix) || str_contains(substr($path, strlen($prefix)), '.')) {
                continue;
            }
            $key = substr($path, strlen($prefix));
            $required = in_array('required', $rules, true);
            $present = $required || in_array('present', $rules, true);
            $optional = ! $present || in_array('sometimes', $rules, true);
            $nonBlank = $required || in_array('filled', $rules, true);
            $type = self::valueType($rules);
            $children = self::level($paths, $path.'.');
            if ($children === null) {
                return null;
            }
            if ($children !== []) {
                if (isset($children['*'])) {
                    // Wildcards accept string and integer keys and empty
                    // results. They never establish list or tuple cardinality.
                    $type = Type::array(Type::union(Type::int(), Type::string()), $children['*']->type);
                    $optional = true;
                } else {
                    $type = self::shape($children);
                    $hasRequired = false;
                    foreach ($children as $child) {
                        $hasRequired = $hasRequired || ! $child->optional;
                    }
                    // Validator::validated can omit an array parent entirely
                    // when none of its child attributes were included.
                    $optional = $optional || ! $hasRequired;
                }
            }
            if (! $nonBlank) {
                // Laravel skips ordinary validators for whitespace-only strings.
                // There is no SDK whitespace-string type, so retain string.
                $type = Type::union($type, Type::string());
            }
            if (! $nonBlank && in_array('nullable', $rules, true)) {
                $type = Type::union($type, Type::null());
            }
            $fields[$key] = new ArrayItem(new ArrayKey(ArrayKeyKind::String, $key), $optional, $type);
        }

        // Overlapping wildcard/literal rules need intersection semantics.
        return isset($fields['*']) && count($fields) > 1 ? null : $fields;
    }

    /** @param array<string, ArrayItem> $fields */
    public static function select(array $fields, string $path): ?ArrayItem
    {
        $optional = false;
        $segments = explode('.', $path);
        foreach ($segments as $index => $segment) {
            $field = $fields[$segment] ?? null;
            if ($field === null || $segment === '*') {
                return null;
            }
            $optional = $optional || $field->optional;
            if ($index === (count($segments) - 1)) {
                break;
            }
            $fields = [];
            foreach ($field->type->atomicTypes as $atomic) {
                if (! $atomic instanceof KeyedArrayType) {
                    $optional = true;
                    continue;
                }
                foreach ($atomic->knownItems ?? [] as $child) {
                    $fields[(string) $child->key->value] = $child;
                }
            }
        }

        return new ArrayItem($field->key, $optional, $field->type);
    }

    /** @param array<string, ArrayItem> $fields */
    public static function shape(array $fields): Type
    {
        $nonEmpty = false;
        foreach ($fields as $field) {
            $nonEmpty = $nonEmpty || ! $field->optional;
        }

        // Unknown keys stay mixed; literal rules are not a closed-world contract.
        return Type::fromAtomic(
            new KeyedArrayType(
                array_values($fields),
                Type::string(),
                Type::mixed(),
                $nonEmpty,
            ),
        );
    }

    /** @return list<string>|null */
    private static function rules(Node\Expr $expression, Codebase $codebase, PhpSource $source): ?array
    {
        $value = PhpSource::value($expression);
        if (is_string($value)) {
            $rules = explode('|', $value);
        } elseif ($expression instanceof Node\Expr\Array_) {
            $rules = [];
            foreach ($expression->items as $item) {
                if ($item->unpack || $item->byRef || $item->key !== null) {
                    return null;
                }
                $rule = PhpSource::value($item->value);
                if (! is_string($rule)) {
                    $rule = self::literalRuleObject($item->value, $codebase, $source);
                }
                if ($rule === null) {
                    return null;
                }
                $rules[] = $rule;
            }
        } else {
            return null;
        }
        $accepted = [];
        foreach ($rules as $rule) {
            if (
                ! in_array(
                    $rule,
                    [
                        'required',
                        'present',
                        'sometimes',
                        'nullable',
                        'filled',
                        'bail',
                        'string',
                        'array',
                        'boolean',
                        'numeric',
                        'integer',
                        'email',
                        'url',
                        'uuid',
                        'ulid',
                        'alpha',
                        'alpha:ascii',
                        'alpha_num',
                        'alpha_num:ascii',
                        'alpha_dash',
                        'alpha_dash:ascii',
                        'date',
                        'json',
                        'ip',
                        'ipv4',
                        'ipv6',
                        'distinct',
                        'distinct:strict',
                    ],
                    true,
                )
                && ! preg_match('/^(?:min|max|size):[0-9]+(?:\.[0-9]+)?$/D', $rule)
                && ! preg_match('/^(?:date_format|in):.+$/D', $rule)
            ) {
                return null;
            }
            $accepted[] = $rule;
        }

        return $accepted;
    }

    private static function literalRuleObject(Node\Expr $expression, Codebase $codebase, PhpSource $source): ?string
    {
        // These exact native factories return Stringable rule builders whose
        // initial constraints are string/numeric. No chained calls, macros,
        // dynamic arguments, or application rule objects are evaluated.
        if (
            ! $expression instanceof Node\Expr\StaticCall
            || ! $expression->class instanceof Node\Name
            || strcasecmp($expression->class->toString(), 'Illuminate\\Validation\\Rule') !== 0
            || ! $expression->name instanceof Node\Identifier
        ) {
            return null;
        }
        $factory = strtolower($expression->name->toString());
        if ($expression->args === []) {
            return in_array($factory, ['string', 'numeric'], true)
            && self::nativeStringableRule($factory, $codebase, $source)
                ? $factory
                : null;
        }
        if ($factory !== 'in' || count($expression->args) !== 1) {
            return null;
        }

        // Read the known factory's literal arguments; never invoke the factory
        // or evaluate dynamic rule objects, constants, or application methods.
        $values = PhpSource::value(PhpSource::argument($expression->args, 0, 'values'));
        if (! is_array($values) || ! array_is_list($values) || $values === []) {
            return null;
        }
        $quoted = [];
        foreach ($values as $value) {
            if (! is_string($value) && ! is_int($value)) {
                return null;
            }
            $quoted[] = '"'.str_replace('"', '""', (string) $value).'"';
        }

        return 'in:'.implode(',', $quoted);
    }

    private static function nativeStringableRule(string $name, Codebase $codebase, PhpSource $source): bool
    {
        $factory = $codebase->getMethod('Illuminate\\Validation\\Rule', $name);
        $builder = 'Illuminate\\Validation\\Rules\\'.($name === 'string' ? 'StringRule' : 'Numeric');
        $stringify = $codebase->getMethod($builder, '__toString');
        if (
            $factory === null
            || strcasecmp($factory->identifier->class ?? '', 'Illuminate\\Validation\\Rule') !== 0
            || ! $factory->static
            || $factory->visibility !== Visibility::Public
            || $factory->flags->contains(MetadataFlags::BY_REFERENCE)
            || $stringify === null
            || strcasecmp($stringify->identifier->class ?? '', $builder) !== 0
            || $stringify->static
            || $stringify->visibility !== Visibility::Public
            || $stringify->flags->contains(MetadataFlags::BY_REFERENCE)
            || $codebase->getFunction('Illuminate\\Validation\\Rules\\implode') !== null
            || $codebase->getFunction('Illuminate\\Validation\\Rules\\array_unique') !== null
        ) {
            return false;
        }
        $reflection = new ModelReflection($codebase, $source);
        $factoryNode = $reflection->methodNode($factory);
        $stringifyNode = $reflection->methodNode($stringify);
        $created = $reflection->returnExpression($factory);
        $rendered = $reflection->returnExpression($stringify);
        if (
            $factoryNode === null
            || $factoryNode->params !== []
            || $factoryNode->byRef
            || $stringifyNode === null
            || $stringifyNode->params !== []
            || $stringifyNode->byRef
            || ! $created instanceof Node\Expr\New_
            || ! $created->class instanceof Node\Name
            || strcasecmp($created->class->toString(), $builder) !== 0
            || $created->args !== []
            || $codebase->getMethod($builder, '__construct') !== null
            || $codebase->getDeclaringMethod($builder, '__construct') !== null
            || ! self::initialRuleConstraint($builder, $name, $stringify->location->file, $source)
            || ! $rendered instanceof Node\Expr\FuncCall
            || ! $rendered->name instanceof Node\Name
            || strcasecmp($rendered->name->toString(), 'implode') !== 0
            || count($rendered->args) !== 2
            || ! $rendered->args[0] instanceof Node\Arg
            || $rendered->args[0]->name !== null
            || $rendered->args[0]->unpack
            || $rendered->args[0]->byRef
            || PhpSource::value($rendered->args[0]->value) !== '|'
            || ! $rendered->args[1] instanceof Node\Arg
            || $rendered->args[1]->name !== null
            || $rendered->args[1]->unpack
            || $rendered->args[1]->byRef
            || ! $rendered->args[1]->value instanceof Node\Expr\FuncCall
        ) {
            return false;
        }
        $unique = $rendered->args[1]->value;

        return (
            $unique->name instanceof Node\Name
            && strcasecmp($unique->name->toString(), 'array_unique') === 0
            && count($unique->args) === 1
            && $unique->args[0] instanceof Node\Arg
            && $unique->args[0]->name === null
            && ! $unique->args[0]->unpack
            && ! $unique->args[0]->byRef
            && $unique->args[0]->value instanceof Node\Expr\PropertyFetch
            && $unique->args[0]->value->var instanceof Node\Expr\Variable
            && $unique->args[0]->value->var->name === 'this'
            && $unique->args[0]->value->name instanceof Node\Identifier
            && $unique->args[0]->value->name->toString() === 'constraints'
        );
    }

    private static function initialRuleConstraint(string $builder, string $rule, ?string $file, PhpSource $source): bool
    {
        if ($file === null) {
            return false;
        }
        $separator = strrpos($builder, '\\');
        if ($separator === false) {
            return false;
        }
        $namespace = substr($builder, 0, $separator);
        $shortName = substr($builder, $separator + 1);
        foreach ($source->read($file) ?? [] as $scope) {
            if (
                ! $scope instanceof Node\Stmt\Namespace_
                || $scope->name === null
                || strcasecmp($scope->name->toString(), $namespace) !== 0
            ) {
                continue;
            }
            foreach ($scope->stmts as $class) {
                if (
                    ! $class instanceof Node\Stmt\Class_
                    || strcasecmp($class->name?->toString() ?? '', $shortName) !== 0
                    || $class->extends !== null
                ) {
                    continue;
                }
                foreach ($class->implements as $interface) {
                    if (strcasecmp($interface->toString(), 'Stringable') !== 0) {
                        // Laravel handles validation contracts before Stringable.
                        return false;
                    }
                }
                foreach ($class->getProperties() as $property) {
                    foreach ($property->props as $declaration) {
                        if ($declaration->name->toString() === 'constraints') {
                            return (
                                ! $property->isStatic()
                                && $property->hooks === []
                                && PhpSource::value($declaration->default) === [$rule]
                            );
                        }
                    }
                }
            }
        }

        return false;
    }

    /** @param list<string> $rules */
    private static function valueType(array $rules): Type
    {
        // Multiple validators intersect at runtime. Picking one proven constraint
        // is safe; no validator is treated as a cast.
        foreach ($rules as $rule) {
            $type = match ($rule) {
                'string', 'url', 'uuid', 'ulid', 'alpha', 'alpha:ascii', 'date_format:Y-m-d' => Type::string(),
                'array' => Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
                'numeric', 'alpha_num', 'alpha_num:ascii', 'alpha_dash', 'alpha_dash:ascii' => Type::union(
                    Type::int(),
                    Type::float(),
                    Type::string(),
                ),
                'boolean' => Type::union(
                    Type::bool(),
                    Type::literalInt(0),
                    Type::literalInt(1),
                    Type::literalString('0'),
                    Type::literalString('1'),
                ),
                default => null,
            };
            if ($type !== null) {
                return $type;
            }
            if (str_starts_with($rule, 'date_format:')) {
                return Type::union(Type::string(), Type::int(), Type::float());
            }
        }

        // integer uses FILTER_VALIDATE_INT, which also accepts non-integer PHP
        // inputs. In casts to string for comparison, permitting Stringable
        // objects as well as scalar representations. Neither rule casts the
        // returned value, so without another constraint retain mixed.
        return Type::mixed();
    }
}
