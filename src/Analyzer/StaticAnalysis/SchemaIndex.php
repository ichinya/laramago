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
                    $scan = ['visited' => [], 'unknownMethod' => false, 'depth' => 0, 'uses' => $uses];
                    $this->invalidateStatements($up, $class, $scan);
                    $this->recordUncertainty($file, $class->getMethod('up') ?? $class, $before);
                    continue;
                }
                $closures = $this->boundClosures($up);
                foreach ($up as $statement) {
                    $this->migrationStatement($file, $statement, $class, [], $uses, $closures);
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
        $blueprintCalls = $this->schemaBlueprintCalls($call);
        if ($blueprintCalls === null) { $this->unreadable = true; }
        $scan = ['visited' => [], 'unknownMethod' => false, 'depth' => 0,
            'blueprintCalls' => $blueprintCalls ?? []];
        $this->invalidateStatements($call->args, $class, $scan);
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
            // Use-site identities belong to this helper's lexical scope.
            $sharedClosures = $this->boundClosures($method->stmts ?? []);
            foreach ($method->stmts ?? [] as $inner) {
                $this->migrationStatement($file, $inner, $class, $visited, $uses, $sharedClosures);
            }
            // Inner statements anchor their own uncertainty records.
            return;
        }
        $scan = ['visited' => [], 'unknownMethod' => false, 'depth' => 0, 'uses' => $uses, 'closures' => $sharedClosures];
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
     * @param array{visited:array<int,true>,unknownMethod:bool,depth:int} $scan
     */
    private function invalidateStatements(array $nodes, ?Node\Stmt\Class_ $class = null, array &$scan = ['visited' => [], 'unknownMethod' => false, 'depth' => 0]): void
    {
        ++$scan['depth'];
        if ((new NodeFinder)->findFirst($nodes, static fn (Node $node): bool =>
            $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\ShellExec) !== null) {
            $this->unreadable = true;
        }
        $guardExceptions = MigrationMetadataRead::guardExceptions($nodes);
        foreach ($this->loopTables($nodes, $class) as $id => $set) {
            foreach ($set as $table => $_) {
                $scan['loopTables'][$id][$table] = true;
            }
        }
        if (! array_key_exists('closures', $scan)) {
            $scan['closures'] = $this->boundClosures($nodes);
        }
        foreach ($this->dataReceivers($nodes, $scan['uses'] ?? []) as $id => $_) {
            $scan['dataCalls'][$id] = true;
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
                $blueprintCalls = $this->schemaBlueprintCalls($call);
                if ($blueprintCalls === null) { $this->unreadable = true; }
                foreach ($blueprintCalls ?? [] as $id => $_) { $scan['blueprintCalls'][$id] = true; }
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
                    if (in_array(strtolower($call->name->toString()), ['table', 'query'], true)
                        && ! $this->dataRootNeutral($call, $scan)) { $this->unreadable = true; }
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
                $this->invalidateFunction($method, $class, $scan);

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
                $this->invalidateFunction($helper[0], $helper[1], $scan);
                $scan['uses'] = $prevUses;

                continue;
            }
            if ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) {
                // Query-builder chains must not escape to connection SQL.
                // Other chains stay suspicious. Blueprint
                // declarations inside Schema callbacks are judged by the
                // callback rules, not by generic call suspicion.
                $builder = false;
                if (! $this->schemaNeutralChain($call, $builder, $scan)) {
                    if (isset($scan['blueprintCalls'][spl_object_id($call)])
                        || isset($scan['dataCalls'][spl_object_id($call)])) {
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
                        $this->invalidateFunction($closure, $class, $scan);
                    } elseif (! isset($scan['visited'][spl_object_id($closure)])) {
                        $this->unreadable = true;
                    }

                    continue;
                }
            }
            // A helper, raw SQL call or indirect schema builder can mutate tables
            // that cannot be identified from its arguments alone.
            $this->unreadable = true;
        }
        if (--$scan['depth'] === 0 && $scan['unknownMethod']) {
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

        return $this->opaqueReferenceArguments($call, $method) ? null : $method;
    }

    /** A used reference parameter can replace a caller binding; unused ones cannot. */
    private function opaqueReferenceArguments(Node\Expr\CallLike $call, Node\FunctionLike $function): bool
    {
        $unpacked = false;
        foreach ($call->getArgs() as $arg) {
            if ($arg instanceof Node\Arg && $arg->unpack) { $unpacked = true; }
        }
        foreach ($function->getParams() as $offset => $parameter) {
            if (! $parameter->byRef || ! is_string($parameter->var->name)) { continue; }
            $name = $parameter->var->name;
            if (! $unpacked && PhpSource::argument($call->getArgs(), $offset, $name) === null
                && (! $parameter->variadic || $call->getArgs() === [])) { continue; }
            if ((new NodeFinder)->findFirst($function->getStmts() ?? [], static fn (Node $node): bool =>
                $node instanceof Node\Expr\Variable && $node->name === $name) !== null) { return true; }
        }

        return false;
    }

    /**
     * Native output parameters write without an explicit call-site `&`.
     * Keep signatures static for the builtins already accepted by this scan;
     * source proofs must not depend on the worker's installed extensions.
     *
     * @return list<Node\Expr>
     */
    private function builtinReferenceArguments(Node\Expr\FuncCall $call): array
    {
        if (! $call->name instanceof Node\Name || $call->isFirstClassCallable()) { return []; }
        $parameter = match (strtolower($call->name->toString())) {
            'array_walk', 'usort', 'uasort', 'uksort',
            'array_pop', 'array_push', 'array_shift', 'array_splice', 'array_unshift',
            'arsort', 'asort', 'krsort', 'ksort', 'natcasesort', 'natsort', 'rsort', 'sort' => [0, 'array'],
            'preg_match', 'preg_match_all' => [2, 'matches'],
            'str_replace' => [3, 'count'],
            'preg_replace', 'preg_replace_callback' => [4, 'count'],
            default => null,
        };
        if ($parameter === null) { return []; }
        foreach ($call->args as $arg) {
            if ($arg instanceof Node\Arg && $arg->unpack) {
                // Unpacking obscures formal positions and may carry references.
                return array_values(array_map(static fn (Node\Arg $arg): Node\Expr => $arg->value,
                    array_filter($call->args, static fn (Node $arg): bool => $arg instanceof Node\Arg)));
            }
        }
        $argument = PhpSource::argument($call->args, $parameter[0], $parameter[1]);

        return $argument === null ? [] : [$argument];
    }

    /** @param array<string, mixed> $scan */
    private function invalidateFunction(Node\FunctionLike $function, ?Node\Stmt\Class_ $class, array &$scan): void
    {
        $previous = $scan['closures'];
        $body = $function->getStmts() ?? [];
        $scan['closures'] = $this->boundClosures($body);
        $this->invalidateStatements($body, $class, $scan);
        $scan['closures'] = $previous;
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
                return $this->opaqueReferenceArguments($call, $method) ? null : [$method, $serviceClass, $serviceUses];
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
     * @param array{visited:array<int,true>,unknownMethod:bool,depth:int,uses?:array<string, string>} $scan
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
        $this->invalidateFunction($constructor, $serviceClass, $scan);
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
     * @return array<int, array<string, true>>
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
            // Bind the actual argument nodes in this loop, not every variable
            // with the same spelling in the enclosing block or another helper.
            $walk = function (Node $node) use (&$walk, &$map, $name, $set): void {
                if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction
                    || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassLike) { return; }
                if ($node instanceof Node\Expr\StaticCall && ($this->isSchema($node)
                    || (MigrationMetadataRead::database($node) && $node->name instanceof Node\Identifier
                        && strcasecmp($node->name->toString(), 'table') === 0))) {
                    $argument = PhpSource::argument($node->args, 0, 'table');
                    if ($argument instanceof Node\Expr\Variable && $argument->name === $name) {
                        foreach ($set as $table) { $map[spl_object_id($argument)][$table] = true; }
                    }
                }
                foreach ($node->getSubNodeNames() as $key) {
                    $value = $node->$key;
                    foreach (is_array($value) ? $value : [$value] as $child) {
                        if ($child instanceof Node) { $walk($child); }
                    }
                }
            };
            foreach ($loop->stmts as $statement) { $walk($statement); }
        }

        return $map;
    }

    /**
     * Assignments, references and unsets of the loop variable anywhere in the
     * body invalidate the iterated-set binding.
     */
    private function loopVarMutated(Node\Stmt\Foreach_ $loop, string $name): bool
    {
        return (new NodeFinder)->findFirst($loop->stmts, function (Node $node) use ($name): bool {
            $isName = static fn (?Node $target): bool => $target !== null && (new NodeFinder)->findFirst(
                [$target], static fn (Node $var): bool => $var instanceof Node\Expr\Variable
                    && (! is_string($var->name) || $var->name === $name),
            ) !== null;
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
            if ($node instanceof Node\Expr\FuncCall) {
                foreach ($this->builtinReferenceArguments($node) as $argument) {
                    if ($isName($argument)) { return true; }
                }
            }
            if ($node instanceof Node\Stmt\Foreach_ && ($isName($node->valueVar) || $isName($node->keyVar)
                || ($node->byRef && $isName($node->expr)))) {
                return true;
            }

            return false;
        }) !== null;
    }

    /** @param array{loopTables?:array<int,array<string,true>>} $scan @return list<string>|null */
    private function boundTables(?Node\Expr $argument, array $scan): ?array
    {
        if (! $argument instanceof Node\Expr\Variable || ! is_string($argument->name)) {
            return null;
        }
        $bound = $scan['loopTables'][spl_object_id($argument)] ?? null;

        return $bound === null ? null : array_keys($bound);
    }

    /**
     * Proven variable-use sites, not names shared across lexical scopes.
     * A single plain assignment must dominate the use in the same statement
     * list. Rewrites, references, jumps and captured callables stay opaque.
     *
     * @param array<array-key, Node> $nodes
     * @return array<int, Node\Expr\Closure|Node\Expr\ArrowFunction>
     */
    private function boundClosures(array $nodes): array
    {
        $bindings = [];
        $scope = function (array $body, int $depth, array $blocked = []) use (&$scope, &$bindings): void {
            if ($depth > 64) { return; }
            $writes = $this->closureWrites($body);
            foreach ($blocked as $name) { $writes[$name] = 2; }
            $allowed = ! isset($writes['*']) && ! isset($writes['@jump']);
            $count = 0;
            $region = function (array $parts, array $inherited, int $level) use (&$region, &$scope, &$bindings, $writes, $allowed, &$count): void {
                if ($level > 64) { return; }
                $candidates = $inherited;
                foreach ($parts as $statement) {
                    $assign = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
                    if (! $allowed || ! $assign instanceof Node\Expr\Assign
                        || ! $assign->var instanceof Node\Expr\Variable || ! is_string($assign->var->name)
                        || ($writes[$assign->var->name] ?? 0) !== 1
                        || (! $assign->expr instanceof Node\Expr\Closure && ! $assign->expr instanceof Node\Expr\ArrowFunction)
                        || $assign->getEndFilePos() < 0 || $count >= 64) { continue; }
                    $candidates[$assign->var->name] = [$assign->expr, $assign->getEndFilePos()];
                    ++$count;
                }
                $walk = function (Node $node) use (&$walk, &$region, &$scope, &$bindings, $candidates, $level): void {
                    if ($node instanceof Node\FunctionLike) {
                        $blocked = [];
                        foreach ($node->getParams() as $param) {
                            if ($param->byRef && is_string($param->var->name)) { $blocked[] = $param->var->name; }
                        }
                        if ($node instanceof Node\Expr\Closure) {
                            foreach ($node->uses as $use) {
                                if ($use->byRef && is_string($use->var->name)) { $blocked[] = $use->var->name; }
                            }
                        }
                        $scope($node->getStmts() ?? [], $level + 1, $blocked);

                        return;
                    }
                    if ($node instanceof Node\Stmt\ClassLike) {
                        foreach ($node->getMethods() as $method) { $scope($method->stmts ?? [], $level + 1); }

                        return;
                    }
                    if ($node instanceof Node\Expr\Variable && is_string($node->name)
                        && isset($candidates[$node->name]) && $node->getStartFilePos() > $candidates[$node->name][1]) {
                        $bindings[spl_object_id($node)] = $candidates[$node->name][0];
                    }
                    foreach ($node->getSubNodeNames() as $key) {
                        $value = $node->$key;
                        if (is_array($value)) {
                            $region(array_filter($value, static fn (mixed $child): bool => $child instanceof Node), $candidates, $level + 1);
                        } elseif ($value instanceof Node) {
                            $walk($value);
                        }
                    }
                };
                foreach ($parts as $part) { $walk($part); }
            };
            $region($body, [], $depth);
        };
        $scope($nodes, 0);

        return $bindings;
    }

    /** @param array<array-key, Node> $nodes @return array<string, int> */
    private function closureWrites(array $nodes): array
    {
        $writes = [];
        $record = static function (?Node $target, int $weight = 1) use (&$writes): void {
            foreach ((new NodeFinder)->findInstanceOf($target === null ? [] : [$target], Node\Expr\Variable::class) as $var) {
                $name = is_string($var->name) ? $var->name : '*';
                $writes[$name] = ($writes[$name] ?? 0) + $weight;
            }
        };
        $walk = function (Node $node) use (&$walk, &$writes, $record): void {
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                if ($node instanceof Node\Expr\Closure) {
                    foreach ($node->uses as $use) { if ($use->byRef) { $record($use->var, 2); } }
                }

                return;
            }
            if ($node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label) { $writes['@jump'] = 1; }
            if ($node instanceof Node\Expr\AssignRef) {
                $record($node->var, 2); $record($node->expr, 2);
            } elseif ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp
                || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc
                || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec) {
                $record($node->var);
            } elseif ($node instanceof Node\Stmt\Foreach_) {
                $record($node->keyVar); $record($node->valueVar);
                if ($node->byRef) { $record($node->expr, 2); }
            } elseif ($node instanceof Node\Stmt\Catch_) {
                $record($node->var);
            } elseif ($node instanceof Node\Stmt\Unset_ || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_) {
                foreach ($node->vars as $var) { $record($var); }
            } elseif (($node instanceof Node\Expr\ArrayItem || $node instanceof Node\Arg) && $node->byRef) {
                $record($node->value, 2);
            }
            if ($node instanceof Node\Expr\FuncCall) {
                foreach ($this->builtinReferenceArguments($node) as $argument) { $record($argument, 2); }
            }
            foreach ($node->getSubNodeNames() as $key) {
                $value = $node->$key;
                foreach (is_array($value) ? $value : [$value] as $child) { if ($child instanceof Node) { $walk($child); } }
            }
        };
        foreach ($nodes as $node) { $walk($node); }

        return $writes;
    }

    /** @param array{closures?:array<int,Node\Expr\Closure|Node\Expr\ArrowFunction>} $scan */
    private function boundClosure(Node\Expr\FuncCall $call, array $scan): Node\Expr\Closure|Node\Expr\ArrowFunction|null
    {
        if (! $call->name instanceof Node\Expr\Variable || ! is_string($call->name->name)) {
            return null;
        }

        $closure = $scan['closures'][spl_object_id($call->name)] ?? null;

        return $closure === null || $this->opaqueReferenceArguments($call, $closure) ? null : $closure;
    }

    /** @param array<string, mixed> $scan */
    private function scanClosureArguments(Node\Expr\StaticCall $call, ?Node\Stmt\Class_ $class, array &$scan): void
    {
        $callback = PhpSource::argument($call->args, 0, 'callback');
        $closure = $callback instanceof Node\Expr\Variable
            ? $scan['closures'][spl_object_id($callback)] ?? null
            : ($callback instanceof Node\Expr\Closure || $callback instanceof Node\Expr\ArrowFunction ? $callback : null);
        if ($closure === null) { $this->unreadable = true; return; }
        if (isset($scan['visited'][spl_object_id($closure)])) { return; }
        if (count($scan['visited']) >= 64) { $this->unreadable = true; return; }
        $scan['visited'][spl_object_id($closure)] = true;
        $this->invalidateFunction($closure, $class, $scan);
    }

    /**
     * Query-builder chains must remain on the builder. Escaping through
     * getConnection() permits arbitrary SQL, and dynamic dispatch cannot
     * establish that the chain remains on the builder.
     */
    /** @param array<string, mixed> $scan */
    private function schemaNeutralChain(Node\Expr\CallLike $call, bool &$builder, array $scan): bool
    {
        $chain = $this->dataChain($call);
        if ($chain === null) { return false; }
        [$var, $calls] = $chain;
        $builder = $var instanceof Node\Expr\StaticCall && MigrationMetadataRead::database($var)
            && $var->name instanceof Node\Identifier
            && in_array(strtolower($var->name->toString()), ['table', 'query'], true);

        return $builder && $this->dataRootNeutral($var, $scan) && $this->dataCallsNeutral($calls, 'builder');
    }

    /** @return array{Node\Expr,list<Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall>}|null */
    private function dataChain(Node\Expr\CallLike $call): ?array
    {
        $var = $call;
        $calls = [];
        while ($var instanceof Node\Expr\MethodCall || $var instanceof Node\Expr\NullsafeMethodCall) {
            if (count($calls) >= 64) { return null; }
            $calls[] = $var;
            $var = $var->var;
        }

        return $calls === [] ? null : [$var, array_reverse($calls)];
    }

    /** A table source may itself be a subquery closure; unproved sources stay opaque.
     * @param array<string, mixed> $scan
     */
    private function dataRootNeutral(Node\Expr\StaticCall $call, array $scan): bool
    {
        if ($call->isFirstClassCallable()) { return false; }
        foreach ($call->args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack) { return false; }
        }
        if ($call->name instanceof Node\Identifier && strcasecmp($call->name->toString(), 'query') === 0) {
            return $call->args === [];
        }
        $table = PhpSource::argument($call->args, 0, 'table');

        return is_string(PhpSource::value($table)) || $this->boundTables($table, $scan) !== null
            || $table instanceof Node\Expr\Closure || $table instanceof Node\Expr\ArrowFunction;
    }

    /** @param list<Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall> $calls */
    private function dataCallsNeutral(array $calls, string $kind): bool
    {
        foreach ($calls as $call) {
            if (! in_array($kind, ['builder', 'collection'], true)) { return false; }
            $next = $this->dataMethodResult($call, $kind);
            if ($next === null) { return false; }
            $kind = $next;
        }

        return true;
    }

    /** A finite native contract: result context and callback positions are both required. */
    private function dataMethodResult(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call, string $kind): ?string
    {
        if (! $call->name instanceof Node\Identifier) { return null; }
        $name = strtolower($call->name->toString());
        $callbacks = [];
        if ($kind === 'builder') {
            $result = match ($name) {
                'where', 'orwhere', 'wherenull', 'orwherenull', 'wherenotnull', 'orwherenotnull',
                'wherein', 'orwherein', 'wherenotin', 'orwherenotin', 'wherecolumn', 'orwherecolumn',
                'wherebetween', 'wherenotbetween', 'whereexists', 'wherenotexists',
                'select', 'addselect', 'from', 'orderby', 'orderbydesc', 'latest', 'oldest', 'reorder',
                'limit', 'take', 'offset', 'skip', 'distinct', 'groupby', 'lockforupdate', 'sharedlock', 'tap' => 'builder',
                'get', 'pluck', 'chunkmap' => 'collection',
                'insert', 'insertorignore', 'update', 'upsert', 'delete', 'increment', 'decrement',
                'count', 'exists', 'doesntexist', 'value', 'first', 'find', 'sole', 'min', 'max', 'avg', 'sum',
                'chunk', 'chunkbyid', 'chunkbyiddesc', 'orderedchunkbyid', 'each', 'eachbyid',
                'pipe', 'when', 'unless' => 'value',
                default => null,
            };
            $callbacks = match ($name) {
                'tap', 'pipe', 'chunkmap', 'each', 'eachbyid' => [[0, 'callback', true]],
                'chunk', 'chunkbyid', 'chunkbyiddesc', 'orderedchunkbyid' => [[1, 'callback', true]],
                'when', 'unless' => [[1, 'callback', true], [2, 'default', false]],
                default => [],
            };
        } else {
            $result = match ($name) {
                'keys', 'values', 'pluck', 'reverse', 'slice', 'take', 'skip', 'chunk', 'merge', 'push',
                'prepend', 'put', 'forget', 'only', 'except', 'each', 'map', 'mapwithkeys', 'flatmap', 'filter', 'tap' => 'collection',
                'all', 'count', 'isempty', 'isnotempty', 'has', 'get', 'first', 'last',
                'reduce', 'reducespread', 'pipe' => 'value',
                default => null,
            };
            $callbacks = match ($name) {
                'each', 'map', 'mapwithkeys', 'flatmap', 'tap', 'pipe', 'reduce', 'reducespread' => [[0, 'callback', true]],
                'filter', 'first', 'last' => [[0, 'callback', false]],
                default => [],
            };
        }
        if ($result === null) { return null; }
        if ($call->isFirstClassCallable()) { return 'value'; }
        foreach ($call->args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack) { return null; }
        }
        foreach ($callbacks as [$position, $parameter, $required]) {
            $callback = PhpSource::argument($call->args, $position, $parameter);
            if ($callback === null && ! $required) { continue; }
            if (! $callback instanceof Node\Expr\Closure && ! $callback instanceof Node\Expr\ArrowFunction) { return null; }
        }
        // These native overloads may execute closure-valued query inputs or defaults.
        if ($kind === 'builder' && in_array($name, ['where', 'orwhere', 'wherein', 'orwherein', 'wherenotin',
            'orwherenotin', 'whereexists', 'wherenotexists', 'select', 'addselect', 'from', 'find', 'delete'], true)) {
            foreach ($call->args as $arg) { if (! $this->dataValueVisible($arg->value)) { return null; } }
        }
        if ($kind === 'builder' && in_array($name, ['orderby', 'orderbydesc', 'latest', 'oldest', 'reorder'], true)
            && ! $this->dataValueVisible(PhpSource::argument($call->args, 0, 'column'))) { return null; }
        if (in_array($name, ['when', 'unless'], true)
            && ! $this->dataValueVisible(PhpSource::argument($call->args, 0, 'value'))) { return null; }
        if ($kind === 'collection' && in_array($name, ['get', 'first', 'last'], true)
            && ! $this->dataValueVisible(PhpSource::argument($call->args, 1, 'default'))) { return null; }

        return $result;
    }

    /** Visible literals or inline bodies only; this is not variable/return-value interpretation. */
    private function dataValueVisible(?Node\Expr $value, int $depth = 0): bool
    {
        if ($depth > 32) { return false; }
        if ($value === null || $value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) { return true; }
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $item) {
                if ($item === null || $item->unpack || $item->byRef || ! $this->dataValueVisible($item->key, $depth + 1)
                    || ! $this->dataValueVisible($item->value, $depth + 1)) { return false; }
            }

            return true;
        }

        return PhpSource::value($value) !== UnknownValue::Value;
    }

    /**
     * @param array<array-key, Node> $nodes
     * @param array<string, string> $uses
     * @return array<int, true>
     */
    private function dataReceivers(array $nodes, array $uses): array
    {
        $calls = [];
        $finder = new NodeFinder;
        $closures = [...$finder->findInstanceOf($nodes, Node\Expr\Closure::class),
            ...$finder->findInstanceOf($nodes, Node\Expr\ArrowFunction::class)];
        foreach ($closures as $closure) {
            foreach ($closure->params as $param) {
                if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                    && $param->type instanceof Node\Name && $this->collectionType($param->type->toString(), $uses)) {
                    $body = $closure instanceof Node\Expr\Closure ? $closure->stmts : [$closure->expr];
                    foreach ($this->receiverCalls($body, $param->var->name) as $call) {
                        $chain = $this->dataChain($call);
                        if ($chain !== null && $this->dataCallsNeutral($chain[1], 'collection')) {
                            $calls[spl_object_id($call)] = true;
                        }
                    }
                }
            }
        }

        return $calls;
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
        $namespaced = $call->name->getAttribute('namespacedName');
        if ($namespaced instanceof Node\Name && strtolower($namespaced->toString()) !== $name) { return false; }
        if ($call->isFirstClassCallable()) { return true; }
        foreach ($call->args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack) { return false; }
        }
        $callback = PhpSource::argument($call->args, $callbackAt[$name], 'callback');

        return ($callback === null && $name === 'array_filter')
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
        // A literal environment lookup is a native read, including inside a
        // Blueprint condition. Do not grant this to a namespaced replacement,
        // callable creation, unpacking or values with implicit user callbacks.
        if (strtolower($call->name->toString()) === 'getenv') {
            $namespaced = $call->name->getAttribute('namespacedName');
            if ($call->isFirstClassCallable() || ($namespaced instanceof Node\Name
                && strtolower($namespaced->toString()) !== 'getenv')) { return false; }
            foreach ($call->args as $arg) {
                if (! $arg instanceof Node\Arg || $arg->unpack || $arg->byRef
                    || ! is_scalar(PhpSource::value($arg->value))) { return false; }
            }

            return true;
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

    private function blueprintType(string $method): ?string
    {
        return match ($method) {
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
    }

    private function blueprintModifier(string $method): bool
    {
        return $method === 'nullable' || in_array(
            $method,
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
        );
    }

    /**
     * Only calls bound to this parameter in this lexical scope are permitted.
     * Rebinding or reference capture loses that proof; aliases are not inferred.
     *
     * @param array<array-key, Node> $nodes
     * @return list<Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall>
     */
    private function receiverCalls(array $nodes, string $name): array
    {
        $calls = [];
        $rebound = false;
        $walk = function (Node $node) use (&$walk, &$calls, &$rebound, $name): void {
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction
                || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassLike) {
                if ($node instanceof Node\Expr\Closure) {
                    foreach ($node->uses as $use) {
                        if ($use->byRef && $use->var->name === $name) { $rebound = true; }
                    }
                }

                return;
            }
            $targets = match (true) {
                $node instanceof Node\Expr\AssignRef => [$node->var, $node->expr],
                $node instanceof Node\Expr\Assign,
                $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\PreInc,
                $node instanceof Node\Expr\PostInc, $node instanceof Node\Expr\PreDec,
                $node instanceof Node\Expr\PostDec => [$node->var],
                $node instanceof Node\Stmt\Foreach_ => array_filter([$node->keyVar, $node->valueVar]),
                $node instanceof Node\Stmt\Unset_ => $node->vars,
                $node instanceof Node\Expr\ArrayItem && $node->byRef => [$node->value],
                $node instanceof Node\Arg && $node->byRef => [$node->value],
                $node instanceof Node\Expr\FuncCall => $this->builtinReferenceArguments($node),
                default => [],
            };
            if ((new NodeFinder)->findFirst($targets, static fn (Node $target): bool =>
                $target instanceof Node\Expr\Variable && (! is_string($target->name) || $target->name === $name)) !== null) {
                $rebound = true;
            }
            if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
                $receiver = $node;
                while ($receiver instanceof Node\Expr\MethodCall || $receiver instanceof Node\Expr\NullsafeMethodCall) {
                    $receiver = $receiver->var;
                }
                if ($receiver instanceof Node\Expr\Variable && $receiver->name === $name) { $calls[] = $node; }
            }
            foreach ($node->getSubNodeNames() as $key) {
                $value = $node->$key;
                foreach (is_array($value) ? $value : [$value] as $child) {
                    if ($child instanceof Node) { $walk($child); }
                }
            }
        };
        foreach ($nodes as $node) { $walk($node); }

        return $rebound ? [] : $calls;
    }

    /** @return array<int, true>|null Null means an opaque Schema operation/callback. */
    private function schemaBlueprintCalls(Node\Expr\StaticCall $call): ?array
    {
        $method = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
        if (in_array($method, ['drop', 'dropifexists', 'rename'], true)) { return []; }
        if (! in_array($method, ['create', 'table'], true)) { return null; }
        $callback = PhpSource::argument($call->args, 1, 'callback');
        if (! $callback instanceof Node\Expr\Closure || ! isset($callback->params[0])
            || ! $callback->params[0]->var instanceof Node\Expr\Variable
            || ! is_string($callback->params[0]->var->name)) { return null; }
        $calls = [];
        foreach ($this->receiverCalls($callback->stmts, $callback->params[0]->var->name) as $candidate) {
            if (! $candidate instanceof Node\Expr\MethodCall) { continue; }
            [, $chain] = PhpSource::chain($candidate);
            $native = true;
            foreach ($chain as $offset => $part) {
                $name = $part->name instanceof Node\Identifier ? strtolower($part->name->toString()) : '';
                if ($offset === 0) {
                    $native = $this->blueprintType($name) !== null || in_array($name, [
                        'index', 'unique', 'primary', 'foreign', 'dropindex', 'dropunique', 'dropprimary',
                        'dropforeign', 'renameindex', 'comment', 'engine', 'charset', 'collation',
                        'dropcolumn', 'dropconstrainedforeignid', 'renamecolumn', 'timestamps',
                        'timestampstz', 'nullabletimestamps', 'droptimestamps', 'droptimestampstz',
                        'remembertoken', 'softdeletes', 'softdeletestz', 'softdeletesdatetime',
                        'dropsoftdeletes', 'dropsoftdeletestz', 'morphs', 'nullablemorphs', 'uuidmorphs',
                        'nullableuuidmorphs', 'ulidmorphs', 'nullableulidmorphs', 'dropmorphs',
                    ], true);
                } else {
                    $native = $native && $this->blueprintModifier($name);
                }
            }
            if ($native) { $calls[spl_object_id($candidate)] = true; }
        }

        return $calls;
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
        $type = $this->blueprintType($method);
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
            } elseif (! $this->blueprintModifier($modifierName)) {
                unset($this->tables[$table][$name]);

                return;
            }
        }
        $this->tables[$table][$name] = new Column($type, $nullable);
    }
}
