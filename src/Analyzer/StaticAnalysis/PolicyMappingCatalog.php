<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Selected native AuthServiceProvider declarations, never runtime policy discovery. */
final class PolicyMappingCatalog
{
    /** @var array<string, string>|null */
    private ?array $mappings = null;

    public function __construct(string $root)
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        if ($text === false) {
            return;
        }
        /** @var mixed $composer */
        $composer = json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $entries */
        $entries = is_array($options) ? $options['policy-sources'] ?? null : null;
        if (! is_array($entries) || ! array_is_list($entries)) {
            return;
        }
        $source = new PhpSource($root);
        $mappings = [];
        /** @var mixed $entry */
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                return;
            }
            /** @var mixed $file */
            $file = $entry['file'] ?? null;
            /** @var mixed $provider */
            $provider = $entry['provider'] ?? null;
            if (! is_string($file) || ! is_string($provider) || $provider === '') {
                return;
            }
            $path = KernelMiddlewareDeclarations::sourcePath($root, $file);
            if ($path === null) {
                return;
            }
            $nodes = $source->read($path);
            $classes = $nodes === null ? null : self::classes($nodes);
            if ($classes === null || count($classes) !== 1) {
                return;
            }
            $class = $classes[0];
            if (
                $class->namespacedName === null
                || strcasecmp($class->namespacedName->toString(), ltrim($provider, '\\')) !== 0
                || $class->extends === null
                || strcasecmp(
                    $class->extends->toString(),
                    'Illuminate\\Foundation\\Support\\Providers\\AuthServiceProvider',
                ) !== 0
                || $class->isAbstract()
                || $class->isReadonly()
                || $class->attrGroups !== []
                || $class->implements !== []
                || count($class->stmts) !== 1
            ) {
                return;
            }
            $property = $class->stmts[0];
            if (
                ! $property instanceof Node\Stmt\Property
                || $property->flags !== Node\Stmt\Class_::MODIFIER_PROTECTED
                || $property->hooks !== []
                || $property->type !== null
                || $property->attrGroups !== []
                || count($property->props) !== 1
                || $property->props[0]->name->toString() !== 'policies'
                || ! $property->props[0]->default instanceof Node\Expr\Array_
            ) {
                return;
            }
            foreach ($property->props[0]->default->items as $item) {
                if ($item->unpack || $item->byRef || $item->key === null) {
                    return;
                }
                $model = self::className($item->key);
                $policy = self::className($item->value);
                if ($model === null || $policy === null) {
                    return;
                }
                // PHP keys are deliberately not lowercased or stripped of leading slashes.
                $mappings[$model] = $policy;
            }
        }
        $this->mappings = $mappings;
    }

    /** @return array<string, string>|null Selected declarations only, in registration order. */
    public function mappings(): ?array
    {
        return $this->mappings;
    }

    /** Null is unknown, never evidence that no policy exists. */
    public function policyForExactKey(string $model): ?string
    {
        return $this->mappings[$model] ?? null;
    }

    private static function className(Node\Expr $expression): ?string
    {
        if (
            $expression instanceof Node\Expr\ClassConstFetch
            && ! $expression->class instanceof Node\Name\FullyQualified
        ) {
            return null;
        }
        $value = PhpSource::value($expression);

        return is_string($value)
        && preg_match(
            '~^\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$~',
            $value,
        ) === 1
            ? $value
            : null;
    }

    /** @param array<array-key, Node> $nodes
     * @return list<Node\Stmt\Class_>|null
     */
    private static function classes(array $nodes): ?array
    {
        $classes = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $nested = self::classes($node->stmts);
                if ($nested === null) {
                    return null;
                }
                array_push($classes, ...$nested);
            } elseif ($node instanceof Node\Stmt\Class_) {
                $classes[] = $node;
            } elseif (
                ! $node instanceof Node\Stmt\Use_
                && ! $node instanceof Node\Stmt\GroupUse
                && ! $node instanceof Node\Stmt\Nop
                && ! ($node instanceof Node\Stmt\Declare_
                && $node->stmts === null)
            ) {
                return null;
            }
        }

        return $classes;
    }
}
