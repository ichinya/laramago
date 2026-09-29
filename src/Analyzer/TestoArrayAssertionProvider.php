<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodAssertionProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\MixedTruthiness;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Preserve PHP array keys after Testo's verified array assertion. */
final class TestoArrayAssertionProvider implements MethodAssertionProvider, InitializationHook
{
    private const ASSERT = 'Testo\\Assert';
    private const DELEGATE = 'Testo\\Assert\\Internal\\Assertion\\AssertArray';
    private const STATE = 'Testo\\Assert\\Internal\\StaticState';

    private ?PhpSource $source = null;
    private ?bool $verified = null;

    public function __construct(private readonly string $root = '.') {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->verified = null;
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::ASSERT, 'array')];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $call = $context->invocation;
        $actual = $call->getArgument(0, 'actual');
        if ($call->kind !== InvocationKind::StaticMethod || strcasecmp($call->name, 'array') !== 0
            || strcasecmp($call->declaringClass ?? '', self::ASSERT) !== 0 || count($call->arguments) !== 1
            || $actual === null || $actual->unpacked || $actual->placeholder || ! self::local($actual->expression)
            || ! ($this->verified ??= $this->verify($context))) {
            return null;
        }

        // Atomic array-key takes Mago's generic array reconciliation path and keeps
        // existing mixed keys. The explicit union intersects those keys precisely.
        return new InvocationAssertions(assertions: [
            '$actual' => [new TypeAssertion(TypeAssertionKind::IsType, Type::array(
                Type::union(Type::int(), Type::string()), Type::mixed(),
            ))],
        ]);
    }

    private function verify(AssertionProviderContext $context): bool
    {
        $methods = [];
        $reflection = new ModelReflection($context->codebase, $this->source ??= new PhpSource($this->root));
        foreach ([
            [self::ASSERT, 'array', '/testo/assert/assert.php'],
            [self::DELEGATE, 'validateAndCreate', '/testo/assert/src/internal/assertion/assertarray.php'],
            [self::STATE, 'typeFail', '/testo/assert/src/internal/staticstate.php'],
        ] as [$owner, $name, $suffix]) {
            $class = $context->codebase->getClass($owner);
            $method = $context->codebase->getDeclaringMethod($owner, $name);
            if ($class === null || ! $class->flags->contains(MetadataFlags::FINAL) || $class->hasIncompleteHierarchy()
                || $class->directParentClass !== null || $class->pseudoMethods !== [] || $class->staticPseudoMethods !== []
                || $method === null || strcasecmp($method->identifier->class ?? '', $owner) !== 0
                || ! $method->static || $method->visibility !== Visibility::Public || $method->abstract
                || $method->flags->contains(MetadataFlags::BY_REFERENCE) || $method->templates !== []
                || ! str_ends_with(strtolower(str_replace('\\', '/', $method->location->file ?? '')), $suffix)) {
                return false;
            }
            $node = $reflection->methodNode($method);
            if ($node === null || ! $node->isStatic() || ! $node->isPublic() || $node->byRef || $node->stmts === null) {
                return false;
            }
            $methods[$name] = [$method, $node];
        }
        [$assert, $assertNode] = $methods['array'];
        [$delegate, $delegateNode] = $methods['validateAndCreate'];
        [$fail, $failNode] = $methods['typeFail'];
        if (! self::mixedInput($assert, $assertNode, 'actual') || ! self::mixedInput($delegate, $delegateNode, 'value')
            || ! self::standardAssertion($assert) || ! self::failure($fail, $failNode)
            || ! $assertNode->returnType instanceof Node\Name
            || strcasecmp($assertNode->returnType->toString(), 'Testo\\Assert\\Api\\Builtin\\ArrayType') !== 0
            || ! $delegateNode->returnType instanceof Node\Name || strtolower($delegateNode->returnType->toString()) !== 'self'
            || ! ($context->codebase->getFunction('is_array')?->flags->contains(MetadataFlags::BUILTIN) ?? false)) {
            return false;
        }
        $forward = count($assertNode->stmts) === 1 ? $assertNode->stmts[0] : null;
        if (! $forward instanceof Node\Stmt\Return_
            || ! self::staticCall($forward->expr, self::DELEGATE, 'validateAndCreate', ['actual'])) {
            return false;
        }

        // The native delegate tests the unchanged, by-value argument before doing
        // any work. A failed test calls a verified never-returning native method.
        if (count($delegateNode->stmts) !== 3) {
            return false;
        }
        [$check, $success, $result] = $delegateNode->stmts;
        $guard = $check instanceof Node\Stmt\Expression ? $check->expr : null;
        if (! $guard instanceof Node\Expr\BinaryOp\LogicalOr || ! $guard->left instanceof Node\Expr\FuncCall
            || ! $guard->left->name instanceof Node\Name\FullyQualified
            || strcasecmp($guard->left->name->toString(), 'is_array') !== 0
            || ! self::arguments($guard->left->args, ['value'])
            || ! self::staticCall($guard->right, self::STATE, 'typeFail', ['@array', 'value'])) {
            return false;
        }
        $assignment = $success instanceof Node\Stmt\Expression ? $success->expr : null;
        if (! $assignment instanceof Node\Expr\Assign || ! self::variable($assignment->var, 'parent')
            || ! self::staticCall($assignment->expr, self::STATE, 'typeSuccess', ['@array', 'value'])
            || ! $result instanceof Node\Stmt\Return_ || ! $result->expr instanceof Node\Expr\New_
            || ! $result->expr->class instanceof Node\Name || strtolower($result->expr->class->toString()) !== 'self'
            || ! self::arguments($result->expr->args, ['value', 'parent'])) {
            return false;
        }
        return true;
    }

    private static function mixedInput(FunctionLikeMetadata $method, Node\Stmt\ClassMethod $node, string $name): bool
    {
        $parameter = count($method->parameters) === 1 ? $method->parameters[0] : null;
        $syntax = count($node->params) === 1 ? $node->params[0] : null;
        return $parameter !== null && $parameter->name === '$'.$name
            && self::plainMixed($parameter->declaredType?->type) && self::plainMixed($parameter->type?->type)
            && $parameter->outType === null && $parameter->defaultType === null
            && ! $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
            && ! $parameter->flags->contains(MetadataFlags::VARIADIC)
            && $syntax !== null && ! $syntax->byRef && ! $syntax->variadic && $syntax->default === null
            && $syntax->type instanceof Node\Identifier && strtolower($syntax->type->toString()) === 'mixed'
            && self::variable($syntax->var, $name);
    }

    /** Defer explicitly stronger or otherwise customized assertion contracts. */
    private static function standardAssertion(FunctionLikeMetadata $method): bool
    {
        if ($method->assertionsInferred || array_keys($method->assertions) !== ['$actual']
            || count($method->assertions['$actual']) < 1 || count($method->assertions['$actual']) > 2
            || $method->ifTrueAssertions !== [] || $method->ifFalseAssertions !== []) {
            return false;
        }
        $seen = [];
        foreach ($method->assertions['$actual'] as $assertion) {
            if (! $assertion instanceof TypeAssertion || $assertion->kind !== TypeAssertionKind::IsType
                || count($assertion->type->atomicTypes) !== 1) {
                return false;
            }
            $array = $assertion->type->atomicTypes[0];
            if (! $array instanceof KeyedArrayType || $array->nonEmpty || ($array->knownItems ?? []) !== []
                || $array->keyType === null || ! self::plainMixed($array->valueType)) {
                return false;
            }
            $key = count($array->keyType->atomicTypes) === 1 ? $array->keyType->atomicTypes[0] : null;
            $kind = self::plainMixed($array->keyType) ? 'mixed' : (
                $key instanceof ScalarType && $key->kind === ScalarTypeKind::ArrayKey && $key->refinement === null ? 'array-key' : null
            );
            if ($kind === null || isset($seen[$kind])) {
                return false;
            }
            $seen[$kind] = true;
        }
        // Testo publishes both Psalm's bare array and PHPStan's mixed-key array.
        // Mago keeps both facts; only this complementary pair or the single
        // mixed-key fact needs normalization. Stronger additional facts defer.
        return isset($seen['mixed']);
    }

    private static function plainMixed(?Type $type): bool
    {
        $atom = $type !== null && count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;
        return $atom instanceof MixedType && ! $atom->nonNull && ! $atom->empty && ! $atom->issetFromLoop
            && $atom->truthiness === MixedTruthiness::Undetermined;
    }

    private static function failure(FunctionLikeMetadata $method, Node\Stmt\ClassMethod $node): bool
    {
        if ((string) $method->declaredReturnType?->type !== 'never' || (string) $method->returnType?->type !== 'never'
            || ! $node->returnType instanceof Node\Identifier || strtolower($node->returnType->toString()) !== 'never'
            || count($method->parameters) !== 3 || count($node->params) !== 3 || $node->stmts === []) {
            return false;
        }
        foreach (['type' => 'string', 'actual' => 'mixed', 'message' => 'string'] as $name => $type) {
            $index = array_search($name, ['type', 'actual', 'message'], true);
            $parameter = $method->parameters[$index];
            $syntax = $node->params[$index];
            if ($parameter->name !== '$'.$name || (string) $parameter->declaredType?->type !== $type
                || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
                || $syntax->byRef || $syntax->variadic || ! self::variable($syntax->var, $name)) {
                return false;
            }
        }
        $last = $node->stmts[array_key_last($node->stmts)];
        return $last instanceof Node\Stmt\Expression && $last->expr instanceof Node\Expr\Throw_
            && (new NodeFinder)->findFirstInstanceOf($node->stmts, Node\Stmt\Return_::class) === null;
    }

    /** @param list<string> $expected */
    private static function staticCall(?Node\Expr $node, string $owner, string $name, array $expected): bool
    {
        return $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
            && strcasecmp($node->class->toString(), $owner) === 0 && $node->name instanceof Node\Identifier
            && strcasecmp($node->name->toString(), $name) === 0 && self::arguments($node->args, $expected);
    }

    /** @param list<Node\Arg|Node\VariadicPlaceholder> $arguments
     * @param list<string> $expected
     */
    private static function arguments(array $arguments, array $expected): bool
    {
        if (count($arguments) !== count($expected)) {
            return false;
        }
        foreach ($arguments as $index => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef || $argument->name !== null) {
                return false;
            }
            $name = $expected[$index];
            if (str_starts_with($name, '@')) {
                if (! $argument->value instanceof Node\Scalar\String_ || $argument->value->value !== substr($name, 1)) {
                    return false;
                }
            } elseif (! self::variable($argument->value, $name)) {
                return false;
            }
        }
        return true;
    }

    private static function variable(Node\Expr $node, string $name): bool
    {
        return $node instanceof Node\Expr\Variable && $node->name === $name;
    }

    private static function local(string $expression): bool
    {
        return preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/D', $expression) === 1
            && ! in_array($expression, ['$this', '$GLOBALS', '$_SERVER', '$_GET', '$_POST', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV'], true);
    }
}
