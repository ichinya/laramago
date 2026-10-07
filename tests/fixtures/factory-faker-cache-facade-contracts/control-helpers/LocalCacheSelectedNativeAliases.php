<?php
declare(strict_types=1);
namespace Example\LocalCacheFocusedTests;

use Example\NativeFixtureSupport\SelectedNativeAliases;
use Mago\Sdk\Analyzer\Type;

/** Test-only selected lookup priming; no cache key is inferred. */
final class LocalCacheSelectedNativeAliases
{
    public static function aliases(object $codebase, array $binding): array
    {
        return match ($binding['kind'] ?? null) {
            'method' => [$codebase->getMethod($binding['class'], $binding['name']),
                $codebase->getDeclaringMethod($binding['class'], $binding['name'])],
            'property' => [$codebase->getDeclaringProperty($binding['class'], $binding['name']),
                $codebase->getProperty($binding['class'], $binding['name'])],
            'class' => [$codebase->getClass($binding['name'])],
            'trait' => [$codebase->getTrait($binding['name'])],
            'interface' => [$codebase->getInterface($binding['name'])],
            'function' => [$codebase->getFunction($binding['name'])],
            default => throw new \RuntimeException('Unsupported focused selected lookup.'),
        };
    }

    public static function select(object $codebase, array $binding): object
    {
        $aliases = self::aliases($codebase, $binding);
        $native = $binding['kind'] === 'method' ? $aliases[1] : $aliases[0];
        if (!is_object($native)) { throw new \RuntimeException('A focused selected declaration is absent.'); }
        foreach ($aliases as $alias) {
            if ($alias !== null && ($alias::class !== $native::class || $alias != $native)) {
                throw new \RuntimeException('The genuine effective and declaring focused lookups differ.');
            }
        }
        return $native;
    }

    public static function copy(object $native, array $changes): object
    {
        // Type has a private payload constructor. Rebuild only its public atomic
        // representation through the real SDK factory, preserving exact flags.
        if ($native instanceof Type) {
            if (array_diff(array_keys($changes), ['atomicTypes', 'flags']) !== []) {
                throw new \RuntimeException('Only public atomic fields may change on a negative Type.');
            }
            $atoms = $changes['atomicTypes'] ?? $native->atomicTypes;
            $flags = $changes['flags'] ?? $native->flags;
            if (!is_array($atoms) || $atoms === []) { throw new \RuntimeException('A negative Type must retain an atomic representation.'); }
            return Type::fromAtomics(...array_values($atoms))->withFlags($flags);
        }
        return SelectedNativeAliases::copy($native, $changes);
    }

    /** Changes only equivalent, genuinely populated aliases, restoring both cache maps in finally. */
    public static function control(object $codebase, object $native, object $replacement,
        array $bindings, callable $whileChanged): array
    {
        if ($bindings === [] || $native::class !== $replacement::class || $native == $replacement) {
            throw new \RuntimeException('A focused mutation must change a populated actual native object.');
        }
        $populated = 0;
        foreach ($bindings as $binding) {
            $present = 0;
            foreach (self::aliases($codebase, $binding) as $alias) {
                if ($alias === null) { continue; }
                if ($alias::class !== $native::class || $alias != $native) {
                    throw new \RuntimeException('The focused selected alias changed before its mutation.');
                }
                $present++; $populated++;
            }
            if ($present === 0) { throw new \RuntimeException('A focused binding has no actual populated alias.'); }
        }
        $cache = (new \ReflectionProperty($codebase, 'cache'))->getValue($codebase);
        $values = $cache->values; $relations = $cache->relations; $slots = [];
        try {
            foreach ($values as $operation => $entries) {
                foreach ($entries as $key => $entry) {
                    if (is_object($entry) && $entry::class === $native::class && $entry == $native) {
                        $cache->values[$operation][$key] = $replacement;
                        $slots[] = ['operation' => $operation, 'key' => $key];
                    }
                }
            }
            if ($slots === []) { throw new \RuntimeException('No populated focused cache alias was changed.'); }
            $whileChanged();
        } finally {
            $cache->values = $values; $cache->relations = $relations;
        }
        return ['actualPopulatedAliases' => $populated, 'slotsChanged' => count($slots),
            'actualSlots' => $slots, 'meaningfulChange' => true, 'cacheValuesAndRelationsRestored' => true];
    }
}
