<?php
declare(strict_types=1);
namespace Example\LocalCacheRequestedOwnerTests;

use Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof;
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, ClassLikeMetadata};

/** Test-only access to actually populated getClassLike and same-kind aliases. */
final class RequestedClassLikeAliases
{
    public static function aliases(object $codebase, array $binding): array
    {
        if (($binding['kind'] ?? null) !== 'classlike' || !is_string($binding['name'] ?? null) || $binding['name'] === '') {
            throw new \RuntimeException('A requested-owner binding must name an actual classlike query.');
        }
        return match ($binding['classLikeKind'] ?? null) {
            'Class_' => [$codebase->getClassLike($binding['name']), $codebase->getClass($binding['name'])],
            'Trait' => [$codebase->getClassLike($binding['name']), $codebase->getTrait($binding['name'])],
            default => throw new \RuntimeException('Only observed class and trait aliases are supported.'),
        };
    }

    public static function select(object $codebase, array $binding): ClassLikeMetadata
    {
        $aliases = self::aliases($codebase, $binding);
        $native = $aliases[0];
        if (!$native instanceof ClassLikeMetadata || $native->kind->name !== $binding['classLikeKind']
            || strcasecmp($native->name, $binding['name']) !== 0 || strcasecmp($native->originalName, $binding['name']) !== 0) {
            throw new \RuntimeException('The requested classlike must genuinely exist with its physical identity.');
        }
        foreach ($aliases as $alias) {
            if (!$alias instanceof ClassLikeMetadata || $alias::class !== $native::class || $alias != $native) {
                throw new \RuntimeException('Both requested classlike aliases must be present and completely equal.');
            }
        }
        return $native;
    }

    /** No synthesized DTO or guessed operation/key; replace every populated equivalent actual object. */
    public static function control(object $codebase, ClassLikeMetadata $native, ClassLikeMetadata $replacement,
        array $bindings, array $counterpart, callable $whileChanged): array
    {
        if ($bindings === [] || $native::class !== $replacement::class || $native == $replacement
            || $native->kind !== $replacement->kind || strcasecmp($native->name, $replacement->name) === 0) {
            throw new \RuntimeException('Wrong-owner control requires two distinct actual same-kind DTOs.');
        }
        $replacementBefore = self::select($codebase, $counterpart);
        if ($replacementBefore != $replacement) { throw new \RuntimeException('The actual counterpart changed before mutation.'); }
        $primed = 0;
        foreach ($bindings as $binding) {
            $current = self::select($codebase, $binding);
            if ($current != $native) { throw new \RuntimeException('A selected requested alias differs before mutation.'); }
            $primed += count(self::aliases($codebase, $binding));
        }
        $cache = (new \ReflectionProperty($codebase, 'cache'))->getValue($codebase);
        $values = $cache->values; $relations = $cache->relations; $slots = []; $counterpartSlots = [];
        try {
            foreach ($values as $operation => $entries) {
                foreach ($entries as $key => $entry) {
                    if (is_object($entry) && $entry::class === $replacement::class && $entry == $replacement) {
                        $counterpartSlots[] = ['operation' => $operation, 'key' => $key];
                    }
                    if (is_object($entry) && $entry::class === $native::class && $entry == $native) {
                        $cache->values[$operation][$key] = $replacement;
                        $slots[] = ['operation' => $operation, 'key' => $key];
                    }
                }
            }
            if ($slots === [] || $counterpartSlots === []) { throw new \RuntimeException('Both requested and counterpart aliases must actually be populated.'); }
            foreach ($bindings as $binding) {
                foreach (self::aliases($codebase, $binding) as $changed) {
                    if (!$changed instanceof ClassLikeMetadata || $changed != $replacement) {
                        throw new \RuntimeException('A genuinely primed requested lookup did not return the replacement DTO.');
                    }
                }
            }
            $whileChanged();
            if (self::select($codebase, $counterpart) != $replacementBefore) {
                throw new \RuntimeException('The counterpart declaration changed during the requested-slot mutation.');
            }
            foreach ($counterpartSlots as $slot) {
                if (($cache->values[$slot['operation']][$slot['key']] ?? null) != $replacementBefore) {
                    throw new \RuntimeException('An independently primed counterpart cache slot was modified.');
                }
            }
        } finally {
            $cache->values = $values; $cache->relations = $relations;
            foreach ($bindings as $binding) {
                if (self::select($codebase, $binding) != $native) { throw new \RuntimeException('A requested declaration did not restore.'); }
            }
            if (self::select($codebase, $counterpart) != $replacementBefore) { throw new \RuntimeException('Counterpart declaration did not restore.'); }
        }
        return ['actualPopulatedAliases' => $primed, 'slotsChanged' => count($slots), 'actualSlots' => $slots,
            'counterpartPopulatedSlots' => count($counterpartSlots), 'counterpartUnchanged' => true,
            'requestedName' => $native->name, 'replacementName' => $replacement->name, 'sameGenuineKind' => $native->kind->name,
            'meaningfulChange' => true, 'cacheValuesAndRelationsRestored' => true,
            'replacementConstructed' => false, 'counterpartWholeDtoSha256' => self::hash($replacementBefore)];
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode(DeprecatedMethodCompatibilityProof::canonical($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
