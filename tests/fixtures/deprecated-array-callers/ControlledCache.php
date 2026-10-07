<?php
declare(strict_types=1);
namespace Example\DeprecatedMethods;

/** Test-only replacement of one already witnessed real SDK object. Never exports the cache. */
final class ControlledCache
{
    public static function copy(object $old, array $changes): object
    {
        $reflection = new \ReflectionClass($old);
        $args = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) { $name = $parameter->getName(); $args[] = array_key_exists($name, $changes) ? $changes[$name] : $old->$name; }
        return $reflection->newInstanceArgs($args);
    }
    public static function replace(object $codebase, object $old, object $replacement): array
    {
        $cache = (new \ReflectionProperty($codebase, 'cache'))->getValue($codebase);
        $changed = [];
        foreach ($cache->values as $group => &$values) { foreach ($values as $key => &$value) { if ($value === $old) { $value = $replacement; $changed[] = [$group, $key]; } } unset($value); } unset($values);
        if ($changed === []) { throw new \RuntimeException('The selected native SDK object was not present in its real cache.'); }
        return $changed;
    }
    public static function restore(object $codebase, object $original, array $slots): void
    {
        $cache = (new \ReflectionProperty($codebase, 'cache'))->getValue($codebase);
        foreach ($slots as [$group, $key]) { $cache->values[$group][$key] = $original; }
    }
}
