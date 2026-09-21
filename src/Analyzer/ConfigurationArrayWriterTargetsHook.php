<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Diagnose native Config array writers whose asserted initial target is not an array. */
final class ConfigurationArrayWriterTargetsHook implements NodeAnalysisHook, InitializationHook
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Config';
    private const REPOSITORY = 'Illuminate\\Config\\Repository';

    private ?bool $runtimeComplete = null;
    private ?NativeFacade $facade = null;
    private ?ContainerBindings $bindings = null;
    private ?PhpSource $source = null;
    private ?ConfigurationIndex $configuration = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\StaticCall> */
    private array $references = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->runtimeComplete = null;
        $this->facade = null;
        $this->bindings = null;
        $this->source = null;
        $this->configuration = null;
        $this->sourceHash = null;
        $this->references = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::StaticMethodCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (! $this->hasCompleteRuntime() || $this->bindings()->configured('config')) {
            return;
        }
        $reference = $this->reference($context);
        if (
            ! $reference instanceof Node\Expr\StaticCall
            || ! $reference->name instanceof Node\Identifier
            || ! self::validArguments($reference)
        ) {
            return;
        }
        $method = strtolower($reference->name->toString());
        if (
            ! $this->facade()->dispatchesClass(
                $context->codebase,
                self::FACADE,
                'config',
                self::REPOSITORY,
                $method,
            )
            || ! $this->nativeWriter($context, $method)
        ) {
            return;
        }
        $key = PhpSource::argument($reference->args, 0, 'key');
        if (! $key instanceof Node\Scalar\String_ || ! $this->invalidTarget($key->value, $method)) {
            return;
        }

        $context->report(
            Level::Warning,
            'laramago-non-array-configuration-writer-target',
            Issue::at(
                'Configuration target "'.$key->value.'" is not an array in the explicitly unchanged runtime catalog.',
                new SourceLocation(
                    $context->source->path,
                    new Span($key->getStartFilePos(), $key->getEndFilePos() + 1),
                ),
            )->withNote(
                'Laravel\'s native Config::'.$method.'() reads the target with an empty-array default before writing.',
            ),
        );
    }

    private function reference(NodeAnalysisContext $context): ?Node\Expr\StaticCall
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->references = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $reference) {
                $this->references[$reference->getStartFilePos().':'.($reference->getEndFilePos() + 1)] = $reference;
            }
        }
        $reference = $this->references[$context->node->span->start.':'.$context->node->span->end] ?? null;

        return $reference instanceof Node\Expr\StaticCall
        && $reference->class instanceof Node\Name
        && strcasecmp($reference->class->toString(), self::FACADE) === 0
        && $reference->name instanceof Node\Identifier
        && in_array(strtolower($reference->name->toString()), ['push', 'prepend'], true)
        && ! $reference->isFirstClassCallable()
            ? $reference
            : null;
    }

    private static function validArguments(Node\Expr\StaticCall $call): bool
    {
        if (count($call->args) !== 2) {
            return false;
        }
        $mapped = [];
        foreach ($call->args as $offset => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack) {
                return false;
            }
            $name = $argument->name?->toString() ?? ['key', 'value'][$offset];
            if (! in_array($name, ['key', 'value'], true) || isset($mapped[$name])) {
                return false;
            }
            $mapped[$name] = true;
        }

        return isset($mapped['key'], $mapped['value']);
    }

    private function invalidTarget(string $key, string $method): bool
    {
        $separator = strrpos($key, '.');
        if ($separator === false) {
            return false;
        }
        $catalog = $this->configuration()->stringKeys(substr($key, 0, $separator));
        $declaration = $this->configuration()->declaration($key);
        if ($catalog === null || ! $catalog->sourceComplete || ! $declaration?->sourceSelected) {
            return false;
        }
        $type = $this->configuration()->lookup($key, null);
        if ($type === null) {
            return false;
        }
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof KeyedArrayType || $atom instanceof ListType) {
                return false;
            }
            if ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null) {
                if ($method === 'push') {
                    return false;
                }

                continue;
            }
            if ($atom instanceof ScalarType) {
                if ($atom->kind === ScalarTypeKind::Boolean && $atom->refinement !== true) {
                    // PHP still converts false for [] writes with a deprecation; avoid a runtime-error claim.
                    return false;
                }
                if (
                    in_array(
                        $atom->kind,
                        [
                            ScalarTypeKind::Boolean,
                            ScalarTypeKind::Integer,
                            ScalarTypeKind::Float,
                            ScalarTypeKind::String,
                        ],
                        true,
                    )
                ) {
                    continue;
                }
            }

            return false;
        }

        return true;
    }

    private function nativeWriter(NodeAnalysisContext $context, string $method): bool
    {
        $reflection = new ModelReflection($context->codebase, $this->source());
        $get = $context->codebase->getDeclaringMethod(self::REPOSITORY, 'get');
        $writer = $context->codebase->getDeclaringMethod(self::REPOSITORY, $method);
        if ($get === null || $writer === null) {
            return false;
        }

        return (
            self::nativeRepositoryMethod($get)
            && self::nativeRepositoryMethod($writer)
            && self::nativeGetBody($reflection->methodNode($get))
            && self::nativeWriterBody($reflection->methodNode($writer), $method)
        );
    }

    private static function nativeGetBody(?Node\Stmt\ClassMethod $method): bool
    {
        $statements = $method?->stmts;
        if (
            ! self::nativeMethod($method, ['key', 'default'], true)
            || ! is_array($statements)
            || count($statements) !== 2
            || ! $statements[0] instanceof Node\Stmt\If_
            || $statements[0]->elseifs !== []
            || $statements[0]->else !== null
            || count($statements[0]->stmts) !== 1
            || ! self::functionCall($statements[0]->cond, 'is_array', ['key'])
            || ! $statements[0]->stmts[0] instanceof Node\Stmt\Return_
            || ! self::instanceCall($statements[0]->stmts[0]->expr, 'getMany', ['key'])
            || ! $statements[1] instanceof Node\Stmt\Return_
        ) {
            return false;
        }
        $expression = $statements[1]->expr;

        return (
            $expression instanceof Node\Expr\StaticCall
            && $expression->class instanceof Node\Name
            && strcasecmp($expression->class->toString(), 'Illuminate\\Support\\Arr') === 0
            && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->toString()) === 'get'
            && count($expression->args) === 3
            && self::itemsProperty($expression->args[0] ?? null)
            && self::variables(array_slice($expression->args, 1), ['key', 'default'])
        );
    }

    private static function nativeWriterBody(?Node\Stmt\ClassMethod $method, string $name): bool
    {
        $statements = $method?->stmts;
        if (
            ! self::nativeMethod($method, ['key', 'value'])
            || ! is_array($statements)
            || count($statements) !== 3
            || ! $statements[0] instanceof Node\Stmt\Expression
            || ! $statements[0]->expr instanceof Node\Expr\Assign
            || ! self::variable($statements[0]->expr->var, 'array')
            || ! self::getWithEmptyDefault($statements[0]->expr->expr)
            || ! $statements[1] instanceof Node\Stmt\Expression
            || ! $statements[2] instanceof Node\Stmt\Expression
            || ! self::instanceCall($statements[2]->expr, 'set', ['key', 'array'])
        ) {
            return false;
        }
        $write = $statements[1]->expr;
        if ($name === 'prepend') {
            return self::functionCall($write, 'array_unshift', ['array', 'value']);
        }

        return (
            $name === 'push'
            && $write instanceof Node\Expr\Assign
            && $write->var instanceof Node\Expr\ArrayDimFetch
            && $write->var->dim === null
            && self::variable($write->var->var, 'array')
            && self::variable($write->expr, 'value')
        );
    }

    /** @param list<string> $parameters */
    private static function nativeMethod(
        ?Node\Stmt\ClassMethod $method,
        array $parameters,
        bool $default = false,
    ): bool {
        if (
            ! $method instanceof Node\Stmt\ClassMethod
            || ! $method->isPublic()
            || $method->isStatic()
            || $method->byRef
            || count($method->params) !== count($parameters)
            || $method->returnType !== null
        ) {
            return false;
        }
        foreach ($method->params as $offset => $parameter) {
            if (
                $parameter->byRef
                || $parameter->variadic
                || $parameter->type !== null
                || ! $parameter->var instanceof Node\Expr\Variable
                || $parameter->var->name !== $parameters[$offset]
                || $offset === 0
                && $parameter->default !== null
                || $offset === 1
                && $default
                && (! $parameter->default instanceof Node\Expr\ConstFetch
                || strtolower($parameter->default->name->toString()) !== 'null')
                || $offset === 1
                && ! $default
                && $parameter->default !== null
            ) {
                return false;
            }
        }

        return true;
    }

    private static function getWithEmptyDefault(Node\Expr $expression): bool
    {
        if (
            ! $expression instanceof Node\Expr\MethodCall
            || ! self::variable($expression->var, 'this')
            || ! $expression->name instanceof Node\Identifier
            || strtolower($expression->name->toString()) !== 'get'
            || count($expression->args) !== 2
            || ! self::variableArgument($expression->args[0] ?? null, 'key')
        ) {
            return false;
        }
        $default = $expression->args[1] ?? null;

        return (
            $default instanceof Node\Arg
            && ! $default->unpack
            && $default->name === null
            && $default->value instanceof Node\Expr\Array_
            && $default->value->items === []
        );
    }

    /** @param list<string> $arguments */
    private static function functionCall(Node\Expr $expression, string $function, array $arguments): bool
    {
        return (
            $expression instanceof Node\Expr\FuncCall
            && $expression->name instanceof Node\Name
            && strtolower($expression->name->toString()) === $function
            && self::variables($expression->args, $arguments)
        );
    }

    /** @param list<string> $arguments */
    private static function instanceCall(?Node\Expr $expression, string $method, array $arguments): bool
    {
        return (
            $expression instanceof Node\Expr\MethodCall
            && self::variable($expression->var, 'this')
            && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->toString()) === strtolower($method)
            && self::variables($expression->args, $arguments)
        );
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments
     * @param list<string> $names
     */
    private static function variables(array $arguments, array $names): bool
    {
        if (count($arguments) !== count($names)) {
            return false;
        }
        foreach ($arguments as $offset => $argument) {
            if (! self::variableArgument($argument, $names[$offset])) {
                return false;
            }
        }

        return true;
    }

    private static function variableArgument(Node\Arg|Node\VariadicPlaceholder|null $argument, string $name): bool
    {
        return (
            $argument instanceof Node\Arg
            && ! $argument->unpack
            && $argument->name === null
            && self::variable($argument->value, $name)
        );
    }

    private static function itemsProperty(Node\Arg|Node\VariadicPlaceholder|null $argument): bool
    {
        return (
            $argument instanceof Node\Arg
            && ! $argument->unpack
            && $argument->name === null
            && $argument->value instanceof Node\Expr\PropertyFetch
            && self::variable($argument->value->var, 'this')
            && $argument->value->name instanceof Node\Identifier
            && $argument->value->name->toString() === 'items'
        );
    }

    private static function variable(?Node\Expr $expression, string $name): bool
    {
        return $expression instanceof Node\Expr\Variable && $expression->name === $name;
    }

    private static function nativeRepositoryMethod(?FunctionLikeMetadata $method): bool
    {
        return (
            $method !== null
            && strcasecmp($method->identifier->class ?? '', self::REPOSITORY) === 0
            && str_ends_with(
                str_replace('\\', '/', $method->location->file ?? ''),
                '/laravel/framework/src/Illuminate/Config/Repository.php',
            )
        );
    }

    private function hasCompleteRuntime(): bool
    {
        return $this->runtimeComplete ??= self::runtimeComplete($this->root);
    }

    private function facade(): NativeFacade
    {
        return $this->facade ??= new NativeFacade($this->root);
    }

    private function bindings(): ContainerBindings
    {
        return $this->bindings ??= new ContainerBindings($this->root);
    }

    private function source(): PhpSource
    {
        return $this->source ??= new PhpSource($this->root);
    }

    private function configuration(): ConfigurationIndex
    {
        return $this->configuration ??= new ConfigurationIndex($this->source());
    }

    private static function runtimeComplete(string $root): bool
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        if ($text === false) {
            return false;
        }
        try {
            /** @var mixed $composer */
            $composer = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $settings */
        $settings = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $contract */
        $contract = is_array($settings) ? $settings['configuration-keys'] ?? null : null;

        return (
            is_array($contract)
            && ($contract['complete'] ?? null) === true
            && ($contract['runtime-configuration-unchanged'] ?? null) === true
        );
    }
}
