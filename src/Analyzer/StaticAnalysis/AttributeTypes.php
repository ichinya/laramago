<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;

final class AttributeTypes
{
    public static function column(Column $column): PropertyType
    {
        $read = match ($column->type) {
            'int' => Type::int(),
            'float' => Type::float(),
            'bool' => Type::union(
                Type::bool(),
                Type::literalInt(0),
                Type::literalInt(1),
                Type::literalString('0'),
                Type::literalString('1'),
            ),
            'decimal' => Type::union(self::numericString(), Type::int(), Type::float()),
            'morph-key' => Type::union(Type::int(), Type::string()),
            default => Type::string(),
        };
        $write = $column->type === 'decimal' ? Type::union(Type::string(), Type::int(), Type::float()) : $read;

        return self::nullable(new PropertyType($read, $write), $column->nullable);
    }

    public static function cast(string $cast, Codebase $codebase): ?PropertyType
    {
        $parts = explode(':', $cast, 2);
        $kind = strtolower($parts[0]);
        if ($kind === 'encrypted') {
            return self::cast($parts[1] ?? 'string', $codebase);
        }
        $array = Type::array(Type::union(Type::int(), Type::string()), Type::mixed());
        $number = Type::union(Type::int(), Type::float(), Type::string());
        $type = match ($kind) {
            'int', 'integer', 'timestamp' => new PropertyType(Type::int(), $number),
            'real', 'float', 'double' => new PropertyType(Type::float(), $number),
            'bool', 'boolean' => new PropertyType(Type::bool(), Type::union(
                Type::bool(),
                Type::literalInt(0),
                Type::literalInt(1),
                Type::literalString('0'),
                Type::literalString('1'),
            )),
            'string', 'hashed' => new PropertyType(Type::string(), Type::string()),
            'decimal' => new PropertyType(self::numericString(), $number),
            'array', 'json' => new PropertyType($array, $array),
            'object' => new PropertyType(Type::namedObject('stdClass'), Type::union(Type::object(), $array)),
            'collection' => new PropertyType(
                Type::namedObject(
                    'Illuminate\\Support\\Collection',
                    Type::union(Type::int(), Type::string()),
                    Type::mixed(),
                ),
                Type::union($array, Type::namedObject(
                    'Illuminate\\Support\\Collection',
                    Type::union(Type::int(), Type::string()),
                    Type::mixed(),
                )),
            ),
            'date',
            'datetime',
            'custom_datetime',
            'immutable_date',
            'immutable_datetime',
            'immutable_custom_datetime',
                => self::date(str_starts_with($kind, 'immutable')),
            default => null,
        };
        if ($type !== null) {
            return $type;
        }
        $enum = $codebase->getClassLike($parts[0]);
        if ($enum === null || $enum->kind !== ClassLikeKind::Enum) {
            return null;
        }
        $read = Type::namedObject($enum->originalName);

        return new PropertyType($read, Type::union($read, $enum->enumType ?? Type::string()));
    }

    public static function date(bool $immutable = false): PropertyType
    {
        return new PropertyType(
            Type::namedObject($immutable ? 'Carbon\\CarbonImmutable' : 'Carbon\\CarbonInterface'),
            Type::union(Type::namedObject('DateTimeInterface'), Type::string(), Type::int()),
        );
    }

    private static function numericString(): Type
    {
        return Type::fromAtomic(new ScalarType(ScalarTypeKind::String, new StringType(
            StringLiteralKind::General,
            null,
            true,
            false,
            true,
            false,
            StringCasing::Unspecified,
        )));
    }

    public static function nullable(PropertyType $type, bool $nullable): PropertyType
    {
        return ! $nullable
            ? $type
            : new PropertyType(
                $type->readType === null ? null : Type::union($type->readType, Type::null()),
                $type->writeType === null ? null : Type::union($type->writeType, Type::null()),
            );
    }
}
