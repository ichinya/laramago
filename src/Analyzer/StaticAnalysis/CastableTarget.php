<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;

/** Recognizes a single literal factory return without executing application code. */
final class CastableTarget
{
    public static function resolve(string $class, Codebase $codebase, PhpSource $source): ?string
    {
        $method = $codebase->getMethod($class, 'castUsing') ?? $codebase->getDeclaringMethod($class, 'castUsing');
        if ($method === null || ! $method->static || $method->abstract || $method->visibility !== Visibility::Public) {
            return null;
        }
        $node = (new ModelReflection($codebase, $source))->methodNode($method);
        $statements = $node?->stmts ?? [];
        if (count($statements) !== 1 || ! $statements[0] instanceof Node\Stmt\Return_) {
            return null;
        }
        $expression = $statements[0]->expr;
        if (
            $expression instanceof Node\Expr\New_
            && $expression->class instanceof Node\Name
            && $expression->args === []
        ) {
            $name = $expression->class->toString();
        } elseif ($expression instanceof Node\Expr\ClassConstFetch) {
            $name = PhpSource::value($expression);
        } else {
            return null;
        }

        // Contextual names and dynamic expressions need runtime information.
        return is_string($name) && ! in_array(strtolower($name), ['self', 'static', 'parent'], true)
            ? $name
            : null;
    }
}
