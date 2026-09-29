<?php

declare(strict_types=1);

// Opt-in evaluated configuration: literal config()/Config::get()/env() reads
// resolve through the same minimal boot as the auth feature. The flag-off
// adapted run retains native diagnostics beyond plain local assignments;
// the flag-on runs pin the resolved types, the static-source precedence
// and the unchanged missing-key semantics.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago evaluated config '.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
foreach (['Config', 'Foundation', 'Foundation/Bootstrap', 'Support/Facades'] as $folder) {
    if (! is_dir($framework.'/'.$folder)) {
        mkdir($framework.'/'.$folder, 0777, true);
    }
}
foreach (['config', 'bootstrap'] as $folder) {
    if (! is_dir($workspace.'/'.$folder)) {
        mkdir($workspace.'/'.$folder, 0777, true);
    }
}
file_put_contents($framework.'/Foundation/Application.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation;
    use ArrayAccess;
    class Application implements ArrayAccess
    {
        protected array $instances = [];
        public function __construct(protected string $basePath) {}
        public function basePath(string $path = ''): string
        {
            return $this->basePath.($path === '' ? '' : '/'.$path);
        }
        public function instance(string $key, mixed $instance): static
        {
            $this->instances[$key] = $instance;
            return $this;
        }
        public function make(string $key): mixed
        {
            return $this->instances[$key] ?? null;
        }
        public function offsetExists(mixed $offset): bool { return isset($this->instances[$offset]); }
        public function offsetGet(mixed $offset): mixed { return $this->instances[$offset] ?? null; }
        public function offsetSet(mixed $offset, mixed $value): void { $this->instances[$offset] = $value; }
        public function offsetUnset(mixed $offset): void { unset($this->instances[$offset]); }
    }
    PHP);
file_put_contents($framework.'/Foundation/EnvironmentStore.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation;
    class EnvironmentStore
    {
        /** @var array<string, string> */
        public static array $values = [];
        public static function load(string $file): void
        {
            if (! is_file($file)) {
                return;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (! preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $matches)) {
                    continue;
                }
                $value = $matches[2];
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                    $value = substr($value, 1, -1);
                }
                static::$values[$matches[1]] = $value;
            }
        }
        public static function get(string $key, mixed $default = null): mixed
        {
            return array_key_exists($key, static::$values) ? static::$values[$key] : $default;
        }
    }
    PHP);
file_put_contents($framework.'/Config/Repository.php', <<<'PHP'
    <?php
    namespace Illuminate\Config;
    use ArrayAccess;
    class Repository implements ArrayAccess
    {
        protected array $items = [];
        public function __construct(array $items = []) { $this->items = $items; }
        public function get(string $key, mixed $default = null): mixed
        {
            $value = $this->items;
            foreach (explode('.', $key) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }
            return $value;
        }
        public function all(): array { return $this->items; }
        public function offsetExists(mixed $offset): bool { return $this->get($offset) !== null; }
        public function offsetGet(mixed $offset): mixed { return $this->get($offset); }
        public function offsetSet(mixed $offset, mixed $value): void {}
        public function offsetUnset(mixed $offset): void {}
    }
    PHP);
file_put_contents($framework.'/Foundation/Bootstrap/LoadEnvironmentVariables.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation\Bootstrap;
    use Illuminate\Foundation\EnvironmentStore;
    class LoadEnvironmentVariables
    {
        public function bootstrap($app): void
        {
            EnvironmentStore::load($app->basePath('.env'));
        }
    }
    PHP);
file_put_contents($framework.'/Foundation/Bootstrap/LoadConfiguration.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation\Bootstrap;
    use Illuminate\Config\Repository;
    class LoadConfiguration
    {
        public function bootstrap($app): void
        {
            $items = [];
            $directory = $app->basePath('config');
            foreach (is_dir($directory) ? glob($directory.'/*.php') ?: [] : [] as $file) {
                $items[basename($file, '.php')] = require $file;
            }
            $app->instance('config', new Repository($items));
        }
    }
    PHP);
file_put_contents($framework.'/Foundation/Bootstrap/RegisterFacades.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation\Bootstrap;
    class RegisterFacades
    {
        public function bootstrap($app): void
        {
            $app->instance('facades.aliases', $app->make('config')->get('app.aliases', []));
        }
    }
    PHP);
file_put_contents($framework.'/Foundation/helpers.php', <<<'PHP'
    <?php
    /** @return mixed */
    function config($key, $default = null)
    {
        throw new LogicException;
    }
    PHP);
file_put_contents($framework.'/Support/helpers.php', <<<'PHP'
    <?php
    use Illuminate\Foundation\EnvironmentStore;
    /** @return mixed */
    function env($key, $default = null)
    {
        return EnvironmentStore::get($key, $default);
    }
    PHP);
file_put_contents($framework.'/Support/Facades/Facade.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    class Facade
    {
        public static function __callStatic(string $method, array $parameters): mixed { throw new \LogicException; }
        protected static function getFacadeRoot(): mixed { throw new \LogicException; }
        protected static function resolveFacadeInstance(mixed $name): mixed { throw new \LogicException; }
    }
    PHP);
file_put_contents($framework.'/Support/Facades/Config.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    /**
     * @method static mixed get(string $key, mixed $default = null)
     */
    class Config extends Facade
    {
        protected static function getFacadeAccessor(): string
        {
            return 'config';
        }
    }
    PHP);
file_put_contents($workspace.'/config/app.php', <<<'PHP'
    <?php
    return [
        'timezone' => 'UTC',
        'key' => env('APP_KEY'),
        'debug' => env('APP_DEBUG', false),
    ];
    PHP);
file_put_contents($workspace.'/config/database.php', <<<'PHP'
    <?php
    return [
        'default' => env('DB_DEFAULT', 'sqlite'),
        'connections' => [
            'mysql' => ['host' => env('DB_HOST', '127.0.0.1'), 'port' => 3306],
            'sqlite' => ['database' => 'database.sqlite'],
        ],
    ];
    PHP);
file_put_contents($workspace.'/bootstrap/app.php', <<<'PHP'
    <?php
    $illuminate = dirname(__DIR__).'/vendor/laravel/framework/src/Illuminate';
    file_put_contents(__DIR__.'/executed.txt', 'booted');
    require_once $illuminate.'/Foundation/EnvironmentStore.php';
    require_once $illuminate.'/Config/Repository.php';
    require_once $illuminate.'/Foundation/Application.php';
    require_once $illuminate.'/Foundation/Bootstrap/LoadEnvironmentVariables.php';
    require_once $illuminate.'/Foundation/Bootstrap/LoadConfiguration.php';
    require_once $illuminate.'/Foundation/Bootstrap/RegisterFacades.php';
    require_once $illuminate.'/Foundation/helpers.php';
    require_once $illuminate.'/Support/helpers.php';
    require_once $illuminate.'/Support/Facades/Facade.php';
    require_once $illuminate.'/Support/Facades/Config.php';
    return new Illuminate\Foundation\Application(dirname(__DIR__));
    PHP);

$sourceLines = [
    '<?php',
    'use Illuminate\Support\Facades\Config;',
    '',
    'function reads()',
    '{',
    // env-backed through the static file: only the evaluated source can type it.
    'env-key|MIX|$key = config(\'app.key\');',
    'env-debug|MIX|$debug = config(\'app.debug\');',
    'env-default|MIX|$default = config(\'database.default\');',
    'env-nested|MIX|$host = config(\'database.connections.mysql.host\');',
    // the whole segment: array typing.
    'segment|MIX|$connections = config(\'database.connections\');',
    // facade read of an env-backed key.
    'facade|MIX|$facadeKey = Config::get(\'app.key\');',
    // the static literal must keep winning over the evaluated source.
    'static-literal|FIXED|$timezone = config(\'app.timezone\');',
    // missing key with a precise default: unchanged, static default wins.
    'missing-default|FIXED|$missing = config(\'app.missing\', \'fallback\');',
    '}',
    '',
    'function envReads()',
    '{',
    // .env sets APP_DEBUG=true: the framework conversion yields bool.
    'env-present|MIX|$debug = env(\'APP_DEBUG\');',
    // absent key: the precise default type.
    'env-absent|MIX|$fallback = env(\'APP_MISSING\', \'fallback\');',
    // absent key without a default argument: defers like before.
    'env-missing|FIXED|$gone = env(\'APP_MISSING\');',
    '}',
];
$lines = [];
$source = [];
foreach ($sourceLines as $sourceLine) {
    if (preg_match('/^([a-z-]+)\|([A-Z]+)\|(.*)$/', $sourceLine, $matches)) {
        $lines[$matches[1]] = ['line' => count($source) + 1, 'group' => $matches[2]];
        $source[] = $matches[3];
    } else {
        $source[] = $sourceLine;
    }
}
file_put_contents($workspace.'/cases.php', implode("\n", $source)."\n");

// Without evaluation, env-backed values stay mixed. Plain local assignment
// warnings are filtered without changing those expression types.
$deferred = [
    'env-key' => [],
    'env-debug' => [],
    'env-default' => [],
    'env-nested' => [],
    'segment' => [],
    'facade' => [],
    // the static literal already wins without the flag: typed as the literal.
    'static-literal' => [],
    'missing-default' => [],
    'env-present' => [],
    'env-absent' => [],
    'env-missing' => [],
];
// Diagnostics when the evaluated runtime resolves the values: the assignments
// stop being mixed, the null default of app.key stays nullable without a
// diagnostic, and the unchanged lines keep their state.
$resolved = [
    'env-key' => [],
    'env-debug' => [],
    'env-default' => [],
    'env-nested' => [],
    'segment' => [],
    'facade' => [],
    'static-literal' => [],
    'missing-default' => [],
    'env-present' => [],
    'env-absent' => [],
    // an absent variable without a default argument resolves to null.
    'env-missing' => [],
];

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (bool $extended, bool $evaluate) use ($workspace, $package, $command): array {
    $workerCommand = [
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        $package.'/bin/laramago-worker.php',
        $package.'/vendor/autoload.php',
        $workspace,
    ];
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.5',
        'source' => ['paths' => ['cases.php'], 'includes' => [$workspace.'/vendor/laravel']],
        'extension-hosts' => $extended ? [
            'laramago' => ['command' => $workerCommand, 'workers' => 1],
        ] : new stdClass,
    ], JSON_THROW_ON_ERROR));
    $environment = getenv();
    unset($environment['LARAMAGO_EVALUATE_RUNTIME']);
    if ($evaluate) {
        $environment['LARAMAGO_EVALUATE_RUNTIME'] = '1';
    }
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/report.json', 'w'],
        2 => ['file', $workspace.'/stderr.log', 'w'],
    ], $pipes, null, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Mago.');
    }
    fclose($pipes[0]);
    proc_close($process);
    $stderr = file_get_contents($workspace.'/stderr.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)) {
        throw new RuntimeException('Provider failure: '.$stderr);
    }

    return json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$summarize = static function (array $issues) use ($workspace): array {
    $result = [];
    foreach ($issues as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
        ))[0];
        if (str_ends_with($primary['span']['file_id']['name'], 'cases.php')) {
            $result[$primary['span']['start']['line'] + 1][] = $issue['code'];
        }
    }
    foreach ($result as &$codes) {
        sort($codes);
    }
    return $result;
};
$expectState = static function (string $message, array $actual, array $state) use ($lines, $workspace): void {
    foreach ($lines as $name => ['line' => $line]) {
        if (($actual[$line] ?? []) !== $state[$name]) {
            throw new RuntimeException(
                $message.' on '.$name.' (line '.$line.'): expected '.json_encode($state[$name])
                .', got '.json_encode($actual[$line] ?? []).'; inspect '.$workspace,
            );
        }
    }
};

$native = $summarize($run(false, false));
$off = $summarize($run(true, false));
foreach ($lines as $name => ['line' => $line]) {
    // The two statically resolvable lines already differ from the native run
    // through the pre-existing literal config provider, which never consults
    // the evaluated runtime.
    if (in_array($name, ['static-literal', 'missing-default'], true)) {
        continue;
    }
    $expected = $native[$line] ?? [];
    if ($lines[$name]['group'] === 'MIX' || $name === 'env-missing') {
        $position = array_search('mixed-assignment', $expected, true);
        if ($position === false) {
            throw new RuntimeException('Expected native local assignment on '.$name.'; inspect '.$workspace);
        }
        unset($expected[$position]);
        $expected = array_values($expected);
    }
    if (($off[$line] ?? []) !== $expected) {
        throw new RuntimeException(
            'flag off must retain native diagnostics on '.$name.' (line '.$line.'): native '
            .json_encode($native[$line] ?? []).'; adapted '.json_encode($off[$line] ?? []).'; inspect '.$workspace,
        );
    }
}
$expectState('flag off must defer like the native behavior', $off, $deferred);
if (is_file($workspace.'/bootstrap/executed.txt')) {
    throw new RuntimeException('Flag-off must not require the application; inspect '.$workspace);
}
echo "PASS: flag off retains native diagnostics beyond local assignments\n";

// Flag on without .env: config defaults resolve through the evaluated values,
// env(APP_DEBUG) resolves to its false default and the absent env key keeps
// its precise default argument type.
$on = $summarize($run(true, true));
if (! is_file($workspace.'/bootstrap/executed.txt')) {
    throw new RuntimeException('Flag-on must boot the application; inspect '.$workspace);
}
unlink($workspace.'/bootstrap/executed.txt');
$expectState('flag on must resolve through the evaluated config', $on, $resolved);
echo "PASS: flag on resolves config, facade and env reads\n";

// Flag on with a fully resolving .env: identical typing through the env values,
// and the boolean conversion of APP_DEBUG=true stays non-mixed.
file_put_contents($workspace.'/.env', "APP_KEY=secret\nDB_DEFAULT=mysql\nDB_HOST=db.internal\nAPP_DEBUG=true\n");
$envResolved = $summarize($run(true, true));
$expectState('env-resolved must keep every read non-mixed', $envResolved, $resolved);
unlink($workspace.'/.env');
echo "PASS: env values resolve identically\n";

// Determinism: a second flag-on run reproduces the first exactly.
$repeat = $summarize($run(true, true));
$expectState('repeated flag-on runs must be deterministic', $repeat, $resolved);
echo "PASS: repeated runs are deterministic\n";

$resolvedPath = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolvedPath === false || $temporary === false || ! str_starts_with($resolvedPath, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedPath, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($resolvedPath);
