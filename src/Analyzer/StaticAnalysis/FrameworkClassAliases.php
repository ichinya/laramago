<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Closure;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Reads the literal class-alias table Laravel registers at boot.
 *
 * The runtime chain is: framework base config `aliases` (a `Facade::defaultAliases()`
 * merge chain), overridden wholesale by a project `config/app.php` `aliases` key, then
 * merged with the package manifest. Only the fully literal part of that chain is
 * trusted here; every deviation disables the map so nothing is forwarded from an
 * unprovable table.
 */
final class FrameworkClassAliases
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Facade';
    private const FACADE_FILE = 'laravel/framework/src/Illuminate/Support/Facades/Facade.php';
    private const FRAMEWORK_CONFIG_FILE = 'laravel/framework/config/app.php';

    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * Alias name => target class for the literal part of the boot chain.
     *
     * The framework `config/app.php` `aliases` entry must exist in its exact merge
     * shape, the project must not override `aliases` wholesale, must not opt out of
     * framework configuration merging, and no installed package may claim a colliding
     * alias name. Anything else returns an empty map.
     *
     * Every step parses through its own throwaway reader: keeping several parsed
     * files alive for the whole worker run has starved the extension's memory
     * budget, so no syntax outlives the call that needed it.
     *
     * @return array<string, string> Lowercased alias name => target class.
     */
    public function aliases(): array
    {
        $defaults = $this->defaultAliases(new PhpSource($this->root));
        if ($defaults === []) {
            return [];
        }
        $map = $this->frameworkConfigAliases(new PhpSource($this->root), $defaults);
        if ($map === []) {
            return [];
        }
        if (
            $this->projectOverridesAliases(new PhpSource($this->root))
            || $this->projectSkipsFrameworkConfiguration(new PhpSource($this->root))
        ) {
            return [];
        }

        return $this->withoutManifestCollisions(new PhpSource($this->root), $map);
    }

    /**
     * The literal array of `Illuminate\Support\Facades\Facade::defaultAliases()`.
     *
     * @return array<string, string> Lowercased alias name => target class.
     */
    private function defaultAliases(PhpSource $source): array
    {
        foreach (['vendor/'.self::FACADE_FILE, self::FACADE_FILE] as $candidate) {
            $nodes = $source->read($candidate);
            if ($nodes === null) {
                continue;
            }
            $resolve = $this->nameResolver($nodes);
            $statements = $this->methodStatements($nodes, self::FACADE, 'defaultAliases');
            if (
                count($statements) !== 1
                || ! $statements[0] instanceof Node\Stmt\Return_
                || ! $statements[0]->expr instanceof Node\Expr\New_
                || count($statements[0]->expr->args) !== 1
                || ! $statements[0]->expr->class instanceof Node\Name
                || strcasecmp($resolve($statements[0]->expr->class), 'Illuminate\\Support\\Collection') !== 0
            ) {
                return [];
            }
            $table = PhpSource::argument($statements[0]->expr->args, 0, 'items');
            if (! $table instanceof Node\Expr\Array_) {
                return [];
            }

            return $this->literalAliasTable($table, $resolve);
        }

        return [];
    }

    /**
     * The framework base config `aliases` entry: a `Facade::defaultAliases()` chain
     * whose literal `merge()` argument overlays the defaults like the runtime
     * `array_merge` does.
     *
     * @param array<string, string> $defaults
     * @return array<string, string> Lowercased alias name => target class.
     */
    private function frameworkConfigAliases(PhpSource $source, array $defaults): array
    {
        foreach (['vendor/'.self::FRAMEWORK_CONFIG_FILE, self::FRAMEWORK_CONFIG_FILE] as $candidate) {
            $nodes = $source->read($candidate);
            if ($nodes === null) {
                continue;
            }
            $resolve = $this->nameResolver($nodes);
            $returns = array_values(array_filter(
                $nodes,
                static fn (Node $node): bool => $node instanceof Node\Stmt\Return_
                    && $node->expr instanceof Node\Expr\Array_,
            ));
            if (count($returns) !== 1) {
                return [];
            }
            $aliases = null;
            foreach ($returns[0]->expr->items as $item) {
                if ($item?->key instanceof Node\Scalar\String_ && strtolower($item->key->value) === 'aliases') {
                    $aliases = $item;
                }
            }
            if (! $aliases instanceof Node\Expr\ArrayItem) {
                return [];
            }
            [$base, $calls] = PhpSource::chain($aliases->value);
            $calls = array_values(array_filter(
                $calls,
                static fn (Node\Expr\MethodCall $call): bool => ! in_array(
                    strtolower($call->name instanceof Node\Identifier ? $call->name->toString() : ''),
                    ['toarray', 'all'],
                    true,
                ),
            ));
            if (
                ! $base instanceof Node\Expr\StaticCall
                || ! $base->name instanceof Node\Identifier
                || strcasecmp($base->name->toString(), 'defaultAliases') !== 0
                || ! $base->class instanceof Node\Name
                || strcasecmp($resolve($base->class), self::FACADE) !== 0
                || count($calls) !== 1
                || ! $calls[0]->name instanceof Node\Identifier
                || strcasecmp($calls[0]->name->toString(), 'merge') !== 0
                || count($calls[0]->args) !== 1
            ) {
                return [];
            }
            $overrides = PhpSource::argument($calls[0]->args, 0, 'values');
            if (! $overrides instanceof Node\Expr\Array_) {
                return [];
            }

            return array_merge(
                $defaults,
                $this->literalAliasTable($overrides, $resolve),
            );
        }

        return [];
    }

    /**
     * A project `config/app.php` carrying any `aliases` key replaces the base table
     * wholesale at runtime and may contain arbitrary expressions, so the map cannot
     * be proven then. An unparseable project config is equally unprovable.
     */
    private function projectOverridesAliases(PhpSource $source): bool
    {
        $nodes = $source->read('config/app.php');
        if ($nodes === null) {
            return false;
        }
        $returns = array_values(array_filter(
            $nodes,
            static fn (Node $node): bool => $node instanceof Node\Stmt\Return_
                && $node->expr instanceof Node\Expr\Array_,
        ));
        if (count($returns) !== 1) {
            return true;
        }
        foreach ($returns[0]->expr->items as $item) {
            if ($item?->key instanceof Node\Scalar\String_ && strtolower($item->key->value) === 'aliases') {
                return true;
            }
        }

        return false;
    }

    /** A project opting out of framework configuration merging drops the base table. */
    private function projectSkipsFrameworkConfiguration(PhpSource $source): bool
    {
        $nodes = $source->read('bootstrap/app.php');
        if ($nodes === null) {
            return false;
        }

        return (new NodeFinder)->findFirst($nodes, static function (Node $node): bool {
            return $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && strcasecmp($node->name->toString(), 'dontMergeFrameworkConfiguration') === 0;
        }) !== null;
    }

    /**
     * Alias names claimed by installed packages: the materialized manifest cache when
     * present, otherwise the package composer metadata it is built from. A collision
     * with one of these names cannot be proven either way and is dropped.
     *
     * @param array<string, string> $map
     * @return array<string, string>
     */
    private function withoutManifestCollisions(PhpSource $source, array $map): array
    {
        $claimed = $this->cachedManifestAliases($source)
            ?? $this->installedJsonAliases();
        foreach (array_keys($claimed) as $name) {
            unset($map[strtolower($name)]);
        }

        return $map;
    }

    /**
     * The `bootstrap/cache/packages.php` manifest dump when it exists, parsed as the
     * literal package => configuration array it is.
     *
     * @return array<string, array<string, string>>|null Null when the cache is absent.
     */
    private function cachedManifestAliases(PhpSource $source): ?array
    {
        $nodes = $source->read('bootstrap/cache/packages.php');
        if ($nodes === null) {
            return null;
        }
        $returns = array_values(array_filter(
            $nodes,
            static fn (Node $node): bool => $node instanceof Node\Stmt\Return_
                && $node->expr instanceof Node\Expr\Array_,
        ));
        if (count($returns) !== 1) {
            return [];
        }
        $claimed = [];
        foreach ($returns[0]->expr->items as $package) {
            if (! $package instanceof Node\Expr\ArrayItem || ! $package->value instanceof Node\Expr\Array_) {
                continue;
            }
            foreach ($package->value->items as $entry) {
                if (
                    $entry instanceof Node\Expr\ArrayItem
                    && $entry->key instanceof Node\Scalar\String_
                    && strtolower($entry->key->value) === 'aliases'
                    && $entry->value instanceof Node\Expr\Array_
                ) {
                    foreach ($this->literalAliasTable($entry->value, static fn (string $name): string => $name) as $alias => $target) {
                        $claimed[$alias] = $target;
                    }
                }
            }
        }

        return $claimed;
    }

    /**
     * The alias claims in `vendor/composer/installed.json` package extras, the source
     * `PackageManifest::build()` materializes the cache from.
     *
     * @return array<string, string>
     */
    private function installedJsonAliases(): array
    {
        $installed = $this->root.'/vendor/composer/installed.json';
        if (! is_file($installed)) {
            return [];
        }
        $contents = file_get_contents($installed);
        if ($contents === false) {
            return [];
        }
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $packages = $decoded['packages'] ?? $decoded;
        if (! is_array($packages)) {
            return [];
        }
        $claimed = [];
        foreach ($packages as $package) {
            $aliases = is_array($package ?? null) ? ($package['extra']['laravel']['aliases'] ?? []) : [];
            foreach (is_array($aliases) ? $aliases : [] as $name => $target) {
                if (is_string($name) && is_string($target)) {
                    $claimed[$name] = $target;
                }
            }
        }

        return $claimed;
    }

    /**
     * String-keyed items whose values are `::class` constants or plain strings,
     * resolved through the file's namespace and imports. Any other shape is
     * unprovable and disables the table.
     *
     * @param Closure(Node\Name): string $resolve
     * @return array<string, string> Lowercased alias name => target class.
     */
    private function literalAliasTable(Node\Expr\Array_ $table, Closure $resolve): array
    {
        $map = [];
        foreach ($table->items as $item) {
            if (
                ! $item instanceof Node\Expr\ArrayItem
                || ! $item->key instanceof Node\Scalar\String_
            ) {
                return [];
            }
            if ($item->value instanceof Node\Expr\ClassConstFetch) {
                if (
                    ! $item->value->name instanceof Node\Identifier
                    || strtolower($item->value->name->toString()) !== 'class'
                    || ! $item->value->class instanceof Node\Name
                ) {
                    return [];
                }
                $target = $resolve($item->value->class);
            } elseif ($item->value instanceof Node\Scalar\String_) {
                $target = $item->value->value;
            } else {
                return [];
            }
            if (! is_string($target) || $target === '') {
                return [];
            }
            $map[strtolower($item->key->value)] = $target;
        }

        return $map;
    }

    /**
     * PHP name resolution for one parsed file: fully qualified names pass through,
     * imported aliases come next, remaining names resolve relative to the namespace.
     *
     * @param list<Node> $nodes
     * @return Closure(Node\Name): string
     */
    private function nameResolver(array $nodes): Closure
    {
        $namespace = '';
        $imports = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $namespace = $node->name?->toString() ?? '';
            }
            if ($node instanceof Node\Stmt\Use_) {
                foreach ($node->uses as $use) {
                    if ($use->type === Node\Stmt\Use_::TYPE_NORMAL) {
                        $imports[strtolower($use->getAlias()->toString())] = $use->name->toString();
                    }
                }
            }
        }

        return static function (Node\Name $name) use ($namespace, $imports): string {
            if ($name->isFullyQualified()) {
                return $name->toString();
            }
            $segments = explode('\\', $name->toString());
            $head = strtolower(array_shift($segments));
            if (isset($imports[$head])) {
                return $segments === [] ? $imports[$head] : $imports[$head].'\\'.implode('\\', $segments);
            }

            return ($namespace === '' ? '' : $namespace.'\\').$name->toString();
        };
    }

    /**
     * The single method declared directly on the given class.
     *
     * @param list<Node> $nodes
     * @return list<Node\Stmt>
     */
    private function methodStatements(array $nodes, string $class, string $method): array
    {
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $found) {
            if (strcasecmp($found->namespacedName?->toString() ?? '', $class) !== 0) {
                continue;
            }
            foreach ($found->stmts as $statement) {
                if (
                    $statement instanceof Node\Stmt\ClassMethod
                    && strcasecmp($statement->name->toString(), $method) === 0
                ) {
                    return $statement->stmts ?? [];
                }
            }
        }

        return [];
    }
}
