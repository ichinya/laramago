<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Explicitly trusted application PHP, with no normal provider/kernel boot.
 * Framework queries are rejected; catalog reads use a separate readonly PDO.
 * This is an execution boundary and not a sandbox for arbitrary application PHP.
 */
final class RuntimeSchemaCollector
{
    public static function collect(string $root, ?string $autoload, array $models): array
    {
        $root = RuntimeSchemaSnapshot::canonical($root) ?? throw new RuntimeException('Invalid project root.');
        if (!is_dir($root)) {
            throw new RuntimeException('Invalid project root.');
        }
        $models = array_values(array_unique($models));
        sort($models, SORT_STRING);
        if ($models === [] || count($models) > RuntimeSchemaSnapshot::MAX_MODELS) {
            throw new RuntimeException('Invalid runtime model selection.');
        }
        foreach ($models as $model) {
            if (
                !is_string($model)
                || strlen($model) > 256
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $model) !== 1
            ) {
                throw new RuntimeException('Invalid runtime model selection.');
            }
        }
        $autoload ??= self::defaultAutoload($root);
        if (!self::absolute($autoload)) {
            $autoload = $root . '/' . $autoload;
        }
        $autoload = str_replace('\\', '/', $autoload);
        $resolvedAutoload = RuntimeSchemaSnapshot::canonical($autoload);
        $snapshot = [
            'schemaVersion' => 1,
            'projectRoot' => $root,
            'scope' => ['kind' => 'runtime-schema', 'evidence' => 'evaluated-schema'],
            'plan' => ['models' => $models, 'autoload' => $resolvedAutoload ?? $autoload],
            'models' => [],
            'sources' => [],
            'sourceSets' => [],
            'errors' => [],
            'truncated' => false,
        ];
        $sets = self::inputSets($root, $autoload);
        self::captureSets($snapshot, $sets);
        $initial = $snapshot['sources'];
        $initialSets = $snapshot['sourceSets'];
        $app = null;
        $loader = null;
        try {
            if ($resolvedAutoload === null || !is_file($resolvedAutoload)) {
                self::error($snapshot, 'runtime-bootstrap-failed', 'Application dependencies could not be loaded.');
            } else {
                $loader = require $resolvedAutoload;
                if (
                    !class_exists('Illuminate\\Foundation\\Application')
                    || !class_exists('Illuminate\\Database\\SQLiteConnection')
                ) {
                    throw new RuntimeException('Missing Laravel runtime dependencies.');
                }
                require_once __DIR__ . '/RuntimeQueryGuard.php';
                RuntimeQueryGuard::$blocked = 0;
                self::installGuards();
                $bootstrap = $root . '/bootstrap/app.php';
                if (!is_file($bootstrap)) {
                    throw new RuntimeException('Missing application bootstrap.');
                }
                $app = require $bootstrap;
                if (!$app instanceof \Illuminate\Foundation\Application) {
                    throw new RuntimeException('Invalid application bootstrap.');
                }
                self::installGuards();
                $configPath = str_replace('\\', '/', $app->configPath());
                if ($configPath !== $root . '/config') {
                    $sets[] = ['directory' => $configPath, 'pattern' => '*.php'];
                }
                $environmentPath = str_replace('\\', '/', $app->environmentPath());
                if ($environmentPath !== $root) {
                    $sets[] = ['directory' => $environmentPath, 'pattern' => '.env*'];
                }
                self::captureSets($snapshot, $sets);
                foreach ([
                    // No application service providers are registered here.
                    'Illuminate\\Foundation\\Bootstrap\\LoadEnvironmentVariables',
                    'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration',
                    'Illuminate\\Foundation\\Bootstrap\\RegisterFacades',
                ] as $bootstrapper) {
                    $cachedPaths = [
                        str_replace('\\', '/', $app->getCachedConfigPath()),
                        str_replace('\\', '/', $app->getCachedPackagesPath()),
                    ];
                    if (
                        $bootstrapper === 'Illuminate\\Foundation\\Bootstrap\\LoadEnvironmentVariables'
                        || $bootstrapper === 'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration'
                    ) {
                        $sets[] = ['paths' => $cachedPaths];
                        self::captureSets($snapshot, $sets);
                    }
                    if ($bootstrapper === 'Illuminate\\Foundation\\Bootstrap\\RegisterFacades') {
                        $manifest = $app->make('Illuminate\\Foundation\\PackageManifest');
                        if (!is_file($manifest->manifestPath)) {
                            // Avoid RegisterFacades rebuilding packages.php on disk.
                            $manifest->manifest = [];
                        }
                    }
                    (new $bootstrapper())->bootstrap($app);
                }
                $provider = $app->register('Illuminate\\Database\\DatabaseServiceProvider');
                $provider->boot();
                if (RuntimeQueryGuard::$blocked !== 0) {
                    throw new RuntimeException('Blocked application query.');
                }
                self::captureSets($snapshot, $sets);
                $database = $app->make('db');
                foreach ($models as $model) {
                    self::model($snapshot, $database, $app->make('config'), $loader, $model, $root);
                }
            }
        } catch (Throwable) {
            self::error($snapshot, 'runtime-bootstrap-failed', 'Application configuration bootstrap failed.');
        } finally {
            if (class_exists('Illuminate\\Support\\Facades\\Facade', false)) {
                \Illuminate\Support\Facades\Facade::clearResolvedInstances();
                \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
            }
            unset($app, $loader);
        }
        foreach (get_included_files() as $file) {
            $canonical = RuntimeSchemaSnapshot::canonical($file);
            if ($canonical !== null && str_starts_with(strtolower($canonical), strtolower($root . '/'))) {
                self::source($snapshot, $canonical);
            }
        }
        self::captureSets($snapshot, $sets);
        foreach ($initial as $file => $digest) {
            if (($snapshot['sources'][$file] ?? null) !== $digest) {
                self::error($snapshot, 'runtime-source-changed', 'Runtime inputs changed during collection.');
                break;
            }
        }
        if (array_slice($snapshot['sourceSets'], 0, count($initialSets)) !== $initialSets) {
            self::error($snapshot, 'runtime-source-changed', 'Runtime inputs changed during collection.');
        }
        ksort($snapshot['sources'], SORT_STRING);
        ksort($snapshot['models'], SORT_STRING);
        if (!RuntimeSchemaSnapshot::currentInputs($snapshot)) {
            self::error($snapshot, 'runtime-source-changed', 'Runtime inputs changed during collection.');
        }

        return $snapshot;
    }

    private static function model(
        array &$snapshot,
        object $database,
        object $configuration,
        mixed $loader,
        string $name,
        string $root,
    ): void {
        $before = RuntimeQueryGuard::$blocked;
        try {
            if (is_object($loader) && method_exists($loader, 'findFile')) {
                $file = $loader->findFile($name);
                if (is_string($file)) {
                    self::source($snapshot, $file);
                }
            }
            if (!class_exists($name) || !is_subclass_of($name, 'Illuminate\\Database\\Eloquent\\Model')) {
                throw new RuntimeException('Invalid model selection.');
            }
            self::classSources($snapshot, new \ReflectionClass($name));
            foreach (get_included_files() as $file) {
                $canonical = RuntimeSchemaSnapshot::canonical($file);
                if ($canonical !== null && str_starts_with(strtolower($canonical), strtolower($root . '/'))) {
                    self::source($snapshot, $canonical);
                }
            }
            $model = new $name();
            $table = $model->getTable();
            $connection = $model->getConnectionName() ?? $database->getDefaultConnection();
            $casts = $model->getCasts();
            if (
                !self::identifier($table)
                || !self::identifier($connection)
                || !is_array($casts)
                || count($casts) > RuntimeSchemaSnapshot::MAX_COLUMNS
                || RuntimeQueryGuard::$blocked !== $before
            ) {
                throw new RuntimeException('Invalid model metadata.');
            }
            $driver = $configuration->get('database.connections.' . $connection . '.driver');
            if ($driver !== 'sqlite') {
                self::error($snapshot, 'runtime-unsupported-driver', 'The selected database driver is not supported.');

                return;
            }
            $resolved = $database->connection($connection);
            $resolved->beforeExecuting(static function (): never {
                RuntimeQueryGuard::reject();
            });
            if (!$resolved instanceof RuntimeQueryGuard || $resolved->getDriverName() !== 'sqlite') {
                self::error($snapshot, 'runtime-unsupported-driver', 'The selected database driver is not supported.');

                return;
            }
            $safeCasts = [];
            foreach ($casts as $attribute => $cast) {
                if (!self::identifier($attribute) || !is_string($cast) || strlen($cast) > 1024) {
                    throw new RuntimeException('Invalid model casts.');
                }
                $safeCasts[$attribute] = self::cast($cast);
                $castClass = explode(':', $cast, 2)[0];
                if (is_object($loader) && method_exists($loader, 'findFile')) {
                    $castFile = $loader->findFile(ltrim($castClass, '\\'));
                    if (is_string($castFile)) {
                        self::source($snapshot, $castFile);
                    }
                }
            }
            ksort($safeCasts, SORT_STRING);
            try {
                $schema = self::columns($resolved, $table, $root);
            } catch (\DomainException) {
                self::error(
                    $snapshot,
                    'runtime-unsupported-column',
                    'A selected table uses unsupported generated columns.',
                );

                return;
            } catch (Throwable) {
                self::error($snapshot, 'runtime-schema-failed', 'Database schema inspection failed.');

                return;
            }
            if ($schema === null) {
                self::error($snapshot, 'runtime-table-missing', 'A selected model table does not exist.');

                return;
            }
            $snapshot['models'][$name] = [
                'connection' => $connection,
                'table' => $table,
                'casts' => $safeCasts,
                'columns' => $schema['columns'],
                'databaseIdentity' => $schema['databaseIdentity'],
            ];
        } catch (Throwable) {
            self::error(
                $snapshot,
                'runtime-model-failed',
                'A selected model could not be resolved without application queries.',
            );
        }
    }

    private static function columns(RuntimeQueryGuard $connection, string $table, string $root): ?array
    {
        $path = $connection->getConfig('database');
        if (!is_string($path) || $path === ':memory:' || str_starts_with($path, 'file:')) {
            throw new RuntimeException('A file-backed SQLite database is required.');
        }
        if (!self::absolute($path)) {
            $path = $root . '/' . $path;
        }
        $path = realpath($path);
        if ($path === false || !is_file($path)) {
            throw new RuntimeException('SQLite database is unavailable.');
        }
        $table = $connection->getTablePrefix() . $table;
        if (!self::identifier($table)) {
            throw new RuntimeException('Invalid model table.');
        }
        // User-supplied connection PRAGMAs/options are intentionally not executed.
        $flagsAttribute = defined('Pdo\\Sqlite::ATTR_OPEN_FLAGS')
            ? constant('Pdo\\Sqlite::ATTR_OPEN_FLAGS')
            : PDO::SQLITE_ATTR_OPEN_FLAGS;
        $readonlyFlag = defined('Pdo\\Sqlite::OPEN_READONLY')
            ? constant('Pdo\\Sqlite::OPEN_READONLY')
            : PDO::SQLITE_OPEN_READONLY;
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            $flagsAttribute => $readonlyFlag,
            PDO::ATTR_TIMEOUT => 1,
        ]);
        $exists = $pdo->prepare(
            "select strict from pragma_table_list where schema = 'main' and name = ? and type = 'table'",
        );
        $exists->execute([$table]);
        $row = $exists->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $strict = (int) $row['strict'] === 1;
        $query = $pdo->prepare('select name, type, "notnull", pk, hidden from pragma_table_xinfo(?) order by name');
        $query->execute([$table]);
        $columns = [];
        while (($column = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ((int) $column['hidden'] !== 0) {
                throw new \DomainException('Unsupported generated or hidden columns.');
            }
            if (
                count($columns) >= RuntimeSchemaSnapshot::MAX_COLUMNS
                || !self::identifier($column['name'])
                || strlen((string) $column['type']) > 256
            ) {
                throw new RuntimeException('Schema limit exceeded.');
            }
            $type = (string) $column['type'];
            $notNull = (int) $column['notnull'] === 1 || (int) $column['pk'] > 0 && $strict;
            $columns[$column['name']] = ['typeName' => $type, 'nullable' => !$notNull, 'strict' => $strict];
        }
        if ($columns === []) {
            throw new RuntimeException('Schema inspection was incomplete.');
        }
        ksort($columns, SORT_STRING);

        return [
            'columns' => $columns,
            'databaseIdentity' => hash('sha256', str_replace('\\', '/', $path) . "\0" . $connection->getTablePrefix()),
        ];
    }

    private static function installGuards(): void
    {
        \Illuminate\Database\Connection::resolverFor(
            'sqlite',
            static fn($pdo, $database, $prefix, $config): RuntimeQueryGuard => new RuntimeQueryGuard(
                $pdo,
                $database,
                $prefix,
                $config,
            ),
        );
        foreach (['mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
            \Illuminate\Database\Connection::resolverFor($driver, static function (): never {
                RuntimeQueryGuard::reject();
            });
        }
    }

    private static function cast(string $cast): string
    {
        [$base, $arguments] = array_pad(explode(':', $cast, 2), 2, null);
        if ($base === '' || preg_match('/\A[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\z/', $base) !== 1) {
            throw new RuntimeException('Invalid cast declaration.');
        }
        if ($base === 'decimal' && is_string($arguments) && preg_match('/\A[0-9]{1,3}\z/', $arguments) === 1) {
            return $base . ':' . $arguments;
        }
        if ($base === 'encrypted' && in_array($arguments, ['array', 'collection', 'object', 'json'], true)) {
            return $base . ':' . $arguments;
        }

        return $base;
    }

    private static function classSources(array &$snapshot, \ReflectionClass $class): void
    {
        $file = $class->getFileName();
        if (is_string($file)) {
            self::source($snapshot, $file);
        }
        foreach ($class->getTraits() as $trait) {
            self::classSources($snapshot, $trait);
        }
        $parent = $class->getParentClass();
        if ($parent !== false) {
            self::classSources($snapshot, $parent);
        }
    }

    private static function defaultAutoload(string $root): string
    {
        $composer = RuntimeFiles::read($root . '/composer.json', 1_048_577);
        $manifest = is_string($composer) && strlen($composer) <= 1_048_576 ? json_decode($composer, true) : null;
        $vendor = is_array($manifest) ? $manifest['config']['vendor-dir'] ?? 'vendor' : 'vendor';
        if (!is_string($vendor) || $vendor === '' || str_contains($vendor, "\0")) {
            throw new RuntimeException('Invalid Composer vendor directory.');
        }

        return (self::absolute($vendor) ? $vendor : $root . '/' . $vendor) . '/autoload.php';
    }

    private static function inputSets(string $root, string $autoload): array
    {
        return [
            ['directory' => $root . '/config', 'pattern' => '*.php'],
            ['directory' => $root, 'pattern' => '.env*'],
            ['directory' => $root . '/bootstrap', 'pattern' => '*.php'],
            ['directory' => $root . '/bootstrap/cache', 'pattern' => '*.php'],
            ['paths' => [$root . '/composer.json', $root . '/composer.lock', $autoload]],
        ];
    }

    private static function captureSets(array &$snapshot, array $sets): void
    {
        $snapshot['sourceSets'] = [];
        foreach ($sets as $set) {
            $files = isset($set['paths'])
                ? array_filter($set['paths'], is_file(...))
                : (glob($set['directory'] . '/' . $set['pattern']) ?: []);
            $files = array_values(array_filter($files, is_file(...)));
            $files = array_map(static fn(string $file): string => str_replace('\\', '/', $file), $files);
            sort($files, SORT_STRING);
            if (count($files) > RuntimeSchemaSnapshot::MAX_SOURCES) {
                $snapshot['truncated'] = true;
                self::error($snapshot, 'runtime-source-limit', 'Runtime source collection limit exceeded.');
                $files = array_slice($files, 0, RuntimeSchemaSnapshot::MAX_SOURCES);
            }
            foreach ($files as $file) {
                self::source($snapshot, $file);
            }
            $files = array_values(array_filter(
                $files,
                static fn(string $file): bool => isset($snapshot['sources'][$file]),
            ));
            $snapshot['sourceSets'][] = $set + ['files' => $files];
        }
    }

    private static function source(array &$snapshot, string $file): void
    {
        $file = str_replace('\\', '/', $file);
        if (count($snapshot['sources']) >= RuntimeSchemaSnapshot::MAX_SOURCES && !isset($snapshot['sources'][$file])) {
            $snapshot['truncated'] = true;
            self::error($snapshot, 'runtime-source-limit', 'Runtime source collection limit exceeded.');

            return;
        }
        $digest = RuntimeFiles::digest($file);
        if (!is_string($digest)) {
            self::error($snapshot, 'runtime-source-failed', 'A runtime source could not be read.');

            return;
        }
        if (isset($snapshot['sources'][$file]) && $snapshot['sources'][$file] !== $digest) {
            self::error($snapshot, 'runtime-source-changed', 'Runtime inputs changed during collection.');
        }
        $snapshot['sources'][$file] = $digest;
    }

    private static function error(array &$snapshot, string $code, string $message): void
    {
        $error = ['code' => $code, 'file' => null, 'message' => $message];
        if (!in_array($error, $snapshot['errors'], true)) {
            $snapshot['errors'][] = $error;
        }
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 256 && !str_contains($value, "\0");
    }

    private static function absolute(string $path): bool
    {
        return preg_match('~\A(?:[A-Za-z]:[/\\\\]|/)~', $path) === 1;
    }
}
