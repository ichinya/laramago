<?php

declare(strict_types=1);

// Real Mago checks for offline auth defaults; no application file is executed.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
require_once $package.'/vendor/autoload.php';

// The installed repository's default reader order is server, env, putenv.
$key = 'LARAMAGO_AUTH_ENV_ORDER_'.bin2hex(random_bytes(6));
$snapshot = new \Ichinya\Laramago\Analyzer\StaticAnalysis\AuthEnvironment(
    $package,
    new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($package),
);
$currentValue = (new ReflectionClass($snapshot))->getMethod('currentValue');
putenv($key.'=process');
$_ENV[$key] = 'env';
$_SERVER[$key] = 'server';
try {
    if ($currentValue->invoke($snapshot, $key) !== 'server') {
        throw new RuntimeException('Server environment value must have first priority.');
    }
    unset($_SERVER[$key]);
    if ($currentValue->invoke($snapshot, $key) !== 'env') {
        throw new RuntimeException('Environment array must have second priority.');
    }
    unset($_ENV[$key]);
    if ($currentValue->invoke($snapshot, $key) !== 'process') {
        throw new RuntimeException('Process environment value must have third priority.');
    }
} finally {
    unset($_SERVER[$key], $_ENV[$key]);
    putenv($key);
}

foreach (['absent', 'process-value', 'process-guard', 'process-model', 'false-guard', 'invalid-model', 'named', 'dotenv', 'selected-dotenv', 'config-cache', 'custom-cache-path', 'custom-helper', 'custom-env', 'mutated', 'config-mutated', 'repository-escape', 'global-write', 'global-read', 'metadata'] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago auth environment '.bin2hex(random_bytes(8));
    mkdir($workspace);
    mkdir($workspace.'/config');
    mkdir($workspace.'/bootstrap');
    $framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
    mkdir($framework.'/Foundation', 0777, true);
    mkdir($framework.'/Support', 0777, true);
    copy(__DIR__.'/fixtures/analysis/auth-types.php.stub', $workspace.'/models.php');
    copy(__DIR__.'/fixtures/analysis/auth-chains.php.stub', $workspace.'/framework.php');
    copy(__DIR__.'/fixtures/analysis/auth-chains-helpers.php.stub', $framework.'/Foundation/helpers.php');
    file_put_contents($framework.'/Support/helpers.php', <<<'PHP'
        <?php
        use Illuminate\Support\Env;
        if (! function_exists('env')) {
            /** @return mixed */
            function env($key, $default = null)
            {
                return Env::get($key, $default);
            }
        }
        PHP);
    file_put_contents($framework.'/Support/Env.php', <<<'PHP'
        <?php
        namespace Illuminate\Support;

        use Dotenv\Repository\Adapter\PutenvAdapter;
        use Dotenv\Repository\RepositoryBuilder;
        use PhpOption\Option;

        class Env
        {
            protected static $putenv = true;
            protected static $repository;
            protected static $customAdapters = [];

            public static function getRepository()
            {
                if (static::$repository === null) {
                    $builder = RepositoryBuilder::createWithDefaultAdapters();

                    if (static::$putenv) {
                        $builder = $builder->addAdapter(PutenvAdapter::class);
                    }

                    foreach (static::$customAdapters as $adapter) {
                        $builder = $builder->addAdapter($adapter());
                    }

                    static::$repository = $builder->immutable()->make();
                }

                return static::$repository;
            }

            public static function get($key, $default = null)
            {
                return self::getOption($key)->getOrCall(fn () => value($default));
            }

            protected static function getOption($key)
            {
                return Option::fromValue(static::getRepository()->get($key))
                    ->map(function ($value) {
                        switch (strtolower($value)) {
                            case 'true':
                            case '(true)':
                                return true;
                            case 'false':
                            case '(false)':
                                return false;
                            case 'empty':
                            case '(empty)':
                                return '';
                            case 'null':
                            case '(null)':
                                return;
                        }

                        if (preg_match('/\A([\'"])(.*)\1\z/', $value, $matches)) {
                            return $matches[2];
                        }

                        return $value;
                    });
            }
        }
        PHP);
    if ($mode === 'custom-helper') {
        file_put_contents($framework.'/Support/helpers.php', '<?php function env($key, $default = null): mixed { return "admin"; }');
    }
    if ($mode === 'custom-env') {
        $contents = file_get_contents($framework.'/Support/Env.php');
        file_put_contents($framework.'/Support/Env.php', str_replace(
            'return self::getOption($key)->getOrCall(fn () => value($default));',
            'return "admin";',
            $contents,
        ));
    }
    $configuration = file_get_contents(__DIR__.'/fixtures/analysis/auth-config.php.stub');
    $configuration = str_replace(
        ["'defaults' => ['guard' => 'web']", "'model' => Member::class"],
        ["'defaults' => ['guard' => env('AUTH_GUARD', 'web')]", "'model' => env('AUTH_MODEL', Member::class)"],
        $configuration,
    );
    if ($mode === 'named') {
        $configuration = str_replace(
            "env('AUTH_GUARD', 'web')",
            "env(default: 'web', key: 'AUTH_GUARD')",
            $configuration,
        );
    }
    if ($mode === 'config-mutated') {
        $configuration = str_replace(
            "file_put_contents(__DIR__.'/executed.txt', 'Configuration must never run.');",
            "putenv('AUTH_GUARD=admin');",
            $configuration,
        );
    }
    file_put_contents($workspace.'/config/auth.php', $configuration);
    // A selected .env file is relevant only when APP_ENV selects it.
    file_put_contents($workspace.'/.env.example', "AUTH_GUARD=admin\n");
    file_put_contents($workspace.'/.env.testing', "AUTH_GUARD=admin\n");
    if ($mode === 'dotenv') {
        file_put_contents($workspace.'/.env', "AUTH_GUARD=admin\n");
    }
    if ($mode === 'config-cache') {
        mkdir($workspace.'/bootstrap/cache');
        file_put_contents($workspace.'/bootstrap/cache/config.php', '<?php throw new RuntimeException("Cache executed");');
    }
    if ($mode === 'mutated') {
        file_put_contents($workspace.'/bootstrap/app.php', '<?php $app->loadEnvironmentFrom("other.env");');
    } elseif ($mode === 'repository-escape') {
        file_put_contents($workspace.'/bootstrap/app.php', '<?php \Illuminate\Support\Env::getRepository()->set("AUTH_GUARD", "admin");');
    } elseif ($mode === 'global-write') {
        file_put_contents($workspace.'/bootstrap/app.php', '<?php $_SERVER["AUTH_GUARD"] = "admin";');
    } elseif ($mode === 'global-read') {
        file_put_contents($workspace.'/bootstrap/app.php', '<?php $host = $_SERVER["HTTP_HOST"] ?? null;');
    } else {
        file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Bootstrap executed");');
    }
    if ($mode === 'metadata') {
        file_put_contents($workspace.'/.env', "AUTH_GUARD=admin\n");
        file_put_contents($workspace.'/composer.json', json_encode([
            'extra' => ['laramago' => ['auth' => [
                'default-guard' => 'web',
                'guards' => ['web' => ['model' => 'AuthMember']],
            ]]],
        ], JSON_THROW_ON_ERROR));
    }

    $resolved = in_array($mode, ['absent', 'process-value', 'process-guard', 'process-model', 'named', 'global-read', 'metadata'], true);
    $guardResolved = $resolved || $mode === 'invalid-model';
    $model = in_array($mode, ['process-value', 'process-guard', 'process-model'], true) ? 'AuthAdmin' : 'AuthMember';
    $cases = [
        'request default' => [
            'return (new Request)->user();',
            '?'.$model,
            $resolved ? [] : ['less-specific-return-statement'],
        ],
        'facade default' => [
            'return Auth::user();',
            '?'.$model,
            $resolved ? [] : ['less-specific-return-statement'],
        ],
        'manager default guard' => [
            'return auth()->guard();',
            $mode === 'process-value' || $mode === 'process-guard' ? '\\Illuminate\\Auth\\TokenGuard' : '\\Illuminate\\Auth\\SessionGuard',
            $guardResolved ? [] : ['less-specific-return-statement'],
        ],
        'wrong model stays diagnostic' => [
            'return (new Request)->user();',
            in_array($mode, ['process-value', 'process-guard', 'process-model'], true) ? '?AuthMember' : '?AuthAdmin',
            $resolved ? ['invalid-return-statement'] : ['less-specific-return-statement'],
        ],
        'explicit guard retains model' => [
            'return (new Request)->user("admin");',
            '?AuthAdmin',
            [],
        ],
    ];
    $source = "<?php\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Support\\Facades\\Auth;\n";
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = [$name, $codes];
    }
    file_put_contents($workspace.'/cases.php', $source);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => [
            'models.php',
            'framework.php',
            'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php',
            'vendor/laravel/framework/src/Illuminate/Support/helpers.php',
            'vendor/laravel/framework/src/Illuminate/Support/Env.php',
        ]],
        'extension-hosts' => ['laramago' => ['command' => [
            PHP_BINARY,
            $package.'/bin/laramago-worker.php',
            $package.'/vendor/autoload.php',
            $workspace,
        ], 'workers' => 2]],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $savedEnvironment = [];
    foreach (['AUTH_GUARD', 'AUTH_MODEL', 'APP_ENV', 'APP_CONFIG_CACHE', 'APP_BASE_PATH'] as $key) {
        $savedEnvironment[$key] = getenv($key);
        putenv($key);
    }
    if (in_array($mode, ['process-value', 'process-guard'], true)) {
        putenv('AUTH_GUARD=admin');
    }
    if (in_array($mode, ['process-value', 'process-model'], true)) {
        putenv('AUTH_MODEL=AuthAdmin');
    }
    if ($mode === 'false-guard') {
        putenv('AUTH_GUARD=false');
    }
    if ($mode === 'invalid-model') {
        putenv('AUTH_MODEL=UnknownAuthClass');
    }
    if ($mode === 'selected-dotenv') {
        putenv('APP_ENV=testing');
    }
    if ($mode === 'custom-cache-path') {
        putenv('APP_CONFIG_CACHE=elsewhere/config.php');
    }
    try {
        $process = proc_open(
            [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
            [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
            $pipes,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start Mago.');
        }
        fclose($pipes[0]);
        $exit = proc_close($process);
    } finally {
        foreach ($savedEnvironment as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
    }
    $log = file_get_contents($workspace.'/stderr.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Unexpected Mago failure in '.$mode.'; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $a): bool => $a['kind'] === 'Primary'))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException($mode.' / '.$name.': expected '.json_encode($expected)
                .', got '.json_encode($codes).'; inspect '.$workspace);
        }
        unset($actual[$line]);
        echo 'PASS ['.$mode.']: '.$name."\n";
    }
    if ($actual !== [] || is_file($workspace.'/config/executed.txt')) {
        throw new RuntimeException('Unexpected diagnostics or executed configuration; inspect '.$workspace);
    }
    $resolvedWorkspace = realpath($workspace);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $entry) {
        $path = realpath($entry->getPathname());
        if ($resolvedWorkspace === false || $path === false || ! str_starts_with($path, $resolvedWorkspace.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing cleanup outside test workspace.');
        }
        is_dir($path) ? rmdir($path) : unlink($path);
    }
    rmdir($workspace);
}
