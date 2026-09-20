<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\MetadataConfidence;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type\Visibility;
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

/** Diagnose absent literal keys used by Laravel's native configuration injection attribute. */
final class ConfigurationAttributeContractsHook implements NodeAnalysisHook, InitializationHook
{
    private const ATTRIBUTE = 'Illuminate\\Container\\Attributes\\Config';
    private const CONTAINER = 'Illuminate\\Contracts\\Container\\Container';
    private const CONTEXTUAL_ATTRIBUTE = 'Illuminate\\Contracts\\Container\\ContextualAttribute';
    private const REPOSITORY = 'Illuminate\\Config\\Repository';

    private ?bool $runtimeComplete = null;
    private ?ContainerBindings $bindings = null;
    private ?PhpSource $source = null;
    private ?ConfigurationIndex $configuration = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Attribute> */
    private array $references = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->runtimeComplete = null;
        $this->bindings = null;
        $this->source = null;
        $this->configuration = null;
        $this->sourceHash = null;
        $this->references = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::Attribute];
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
        $attribute = $this->reference($context);
        if (
            ! $attribute instanceof Node\Attribute
            || ! self::validArguments($attribute->args)
            || ! $this->nativeAttribute($context)
            || $this->bindings()->configured('config')
        ) {
            return;
        }
        $key = PhpSource::argument($attribute->args, 0, 'key');
        if (! $key instanceof Node\Scalar\String_) {
            return;
        }
        $parts = explode('.', $key->value);
        if (count($parts) < 2) {
            // The static index cannot prove that an absent namespace is not package-provided.
            return;
        }
        $name = array_pop($parts);
        if (
            $this->configuration()->stringKeyConfidence(implode('.', $parts), $name)
            !== MetadataConfidence::CompleteAbsent
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

    private function reference(NodeAnalysisContext $context): ?Node\Attribute
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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Attribute::class) as $reference) {
                $this->references[$reference->getStartFilePos().':'.($reference->getEndFilePos() + 1)] = $reference;
            }
        }
        $reference = $this->references[$context->node->span->start.':'.$context->node->span->end] ?? null;

        return $reference instanceof Node\Attribute && strcasecmp($reference->name->toString(), self::ATTRIBUTE) === 0
            ? $reference
            : null;
    }

    private function nativeAttribute(NodeAnalysisContext $context): bool
    {
        $class = $context->codebase->getClass(self::ATTRIBUTE);
        $constructor = $context->codebase->getMethod(self::ATTRIBUTE, '__construct');
        $resolve = $context->codebase->getMethod(self::ATTRIBUTE, 'resolve');
        if (
            $class === null
            || $class->hasIncompleteHierarchy()
            || ! in_array(
                strtolower(self::CONTEXTUAL_ATTRIBUTE),
                array_map(strtolower(...), $class->parentInterfaces),
                true,
            )
            || $constructor === null
            || $resolve === null
            || strcasecmp($constructor->identifier->class ?? '', self::ATTRIBUTE) !== 0
            || strcasecmp($resolve->identifier->class ?? '', self::ATTRIBUTE) !== 0
            || ! $constructor->constructor
            || $constructor->static
            || $constructor->visibility !== Visibility::Public
            || $constructor->flags->contains(MetadataFlags::BY_REFERENCE)
            || ! $resolve->static
            || $resolve->visibility !== Visibility::Public
            || $resolve->flags->contains(MetadataFlags::BY_REFERENCE)
            || ! self::frameworkFile($constructor->location->file, 'Illuminate/Container/Attributes/Config.php')
            || ! self::frameworkFile($resolve->location->file, 'Illuminate/Container/Attributes/Config.php')
            || ! self::nativeContainerMake($context)
            || ! self::nativeRepositoryGet($context)
        ) {
            return false;
        }
        $reflection = new ModelReflection($context->codebase, $this->source());
        $constructorNode = $reflection->methodNode($constructor);
        $resolveNode = $reflection->methodNode($resolve);
        if (
            $constructorNode === null
            || count($constructorNode->params) !== 2
            || $constructorNode->byRef
            || $constructorNode->stmts !== []
            || ! self::promotedParameter($constructorNode->params[0], 'key', 'string', false)
            || ! self::promotedParameter($constructorNode->params[1], 'default', 'mixed', true)
            || $resolveNode === null
            || $resolveNode->byRef
            || ! self::resolveParameters(array_values($resolveNode->params))
        ) {
            return false;
        }
        $statements = $resolveNode->stmts;
        $expression =
            is_array($statements) && count($statements) === 1 && $statements[0] instanceof Node\Stmt\Return_
                ? $statements[0]->expr
                : null;

        return self::nativeResolveExpression($expression);
    }

    private static function nativeContainerMake(NodeAnalysisContext $context): bool
    {
        $method = $context->codebase->getMethod(self::CONTAINER, 'make') ?? $context->codebase->getDeclaringMethod(
            self::CONTAINER,
            'make',
        );

        return (
            $method !== null
            && strcasecmp($method->identifier->class ?? '', self::CONTAINER) === 0
            && ! $method->static
            && $method->visibility === Visibility::Public
            && ! $method->flags->contains(MetadataFlags::BY_REFERENCE)
            && ! self::byReference($method->parameters)
            && array_map(static fn ($parameter): string => $parameter->name, $method->parameters) === [
                '$abstract',
                '$parameters',
            ]
            && self::frameworkFile($method->location->file, 'Illuminate/Contracts/Container/Container.php')
        );
    }

    private static function nativeRepositoryGet(NodeAnalysisContext $context): bool
    {
        $method = $context->codebase->getMethod(self::REPOSITORY, 'get') ?? $context->codebase->getDeclaringMethod(
            self::REPOSITORY,
            'get',
        );
        if (
            $method === null
            || strcasecmp($method->identifier->class ?? '', self::REPOSITORY) !== 0
            || $method->static
            || $method->visibility !== Visibility::Public
            || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || ! self::frameworkFile($method->location->file, 'Illuminate/Config/Repository.php')
            || array_map(static fn ($parameter): string => $parameter->name, $method->parameters) !== [
                '$key',
                '$default',
            ]
        ) {
            return false;
        }

        return ! self::byReference($method->parameters);
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

    private static function promotedParameter(
        Node\Param $parameter,
        string $name,
        string $type,
        bool $nullDefault,
    ): bool {
        return (
            $parameter->isPromoted()
            && $parameter->isPublic()
            && ! $parameter->byRef
            && ! $parameter->variadic
            && $parameter->var instanceof Node\Expr\Variable
            && $parameter->var->name === $name
            && $parameter->type instanceof Node\Identifier
            && strtolower($parameter->type->toString()) === $type
            && (
                $nullDefault
                    ? $parameter->default instanceof Node\Expr\ConstFetch
                    && strtolower($parameter->default->name->toString()) === 'null'
                    : $parameter->default === null
            )
        );
    }

    /** @param list<Node\Param> $parameters */
    private static function resolveParameters(array $parameters): bool
    {
        if (count($parameters) !== 2) {
            return false;
        }
        [$attribute, $container] = $parameters;

        return (
            ! $attribute->byRef
            && ! $attribute->variadic
            && $attribute->default === null
            && $attribute->var instanceof Node\Expr\Variable
            && $attribute->var->name === 'attribute'
            && $attribute->type instanceof Node\Name
            && strtolower($attribute->type->toString()) === 'self'
            && ! $container->byRef
            && ! $container->variadic
            && $container->default === null
            && $container->var instanceof Node\Expr\Variable
            && $container->var->name === 'container'
            && $container->type instanceof Node\Name
            && strcasecmp($container->type->toString(), self::CONTAINER) === 0
        );
    }

    private static function nativeResolveExpression(?Node\Expr $expression): bool
    {
        if (
            ! $expression instanceof Node\Expr\MethodCall
            || ! $expression->name instanceof Node\Identifier
            || strtolower($expression->name->toString()) !== 'get'
            || count($expression->args) !== 2
            || ! $expression->var instanceof Node\Expr\MethodCall
            || ! $expression->var->name instanceof Node\Identifier
            || strtolower($expression->var->name->toString()) !== 'make'
            || ! $expression->var->var instanceof Node\Expr\Variable
            || $expression->var->var->name !== 'container'
        ) {
            return false;
        }
        $config = PhpSource::argument($expression->var->args, 0, 'abstract');
        $key = PhpSource::argument($expression->args, 0, 'key');
        $default = PhpSource::argument($expression->args, 1, 'default');

        return (
            $config instanceof Node\Scalar\String_
            && $config->value === 'config'
            && self::attributeProperty($key, 'key')
            && self::attributeProperty($default, 'default')
        );
    }

    private static function attributeProperty(?Node $node, string $name): bool
    {
        return (
            $node instanceof Node\Expr\PropertyFetch
            && $node->var instanceof Node\Expr\Variable
            && $node->var->name === 'attribute'
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === $name
        );
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments */
    private static function validArguments(array $arguments): bool
    {
        if (count($arguments) > 2) {
            return false;
        }
        $seen = [];
        foreach ($arguments as $offset => $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                && ! in_array($argument->name->toString(), ['key', 'default'], true)
            ) {
                return false;
            }
            $name = $argument->name?->toString() ?? ['key', 'default'][$offset];
            if (isset($seen[$name])) {
                return false;
            }
            $seen[$name] = true;
        }

        return true;
    }

    private static function frameworkFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/'.$suffix);
    }

    private function hasCompleteRuntime(): bool
    {
        return $this->runtimeComplete ??= self::runtimeComplete($this->root);
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
