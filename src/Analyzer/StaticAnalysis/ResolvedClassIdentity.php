<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Class identities in ASTs already traversed by PhpParser's NameResolver. */
final class ResolvedClassIdentity
{
    public static function is(Node\Name $name, string $class): bool
    {
        $actual = $name->toString();

        return (
            ! in_array(strtolower($actual), ['self', 'static', 'parent'], true)
            && strcasecmp(ltrim($actual, '\\'), ltrim($class, '\\')) === 0
        );
    }

    /**
     * Only a sole top-level declaration can prove a source file's class identity.
     * A same-short-name declaration elsewhere keeps the file ambiguous.
     *
     * @param array<array-key, Node> $nodes
     */
    public static function uniqueDeclaration(array $nodes, string $class): ?Node\Stmt\ClassLike
    {
        $separator = strrpos($class, '\\');
        $shortName = substr($class, $separator === false ? 0 : $separator + 1);
        $declarations = (new NodeFinder)->find(
            $nodes,
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\ClassLike
                && strcasecmp($node->name?->toString() ?? '', $shortName) === 0
            ),
        );
        if (count($declarations) !== 1) {
            return null;
        }
        $candidate = $declarations[0];
        if (
            ! $candidate instanceof Node\Stmt\ClassLike
            || ! $candidate->namespacedName instanceof Node\Name
            || ! self::is($candidate->namespacedName, $class)
        ) {
            return null;
        }
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                if ($statement === $candidate) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
