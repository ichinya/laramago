<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
require $package.'/vendor/autoload.php';
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago config writers '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/bootstrap');
mkdir($workspace.'/config');
$framework = $workspace.'/dependencies with spaces/laravel/framework/src/Illuminate';
mkdir($framework.'/Support/Facades', 0777, true);
mkdir($framework.'/Config', 0777, true);
copy(__DIR__.'/fixtures/analysis/configuration-facade-base.php.stub', $framework.'/Support/Facades/Facade.php');
copy(
    __DIR__.'/fixtures/analysis/configuration-array-writer-facade.php.stub',
    $framework.'/Support/Facades/Config.php',
);
copy(__DIR__.'/fixtures/analysis/configuration-array-writer-arr.php.stub', $framework.'/Support/Arr.php');
copy(
    __DIR__.'/fixtures/analysis/configuration-array-writer-repository.php.stub',
    $framework.'/Config/Repository.php',
);
file_put_contents($workspace.'/config/example.php', <<<'PHP'
    <?php
    return [
        'list' => ['existing'],
        'empty' => [],
        'string' => 'value',
        'integer' => 42,
        'nothing' => null,
        'false' => false,
        'true' => true,
        'dynamic' => env('EXAMPLE_DYNAMIC'),
        'nested' => [
            'list' => [],
            'string' => 'nested value',
        ],
        'open' => [
            'string' => 'uncertain',
            ...runtimeConfiguration(),
        ],
    ];
    PHP);

$contract = [
    'complete' => true,
    'runtime-configuration-unchanged' => true,
];
$writeComposer = static function (mixed $configuration, ?array $bindingFiles = null) use ($workspace): void {
    $laramago = ['configuration-keys' => $configuration];
    if ($bindingFiles !== null) {
        $laramago['binding-files'] = $bindingFiles;
    }
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => $laramago],
    ], JSON_THROW_ON_ERROR));
};
$writeComposer($contract);

$warning = ['ichinya/laramago/laramago-non-array-configuration-writer-target'];
$cases = [
    'push accepts an existing list' => ['Config::push("example.list", "next");', []],
    'prepend accepts an empty array' => ['Config::prepend("example.empty", "first");', []],
    'push rejects a source-proven string target' => ['Config::push("example.string", "next");', $warning],
    'prepend rejects a source-proven integer target' => ['Config::prepend("example.integer", 1);', $warning],
    'push permits a null target to initialize an array' => ['Config::push("example.nothing", "next");', []],
    'prepend rejects a source-proven null target' => ['Config::prepend("example.nothing", "first");', $warning],
    'push false defers because PHP permits deprecated conversion' => ['Config::push("example.false", "next");', []],
    'prepend false conservatively defers' => ['Config::prepend("example.false", "first");', []],
    'push rejects a source-proven true target' => ['Config::push("example.true", "next");', $warning],
    'named push arguments retain the key target' => [
        'Config::push(value: "next", key: "example.string");',
        $warning,
    ],
    'nested scalar target is checked' => ['Config::prepend("example.nested.string", "first");', $warning],
    'nested array target is accepted' => ['Config::push("example.nested.list", "next");', []],
    'missing push target initializes an array' => ['Config::push("example.missing", "next");', []],
    'missing nested prepend target initializes an array' => [
        'Config::prepend("example.nested.missing", "first");',
        [],
    ],
    'dynamic source target defers' => ['Config::push("example.dynamic", "next");', []],
    'source-incomplete parent defers' => ['Config::prepend("example.open.string", "first");', []],
    'dynamic call target defers' => [
        '$key = "example.string"; Config::push($key, "next");',
        [],
    ],
    'repository instance has no application provenance' => [
        '(new Repository)->push("example.string", "next");',
        [],
    ],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Config\Repository;
    use Illuminate\Support\Facades\Config;
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $expected]) {
    $source .= 'function scenario'.count($lines).'(): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $expected];
}
file_put_contents($workspace.'/cases.php', $source);

$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Facades/Config.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Arr.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Config/Repository.php',
        ],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 2,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$analyze = static function (string $label) use ($command, $workspace): array {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$label.'.json', 'w'],
            2 => ['file', $workspace.'/'.$label.'.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$label.'.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException($label.': external analyzer failed; inspect '.$workspace);
    }

    return [
        $exit,
        json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR),
    ];
};

$assertCases = static function (string $label, int $exit, array $report) use ($lines, $workspace): void {
    if ($exit !== 0) {
        throw new RuntimeException($label.': Mago analysis failed; inspect '.$workspace);
    }
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException($label.': unexpected diagnostics outside scenarios; inspect '.$workspace);
    }
};

[$exit, $report] = $analyze('enabled');
$assertCases('enabled', $exit, $report);

$guards = [
    'no-contract' => null,
    'incomplete-contract' => ['complete' => false, 'runtime-configuration-unchanged' => true],
    'runtime-assertion-omitted' => ['complete' => true],
    'runtime-mutation-allowed' => ['complete' => true, 'runtime-configuration-unchanged' => false],
    'malformed-contract' => 'complete',
];
foreach ($guards as $label => $guard) {
    $writeComposer($guard);
    [$guardExit, $guardReport] = $analyze($label);
    if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
        throw new RuntimeException($label.': expected no advisory diagnostics; inspect '.$workspace);
    }
    echo 'PASS: '.str_replace('-', ' ', $label)."\n";
}

$writeComposer($contract);
$repositoryPath = $framework.'/Config/Repository.php';
$repositorySource = file_get_contents($repositoryPath);
file_put_contents(
    $repositoryPath,
    str_replace('return Arr::get($this->items, $key, $default);', 'return $default;', $repositorySource),
);
[$guardExit, $guardReport] = $analyze('changed-get-body');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Changed Repository::get body must defer; inspect '.$workspace);
}
echo "PASS: changed Repository get body defers\n";

file_put_contents(
    $repositoryPath,
    str_replace('$array = $this->get($key, []);', '$array = [];', $repositorySource),
);
[$guardExit, $guardReport] = $analyze('changed-writer-body');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Changed native writer bodies must defer; inspect '.$workspace);
}
echo "PASS: changed native writer bodies defer\n";
file_put_contents($repositoryPath, $repositorySource);

$facadePath = $framework.'/Support/Facades/Config.php';
$facadeSource = str_replace("\r\n", "\n", file_get_contents($facadePath));
file_put_contents($facadePath, str_replace("return 'config'", "return 'custom.config'", $facadeSource));
[$guardExit, $guardReport] = $analyze('custom-facade-accessor');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Custom facade accessor must defer; inspect '.$workspace);
}
echo "PASS: custom facade accessor defers\n";

file_put_contents(
    $facadePath,
    str_replace(
        "{\n    protected static function getFacadeAccessor()",
        "{\n    public static function push(string \$key, mixed \$value): void {}\n\n"
        ."    public static function prepend(string \$key, mixed \$value): void {}\n\n"
        .'    protected static function getFacadeAccessor()',
        $facadeSource,
    ),
);
[$guardExit, $guardReport] = $analyze('custom-facade-method');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Concrete facade writer must retain priority; inspect '.$workspace);
}
echo "PASS: concrete facade writer retains priority\n";
file_put_contents($facadePath, $facadeSource);

file_put_contents(
    $workspace.'/bootstrap/bindings.php',
    '<?php \\app()->singleton("config", CustomConfiguration::class);',
);
$writeComposer($contract, ['bootstrap/bindings.php']);
[$guardExit, $guardReport] = $analyze('custom-config-binding');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Custom config binding must defer; inspect '.$workspace);
}
echo "PASS: custom config binding defers\n";

echo "PASS: configuration array writer targets preserve native initialization and dispatch boundaries\n";
