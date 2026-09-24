<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodAssertionProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/** Narrow values after verified Laratesto assertion methods return successfully. */
final class LaratestoAssertionProvider implements MethodAssertionProvider, InitializationHook
{
    private const CLASS_NAME = 'Laratesto\\Testing\\PhpUnitCompatibility';

    /** Method fingerprints include signatures, PHPDoc and bodies. */
    private const FINGERPRINTS = [
        'assertisarray' => '3d2282973ccec9f68d6680ea201205396daa4c835cf54c14cf49ba030ecb1188',
        'assertisstring' => '52313284895d1877febb33f85b8612100268613b1d8191430dd8bbab68838c77',
        'assertisint' => '96e23dc43a47d39f5743b75f949815e33f64e473e58e9ffa20dcf26ac3c65450',
        'assertisbool' => '5429639bf4c4c4aaeb717b61b4abaac85cf20b1c462d918cf47e972ff505ab2e',
        'assertisobject' => '96916e9a8f011c85eed753fd049eb546c2056d9e70c1c0b4bbae81bb80a704cc',
        'assertnotfalse' => 'aa6e6e830aa3c1370ef46211c6faa42ed2fcccb1061ecd9c2df201f848f3d5f5',
    ];

    /** @var array<string, bool> */
    private array $verified = [];
    /** @var array<string, bool> */
    private array $helperVerified = [];
    private readonly PhpSource $source;

    public function __construct(string $root = '.')
    {
        $this->source = new PhpSource($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->verified = [];
        $this->helperVerified = [];
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::CLASS_NAME, 'assertIsArray'),
            MethodTarget::exact(self::CLASS_NAME, 'assertIsString'),
            MethodTarget::exact(self::CLASS_NAME, 'assertIsInt'),
            MethodTarget::exact(self::CLASS_NAME, 'assertIsBool'),
            MethodTarget::exact(self::CLASS_NAME, 'assertIsObject'),
            MethodTarget::exact(self::CLASS_NAME, 'assertNotFalse'),
        ];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $call = $context->invocation;
        $name = strtolower($call->name);
        if (
            $call->kind !== InvocationKind::StaticMethod
            || strcasecmp($call->declaringClass ?? '', self::CLASS_NAME) !== 0
            || ! isset(self::FINGERPRINTS[$name])
            || count($call->arguments) > 2
        ) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $actual = $call->getArgument(0, 'actual');
        if (
            $actual === null
            || ! self::localVariable($actual->expression)
            || ! $this->verified($context, $name)
            || ! $this->helperMatches($context->codebase, $name === 'assertnotfalse' ? 'notSame' : 'true')
        ) {
            return null;
        }

        $kind = $name === 'assertnotfalse' ? TypeAssertionKind::IsNotType : TypeAssertionKind::IsType;
        $type = match ($name) {
            'assertisarray' => Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
            'assertisstring' => Type::string(),
            'assertisint' => Type::int(),
            'assertisbool' => Type::bool(),
            'assertisobject' => Type::object(),
            'assertnotfalse' => Type::false(),
            default => null,
        };
        if ($type === null) {
            return null;
        }

        return new InvocationAssertions(assertions: [
            '$actual' => [new TypeAssertion($kind, $type)],
        ]);
    }

    private function verified(AssertionProviderContext $context, string $name): bool
    {
        if (array_key_exists($name, $this->verified)) {
            return $this->verified[$name];
        }
        $class = $context->codebase->getClassLike(self::CLASS_NAME);
        $method = $context->codebase->getDeclaringMethod(self::CLASS_NAME, $name);
        if (
            $class === null
            || ! $class->flags->contains(MetadataFlags::FINAL)
            || $class->hasIncompleteHierarchy()
            || $class->pseudoMethods !== []
            || $class->staticPseudoMethods !== []
            || $method === null
            || strcasecmp($method->identifier->class ?? '', self::CLASS_NAME) !== 0
            || ! $method->static
            || ! str_ends_with(
                strtolower(str_replace('\\', '/', $method->location->file ?? '')),
                '/ichinya/laratesto/src/testing/phpunitcompatibility.php',
            )
        ) {
            return $this->verified[$name] = false;
        }
        $node = (new ModelReflection($context->codebase, $this->source))->methodNode($method);

        return $this->verified[$name] = $node !== null && self::fingerprint($node) === self::FINGERPRINTS[$name];
    }

    /** Confirm the delegated assertion can only return after its tested condition holds. */
    private function helperMatches(Codebase $codebase, string $name): bool
    {
        if (array_key_exists($name, $this->helperVerified)) {
            return $this->helperVerified[$name];
        }
        $assertClass = 'Testo\\Assert';
        $stateClass = 'Testo\\Assert\\Internal\\StaticState';
        $assert = $codebase->getClassLike($assertClass);
        $method = $codebase->getDeclaringMethod($assertClass, $name);
        $state = $codebase->getClassLike($stateClass);
        $fail = $codebase->getDeclaringMethod($stateClass, 'fail');
        if (
            $assert === null
            || ! $assert->flags->contains(MetadataFlags::FINAL)
            || $assert->hasIncompleteHierarchy()
            || $assert->pseudoMethods !== []
            || $assert->staticPseudoMethods !== []
            || $method === null
            || strcasecmp($method->identifier->class ?? '', $assertClass) !== 0
            || ! $method->static
            || $method->visibility !== Visibility::Public
            || ! str_ends_with(
                strtolower(str_replace('\\', '/', $method->location->file ?? '')),
                '/testo/assert/assert.php',
            )
            || $state === null
            || ! $state->flags->contains(MetadataFlags::FINAL)
            || $state->hasIncompleteHierarchy()
            || $fail === null
            || strcasecmp($fail->identifier->class ?? '', $stateClass) !== 0
            || ! $fail->static
            || (string) $fail->declaredReturnType?->type !== 'never'
            || ! str_ends_with(
                strtolower(str_replace('\\', '/', $fail->location->file ?? '')),
                '/testo/assert/src/internal/staticstate.php',
            )
        ) {
            return $this->helperVerified[$name] = false;
        }
        $node = (new ModelReflection($codebase, $this->source))->methodNode($method);
        $statements = $node?->stmts ?? [];
        $statement = count($statements) === 1 ? $statements[0] : null;
        $expression = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
        if (
            ! $expression instanceof Node\Expr\Ternary
            || ! self::stateCall($expression->if, $stateClass, 'success')
            || ! self::stateCall($expression->else, $stateClass, 'fail')
        ) {
            return $this->helperVerified[$name] = false;
        }
        $condition = $expression->cond;
        if ($name === 'true') {
            $matches =
                $condition instanceof Node\Expr\BinaryOp\Identical
                && self::variable($condition->left, 'actual')
                && $condition->right instanceof Node\Expr\ConstFetch
                && strtolower($condition->right->name->toString()) === 'true';
        } else {
            $matches =
                $condition instanceof Node\Expr\BinaryOp\NotIdentical
                && self::variable($condition->left, 'actual')
                && self::variable($condition->right, 'expected');
        }

        return $this->helperVerified[$name] = $matches;
    }

    private static function stateCall(?Node\Expr $expression, string $class, string $method): bool
    {
        return (
            $expression instanceof Node\Expr\StaticCall
            && $expression->class instanceof Node\Name
            && strcasecmp($expression->class->toString(), $class) === 0
            && $expression->name instanceof Node\Identifier
            && strcasecmp($expression->name->toString(), $method) === 0
        );
    }

    private static function variable(Node\Expr $expression, string $name): bool
    {
        return $expression instanceof Node\Expr\Variable && $expression->name === $name;
    }

    private static function localVariable(string $expression): bool
    {
        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/D', $expression) !== 1) {
            return false;
        }

        return ! in_array(
            $expression,
            [
                '$this',
                '$GLOBALS',
                '$_SERVER',
                '$_GET',
                '$_POST',
                '$_FILES',
                '$_COOKIE',
                '$_SESSION',
                '$_REQUEST',
                '$_ENV',
            ],
            true,
        );
    }

    private static function fingerprint(Node $node): string
    {
        $normalized = '';
        foreach (token_get_all('<?php '.(new Standard)->prettyPrint([$node])) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_OPEN_TAG], true)) {
                    continue;
                }
                $normalized .= str_replace(["\r\n", "\r"], "\n", $token[1]);
            } else {
                $normalized .= $token;
            }
        }

        return hash('sha256', $normalized);
    }
}
