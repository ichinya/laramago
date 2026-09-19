<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\InertiaLiteralPropTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\InertiaUiModalProof;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
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
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Diagnose missing literal pages on the installed native Inertia render entry points. */
final class InertiaPageReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private const FACADE = 'Inertia\\Inertia';
    private const FACTORY = 'Inertia\\ResponseFactory';
    private const FACADE_BASE = 'Illuminate\\Support\\Facades\\Facade';

    private ?ReferenceCatalogs $catalogs = null;
    private ?PhpSource $source = null;
    private ?ContainerBindings $bindings = null;
    private ?InertiaUiModalProof $modal = null;
    private ?InertiaLiteralPropTypes $propTypes = null;
    private ?bool $nativeFactoryProps = null;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->catalogs = null;
        $this->source = null;
        $this->bindings = null;
        $this->modal = null;
        $this->propTypes = null;
        $this->nativeFactoryProps = null;
        $this->sourceHash = '';
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::FACADE, 'render'),
            MethodTarget::exact(self::FACTORY, 'render'),
            MethodTarget::exact(self::FACADE, 'modal'),
            MethodTarget::exact(self::FACTORY, 'modal'),
        ];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable() || ! $call->name instanceof Node\Identifier) {
            return;
        }
        $modal = strcasecmp($call->name->name, 'modal') === 0;
        if ($modal && $call->name->name !== 'modal') {
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
        if ($modal && ! $this->modal()->supports($context->codebase)) {
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
        if (! $component instanceof Node\Scalar\String_) {
            return;
        }
        $catalogs = $this->catalogs();
        $ambiguousPaths = $catalogs->ambiguousInertiaPagePaths($component->value);
        if ($ambiguousPaths !== null) {
            $context->report(
                Level::Warning,
                'laramago-ambiguous-inertia-page',
                Issue::at(
                    'Inertia page "'
                    .$component->value
                    .'" matches multiple files despite the unique inertia-pages assertion: '
                    .implode(', ', $ambiguousPaths)
                    .'.',
                    new SourceLocation($context->source->path, $context->node->span),
                ),
            );

            return;
        }
        $known = $catalogs->containsInertiaPage($component->value);
        if ($known === true) {
            $this->checkRequiredProps($context, $call, $component->value, $catalogs);
            $this->checkLiteralPropTypes($context, $call, $component->value);

            return;
        }
        if ($known !== false) {
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

    private function checkLiteralPropTypes(
        NodeAnalysisContext $context,
        Node\Expr\MethodCall|Node\Expr\StaticCall $call,
        string $page,
    ): void {
        if (! $call->name instanceof Node\Identifier || strcasecmp($call->name->name, 'render') !== 0) {
            return;
        }
        $contracts = $this->propTypes ??= new InertiaLiteralPropTypes($this->root);
        $expected = $contracts->forPage($page);
        if ($expected === [] || ! $this->factoryMergesProps($context)) {
            return;
        }
        foreach ($this->calls as $other) {
            if ($other instanceof Node\Expr\MethodCall && $other->var === $call) {
                return;
            }
        }
        $actual = $contracts->literalProps($call);
        if ($actual === null) {
            return;
        }
        foreach ($actual as $name => $kind) {
            if (! isset($expected[$name]) || in_array($kind, $expected[$name], true)) {
                continue;
            }
            $context->report(
                Level::Warning,
                'laramago-incompatible-inertia-prop',
                Issue::at(
                    'Inertia page "'
                    .$page
                    .'" prop "'
                    .$name
                    .'" has literal JSON type '
                    .$kind
                    .'; its explicit frontend contract expects '
                    .implode('|', $expected[$name])
                    .'.',
                    new SourceLocation($context->source->path, $context->node->span),
                ),
            );
        }
    }

    private function checkRequiredProps(
        NodeAnalysisContext $context,
        Node\Expr\MethodCall|Node\Expr\StaticCall $call,
        string $page,
        ReferenceCatalogs $catalogs,
    ): void {
        if (! $call->name instanceof Node\Identifier || strcasecmp($call->name->name, 'render') !== 0) {
            return;
        }
        $contract = $catalogs->inertiaRequiredProps($page);
        if (
            $contract === null
            || $contract['required'] === []
            || $this->chainedRenderCall($call)
            || ! $this->factoryMergesProps($context)
        ) {
            return;
        }
        $provided = $this->literalPropNames($call);
        if ($provided === null) {
            return;
        }
        $available = array_fill_keys([...$provided, ...$contract['shared']], true);
        foreach ($contract['required'] as $required) {
            if (isset($available[$required])) {
                continue;
            }
            $context->report(
                Level::Warning,
                'laramago-missing-inertia-prop',
                Issue::at(
                    'Inertia page "'
                    .$page
                    .'" requires prop "'
                    .$required
                    .'" under the explicit full-page props contract.',
                    new SourceLocation($context->source->path, $context->node->span),
                ),
            );
        }
    }

    /** @return list<string>|null */
    private function literalPropNames(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?array
    {
        $props = null;
        $assigned = [];
        $position = 0;
        $named = false;
        foreach ($call->getArgs() as $argument) {
            if ($argument->unpack) {
                return null;
            }
            if ($argument->name === null) {
                if ($named || $position > 1) {
                    return null;
                }
                $parameter = $position === 0 ? 'component' : 'props';
                $position++;
            } else {
                $named = true;
                $parameter = $argument->name->name;
                if ($parameter !== 'component' && $parameter !== 'props') {
                    return null;
                }
            }
            if (isset($assigned[$parameter])) {
                return null;
            }
            $assigned[$parameter] = true;
            if ($parameter === 'props') {
                $props = $argument->value;
            }
        }
        if ($props === null) {
            return [];
        }
        if (! $props instanceof Node\Expr\Array_) {
            return null;
        }
        $names = [];
        foreach ($props->items as $item) {
            if (
                $item->unpack
                || ! $item->key instanceof Node\Scalar\String_
                || str_contains($item->key->value, '.')
            ) {
                return null;
            }
            $names[] = $item->key->value;
        }

        return $names;
    }

    private function chainedRenderCall(Node\Expr\MethodCall|Node\Expr\StaticCall $call): bool
    {
        foreach ($this->calls as $other) {
            if ($other instanceof Node\Expr\MethodCall && $other->var === $call) {
                return true;
            }
        }

        return false;
    }

    private function factoryMergesProps(NodeAnalysisContext $context): bool
    {
        // Same native 3.x render body used by InertiaEntryReferencesHook (MIT).
        if ($this->nativeFactoryProps !== null) {
            return $this->nativeFactoryProps;
        }
        $method = $context->codebase->getDeclaringMethod(self::FACTORY, 'render');
        if ($method === null) {
            return $this->nativeFactoryProps = false;
        }
        $node = (new ModelReflection($context->codebase, $this->source()))->methodNode($method);
        $expectedSource = <<<'PHP'
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
            PHP;
        $expected = (new ParserFactory)
            ->createForNewestSupportedVersion()
            ->parse($expectedSource) ?? [];
        $expected = (new NodeTraverser(new NameResolver))->traverse($expected);
        $expectedMethod = (new NodeFinder)->findFirstInstanceOf($expected, Node\Stmt\ClassMethod::class);
        if (! $node instanceof Node\Stmt\ClassMethod || ! $expectedMethod instanceof Node\Stmt\ClassMethod) {
            return $this->nativeFactoryProps = false;
        }
        $printer = new Standard;
        $actual = clone $node;
        $expectedMethod = clone $expectedMethod;
        $stripComments = static function (Node $method): void {
            (new NodeTraverser(new class extends NodeVisitorAbstract {
                public function enterNode(Node $node): ?Node
                {
                    $node->setAttribute('comments', []);

                    return null;
                }
            }))->traverse([$method]);
        };
        $stripComments($actual);
        $stripComments($expectedMethod);

        return $this->nativeFactoryProps = $printer->prettyPrint([$actual]) === $printer->prettyPrint([
            $expectedMethod,
        ]);
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
            || $this->bindings()->configured(self::FACTORY)
        ) {
            return false;
        }
        $reflection = new ModelReflection($context->codebase, $this->source());
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
            || ! in_array(strtolower($call->name->name), ['render', 'modal'], true)
        ) {
            return null;
        }

        return $call;
    }

    private function catalogs(): ReferenceCatalogs
    {
        return $this->catalogs ??= new ReferenceCatalogs($this->root);
    }

    private function source(): PhpSource
    {
        return $this->source ??= new PhpSource($this->root);
    }

    private function bindings(): ContainerBindings
    {
        return $this->bindings ??= new ContainerBindings($this->root);
    }

    private function modal(): InertiaUiModalProof
    {
        return $this->modal ??= new InertiaUiModalProof($this->root, $this->source());
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
