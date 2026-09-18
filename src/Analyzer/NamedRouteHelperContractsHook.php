<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NamedRouteCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
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

/** Diagnose missing literal route names on Laravel's native route helpers. */
final class NamedRouteHelperContractsHook implements NodeAnalysisHook
{
    private readonly NamedRouteCatalog $catalog;
    private readonly ContainerBindings $bindings;
    private readonly PhpSource $source;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\FuncCall> */
    private array $calls = [];
    /** @var array<string, bool> */
    private array $native = [];

    public function __construct(string $root = '.')
    {
        $this->catalog = new NamedRouteCatalog($root);
        $this->bindings = new ContainerBindings($root);
        $this->source = new PhpSource($root);
    }

    public function getTargets(): array
    {
        return [NodeKind::FunctionCall];
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
        if ($call === null || $call->isFirstClassCallable()) {
            return;
        }
        $functionName = $this->functionName($context, $call);
        $helper = strtolower($functionName);
        if (! in_array($helper, ['route', 'to_route'], true)) {
            return;
        }
        if (
            ! $this->nativeHelper($context, $helper)
            || $this->customService($helper)
        ) {
            return;
        }
        $parameters = $helper === 'route'
            ? ['name', 'parameters', 'absolute']
            : ['route', 'parameters', 'status', 'headers'];
        $name = $this->routeName($call, $parameters);
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

    private function functionName(NodeAnalysisContext $context, Node\Expr\FuncCall $call): string
    {
        $name = $call->name instanceof Node\Name ? $call->name->toString() : '';
        /** @var mixed $namespaced */
        $namespaced = $call->name instanceof Node\Name ? $call->name->getAttribute('namespacedName') : null;
        if ($namespaced instanceof Node\Name && $context->codebase->getFunction($namespaced->toString()) !== null) {
            return $namespaced->toString();
        }

        return $name;
    }

    private function nativeHelper(NodeAnalysisContext $context, string $helper): bool
    {
        if (array_key_exists($helper, $this->native)) {
            return $this->native[$helper];
        }
        $function = $context->codebase->getFunction($helper);
        $file = $function?->location->file;
        $expectedParameters = $helper === 'route'
            ? ['$name', '$parameters', '$absolute']
            : ['$route', '$parameters', '$status', '$headers'];
        if (
            $function === null
            || $file === null
            || ! str_ends_with(
                str_replace('\\', '/', $file),
                '/laravel/framework/src/Illuminate/Foundation/helpers.php',
            )
            || array_map(static fn ($parameter): string => $parameter->name, $function->parameters)
                !== $expectedParameters
            || ! $this->nativeDispatch($context, $helper)
        ) {
            return $this->native[$helper] = false;
        }
        $node = (new NodeFinder)->findFirst(
            $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file) ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Function_
                && strtolower($node->name->toString()) === $helper
            ),
        );
        if (
            ! $node instanceof Node\Stmt\Function_
            || $node->byRef
            || count($node->params) !== count($expectedParameters)
            || count($node->stmts) !== 1
            || ! $node->stmts[0] instanceof Node\Stmt\Return_
            || ! $node->stmts[0]->expr instanceof Node\Expr\MethodCall
        ) {
            return $this->native[$helper] = false;
        }
        foreach ($node->params as $offset => $parameter) {
            if (
                $parameter->byRef
                || $parameter->variadic
                || ! $parameter->var instanceof Node\Expr\Variable
                || ! is_string($parameter->var->name)
                || '$'.$parameter->var->name !== $expectedParameters[$offset]
            ) {
                return $this->native[$helper] = false;
            }
        }
        $forward = $node->stmts[0]->expr;
        if (
            ! $forward->name instanceof Node\Identifier
            || strtolower($forward->name->toString()) !== 'route'
            || count($forward->args) !== count($expectedParameters)
            || ! $forward->var instanceof Node\Expr\FuncCall
            || ! $forward->var->name instanceof Node\Name
        ) {
            return $this->native[$helper] = false;
        }
        $dispatcher = strtolower($forward->var->name->toString());
        if (
            $helper === 'route'
            && ($dispatcher !== 'app'
            || count($forward->var->args) !== 1
            || ! $forward->var->args[0] instanceof Node\Arg
            || ! $forward->var->args[0]->value instanceof Node\Scalar\String_
            || $forward->var->args[0]->value->value !== 'url')
            || $helper === 'to_route'
            && ($dispatcher !== 'redirect'
            || $forward->var->args !== [])
        ) {
            return $this->native[$helper] = false;
        }
        foreach ($forward->args as $offset => $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || ! $argument->value instanceof Node\Expr\Variable
                || ! is_string($argument->value->name)
                || '$'.$argument->value->name !== $expectedParameters[$offset]
            ) {
                return $this->native[$helper] = false;
            }
        }

        return $this->native[$helper] = true;
    }

    private function customService(string $helper): bool
    {
        $services = $helper === 'route'
            ? ['url', 'Illuminate\\Routing\\UrlGenerator', 'Illuminate\\Contracts\\Routing\\UrlGenerator']
            : [
                'redirect',
                'Illuminate\\Routing\\Redirector',
                'url',
                'Illuminate\\Routing\\UrlGenerator',
                'Illuminate\\Contracts\\Routing\\UrlGenerator',
            ];
        foreach ($services as $service) {
            if ($this->bindings->configured($service)) {
                return true;
            }
        }

        return false;
    }

    private function nativeDispatch(NodeAnalysisContext $context, string $helper): bool
    {
        foreach ($helper === 'route' ? ['app'] : ['app', 'redirect'] as $dispatcher) {
            $function = $context->codebase->getFunction($dispatcher);
            $parameters = $dispatcher === 'app'
                ? ['$abstract', '$parameters']
                : ['$to', '$status', '$headers', '$secure'];
            if (
                $function === null
                || ! str_ends_with(
                    str_replace('\\', '/', $function->location->file ?? ''),
                    '/laravel/framework/src/Illuminate/Foundation/helpers.php',
                )
                || array_map(static fn ($parameter): string => $parameter->name, $function->parameters) !== $parameters
            ) {
                return false;
            }
        }
        $classes = $helper === 'route'
            ? ['Illuminate\\Routing\\UrlGenerator']
            : ['Illuminate\\Routing\\Redirector', 'Illuminate\\Routing\\UrlGenerator'];
        foreach ($classes as $class) {
            $method = $context->codebase->getDeclaringMethod($class, 'route');
            if (
                $method === null
                || strcasecmp($method->identifier->class ?? '', $class) !== 0
                || $method->static
                || ! str_ends_with(
                    str_replace('\\', '/', $method->location->file ?? ''),
                    '/laravel/framework/src/'.str_replace('\\', '/', $class).'.php',
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $parameters */
    private function routeName(Node\Expr\FuncCall $call, array $parameters): ?Node\Expr
    {
        if (count($call->args) > count($parameters)) {
            return null;
        }
        $name = null;
        foreach ($call->args as $offset => $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                && ! in_array($argument->name->toString(), $parameters, true)
            ) {
                return null;
            }
            if (
                $argument->name === null
                && $offset === 0
                || $argument->name?->toString() === $parameters[0]
            ) {
                if ($name !== null) {
                    return null;
                }
                $name = $argument->value;
            }
        }

        return $name;
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\FuncCall
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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
