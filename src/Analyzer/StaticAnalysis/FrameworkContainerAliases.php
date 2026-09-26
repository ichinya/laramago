<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Reads the literal core alias table from the installed Laravel Application. */
final class FrameworkContainerAliases
{
    private const APPLICATION = 'Illuminate\\Foundation\\Application';
    private const APPLICATION_FILE = 'laravel/framework/src/Illuminate/Foundation/Application.php';
    private const CONTRACT_PREFIX = 'Illuminate\\Contracts\\';

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

    /**
     * Maps every `Illuminate\Contracts\*` interface listed in the literal core alias
     * table to the root concrete class bound beside it.
     *
     * The table must retain its exact literal shape; any shape deviation disables the
     * map so nothing is trusted from a changed framework. Entries are candidates only:
     * roots may legitimately reference optional packages that are not installed, so
     * usability is proven per call against the root's own declarations, and a contract
     * the table binds to two different roots is dropped instead of trusted.
     *
     * @return array<string, string> Contract interface => root concrete class.
     */
    public function contracts(Codebase $codebase): array
    {
        $application = self::APPLICATION;
        $method = $codebase->getDeclaringMethod($application, 'registerCoreContainerAliases');
        if (
            $method === null
            || $method->identifier->class !== $application
            || ! str_ends_with(
                str_replace('\\', '/', $this->source->path($method->location->file ?? '')),
                '/'.self::APPLICATION_FILE,
            )
        ) {
            return [];
        }
        $statements = (new ModelReflection($codebase, $this->source))->methodNode($method)?->stmts ?? [];
        if (
            count($statements) !== 1
            || ! $statements[0] instanceof Node\Stmt\Foreach_
            || ! $statements[0]->expr instanceof Node\Expr\Array_
        ) {
            return [];
        }

        return self::contractMap($statements[0]->expr);
    }

    /**
     * The same literal map read from source alone, before a Codebase exists.
     * Usability is re-verified through the Codebase before anything is forwarded.
     *
     * @return array<string, string> Contract interface => root concrete class.
     */
    public function contractsFromSource(): array
    {
        foreach (['vendor/'.self::APPLICATION_FILE, self::APPLICATION_FILE] as $candidate) {
            $nodes = $this->source->read($candidate);
            if ($nodes === null) {
                continue;
            }
            $method = null;
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                if (strcasecmp($class->namespacedName?->toString() ?? '', self::APPLICATION) !== 0) {
                    continue;
                }
                foreach ($class->stmts as $statement) {
                    if (
                        $statement instanceof Node\Stmt\ClassMethod
                        && strcasecmp($statement->name->toString(), 'registerCoreContainerAliases') === 0
                    ) {
                        $method = $statement;
                    }
                }
                break;
            }
            if (! $method instanceof Node\Stmt\ClassMethod) {
                return [];
            }
            $statements = $method->stmts ?? [];
            if (
                count($statements) !== 1
                || ! $statements[0] instanceof Node\Stmt\Foreach_
                || ! $statements[0]->expr instanceof Node\Expr\Array_
            ) {
                return [];
            }

            return self::contractMap($statements[0]->expr);
        }

        return [];
    }

    /**
     * Only `Illuminate\Contracts\*` interfaces are mapped, skipping foreign references
     * such as `\Psr\Container\ContainerInterface`. The first array item is the root
     * concrete class; every later contract reference maps to that root. A contract the
     * table binds to two different roots is dropped instead of trusted.
     *
     * @return array<string, string> Contract interface => root concrete class.
     */
    private static function contractMap(Node\Expr\Array_ $table): array
    {
        $map = [];
        foreach ($table->items as $item) {
            if (
                ! $item instanceof Node\Expr\ArrayItem
                || ! $item->key instanceof Node\Scalar\String_
                || ! $item->value instanceof Node\Expr\Array_
                || $item->value->items === []
            ) {
                return [];
            }
            $first = $item->value->items[0] ?? null;
            $root = PhpSource::value($first?->value, self::APPLICATION, self::APPLICATION);
            if (! is_string($root) || $root === '') {
                return [];
            }
            foreach ($item->value->items as $reference) {
                $contract = PhpSource::value($reference?->value, self::APPLICATION, self::APPLICATION);
                if (! is_string($contract) || $contract === '') {
                    return [];
                }
                if (! str_starts_with($contract, self::CONTRACT_PREFIX)) {
                    continue;
                }
                $previous = $map[$contract] ?? null;
                if ($previous === null) {
                    $map[$contract] = $root;

                    continue;
                }
                if (strcasecmp($previous, $root) !== 0) {
                    unset($map[$contract]);
                }
            }
        }

        return $map;
    }
}
