<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/** An ordered, conservative interpretation of declarative migration statements. */
final class SchemaIndex
{
    /** @var array<string, array<string, Column>> */
    private array $tables = [];
    /** @var array<string, true> */
    private array $uncertain = [];
    private bool $unreadable = false;
    /** @var list<array{file:string,line:int,scope:string,tables:list<string>}> */
    private array $uncertainties = [];

    public function __construct(
        private readonly PhpSource $source,
    ) {}

    public function load(): void
    {
        $paths = ['database/migrations'];
        $composer = $this->source->root.'/composer.json';
        if (is_file($composer)) {
            $contents = @file_get_contents($composer);
            try {
                $paths = [
                    ...$paths,
                    ...self::configuredPaths(json_decode(
                        $contents === false ? '' : $contents,
                        true,
                        flags: JSON_THROW_ON_ERROR,
                    )),
                ];
            } catch (\JsonException|\RuntimeException $error) {
                $this->source->warnings[$composer] = 'Cannot read migration configuration: '.$error->getMessage();
                $this->unreadable = true;
            }
        }
        $files = [];
        foreach (array_unique($paths) as $path) {
            $directory = $this->source->path($path);
            if (! is_dir($directory)) {
                if ($path !== 'database/migrations') {
                    $this->source->warnings[$directory] = 'Configured migration directory does not exist.';
                    $this->unreadable = true;
                }
                continue;
            }
            try {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS,
                )) as $file) {
                    if (
                        $file instanceof SplFileInfo
                        && $file->isFile()
                        && strtolower($file->getExtension()) === 'php'
                    ) {
                        $files[$file->getRealPath() ?: $file->getPathname()] = $file->getFilename();
                    }
                }
            } catch (UnexpectedValueException $error) {
                $this->source->warnings[$directory] = 'Cannot read migration directory: '.$error->getMessage();
                $this->unreadable = true;
            }
        }
        uksort($files, static fn (string $a, string $b): int => [basename($a), $a] <=> [basename($b), $b]);
        foreach ($files as $file => $_) {
            $nodes = $this->source->read($file);
            if ($nodes === null) {
                $this->unreadable = true;
                continue;
            }
            $uses = $this->fileUses($nodes);
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                $up = $class->getMethod('up')?->stmts ?? [];
                $connection = null;
                foreach ($class->getProperties() as $property) {
                    foreach ($property->props as $item) {
                        if ($item->name->toString() === 'connection' && $item->default !== null) {
                            $connection = PhpSource::value($item->default);
                        }
                    }
                }
                if (
                    strcasecmp($class->extends?->toString() ?? '', 'Illuminate\\Database\\Migrations\\Migration') !== 0
                    || $connection !== null
                    || $class->getMethod('getConnection') !== null
                ) {
                    $before = [$this->unreadable, $this->uncertain];
                    $scan = ['visited' => [], 'schema' => false, 'unknownMethod' => false, 'depth' => 0, 'uses' => $uses];
                    $this->invalidateStatements($up, $class, $scan);
                    $this->recordUncertainty($file, $class->getMethod('up') ?? $class, $before);
                    continue;
                }
                foreach ($up as $statement) {
                    $this->migrationStatement($file, $statement, $class, [], $uses);
                }
            }
        }
    }

    public function column(?string $table, string $property): ?Column
    {
        return $table === null || $this->unreadable || isset($this->uncertain[$table])
            ? null
            : $this->tables[$table][$property] ?? null;
    }

    /** Source-only reasons for lost schema precision; they do not relax invalidation. */
    public function uncertainties(): array { return $this->uncertainties; }

    /** @param array{bool,array<string,true>} $before */
    private function recordUncertainty(string $file, Node $node, array $before): void
    {
        $global = $this->unreadable && ! $before[0];
        $tables = array_keys(array_diff_key($this->uncertain, $before[1]));
        if (! $global && $tables === []) { return; }
        $this->uncertainties[] = ['file' => $file, 'line' => $node->getStartLine(),
            'scope' => $global ? 'all-tables' : 'tables', 'tables' => $global ? [] : $tables];
    }

    /** @return list<string> */
    private static function configuredPaths(mixed $config): array
    {
        if (
            ! is_array($config)
            || ! is_array($config['extra'] ?? [])
            || ! is_array($config['extra']['laramago'] ?? [])
        ) {
            throw new \RuntimeException('Composer extra.laramago must be an object.');
        }

        return self::pathList($config['extra']['laramago']['migration-paths'] ?? []);
    }

    /** @return list<string> */
    private static function pathList(mixed $paths): array
    {
        if (! is_array($paths) || ! array_is_list($paths)) {
            throw new \RuntimeException('extra.laramago.migration-paths must be a list of non-empty directory paths.');
        }

        return array_values(array_map(static function (mixed $path): string {
            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('extra.laramago.migration-paths must contain non-empty directory paths.');
            }

            return $path;
        }, $paths));
    }

    private function isSchema(Node\Expr\StaticCall $call): bool
    {
        return (
            $call->class instanceof Node\Name
            && in_array(strtolower($call->class->toString()), ['schema', 'illuminate\\support\\facades\\schema'], true)
        );
    }

    private function schemaCall(Node\Expr\StaticCall $call, ?Node\Stmt\Class_ $class = null): void
    {
        if (MigrationMetadataRead::schema($call) || MigrationMetadataRead::foreignKeys($call)) {
            // Arguments still run before a native read, and may contain raw SQL
            // or unknown helpers. Inspect their effects instead of the getter.
            $this->invalidateStatements($call->args, $class);

            return;
        }
        $method = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
        $table = PhpSource::value(PhpSource::argument(
            $call->args,
            0,
            in_array($method, ['rename'], true) ? 'from' : 'table',
        ));
        if (! is_string($table) || $method === 'connection') {
            $this->unreadable = true;

            return;
        }
        if (in_array($method, ['drop', 'dropifexists'], true)) {
            unset($this->tables[$table]);

            return;
        }
        if ($method === 'rename') {
            $to = PhpSource::value(PhpSource::argument($call->args, 1, 'to'));
            if (is_string($to)) {
                $this->tables[$to] = $this->tables[$table] ?? [];
                if (isset($this->uncertain[$table])) {
                    $this->uncertain[$to] = true;
                }
                unset($this->tables[$table]);
            } else {
                $this->unreadable = true;
            }

            return;
        }
        if (! in_array($method, ['create', 'table'], true)) {
            $this->uncertain[$table] = true;

            return;
        }
        $callback = PhpSource::argument($call->args, 1, 'callback');
        if (
            ! $callback instanceof Node\Expr\Closure
            || ! isset($callback->params[0])
            || ! $callback->params[0]->var instanceof Node\Expr\Variable
            || ! is_string($callback->params[0]->var->name)
        ) {
            $this->uncertain[$table] = true;

            return;
        }
        if ($method === 'create') {
            $this->tables[$table] = [];
            unset($this->uncertain[$table]);
        }
        $blueprint = $callback->params[0]->var->name;
        $preparation = new SchemaPreparation($callback);
        foreach ($callback->stmts as $statement) {
            // Snapshot before accepts() expires values mentioned by this statement.
            $arguments = clone $preparation;
            if ($preparation->accepts($statement)) {
                continue;
            }
            if ($statement instanceof Node\Stmt\Foreach_) {
                $this->blueprintLoop($table, $blueprint, $statement, $arguments);
                continue;
            }
            if (! $statement instanceof Node\Stmt\Expression || ! $statement->expr instanceof Node\Expr\MethodCall) {
                $this->uncertain[$table] = true;
                continue;
            }
            [$receiver, $chain] = PhpSource::chain($statement->expr);
            if (! $receiver instanceof Node\Expr\Variable || $receiver->name !== $blueprint) {
                $this->uncertain[$table] = true;
                continue;
            }
            if (! $arguments->safeArguments($chain)) {
                $this->uncertain[$table] = true;
                continue;
            }
            $this->blueprint($table, $chain, $arguments);
        }
    }

    private function blueprintLoop(
        string $table,
        string $blueprint,
        Node\Stmt\Foreach_ $loop,
        SchemaPreparation $preparation,
    ): void {
        $iterations = $preparation->iterations($loop);
        if ($iterations === null) {
            $this->uncertain[$table] = true;

            return;
        }
        // No assignments, branching, nested loops, break/continue, callbacks or
        // helpers: every body statement must be a direct Blueprint declaration.
        $chains = [];
        foreach ($loop->stmts as $statement) {
            if ($statement instanceof Node\Stmt\Nop) {
                continue;
            }
            if (! $statement instanceof Node\Stmt\Expression || ! $statement->expr instanceof Node\Expr\MethodCall) {
                $this->uncertain[$table] = true;

                return;
            }
            [$receiver, $chain] = PhpSource::chain($statement->expr);
            if (! $receiver instanceof Node\Expr\Variable || $receiver->name !== $blueprint) {
                $this->uncertain[$table] = true;

                return;
            }
            $chains[] = [$statement, $chain];
        }
        foreach ($iterations as $iteration) {
            foreach ($chains as [$statement, $chain]) {
                $arguments = clone $iteration;
                $iteration->accepts($statement);
                if (! $arguments->safeArguments($chain)) {
                    $this->uncertain[$table] = true;

                    return;
                }
                $this->blueprint($table, $chain, $arguments);
            }
        }
    }

    /** @param array<int, true> $visited Helpers and guards expanded in this call chain. */
    private function migrationStatement(string $file, Node\Stmt $statement, Node\Stmt\Class_ $class, array $visited, array $uses = [], array $sharedClosures = []): void
    {
        $before = [$this->unreadable, $this->uncertain];
        if ($statement instanceof Node\Stmt\If_ && count($visited) < 64) {
            $execute = $this->columnGuard($statement);
            if ($execute !== null) {
                if ($execute) {
                    $visited[spl_object_id($statement)] = true;
                    foreach ($statement->stmts as $inner) {
                        $this->migrationStatement($file, $inner, $class, $visited, $uses, $sharedClosures);
                    }
                }

                return;
            }
        }
        if ($statement instanceof Node\Stmt\Foreach_) {
            $calls = MigrationTableLoop::expand($statement, $class);
            if ($calls !== null) {
                foreach ($calls as $call) {
                    $this->schemaCall($call, $class);
                }
                $this->recordUncertainty($file, $statement, $before);

                return;
            }
        }
        if (
            $statement instanceof Node\Stmt\Expression
            && $statement->expr instanceof Node\Expr\StaticCall
            && $this->isSchema($statement->expr)
        ) {
            $this->schemaCall($statement->expr, $class);
            $this->recordUncertainty($file, $statement, $before);

            return;
        }
        $method = $statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\CallLike
            ? $this->localMethod($statement->expr, $class)
            : null;
        if (
            $method !== null
            && ! isset($visited[spl_object_id($method)])
            && count($visited) < 64
        ) {
            // Arguments execute even when the resolved helper ignores them.
            // Keep raw SQL and includes visible before expanding its body.
            $this->invalidateStatements($statement->expr->getArgs(), $class);
            $this->recordUncertainty($file, $statement, $before);
            $visited[spl_object_id($method)] = true;
            // Closures bound anywhere in the helper body stay visible to
            // statements that invoke them later, like DB::transaction($alter).
            $sharedClosures += $this->boundClosures($method->stmts ?? []);
            foreach ($method->stmts ?? [] as $inner) {
                $this->migrationStatement($file, $inner, $class, $visited, $uses, $sharedClosures);
            }
            // Inner statements anchor their own uncertainty records.
            return;
        }
        $scan = ['visited' => [], 'schema' => false, 'unknownMethod' => false, 'depth' => 0, 'uses' => $uses, 'closures' => $sharedClosures];
        $this->invalidateStatements([$statement], $class, $scan);
        $this->recordUncertainty($file, $statement, $before);
    }

    /** Decide an idempotent column guard only from a complete earlier table. */
    private function columnGuard(Node\Stmt\If_ $statement): ?bool
    {
        $condition = $statement->cond;
        if ($this->unreadable || $statement->else !== null || $statement->elseifs !== []
            || ! $condition instanceof Node\Expr\BooleanNot
            || ! $condition->expr instanceof Node\Expr\StaticCall) {
            return null;
        }
        $call = $condition->expr;
        if (! $this->isSchema($call) || ! $call->name instanceof Node\Identifier
            || strcasecmp($call->name->toString(), 'hasColumn') !== 0 || count($call->args) !== 2) {
            return null;
        }
        foreach ($call->args as $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return null;
            }
        }
        $table = PhpSource::value(PhpSource::argument($call->args, 0, 'table'));
        $column = PhpSource::value(PhpSource::argument($call->args, 1, 'column'));
        if (! is_string($table) || ! is_string($column) || ! isset($this->tables[$table]) || isset($this->uncertain[$table])) {
            return null;
        }

        return ! array_key_exists($column, $this->tables[$table]);
    }

    /**
     * @param array<array-key, Node> $nodes
     * @param array{visited:array<int,true>,schema:bool,unknownMethod:bool,depth:int} $scan
     */
    private function invalidateStatements(array $nodes, ?Node\Stmt\Class_ $class = null, array &$scan = ['visited' => [], 'schema' => false, 'unknownMethod' => false, 'depth' => 0]): void
    {
        ++$scan['depth'];
        if ((new NodeFinder)->findFirst($nodes, static fn (Node $node): bool =>
            $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\ShellExec) !== null) {
            $this->unreadable = true;
        }
        $guardExceptions = MigrationMetadataRead::guardExceptions($nodes);
        foreach ($this->loopTables($nodes, $class) as $name => $set) {
            foreach ($set as $table => $_) {
                $scan['loopTables'][$name][$table] = true;
            }
        }
        foreach ($this->boundClosures($nodes) as $name => $closure) {
            $scan['closures'][$name] = $closure;
        }
        foreach ($this->dataReceivers($nodes, $scan['uses'] ?? []) as $name => $_) {
            $scan['dataVars'][$name] = true;
        }
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\CallLike::class) as $call) {
            if (isset($guardExceptions[spl_object_id($call)]) || MigrationMetadataRead::connection($call)
                || MigrationMetadataRead::collection($call)) {
                continue;
            }
            if ($call instanceof Node\Expr\StaticCall && $this->isSchema($call)) {
                if (MigrationMetadataRead::schema($call) || MigrationMetadataRead::foreignKeys($call)) {
                    continue;
                }
                $scan['schema'] = true;
                $table = PhpSource::value(PhpSource::argument($call->args, 0, 'table'));
                if (
                    is_string($table)
                    && $call->name instanceof Node\Identifier
                    && strtolower($call->name->toString()) !== 'connection'
                ) {
                    $this->uncertain[$table] = true;
                } else {
                    $bound = $this->boundTables(PhpSource::argument($call->args, 0, 'table'), $scan);
                    if ($bound !== null) {
                        foreach ($bound as $boundTable) {
                            $this->uncertain[$boundTable] = true;
                        }
                    } else {
                        $this->unreadable = true;
                    }
                }

                continue;
            }
            if ($call instanceof Node\Expr\StaticCall && MigrationMetadataRead::neutralStatic($call)) {
                continue;
            }
            if (
                $call instanceof Node\Expr\StaticCall
                && $call->class instanceof Node\Name
                && in_array(strtolower($call->class->toString()), ['db', 'illuminate\\support\\facades\\db'], true)
            ) {
                // Query-builder entry points never emit DDL; raw-SQL executors
                // (statement, select, unprepared, connection, ...) can change
                // any table, so they keep the whole corpus unreadable.
                if (
                    $call->name instanceof Node\Identifier
                    && in_array(strtolower($call->name->toString()), ['table', 'query', 'raw', 'transaction', 'begintransaction', 'commit', 'rollback'], true)
                ) {
                    if (strtolower($call->name->toString()) === 'transaction') {
                        $this->scanClosureArguments($call, $class, $scan);
                    }

                    continue;
                }
                if ($call->name instanceof Node\Identifier && $call->args === []
                    && in_array(strtolower($call->name->toString()), ['getdrivername', 'transactionlevel'], true)) {
                    continue;
                }
                if ($call->name instanceof Node\Identifier && strcasecmp($call->name->toString(), 'connection') === 0
                    && (new NodeFinder)->findFirst($nodes, static fn (Node $node): bool =>
                        $node instanceof Node\Expr\MethodCall && $node->var === $call && MigrationMetadataRead::connection($node)) !== null) {
                    continue;
                }
                if (MigrationMetadataRead::readOnlySql($call)) {
                    continue;
                }
                $this->unreadable = true;

                continue;
            }
            $method = $this->localMethod($call, $class);
            if ($method !== null) {
                // A repeated call to an already scanned helper adds no new
                // knowledge; a visit skipped over the bound stays unproven.
                if (isset($scan['visited'][spl_object_id($method)])) {
                    continue;
                }
                if (count($scan['visited']) >= 64) {
                    $scan['unknownMethod'] = true;

                    continue;
                }
                $scan['visited'][spl_object_id($method)] = true;
                $this->invalidateStatements($method->stmts ?? [], $class, $scan);

                continue;
            }
            $helper = $this->appMethod($call, $scan['uses'] ?? []);
            if ($helper !== null) {
                if (isset($scan['visited'][spl_object_id($helper[0])])) {
                    continue;
                }
                if (count($scan['visited']) >= 64) {
                    $scan['unknownMethod'] = true;

                    continue;
                }
                $scan['visited'][spl_object_id($helper[0])] = true;
                $prevUses = $scan['uses'] ?? [];
                $scan['uses'] = $helper[2];
                $this->invalidateStatements($helper[0]->stmts ?? [], $helper[1], $scan);
                $scan['uses'] = $prevUses;

                continue;
            }
            if ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) {
                // Query-builder chains must not escape to connection SQL.
                // Other chains stay suspicious. Blueprint
                // declarations inside Schema callbacks are judged by the
                // callback rules, not by generic call suspicion.
                $builder = false;
                if (! $this->schemaNeutralChain($call, $builder)) {
                    $root = $call;
                    while ($root instanceof Node\Expr\MethodCall || $root instanceof Node\Expr\NullsafeMethodCall) {
                        $root = $root->var;
                    }
                    if (
                        $root instanceof Node\Expr\Variable && is_string($root->name)
                        && isset($scan['dataVars'][$root->name])
                    ) {
                        continue;
                    }
                    $scan['unknownMethod'] = true;
                    if ($builder) {
                        // A nearby Schema callback does not contain this escape.
                        $this->unreadable = true;
                    }
                }

                continue;
            }
            if ($call instanceof Node\Expr\FuncCall) {
                if ($this->pureBuiltin($call) || $this->higherOrder($call)) {
                    continue;
                }
                if (MigrationMetadataRead::container($call)
                    && $this->containerConstruction($call, $scan)) {
                    continue;
                }
                $closure = $this->boundClosure($call, $scan);
                if ($closure !== null) {
                    if (
                        ! isset($scan['visited'][spl_object_id($closure)])
                        && count($scan['visited']) < 64
                    ) {
                        $scan['visited'][spl_object_id($closure)] = true;
                        $this->invalidateStatements($closure->stmts ?? [], $class, $scan);
                    }

                    continue;
                }
            }
            // A helper, raw SQL call or indirect schema builder can mutate tables
            // that cannot be identified from its arguments alone.
            $this->unreadable = true;
        }
        if (--$scan['depth'] === 0 && ! $scan['schema'] && $scan['unknownMethod']) {
            $this->unreadable = true;
        }
    }

    /**
     * A call resolvable to a method declared on the migration class itself.
     * Instance and late-static dispatch can reach subclass overrides, so they
     * resolve only for private methods or classes that cannot be extended.
     */
    private function localMethod(Node\Expr\CallLike $call, ?Node\Stmt\Class_ $class): ?Node\Stmt\ClassMethod
    {
        if ($class === null) {
            return null;
        }
        $instance = $call instanceof Node\Expr\MethodCall
            && $call->var instanceof Node\Expr\Variable
            && $call->var->name === 'this'
            && $call->name instanceof Node\Identifier;
        $static = $call instanceof Node\Expr\StaticCall
            && $call->class instanceof Node\Name
            && $call->name instanceof Node\Identifier;
        $staticScope = $static ? strtolower($call->class->toString()) : '';
        if (! $instance && ! ($static && $this->ownScope($staticScope, $class))) {
            return null;
        }
        foreach ($call->args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack) {
                return null;
            }
        }
        $method = $class->getMethod(
            ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) && $call->name instanceof Node\Identifier
                ? $call->name->toString()
                : '',
        );
        if ($method === null || $method->stmts === null) {
            return null;
        }
        // self:: binds to the declaring scope; $this-> and static:: dispatch.
        $dispatched = $instance || $staticScope === 'static';
        if ($dispatched && ! $method->isPrivate() && ! $class->isAnonymous() && ! $class->isFinal()) {
            return null;
        }

        return $method;
    }

    private function ownScope(string $scope, Node\Stmt\Class_ $class): bool
    {
        return $scope === 'self'
            || $scope === 'static'
            || ($class->name !== null && $scope === strtolower($class->name->toString()));
    }

    /**
     * A call resolvable to a method declared on an application class reached
     * through `app(Foo::class)->m()`, `Foo::m()` or `new Foo()->m()`. Vendor
     * helpers stay opaque: only `App\`-rooted classes are loaded and scanned.
     *
     * Short class names are resolved through the importing file's `use`
     * map; the returned element also carries the service file's own map
     * so nested calls resolve against its imports, not the caller's.
     *
     * @param array<string, string> $uses
     * @return array{Node\Stmt\ClassMethod,Node\Stmt\Class_,array<string, string>}|null
     */
    private function appMethod(Node\Expr\CallLike $call, array $uses = []): ?array
    {
        $fqcn = null;
        if ($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name
            && $call->name instanceof Node\Identifier) {
            $fqcn = $call->class->toString();
        } elseif (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall)
            && $call->name instanceof Node\Identifier) {
            $var = $call->var;
            if ($var instanceof Node\Expr\FuncCall && $var->name instanceof Node\Name
                && in_array(strtolower($var->name->getLast()), ['app', 'resolve'], true)) {
                $arg = PhpSource::argument($var->args, 0, 'abstract');
                if ($arg instanceof Node\Expr\ClassConstFetch && $arg->class instanceof Node\Name
                    && $arg->name instanceof Node\Identifier
                    && strcasecmp($arg->name->toString(), 'class') === 0) {
                    $fqcn = $arg->class->toString();
                } elseif ($arg instanceof Node\Scalar\String_) {
                    $fqcn = $arg->value;
                }
            } elseif ($var instanceof Node\Expr\New_ && $var->class instanceof Node\Name) {
                $fqcn = $var->class->toString();
            }
        }
        if ($fqcn === null) {
            return null;
        }
        $service = $this->serviceSource($fqcn, $uses);
        if ($service === null) {
            return null;
        }
        [$nodes, $serviceUses] = $service;
        $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $serviceClass) {
            $method = $serviceClass->getMethod($methodName);
            // Instance dispatch can reach a bound subclass override; only
            // final classes, private methods and exact static calls are
            // provably the body being scanned.
            if (
                $method !== null && $method->stmts !== null
                && ($serviceClass->isFinal() || $method->isPrivate() || $call instanceof Node\Expr\StaticCall)
            ) {
                return [$method, $serviceClass, $serviceUses];
            }
        }

        return null;
    }

    /**
     * Resolves a class string to the statements of its `app/` source file and
     * the service file's own `use` map, so nested calls inside the service
     * resolve against its imports. Vendor or missing classes stay opaque.
     *
     * @param array<string, string> $uses
     * @return array{list<Node>,array<string, string>}|null
     */
    private function serviceSource(string $fqcn, array $uses): ?array
    {
        $fqcn = ltrim($fqcn, '\\');
        if (! str_starts_with($fqcn, 'App\\')) {
            $first = strtolower(strtok($fqcn, '\\'));
            if (isset($uses[$first])) {
                $fqcn = $uses[$first].substr($fqcn, strlen($first));
            }
        }
        if (! str_starts_with($fqcn, 'App\\')) {
            return null;
        }
        $nodes = $this->source->read('app/'.str_replace('\\', '/', substr($fqcn, 4)).'.php');
        return $nodes === null ? null : [$nodes, $this->fileUses($nodes)];
    }

    /**
     * `app()`/`resolve()` without arguments only returns the container. A class
     * string resolution constructs the service, so the constructor body must be
     * provable like a helper method: a final `App` class is scanned, while
     * interfaces, string aliases, unresolved files and non-final targets keep
     * the index uncertain — bindings can substitute their construction.
     *
     * @param array{visited:array<int,true>,schema:bool,unknownMethod:bool,depth:int,uses?:array<string, string>} $scan
     */
    private function containerConstruction(Node\Expr\FuncCall $call, array &$scan): bool
    {
        if ($call->args === []) {
            return true;
        }
        $abstract = PhpSource::argument($call->args, 0, 'abstract')
            ?? PhpSource::argument($call->args, 0, 'name');
        $fqcn = null;
        if (
            $abstract instanceof Node\Expr\ClassConstFetch && $abstract->class instanceof Node\Name
            && $abstract->name instanceof Node\Identifier && strcasecmp($abstract->name->toString(), 'class') === 0
        ) {
            $fqcn = $abstract->class->toString();
        } elseif ($abstract instanceof Node\Scalar\String_) {
            $fqcn = $abstract->value;
        }
        if ($fqcn === null) {
            return false;
        }
        $service = $this->serviceSource($fqcn, $scan['uses'] ?? []);
        if ($service === null) {
            return false;
        }
        [$nodes, $serviceUses] = $service;
        $short = strtolower(substr(str_replace('\\', '/', $fqcn), strrpos(str_replace('\\', '/', $fqcn), '/') + 1));
        $serviceClass = null;
        $single = null;
        $named = 0;
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $candidate) {
            if ($candidate->name === null) {
                continue;
            }
            ++$named;
            $single ??= $candidate;
            if (strtolower($candidate->name->toString()) === $short) {
                $serviceClass = $candidate;

                break;
            }
        }
        $serviceClass ??= $named === 1 ? $single : null;
        if ($serviceClass === null || $serviceClass->isAbstract() || ! $serviceClass->isFinal()) {
            return false;
        }
        // A missing local constructor does not prove that construction is
        // inert: a parent or trait can supply it. Their construction graph
        // is not resolved by this bounded body scan.
        if ($serviceClass->extends !== null || $serviceClass->getTraitUses() !== []) {
            return false;
        }
        $constructor = $serviceClass->getMethod('__construct');
        if ($constructor === null) {
            return true;
        }
        if ($constructor->stmts === null) {
            return false;
        }
        // Container injection and object defaults can execute another
        // constructor before this body. Builtin scalar parameters remain
        // neutral; unresolved class types/default construction stay opaque.
        if ((new NodeFinder)->findFirst(
            $constructor->params,
            static fn (Node $node): bool => $node instanceof Node\Name || $node instanceof Node\Expr\New_,
        ) !== null) {
            return false;
        }
        // Same bounded body scan as application helper methods.
        if (isset($scan['visited'][spl_object_id($constructor)])) {
            return true;
        }
        if (count($scan['visited']) >= 64) {
            $scan['unknownMethod'] = true;

            return true;
        }
        $scan['visited'][spl_object_id($constructor)] = true;
        $prevUses = $scan['uses'] ?? [];
        $scan['uses'] = $serviceUses;
        $this->invalidateStatements($constructor->stmts, $serviceClass, $scan);
        $scan['uses'] = $prevUses;

        return true;
    }

    /**
     * `use` imports declared at file scope: lowercase alias to FQCN, so
     * short class names in calls can be resolved to `App\` paths.
     *
     * @param array<array-key, Node> $nodes
     * @return array<string, string>
     */
    private function fileUses(array $nodes): array
    {
        $uses = [];
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Use_::class) as $use) {
            if ($use->type !== Node\Stmt\Use_::TYPE_NORMAL) {
                continue;
            }
            foreach ($use->uses as $item) {
                $uses[strtolower($item->alias?->toString() ?? $item->name->getLast())] = $item->name->toString();
            }
        }
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\GroupUse::class) as $group) {
            if ($group->type !== Node\Stmt\GroupUse::TYPE_NORMAL) {
                continue;
            }
            $prefix = $group->prefix->toString();
            foreach ($group->uses as $item) {
                $uses[strtolower($item->alias?->toString() ?? $item->name->getLast())] = $prefix.'\\'.$item->name->toString();
            }
        }

        return $uses;
    }

    /**
     * Variables bound by foreach loops over a resolvable literal or class
     * constant table list: their Schema calls only touch the iterated set.
     *
     * @param array<array-key, Node> $nodes
     * @return array<string, array<string, true>>
     */
    private function loopTables(array $nodes, ?Node\Stmt\Class_ $class): array
    {
        if ($class === null) {
            return [];
        }
        $map = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Foreach_::class) as $loop) {
            $name = $loop->valueVar instanceof Node\Expr\Variable ? $loop->valueVar->name : null;
            if (! is_string($name) || $loop->byRef || $this->loopVarMutated($loop, $name)) {
                continue;
            }
            $set = MigrationTableLoop::tablesFor($loop->expr, $class);
            if ($set === null) {
                continue;
            }
            foreach ($set as $table) {
                $map[$name][$table] = true;
            }
        }

        return $map;
    }

    /**
     * Assignments, references and unsets of the loop variable anywhere in the
     * body invalidate the iterated-set binding.
     */
    private function loopVarMutated(Node\Stmt\Foreach_ $loop, string $name): bool
    {
        return (new NodeFinder)->findFirst($loop->stmts, static function (Node $node) use ($name): bool {
            $isName = static fn (?Node $target): bool => $target instanceof Node\Expr\Variable && $target->name === $name;
            if (
                ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp
                || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PreDec
                || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec)
                && $isName($node->var)
            ) {
                return true;
            }
            if ($node instanceof Node\Expr\AssignRef && ($isName($node->var) || $isName($node->expr))) {
                return true;
            }
            if ($node instanceof Node\Stmt\Unset_) {
                foreach ($node->vars as $var) {
                    if ($isName($var)) {
                        return true;
                    }
                }
            }
            if ($node instanceof Node\Expr\ClosureUse && $node->byRef && $isName($node->var)) {
                return true;
            }
            if ($node instanceof Node\Expr\ArrayItem && $node->byRef && $isName($node->value)) {
                return true;
            }
            if ($node instanceof Node\Stmt\Foreach_ && $node->byRef && $isName($node->expr)) {
                return true;
            }

            return false;
        }) !== null;
    }

    /** @param array{loopTables?:array<string,array<string,true>>} $scan @return list<string>|null */
    private function boundTables(?Node\Expr $argument, array $scan): ?array
    {
        if (! $argument instanceof Node\Expr\Variable || ! is_string($argument->name)) {
            return null;
        }
        $bound = $scan['loopTables'][$argument->name] ?? null;

        return $bound === null ? null : array_keys($bound);
    }

    /**
     * Variables assigned a literal closure: `$alter = function ...`; invoking
     * `$alter()` or passing `$alter` to `DB::transaction` runs the same body.
     *
     * @param array<array-key, Node> $nodes
     * @return array<string, Node\Expr\Closure|Node\Expr\ArrowFunction>
     */
    private function boundClosures(array $nodes): array
    {
        $closures = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\Assign::class) as $assign) {
            if (
                $assign->var instanceof Node\Expr\Variable
                && is_string($assign->var->name)
                && ($assign->expr instanceof Node\Expr\Closure || $assign->expr instanceof Node\Expr\ArrowFunction)
            ) {
                $closures[$assign->var->name] = $assign->expr;
            }
        }

        return $closures;
    }

    /** @param array{closures?:array<string,Node\Expr\Closure|Node\Expr\ArrowFunction>} $scan */
    private function boundClosure(Node\Expr\FuncCall $call, array $scan): Node\Expr\Closure|Node\Expr\ArrowFunction|null
    {
        if (! $call->name instanceof Node\Expr\Variable || ! is_string($call->name->name)) {
            return null;
        }

        return $scan['closures'][$call->name->name] ?? null;
    }

    /** @param array<string, mixed> $scan */
    private function scanClosureArguments(Node\Expr\StaticCall $call, ?Node\Stmt\Class_ $class, array &$scan): void
    {
        foreach ($call->args as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;
            }
            $closure = $arg->value instanceof Node\Expr\Variable && is_string($arg->value->name)
                ? $scan['closures'][$arg->value->name] ?? null
                : ($arg->value instanceof Node\Expr\Closure || $arg->value instanceof Node\Expr\ArrowFunction
                    ? $arg->value
                    : null);
            if ($closure === null || isset($scan['visited'][spl_object_id($closure)]) || count($scan['visited']) >= 64) {
                continue;
            }
            $scan['visited'][spl_object_id($closure)] = true;
            $body = $closure instanceof Node\Expr\Closure ? $closure->stmts ?? [] : [$closure->expr];
            $this->invalidateStatements($body, $class, $scan);
        }
    }

    /**
     * Query-builder chains must remain on the builder. Escaping through
     * getConnection() permits arbitrary SQL, and dynamic dispatch cannot
     * establish that the chain remains on the builder.
     */
    private function schemaNeutralChain(Node\Expr\CallLike $call, bool &$builder): bool
    {
        $var = $call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall
            ? $call
            : null;
        $guard = 0;
        $safe = true;
        while ($var instanceof Node\Expr\MethodCall || $var instanceof Node\Expr\NullsafeMethodCall) {
            if (++$guard > 64) { return false; }
            if (! $var->name instanceof Node\Identifier
                || strtolower($var->name->toString()) === 'getconnection') {
                $safe = false;
            }
            $var = $var->var;
        }

        $builder = $var instanceof Node\Expr\StaticCall
            && $var->class instanceof Node\Name
            && in_array(strtolower($var->class->toString()), ['db', 'illuminate\\support\\facades\\db'], true)
            && $var->name instanceof Node\Identifier
            && in_array(strtolower($var->name->toString()), ['table', 'query'], true);

        return $builder && $safe;
    }

    /**
     * Closure parameters typed as Laravel collections hold data rows, never
     * schema or connection handles — calls on them cannot emit DDL.
     *
     * @param array<array-key, Node> $nodes
     * @param array<string, string> $uses
     * @return array<string, true>
     */
    private function dataReceivers(array $nodes, array $uses): array
    {
        $vars = [];
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($nodes, Node\Expr\Closure::class) as $closure) {
            foreach ($closure->params as $param) {
                if (
                    $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                    && $param->type instanceof Node\Name
                    && $this->collectionType($param->type->toString(), $uses)
                ) {
                    $vars[$param->var->name] = true;
                }
            }
        }
        foreach ($finder->findInstanceOf($nodes, Node\Expr\ArrowFunction::class) as $fn) {
            foreach ($fn->params as $param) {
                if (
                    $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                    && $param->type instanceof Node\Name
                    && $this->collectionType($param->type->toString(), $uses)
                ) {
                    $vars[$param->var->name] = true;
                }
            }
        }

        return $vars;
    }

    /**
     * @param array<string, string> $uses
     */
    private function collectionType(string $type, array $uses): bool
    {
        $type = ltrim($type, '\\');
        $resolved = $uses[strtolower(strtok($type, '\\'))] ?? $type;

        return in_array(strtolower($resolved), [
            'collection',
            'illuminate\\support\\collection',
            'illuminate\\support\\lazycollection',
            'illuminate\\support\\enumerable',
            'illuminate\\pagination\\abstractpaginator',
        ], true);
    }

    /**
     * Builtins that only iterate data can run no SQL themselves; their
     * callback must be an inline closure whose body is scanned like any
     * other code. A string or variable callable stays unprovable.
     */
    private function higherOrder(Node\Expr\FuncCall $call): bool
    {
        if (! $call->name instanceof Node\Name) {
            return false;
        }
        static $callbackAt = [
            'array_map' => 0, 'array_filter' => 1, 'array_reduce' => 1,
            'array_walk' => 1, 'array_any' => 1, 'array_all' => 1,
            'array_find' => 1, 'array_find_key' => 1, 'array_some' => 1,
            'usort' => 1, 'uasort' => 1, 'uksort' => 1,
            'preg_replace_callback' => 1,
        ];
        $name = strtolower($call->name->toString());
        if (! isset($callbackAt[$name])) {
            return false;
        }
        $callback = PhpSource::argument($call->args, $callbackAt[$name], 'callback');

        return $callback === null
            || $callback instanceof Node\Expr\Closure
            || $callback instanceof Node\Expr\ArrowFunction;
    }

    /**
     * Internal PHP functions cannot contain Schema or DB calls; only the
     * side-effect-free subset that never invokes user callbacks counts.
     */
    private function pureBuiltin(Node\Expr\FuncCall $call): bool
    {
        if (! $call->name instanceof Node\Name) {
            return false;
        }
        static $pure = [
            'abs', 'array_chunk', 'array_column', 'array_combine', 'array_count_values',
            'array_diff', 'array_diff_key', 'array_fill', 'array_fill_keys', 'array_flip',
            'array_intersect', 'array_intersect_key', 'array_key_exists', 'array_key_first',
            'array_key_last', 'array_keys', 'array_merge', 'array_merge_recursive',
            'array_pad', 'array_pop', 'array_push', 'array_replace', 'array_reverse',
            'array_search', 'array_shift', 'array_slice', 'array_splice', 'array_sum',
            'array_unique', 'array_unshift', 'array_values', 'arsort', 'asort',
            'base64_decode', 'base64_encode', 'bin2hex', 'boolval', 'ceil', 'checkdate',
            'chr', 'chunk_split', 'constant', 'count', 'crc32', 'date', 'defined',
            'doubleval', 'explode', 'floatval', 'floor', 'fmod', 'gmdate', 'hex2bin',
            'implode', 'in_array', 'intdiv', 'intval', 'is_array', 'is_bool',
            'is_countable', 'is_double', 'is_float', 'is_int', 'is_integer', 'is_iterable',
            'is_long', 'is_null', 'is_numeric', 'is_object', 'is_scalar', 'is_string',
            'json_decode', 'json_encode', 'krsort', 'ksort', 'lcfirst', 'ltrim', 'max',
            'mb_strtolower', 'mb_strtoupper', 'mb_strlen', 'mb_substr', 'md5',
            'microtime', 'min', 'mktime', 'mt_rand', 'natcasesort', 'natsort', 'nl2br',
            'number_format', 'ord', 'pow', 'preg_last_error', 'preg_match',
            'preg_match_all', 'preg_quote', 'preg_replace', 'preg_split', 'rand',
            'round', 'rsort', 'rtrim', 'sha1', 'sizeof', 'sort', 'sqrt', 'sprintf',
            'str_contains', 'str_ends_with', 'str_pad', 'str_repeat', 'str_replace',
            'str_split', 'str_starts_with', 'strcasecmp', 'strcmp', 'strip_tags',
            'stripos', 'strlen', 'strnatcasecmp', 'strnatcmp', 'strpos', 'strrev',
            'strrpos', 'strtolower', 'strtotime', 'strtoupper', 'strval', 'substr',
            'substr_count', 'substr_replace', 'time', 'trim', 'ucfirst', 'ucwords',
            'vsprintf', 'wordwrap',
        ];

        return in_array(strtolower($call->name->toString()), $pure, true);
    }

    /** @param list<Node\Expr\MethodCall> $chain */
    private function blueprint(string $table, array $chain, SchemaPreparation $arguments): void
    {
        if ($chain === []) {
            $this->uncertain[$table] = true;

            return;
        }
        $call = $chain[0];
        $method = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
        if (in_array(
            $method,
            [
                'index',
                'unique',
                'primary',
                'foreign',
                'dropindex',
                'dropunique',
                'dropprimary',
                'dropforeign',
                'renameindex',
                'comment',
                'engine',
                'charset',
                'collation',
            ],
            true,
        )) {
            return;
        }
        $name = $arguments->value(PhpSource::argument($call->args, 0, 'column'));
        if ($method === 'dropcolumn' || $method === 'dropconstrainedforeignid') {
            if ($method === 'dropcolumn') {
                $name = $arguments->value(PhpSource::argument($call->args, 0, 'columns'));
                if (count($call->args) > 1) {
                    $name = array_map(static fn (Node\Arg|Node\VariadicPlaceholder $arg): mixed => $arg
                        instanceof Node\Arg
                        && ! $arg->unpack
                        && $arg->name === null
                            ? $arguments->value($arg->value)
                            : UnknownValue::Value, $call->args);
                }
            }
            $names = is_array($name) ? $name : [$name];
            foreach ($names as $column) {
                if (is_string($column)) {
                    unset($this->tables[$table][$column]);
                } else {
                    $this->uncertain[$table] = true;
                }
            }

            return;
        }
        if ($method === 'renamecolumn') {
            $from = $arguments->value(PhpSource::argument($call->args, 0, 'from'));
            $to = $arguments->value(PhpSource::argument($call->args, 1, 'to'));
            if (is_string($from) && is_string($to)) {
                unset($this->tables[$table][$to]);
                if (isset($this->tables[$table][$from])) {
                    $this->tables[$table][$to] = $this->tables[$table][$from];
                }
                unset($this->tables[$table][$from]);
            } else {
                $this->uncertain[$table] = true;
            }

            return;
        }
        if (in_array($method, ['timestamps', 'timestampstz', 'nullabletimestamps'], true)) {
            foreach (['created_at', 'updated_at'] as $column) {
                $this->tables[$table][$column] = new Column('timestamp', true);
            }

            return;
        }
        if (in_array($method, ['droptimestamps', 'droptimestampstz'], true)) {
            unset($this->tables[$table]['created_at'], $this->tables[$table]['updated_at']);

            return;
        }
        // This is a Blueprint method name, not authentication material.
        // @mago-expect lint:no-insecure-comparison
        if ($method === 'remembertoken') {
            $this->tables[$table]['remember_token'] = new Column('string', true);

            return;
        }
        if (in_array(
            $method,
            ['softdeletes', 'softdeletestz', 'softdeletesdatetime', 'dropsoftdeletes', 'dropsoftdeletestz'],
            true,
        )) {
            $name = PhpSource::argument($call->args, 0, 'column') === null ? 'deleted_at' : $name;
            if (! is_string($name)) {
                $this->uncertain[$table] = true;
            } elseif (str_starts_with($method, 'drop')) {
                unset($this->tables[$table][$name]);
            } else {
                $this->tables[$table][$name] = new Column('timestamp', true);
            }

            return;
        }
        if (in_array(
            $method,
            [
                'morphs',
                'nullablemorphs',
                'uuidmorphs',
                'nullableuuidmorphs',
                'ulidmorphs',
                'nullableulidmorphs',
                'dropmorphs',
            ],
            true,
        )) {
            $name = $arguments->value(PhpSource::argument($call->args, 0, 'name'));
            if (! is_string($name)) {
                $this->uncertain[$table] = true;

                return;
            }
            if ($method === 'dropmorphs') {
                unset($this->tables[$table][$name.'_id'], $this->tables[$table][$name.'_type']);
            } else {
                $nullable = str_starts_with($method, 'nullable');
                $this->tables[$table][$name.'_type'] = new Column('string', $nullable);
                $this->tables[$table][$name.'_id'] = new Column(
                    str_contains($method, 'uuid') || str_contains($method, 'ulid') ? 'string' : 'morph-key',
                    $nullable,
                );
            }

            return;
        }
        $type = match ($method) {
            'id',
            'increments',
            'tinyincrements',
            'smallincrements',
            'mediumincrements',
            'bigincrements',
            'integer',
            'tinyinteger',
            'smallinteger',
            'mediuminteger',
            'biginteger',
            'unsignedinteger',
            'unsignedtinyinteger',
            'unsignedsmallinteger',
            'unsignedmediuminteger',
            'unsignedbiginteger',
            'foreignid',
                => 'int',
            'float', 'double', 'real' => 'float',
            'decimal', 'unsigneddecimal' => 'decimal',
            'boolean' => 'bool',
            'date', 'datetime', 'datetimetz', 'timestamp', 'timestamptz' => 'timestamp',
            'char',
            'string',
            'text',
            'tinytext',
            'mediumtext',
            'longtext',
            'enum',
            'set',
            'json',
            'jsonb',
            'uuid',
            'ulid',
            'foreignuuid',
            'foreignulid',
            'time',
            'timetz',
            'year',
            'ipaddress',
            'macaddress',
                => 'string',
            default => null,
        };
        if ($method === 'id' && PhpSource::argument($call->args, 0, 'column') === null) {
            $name = 'id';
        }
        if ($type === null || ! is_string($name)) {
            $this->uncertain[$table] = true;

            return;
        }
        $nullable = false;
        foreach (array_slice($chain, 1) as $modifier) {
            $modifierName = $modifier->name instanceof Node\Identifier ? strtolower($modifier->name->toString()) : '';
            if ($modifierName === 'nullable') {
                $argument = PhpSource::argument($modifier->args, 0, 'value');
                $value = $argument === null ? true : $arguments->value($argument);
                if (! is_bool($value)) {
                    unset($this->tables[$table][$name]);

                    return;
                }
                $nullable = $value;
            } elseif (! in_array(
                $modifierName,
                [
                    'change',
                    'default',
                    'unsigned',
                    'index',
                    'unique',
                    'primary',
                    'constrained',
                    'references',
                    'on',
                    'ondelete',
                    'onupdate',
                    'cascadeondelete',
                    'cascadeonupdate',
                    'restrictondelete',
                    'restrictonupdate',
                    'nullondelete',
                    'nullonupdate',
                    'noactionondelete',
                    'noactiononupdate',
                    'after',
                    'first',
                    'comment',
                    'autoincrement',
                    'usecurrent',
                    'usecurrentonupdate',
                    'storedas',
                    'virtualas',
                    'charset',
                    'collation',
                ],
                true,
            )) {
                unset($this->tables[$table][$name]);

                return;
            }
        }
        $this->tables[$table][$name] = new Column($type, $nullable);
    }
}
