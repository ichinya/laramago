<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Explicit frontend JSON type expectations for directly serializable render literals. */
final class InertiaLiteralPropTypes
{
    /** @var array<string, array<string, list<string>>> */
    private array $pages = [];

    public function __construct(string $root)
    {
        $contents = @file_get_contents($root.'/composer.json');
        if ($contents === false) {
            return;
        }
        try {
            /** @var mixed $composer */
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        /** @var mixed $pages */
        $pages = is_array($composer)
            ? $composer['extra']['laramago']['reference-catalogs']['inertia-pages'] ?? null
            : null;
        if (! is_array($pages) || ($pages['prop-values-unchanged'] ?? null) !== true) {
            return;
        }
        /** @var mixed $contracts */
        $contracts = $pages['prop-types'] ?? null;
        if (! is_array($contracts) || array_is_list($contracts)) {
            return;
        }
        /** @var mixed $props */
        foreach ($contracts as $page => $props) {
            if (! is_string($page) || ! is_array($props) || array_is_list($props)) {
                continue;
            }
            $valid = [];
            /** @var mixed $type */
            foreach ($props as $name => $type) {
                if (
                    ! is_string($name)
                    || $name === ''
                    || str_contains($name, '.')
                    || is_numeric($name)
                    || ! is_string($type)
                ) {
                    continue;
                }
                $members = explode('|', $type);
                if (count($members) !== count(array_unique($members))) {
                    continue;
                }
                if (array_diff($members, ['string', 'number', 'boolean', 'array', 'null']) !== []) {
                    continue;
                }
                $valid[$name] = $members;
            }
            $this->pages[$page] = $valid;
        }
    }

    /** @return array<string, list<string>> */
    public function forPage(string $page): array
    {
        return $this->pages[$page] ?? [];
    }

    /** @return array<string, string>|null */
    public function literalProps(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?array
    {
        $props = null;
        $assigned = [];
        $position = 0;
        $named = false;
        foreach ($call->getArgs() as $argument) {
            if ($argument->unpack) {
                return null;
            }
            if ($argument->name === null) {
                if ($named || $position > 1) {
                    return null;
                }
                $parameter = $position++ === 0 ? 'component' : 'props';
            } else {
                $named = true;
                $parameter = $argument->name->name;
                if ($parameter !== 'component' && $parameter !== 'props') {
                    return null;
                }
            }
            if (isset($assigned[$parameter])) {
                return null;
            }
            $assigned[$parameter] = true;
            if ($parameter === 'props') {
                $props = $argument->value;
            }
        }
        if (! $props instanceof Node\Expr\Array_) {
            return null;
        }
        $values = [];
        foreach ($props->items as $item) {
            if (
                $item->unpack
                || ! $item->key instanceof Node\Scalar\String_
                || $item->byRef
                || str_contains($item->key->value, '.')
                || is_numeric($item->key->value)
                || array_key_exists($item->key->value, $values)
            ) {
                return null;
            }
            $kind = self::literalKind($item->value, 0);
            if ($kind === null) {
                return null;
            }
            $values[$item->key->value] = $kind;
        }

        return $values;
    }

    private static function literalKind(Node\Expr $expression, int $depth): ?string
    {
        if ($expression instanceof Node\Scalar\String_) {
            return preg_match('//u', $expression->value) === 1 ? 'string' : null;
        }
        if ($expression instanceof Node\Scalar\Int_) {
            return 'number';
        }
        if ($expression instanceof Node\Scalar\Float_) {
            return is_finite($expression->value) ? 'number' : null;
        }
        if (
            ($expression instanceof Node\Expr\UnaryMinus
            || $expression instanceof Node\Expr\UnaryPlus)
            && ($expression->expr instanceof Node\Scalar\Int_
            || $expression->expr instanceof Node\Scalar\Float_)
        ) {
            return $expression->expr instanceof Node\Scalar\Float_ && ! is_finite($expression->expr->value)
                ? null
                : 'number';
        }
        if ($expression instanceof Node\Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'true', 'false' => 'boolean',
                'null' => 'null',
                default => null,
            };
        }
        if (! $expression instanceof Node\Expr\Array_ || $depth >= 16) {
            return null;
        }
        foreach ($expression->items as $item) {
            if (
                $item->unpack
                || $item->byRef
                || $item->key !== null
                || self::literalKind($item->value, $depth + 1) === null
            ) {
                return null;
            }
        }

        return 'array';
    }
}
