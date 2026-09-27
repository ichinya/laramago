<?php

declare(strict_types=1);

// Opt-in evaluated runtime: the worker must boot the analyzed application's
// configuration through exactly the three configuration bootstrappers when
// the gate is enabled, and must do zero work when it is not. The flag-off
// adapted run must stay byte-identical to the pre-existing adapted behavior;
// the only lines where that differs from the extension-less native run are
// the pre-existing helper/facade resolutions that never consult the gate.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago evaluated runtime '.bin2hex(random_bytes(8));
// The framework tree lives under vendor/ so that the workspace-relative file
// name of the helpers keeps the `/laravel/framework/src/...` suffix that the
// auth helper contract requires.
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
foreach ([
    'Config',
    'Foundation',
    'Foundation/Bootstrap',
    'Http',
    'Contracts/Auth',
    'Auth',
    'Support/Facades',
] as $folder) {
    if (! is_dir($framework.'/'.$folder)) {
        mkdir($framework.'/'.$folder, 0777, true);
    }
}
mkdir($workspace.'/app/Models', 0777, true);
mkdir($workspace.'/config', 0777, true);
mkdir($workspace.'/bootstrap', 0777, true);

// The worker does not autoload the workspace, so the fake bootstrap/app.php
// pulls in every fake framework piece itself.
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
// A fourth bootstrapper that the evaluated runtime must never reach.
file_put_contents($framework.'/Foundation/Bootstrap/RegisterProviders.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation\Bootstrap;
    class RegisterProviders
    {
        public function bootstrap($app): void
        {
            file_put_contents($app->basePath('providers-executed.txt'), 'providers must never run');
        }
    }
    PHP);
file_put_contents($framework.'/Foundation/helpers.php', <<<'PHP'
    <?php
    use Illuminate\Contracts\Auth\Factory;
    use Illuminate\Contracts\Auth\Guard;
    use Illuminate\Foundation\EnvironmentStore;
    function env($key, $default = null)
    {
        return EnvironmentStore::get($key, $default);
    }
    /** @return ($guard is null ? Factory : Guard) */
    function auth(?string $guard = null): Factory|Guard
    {
        throw new LogicException;
    }
    PHP);
file_put_contents($framework.'/Http/Request.php', <<<'PHP'
    <?php
    namespace Illuminate\Http;
    class Request
    {
        /** @return mixed */
        public function user($guard = null)
        {
            return null;
        }
    }
    PHP);
file_put_contents($framework.'/Contracts/Auth/Authenticatable.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Auth;
    interface Authenticatable {}
    PHP);
file_put_contents($framework.'/Contracts/Auth/Guard.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Auth;
    interface Guard
    {
        public function user(): ?Authenticatable;
    }
    PHP);
file_put_contents($framework.'/Contracts/Auth/StatefulGuard.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Auth;
    interface StatefulGuard extends Guard {}
    PHP);
file_put_contents($framework.'/Contracts/Auth/Factory.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Auth;
    interface Factory
    {
        public function guard(?string $name = null): Guard;
    }
    PHP);
file_put_contents($framework.'/Auth/AuthManager.php', <<<'PHP'
    <?php
    namespace Illuminate\Auth;
    /** @mixin \Illuminate\Contracts\Auth\Guard */
    class AuthManager implements \Illuminate\Contracts\Auth\Factory
    {
        public function guard(?string $name = null): \Illuminate\Contracts\Auth\Guard { throw new \LogicException; }
        public function __call(string $method, array $parameters): mixed { throw new \LogicException; }
    }
    PHP);
file_put_contents($framework.'/Auth/SessionGuard.php', <<<'PHP'
    <?php
    namespace Illuminate\Auth;
    class SessionGuard implements \Illuminate\Contracts\Auth\StatefulGuard
    {
        public function user(): ?\Illuminate\Contracts\Auth\Authenticatable { return null; }
    }
    PHP);
file_put_contents($framework.'/Auth/TokenGuard.php', <<<'PHP'
    <?php
    namespace Illuminate\Auth;
    class TokenGuard implements \Illuminate\Contracts\Auth\Guard
    {
        public function user(): ?\Illuminate\Contracts\Auth\Authenticatable { return null; }
    }
    PHP);
file_put_contents($framework.'/Support/Facades/Auth.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    /**
     * @method static \Illuminate\Contracts\Auth\Authenticatable|null user()
     * @method static \Illuminate\Contracts\Auth\Guard guard(?string $name = null)
     */
    class Auth
    {
        public static function __callStatic(string $method, array $parameters): mixed { throw new \LogicException; }
    }
    PHP);
file_put_contents($workspace.'/app/Models/User.php', <<<'PHP'
    <?php
    namespace App\Models;
    use Illuminate\Contracts\Auth\Authenticatable;
    class User implements Authenticatable
    {
        public string $name;
        public string $email;
    }
    PHP);
file_put_contents($workspace.'/app/Models/Admin.php', <<<'PHP'
    <?php
    namespace App\Models;
    use Illuminate\Contracts\Auth\Authenticatable;
    class Admin implements Authenticatable
    {
        public int $level;
    }
    PHP);
file_put_contents($workspace.'/config/auth.php', <<<'PHP'
    <?php
    use App\Models\Admin;
    use App\Models\User;

    return [
        'defaults' => ['guard' => env('AUTH_GUARD', 'web')],
        'guards' => [
            'web' => ['driver' => 'session', 'provider' => env('AUTH_PROVIDER', 'users')],
            'admin' => ['driver' => 'token', 'provider' => 'admins'],
        ],
        'providers' => [
            'users' => ['driver' => env('AUTH_DRIVER', 'eloquent'), 'model' => env('AUTH_MODEL', User::class)],
            'admins' => ['driver' => env('AUTH_ADMIN_DRIVER', 'eloquent'), 'model' => Admin::class],
        ],
    ];
    PHP);
$writeBootstrap = static function () use ($workspace): void {
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
        require_once $illuminate.'/Http/Request.php';
        require_once $illuminate.'/Contracts/Auth/Authenticatable.php';
        require_once $illuminate.'/Contracts/Auth/Guard.php';
        require_once $illuminate.'/Contracts/Auth/StatefulGuard.php';
        require_once $illuminate.'/Contracts/Auth/Factory.php';
        require_once $illuminate.'/Auth/AuthManager.php';
        require_once $illuminate.'/Auth/SessionGuard.php';
        require_once $illuminate.'/Auth/TokenGuard.php';
        require_once $illuminate.'/Support/Facades/Auth.php';
        require_once dirname(__DIR__).'/app/Models/User.php';
        require_once dirname(__DIR__).'/app/Models/Admin.php';
        return new Illuminate\Foundation\Application(dirname(__DIR__));
        PHP);
};
$writeBootstrap();

// Each case line has a name; the expected diagnostics per state are pinned
// below. `|` groups mark the gate-dependent (MIX) and gate-independent
// (FIXED) lines.
$sourceLines = [
    '<?php',
    'use Illuminate\Http\Request;',
    'use Illuminate\Support\Facades\Auth;',
    '',
    'function defaultGuard()',
    '{',
    '    $request = new Request;',
    'default-user|MIX|$u = $request->user();',
    'default-property|MIX|$name = $u->name;',
    '    if ($u !== null) {',
    'default-email|MIX|$email = $u->email;',
    'default-unknown|MIX|$ghost = $u->ghost;',
    '    }',
    '}',
    '',
    'function explicitGuard()',
    '{',
    'guard-factory|FIXED|$guard = Auth::guard(\'admin\');',
    'guard-user|FIXED|$admin = $guard->user();',
    '    if ($admin !== null) {',
    'guard-property|FIXED|$level = $admin->level;',
    '    }',
    'explicit-user|MIX|$explicit = (new Request)->user(\'admin\');',
    '    if ($explicit !== null) {',
    'explicit-property|MIX|$adminLevel = $explicit->level;',
    '    }',
    '}',
    '',
    'function facadeUser()',
    '{',
    'facade-user|MIX|$facade = Auth::user();',
    '    if ($facade !== null) {',
    'facade-property|MIX|$facadeEmail = $facade->email;',
    '    }',
    'helper-user|FIXED|$helper = auth()->user();',
    '    if ($helper !== null) {',
    'helper-property|MIX|$helperEmail = $helper->email;',
    '    }',
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

// Diagnostics when the gate is off: the extension defers wherever the literal
// config path fails, exactly as before this feature existed.
$deferred = [
    'default-user' => ['mixed-assignment'],
    'default-property' => ['mixed-assignment', 'mixed-property-access'],
    'default-email' => ['mixed-assignment', 'mixed-property-access'],
    'default-unknown' => ['mixed-assignment', 'mixed-property-access'],
    'guard-factory' => [],
    'guard-user' => [],
    'guard-property' => ['mixed-assignment', 'non-existent-property'],
    'explicit-user' => ['mixed-assignment'],
    'explicit-property' => ['mixed-assignment', 'mixed-property-access'],
    'facade-user' => [],
    'facade-property' => ['mixed-assignment', 'non-existent-property'],
    // auth() resolves to the manager through pre-existing behavior that never
    // consults the evaluated runtime, so user() stays ?Authenticatable here.
    'helper-user' => [],
    'helper-property' => ['mixed-assignment', 'non-existent-property'],
];
// Diagnostics when the evaluated runtime resolves the default guard to the
// users provider and the explicit admin guard to the admins provider.
$resolved = [
    'default-user' => [],
    'default-property' => ['possibly-null-property-access'],
    'default-email' => [],
    'default-unknown' => ['mixed-assignment', 'non-existent-property'],
    'guard-factory' => [],
    'guard-user' => [],
    'guard-property' => ['mixed-assignment', 'non-existent-property'],
    'explicit-user' => [],
    'explicit-property' => [],
    'facade-user' => [],
    'facade-property' => [],
    'helper-user' => [],
    'helper-property' => [],
];

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (bool $extended, bool $evaluate, bool $argvFlag = false) use ($workspace, $package, $command): array {
    // opcache is disabled for the worker because its revalidate window would
    // otherwise serve stale bootstrap bytecode rewritten between two runs.
    $workerCommand = [
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        $package.'/bin/laramago-worker.php',
        $package.'/vendor/autoload.php',
        $workspace,
    ];
    if ($argvFlag) {
        $workerCommand[] = '--evaluate-runtime';
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.5',
        'source' => ['paths' => ['cases.php'], 'includes' => [$workspace.'/vendor/laravel', $workspace.'/app']],
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
$expectLine = static function (string $message, string $name, array $actual, array $expected) use ($lines, $workspace): void {
    $line = $lines[$name]['line'];
    if (($actual[$line] ?? []) !== $expected) {
        throw new RuntimeException(
            $message.' on '.$name.' (line '.$line.'): expected '.json_encode($expected)
            .', got '.json_encode($actual[$line] ?? []).'; inspect '.$workspace,
        );
    }
};

$native = $summarize($run(false, false));

// Flag off: the adapted run must be byte-identical to the extension-less
// native baseline except for the two pre-existing helper resolutions that
// never consult the evaluated runtime, and must match the deferred state.
$off = $summarize($run(true, false));
foreach ($lines as $name => ['line' => $line]) {
    if (in_array($name, ['helper-user', 'helper-property'], true)) {
        continue;
    }
    if (($off[$line] ?? []) !== ($native[$line] ?? [])) {
        throw new RuntimeException(
            'flag off must retain native diagnostics on '.$name.' (line '.$line.'): native '
            .json_encode($native[$line] ?? []).'; adapted '.json_encode($off[$line] ?? []).'; inspect '.$workspace,
        );
    }
}
$expectState('flag off must defer like the pre-existing behavior', $off, $deferred);
if (is_file($workspace.'/bootstrap/executed.txt')) {
    throw new RuntimeException('Flag-off must not require the application; inspect '.$workspace);
}
echo "PASS: flag off is identical to the pre-existing behavior\n";

// Flag on without .env: the literal defaults in config/auth.php resolve.
$on = $summarize($run(true, true));
if (! is_file($workspace.'/bootstrap/executed.txt')) {
    throw new RuntimeException('Flag-on must boot the application; inspect '.$workspace);
}
if (is_file($workspace.'/providers-executed.txt') || is_file($workspace.'/bootstrap/providers-executed.txt')) {
    throw new RuntimeException('Providers must never run; inspect '.$workspace);
}
$expectState('flag on with defaults must resolve', $on, $resolved);
unlink($workspace.'/bootstrap/executed.txt');
echo "PASS: flag on with defaults resolves through the three bootstrappers\n";

// Flag on with a fully resolving .env: identical typing through the env values.
file_put_contents($workspace.'/.env', "AUTH_GUARD=web\nAUTH_PROVIDER=users\nAUTH_DRIVER=eloquent\nAUTH_MODEL=App\\Models\\User\n");
$envResolved = $summarize($run(true, true));
$expectState('env-resolved must match defaults-resolved', $envResolved, $resolved);
unlink($workspace.'/.env');
echo "PASS: env resolves everything\n";

// Flag on with a model that does not exist: the default guard defers while
// the explicit admin guard keeps resolving through its intact provider.
file_put_contents($workspace.'/.env', "AUTH_MODEL=App\\Models\\Ghost\n");
$ghostModel = $summarize($run(true, true));
foreach (['default-user', 'default-property', 'default-email', 'default-unknown', 'facade-user', 'facade-property', 'helper-property'] as $name) {
    $expectLine('ghost model must defer', $name, $ghostModel, $deferred[$name]);
}
foreach (['explicit-user', 'explicit-property', 'helper-user'] as $name) {
    $expectLine('ghost model must keep the intact provider', $name, $ghostModel, $resolved[$name]);
}
unlink($workspace.'/.env');
echo "PASS: missing model defers\n";

// Flag on with a driver that is not eloquent: the default guard defers.
file_put_contents($workspace.'/.env', "AUTH_DRIVER=ldap\n");
$customDriver = $summarize($run(true, true));
foreach (['default-user', 'default-property', 'default-email', 'default-unknown', 'facade-user', 'facade-property', 'helper-property'] as $name) {
    $expectLine('custom driver must defer', $name, $customDriver, $deferred[$name]);
}
foreach (['explicit-user', 'explicit-property', 'helper-user'] as $name) {
    $expectLine('custom driver must keep the intact provider', $name, $customDriver, $resolved[$name]);
}
unlink($workspace.'/.env');
echo "PASS: non-eloquent driver defers\n";

// Flag on with a throwing bootstrap/app.php: defer and no worker errors.
$healthyBootstrap = file_get_contents($workspace.'/bootstrap/app.php');
if (is_file($workspace.'/bootstrap/executed.txt')) {
    unlink($workspace.'/bootstrap/executed.txt');
}
file_put_contents($workspace.'/bootstrap/app.php', "<?php throw new RuntimeException('evaluation probe');\n");
$brokenBootstrap = $summarize($run(true, true));
$expectState('broken bootstrap must defer', $brokenBootstrap, $deferred);
if (is_file($workspace.'/bootstrap/executed.txt')) {
    throw new RuntimeException('Broken bootstrap must fail closed before any marker; inspect '.$workspace);
}
file_put_contents($workspace.'/bootstrap/app.php', $healthyBootstrap);
echo "PASS: broken bootstrap defers\n";

// The literal --evaluate-runtime worker flag enables the gate without the env var.
$argvFlag = $summarize($run(true, false, true));
$expectState('argv flag must match env flag', $argvFlag, $resolved);
if (! is_file($workspace.'/bootstrap/executed.txt')) {
    throw new RuntimeException('argv flag must boot the application; inspect '.$workspace);
}
unlink($workspace.'/bootstrap/executed.txt');
echo "PASS: argv flag enables the gate\n";

// Contract priority: the composer.json contract wins over the evaluated map.
// The contract names Admin where the evaluated map would resolve User, so the
// Admin-only `level` property separating both outcomes proves who supplied
// the type. The default-guard lines are identical with the flag on and off.
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['auth' => [
        'default-guard' => 'web',
        'guards' => ['web' => ['model' => 'App\\Models\\Admin']],
    ]]],
]));
$contractOff = $summarize($run(true, false));
$contractOn = $summarize($run(true, true));
unlink($workspace.'/composer.json');
foreach (['default-user', 'default-property', 'default-email', 'default-unknown', 'facade-user', 'facade-property', 'helper-user', 'helper-property'] as $name) {
    if (($contractOn[$lines[$name]['line']] ?? []) !== ($contractOff[$lines[$name]['line']] ?? [])) {
        throw new RuntimeException(
            'contract default-guard lines must be identical with the flag on and off on '.$name
            .': off '.json_encode($contractOff[$lines[$name]['line']] ?? [])
            .'; on '.json_encode($contractOn[$lines[$name]['line']] ?? []).'; inspect '.$workspace,
        );
    }
}
foreach (['default-user', 'facade-user', 'helper-user'] as $name) {
    $expectLine('contract must resolve the default guard', $name, $contractOn, []);
}
foreach (['default-email', 'facade-property', 'helper-property'] as $name) {
    $codes = $contractOn[$lines[$name]['line']] ?? [];
    // Admin has no `email`; the evaluated map would have resolved User where
    // `email` exists and these lines would be clean.
    if (! in_array('non-existent-property', $codes, true)) {
        throw new RuntimeException(
            'contract model must win over the evaluated model on '.$name
            .'; got '.json_encode($codes).'; inspect '.$workspace,
        );
    }
    if (($contractOn[$lines[$name]['line']] ?? []) === $resolved[$name]) {
        throw new RuntimeException('contract must not fall back to the evaluated user on '.$name.'; inspect '.$workspace);
    }
}
foreach (['explicit-user', 'explicit-property'] as $name) {
    $expectLine('contract must leave the explicit admin guard to the evaluated map', $name, $contractOn, []);
}
echo "PASS: contract priority\n";

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
