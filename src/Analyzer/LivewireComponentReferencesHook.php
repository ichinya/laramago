<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
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
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Check unambiguous literal Livewire names only under an asserted complete effective catalog. */
final class LivewireComponentReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private const FACADE = 'Livewire\\Livewire';
    private const MANAGER = 'Livewire\\LivewireManager';
    private const FACADE_BASE = 'Illuminate\\Support\\Facades\\Facade';

    /** @var array<array-key, true>|null */
    private ?array $names = null;
    private bool $catalogLoaded = false;
    private ?PhpSource $source = null;
    private ?ContainerBindings $bindings = null;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->names = null;
        $this->catalogLoaded = false;
        $this->source = null;
        $this->bindings = null;
        $this->sourceHash = '';
        $this->calls = [];
    }

    public function getTargets(): array
    {
        $targets = [];
        foreach ([self::FACADE, self::MANAGER] as $class) {
            foreach (['test', 'mount', 'new'] as $method) {
                $targets[] = MethodTarget::exact($class, $method);
            }
        }

        return $targets;
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $names = $this->names();
        if ($names === null) {
            return;
        }
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable() || ! $call->name instanceof Node\Identifier) {
            return;
        }
        $method = strtolower($call->name->name);
        if (! in_array($method, ['test', 'mount', 'new'], true) || ! $this->nativeDispatch($context, $call, $method)) {
            return;
        }
        $value = null;
        $position = 0;
        $named = false;
        foreach ($call->getArgs() as $argument) {
            if ($argument->unpack) {
                return;
            }
            if ($argument->name === null) {
                if ($named) {
                    return;
                }
                if ($position === 0) {
                    $value = $argument->value;
                }
                $position++;
            } else {
                $named = true;
                if ($argument->name->name === 'name') {
                    if ($value !== null) {
                        return;
                    }
                    $value = $argument->value;
                }
            }
        }
        // A PHP string can also name a component class. Dots, hyphens and the
        // Livewire namespace separator cannot occur in a PHP class name.
        if (
            ! $value instanceof Node\Scalar\String_
            || ! preg_match('/[.:-]/', $value->value)
            || isset($names[$value->value])
        ) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-livewire-component',
            Issue::at(
                'Livewire component "'
                .$value->value
                .'" is absent from the explicitly complete effective livewire-components catalog.',
                new SourceLocation($context->source->path, $context->node->span),
            ),
        );
    }

    /** @return array<array-key, true>|null */
    private function names(): ?array
    {
        if ($this->catalogLoaded) {
            return $this->names;
        }
        $this->catalogLoaded = true;
        $contents = @file_get_contents($this->root.'/composer.json');
        if ($contents === false) {
            return null;
        }
        try {
            /** @var mixed $composer */
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($composer)) {
            return null;
        }
        /** @var mixed $extra */
        $extra = $composer['extra'] ?? null;
        /** @var mixed $settings */
        $settings = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $references */
        $references = is_array($settings) ? $settings['reference-catalogs'] ?? null : null;
        /** @var mixed $catalog */
        $catalog = is_array($references) ? $references['livewire-components'] ?? null : null;
        /** @var mixed $names */
        $names = is_array($catalog) ? $catalog['names'] ?? null : null;
        if (
            ! is_array($catalog)
            || ($catalog['complete'] ?? null) !== true
            || ! is_array($names)
            || ! array_is_list($names)
        ) {
            return null;
        }
        $known = [];
        /** @var mixed $name */
        foreach ($names as $name) {
            if (! is_string($name) || $name === '' || isset($known[$name])) {
                return null;
            }
            $known[$name] = true;
        }

        return $this->names = $known;
    }

    private function nativeDispatch(
        NodeAnalysisContext $context,
        Node\Expr\MethodCall|Node\Expr\StaticCall $call,
        string $method,
    ): bool {
        if ($call instanceof Node\Expr\StaticCall) {
            if (! $call->class instanceof Node\Name || strcasecmp($call->class->toString(), self::FACADE) !== 0) {
                return false;
            }
            $facade = $context->codebase->getClassLike(self::FACADE);
            $accessor = $context->codebase->getDeclaringMethod(self::FACADE, 'getFacadeAccessor');
            if (
                $facade === null
                || $facade->hasIncompleteHierarchy()
                || ! self::packageFile($facade->location->file, 'Livewire.php')
                || ($this->bindings ??= new ContainerBindings($this->root))->configured(self::MANAGER)
                || $accessor === null
                || strcasecmp($accessor->identifier->class ?? '', self::FACADE) !== 0
                || ! $accessor->static
                || PhpSource::value(
                    $this->reflection($context)->returnExpression($accessor),
                    self::FACADE,
                    self::FACADE,
                ) !== self::MANAGER
            ) {
                return false;
            }
            $declared = $context->codebase->getMethod(self::FACADE, $method) ?? $context->codebase->getDeclaringMethod(
                self::FACADE,
                $method,
            );
            if (
                $declared !== null
                && strcasecmp($declared->identifier->class ?? '', self::FACADE) === 0
                && $this->reflection($context)->methodNode($declared) !== null
            ) {
                return false;
            }
            foreach (['__callStatic', 'getFacadeRoot', 'resolveFacadeInstance'] as $name) {
                $metadata = $context->codebase->getDeclaringMethod(self::FACADE, $name);
                if (
                    $metadata === null
                    || strcasecmp($metadata->identifier->class ?? '', self::FACADE_BASE) !== 0
                    || ! $metadata->static
                    || ! str_ends_with(
                        str_replace('\\', '/', $metadata->location->file ?? ''),
                        '/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
                    )
                ) {
                    return false;
                }
            }
        } else {
            $receiver = $context->receiverType?->atomicTypes[0] ?? null;
            if (
                $context->receiverType === null
                || count($context->receiverType->atomicTypes) !== 1
                || ! $receiver instanceof NamedObjectType
                || strcasecmp($receiver->name, self::MANAGER) !== 0
            ) {
                return false;
            }
        }
        $metadata = $context->codebase->getDeclaringMethod(self::MANAGER, $method);
        if (
            $metadata === null
            || $metadata->static
            || strcasecmp($metadata->identifier->class ?? '', self::MANAGER) !== 0
            || ! self::packageFile($metadata->location->file, 'LivewireManager.php')
            || in_array($method, ['new', 'mount'], true)
            && $context->codebase->getFunction('Livewire\\app') !== null
        ) {
            return false;
        }
        $node = $this->reflection($context)->methodNode($metadata);
        if ($node?->stmts === null) {
            return false;
        }
        $actual = clone $node;
        $actual->setAttribute('comments', []);
        $printed = (new Standard)->prettyPrint([$actual]);
        foreach (self::nativeMethods($method) as $canonical) {
            if ($printed === $canonical) {
                return true;
            }
        }

        return false;
    }

    private function reflection(NodeAnalysisContext $context): ModelReflection
    {
        return new ModelReflection($context->codebase, $this->source ??= new PhpSource($this->root));
    }

    private static function packageFile(?string $file, string $basename): bool
    {
        return $file !== null
        && str_ends_with(
            str_replace('\\', '/', $file),
            '/livewire/livewire/src/'.$basename,
        );
    }

    /** @return list<string> */
    private static function nativeMethods(string $method): array
    {
        // From livewire/livewire 3.x 1de96cea and 4.x 5e4cd636 (MIT).
        $sources = match ($method) {
            'new' => [
                'function new($name, $id = null) { return app(ComponentRegistry::class)->new($name, $id); }',
                "function new(\$name, \$id = null) { return app('livewire.factory')->create(\$name, \$id); }",
            ],
            'mount' => [
                'function mount($name, $params = [], $key = null) { return app(HandleComponents::class)->mount($name, $params, $key); }',
                'function mount($name, $params = [], $key = null, $slots = []) { return app(HandleComponents::class)->mount($name, $params, $key, $slots); }',
            ],
            'test' => [
                'function test($name, $params = []) { return Testable::create($name, $params, $this->queryParamsForTesting, $this->cookiesForTesting, $this->headersForTesting); }',
            ],
            default => [],
        };
        $printer = new Standard;
        $result = [];
        foreach ($sources as $source) {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse(
                    '<?php namespace Livewire;'
                    .' use Livewire\\Features\\SupportTesting\\Testable;'
                    .' use Livewire\\Mechanisms\\ComponentRegistry;'
                    .' use Livewire\\Mechanisms\\HandleComponents\\HandleComponents;'
                    .' class Expected { '
                    .$source
                    .' }',
                );
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            $candidate = (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\ClassMethod::class);
            if ($candidate instanceof Node\Stmt\ClassMethod) {
                $result[] = $printer->prettyPrint([$candidate]);
            }
        }

        return $result;
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

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
