<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\MacroIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
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
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Diagnose literal Inertia helper and route components only with installed forwarding contracts.
 * Native contract excerpts derive from inertiajs/inertia-laravel (MIT); see
 * tests/fixtures/analysis/inertia-LICENSE.md.
 */
final class InertiaEntryReferencesHook implements NodeAnalysisHook, InitializationHook
{
    private const FACADE = 'Inertia\\Inertia';
    private const FACTORY = 'Inertia\\ResponseFactory';
    private const ROUTE = 'Illuminate\\Support\\Facades\\Route';
    private const ROUTER = 'Illuminate\\Routing\\Router';

    private ReferenceCatalogs $catalogs;
    private PhpSource $source;
    private ContainerBindings $bindings;
    private MacroIndex $macros;
    private NativeFacade $facade;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\FuncCall|Node\Expr\StaticCall> */
    private array $calls = [];
    private ?bool $nativeHelper = null;
    private ?bool $nativeRoute = null;

    public function __construct(
        private readonly string $root = '.',
    ) {
        $this->reset();
    }

    public function initialize(InitializationContext $context): void
    {
        $this->reset();
    }

    private function reset(): void
    {
        $this->catalogs = new ReferenceCatalogs($this->root);
        $this->source = new PhpSource($this->root);
        $this->bindings = new ContainerBindings($this->root);
        $this->macros = new MacroIndex($this->root);
        $this->facade = new NativeFacade($this->root);
        $this->sourceHash = null;
        $this->calls = [];
        $this->nativeHelper = null;
        $this->nativeRoute = null;
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
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable()) {
            return;
        }
        if ($call instanceof Node\Expr\FuncCall) {
            if (! $this->nativeHelper($context, $call)) {
                return;
            }
            $component = self::argument($call->args, ['component', 'props'], 'component');
            // The official helper returns its factory for falsey components.
            if (! $component instanceof Node\Scalar\String_ || ! (bool) $component->value) {
                return;
            }
        } else {
            if (! $this->nativeRoute($context, $call)) {
                return;
            }
            $component = self::argument($call->args, ['uri', 'component', 'props'], 'component');
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
                new SourceLocation(
                    $context->source->path,
                    new Span($component->getStartFilePos(), $component->getEndFilePos() + 1),
                ),
            ),
        );
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args
     *  @param list<string> $parameters
     */
    private static function argument(array $args, array $parameters, string $target): ?Node\Expr
    {
        if (count($args) > count($parameters)) {
            return null;
        }
        $seen = [];
        $named = false;
        foreach ($args as $offset => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return null;
            }
            $parameter = $argument->name?->toString() ?? $parameters[$offset] ?? null;
            if (
                $parameter === null
                || ! in_array($parameter, $parameters, true)
                || isset($seen[$parameter])
                || $named
                && $argument->name === null
            ) {
                return null;
            }
            $named = $argument->name !== null;
            $seen[$parameter] = $argument->value;
        }

        return $seen[$target] ?? null;
    }

    private function nativeHelper(NodeAnalysisContext $context, Node\Expr\FuncCall $call): bool
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
        if (strcasecmp(ltrim($name, '\\'), 'inertia') !== 0) {
            return false;
        }
        if ($this->nativeHelper !== null) {
            return $this->nativeHelper;
        }
        $function = $context->codebase->getFunction('inertia');
        $file = $function?->location->file;
        if (
            $function === null
            || ! self::inertiaFile($file, 'helpers.php', false)
            || $function->flags->contains(MetadataFlags::BY_REFERENCE)
            || array_map(static fn ($parameter): string => $parameter->name, $function->parameters) !== [
                '$component',
                '$props',
            ]
            || ! $this->nativeFactory($context)
            || ! $this->nativeInertiaFacade($context)
        ) {
            return $this->nativeHelper = false;
        }
        $node = (new NodeFinder)->findFirst(
            $this->source->read(self::readable($file)) ?? [],
            static fn (Node $node): bool => $node instanceof Node\Stmt\Function_ && $node->name->name === 'inertia',
        );
        $expected = $this->expected(
            '<?php use Inertia\\Inertia; function inertia($component = null, $props = []) {'
            .' $instance = Inertia::getFacadeRoot();'
            .' if ($component) { return $instance->render($component, $props); }'
            .' return $instance; }',
            Node\Stmt\Function_::class,
        );

        return $this->nativeHelper =
            $node instanceof Node\Stmt\Function_
            && $expected instanceof Node\Stmt\Function_
            && preg_match('/@param\s+null\|string\s+\$component\b/', $node->getDocComment()?->getText() ?? '') === 1
            && preg_match('/@param\s+array\|Arrayable\s+\$props\b/', $node->getDocComment()?->getText() ?? '') === 1
            && preg_match(
                '/@return\s+\(\$component is null \? ResponseFactory : Response\)/',
                $node->getDocComment()?->getText() ?? '',
            ) === 1
            && self::same($node, $expected);
    }

    private function nativeRoute(NodeAnalysisContext $context, Node\Expr\StaticCall $call): bool
    {
        if (
            ! $call->class instanceof Node\Name
            || strcasecmp($call->class->toString(), self::ROUTE) !== 0
            || ! $call->name instanceof Node\Identifier
            || strcasecmp($call->name->name, 'inertia') !== 0
        ) {
            return false;
        }
        if ($this->nativeRoute !== null) {
            return $this->nativeRoute;
        }
        if (
            ! $this->catalogs->inertiaRouteMacroActive()
            || $this->bindings->configured('router')
            || $this->bindings->configured(self::ROUTER)
            || $this->macros->hasUnknownRegistrations()
            || $this->macros->mayRegister(self::ROUTER, 'inertia')
            || $this->macros->mayRegister(self::ROUTE, 'inertia')
            || ! $this->facade->dispatchesClass($context->codebase, self::ROUTE, 'router', self::ROUTER, 'match')
            || ! $this->nativeFactory($context)
            || ! $this->nativeInertiaFacade($context)
        ) {
            return $this->nativeRoute = false;
        }
        $route = $context->codebase->getClassLike(self::ROUTE);
        $router = $context->codebase->getClassLike(self::ROUTER);
        $provider = $context->codebase->getClassLike('Inertia\\ServiceProvider');
        $controller = $context->codebase->getDeclaringMethod('Inertia\\Controller', '__invoke');
        if (
            $route === null
            || $router === null
            || $provider === null
            || ! self::laravelFile($route->location->file, 'Illuminate/Support/Facades/Route.php')
            || ! self::laravelFile($router->location->file, 'Illuminate/Routing/Router.php')
            || ! self::inertiaFile($provider->location->file, 'ServiceProvider.php')
            || $controller === null
            || strcasecmp($controller->identifier->class ?? '', 'Inertia\\Controller') !== 0
            || ! self::inertiaFile($controller->location->file, 'Controller.php')
            || $context->codebase->getDeclaringMethod(self::ROUTER, 'inertia') !== null
            || $context->codebase->getDeclaringMethod(self::ROUTE, 'inertia') !== null
        ) {
            return $this->nativeRoute = false;
        }
        $reflection = new ModelReflection($context->codebase, $this->source);
        $body = $reflection->methodNode($controller);
        $expectedController = $this->expected(
            '<?php namespace Inertia; use Illuminate\\Http\\Request;'
            .' class Controller { public function __invoke(Request $request): Response {'
            .' return Inertia::render($request->route()->defaults[\'component\'],'
            .' $request->route()->defaults[\'props\']); } }',
            Node\Stmt\ClassMethod::class,
        );
        if (
            ! $body instanceof Node\Stmt\ClassMethod
            || ! $expectedController instanceof Node\Stmt\ClassMethod
            || ! self::same($body, $expectedController)
        ) {
            return $this->nativeRoute = false;
        }
        $providerNode = (new NodeFinder)->findFirst(
            $this->source->read(self::readable($provider->location->file)) ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Class_
                && strcasecmp($node->namespacedName?->toString() ?? '', 'Inertia\\ServiceProvider') === 0
            ),
        );
        if (! $providerNode instanceof Node\Stmt\Class_) {
            return $this->nativeRoute = false;
        }
        $register = $providerNode->getMethod('register');
        $macro = $providerNode->getMethod('registerRouterMacro');
        if (
            $register === null
            || $macro === null
            || ! $register->isPublic()
            || ! $macro->isProtected()
            || ! self::callsRegistration($register)
            || ! $this->nativeRouterMacro($macro)
        ) {
            return $this->nativeRoute = false;
        }

        return $this->nativeRoute = true;
    }

    private static function callsRegistration(Node\Stmt\ClassMethod $register): bool
    {
        foreach ($register->stmts ?? [] as $statement) {
            if (! $statement instanceof Node\Stmt\Expression) {
                // An earlier return, throw, or conditional cannot prove registration.
                return false;
            }
            if (
                $statement->expr instanceof Node\Expr\MethodCall
                && $statement->expr->var instanceof Node\Expr\Variable
                && $statement->expr->var->name === 'this'
                && $statement->expr->name instanceof Node\Identifier
                && $statement->expr->name->name === 'registerRouterMacro'
                && $statement->expr->args === []
            ) {
                return true;
            }
        }

        return false;
    }

    private function nativeRouterMacro(Node\Stmt\ClassMethod $method): bool
    {
        $statements = $method->stmts;
        if ($statements === null || count($statements) !== 1 || ! $statements[0] instanceof Node\Stmt\Expression) {
            return false;
        }
        $registration = $statements[0]->expr;
        if (
            ! $registration instanceof Node\Expr\StaticCall
            || ! $registration->class instanceof Node\Name
            || strcasecmp($registration->class->toString(), self::ROUTER) !== 0
            || ! $registration->name instanceof Node\Identifier
            || $registration->name->name !== 'macro'
            || count($registration->args) !== 2
            || ! $registration->args[0] instanceof Node\Arg
            || ! $registration->args[0]->value instanceof Node\Scalar\String_
            || $registration->args[0]->value->value !== 'inertia'
            || ! $registration->args[1] instanceof Node\Arg
            || ! $registration->args[1]->value instanceof Node\Expr\Closure
        ) {
            return false;
        }
        $closure = $registration->args[1]->value;
        if (count($closure->stmts) !== 3 || $closure->byRef || $closure->static) {
            return false;
        }
        $expected = $this->expected(
            '<?php namespace Inertia; use Illuminate\\Routing\\Router;'
            .' use Inertia\\DevTools\\DevTools; use Inertia\\DevTools\\SourceLocator;'
            .' class ServiceProvider { function registerRouterMacro() {'
            .' Router::macro(\'inertia\', function ($uri, $component, $props = []) {'
            .' $route = $this->match([\'GET\', \'HEAD\'], $uri, \'\\\\\'.Controller::class)'
            .'->defaults(\'component\', $component)->defaults(\'props\', $props);'
            .' if (DevTools::enabled()) { $source = app(SourceLocator::class)->captureCallerSource();'
            .' if ($source !== null) { $route->defaults(DevTools::RENDER_SOURCE_KEY, $source); } }'
            .' return $route; }); } }',
            Node\Expr\Closure::class,
        );

        return $expected instanceof Node\Expr\Closure && self::same($closure, $expected);
    }

    private function nativeFactory(NodeAnalysisContext $context): bool
    {
        $method = $context->codebase->getDeclaringMethod(self::FACTORY, 'render');
        if (
            $method === null
            || strcasecmp($method->identifier->class ?? '', self::FACTORY) !== 0
            || $method->static
            || ! self::inertiaFile($method->location->file, 'ResponseFactory.php')
            || $this->bindings->configured(self::FACTORY)
        ) {
            return false;
        }
        $reflection = new ModelReflection($context->codebase, $this->source);
        $node = $reflection->methodNode($method);
        $expected = $this->expected(<<<'PHP'
            <?php
            namespace Inertia;
            use BackedEnum;
            use UnitEnum;
            use Illuminate\Contracts\Support\Arrayable;
            use InvalidArgumentException;
            use Inertia\DevTools\DevTools;
            class ResponseFactory {
                public function render($component, $props = []): Response {
                    $component = $this->transformComponent($component);
                    $component = match (true) {
                        $component instanceof BackedEnum => $component->value,
                        $component instanceof UnitEnum => $component->name,
                        default => $component,
                    };
                    if (! is_string($component)) {
                        throw new InvalidArgumentException('Component argument must be of type string or a string BackedEnum');
                    }
                    if (config('inertia.pages.ensure_pages_exist', false)) {
                        $this->findComponentOrFail($component);
                    }
                    if ($props instanceof Arrayable) {
                        $props = $props->toArray();
                    } elseif ($props instanceof ProvidesInertiaProperties) {
                        $props = [$props];
                    }
                    $response = new Response(
                        $component,
                        $this->sharedProps,
                        $props,
                        $this->rootView,
                        $this->getVersion(),
                        $this->encryptHistory ?? config('inertia.history.encrypt', false),
                        $this->urlResolver,
                    );
                    DevTools::recorder()?->pageRendering($component, $response, $this->sharedProps);
                    return $response;
                }
            }
            PHP, Node\Stmt\ClassMethod::class);

        return (
            $node instanceof Node\Stmt\ClassMethod
            && $expected instanceof Node\Stmt\ClassMethod
            && $node->isPublic()
            && preg_match(
                '/@param\s+BackedEnum\|UnitEnum\|string\s+\$component\b/',
                $node->getDocComment()?->getText() ?? '',
            ) === 1
            && self::same($node, $expected)
        );
    }

    private function nativeInertiaFacade(NodeAnalysisContext $context): bool
    {
        $class = $context->codebase->getClassLike(self::FACADE);
        if (
            $class === null
            || $class->hasIncompleteHierarchy()
            || ! self::inertiaFile($class->location->file, 'Inertia.php')
        ) {
            return false;
        }
        $reflection = new ModelReflection($context->codebase, $this->source);
        $render = $context->codebase->getMethod(self::FACADE, 'render') ?? $context->codebase->getDeclaringMethod(
            self::FACADE,
            'render',
        );
        if (
            $render !== null
            && strcasecmp($render->identifier->class ?? '', self::FACADE) === 0
            && $reflection->methodNode($render) !== null
        ) {
            return false;
        }
        $accessor = $context->codebase->getDeclaringMethod(self::FACADE, 'getFacadeAccessor');
        $root = $context->codebase->getDeclaringMethod(self::FACADE, 'getFacadeRoot');

        return (
            $accessor !== null
            && strcasecmp($accessor->identifier->class ?? '', self::FACADE) === 0
            && self::inertiaFile($accessor->location->file, 'Inertia.php')
            && PhpSource::value($reflection->returnExpression($accessor), self::FACADE, self::FACADE) === self::FACTORY
            && $root !== null
            && strcasecmp($root->identifier->class ?? '', NativeFacade::BASE) === 0
            && $root->static
            && self::laravelFile($root->location->file, 'Illuminate/Support/Facades/Facade.php')
        );
    }

    private function call(NodeAnalysisContext $context): Node\Expr\FuncCall|Node\Expr\StaticCall|null
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
                    $node instanceof Node\Expr\FuncCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            ) as $call) {
                if ($call instanceof Node\Expr\FuncCall || $call instanceof Node\Expr\StaticCall) {
                    $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
                }
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }

    /** @param class-string<Node> $class */
    private function expected(string $source, string $class): ?Node
    {
        $nodes = (new ParserFactory)
            ->createForNewestSupportedVersion()
            ->parse($source);
        $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);

        return (new NodeFinder)->findFirst($nodes, static fn (Node $node): bool => $node instanceof $class);
    }

    private static function same(Node $actual, Node $expected): bool
    {
        $printer = new Standard;
        $actual = clone $actual;
        self::stripComments($actual);
        self::stripComments($expected);

        return $printer->prettyPrint([$actual]) === $printer->prettyPrint([$expected]);
    }

    private static function stripComments(Node $node): void
    {
        (new NodeTraverser(new class extends NodeVisitorAbstract {
            public function enterNode(Node $node): ?Node
            {
                $node->setAttribute('comments', []);

                return null;
            }
        }))->traverse([$node]);
    }

    private static function inertiaFile(?string $path, string $name, bool $inSrc = true): bool
    {
        return str_ends_with(
            str_replace('\\', '/', $path ?? ''),
            '/inertiajs/inertia-laravel/'.($inSrc ? 'src/' : '').$name,
        );
    }

    private static function laravelFile(?string $path, string $name): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/'.$name);
    }

    private static function readable(?string $path): string
    {
        $path ??= '';

        return str_starts_with($path, '//?/') ? substr($path, 4) : $path;
    }
}
