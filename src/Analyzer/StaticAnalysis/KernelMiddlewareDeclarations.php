<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Literal property declarations only: these are not the effective runtime registry. */
final class KernelMiddlewareDeclarations
{
    /** @var array<string, list<string>>|null */
    private ?array $groups = null;
    private ?Node\Expr\Array_ $groupNode = null;

    public function __construct(PhpSource $source, string $file, string $class)
    {
        $path = self::sourcePath($source->root, $file);
        if ($path === null) {
            return;
        }
        $nodes = $source->read($path);
        if ($nodes === null) {
            return;
        }
        $matches = [];
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                if (
                    $statement instanceof Node\Stmt\Class_
                    && isset($statement->namespacedName)
                    && strcasecmp($statement->namespacedName->toString(), ltrim($class, '\\')) === 0
                ) {
                    $matches[] = $statement;
                }
            }
        }
        if (count($matches) !== 1) {
            return;
        }
        $declaration = $matches[0];
        $properties = [];
        foreach ($declaration->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\Property || $statement->isStatic()) {
                continue;
            }
            foreach ($statement->props as $property) {
                if ($property->name->toString() === 'middlewareGroups') {
                    $properties[] = $property;
                }
            }
        }
        if (count($properties) === 1 && $declaration->namespacedName !== null) {
            $this->groups = self::readGroups($properties[0]->default, $declaration->namespacedName->toString());
            if ($this->groups !== null && $properties[0]->default instanceof Node\Expr\Array_) {
                $this->groupNode = $properties[0]->default;
            }
        }
    }

    public function groupNode(): ?Node\Expr\Array_
    {
        return $this->groupNode;
    }

    /** Project-relative PHP files only, including containment after resolving links. */
    public static function sourcePath(string $root, string $file): ?string
    {
        if (
            $file === ''
            || str_contains($file, "\0")
            || preg_match('~^(?:[/\\\\]|[A-Za-z]:)|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
            || ! str_ends_with($file, '.php')
        ) {
            return null;
        }
        $directory = realpath($root);
        if ($directory === false) {
            return null;
        }
        $directory = str_replace('\\', '/', $directory).'/';
        $path = realpath($directory.$file);

        return $path !== false && str_starts_with(str_replace('\\', '/', $path), $directory) && is_file($path)
            ? $path
            : null;
    }

    /** @return array<string, list<string>>|null Missing or inherited declarations remain unknown. */
    public function groups(): ?array
    {
        return $this->groups;
    }

    /** @return array<string, list<string>>|null */
    public static function readGroups(?Node $node, ?string $class = null): ?array
    {
        if (! $node instanceof Node\Expr\Array_) {
            return null;
        }
        $groups = [];
        foreach ($node->items as $item) {
            if ($item->unpack || $item->byRef || ! $item->key instanceof Node\Scalar\String_) {
                return null;
            }
            $name = $item->key->value;
            if ($name === '' || is_numeric($name) || ! $item->value instanceof Node\Expr\Array_) {
                return null;
            }
            $members = [];
            foreach ($item->value->items as $member) {
                if ($member->unpack || $member->byRef || $member->key !== null) {
                    return null;
                }
                $value = PhpSource::value($member->value, $class);
                if (! is_string($value) || $value === '') {
                    return null;
                }
                // parent::class needs ancestry; static::class is invalid in a property initializer.
                if (
                    $member->value instanceof Node\Expr\ClassConstFetch
                    && $member->value->class instanceof Node\Name
                    && in_array(strtolower($member->value->class->toString()), ['parent', 'static'], true)
                ) {
                    return null;
                }
                $members[] = $value;
            }
            $groups[$name] = $members;
        }

        return $groups;
    }
}
