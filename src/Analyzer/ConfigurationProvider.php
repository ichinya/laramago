<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationWrites;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\EvaluatedRuntime;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
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

/** Refines literal config reads without executing configuration or environment helpers. */
final class ConfigurationProvider implements FunctionReturnTypeProvider, InitializationHook, BeforeAnalysisHook
{
    private ?ConfigurationIndex $index = null;
    private ?ContainerBindings $bindings = null;
    private ?bool $nativeEnvironment = null;
    private ?ConfigurationWrites $writes = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->index = null;
        $this->bindings = null;
        $this->nativeEnvironment = null;
        $this->writes = null;
    }

    public function getTargets(): array
    {
        return [FunctionTarget::exact('config')];
    }

    public function beforeAnalysis(BeforeAnalysisContext $context): void
    {
        $this->bindings ??= new ContainerBindings($this->root);
        if ($this->bindings->configured('config')) {
            return;
        }
        $index = $this->index ??= new ConfigurationIndex(new PhpSource($this->root));
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

        $this->writes ??= new ConfigurationWrites(new PhpSource($this->root), $context->codebase);
        if ($this->writes->affects($key)) {
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
        $static = $index->lookup($key, $defaultType, $this->nativeEnvironment);
        if ($static !== null) {
            return $static;
        }

        return $this->evaluatedRead($key);
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
