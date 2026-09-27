<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\EvaluatedRuntime;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;

/**
 * Refines literal env reads under the opt-in evaluated runtime as the last
 * source: a value resolved on this machine yields its general scalar type,
 * and an absent value yields the call's precise default. Machine-dependent
 * literals are never produced, and every other shape defers to native analysis.
 */
final class EnvironmentValueProvider implements FunctionReturnTypeProvider
{
    public function __construct(
        private readonly string $root,
    ) {}

    public function getTargets(): array
    {
        return [FunctionTarget::exact('env')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $return = $this->frameworkHelper($context->codebase)?->returnType?->type;
        // A concrete application declaration always wins over the framework helper.
        if ($return === null || count($return->atomicTypes) !== 1 || ! $return->atomicTypes[0] instanceof MixedType) {
            return null;
        }
        $call = $context->invocation;
        $argument = $call->getArgument(0, 'key');
        $key = $argument?->type?->getLiteralString();
        if (
            $argument === null
            || $argument->unpacked
            || $argument->placeholder
            || $key === null
            || $key === ''
        ) {
            return null;
        }
        $default = $call->getArgument(1, 'default');
        if ($default !== null && ($default->unpacked || $default->placeholder || $default->type === null)) {
            return null;
        }
        $entry = EvaluatedRuntime::envEntry($this->root, $key);
        if ($entry === null) {
            return null;
        }
        if ($entry['found']) {
            return match (true) {
                is_string($entry['value']) => Type::string(),
                is_int($entry['value']) => Type::int(),
                is_float($entry['value']) => Type::float(),
                is_bool($entry['value']) => Type::bool(),
                $entry['value'] === null => Type::null(),
                default => null,
            };
        }
        // An absent variable resolves to the call's default: the helper's own
        // null when no default argument is given, matching the missing-key
        // semantics of the config provider.
        if ($default === null) {
            return Type::null();
        }
        $defaultType = $default->type;
        foreach ($defaultType->atomicTypes as $atom) {
            // Non-scalar default arguments keep native behavior; Laravel's env
            // helper itself never produces arrays, so they stay unproven here.
            if (
                ! $atom instanceof ScalarType
                && ! $atom instanceof SimpleAtomicType
                && ! $atom instanceof KeyedArrayType
                && ! $atom instanceof ListType
            ) {
                return null;
            }
        }

        return $defaultType;
    }

    private function frameworkHelper(Codebase $codebase): ?\Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata
    {
        $function = $codebase->getFunction('env');
        $file = str_replace('\\', '/', $function?->location->file ?? '');

        return str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php') ? $function : null;
    }
}
