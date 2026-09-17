<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * Diagnose duplicate placeholders in explicit native route declarations.
 */
final class RouteParametersHook implements MethodCallAnalysisHook
{
    private const ROUTER = 'Illuminate\\Routing\\Router';
    private const ROUTE = 'Illuminate\\Routing\\Route';

    public function getTargets(): array
    {
        $targets = [MethodTarget::exact(self::ROUTE, 'setUri')];
        foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'addRoute'] as $method) {
            $targets[] = MethodTarget::exact(self::ROUTER, $method);
        }

        return $targets;
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $atoms = $context->receiverType?->atomicTypes ?? [];
        if (count($atoms) !== 1 || ! $atoms[0] instanceof NamedObjectType) {
            return;
        }
        // Subclasses may replace route creation or URI normalization indirectly.
        if (! in_array($atoms[0]->name, [self::ROUTER, self::ROUTE], true)) {
            return;
        }
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse('<?php '.$context->source->getText($context->node).';');
        } catch (\PhpParser\Error) {
            return;
        }
        $statement = $nodes[0] ?? null;
        $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            || ! $call->name instanceof Node\Identifier
            || $call->isFirstClassCallable()
        ) {
            return;
        }
        $method = strtolower($call->name->name);
        $owner = $context->codebase->getDeclaringMethod($atoms[0]->name, $method)?->identifier->class;
        if (! in_array($owner, [self::ROUTER, self::ROUTE], true)) {
            return;
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
                $context->report(Level::Warning, 'laramago-duplicate-route-parameter', Issue::at(
                    'Route URI repeats parameter {'.$name.'}; route compilation requires unique parameter names.',
                    new SourceLocation($context->source->path, $context->node->span),
                ));

                return;
            }
            $seen[$name] = true;
        }
    }
}
