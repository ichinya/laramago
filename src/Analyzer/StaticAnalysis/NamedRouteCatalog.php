<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Missing named routes require an explicit complete application catalog. */
final class NamedRouteCatalog
{
    /** @var list<string>|null */
    private ?array $names = null;

    public function __construct(string $root)
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        if ($text === false) {
            return;
        }
        /** @var mixed $data */
        $data = json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($data) ? $data['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalog */
        $catalog = is_array($options) ? $options['named-routes'] ?? null : null;
        if (
            ! is_array($catalog)
            || ($catalog['complete'] ?? null) !== true
            || ($catalog['missing-route-resolver'] ?? null) !== false
        ) {
            return;
        }
        /** @var mixed $names */
        $names = $catalog['names'] ?? null;
        if (! is_array($names) || ! array_is_list($names)) {
            return;
        }
        $valid = [];
        /** @var mixed $name */
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                return;
            }
            $valid[] = $name;
        }
        if (array_key_exists('files', $catalog)) {
            $extracted = LiteralRouteNames::fromFiles($root, $catalog['files']);
            if ($extracted === null) {
                return;
            }
            array_push($valid, ...$extracted);
        }
        $this->names = $valid;
    }

    public function enabled(): bool
    {
        return $this->names !== null;
    }

    public function missing(string $name): bool
    {
        return $this->names !== null && ! in_array($name, $this->names, true);
    }
}
