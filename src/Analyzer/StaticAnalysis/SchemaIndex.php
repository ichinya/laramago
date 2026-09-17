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
                    $this->invalidateStatements($up);
                    continue;
                }
                foreach ($up as $statement) {
                    if (
                        $statement instanceof Node\Stmt\Expression
                        && $statement->expr instanceof Node\Expr\StaticCall
                        && $this->isSchema($statement->expr)
                    ) {
                        $this->schemaCall($statement->expr);
                    } else {
                        $this->invalidateStatements([$statement]);
                    }
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

    private function schemaCall(Node\Expr\StaticCall $call): void
    {
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

    /** @param array<array-key, Node> $nodes */
    private function invalidateStatements(array $nodes): void
    {
        $foundSchema = false;
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
            if ($this->isSchema($call)) {
                $foundSchema = true;
                $table = PhpSource::value(PhpSource::argument($call->args, 0, 'table'));
                if (
                    is_string($table)
                    && $call->name instanceof Node\Identifier
                    && strtolower($call->name->toString()) !== 'connection'
                ) {
                    $this->uncertain[$table] = true;
                } else {
                    $this->unreadable = true;
                }
            } elseif (
                $call->class instanceof Node\Name
                && in_array(strtolower($call->class->toString()), ['db', 'illuminate\\support\\facades\\db'], true)
            ) {
                // SQL can change any table; never interpret or execute it.
                $this->unreadable = true;
            }
        }
        if (! $foundSchema && (new NodeFinder)->findFirstInstanceOf($nodes, Node\Expr\CallLike::class) !== null) {
            // A helper, raw SQL call or indirect schema builder can mutate tables
            // that cannot be identified from its arguments alone.
            $this->unreadable = true;
        }
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
