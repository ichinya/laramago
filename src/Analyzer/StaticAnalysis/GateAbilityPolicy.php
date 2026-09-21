<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Explicit permitted Gate ability references; never an authorization result. */
final class GateAbilityPolicy
{
    /** @var list<string>|null */
    private ?array $abilities = null;
    private bool $enabled = false;

    public function __construct(string $root)
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        if ($text === false) {
            return;
        }

        /** @var mixed $composer */
        $composer = json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $policy */
        $policy = is_array($options) ? $options['gate-ability-policy'] ?? null : null;
        if (! is_array($policy)) {
            return;
        }

        /** @var mixed $enabled */
        $enabled = $policy['enabled'] ?? false;
        /** @var mixed $abilities */
        $abilities = $policy['abilities'] ?? null;
        if (! is_bool($enabled) || ! is_array($abilities) || ! array_is_list($abilities)) {
            return;
        }

        $valid = [];
        /** @var mixed $ability */
        foreach ($abilities as $ability) {
            if (! is_string($ability)) {
                return;
            }
            if (! in_array($ability, $valid, true)) {
                $valid[] = $ability;
            }
        }

        $this->abilities = $valid;
        $this->enabled = $enabled;
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->abilities !== null;
    }

    public function permits(string $ability): ?bool
    {
        $abilities = $this->abilities;
        if (! $this->enabled || $abilities === null) {
            return null;
        }

        return in_array($ability, $abilities, true);
    }
}
