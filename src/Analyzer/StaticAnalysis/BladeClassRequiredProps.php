<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Constructor candidates and explicit Blade prop checks, without runtime DI assumptions. */
final class BladeClassRequiredProps
{
    /**
     * Parameters without syntactic defaults are only candidates: Laravel may obtain
     * them from the container, contextual bindings, nullable injection or a resolver.
     *
     * @return list<string>|null Null means the constructor declaration is unknown.
     */
    public function candidates(BladeClassComponent $component): ?array
    {
        if ($component->constructor === null) {
            return null;
        }

        $names = [];
        foreach ($component->constructor as $parameter) {
            if (! $parameter['hasDefault'] && ! $parameter['variadic']) {
                $names[] = $parameter['name'];
            }
        }

        return $names;
    }

    /**
     * Compare an application-authored required-name contract with a complete list
     * of attribute names after Blade's bind/short-attribute parsing. No value is
     * evaluated. Caller must prove both the class target and attribute list.
     *
     * @param list<scalar|array|null> $requiredNames Explicit constructor parameter names.
     * @param list<scalar|array|null> $attributeNames Names after Blade attribute parsing.
     * @return list<string>|null Missing explicit names, or null when unprovable.
     */
    public function missingExplicit(
        BladeClassComponent $component,
        array $requiredNames,
        array $attributeNames,
        bool $complete,
    ): ?array {
        if (! $complete || $component->constructor === null) {
            return null;
        }

        $parameters = [];
        foreach ($component->constructor as $parameter) {
            if (! $parameter['variadic']) {
                $parameters[$parameter['name']] = true;
            }
        }

        $required = [];
        foreach ($requiredNames as $name) {
            if (! is_string($name) || ! isset($parameters[$name]) || isset($required[$name])) {
                return null;
            }
            $required[$name] = true;
        }

        $supplied = [];
        foreach ($attributeNames as $name) {
            if (! is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $name) !== 1) {
                return null;
            }
            $words = preg_split('/[-_]+/', $name);
            if ($words === false || in_array('', $words, true)) {
                return null;
            }
            $supplied[lcfirst(implode('', array_map(ucfirst(...), $words)))] = true;
        }

        $missing = [];
        foreach ($required as $name => $_) {
            if (! isset($supplied[$name])) {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
