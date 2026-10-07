<?php

declare(strict_types=1);

namespace Example\Fortify\StaticAnalysis;

use PhpParser\Node;

/** Source syntax fingerprint; comments and source locations carry no semantics. */
final class AstContract
{
    public static function fingerprint(Node $node): string
    {
        return hash('sha256', json_encode(self::structure($node), JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed>|scalar|null */
    private static function structure(mixed $value): array|string|int|float|bool|null
    {
        if ($value instanceof Node) {
            $result = [$value->getType()];
            $properties = get_object_vars($value);
            foreach ($value->getSubNodeNames() as $name) {
                $result[$name] = self::structure($properties[$name] ?? null);
            }

            return $result;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::structure($item);
            }

            return $result;
        }

        return is_scalar($value) ? $value : null;
    }
}
