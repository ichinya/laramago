<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
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
            ) {
                return null;
            }
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
                Type::union(Type::int(), Type::string()),
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
