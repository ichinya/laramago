<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;

/** Reads the literal core alias table from the installed Laravel Application. */
final class FrameworkContainerAliases
{
    public function __construct(
        private readonly PhpSource $source,
    ) {}

    public function concrete(Codebase $codebase, string $accessor): ?string
    {
        $application = 'Illuminate\\Foundation\\Application';
        $method = $codebase->getDeclaringMethod($application, 'registerCoreContainerAliases');
        if (
            $method === null
            || $method->identifier->class !== $application
            || ! str_ends_with(
                str_replace('\\', '/', $this->source->path($method->location->file ?? '')),
                '/laravel/framework/src/Illuminate/Foundation/Application.php',
            )
        ) {
            return null;
        }
        $statements = (new ModelReflection($codebase, $this->source))->methodNode($method)?->stmts ?? [];
        if (
            count($statements) !== 1
            || ! $statements[0] instanceof Node\Stmt\Foreach_
            || ! $statements[0]->expr instanceof Node\Expr\Array_
        ) {
            return null;
        }
        foreach ($statements[0]->expr->items as $item) {
            if (
                ! $item->key instanceof Node\Scalar\String_
                || $item->key->value !== $accessor
                || ! $item->value instanceof Node\Expr\Array_
            ) {
                continue;
            }
            $first = $item->value->items[0] ?? null;
            $root = PhpSource::value($first?->value, $application, $application);
            if (! is_string($root) || $codebase->getClass($root) === null) {
                return null;
            }

            return $root;
        }

        return null;
    }
}
