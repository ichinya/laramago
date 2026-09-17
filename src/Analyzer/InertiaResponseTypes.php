<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Types proven by Inertia's standard test response implementation. */
final class InertiaResponseTypes
{
    private const ASSERTABLE_INERTIA = 'Inertia\\Testing\\AssertableInertia';
    private const INERTIA_MACROS = 'Inertia\\Testing\\TestResponseMacros';

    public static function page(): Type
    {
        return Type::fromAtomic(
            new KeyedArrayType(
                [
                    new ArrayItem(new ArrayKey(ArrayKeyKind::String, 'component'), false, Type::string()),
                    new ArrayItem(new ArrayKey(ArrayKeyKind::String, 'props'), false, self::props()),
                    new ArrayItem(new ArrayKey(ArrayKeyKind::String, 'url'), false, Type::string()),
                    new ArrayItem(
                        new ArrayKey(ArrayKeyKind::String, 'version'),
                        false,
                        Type::union(Type::string(), Type::null()),
                    ),
                    new ArrayItem(
                        new ArrayKey(ArrayKeyKind::String, 'flash'),
                        false,
                        Type::array(Type::string(), Type::mixed()),
                    ),
                    new ArrayItem(new ArrayKey(ArrayKeyKind::String, 'encryptHistory'), true, Type::true()),
                    new ArrayItem(new ArrayKey(ArrayKeyKind::String, 'clearHistory'), true, Type::true()),
                ],
                null,
                null,
                true,
            ),
        );
    }

    public static function props(): Type
    {
        return Type::array(Type::union(Type::int(), Type::string()), Type::mixed());
    }

    public static function supportsPage(Codebase $codebase, PhpSource $source): bool
    {
        $toArray = self::method($codebase, self::ASSERTABLE_INERTIA, 'toArray');
        $factory = self::method($codebase, self::INERTIA_MACROS, 'inertiaPage');
        if (
            $toArray === null
            || $toArray->static
            || $toArray->parameters !== []
            || strcasecmp($toArray->identifier->class ?? '', self::ASSERTABLE_INERTIA) !== 0
            || ! self::stringMixedArray($toArray->returnType?->type ?? $toArray->declaredReturnType?->type)
            || $factory === null
            || strcasecmp($factory->identifier->class ?? '', self::INERTIA_MACROS) !== 0
            || ! self::closureType($factory->returnType?->type ?? $factory->declaredReturnType?->type)
            || ! self::propertyContracts($codebase)
        ) {
            return false;
        }

        return (
            self::pageBody(self::methodNode($toArray, $source))
            && self::pageFactoryBody(self::methodNode($factory, $source))
        );
    }

    public static function forwardsPage(FunctionLikeMetadata $method, PhpSource $source): bool
    {
        $node = self::methodNode($method, $source);
        if ($node === null || $node->stmts === null || count($node->stmts) !== 1) {
            return false;
        }
        $statement = $node->stmts[0];
        $call = $statement instanceof Node\Stmt\Return_ ? $statement->expr : null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            || ! self::thisVariable($call->var)
            || ! $call->name instanceof Node\Identifier
            || strcasecmp($call->name->toString(), 'frameworkAssertion') !== 0
            || count($call->args) !== 2
        ) {
            return false;
        }
        [$methodArgument, $argumentsArgument] = $call->args;
        if (
            ! $methodArgument instanceof Node\Arg
            || ! $methodArgument->value instanceof Node\Scalar\String_
            || strcasecmp($methodArgument->value->value, 'inertiaPage') !== 0
            || ! $argumentsArgument instanceof Node\Arg
            || ! $argumentsArgument->value instanceof Node\Expr\Array_
        ) {
            return false;
        }
        $arguments = $argumentsArgument->value->items;
        if (count($arguments) !== 1) {
            return false;
        }
        $argument = $arguments[0];

        return (
            $argument->key === null
            && ! $argument->unpack
            && ! $argument->byRef
            && $argument->value instanceof Node\Expr\Variable
            && $argument->value->name === 'key'
        );
    }

    private static function propertyContracts(Codebase $codebase): bool
    {
        foreach ([
            'component' => ['string'],
            'url' => ['string'],
            'version' => ['null|string', 'string|null'],
            'encryptHistory' => ['bool'],
            'clearHistory' => ['bool'],
        ] as $name => $expected) {
            $property = $codebase->getDeclaringProperty(self::ASSERTABLE_INERTIA, '$'.$name) ?? $codebase->getProperty(
                self::ASSERTABLE_INERTIA,
                '$'.$name,
            );
            $type = $property?->type?->type ?? $property?->declaredType?->type;
            if ($type === null || ! in_array((string) $type, $expected, true)) {
                return false;
            }
        }

        $flash = $codebase->getDeclaringProperty(self::ASSERTABLE_INERTIA, '$flash') ?? $codebase->getProperty(
            self::ASSERTABLE_INERTIA,
            '$flash',
        );
        $flashType = $flash?->type?->type ?? $flash?->declaredType?->type;
        if (! self::stringMixedArray($flashType)) {
            return false;
        }

        return true;
    }

    private static function stringMixedArray(?Type $type): bool
    {
        if ($type === null || count($type->atomicTypes) !== 1) {
            return false;
        }
        $array = $type->atomicTypes[0];
        if (
            ! $array instanceof KeyedArrayType
            || $array->knownItems !== null
            || (string) $array->keyType !== 'string'
            || (string) $array->valueType !== 'mixed'
        ) {
            return false;
        }

        return true;
    }

    private static function closureType(?Type $type): bool
    {
        if ($type === null || count($type->atomicTypes) !== 1) {
            return false;
        }
        $callable = $type->atomicTypes[0];

        return $callable instanceof CallableType && ($callable->signature?->closure ?? false);
    }

    private static function pageBody(?Node\Stmt\ClassMethod $method): bool
    {
        if ($method === null || $method->stmts === null || count($method->stmts) !== 1) {
            return false;
        }
        $statement = $method->stmts[0];
        $merge = $statement instanceof Node\Stmt\Return_ ? $statement->expr : null;
        if (
            ! $merge instanceof Node\Expr\FuncCall
            || ! $merge->name instanceof Node\Name
            || strcasecmp($merge->name->toString(), 'array_merge') !== 0
            || count($merge->args) !== 3
        ) {
            return false;
        }
        [$base, $encryptHistory, $clearHistory] = $merge->args;
        if (
            ! $base instanceof Node\Arg
            || $base->unpack
            || ! $encryptHistory instanceof Node\Arg
            || $encryptHistory->unpack
            || ! $clearHistory instanceof Node\Arg
            || $clearHistory->unpack
        ) {
            return false;
        }

        return (
            self::basePage($base->value)
            && self::optionalTrue($encryptHistory->value, 'encryptHistory')
            && self::optionalTrue($clearHistory->value, 'clearHistory')
        );
    }

    private static function basePage(Node\Expr $expression): bool
    {
        if (! $expression instanceof Node\Expr\Array_ || count($expression->items) !== 5) {
            return false;
        }
        $expected = [
            'component' => 'component',
            'props' => null,
            'url' => 'url',
            'version' => 'version',
            'flash' => 'flash',
        ];
        foreach (array_values($expression->items) as $position => $item) {
            $key = array_keys($expected)[$position];
            if (
                ! $item->key instanceof Node\Scalar\String_
                || $item->key->value !== $key
                || $item->unpack
                || $item->byRef
            ) {
                return false;
            }
            $property = $expected[$key];
            if ($property === null) {
                if (! self::propsCall($item->value)) {
                    return false;
                }
            } elseif (! self::propertyFetch($item->value, $property)) {
                return false;
            }
        }

        return true;
    }

    private static function optionalTrue(Node\Expr $expression, string $property): bool
    {
        if (
            ! $expression instanceof Node\Expr\Ternary
            || ! self::propertyFetch($expression->cond, $property)
            || ! $expression->if instanceof Node\Expr\Array_
            || ! $expression->else instanceof Node\Expr\Array_
            || count($expression->if->items) !== 1
            || $expression->else->items !== []
        ) {
            return false;
        }
        $item = $expression->if->items[0];

        return (
            $item->key instanceof Node\Scalar\String_
            && $item->key->value === $property
            && ! $item->unpack
            && ! $item->byRef
            && $item->value instanceof Node\Expr\ConstFetch
            && strcasecmp($item->value->name->toString(), 'true') === 0
        );
    }

    private static function pageFactoryBody(?Node\Stmt\ClassMethod $method): bool
    {
        if ($method === null || $method->stmts === null || count($method->stmts) !== 1) {
            return false;
        }
        $statement = $method->stmts[0];
        $closure = $statement instanceof Node\Stmt\Return_ ? $statement->expr : null;
        if (
            ! $closure instanceof Node\Expr\Closure
            || $closure->params !== []
            || count($closure->stmts) !== 1
            || ! $closure->stmts[0] instanceof Node\Stmt\Return_
        ) {
            return false;
        }
        $toArray = $closure->stmts[0]->expr;
        if (
            ! $toArray instanceof Node\Expr\MethodCall
            || ! $toArray->name instanceof Node\Identifier
            || strcasecmp($toArray->name->toString(), 'toArray') !== 0
            || $toArray->args !== []
            || ! $toArray->var instanceof Node\Expr\StaticCall
        ) {
            return false;
        }
        $fromResponse = $toArray->var;
        if (
            ! $fromResponse->class instanceof Node\Name
            || strcasecmp($fromResponse->class->toString(), self::ASSERTABLE_INERTIA) !== 0
            || ! $fromResponse->name instanceof Node\Identifier
            || strcasecmp($fromResponse->name->toString(), 'fromTestResponse') !== 0
            || count($fromResponse->args) !== 1
        ) {
            return false;
        }
        $response = $fromResponse->args[0];

        return $response instanceof Node\Arg && self::thisVariable($response->value);
    }

    private static function propsCall(Node\Expr $expression): bool
    {
        return (
            $expression instanceof Node\Expr\MethodCall
            && self::thisVariable($expression->var)
            && $expression->name instanceof Node\Identifier
            && strcasecmp($expression->name->toString(), 'prop') === 0
            && $expression->args === []
        );
    }

    private static function propertyFetch(Node\Expr $expression, string $property): bool
    {
        return (
            $expression instanceof Node\Expr\PropertyFetch
            && self::thisVariable($expression->var)
            && $expression->name instanceof Node\Identifier
            && $expression->name->toString() === $property
        );
    }

    private static function thisVariable(Node\Expr $expression): bool
    {
        return $expression instanceof Node\Expr\Variable && $expression->name === 'this';
    }

    private static function methodNode(FunctionLikeMetadata $method, PhpSource $source): ?Node\Stmt\ClassMethod
    {
        $file = $method->location->file;
        if ($file === null || str_starts_with($file, '@')) {
            return null;
        }
        foreach ((new NodeFinder)->findInstanceOf($source->read($file) ?? [], Node\Stmt\ClassMethod::class) as $node) {
            if (
                strcasecmp($node->name->toString(), $method->originalName) === 0
                && $node->getStartFilePos() >= $method->location->span->start
                && $node->getStartFilePos() < $method->location->span->end
            ) {
                return $node;
            }
        }

        return null;
    }

    private static function method(Codebase $codebase, string $class, string $method): ?FunctionLikeMetadata
    {
        return $codebase->getMethod($class, $method) ?? $codebase->getDeclaringMethod($class, $method);
    }
}
