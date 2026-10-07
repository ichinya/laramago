<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\StringType;

/** The explicit env default is a scalar declaration under Larastan's policy. */
final class EnvironmentDefaultContracts
{
    public static function type(Type $default): ?Type
    {
        $types = [];
        foreach ($default->atomicTypes as $atom) {
            if ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null) {
                $type = Type::null();
            } elseif ($atom instanceof ScalarType) {
                $type = match ($atom->kind) {
                    ScalarTypeKind::Boolean => Type::bool(),
                    ScalarTypeKind::Integer => Type::int(),
                    ScalarTypeKind::Float => Type::float(),
                    ScalarTypeKind::String => $atom->refinement instanceof StringType ? Type::string() : null,
                    default => null,
                };
            } else {
                return null;
            }
            if ($type === null) { return null; }
            $types[(string) $type] = $type;
        }
        if ($types === []) { return null; }
        $types = array_values($types);
        return count($types) === 1 ? $types[0] : Type::union(...$types);
    }
}
