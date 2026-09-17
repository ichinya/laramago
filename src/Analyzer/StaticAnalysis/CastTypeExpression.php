<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use PhpParser\NameContext;
use PhpParser\Node;

/** A bounded PHPDoc subset for explicitly bound custom-caster ancestors. */
final class CastTypeExpression
{
    /**
     * @param array<string, Type> $bindings
     * @param list<string> $unbound
     */
    public function __construct(
        private readonly Codebase $codebase,
        private readonly NameContext $context,
        private readonly array $bindings,
        private readonly array $unbound,
    ) {}

    public function parse(string $expression, int $depth = 0): ?Type
    {
        if ($depth > 16 || strlen($expression) > 8192) {
            return null;
        }
        $expression = trim($expression);
        $union = self::split($expression, '|');
        if ($union === null) {
            return null;
        }
        if (count($union) > 1) {
            $result = null;
            foreach ($union as $part) {
                $type = $this->parse($part, $depth + 1);
                if ($type === null) {
                    return null;
                }
                $result = $result === null ? $type : Type::union($result, $type);
            }

            return $result;
        }
        if (str_starts_with($expression, '?')) {
            $type = $this->parse(substr($expression, 1), $depth + 1);

            return $type === null ? null : Type::union($type, Type::null());
        }
        $shape = [];
        if (preg_match('/^array\s*\{(.*)\}$/sD', $expression, $shape)) {
            return $this->shape($shape[1], $depth + 1);
        }
        $generic = [];
        if (preg_match('/^([\\\\A-Za-z_][\\\\A-Za-z0-9_-]*)\s*<(.*)>$/sD', $expression, $generic)) {
            $parts = self::split($generic[2], ',');
            if ($parts === null) {
                return null;
            }
            $arguments = [];
            foreach ($parts as $part) {
                $argument = $this->parse($part, $depth + 1);
                if ($argument === null) {
                    return null;
                }
                $arguments[] = $argument;
            }
            if (in_array($generic[1], ['list', 'non-empty-list'], true)) {
                return count($arguments) === 1
                    ? Type::fromAtomic(new ListType($arguments[0], null, null, $generic[1] === 'non-empty-list'))
                    : null;
            }
            if (in_array($generic[1], ['array', 'non-empty-array'], true)) {
                if (count($arguments) === 1) {
                    array_unshift($arguments, Type::union(Type::int(), Type::string()));
                }
                foreach ($arguments[0]->atomicTypes as $key) {
                    if (
                        ! $key instanceof ScalarType
                        || ! in_array($key->kind, [ScalarTypeKind::Integer, ScalarTypeKind::String], true)
                    ) {
                        return null;
                    }
                }

                return count($arguments) === 2
                    ? Type::fromAtomic(
                        new KeyedArrayType(null, $arguments[0], $arguments[1], $generic[1] === 'non-empty-array'),
                    )
                    : null;
            }
            $class = $this->className($generic[1]);
            if (
                $class === null
                || count($this->codebase->getClassLike($class)?->templates ?? []) !== count($arguments)
            ) {
                return null;
            }

            return Type::namedObject($class, ...$arguments);
        }
        if (in_array($expression, $this->unbound, true)) {
            return null;
        }
        $type = $this->bindings[$expression] ?? match ($expression) {
            'int' => Type::int(),
            'string' => Type::string(),
            'bool' => Type::bool(),
            'float' => Type::float(),
            'null' => Type::null(),
            'mixed' => Type::mixed(),
            'true' => Type::true(),
            'false' => Type::false(),
            'array-key' => Type::union(Type::int(), Type::string()),
            default => null,
        };
        if ($type !== null) {
            return $type;
        }
        $class = $this->className($expression);

        // Omitting the arguments of a generic class must not introduce implicit mixed.
        return $class === null || ($this->codebase->getClassLike($class)?->templates ?? []) !== []
            ? null
            : Type::namedObject($class);
    }

    private function className(string $name): ?string
    {
        if (
            isset($this->bindings[$name])
            || in_array($name, $this->unbound, true)
            || ! preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $name)
        ) {
            return null;
        }
        $node = str_starts_with($name, '\\')
            ? new Node\Name\FullyQualified(substr($name, 1))
            : new Node\Name($name);
        $resolved = $this->context->getResolvedClassName($node)->toString();

        return $this->codebase->classOrInterfaceExists($resolved) ? $resolved : null;
    }

    private function shape(string $expression, int $depth): ?Type
    {
        $parts = trim($expression) === '' ? [] : self::split($expression, ',');
        if ($parts === null) {
            return null;
        }
        $items = [];
        $keys = [];
        $required = false;
        foreach ($parts as $part) {
            // Explicit unquoted identifiers and integer keys only; no open shapes or aliases.
            $pair = self::split($part, ':');
            $match = [];
            if (
                $pair === null
                || count($pair) !== 2
                || ! preg_match('/^([A-Za-z_][A-Za-z0-9_]*|0|-?[1-9][0-9]*)(\?)?$/D', trim($pair[0]), $match)
            ) {
                return null;
            }
            $type = $this->parse($pair[1], $depth + 1);
            if ($type === null || isset($keys[$match[1]])) {
                return null;
            }
            $keys[$match[1]] = true;
            $integer = preg_match('/^-?[0-9]+$/D', $match[1]) === 1;
            if ($integer && (string) (int) $match[1] !== $match[1]) {
                return null;
            }
            $optional = ($match[2] ?? '') === '?';
            if (! $optional) {
                $required = true;
            }
            $items[] = new ArrayItem(
                new ArrayKey(
                    $integer ? ArrayKeyKind::Integer : ArrayKeyKind::String,
                    $integer ? (int) $match[1] : $match[1],
                ),
                $optional,
                $type,
            );
        }

        return Type::fromAtomic(new KeyedArrayType($items, null, null, $required));
    }

    /** Split delimiters only outside nested types; malformed/unsupported syntax stays unknown.
     * @return list<string>|null
     */
    public static function split(string $expression, string $delimiter): ?array
    {
        $parts = [];
        $stack = [];
        $start = 0;
        $closers = ['<' => '>', '{' => '}', '(' => ')', '[' => ']'];
        for ($index = 0, $length = strlen($expression); $index < $length; ++$index) {
            $character = $expression[$index];
            if (isset($closers[$character])) {
                $stack[] = $closers[$character];
                if (count($stack) > 16) {
                    return null;
                }
            } elseif (in_array($character, $closers, true)) {
                if (array_pop($stack) !== $character) {
                    return null;
                }
            } elseif ($character === $delimiter && $stack === []) {
                $parts[] = trim(substr($expression, $start, $index - $start));
                $start = $index + 1;
            } elseif ($character === '"' || $character === "'") {
                return null;
            }
        }
        $parts[] = trim(substr($expression, $start));

        return $stack !== [] || in_array('', $parts, true) ? null : $parts;
    }
}
