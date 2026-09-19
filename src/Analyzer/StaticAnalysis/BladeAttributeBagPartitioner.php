<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Partitions complete, already parsed attribute keys without evaluating values or Blade. */
final class BladeAttributeBagPartitioner
{
    /**
     * Apply ComponentAttributeBag::extractPropNames and Blade's @props extraction.
     * A missing/uncertain directive or incomplete attributes cannot prove a bag.
     *
     * @param list<string> $attributeNames Final post-parse keys, including escaped and Alpine keys.
     */
    public function anonymous(
        BladePropsMetadata $props,
        array $attributeNames,
        bool $complete,
    ): ?BladeAttributePartition {
        if (! $props->hasDirective || ! $complete || ! self::validAttributeNames($attributeNames)) {
            return null;
        }

        $accepted = [];
        $ambiguous = [];
        foreach ($props->declarations as $declaration) {
            foreach ([$declaration->name, self::kebab($declaration->name)] as $alias) {
                if (isset($accepted[$alias]) && $accepted[$alias] !== $declaration->name) {
                    $ambiguous[$alias] = true;
                } else {
                    $accepted[$alias] = $declaration->name;
                }
            }
        }

        /** @var array<string, string> $matched */
        $matched = [];
        /** @var list<string> $bag */
        $bag = [];
        foreach ($attributeNames as $name) {
            if (isset($ambiguous[$name])) {
                return null;
            }
            if (isset($accepted[$name])) {
                $matched[$name] = $accepted[$name];
            } else {
                $bag[] = $name;
            }
        }

        return new BladeAttributePartition($matched, $bag);
    }

    /**
     * Apply ComponentTagCompiler::partitionDataAndAttributes for a proven class.
     * This uses the constructor declaration only; it cannot model custom resolvers.
     *
     * @param list<string> $attributeNames Final post-parse keys, including escaped and Alpine keys.
     */
    public function componentClass(
        BladeClassComponent $component,
        array $attributeNames,
        bool $complete,
    ): ?BladeAttributePartition {
        if (! $complete || $component->constructor === null || ! self::validAttributeNames($attributeNames)) {
            return null;
        }

        $parameters = [];
        foreach ($component->constructor as $parameter) {
            $parameters[$parameter['name']] = true;
        }

        /** @var array<string, string> $matched */
        $matched = [];
        /** @var list<string> $bag */
        $bag = [];
        foreach ($attributeNames as $name) {
            // Laravel's Str::camel is Unicode-aware. Do not guess its result for
            // names outside the ASCII tag subset handled here.
            if (preg_match('/[^\x00-\x7F]/', $name) === 1) {
                return null;
            }
            $canonical = self::camel($name);
            if (isset($parameters[$canonical])) {
                $matched[$name] = $canonical;
            } else {
                $bag[] = $name;
            }
        }

        return new BladeAttributePartition($matched, $bag);
    }

    /** @param list<string> $names */
    private static function validAttributeNames(array $names): bool
    {
        $seen = [];
        foreach ($names as $name) {
            if ($name === '' || isset($seen[$name])) {
                return false;
            }
            $seen[$name] = true;
        }

        return true;
    }

    private static function kebab(string $name): string
    {
        if (ctype_lower($name)) {
            return $name;
        }

        // The literal @props parser accepts ASCII identifiers only. This is
        // Str::snake($name, '-') for that subset (used by Str::kebab).
        return strtolower(preg_replace('/(.)(?=[A-Z])/', '$1-', ucwords($name)) ?? $name);
    }

    private static function camel(string $name): string
    {
        $words = preg_split('/[-_\s]+/', $name, -1, PREG_SPLIT_NO_EMPTY);

        return lcfirst(implode('', array_map(ucfirst(...), $words ?: [])));
    }
}
