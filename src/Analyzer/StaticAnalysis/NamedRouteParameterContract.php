<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Explicit effective required URI parameters for native named-route URL generation. */
final class NamedRouteParameterContract
{
    /** @var array<string, list<string>>|null */
    private ?array $routes = null;

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
        /** @var mixed $contract */
        $contract = is_array($options) ? $options['named-route-parameters'] ?? null : null;
        /** @var mixed $routes */
        $routes = is_array($contract) ? $contract['routes'] ?? null : null;
        if (
            ! is_array($contract)
            || ($contract['native-url-generation'] ?? null) !== true
            || ($contract['url-defaults-complete'] ?? null) !== true
            || ! is_array($routes)
            || array_is_list($routes)
            && $routes !== []
        ) {
            return;
        }
        $valid = [];
        /** @var mixed $required */
        foreach ($routes as $route => $required) {
            if (! is_string($route) || $route === '' || ! is_array($required) || ! array_is_list($required)) {
                return;
            }
            $keys = [];
            /** @var mixed $key */
            foreach ($required as $key) {
                if (
                    ! is_string($key)
                    || $key === ''
                    || preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $key) === 1
                    || in_array($key, $keys, true)
                ) {
                    return;
                }
                $keys[] = $key;
            }
            $valid[$route] = $keys;
        }
        $this->routes = $valid;
    }

    public function enabled(): bool
    {
        return $this->routes !== null;
    }

    /**
     * Return definitely missing required keys for a closed named array literal.
     *
     * Null means that the call or route is outside the provable subset.
     *
     * @return list<string>|null
     */
    public function missing(string $route, ?Node\Expr $parameters): ?array
    {
        $required = $this->routes[$route] ?? null;
        if ($required === null || $required === []) {
            return $required;
        }
        if ($parameters === null || self::null($parameters)) {
            return $required;
        }
        if (! $parameters instanceof Node\Expr\Array_) {
            return null;
        }
        /** @var array<string, bool> $supplied */
        $supplied = [];
        foreach ($parameters->items as $item) {
            if (
                $item->unpack
                || ! $item->key instanceof Node\Scalar\String_
                || preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $item->key->value) === 1
            ) {
                return null;
            }
            $supplied[$item->key->value] =
                ! self::null($item->value)
                && (! $item->value instanceof Node\Scalar\String_ || $item->value->value !== '');
        }

        return array_values(array_filter(
            $required,
            static fn (string $key): bool => ($supplied[$key] ?? false) === false,
        ));
    }

    private static function null(Node\Expr $expression): bool
    {
        return $expression instanceof Node\Expr\ConstFetch && strtolower($expression->name->toString()) === 'null';
    }
}
