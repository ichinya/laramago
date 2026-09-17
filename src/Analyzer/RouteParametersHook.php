<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
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

/**
 * Diagnose duplicate placeholders in explicit native route declarations.
 */
final class RouteParametersHook implements MethodCallAnalysisHook
{
    private const ROUTER = 'Illuminate\\Routing\\Router';
    private const ROUTE = 'Illuminate\\Routing\\Route';
    private const FACADE = 'Illuminate\\Support\\Facades\\Route';

    private readonly NativeFacade $facade;

    public function __construct(string $root = '.')
    {
        $this->facade = new NativeFacade($root);
    }

    public function getTargets(): array
    {
        $targets = [MethodTarget::exact(self::ROUTE, 'setUri')];
        foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'addRoute'] as $method) {
            $targets[] = MethodTarget::exact(self::ROUTER, $method);
            $targets[] = MethodTarget::exact(self::FACADE, $method);
        }

        return $targets;
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($context->source->contents);
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
        } catch (\PhpParser\Error) {
            return;
        }
        $call = (new NodeFinder)->findFirst(
            $nodes,
            static fn (Node $node): bool => (
                ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
                && $node->getStartFilePos() === $context->node->span->start
                && ($node->getEndFilePos() + 1) === $context->node->span->end
            ),
        );
        if (
            ! $call instanceof Node\Expr\MethodCall
            && ! $call instanceof Node\Expr\StaticCall
            || ! $call->name instanceof Node\Identifier
            || $call->isFirstClassCallable()
        ) {
            return;
        }
        $method = strtolower($call->name->name);
        if ($call instanceof Node\Expr\StaticCall) {
            if (
                ! $call->class instanceof Node\Name
                || strcasecmp($call->class->toString(), self::FACADE) !== 0
            ) {
                return;
            }
            if (! $this->facade->dispatchesClass($context->codebase, self::FACADE, 'router', self::ROUTER, $method)) {
                return;
            }
        } else {
            $atoms = $context->receiverType?->atomicTypes ?? [];
            if (
                count($atoms) !== 1
                || ! $atoms[0] instanceof NamedObjectType
                || ! in_array($atoms[0]->name, [self::ROUTER, self::ROUTE], true)
            ) {
                return;
            }
            // Subclasses may replace route creation or URI normalization indirectly.
            $owner = $context->codebase->getDeclaringMethod($atoms[0]->name, $method)?->identifier->class;
            if (! in_array($owner, [self::ROUTER, self::ROUTE], true)) {
                return;
            }
        }
        $position = in_array($method, ['match', 'addroute'], true) ? 1 : 0;
        $uri = null;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack) {
                return;
            }
            if (
                $argument->name === null
                && $offset === $position
                || $argument->name !== null
                && $argument->name->name === 'uri'
            ) {
                $uri = $argument->value;
            }
        }
        if (! $uri instanceof Node\Scalar\String_) {
            return;
        }
        // Match only conventional ASCII placeholders. Binding fields and optional markers
        // are removed by Laravel before Symfony compiles the route pattern.
        $matches = [];
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(?::[A-Za-z_][A-Za-z0-9_]*)?\??\}/', $uri->value, $matches);
        $seen = [];
        foreach ($matches[1] as $name) {
            if (isset($seen[$name])) {
                $context->report(
                    Level::Warning,
                    'laramago-duplicate-route-parameter',
                    Issue::at(
                        'Route URI repeats parameter {'.$name.'}; route compilation requires unique parameter names.',
                        new SourceLocation($context->source->path, $context->node->span),
                    ),
                );

                return;
            }
            $seen[$name] = true;
        }
    }
}
