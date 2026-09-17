<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use PhpParser\Node;

/** A syntax-only snapshot of conventional configuration files. */
final class ConfigurationIndex
{
    /** @var array<string, Node\Expr\Array_|null> */
    private array $files = [];

    public function __construct(
        public readonly PhpSource $source,
    ) {
        foreach (glob($source->path('config/*.php')) ?: [] as $path) {
            $nodes = $source->read($path);
            $returned = null;
            foreach ($nodes ?? [] as $node) {
                if (
                    $node instanceof Node\Stmt\Return_
                    && $returned === null
                    && $node->expr instanceof Node\Expr\Array_
                ) {
                    $returned = $node->expr;
                } elseif (
                    ! $node instanceof Node\Stmt\Declare_
                    && ! $node instanceof Node\Stmt\Use_
                    && ! $node instanceof Node\Stmt\GroupUse
                    && ! $node instanceof Node\Stmt\Nop
                ) {
                    $returned = null;
                    break;
                }
            }
            $this->files[pathinfo($path, PATHINFO_FILENAME)] = $returned;
        }
    }

    /** Unknown namespaces and dynamic branches defer, including their defaults. */
    public function lookup(string $key, ?Type $default): ?Type
    {
        $parts = explode('.', $key, 2);
        $node = $this->files[$parts[0]] ?? null;
        if ($node === null) {
            return null;
        }
        if (! isset($parts[1])) {
            return $this->type($node);
        }
        $remaining = $parts[1];
        while (true) {
            if (! $node instanceof Node\Expr\Array_) {
                return null;
            }
            $items = $this->items($node);
            if ($items === null) {
                return null;
            }
            $parts = explode('.', $remaining, 2);
            if (! array_key_exists($parts[0], $items)) {
                return $default;
            }
            $node = $items[$parts[0]];
            if (! isset($parts[1])) {
                return $this->type($node);
            }
            $remaining = $parts[1];
        }
    }

    /** @return array<array-key, Node\Expr>|null */
    private function items(Node\Expr\Array_ $array): ?array
    {
        $items = [];
        foreach ($array->items as $item) {
            if ($item->unpack || $item->byRef) {
                return null;
            }
            if ($item->key === null) {
                $items[] = $item->value;
                continue;
            }
            $key = PhpSource::value($item->key);
            if (! is_string($key) && ! is_int($key)) {
                return null;
            }
            $items[$key] = $item->value;
        }

        return $items;
    }

    private function type(Node\Expr $node): ?Type
    {
        if ($node instanceof Node\Expr\Array_) {
            $items = $this->items($node);
            if ($items === null) {
                return null;
            }
            $shape = [];
            foreach ($items as $key => $value) {
                $type = $this->type($value);
                if ($type === null) {
                    return null;
                }
                $shape[] = new ArrayItem(
                    new ArrayKey(is_int($key) ? ArrayKeyKind::Integer : ArrayKeyKind::String, $key),
                    false,
                    $type,
                );
            }

            return Type::fromAtomic(new KeyedArrayType($shape, null, null, $shape !== []));
        }
        $value = PhpSource::value($node);

        return match (true) {
            is_string($value) => Type::literalString($value),
            is_int($value) => Type::literalInt($value),
            is_float($value) => Type::float(),
            $value === true => Type::true(),
            $value === false => Type::false(),
            $value === null => Type::null(),
            default => null,
        };
    }
}
