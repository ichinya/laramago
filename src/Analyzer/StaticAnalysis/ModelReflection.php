<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Doctrine\Inflector\InflectorFactory;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class ModelReflection
{
    public const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    public function __construct(
        private readonly Codebase $codebase,
        private readonly PhpSource $source,
    ) {}

    /**
     * @param scalar|array<array-key, scalar|null|UnknownValue>|null|UnknownValue $fallback
     * @return scalar|array<array-key, scalar|null|UnknownValue>|null|UnknownValue
     */
    public function default(
        string $class,
        string $property,
        string|int|float|bool|array|UnknownValue|null $fallback = null,
    ): string|int|float|bool|array|UnknownValue|null {
        $metadata = $this->codebase->getDeclaringProperty($class, '$'.$property) ?? $this->codebase->getProperty(
            $class,
            '$'.$property,
        );
        if (
            $metadata !== null
            && ! $metadata->flags->contains(MetadataFlags::HAS_DEFAULT)
            && $metadata->declaredType === null
        ) {
            return null;
        }

        return $metadata === null ? $fallback : self::literal($metadata->defaultType?->type);
    }

    /** @return scalar|array<array-key, scalar|null|UnknownValue>|null|UnknownValue */
    private static function literal(?Type $type): string|int|float|bool|array|UnknownValue|null
    {
        if ($type === null) {
            return UnknownValue::Value;
        }
        if ((string) $type === 'null') {
            return null;
        }
        $literal =
            $type->getLiteralString() ?? $type->getLiteralClassString() ?? $type->getLiteralInt() ?? $type->getLiteralBool();
        if ($literal !== null) {
            return $literal;
        }
        if (count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof KeyedArrayType) {
            $array = $type->atomicTypes[0];
            if ($array->keyType !== null && (string) $array->keyType !== 'never') {
                return UnknownValue::Value;
            }
            $values = [];
            foreach ($array->knownItems ?? [] as $item) {
                if (! is_int($item->key->value) && ! is_string($item->key->value) || $item->optional) {
                    return UnknownValue::Value;
                }
                $value = self::literal($item->type);
                $values[$item->key->value] = is_array($value) ? UnknownValue::Value : $value;
            }

            return $values;
        }
        if (
            count($type->atomicTypes) === 1
            && $type->atomicTypes[0] instanceof ListType
            && ($type->atomicTypes[0]->knownCount === 0
            || (string) $type->atomicTypes[0]->elementType === 'never')
        ) {
            return [];
        }

        return UnknownValue::Value;
    }

    public function methodNode(FunctionLikeMetadata $method): ?Node\Stmt\ClassMethod
    {
        $file = $method->location->file;
        if ($file === null || str_starts_with($file, '@')) {
            return null;
        }
        foreach ((new NodeFinder)->findInstanceOf(
            $this->source->read($file) ?? [],
            Node\Stmt\ClassMethod::class,
        ) as $node) {
            if (
                strcasecmp($node->name->toString(), $method->originalName) === 0
                && $node->getStartFilePos() >= $method->location->span->start
                && $node->getStartFilePos() < $method->location->span->end
            ) {
                return $node;
            }
        }

        return null;
    }

    public function returnExpression(FunctionLikeMetadata $method): ?Node\Expr
    {
        $statements = $this->methodNode($method)?->stmts ?? [];

        return count($statements) === 1 && $statements[0] instanceof Node\Stmt\Return_ ? $statements[0]->expr : null;
    }

    public function table(string $class): ?string
    {
        if (
            $this->default($class, 'connection') !== null
            || $this->customMethod($class, 'getConnectionName') !== null
        ) {
            return null;
        }
        if (($method = $this->customMethod($class, 'getTable')) !== null) {
            $value = PhpSource::value($this->returnExpression($method), $method->identifier->class, $class);

            return is_string($value) ? $value : null;
        }
        $table = $this->default($class, 'table');
        if ($table !== null) {
            return is_string($table) ? $table : null;
        }
        $base = substr($class, (int) strrpos('\\'.$class, '\\'));
        // Laravel pluralizes the final Studly word before converting to snake case.
        $parts = preg_split('/(?=[A-Z])/', $base, -1, PREG_SPLIT_NO_EMPTY) ?: [$base];
        $last = array_pop($parts);
        $plural = InflectorFactory::create()->build()->pluralize($last);

        return self::snake(implode('', $parts).$plural);
    }

    public static function snake(string $name): string
    {
        return strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1_', $name) ?? $name);
    }

    public static function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name)));
    }

    public function customMethod(string $class, string $method): ?FunctionLikeMetadata
    {
        $metadata = $this->method($class, $method);
        $declaring = $metadata?->identifier->class ?? '';
        if (
            strcasecmp($declaring, self::MODEL) === 0
            || str_starts_with(strtolower($declaring), 'illuminate\\database\\eloquent\\concerns\\')
        ) {
            return null;
        }

        return $metadata;
    }

    public function method(string $class, string $method): ?FunctionLikeMetadata
    {
        return $this->codebase->getMethod($class, $method) ?? $this->codebase->getDeclaringMethod($class, $method);
    }

    /** @return array<string, string|UnknownValue>|UnknownValue */
    public function casts(string $class): array|UnknownValue
    {
        if ($this->customMethod($class, 'getCasts') !== null) {
            return UnknownValue::Value;
        }
        $casts = $this->default($class, 'casts', []);
        if (! is_array($casts)) {
            return UnknownValue::Value;
        }
        if (($method = $this->customMethod($class, 'casts')) !== null) {
            $values = PhpSource::value($this->returnExpression($method), $method->identifier->class, $class);
            if (! is_array($values)) {
                return UnknownValue::Value;
            }
            $casts = array_replace($casts, $values);
        }
        $key = $this->key($class);
        if ($key !== null && $this->default($class, 'incrementing', true) === true) {
            $keyType = $this->default($class, 'keyType', 'int');
            $casts = [$key => $keyType, ...$casts];
        }
        $result = [];
        foreach ($casts as $name => $value) {
            if (! is_string($name)) {
                return UnknownValue::Value;
            }
            $result[$name] = is_string($value) ? $value : UnknownValue::Value;
        }

        return $result;
    }

    public function key(string $class): ?string
    {
        if (
            $this->customMethod($class, 'getKeyName') !== null
            || $this->customMethod($class, 'getKeyType') !== null
            || $this->customMethod($class, 'getIncrementing') !== null
        ) {
            return null;
        }
        $key = $this->default($class, 'primaryKey', 'id');

        return is_string($key) ? $key : null;
    }

    public function automaticDate(string $class, string $property): bool
    {
        if ($this->customMethod($class, 'getDates') !== null) {
            return false;
        }
        if ($this->default($class, 'timestamps', true) === true) {
            foreach (['CREATED_AT' => 'created_at', 'UPDATED_AT' => 'updated_at'] as $constant => $default) {
                $metadata = $this->codebase->getClassConstant($class, $constant);
                $name = $metadata === null
                    ? $default
                    : self::literal($metadata->type?->type ?? $metadata->inferredType);
                if ($property === $name) {
                    return true;
                }
            }
        }
        if ($this->codebase->methodExists($class, 'initializeSoftDeletes')) {
            $metadata = $this->codebase->getClassConstant($class, 'DELETED_AT');

            return (
                $property
                === (
                    $metadata === null ? 'deleted_at' : self::literal($metadata->type?->type ?? $metadata->inferredType)
                )
            );
        }

        return false;
    }
}
