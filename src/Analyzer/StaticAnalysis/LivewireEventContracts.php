<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/** Literal event declarations and dispatch sites in one selected PHP class. */
final class LivewireEventContracts
{
    public function __construct(
        private readonly PhpSource $source,
    ) {}

    /**
     * The caller selects a known Livewire 3/4 component. This is a syntax snapshot,
     * not proof that a listener is active or a dispatch will execute.
     *
     * @return array{listeners: list<array{event: string, method: string, line: int, kind: string}>, dispatches: list<array{event: string, method: string, line: int}>, unresolved: bool}|null
     */
    public function inspect(string $file, string $class): ?array
    {
        $nodes = $this->source->read($file);
        if ($nodes === null) {
            return null;
        }
        $matches = self::classes($nodes, ltrim($class, '\\'));
        if (count($matches) !== 1) {
            return null;
        }
        $component = $matches[0];
        $listeners = [];
        $dispatches = [];
        $unresolved = false;
        if ($component->extends?->toString() !== 'Livewire\\Component') {
            $unresolved = true;
        }

        $this->attributes($component->attrGroups, '$refresh', $listeners, $unresolved);
        foreach ($component->stmts as $statement) {
            if ($statement instanceof Node\Stmt\TraitUse) {
                $unresolved = true;
            }
            if ($statement instanceof Node\Stmt\Property) {
                foreach ($statement->props as $property) {
                    if ($property->name->toString() !== 'listeners') {
                        continue;
                    }
                    if (! $property->default instanceof Node\Expr\Array_) {
                        $unresolved = true;
                        continue;
                    }
                    foreach ($property->default->items as $item) {
                        if ($item->unpack || $item->byRef) {
                            $unresolved = true;
                            continue;
                        }
                        $method = $item->value instanceof Node\Scalar\String_ ? $item->value->value : null;
                        $key =
                            $item->key instanceof Node\Scalar\String_ || $item->key instanceof Node\Scalar\Int_
                                ? (string) $item->key->value
                                : null;
                        $event = $item->key === null || $key !== null && is_numeric($key) ? $method : $key;
                        if ($event === null || $method === null || ! self::exactEvent($event)) {
                            $unresolved = true;
                            continue;
                        }
                        $listeners[] = [
                            'event' => $event,
                            'method' => $method,
                            'line' => $item->getStartLine(),
                            'kind' => 'property',
                        ];
                    }
                }
            }
            if (! $statement instanceof Node\Stmt\ClassMethod) {
                continue;
            }
            $methodName = $statement->name->toString();
            if (strcasecmp($methodName, 'getListeners') === 0) {
                $unresolved = true;
            }
            $this->attributes($statement->attrGroups, $methodName, $listeners, $unresolved);
            if ($statement->stmts !== null) {
                self::dispatches($statement->stmts, $methodName, $dispatches, $unresolved);
            }
        }

        return ['listeners' => $listeners, 'dispatches' => $dispatches, 'unresolved' => $unresolved];
    }

    /** @param array<array-key, Node> $nodes
     * @return list<Node\Stmt\Class_>
     */
    private static function classes(array $nodes, string $name): array
    {
        $matches = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                array_push($matches, ...self::classes($node->stmts, $name));
            } elseif (
                $node instanceof Node\Stmt\Class_
                && $node->namespacedName !== null
                && strcasecmp($node->namespacedName->toString(), $name) === 0
            ) {
                $matches[] = $node;
            }
        }

        return $matches;
    }

    /** @param array<array-key, Node\AttributeGroup> $groups
     * @param list<array{event: string, method: string, line: int, kind: string}> $listeners
     */
    private function attributes(array $groups, string $method, array &$listeners, bool &$unresolved): void
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if (strcasecmp($attribute->name->toString(), 'Livewire\\Attributes\\On') !== 0) {
                    continue;
                }
                if (
                    count($attribute->args) !== 1
                    || $attribute->args[0]->unpack
                    || $attribute->args[0]->byRef
                    || $attribute->args[0]->name !== null
                    && $attribute->args[0]->name->toString() !== 'event'
                ) {
                    $unresolved = true;
                    continue;
                }
                $value = $attribute->args[0]->value;
                $events = $value instanceof Node\Expr\Array_ ? $value->items : [$value];
                foreach ($events as $entry) {
                    $expression = $entry instanceof Node\ArrayItem ? $entry->value : $entry;
                    if ($entry instanceof Node\ArrayItem && ($entry->key !== null || $entry->unpack || $entry->byRef)) {
                        $unresolved = true;
                        continue;
                    }
                    if (! $expression instanceof Node\Scalar\String_ || ! self::exactEvent($expression->value)) {
                        $unresolved = true;
                        continue;
                    }
                    $listeners[] = [
                        'event' => $expression->value,
                        'method' => $method,
                        'line' => $expression->getStartLine(),
                        'kind' => 'attribute',
                    ];
                }
            }
        }
    }

    /** @param array<array-key, Node> $nodes
     * @param list<array{event: string, method: string, line: int}> $dispatches
     */
    private static function dispatches(array $nodes, string $method, array &$dispatches, bool &$unresolved): void
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Node\Expr\MethodCall> */
            public array $calls = [];

            public function enterNode(Node $node): Node|int|null
            {
                if (
                    $node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Stmt\Class_
                    || ($node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction)
                    && $node->static
                ) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Node\Expr\MethodCall) {
                    $this->calls[] = $node;
                }

                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse($nodes);
        foreach ($visitor->calls as $node) {
            if (
                $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && $node->name instanceof Node\Identifier
                && strcasecmp($node->name->toString(), 'dispatch') === 0
            ) {
                $first = $node->args[0] ?? null;
                if (
                    $first instanceof Node\Arg
                    && ! $first->unpack
                    && ! $first->byRef
                    && ($first->name === null
                    || $first->name->toString() === 'event')
                    && $first->value instanceof Node\Scalar\String_
                    && self::exactEvent($first->value->value)
                ) {
                    $dispatches[] = [
                        'event' => $first->value->value,
                        'method' => $method,
                        'line' => $first->value->getStartLine(),
                    ];
                } else {
                    $unresolved = true;
                }
            }
        }
    }

    private static function exactEvent(string $event): bool
    {
        return (
            $event !== ''
            && ! str_contains($event, '{')
            && ! str_contains($event, '}')
            && ! str_contains($event, '*')
            && ! str_starts_with(strtolower($event), 'echo:')
            && ! str_starts_with(strtolower($event), 'echo-')
        );
    }
}
