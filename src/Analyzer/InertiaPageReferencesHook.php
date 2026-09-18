<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Diagnose missing literal pages on the installed native Inertia render entry points. */
final class InertiaPageReferencesHook implements MethodCallAnalysisHook
{
    private const FACADE = 'Inertia\\Inertia';
    private const FACTORY = 'Inertia\\ResponseFactory';
    private const FACADE_BASE = 'Illuminate\\Support\\Facades\\Facade';

    private readonly ReferenceCatalogs $catalogs;
    private readonly PhpSource $source;
    private readonly ContainerBindings $bindings;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {
        $this->catalogs = new ReferenceCatalogs($root);
        $this->source = new PhpSource($root);
        $this->bindings = new ContainerBindings($root);
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::FACADE, 'render'), MethodTarget::exact(self::FACTORY, 'render')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable()) {
            return;
        }
        $receiver = $context->receiverType?->atomicTypes[0] ?? null;
        if ($call instanceof Node\Expr\StaticCall) {
            if (
                ! $call->class instanceof Node\Name
                || strcasecmp($call->class->toString(), self::FACADE) !== 0
                || ! $this->nativeDispatch($context, self::FACADE)
            ) {
                return;
            }
        } elseif (
            $context->receiverType === null
            || count($context->receiverType->atomicTypes) !== 1
            || ! $receiver instanceof NamedObjectType
            || $receiver->name !== self::FACTORY
            || ! $this->nativeDispatch($context, self::FACTORY)
        ) {
            return;
        }
        $component = null;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack) {
                return;
            }
            if (
                $argument->name === null
                && $offset === 0
                || $argument->name !== null
                && $argument->name->name === 'component'
            ) {
                $component = $argument->value;
            }
        }
        if (
            ! $component instanceof Node\Scalar\String_
            || $this->catalogs->containsInertiaPage($component->value) !== false
        ) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-inertia-page',
            Issue::at(
                'Inertia page "'.$component->value.'" is absent from the explicitly complete inertia-pages catalog.',
                new SourceLocation($context->source->path, $context->node->span),
            ),
        );
    }

    private function nativeDispatch(NodeAnalysisContext $context, string $receiver): bool
    {
        $factory = $context->codebase->getDeclaringMethod(self::FACTORY, 'render');
        if (
            $factory === null
            || strcasecmp($factory->identifier->class ?? '', self::FACTORY) !== 0
            || $factory->static
            || ! self::inertiaFile($factory->location->file, 'ResponseFactory.php')
        ) {
            return false;
        }
        if ($receiver === self::FACTORY) {
            return true;
        }
        $facade = $context->codebase->getClassLike(self::FACADE);
        if (
            $facade === null
            || $facade->hasIncompleteHierarchy()
            || ! self::inertiaFile($facade->location->file, 'Inertia.php')
            || $this->bindings->configured(self::FACTORY)
        ) {
            return false;
        }
        $reflection = new ModelReflection($context->codebase, $this->source);
        $declared = $context->codebase->getMethod(self::FACADE, 'render') ?? $context->codebase->getDeclaringMethod(
            self::FACADE,
            'render',
        );
        if (
            $declared !== null
            && strcasecmp($declared->identifier->class ?? '', self::FACADE) === 0
            && $reflection->methodNode($declared) !== null
        ) {
            return false;
        }
        $accessor = $context->codebase->getDeclaringMethod(self::FACADE, 'getFacadeAccessor');
        if (
            $accessor === null
            || strcasecmp($accessor->identifier->class ?? '', self::FACADE) !== 0
            || ! $accessor->static
            || ! self::inertiaFile($accessor->location->file, 'Inertia.php')
            || PhpSource::value($reflection->returnExpression($accessor), self::FACADE, self::FACADE) !== self::FACTORY
        ) {
            return false;
        }
        foreach (['__callStatic', 'getFacadeRoot', 'resolveFacadeInstance'] as $method) {
            $metadata = $context->codebase->getDeclaringMethod(self::FACADE, $method);
            if (
                $metadata === null
                || strcasecmp($metadata->identifier->class ?? '', self::FACADE_BASE) !== 0
                || ! $metadata->static
                || ! self::laravelFacadeFile($metadata->location->file)
            ) {
                return false;
            }
        }

        return true;
    }

    private function call(NodeAnalysisContext $context): Node\Expr\MethodCall|Node\Expr\StaticCall|null
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
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
                    $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            ) as $call) {
                if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) {
                    continue;
                }
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }
        $call = $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            && ! $call instanceof Node\Expr\StaticCall
            || ! $call->name instanceof Node\Identifier
            || strcasecmp($call->name->name, 'render') !== 0
        ) {
            return null;
        }

        return $call;
    }

    private static function inertiaFile(?string $path, string $file): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/inertiajs/inertia-laravel/src/'.$file);
    }

    private static function laravelFacadeFile(?string $path): bool
    {
        return str_ends_with(
            str_replace('\\', '/', $path ?? ''),
            '/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
        );
    }
}
