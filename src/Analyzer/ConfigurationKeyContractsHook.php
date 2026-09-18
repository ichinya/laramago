<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type\MixedType;
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

/** Diagnose absent literal configuration keys only under a closed-runtime contract. */
final class ConfigurationKeyContractsHook implements NodeAnalysisHook, InitializationHook
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Config';
    private const REPOSITORY = 'Illuminate\\Config\\Repository';

    private ?bool $runtimeComplete = null;
    private ?NativeFacade $facade = null;
    private ?ContainerBindings $bindings = null;
    private ?PhpSource $source = null;
    private ?ConfigurationIndex $configuration = null;
    private ?bool $nativeHelper = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\FuncCall|Node\Expr\StaticCall> */
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
        $this->nativeHelper = null;
        $this->sourceHash = null;
        $this->references = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::FunctionCall, NodeKind::StaticMethodCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (! $this->hasCompleteRuntime()) {
            return;
        }
        $reference = $this->reference($context);
        if ($reference instanceof Node\Expr\FuncCall) {
            if (! $this->nativeHelper($reference, $context) || ! self::validGetArguments($reference->args)) {
                return;
            }
            $key = PhpSource::argument($reference->args, 0, 'key');
            $keys = $key instanceof Node\Scalar\String_ ? [$key] : [];
        } elseif ($reference instanceof Node\Expr\StaticCall) {
            if (! $reference->name instanceof Node\Identifier) {
                return;
            }
            $method = strtolower($reference->name->toString());
            if (
                ! (
                    $method === 'get'
                        ? self::validGetArguments($reference->args)
                        : self::validGetManyArguments($reference->args)
                )
                || ! $this->facade()->dispatchesClass(
                    $context->codebase,
                    self::FACADE,
                    'config',
                    self::REPOSITORY,
                    $method,
                )
            ) {
                return;
            }
            if ($method === 'get') {
                $key = PhpSource::argument($reference->args, 0, 'key');
                $keys = $key instanceof Node\Scalar\String_ ? [$key] : [];
            } else {
                $argument = PhpSource::argument($reference->args, 0, 'keys');
                $keys = $argument instanceof Node\Expr\Array_ ? self::getManyKeys($argument) : null;
                if ($keys === null) {
                    return;
                }
            }
        } else {
            return;
        }
        if ($this->bindings()->configured('config')) {
            return;
        }

        foreach ($keys as $key) {
            $this->reportMissing($key, $context);
        }
    }

    private function reportMissing(Node\Scalar\String_ $key, NodeAnalysisContext $context): void
    {
        $parts = explode('.', $key->value);
        if (count($parts) < 2) {
            // The static index cannot prove that an absent namespace is not package-provided.
            return;
        }
        $name = array_pop($parts);
        $catalog = $this->configuration()->stringKeys(implode('.', $parts));
        if (
            $catalog === null
            || ! $catalog->sourceComplete
            || in_array($name, $catalog->keys, true)
        ) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-configuration-key',
            Issue::at(
                'Configuration key "'.$key->value.'" is absent from the explicitly complete runtime catalog.',
                new SourceLocation(
                    $context->source->path,
                    new Span($key->getStartFilePos(), $key->getEndFilePos() + 1),
                ),
            ),
        );
    }

    private function reference(
        NodeAnalysisContext $context,
    ): Node\Expr\FuncCall|Node\Expr\StaticCall|null {
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
            foreach ((new NodeFinder)->find(
                $nodes,
                static fn (Node $node): bool => (
                    $node instanceof Node\Expr\FuncCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            ) as $reference) {
                if (! $reference instanceof Node\Expr\FuncCall && ! $reference instanceof Node\Expr\StaticCall) {
                    continue;
                }
                $this->references[$reference->getStartFilePos().':'.($reference->getEndFilePos() + 1)] = $reference;
            }
        }
        $reference = $this->references[$context->node->span->start.':'.$context->node->span->end] ?? null;
        if ($reference instanceof Node\Expr\FuncCall) {
            return $reference->name instanceof Node\Name && ! $reference->isFirstClassCallable()
                ? $reference
                : null;
        }

        return $reference instanceof Node\Expr\StaticCall
        && $reference->class instanceof Node\Name
        && strcasecmp($reference->class->toString(), self::FACADE) === 0
        && $reference->name instanceof Node\Identifier
        && in_array(strtolower($reference->name->toString()), ['get', 'getmany'], true)
        && ! $reference->isFirstClassCallable()
            ? $reference
            : null;
    }

    private function nativeHelper(Node\Expr\FuncCall $call, NodeAnalysisContext $context): bool
    {
        if (! $call->name instanceof Node\Name) {
            return false;
        }
        $name = $call->name->toString();
        /** @var mixed $namespaced */
        $namespaced = $call->name->getAttribute('namespacedName');
        if ($namespaced instanceof Node\Name && $context->codebase->getFunction($namespaced->toString()) !== null) {
            $name = $namespaced->toString();
        }
        if (strtolower($name) !== 'config') {
            return false;
        }
        if ($this->nativeHelper !== null) {
            return $this->nativeHelper;
        }
        $function = $context->codebase->getFunction($name);
        $file = str_replace('\\', '/', $function?->location->file ?? '');
        $return = $function?->returnType?->type;
        $parameters = $function?->parameters ?? [];
        $app = $context->codebase->getFunction('app');
        $repositoryGet = $context->codebase->getDeclaringMethod(self::REPOSITORY, 'get');
        if (
            ! str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php')
            || $return === null
            || count($return->atomicTypes) !== 1
            || ! $return->atomicTypes[0] instanceof MixedType
            || array_map(static fn ($parameter): string => $parameter->name, $parameters) !== ['$key', '$default']
            || self::byReference($parameters)
            || ! str_ends_with(
                str_replace('\\', '/', $app?->location->file ?? ''),
                '/laravel/framework/src/Illuminate/Foundation/helpers.php',
            )
            || array_map(static fn ($parameter): string => $parameter->name, $app?->parameters ?? []) !== [
                '$abstract',
                '$parameters',
            ]
            || self::byReference($app?->parameters ?? [])
            || $repositoryGet === null
            || $repositoryGet->static
            || $repositoryGet->visibility !== \Mago\Sdk\Analyzer\Type\Visibility::Public
            || strcasecmp($repositoryGet->identifier->class ?? '', self::REPOSITORY) !== 0
            || ! str_ends_with(
                str_replace('\\', '/', $repositoryGet->location->file ?? ''),
                '/laravel/framework/src/Illuminate/Config/Repository.php',
            )
            || array_map(static fn ($parameter): string => $parameter->name, $repositoryGet->parameters) !== [
                '$key',
                '$default',
            ]
            || self::byReference($repositoryGet->parameters)
        ) {
            return $this->nativeHelper = false;
        }
        $node = (new NodeFinder)->findFirst(
            $this->source()->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file) ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Function_
                && strtolower($node->name->toString()) === 'config'
            ),
        );
        if (
            ! $node instanceof Node\Stmt\Function_
            || $node->byRef
            || count($node->params) !== 2
            || count($node->stmts) !== 3
            || ! self::parameters(array_values($node->params), ['key', 'default'])
            || ! self::guardedReturn($node->stmts[0], 'is_null', null, ['key'])
            || ! self::guardedReturn($node->stmts[1], 'is_array', 'set', ['key'])
            || ! $node->stmts[2] instanceof Node\Stmt\Return_
            || ! self::serviceCall($node->stmts[2]->expr, 'get', ['key', 'default'])
        ) {
            return $this->nativeHelper = false;
        }

        return $this->nativeHelper = true;
    }

    /** @param list<\Mago\Sdk\Analyzer\Metadata\ParameterMetadata> $parameters */
    private static function byReference(array $parameters): bool
    {
        foreach ($parameters as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Node\Param> $parameters @param list<string> $names */
    private static function parameters(array $parameters, array $names): bool
    {
        foreach ($parameters as $offset => $parameter) {
            if (
                $parameter->byRef
                || $parameter->variadic
                || ! $parameter->var instanceof Node\Expr\Variable
                || $parameter->var->name !== $names[$offset]
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $arguments */
    private static function guardedReturn(
        Node $statement,
        string $guard,
        ?string $method,
        array $arguments,
    ): bool {
        if (
            ! $statement instanceof Node\Stmt\If_
            || $statement->elseifs !== []
            || $statement->else !== null
            || count($statement->stmts) !== 1
            || ! $statement->stmts[0] instanceof Node\Stmt\Return_
            || ! $statement->cond instanceof Node\Expr\FuncCall
            || ! $statement->cond->name instanceof Node\Name
            || strtolower($statement->cond->name->toString()) !== $guard
            || count($statement->cond->args) !== 1
            || ! self::variables($statement->cond->args, [$arguments[0]])
        ) {
            return false;
        }
        $returned = $statement->stmts[0]->expr;

        return $method === null ? self::service($returned) : self::serviceCall($returned, $method, $arguments);
    }

    private static function service(?Node\Expr $expression): bool
    {
        if (
            ! $expression instanceof Node\Expr\FuncCall
            || ! $expression->name instanceof Node\Name
            || strtolower($expression->name->toString()) !== 'app'
            || count($expression->args) !== 1
        ) {
            return false;
        }
        $service = $expression->args[0] ?? null;

        return (
            $service instanceof Node\Arg
            && ! $service->unpack
            && $service->value instanceof Node\Scalar\String_
            && $service->value->value === 'config'
        );
    }

    /** @param list<string> $arguments */
    private static function serviceCall(?Node\Expr $expression, string $method, array $arguments): bool
    {
        return (
            $expression instanceof Node\Expr\MethodCall
            && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->toString()) === $method
            && self::service($expression->var)
            && self::variables($expression->args, $arguments)
        );
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments @param list<string> $names */
    private static function variables(array $arguments, array $names): bool
    {
        if (count($arguments) !== count($names)) {
            return false;
        }
        foreach ($arguments as $offset => $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                || ! $argument->value instanceof Node\Expr\Variable
                || $argument->value->name !== $names[$offset]
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments */
    private static function validGetArguments(array $arguments): bool
    {
        if (count($arguments) > 2) {
            return false;
        }
        foreach ($arguments as $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                && ! in_array($argument->name->toString(), ['key', 'default'], true)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments */
    private static function validGetManyArguments(array $arguments): bool
    {
        if (count($arguments) > 1) {
            return false;
        }
        foreach ($arguments as $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                && $argument->name->toString() !== 'keys'
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mirror Repository::getMany() for a closed array literal. Numeric array keys
     * select their values as configuration names; non-numeric string keys are the
     * names and their values are defaults. Unknown, unpacked, referenced,
     * duplicate and negative integer keys defer the complete call because array
     * overwrite and append behavior can change the effective entries.
     *
     * @return list<Node\Scalar\String_>|null
     */
    private static function getManyKeys(Node\Expr\Array_ $array): ?array
    {
        /** @var array<int|string, Node\Scalar\String_|null> $entries */
        $entries = [];
        $next = 0;
        foreach ($array->items as $item) {
            if ($item->unpack || $item->byRef) {
                return null;
            }
            $numeric = false;
            if ($item->key === null) {
                if ($next === PHP_INT_MAX) {
                    return null;
                }
                $key = $next++;
                $numeric = true;
            } elseif ($item->key instanceof Node\Scalar\LNumber) {
                $key = $item->key->value;
                $numeric = true;
                if ($key < 0 || $key === PHP_INT_MAX) {
                    return null;
                }
                $next = max($next, $key + 1);
            } elseif ($item->key instanceof Node\Scalar\String_) {
                $numeric = is_numeric($item->key->value);
                $key = self::arrayStringKey($item->key->value);
                if (is_int($key)) {
                    if ($key < 0 || $key === PHP_INT_MAX) {
                        return null;
                    }
                    $next = max($next, $key + 1);
                }
            } else {
                return null;
            }
            if (array_key_exists($key, $entries)) {
                return null;
            }
            $name = $numeric ? $item->value : $item->key;
            $entries[$key] = $name instanceof Node\Scalar\String_ ? $name : null;
        }

        return array_values(array_filter(
            $entries,
            static fn (?Node\Scalar\String_ $key): bool => $key instanceof Node\Scalar\String_,
        ));
    }

    private static function arrayStringKey(string $key): int|string
    {
        $integer = (int) $key;

        return (string) $integer === $key ? $integer : $key;
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
