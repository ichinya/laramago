<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ArrayAssertionCalls;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Argument;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodAssertionProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Array-element assertions require a proven PHP-array prefix, never an ArrayAccess getter. */
final class LaratestoArrayAssertionProvider implements MethodAssertionProvider, InitializationHook
{
    private const ASSERT = 'Laratesto\\Testing\\PhpUnitCompatibility';
    private readonly LaratestoAssertionProvider $assertions;
    private readonly TestoArrayAssertionProvider $arrays;
    /** @var array<string, bool> */
    private array $keyMethods = [];
    private ?bool $constructor = null;
    /** @var array<string, bool> */
    private array $successPaths = [];

    public function __construct(private readonly string $root = '.', public readonly ArrayAssertionCalls $calls = new ArrayAssertionCalls)
    {
        $this->assertions = new LaratestoAssertionProvider($root);
        $this->arrays = new TestoArrayAssertionProvider($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->assertions->initialize($context);
        $this->arrays->initialize($context);
        $this->keyMethods = [];
        $this->constructor = null;
        $this->successPaths = [];
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::ASSERT, 'assertIsArray')];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $invocation = $context->invocation;
        $actual = $invocation->getArgument(0, 'actual');
        $run = $this->calls->run($invocation->span);
        if ($invocation->kind !== InvocationKind::StaticMethod || strcasecmp($invocation->declaringClass ?? '', self::ASSERT) !== 0
            || strcasecmp($invocation->name, 'assertIsArray') !== 0 || $actual === null || $run === null || count($run) < 2
            || ! $this->successPath($context, 'success')) {
            return null;
        }
        $arrays = [];
        $result = null;
        foreach ($run as $expression) {
            $call = ArrayAssertionCalls::assertion($expression);
            if ($call === null) {
                return null;
            }
            $argument = PhpSource::argument($call->args, 0, 'actual');
            $path = $argument === null ? null : ArrayAssertionCalls::path($argument);
            if ($path === null) {
                return null;
            }
            foreach ($call->args as $arg) {
                if (! $arg instanceof Node\Arg || $arg->unpack || $arg->byRef
                    || ($arg->value !== $argument && ! $arg->value instanceof Node\Scalar\String_)) {
                    return null;
                }
            }
            for ($depth = 1; $depth < count($path); ++$depth) {
                if (! isset($arrays[json_encode(array_slice($path, 0, $depth), JSON_THROW_ON_ERROR)])) {
                    return null;
                }
            }
            $testo = strcasecmp($call->class->toString(), 'Testo\\Assert') === 0;
            if ($testo && (! ($this->constructor ??= $this->arrayConstructor($context)) || ! $this->successPath($context, 'typeSuccess'))) {
                return null;
            }
            $proxy = self::localContext($context, $testo ? 'Testo\\Assert' : self::ASSERT, $testo ? 'array' : 'assertIsArray', $call);
            $result = $testo ? $this->arrays->getAssertions($proxy) : $this->assertions->getAssertions($proxy);
            if ($result === null || ($expression instanceof Node\Expr\MethodCall
                && (! $testo || ! $this->keyMethod($context, $expression->name->name)))) {
                return null;
            }
            $arrays[json_encode($path, JSON_THROW_ON_ERROR)] = true;
        }
        $last = $run[array_key_last($run)];
        $syntaxArgument = null;
        foreach ($call->args as $candidate) {
            if ($candidate instanceof Node\Arg && $candidate->value === $argument) {
                $syntaxArgument = $candidate;
            }
        }
        if (! $last instanceof Node\Expr\StaticCall || count($path ?? []) < 2
            || $syntaxArgument === null || $actual->span->start !== $syntaxArgument->getStartFilePos()
            || $actual->span->end !== $syntaxArgument->getEndFilePos() + 1) {
            return null;
        }
        return $result;
    }

    /** Successful assertion logging must not call user code or mutate an escaped array. */
    private function successPath(AssertionProviderContext $context, string $name): bool
    {
        if (array_key_exists($name, $this->successPaths)) {
            return $this->successPaths[$name];
        }
        $owner = 'Testo\\Assert\\Internal\\StaticState';
        $node = $this->nativeMethod($context, $owner, $name, '/testo/assert/src/internal/staticstate.php');
        if ($node === null || ! $node->isStatic()) {
            return $this->successPaths[$name] = false;
        }
        // Tiny source fixtures and adapters may be a no-op, a native allocation,
        // or an unconditional native throw. None can reenter application code.
        $only = count($node->stmts ?? []) === 1 ? $node->stmts[0] : null;
        if ($name === 'success' && ($node->stmts === [] || $only instanceof Node\Stmt\Expression
            && $only->expr instanceof Node\Expr\Throw_ && $only->expr->expr instanceof Node\Expr\New_
            && $only->expr->expr->class instanceof Node\Name\FullyQualified
            && $only->expr->expr->class->toString() === 'RuntimeException'
            && count($only->expr->expr->args) === 1 && $only->expr->expr->args[0] instanceof Node\Arg
            && $only->expr->expr->args[0]->value instanceof Node\Scalar\String_
            && ($context->codebase->getClass('RuntimeException')?->flags->contains(MetadataFlags::BUILTIN) ?? false))) {
            return $this->successPaths[$name] = true;
        }
        if ($name === 'typeSuccess' && $only instanceof Node\Stmt\Return_ && $only->expr instanceof Node\Expr\New_
            && $only->expr->class instanceof Node\Name\FullyQualified && $only->expr->class->toString() === 'stdClass'
            && $only->expr->args === [] && ($context->codebase->getClass('stdClass')?->flags->contains(MetadataFlags::BUILTIN) ?? false)) {
            return $this->successPaths[$name] = true;
        }
        $hash = $name === 'success'
            ? '8924835b9b769a69cf4f7912a29ffa87a6c3143190f10d56629b0664c5dd7901'
            : 'a98b9207ceb2cfb7062b0e3ee2470ee3a229fc00810d84f75d828c0e7c7b2697';
        if (self::fingerprint($node) !== $hash || ! $this->recordClasses($context)) {
            return $this->successPaths[$name] = false;
        }
        $stringifier = $this->nativeMethod($context, 'Testo\\Assert\\Internal\\Support', 'stringify', '/testo/assert/src/internal/support.php');
        if ($stringifier === null || self::fingerprint($stringifier) !== 'ce68ad7356c988ecffe121a9c8c6cf0ecf90349371433921ae111aaed8e5ab9a') {
            return $this->successPaths[$name] = false;
        }
        foreach (['count', 'is_array', 'is_string', 'is_resource', 'is_object', 'strlen', 'str_replace'] as $function) {
            if (! ($context->codebase->getFunction($function)?->flags->contains(MetadataFlags::BUILTIN) ?? false)) {
                return $this->successPaths[$name] = false;
            }
        }
        $state = $context->codebase->getDeclaringProperty($owner, '$state');
        $stateTypes = explode('|', strtolower((string) $state?->declaredType?->type));
        sort($stateTypes);
        $collector = $context->codebase->getClass('Testo\\Assert\\TestState');
        return $this->successPaths[$name] = $state !== null && $state->hooks === []
            && $state->flags->contains(MetadataFlags::STATIC) && $stateTypes === ['null', 'testo\\assert\\teststate']
            && $collector !== null && $collector->flags->contains(MetadataFlags::FINAL) && ! $collector->hasIncompleteHierarchy()
            && $this->arrayProperty($context, 'Testo\\Assert\\TestState', '$history', Visibility::Public);
    }

    private function recordClasses(AssertionProviderContext $context): bool
    {
        $base = 'Testo\\Assert\\State\\Assertion\\AssertionSuccess';
        $composite = 'Testo\\Assert\\State\\Assertion\\AssertionComposite';
        $constructor = $this->nativeMethod($context, $base, '__construct', '/testo/assert/src/state/assertion/assertionsuccess.php');
        if ($constructor === null || self::fingerprint($constructor) !== '3c0e9a2dc01ba169c42ceadd3b9f36afd9689ceaa8f746653b4031be5911e9be') {
            return false;
        }
        foreach ([$base, $composite] as $class) {
            $metadata = $context->codebase->getClass($class);
            if ($metadata === null || $metadata->hasIncompleteHierarchy() || $context->codebase->getDeclaringMethod($class, '__destruct') !== null
                || strcasecmp($context->codebase->getDeclaringMethod($class, '__construct')?->identifier->class ?? '', $base) !== 0) {
                return false;
            }
            foreach (['$value', '$assertion', '$context'] as $name) {
                $property = $context->codebase->getDeclaringProperty($class, $name);
                if ($property === null || $property->hooks !== [] || (string) $property->declaredType?->type !== 'string'
                    || ! $property->flags->contains(MetadataFlags::READONLY)) {
                    return false;
                }
            }
        }
        $metadata = $context->codebase->getClass($composite);
        return $metadata->flags->contains(MetadataFlags::FINAL) && strcasecmp($metadata->directParentClass ?? '', $base) === 0;
    }

    private function arrayProperty(AssertionProviderContext $context, string $class, string $name, Visibility $visibility): bool
    {
        $property = $context->codebase->getDeclaringProperty($class, $name);
        return $property !== null && $property->hooks === [] && ! $property->flags->contains(MetadataFlags::STATIC)
            && $property->readVisibility === $visibility && $property->writeVisibility === $visibility
            && count($property->declaredType?->type->atomicTypes ?? []) === 1
            && $property->declaredType->type->atomicTypes[0] instanceof KeyedArrayType;
    }

    private function nativeMethod(AssertionProviderContext $context, string $owner, string $name, string $suffix): ?Node\Stmt\ClassMethod
    {
        $class = $context->codebase->getClass($owner);
        $method = $context->codebase->getDeclaringMethod($owner, $name);
        if ($class === null || $class->hasIncompleteHierarchy() || $method === null || $method->abstract
            || strcasecmp($method->identifier->class ?? '', $owner) !== 0 || $method->visibility !== Visibility::Public
            || $method->templates !== [] || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || ! str_ends_with(strtolower(str_replace('\\', '/', $method->location->file ?? '')), $suffix)) {
            return null;
        }
        foreach ($method->parameters as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE)) {
                return null;
            }
        }
        return (new ModelReflection($context->codebase, new PhpSource($this->root)))->methodNode($method);
    }

    private static function fingerprint(Node $node): string
    {
        $normalized = '';
        foreach (token_get_all('<?php '.(new Standard)->prettyPrint([$node])) as $token) {
            if (is_array($token)) {
                if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_OPEN_TAG], true)) {
                    $normalized .= str_replace(["\r\n", "\r"], "\n", $token[1]);
                }
            } else {
                $normalized .= $token;
            }
        }
        return hash('sha256', $normalized);
    }

    private function arrayConstructor(AssertionProviderContext $context): bool
    {
        $owner = 'Testo\\Assert\\Internal\\Assertion\\AssertArray';
        $class = $context->codebase->getClass($owner);
        $method = $context->codebase->getDeclaringMethod($owner, '__construct');
        if ($class === null || ! $class->flags->contains(MetadataFlags::FINAL | MetadataFlags::READONLY)
            || $class->hasIncompleteHierarchy() || $context->codebase->getDeclaringMethod($owner, '__destruct') !== null
            || $method === null || strcasecmp($method->identifier->class ?? '', $owner) !== 0) {
            return false;
        }
        $node = (new ModelReflection($context->codebase, new PhpSource($this->root)))->methodNode($method);
        if ($node === null || $node->stmts !== [] || count($node->params) !== 2 || ! $node->isPublic()
            || $node->isStatic() || $node->byRef || ! $node->params[0]->type instanceof Node\Identifier
            || strtolower($node->params[0]->type->name) !== 'array') {
            return false;
        }
        foreach (['value', 'parent'] as $index => $name) {
            $parameter = $node->params[$index];
            if ($parameter->byRef || $parameter->variadic || $parameter->default !== null || $parameter->flags !== Node\Stmt\Class_::MODIFIER_PRIVATE
                || ! $parameter->var instanceof Node\Expr\Variable || $parameter->var->name !== $name) {
                return false;
            }
        }
        return true;
    }

    private static function localContext(AssertionProviderContext $context, string $owner, string $name, Node\Expr\StaticCall $call): AssertionProviderContext
    {
        $arguments = [];
        foreach ($call->args as $index => $argument) {
            $actual = $argument->name?->toString() === 'actual' || $argument->name === null && $index === 0;
            $arguments[] = new Argument($argument->name?->toString(), false, false, new Span(0, 0),
                $actual ? '$value' : (new Standard)->prettyPrintExpr($argument->value), null);
        }
        return new AssertionProviderContext($context->phpVersion, $context->codebase,
            new Invocation(InvocationKind::StaticMethod, $name, $owner, Type::namedObject($owner), new Span(0, 0), $arguments),
            $context->types, $context->cancellation);
    }

    /** Only readonly key inspection may occur between the proven array assertions. */
    private function keyMethod(AssertionProviderContext $context, string $name): bool
    {
        $name = strtolower($name);
        if (array_key_exists($name, $this->keyMethods)) {
            return $this->keyMethods[$name];
        }
        $owner = 'Testo\\Assert\\Internal\\Assertion\\AssertArray';
        foreach (['array_key_exists', 'count', 'implode'] as $function) {
            if (! ($context->codebase->getFunction($function)?->flags->contains(MetadataFlags::BUILTIN) ?? false)) {
                return $this->keyMethods[$name] = false;
            }
        }
        $class = $context->codebase->getClass($owner);
        $method = $context->codebase->getDeclaringMethod($owner, $name);
        if ($class === null || ! $class->flags->contains(MetadataFlags::FINAL | MetadataFlags::READONLY)
            || $method === null || strcasecmp($method->identifier->class ?? '', $owner) !== 0 || $method->static
            || $method->visibility !== Visibility::Public || $method->abstract || $method->templates !== []
            || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || ! str_ends_with(strtolower(str_replace('\\', '/', $method->location->file ?? '')), '/testo/assert/src/internal/assertion/assertarray.php')) {
            return $this->keyMethods[$name] = false;
        }
        $node = (new ModelReflection($context->codebase, new PhpSource($this->root)))->methodNode($method);
        if ($node === null || $node->stmts === null || count($node->params) !== 1 || ! $node->params[0]->variadic
            || $node->params[0]->byRef || $node->byRef || ! $node->isPublic()
            || ! $node->returnType instanceof Node\Name || strtolower($node->returnType->toString()) !== 'static'
            || (new NodeFinder)->findFirst($node->stmts, static fn (Node $part): bool =>
                $part instanceof Node\Stmt\Global_ || $part instanceof Node\Stmt\Static_ || $part instanceof Node\Stmt\Unset_
                || $part instanceof Node\Stmt\TryCatch) !== null) {
            return $this->keyMethods[$name] = false;
        }
        // Structural shape alone does not prove that local temporaries never
        // acquire objects whose count/string conversions reenter user code.
        // Keep only the audited native bodies and the minimal read-only adapter.
        $fingerprints = $name === 'haskeys'
            ? ['5c2e3ba9569d0988f2d98bdc0a663d4b132ff1af44d242e4b2c0a18c2b850191', '43d860064a62b43b597790b628086429930997dddaa08be0ebdd2d87fcb069c3']
            : ['3c501ecad98469c477337357b5f8a33de303d850235fe10cbcea43e1cea3917e'];
        if (! in_array(self::fingerprint($node), $fingerprints, true)) {
            return $this->keyMethods[$name] = false;
        }
        $valueReads = [];
        $approvedReads = [];
        $successCalls = [];
        $failureCalls = [];
        foreach ((new NodeFinder)->find($node->stmts, static fn (Node $part): bool =>
            $part instanceof Node\Stmt\Expression || $part instanceof Node\Expr\Throw_) as $part) {
            if ($part->expr instanceof Node\Expr\MethodCall) {
                if ($part instanceof Node\Expr\Throw_) {
                    $failureCalls[spl_object_id($part->expr)] = true;
                } else {
                    $successCalls[spl_object_id($part->expr)] = true;
                }
            }
        }
        foreach ((new NodeFinder)->find($node->stmts, static fn (Node $part): bool => $part instanceof Node\Expr) as $part) {
            if ($part instanceof Node\Expr\AssignRef || $part instanceof Node\Expr\Closure || $part instanceof Node\Expr\ArrowFunction
                || $part instanceof Node\Expr\StaticCall || $part instanceof Node\Expr\New_ || $part instanceof Node\Expr\Eval_
                || $part instanceof Node\Expr\Include_ || $part instanceof Node\Expr\Yield_ || $part instanceof Node\Expr\YieldFrom
                || $part instanceof Node\Expr\NullsafeMethodCall || $part instanceof Node\Expr\NullsafePropertyFetch) {
                return $this->keyMethods[$name] = false;
            }
            if ($part instanceof Node\Expr\Variable && (! is_string($part->name)
                || in_array($part->name, ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'], true))) {
                return $this->keyMethods[$name] = false;
            }
            if ($part instanceof Node\Expr\FuncCall
                && (! $part->name instanceof Node\Name\FullyQualified
                    || ! in_array(strtolower($part->name->toString()), ['array_key_exists', 'count', 'implode'], true))) {
                return $this->keyMethods[$name] = false;
            }
            if ($part instanceof Node\Expr\MethodCall && (! $part->var instanceof Node\Expr\PropertyFetch
                || ! self::thisProperty($part->var, 'parent') || ! $part->name instanceof Node\Identifier
                || ! in_array(strtolower($part->name->name), ['success', 'fail'], true))) {
                return $this->keyMethods[$name] = false;
            }
            if ($part instanceof Node\Expr\MethodCall) {
                if (strtolower($part->name->name) === 'fail') {
                    if (! isset($failureCalls[spl_object_id($part)])) {
                        return $this->keyMethods[$name] = false;
                    }
                } else {
                    $parent = 'Testo\\Assert\\State\\Assertion\\AssertionComposite';
                    $success = $this->nativeMethod($context, $parent, 'success', '/testo/assert/src/state/assertion/assertioncomposite.php');
                    if (! isset($successCalls[spl_object_id($part)]) || $success === null || ! $this->recordClasses($context)
                        || self::fingerprint($success) !== '73287983149c38f5e9cea753f85ef86aaae8f8a0c70e813e29ddfa197c02cc6f'
                        || ! $this->arrayProperty($context, $parent, '$records', Visibility::Private)
                        || (string) $context->codebase->getDeclaringProperty($owner, '$parent')?->declaredType?->type !== $parent) {
                        return $this->keyMethods[$name] = false;
                    }
                }
            }
            if ($part instanceof Node\Expr\MethodCall || $part instanceof Node\Expr\FuncCall) {
                foreach ($part->args as $index => $arg) {
                    if (! $arg instanceof Node\Arg || $arg->unpack || $arg->byRef) {
                        return $this->keyMethods[$name] = false;
                    }
                    $property = (new NodeFinder)->findFirstInstanceOf([$arg->value], Node\Expr\PropertyFetch::class);
                    if ($property !== null && (! $part instanceof Node\Expr\FuncCall
                        || strtolower($part->name->toString()) !== 'array_key_exists' || $index !== 1
                        || $arg->value !== $property || ! self::thisProperty($property, 'value'))) {
                        return $this->keyMethods[$name] = false;
                    }
                    if ($property !== null) {
                        $approvedReads[spl_object_id($property)] = true;
                    }
                }
            }
            if ($part instanceof Node\Expr\PropertyFetch && ! self::thisProperty($part, 'value') && ! self::thisProperty($part, 'parent')) {
                return $this->keyMethods[$name] = false;
            }
            if ($part instanceof Node\Expr\PropertyFetch && self::thisProperty($part, 'value')) {
                $valueReads[spl_object_id($part)] = true;
            }
        }
        return $this->keyMethods[$name] = array_diff_key($valueReads, $approvedReads) === [];
    }

    private static function thisProperty(Node\Expr\PropertyFetch $node, string $name): bool
    {
        return $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier && $node->name->name === $name;
    }
}
