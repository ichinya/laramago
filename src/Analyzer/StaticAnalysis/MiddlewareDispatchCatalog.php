<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Resolve middleware only under an explicit native dispatch and effective-map contract. */
final class MiddlewareDispatchCatalog
{
    private ?MiddlewareAliasCatalog $aliases = null;
    private ?MiddlewareGroupCatalog $groups = null;

    public function __construct(string $root)
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        if (! is_array($options) || ($options['middleware-native-dispatch'] ?? null) !== true) {
            return;
        }
        $aliases = new MiddlewareAliasCatalog($root);
        $groups = new MiddlewareGroupCatalog($root);
        if ($aliases->isComplete() && $groups->isComplete()) {
            $this->aliases = $aliases;
            $this->groups = $groups;
        }
    }

    public function enabled(): bool
    {
        return $this->aliases !== null && $this->groups !== null;
    }

    /** @return list<array{class: string, parameters: int}>|null */
    public function resolve(string $reference): ?array
    {
        if ($this->aliases === null || $this->groups === null) {
            return null;
        }

        return $this->expand($reference, [], 0, false);
    }

    /** @param list<string> $active
     * @return list<array{class: string, parameters: int}>|null
     */
    private function expand(string $reference, array $active, int $depth, bool $groupMember): ?array
    {
        if ($depth > 32 || str_contains($reference, '::') || in_array($reference, $active, true)) {
            return null;
        }
        $groups = $this->groups?->groups() ?? [];
        if (array_key_exists($reference, $groups)) {
            $result = [];
            foreach ($groups[$reference] as $member) {
                $resolved = $this->expand($member, [...$active, $reference], $depth + 1, true);
                if ($resolved === null || (count($result) + count($resolved)) > 256) {
                    return null;
                }
                array_push($result, ...$resolved);
            }

            return $result;
        }
        $pieces = explode(':', $reference, 2);
        $name = $pieces[0];
        $suffix = $pieces[1] ?? null;
        // Native parseMiddlewareGroup drops falsey suffixes while rebuilding strings.
        if ($groupMember && ($suffix === '' || $suffix === '0')) {
            $suffix = null;
        }
        $class = $this->aliases?->target($name) ?? $name;
        if (! preg_match(
            '~^\\\\?[a-zA-Z_\\x80-\\xff][a-zA-Z0-9_\\x80-\\xff]*(?:\\\\[a-zA-Z_\\x80-\\xff][a-zA-Z0-9_\\x80-\\xff]*)*$~D',
            $class,
        )) {
            return null;
        }

        return [['class' => ltrim($class, '\\'), 'parameters' => $suffix === null ? 0 : count(explode(',', $suffix))]];
    }
}
