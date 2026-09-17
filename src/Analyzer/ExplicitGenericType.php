<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\IntegerType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\StringType;

/** Retains explicit concrete factory arguments without guessing template roles. */
final class ExplicitGenericType
{
    public static function concrete(Type $type): bool
    {
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof NamedObjectType) {
                if ($atom->static || $atom->isThis || ($atom->intersections ?? []) !== []) {
                    return false;
                }
                foreach ($atom->parameters ?? [] as $parameter) {
                    if (! self::concrete($parameter)) {
                        return false;
                    }
                }
            } elseif (
                ! $atom instanceof IntegerType
                && ! $atom instanceof ScalarType
                && ! $atom instanceof StringType
                && ! $atom instanceof SimpleAtomicType
            ) {
                return false;
            }
        }

        return true;
    }
}
