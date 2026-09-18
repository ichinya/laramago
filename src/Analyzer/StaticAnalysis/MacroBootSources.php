<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Explicit active providers only; source presence never implies activation. */
final class MacroBootSources
{
    /**
     * @return list<Node\Stmt>|null
     */
    public static function statements(PhpSource $source, mixed $entries): ?array
    {
        if (! is_array($entries) || $entries !== [] && array_is_list($entries)) {
            return null;
        }
        $result = [];
        /** @var mixed $file */
        foreach ($entries as $class => $file) {
            if (
                ! is_string($class)
                || ! is_string($file)
                || ! preg_match('~^(?:app|bootstrap)/[a-zA-Z0-9_./-]+\\.php$~', $file)
                || in_array('..', explode('/', $file), true)
            ) {
                return null;
            }
            $nodes = $source->read($file);
            if ($nodes === null) {
                return null;
            }
            $classes = self::classes($nodes);
            if ($classes === null || count($classes) !== 1) {
                return null;
            }
            $provider = $classes[0];
            if (
                $provider->namespacedName === null
                || strcasecmp($provider->namespacedName->toString(), ltrim($class, '\\')) !== 0
                || $provider->extends === null
                || strcasecmp($provider->extends->toString(), 'Illuminate\\Support\\ServiceProvider') !== 0
                || $provider->isAbstract()
                || $provider->attrGroups !== []
                || $provider->implements !== []
            ) {
                return null;
            }
            // Custom constructors, register methods, traits and inherited boot methods
            // require runtime reasoning outside this explicit direct-boot subset.
            if (count($provider->stmts) !== 1 || ! $provider->stmts[0] instanceof Node\Stmt\ClassMethod) {
                return null;
            }
            $boot = $provider->stmts[0];
            if (
                strtolower($boot->name->toString()) !== 'boot'
                || ! $boot->isPublic()
                || $boot->isStatic()
                || $boot->byRef
                || $boot->params !== []
                || $boot->attrGroups !== []
                || $boot->stmts === null
            ) {
                return null;
            }
            foreach ($boot->stmts as $statement) {
                if (
                    ! $statement instanceof Node\Stmt\Expression
                    || ! $statement->expr instanceof Node\Expr\StaticCall
                    || ! $statement->expr->class instanceof Node\Name\FullyQualified
                    || ! $statement->expr->name instanceof Node\Identifier
                    || strtolower($statement->expr->name->toString()) !== 'macro'
                ) {
                    return null;
                }
                $result[] = $statement;
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, Node> $nodes
     * @return list<Node\Stmt\Class_>|null
     */
    private static function classes(array $nodes): ?array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $nested = self::classes($node->stmts);
                if ($nested === null) {
                    return null;
                }
                array_push($result, ...$nested);
            } elseif ($node instanceof Node\Stmt\Class_) {
                $result[] = $node;
            } elseif (
                ! $node instanceof Node\Stmt\Use_
                && ! $node instanceof Node\Stmt\GroupUse
                && ! ($node instanceof Node\Stmt\Declare_ && $node->stmts === null)
                && ! $node instanceof Node\Stmt\Nop
            ) {
                return null;
            }
        }

        return $result;
    }
}
