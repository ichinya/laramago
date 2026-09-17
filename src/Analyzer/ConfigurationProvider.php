<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationIndex;
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

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->index = null;
    }

    public function getTargets(): array
    {
        return [FunctionTarget::exact('config')];
    }

    public function beforeAnalysis(BeforeAnalysisContext $context): void
    {
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
        $call = $context->invocation;
        $return = $this->frameworkHelper($context->codebase)?->returnType?->type;
        // A concrete application declaration always wins over the framework helper.
        if ($return === null || count($return->atomicTypes) !== 1 || ! $return->atomicTypes[0] instanceof MixedType) {
            return null;
        }
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

        return $index->lookup($key, $defaultType);
    }

    private function frameworkHelper(Codebase $codebase): ?FunctionLikeMetadata
    {
        $function = $codebase->getFunction('config');
        $file = str_replace('\\', '/', $function?->location->file ?? '');

        return str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php') ? $function : null;
    }
}
