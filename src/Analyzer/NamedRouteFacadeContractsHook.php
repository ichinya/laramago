<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NamedRouteCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
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

/** Check literal names only when native URL/Redirect facade dispatch is proven. */
final class NamedRouteFacadeContractsHook implements NodeAnalysisHook
{
    private readonly NamedRouteCatalog $catalog;
    private readonly ContainerBindings $bindings;
    private readonly NativeFacade $facade;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(string $root = '.')
    {
        $this->catalog = new NamedRouteCatalog($root);
        $this->bindings = new ContainerBindings($root);
        $this->facade = new NativeFacade($root);
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
        if (! $this->catalog->enabled()) {
            return;
        }
        $call = $this->call($context);
        if (
            $call === null
            || ! $call->class instanceof Node\Name
            || ! $call->name instanceof Node\Identifier
            || strtolower($call->name->toString()) !== 'route'
            || $call->isFirstClassCallable()
        ) {
            return;
        }
        $class = strtolower($call->class->toString());
        $redirect = $class === 'illuminate\\support\\facades\\redirect';
        if (! $redirect && $class !== 'illuminate\\support\\facades\\url') {
            return;
        }
        $services = ['url', 'Illuminate\\Routing\\UrlGenerator', 'Illuminate\\Contracts\\Routing\\UrlGenerator'];
        if ($redirect) {
            $services = [...$services, 'redirect', 'Illuminate\\Routing\\Redirector'];
        }
        foreach ($services as $service) {
            if ($this->bindings->configured($service)) {
                return;
            }
        }
        if (! $this->facade->dispatchesClass(
            $context->codebase,
            $redirect ? 'Illuminate\\Support\\Facades\\Redirect' : 'Illuminate\\Support\\Facades\\URL',
            $redirect ? 'redirect' : 'url',
            $redirect ? 'Illuminate\\Routing\\Redirector' : 'Illuminate\\Routing\\UrlGenerator',
            'route',
        )) {
            return;
        }
        // Redirector forwards its route name to the installed URL generator.
        if ($redirect) {
            $method = $context->codebase->getDeclaringMethod('Illuminate\\Routing\\UrlGenerator', 'route');
            if (
                $method === null
                || $method->static
                || strcasecmp($method->identifier->class ?? '', 'Illuminate\\Routing\\UrlGenerator') !== 0
                || ! str_ends_with(
                    str_replace('\\', '/', $method->location->file ?? ''),
                    '/laravel/framework/src/Illuminate/Routing/UrlGenerator.php',
                )
            ) {
                return;
            }
        }
        $parameters = $redirect ? ['route', 'parameters', 'status', 'headers'] : ['name', 'parameters', 'absolute'];
        $arguments = [];
        $named = false;
        foreach ($call->getArgs() as $offset => $argument) {
            $parameter = $argument->name?->toString() ?? $parameters[$offset] ?? null;
            if (
                $argument->unpack
                || $parameter === null
                || ! in_array($parameter, $parameters, true)
                || isset($arguments[$parameter])
                || $named
                && $argument->name === null
            ) {
                return;
            }
            $named = $argument->name !== null;
            $arguments[$parameter] = $argument->value;
        }
        $name = $arguments[$parameters[0]] ?? null;
        if (! $name instanceof Node\Scalar\String_ || ! $this->catalog->missing($name->value)) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-named-route',
            Issue::at(
                'Named route "'.$name->value.'" is absent from the explicitly complete named-routes catalog.',
                new SourceLocation(
                    $context->source->path,
                    new Span($name->getStartFilePos(), $name->getEndFilePos() + 1),
                ),
            ),
        );
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\StaticCall
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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
