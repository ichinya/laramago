<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NamedRouteCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NamedRouteParameterContract;
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
    private readonly NamedRouteParameterContract $routeParameters;
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
        $this->routeParameters = new NamedRouteParameterContract($root);
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
        if (! $this->catalog->enabled() && ! $this->routeParameters->enabled()) {
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
        $parameters = $methodName === 'route'
            ? ($redirect ? ['route', 'parameters', 'status', 'headers'] : ['name', 'parameters', 'absolute'])
            : SignedRouteMethods::parameters($methodName, $redirect);
        $arguments = [];
        $named = false;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack) {
                return;
            }
            $parameter = $argument->name?->toString() ?? $parameters[$offset] ?? null;
            if (
                $parameter === null
                || ! in_array($parameter, $parameters, true)
                || array_key_exists($parameter, $arguments)
                || $named
                && $argument->name === null
            ) {
                return;
            }
            $arguments[$parameter] = $argument->value;
            $named = $argument->name !== null;
        }
        $name = $arguments[$parameters[0]] ?? null;
        if (! $name instanceof Node\Scalar\String_) {
            return;
        }
        if ($this->catalog->missing($name->value)) {
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
        if ($redirect && ($this->customUrlService() || ! $this->nativeUrlRoute($context))) {
            return;
        }
        $missing = $this->routeParameters->missing($name->value, $arguments['parameters'] ?? null);
        if ($missing !== null && $missing !== []) {
            $context->report(
                Level::Warning,
                'laramago-missing-named-route-parameter',
                Issue::at(
                    'Named route "'.$name->value.'" is missing required URI '.self::keyMessage($missing).'.',
                    new SourceLocation(
                        $context->source->path,
                        new Span($name->getStartFilePos(), $name->getEndFilePos() + 1),
                    ),
                ),
            );
        }
    }

    private function customUrlService(): bool
    {
        foreach ([
            'url',
            'Illuminate\\Routing\\UrlGenerator',
            'Illuminate\\Contracts\\Routing\\UrlGenerator',
        ] as $service) {
            if ($this->bindings->configured($service)) {
                return true;
            }
        }

        return false;
    }

    private function nativeUrlRoute(NodeAnalysisContext $context): bool
    {
        $method = $context->codebase->getDeclaringMethod('Illuminate\\Routing\\UrlGenerator', 'route');

        return (
            $method !== null
            && ! $method->static
            && $method->identifier->class === 'Illuminate\\Routing\\UrlGenerator'
            && str_ends_with(
                str_replace('\\', '/', $method->location->file ?? ''),
                '/laravel/framework/src/Illuminate/Routing/UrlGenerator.php',
            )
        );
    }

    /** @param list<string> $keys */
    private static function keyMessage(array $keys): string
    {
        $quoted = array_map(static fn (string $key): string => '"'.$key.'"', $keys);

        return (count($quoted) === 1 ? 'parameter ' : 'parameters ').implode(', ', $quoted);
    }
}
