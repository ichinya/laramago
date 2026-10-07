<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago configuration '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/config');
mkdir($workspace.'/bootstrap');
$helpers = 'dependencies with spaces/laravel/framework/src/Illuminate/Foundation/helpers.php';
$envHelpers = 'dependencies with spaces/laravel/framework/src/Illuminate/Support/helpers.php';
mkdir(dirname($workspace.'/'.$helpers), 0777, true);
$framework = $workspace.'/dependencies with spaces/laravel/framework/src/Illuminate';
mkdir($framework.'/Support/Facades', 0777, true);
mkdir($framework.'/Config', 0777, true);
copy(__DIR__.'/fixtures/analysis/configuration-facade-base.php.stub', $framework.'/Support/Facades/Facade.php');
copy(__DIR__.'/fixtures/analysis/configuration-facade.php.stub', $framework.'/Support/Facades/Config.php');
copy(__DIR__.'/fixtures/analysis/configuration-repository.php.stub', $framework.'/Config/Repository.php');
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Bootstrap executed.");');
file_put_contents(
    $workspace.'/'.$helpers,
    '<?php function config(?string $key = null, mixed $default = null): mixed { throw new RuntimeException("Helper executed."); }',
);
file_put_contents($workspace.'/'.$envHelpers, <<<'PHP'
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
file_put_contents($workspace.'/config/example.php', <<<'PHP'
    <?php
    return [
        'name' => 'Example',
        'count' => 4,
        'enabled' => true,
        'optional' => null,
        'nested' => ['count' => 2],
        'with.dot' => 'direct',
        'items' => ['first', 'second'],
        'dynamic' => env('EXAMPLE', 'fallback'),
        'nullable_env' => env('MISSING'),
        'env_array' => ['value' => env('EXAMPLE', 'fallback')],
        'unsafe_env_default' => env('EXAMPLE', fn (): int => 42),
        'computed' => throw new RuntimeException('Configuration executed.'),
        'open' => [...dynamicConfiguration()],
        'class' => stdClass::class,
    ];
    PHP);
file_put_contents($workspace.'/config/dynamic.php', '<?php return dynamicConfiguration();');
file_put_contents(
    $workspace.'/config/conditional.php',
    '<?php if (true) { return ["count" => 1]; } return ["count" => "other"];',
);
$cases = [
    'literal string' => ["return config('example.name');", 'string', []],
    'literal integer' => ["return config('example.count');", 'int', []],
    'literal boolean' => ["return config('example.enabled');", 'bool', []],
    'nested key' => ["return config('example.nested.count');", 'int', []],
    'dotted array key is not traversable' => ["return config('example.with.dot');", 'null', []],
    'closure default deferred' => [
        "return config('example.missing', fn(): int => 42);",
        'int',
        ['mixed-return-statement'],
    ],
    'array shape' => ["return config('example.nested')['count'];", 'int', []],
    'list offset' => ["return config('example.items')[1];", 'string', []],
    'class name' => ["return config('example.class');", 'string', []],
    'missing complete key default' => ["return config('example.absent', 42);", 'int', []],
    'named default arguments' => ["return config(default: 42, key: 'example.absent');", 'int', []],
    'missing complete key null' => ["return config('example.absent');", 'null', []],
    'null ignores default' => ["return config('example.optional', 42);", 'null', []],
    'native mismatch preserved' => ["return config('example.count');", 'string', ['invalid-return-statement']],
    'environment has safe union' => ["return config('example.dynamic', 'fallback');", 'string', ['invalid-return-statement', 'nullable-return-statement']],
    'environment assignment is typed' => ["\$value = config('example.dynamic');", 'void', []],
    'environment missing is nullable' => ["return config('example.nullable_env');", 'string', ['invalid-return-statement', 'nullable-return-statement']],
    'environment nested array' => ["\$value = config('example.env_array');", 'void', []],
    'unsafe environment default deferred' => ["return config('example.unsafe_env_default');", 'string', ['mixed-return-statement']],
    'direct environment helper typed' => ["\$value = env('EXAMPLE');", 'void', []],
    'direct environment declared scalar default' => ["return env('EXAMPLE', 'fallback');", 'string', []],
    'default integer generalizes' => ["return env('EXAMPLE', 42);", 'int', []],
    'default float generalizes' => ["return env('EXAMPLE', 2.5);", 'float', []],
    'default true generalizes' => ["return env('EXAMPLE', true);", 'bool', []],
    'default false generalizes' => ["return env('EXAMPLE', false);", 'bool', []],
    'explicit null declaration' => ["return env('EXAMPLE', null);", 'null', []],
    'empty default stays string' => ["return env('EXAMPLE', '');", 'string', []],
    'numeric default stays string' => ["return env('EXAMPLE', '42');", 'string', []],
    'reserved default stays string' => ["return env('EXAMPLE', 'null');", 'string', []],
    'named env default' => ["return env(default: 'fallback', key: 'EXAMPLE');", 'string', []],
    'string default mismatch remains' => ["return env('EXAMPLE', 'fallback');", 'int', ['invalid-return-statement']],
    'integer default mismatch remains' => ["return env('EXAMPLE', 42);", 'string', ['invalid-return-statement']],
    'boolean default mismatch remains' => ["return env('EXAMPLE', true);", 'string', ['invalid-return-statement']],
    'string literal is widened' => ["return env('EXAMPLE', 'fallback');", "'fallback'", ['invalid-return-statement']],
    'integer literal is widened' => ["return env('EXAMPLE', 42);", '42', ['invalid-return-statement']],
    'false literal is widened' => ["return env('EXAMPLE', false);", 'false', ['less-specific-return-statement']],
    'closure env default stays native' => ["return env('EXAMPLE', fn (): int => 42);", 'int', ['mixed-return-statement']],
    'dynamic env key stays native' => ["return env((string) random_int(1, 2), 'fallback');", 'string', ['mixed-return-statement']],
    'throw never executed' => ["return config('example.computed');", 'string', ['mixed-return-statement']],
    'unpack missing key deferred' => ["return config('example.open.missing', 42);", 'int', ['mixed-return-statement']],
    'unknown namespace deferred' => ["return config('unknown.missing', 42);", 'int', ['mixed-return-statement']],
    'dynamic namespace deferred' => ["return config('dynamic.missing', 42);", 'int', ['mixed-return-statement']],
    'conditional namespace deferred' => ["return config('conditional.count');", 'int', ['mixed-return-statement']],
    'unknown array root deferred' => ["return config('example');", 'array', ['mixed-return-statement']],
    'native config facade literal' => [
        "return \\Illuminate\\Support\\Facades\\Config::get('example.count');",
        'int',
        [],
    ],
    'native config facade named default' => [
        "return \\Illuminate\\Support\\Facades\\Config::get(default: 42, key: 'example.absent');",
        'int',
        [],
    ],
    'native config facade dynamic key deferred' => [
        '$key = (string) random_int(1, 2); return \\Illuminate\\Support\\Facades\\Config::get($key);',
        'int',
        ['mixed-return-statement'],
    ],
    'arbitrary configuration repository deferred' => [
        "return (new \\Illuminate\\Config\\Repository)->get('example.count');",
        'int',
        ['mixed-return-statement'],
    ],
];
$source = "<?php\n";
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
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            $helpers,
            $envHelpers,
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Env.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Facades/Config.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Config/Repository.php',
        ],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 3,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/report.json', 'w'],
        2 => ['file', $workspace.'/stderr.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Expected native diagnostics without extension fallback; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException(
            $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics outside configuration scenarios; inspect '.$workspace);
}

$assertAlteredContractWins = static function (string $name, array $expected) use ($command, $workspace): void {
    file_put_contents(
        $workspace.'/cases.php',
        '<?php function alteredContract(): string { return \\Illuminate\\Support\\Facades\\Config::get("example.count"); }',
    );
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/altered.json', 'w'],
            2 => ['file', $workspace.'/altered.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $report = json_decode(file_get_contents($workspace.'/altered.json'), true, flags: JSON_THROW_ON_ERROR);
    $codes = array_column($report['issues'] ?? [], 'code');
    if (
        $exit !== ($expected === [] ? 0 : 1)
        || $codes !== $expected
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($workspace.'/altered.log'),
        )
    ) {
        throw new RuntimeException($name.' must retain its installed return contract; inspect '.$workspace);
    }
    echo 'PASS: '.$name."\n";
};
$configFacadePath = $framework.'/Support/Facades/Config.php';
$configFacadeSource = file_get_contents($configFacadePath);
file_put_contents($configFacadePath, str_replace(
    '@method static mixed get',
    '@method static string get',
    $configFacadeSource,
));
$assertAlteredContractWins('altered facade contract priority', []);
file_put_contents($configFacadePath, $configFacadeSource);
$repositoryPath = $framework.'/Config/Repository.php';
$repositorySource = file_get_contents($repositoryPath);
file_put_contents($repositoryPath, str_replace('@return mixed', '@return string', $repositorySource));
$assertAlteredContractWins('altered repository contract priority', ['mixed-return-statement']);
file_put_contents($repositoryPath, $repositorySource);

$assertEnvironmentDefers = static function (string $name) use ($command, $workspace): void {
    file_put_contents(
        $workspace.'/cases.php',
        '<?php function configEnv(): string { return config("example.dynamic"); } '
        .'function directEnv(): string { return env("EXAMPLE"); }',
    );
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/altered-env.json', 'w'],
            2 => ['file', $workspace.'/altered-env.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $report = json_decode(file_get_contents($workspace.'/altered-env.json'), true, flags: JSON_THROW_ON_ERROR);
    $codes = array_column($report['issues'] ?? [], 'code');
    if (
        $exit !== 1
        || $codes !== ['mixed-return-statement', 'mixed-return-statement']
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($workspace.'/altered-env.log'),
        )
    ) {
        throw new RuntimeException($name.' must defer to native mixed; inspect '.$workspace);
    }
    echo 'PASS: '.$name."\n";
};
$envPath = $framework.'/Support/Env.php';
$envSource = file_get_contents($envPath);
file_put_contents($envPath, str_replace(
    'return self::getOption($key)->getOrCall(fn () => value($default));',
    'return [];',
    $envSource,
));
$assertEnvironmentDefers('changed Env::get body');
file_put_contents($envPath, $envSource);
$envHelperPath = $workspace.'/'.$envHelpers;
$envHelperSource = file_get_contents($envHelperPath);
file_put_contents($envHelperPath, str_replace('return Env::get($key, $default);', 'return [];', $envHelperSource));
$assertEnvironmentDefers('changed native env helper body');
file_put_contents($envHelperPath, $envHelperSource);
file_put_contents(
    $envPath,
    $envSource."\nclass AlternativeEnv { public static function get(\$key, \$default = null): mixed { return []; } }\n",
);
file_put_contents(
    $envHelperPath,
    str_replace('use Illuminate\\Support\\Env;', 'use Illuminate\\Support\\AlternativeEnv as Env;', $envHelperSource),
);
$assertEnvironmentDefers('changed native env helper import');
file_put_contents($envHelperPath, $envHelperSource);
file_put_contents($envPath, $envSource);
file_put_contents(
    $workspace.'/custom-env.php',
    '<?php function env($key, $default = null): mixed { return []; }',
);
$configuration = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
$configuration['source']['includes'] = array_values(array_filter(
    $configuration['source']['includes'],
    static fn (string $path): bool => $path !== $envHelpers,
));
$configuration['source']['includes'][] = 'custom-env.php';
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
$assertEnvironmentDefers('custom env helper takes priority');
$configuration['source']['includes'] = array_values(array_filter(
    $configuration['source']['includes'],
    static fn (string $path): bool => $path !== 'custom-env.php',
));
$configuration['source']['includes'][] = $envHelpers;
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));

file_put_contents(
    $workspace.'/'.$helpers,
    '<?php function config(?string $key = null, mixed $default = null): string { throw new RuntimeException("Helper executed."); }',
);
file_put_contents($workspace.'/cases.php', '<?php function concrete(): string { return config("example.count"); }');
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/override.json', 'w'],
        2 => ['file', $workspace.'/override.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/override.json'), true, flags: JSON_THROW_ON_ERROR);
if ($exit !== 0 || ($report['issues'] ?? []) !== []) {
    throw new RuntimeException('Concrete helper declaration must win; inspect '.$workspace);
}
echo "PASS: concrete helper declaration priority\n";
file_put_contents($workspace.'/config/broken.php', '<?php return [');
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/warning.json', 'w'],
        2 => ['file', $workspace.'/warning.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/warning.json'), true, flags: JSON_THROW_ON_ERROR);
$codes = array_column($report['issues'] ?? [], 'code');
if ($exit > 1 || $codes !== ['ichinya/laramago/configuration-unavailable']) {
    throw new RuntimeException('Malformed configuration must report its own warning; inspect '.$workspace);
}
echo "PASS: malformed configuration warning\n";

file_put_contents(
    $workspace.'/custom-helper.php',
    '<?php function config(?string $key = null, mixed $default = null): mixed { throw new RuntimeException("Custom helper executed."); }',
);
$configuration = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
$configuration['source']['includes'] = ['custom-helper.php'];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/custom.json', 'w'],
        2 => ['file', $workspace.'/custom.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/custom.json'), true, flags: JSON_THROW_ON_ERROR);
if (
    $exit !== 1
    || array_column($report['issues'] ?? [], 'code') !== ['mixed-return-statement']
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/custom.log'),
    )
) {
    throw new RuntimeException('Custom mixed helper must stay native; inspect '.$workspace);
}
echo "PASS: custom mixed helper remains native\n";

// Test reports and syntax-only execution traps remain in the temporary workspace.
