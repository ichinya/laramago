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
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
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

/** Diagnose missing literal disks only under an explicit closed-runtime contract. */
final class StorageDiskContractsHook implements NodeAnalysisHook, InitializationHook
{
    private const ATTRIBUTE = 'Illuminate\\Container\\Attributes\\Storage';
    private const FACADE = 'Illuminate\\Support\\Facades\\Storage';
    private const MANAGER = 'Illuminate\\Filesystem\\FilesystemManager';

    private ?bool $runtimeComplete = null;
    private ?NativeFacade $facade = null;
    private ?ContainerBindings $bindings = null;
    private ?PhpSource $source = null;
    private ?ConfigurationIndex $configuration = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Attribute|Node\Expr\StaticCall> */
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
        return [NodeKind::Attribute, NodeKind::StaticMethodCall];
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
        if ($reference instanceof Node\Expr\StaticCall) {
            if (self::hasUnpackedArgument($reference->args)) {
                return;
            }
            if (
                ! $this->facade()->dispatchesClass(
                    $context->codebase,
                    self::FACADE,
                    'filesystem',
                    self::MANAGER,
                    'disk',
                )
            ) {
                return;
            }
            $name = PhpSource::argument($reference->args, 0, 'name');
        } elseif ($reference instanceof Node\Attribute) {
            if (self::hasUnpackedArgument($reference->args) || ! $this->nativeAttribute($context)) {
                return;
            }
            $name = PhpSource::argument($reference->args, 0, 'disk');
        } else {
            return;
        }
        if (
            ! $name instanceof Node\Scalar\String_
            || $name->value === ''
            || $name->value === '0'
        ) {
            return;
        }
        if ($this->bindings()->configured('config') || $this->bindings()->configured('filesystem')) {
            return;
        }
        $catalog = $this->configuration()->stringKeys('filesystems.disks');
        if ($catalog === null || ! $catalog->sourceComplete || in_array($name->value, $catalog->keys, true)) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-storage-disk',
            Issue::at(
                'Storage disk "'.$name->value.'" is absent from the explicitly complete runtime disk catalog.',
                new SourceLocation(
                    $context->source->path,
                    new Span($name->getStartFilePos(), $name->getEndFilePos() + 1),
                ),
            ),
        );
    }

    private function reference(NodeAnalysisContext $context): Node\Attribute|Node\Expr\StaticCall|null
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
            foreach ((new NodeFinder)->find(
                $nodes,
                static fn (Node $node): bool => (
                    $node instanceof Node\Attribute
                    || $node instanceof Node\Expr\StaticCall
                ),
            ) as $reference) {
                if (! $reference instanceof Node\Attribute && ! $reference instanceof Node\Expr\StaticCall) {
                    continue;
                }
                $this->references[$reference->getStartFilePos().':'.($reference->getEndFilePos() + 1)] = $reference;
            }
        }
        $reference = $this->references[$context->node->span->start.':'.$context->node->span->end] ?? null;
        if ($reference instanceof Node\Attribute) {
            return strcasecmp($reference->name->toString(), self::ATTRIBUTE) === 0 ? $reference : null;
        }

        return $reference instanceof Node\Expr\StaticCall
        && $reference->class instanceof Node\Name
        && strcasecmp($reference->class->toString(), self::FACADE) === 0
        && $reference->name instanceof Node\Identifier
        && strtolower($reference->name->name) === 'disk'
        && ! $reference->isFirstClassCallable()
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
            || $constructor === null
            || $resolve === null
            || strcasecmp($constructor->identifier->class ?? '', self::ATTRIBUTE) !== 0
            || strcasecmp($resolve->identifier->class ?? '', self::ATTRIBUTE) !== 0
            || ! $constructor->constructor
            || $constructor->static
            || ! $resolve->static
            || ! self::frameworkFile($constructor->location->file, 'Illuminate/Container/Attributes/Storage.php')
            || ! self::frameworkFile($resolve->location->file, 'Illuminate/Container/Attributes/Storage.php')
            || ! self::nativeManager($context)
        ) {
            return false;
        }
        $reflection = new StaticAnalysis\ModelReflection($context->codebase, $this->source());
        $constructorNode = $reflection->methodNode($constructor);
        $resolveNode = $reflection->methodNode($resolve);
        $parameter = $constructorNode?->params[0] ?? null;
        if (
            $constructorNode === null
            || count($constructorNode->params) !== 1
            || $constructorNode->stmts !== []
            || ! $parameter instanceof Node\Param
            || ! $parameter->isPromoted()
            || ! $parameter->isPublic()
            || ! $parameter->var instanceof Node\Expr\Variable
            || $parameter->var->name !== 'disk'
        ) {
            return false;
        }
        $statements = $resolveNode?->stmts;
        $expression =
            is_array($statements) && count($statements) === 1 && $statements[0] instanceof Node\Stmt\Return_
                ? $statements[0]->expr
                : null;

        return self::nativeResolveExpression($expression);
    }

    private static function nativeManager(NodeAnalysisContext $context): bool
    {
        $method = $context->codebase->getMethod(self::MANAGER, 'disk') ?? $context->codebase->getDeclaringMethod(
            self::MANAGER,
            'disk',
        );

        return (
            $method !== null
            && strcasecmp($method->identifier->class ?? '', self::MANAGER) === 0
            && ! $method->static
            && self::frameworkFile($method->location->file, 'Illuminate/Filesystem/FilesystemManager.php')
        );
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments */
    private static function hasUnpackedArgument(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Node\Arg && $argument->unpack) {
                return true;
            }
        }

        return false;
    }

    private static function nativeResolveExpression(?Node\Expr $expression): bool
    {
        if (
            ! $expression instanceof Node\Expr\MethodCall
            || ! $expression->name instanceof Node\Identifier
            || strtolower($expression->name->toString()) !== 'disk'
            || count($expression->args) !== 1
            || ! $expression->var instanceof Node\Expr\MethodCall
            || ! $expression->var->name instanceof Node\Identifier
            || strtolower($expression->var->name->toString()) !== 'make'
            || ! $expression->var->var instanceof Node\Expr\Variable
            || $expression->var->var->name !== 'container'
        ) {
            return false;
        }
        $filesystem = PhpSource::argument($expression->var->args, 0, 'abstract');
        $disk = PhpSource::argument($expression->args, 0, 'name');

        return (
            $filesystem instanceof Node\Scalar\String_
            && $filesystem->value === 'filesystem'
            && $disk instanceof Node\Expr\PropertyFetch
            && $disk->var instanceof Node\Expr\Variable
            && $disk->var->name === 'attribute'
            && $disk->name instanceof Node\Identifier
            && $disk->name->toString() === 'disk'
        );
    }

    private static function frameworkFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/'.$suffix);
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
        $contract = is_array($settings) ? $settings['storage-disks'] ?? null : null;

        return (
            is_array($contract)
            && ($contract['complete'] ?? null) === true
            && ($contract['runtime-disks-unchanged'] ?? null) === true
        );
    }
}
