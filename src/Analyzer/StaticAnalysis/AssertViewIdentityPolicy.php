<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Explicit permitted assertViewIs identities; never a view-finder result. */
final class AssertViewIdentityPolicy
{
    /** @var list<string>|null */
    private ?array $identities = null;
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
        $policy = is_array($options) ? $options['assert-view-identity-policy'] ?? null : null;
        if (! is_array($policy)) {
            return;
        }

        /** @var mixed $enabled */
        $enabled = $policy['enabled'] ?? false;
        /** @var mixed $identities */
        $identities = $policy['identities'] ?? null;
        if (! is_bool($enabled) || ! is_array($identities) || ! array_is_list($identities)) {
            return;
        }

        $valid = [];
        /** @var mixed $identity */
        foreach ($identities as $identity) {
            if (! is_string($identity)) {
                return;
            }
            if (! in_array($identity, $valid, true)) {
                $valid[] = $identity;
            }
        }

        $this->identities = $valid;
        $this->enabled = $enabled;
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->identities !== null;
    }

    public function permits(string $identity): ?bool
    {
        $identities = $this->identities;
        if (! $this->enabled || $identities === null) {
            return null;
        }

        return in_array($identity, $identities, true);
    }
}
