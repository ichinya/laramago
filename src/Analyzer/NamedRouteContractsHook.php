<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NamedRouteCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\SignedRouteMethods;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Missing named routes require an explicit complete application catalog. */
final class NamedRouteContractsHook implements MethodCallAnalysisHook
{
    private readonly NamedRouteCatalog $catalog;
    private readonly ContainerBindings $bindings;
    private readonly SignedRouteMethods $signed;

    private string $sourceHash = '';

    /**
     * @var array<int, Node\Expr\MethodCall>
     */
    private array $calls = [];

    public function __construct(string $root = '.')
    {
        $this->catalog = new NamedRouteCatalog($root);
        $this->bindings = new ContainerBindings($root);
        $this->signed = new SignedRouteMethods($root);
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact('Illuminate\\Routing\\UrlGenerator', 'route'),
            MethodTarget::exact('Illuminate\\Routing\\Redirector', 'route'),
            MethodTarget::exact('Illuminate\\Routing\\UrlGenerator', 'signedRoute'),
            MethodTarget::exact('Illuminate\\Routing\\UrlGenerator', 'temporarySignedRoute'),
            MethodTarget::exact('Illuminate\\Routing\\Redirector', 'signedRoute'),
            MethodTarget::exact('Illuminate\\Routing\\Redirector', 'temporarySignedRoute'),
        ];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (! $this->catalog->enabled()) {
            return;
        }
        $atoms = $context->receiverType?->atomicTypes ?? [];
        if (
            count($atoms) !== 1
            || ! $atoms[0] instanceof NamedObjectType
            || ! in_array(
                $atoms[0]->name,
                ['Illuminate\\Routing\\UrlGenerator', 'Illuminate\\Routing\\Redirector'],
                true,
            )
        ) {
            return;
        }
        $owner = $context->codebase->getDeclaringMethod($atoms[0]->name, 'route');
        if (
            $owner === null
            || $owner->identifier->class !== $atoms[0]->name
            || $owner->static
            || ! str_ends_with(
                str_replace('\\', '/', $owner->location->file ?? ''),
                '/laravel/framework/src/'.str_replace('\\', '/', $atoms[0]->name).'.php',
            )
        ) {
            return;
        }
        $hash = hash('sha256', $context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                foreach ((new NodeFinder)->findInstanceOf($nodes ?? [], Node\Expr\MethodCall::class) as $node) {
                    $this->calls[$node->getStartFilePos()] = $node;
                }
            } catch (\PhpParser\Error) {
                return;
            }
        }
        $call = $this->calls[$context->node->span->start] ?? null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            || ($call->getEndFilePos() + 1) !== $context->node->span->end
            || ! $call->name instanceof Node\Identifier
            || ! in_array(strtolower($call->name->name), ['route', 'signedroute', 'temporarysignedroute'], true)
            || $call->isFirstClassCallable()
        ) {
            return;
        }
        $methodName = strtolower($call->name->name);
        $redirect = $atoms[0]->name === 'Illuminate\\Routing\\Redirector';
        if ($methodName !== 'route') {
            if (! $this->signed->native($context->codebase, $methodName, $redirect)) {
                return;
            }
            if ($redirect) {
                foreach ([
                    'url',
                    'Illuminate\\Routing\\UrlGenerator',
                    'Illuminate\\Contracts\\Routing\\UrlGenerator',
                ] as $service) {
                    if ($this->bindings->configured($service)) {
                        return;
                    }
                }
            }
        }
        $name = null;
        $parameters = $methodName === 'route' ? null : SignedRouteMethods::parameters($methodName, $redirect);
        $arguments = [];
        $named = false;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack) {
                return;
            }
            if ($parameters !== null) {
                $parameter = $argument->name?->toString() ?? $parameters[$offset] ?? null;
                if (
                    $parameter === null
                    || ! in_array($parameter, $parameters, true)
                    || isset($arguments[$parameter])
                    || $named
                    && $argument->name === null
                ) {
                    return;
                }
                $arguments[$parameter] = true;
                $named = $argument->name !== null;
            }
            if (
                $argument->name === null
                && $offset === 0
                || $argument->name !== null
                && $argument->name->name
                    === ($atoms[0]->name === 'Illuminate\\Routing\\UrlGenerator' ? 'name' : 'route')
            ) {
                $name = $argument->value;
            }
        }
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
                    $methodName === 'route'
                        ? $context->node->span
                        : new Span($name->getStartFilePos(), $name->getEndFilePos() + 1),
                ),
            ),
        );
    }
}
