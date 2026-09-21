<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;

/** Explicit dispatch contracts, never an inferred effective Gate policy registry. */
final class PolicyCallContracts
{
    /** @var array<string, string> */
    private array $policies = [];

    public function __construct(string $root)
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $options */
        $options = is_array($composer) ? $composer['extra']['laramago']['policy-call-contracts'] ?? null : null;
        if (
            ! is_array($options)
            || ($options['diagnose'] ?? null) !== true
            || ($options['native-dispatch'] ?? null) !== true
            || ($options['authenticated-user'] ?? null) !== true
            || ($options['no-intercepting-callbacks'] ?? null) !== true
            || ! is_array($options['policies'] ?? null)
        ) {
            return;
        }
        $policies = [];
        /** @var mixed $policy */
        foreach ($options['policies'] as $model => $policy) {
            if (
                ! is_string($model)
                || ! is_string($policy)
                || ! self::className($model)
                || ! self::className($policy)
            ) {
                return;
            }
            $policies[$model] = $policy;
        }
        $this->policies = $policies;
    }

    public function enabled(): bool
    {
        return $this->policies !== [];
    }

    public function policy(string $model): ?string
    {
        return $this->policies[$model] ?? null;
    }

    public function check(NodeAnalysisContext $context, string $policy, string $name, Node\Expr $target): void
    {
        // Even an explicit contract does not override visible interception or magic declarations.
        if (
            $context->codebase->getMethod($policy, 'before') !== null
            || $context->codebase->getDeclaringMethod($policy, 'before') !== null
            || $context->codebase->getMethod($policy, '__call') !== null
            || $context->codebase->getDeclaringMethod($policy, '__call') !== null
        ) {
            return;
        }
        $method = $context->codebase->getMethod($policy, $name) ?? $context->codebase->getDeclaringMethod(
            $policy,
            $name,
        );
        if (
            $method === null
            || $method->visibility !== Visibility::Public
            || $method->abstract
            || $method->flags->contains(MetadataFlags::MAGIC_METHOD)
        ) {
            return;
        }
        $arguments = [$target];
        if ($target instanceof Node\Expr\Array_) {
            $arguments = [];
            foreach ($target->items as $item) {
                if ($item->key !== null || $item->byRef || $item->unpack) {
                    return;
                }
                $arguments[] = $item->value;
            }
        }
        // Gate removes a class-string selector before invoking the policy method.
        $first = $arguments[0] ?? null;
        if ($first instanceof Node\Expr\ClassConstFetch) {
            if (
                ! $first->class instanceof Node\Name
                || ! $first->name instanceof Node\Identifier
                || strtolower($first->name->toString()) !== 'class'
                || $first->class->isSpecialClassName()
            ) {
                return;
            }
            array_shift($arguments);
        } elseif (! $first instanceof Node\Expr\New_) {
            // Variables and inferred class-string unions can select/remove a different first argument.
            return;
        }
        $parameters = array_slice($method->parameters, 1); // Gate supplies the user separately.
        foreach ($parameters as $parameter) {
            if (
                $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC)
            ) {
                return;
            }
        }
        foreach ($parameters as $index => $parameter) {
            $argument = $arguments[$index] ?? null;
            if ($argument === null) {
                if (! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)) {
                    self::report(
                        $context,
                        $target,
                        'Selected policy '
                        .$policy
                        .'::'
                        .$name
                        .' requires '
                        .$parameter->name
                        .' after Gate removes any class selector.',
                    );
                }
                continue;
            }
            $expected = $parameter->declaredType?->type;
            $actual = self::literalType($argument);
            // Deliberately only compare native object parameter types. Laravel invokes policy
            // methods weakly; scalar coercion, Stringable and PHPDoc refinements need different rules.
            if (
                $expected === null
                || $actual === null
                || ! self::objectContract($context, $expected)
            ) {
                continue;
            }
            $actualObject = $actual->atomicTypes[0];
            if ($actualObject instanceof NamedObjectType) {
                $actualClass = $context->codebase->getClass($actualObject->name);
                if ($actualClass === null || $actualClass->hasIncompleteHierarchy()) {
                    continue;
                }
            }
            if (! $context->types->isContainedBy($actual, $expected)) {
                self::report(
                    $context,
                    $argument,
                    'Selected policy '.$policy.'::'.$name.' expects '.(string) $expected.' for '.$parameter->name.'.',
                );
            }
        }
    }

    /** Scalar alternatives require Laravel's weak-coercion rules and therefore defer. */
    private static function objectContract(NodeAnalysisContext $context, Type $type): bool
    {
        $hasObject = false;
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }
            if (! $atomic instanceof NamedObjectType || $atomic->intersections !== null) {
                return false;
            }
            $class = $context->codebase->getClassLike($atomic->name);
            if ($class === null || $class->hasIncompleteHierarchy()) {
                return false;
            }
            $hasObject = true;
        }

        return $hasObject;
    }

    private static function literalType(Node\Expr $expression): ?Type
    {
        if (
            $expression instanceof Node\Expr\New_
            && $expression->class instanceof Node\Name
            && ! $expression->class->isSpecialClassName()
        ) {
            return Type::namedObject($expression->class->toString());
        }
        if ($expression instanceof Node\Scalar\String_) {
            return Type::literalString($expression->value);
        }
        if ($expression instanceof Node\Scalar\Int_) {
            return Type::literalInt($expression->value);
        }
        if ($expression instanceof Node\Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'null' => Type::null(),
                'true', 'false' => Type::bool(),
                default => null,
            };
        }

        return null;
    }

    private static function className(string $name): bool
    {
        return preg_match('~^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$~', $name) === 1;
    }

    private static function report(NodeAnalysisContext $context, Node\Expr $expression, string $message): void
    {
        $context->report(Level::Warning, 'laramago-policy-call-contract', Issue::at(
            $message.' This check relies on the explicit policy-call-contracts dispatch assumptions.',
            new SourceLocation(
                $context->source->path,
                new Span($expression->getStartFilePos(), $expression->getEndFilePos() + 1),
            ),
        ));
    }
}
