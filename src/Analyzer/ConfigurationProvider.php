<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationWrites;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\EvaluatedRuntime;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\BeforeAnalysisContext;
use Mago\Sdk\Analyzer\BeforeAnalysisHook;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

/** Refines literal config reads without executing configuration or environment helpers. */
final class ConfigurationProvider implements FunctionReturnTypeProvider, InitializationHook, BeforeAnalysisHook, AfterAnalysisHook
{
    private ?ConfigurationIndex $index = null;
    private ?ContainerBindings $bindings = null;
    private ?bool $nativeEnvironment = null;
    private ?ConfigurationWrites $writes = null;
    private bool $buildingWrites = false;
    private int $writeGeneration = 0;
    /** @var list<Suspension<ConfigurationWrites>> */
    private array $writeWaiters = [];
    private bool $stale = false;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        ++$this->writeGeneration;
        $waiters = $this->writeWaiters;
        $this->writeWaiters = [];
        $this->buildingWrites = false;
        foreach ($waiters as $waiter) {
            $waiter->throw(new \RuntimeException('Configuration state reset during index preparation.'));
        }
        $this->index = null;
        $this->bindings = null;
        $this->nativeEnvironment = null;
        $this->writes = null;
        $this->stale = false;
    }

    public function getTargets(): array
    {
        return [FunctionTarget::exact('config')];
    }

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        // The final hook may run on a coordinator that did not handle any reads.
        if (($this->index !== null || $this->bindings !== null) && (
            $this->stale
            || ! ($this->bindings?->current() ?? true)
            || ! ($this->bindings?->dependenciesCurrent() ?? true)
            || ! ($this->index?->allCurrent() ?? true)
        )) {
            $file = $context->analysis->files[0]->file ?? null;
            if ($file === null) {
                throw new \RuntimeException('Configuration sources changed during analysis; rerun with unchanged sources.');
            }
            $context->report(
                Level::Error,
                'configuration-source-changed',
                Issue::at('Configuration sources changed during analysis; rerun with unchanged sources.', new SourceLocation($file, new Span(0, 0)))
                    ->withNote('Configuration or declared binding files, directory membership, composer.json, or composer.lock no longer match the prepared metadata.'),
            );
        }
    }

    public function beforeAnalysis(BeforeAnalysisContext $context): void
    {
        $this->bindings ??= new ContainerBindings($this->root);
        if ($this->bindings->configured('config')) {
            return;
        }
        $index = $this->index ??= new ConfigurationIndex(new PhpSource($this->root));
        // Metadata requests suspend the SDK fiber. Lazy construction in a
        // return-type callback can therefore build this index concurrently
        // before ??= assigns it. Prepare it at the lifecycle barrier instead.
        $this->writeIndex($context->codebase);
        $anchor = $this->frameworkHelper($context->codebase)?->location;
        if ($anchor !== null) {
            foreach ($index->source->warnings as $path => $message) {
                $context->report(
                    Level::Warning,
                    'configuration-unavailable',
                    Issue::at('Cannot read static configuration metadata.', $anchor)->withNote($path.': '.$message),
                );
            }
        }
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $return = $this->frameworkHelper($context->codebase)?->returnType?->type;
        // A concrete application declaration always wins over the framework helper.
        // The installed helper's inferred type is conditional on its arguments, so
        // the permissive gate accepts mixed or a conditional type and rejects
        // everything precise.
        $atom = $return?->atomicTypes[0] ?? null;
        if (
            $return === null
            || count($return->atomicTypes) !== 1
            || (! $atom instanceof MixedType && ! $atom instanceof ConditionalType)
        ) {
            return null;
        }

        return $this->literalRead($context);
    }

    /** Resolve a proven read from Laravel's application configuration singleton. */
    public function literalRead(ReturnTypeProviderContext $context): ?Type
    {
        $this->bindings ??= new ContainerBindings($this->root);
        if (! $this->bindings->current()) {
            $this->stale = true;

            return null;
        }
        if ($this->bindings->configured('config')) {
            return null;
        }
        $call = $context->invocation;
        $argument = $call->getArgument(0, 'key');
        $key = $argument?->type?->getLiteralString();
        if ($argument === null || $argument->unpacked || $argument->placeholder || $key === null) {
            return null;
        }
        $default = $call->getArgument(1, 'default');
        if ($default !== null && ($default->unpacked || $default->placeholder || $default->type === null)) {
            return null;
        }
        $index = $this->index ??= new ConfigurationIndex(new PhpSource($this->root));

        if ($this->writeIndex($context->codebase)->affects($key)) {
            // Neither a source default nor an evaluated snapshot proves the value
            // after possible runtime replacement. Keep the native mixed contract.
            return null;
        }

        $defaultType = $default?->type ?? Type::null();
        foreach ($defaultType->atomicTypes as $atom) {
            // Laravel evaluates Closure defaults. Unknown objects and callables defer.
            if (
                ! $atom instanceof ScalarType
                && ! $atom instanceof SimpleAtomicType
                && ! $atom instanceof KeyedArrayType
                && ! $atom instanceof ListType
            ) {
                $defaultType = null;
                break;
            }
        }

        if ($this->nativeEnvironment === null) {
            $this->nativeEnvironment = EnvironmentValueProvider::nativeSource($context->codebase, $index->source);
        }
        // Metadata requests above can suspend a Fiber; validate after the last one.
        if (! $this->bindings->current() || ! $index->current($key)) {
            $this->stale = true;

            return null;
        }
        $static = $index->lookup($key, $defaultType, $this->nativeEnvironment);
        if ($static !== null) {
            return $static;
        }

        return $this->evaluatedRead($key);
    }

    /** Prepare once per generation, including workers without a lifecycle callback. */
    private function writeIndex(Codebase $codebase): ConfigurationWrites
    {
        if ($this->writes !== null) {
            return $this->writes;
        }
        if ($this->buildingWrites) {
            $generation = $this->writeGeneration;
            /** @var Suspension<ConfigurationWrites> $waiter */
            $waiter = EventLoop::getSuspension();
            $this->writeWaiters[] = $waiter;

            $writes = $waiter->suspend();
            if ($generation !== $this->writeGeneration) {
                throw new \RuntimeException('Configuration generation changed before index delivery.');
            }

            return $writes;
        }
        $this->buildingWrites = true;
        $generation = $this->writeGeneration;
        try {
            $writes = new ConfigurationWrites(new PhpSource($this->root), $codebase);
            if ($generation !== $this->writeGeneration) {
                throw new \RuntimeException('Configuration generation changed during index preparation.');
            }
        } catch (Throwable $error) {
            if ($generation === $this->writeGeneration) {
                $this->buildingWrites = false;
                $waiters = $this->writeWaiters;
                $this->writeWaiters = [];
                foreach ($waiters as $waiter) {
                    $waiter->throw($error);
                }
            }
            throw $error;
        }
        $this->writes = $writes;
        $this->buildingWrites = false;
        $waiters = $this->writeWaiters;
        $this->writeWaiters = [];
        foreach ($waiters as $waiter) {
            $waiter->resume($writes);
        }

        return $writes;
    }

    /**
     * The resolved value from the opt-in evaluated runtime as the last source,
     * after explicit contracts and the static source. Only general scalar and
     * array types are produced: machine-dependent literals would make
     * diagnostics non-reproducible, and runtime configuration mutations stay
     * a documented deferral.
     */
    private function evaluatedRead(string $key): ?Type
    {
        $configuration = EvaluatedRuntime::config($this->root);
        if ($configuration === null) {
            return null;
        }
        $value = $configuration;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return match (true) {
            is_string($value) => Type::string(),
            is_int($value) => Type::int(),
            is_float($value) => Type::float(),
            is_bool($value) => Type::bool(),
            $value === null => Type::null(),
            is_array($value) => Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
            default => null,
        };
    }

    private function frameworkHelper(Codebase $codebase): ?FunctionLikeMetadata
    {
        $function = $codebase->getFunction('config');
        $file = str_replace('\\', '/', $function?->location->file ?? '');

        return str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php') ? $function : null;
    }
}
