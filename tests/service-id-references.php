<?php

declare(strict_types=1);

// Analyze generated source with the real Mago worker. Never execute application PHP.
// Laravel excerpts: Copyright (c) Taylor Otwell. See fixtures/analysis/configuration-LICENSE.md.
if (! isset($argv[1])) {
    foreach ([
        'native',
        'disabled',
        'malformed',
        'absent',
        'custom-helper',
        'changed-app',
        'changed-resolve',
        'custom-doc',
    ] as $mode) {
        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, $mode],
            [STDIN, STDOUT, STDERR],
            $pipes,
        );
        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Service reference scenario failed: '.$mode);
        }
    }
    exit(0);
}
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago service IDs '.bin2hex(random_bytes(8));
$mode = $argv[1] ?? 'native';
if (! in_array(
    $mode,
    ['native', 'disabled', 'malformed', 'absent', 'custom-helper', 'changed-app', 'changed-resolve', 'custom-doc'],
    true,
)) {
    throw new RuntimeException('Unknown mode.');
}
mkdir($workspace);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Foundation';
mkdir($framework, 0777, true);
$helperPath = $mode === 'custom-helper' ? $workspace.'/custom/helpers.php' : $framework.'/helpers.php';
if ($mode === 'custom-helper') {
    mkdir($workspace.'/custom');
}
$helper = <<<'PHP'
    <?php
    use Illuminate\Container\Container;
    /**
     * @template TClass of object
     * @param string|class-string<TClass>|null $abstract
     * @return ($abstract is class-string<TClass> ? TClass : ($abstract is null ? \Illuminate\Foundation\Application : mixed))
     */
    function app($abstract = null, array $parameters = [])
    {
        if (is_null($abstract)) { return Container::getInstance(); }
        return Container::getInstance()->make($abstract, $parameters);
    }
    /**
     * @template TClass of object
     * @param string|class-string<TClass> $name
     * @return ($name is class-string<TClass> ? TClass : mixed)
     */
    function resolve($name, array $parameters = []) { return app($name, $parameters); }
    PHP;
if ($mode === 'changed-app') {
    $helper = str_replace(
        'return Container::getInstance()->make($abstract, $parameters);',
        'return $abstract;',
        $helper,
    );
}
if ($mode === 'changed-resolve') {
    $helper = str_replace('return app($name, $parameters);', 'return $name;', $helper);
}
if ($mode === 'custom-doc') {
    $helper = preg_replace('/@return [^\n]+/', '@return string', $helper);
}
file_put_contents($helperPath, $helper);
$containerPath = $workspace.'/framework.php';
file_put_contents($containerPath, <<<'PHP'
    <?php
    namespace Illuminate\Container { class Container {
        public static function getInstance(): static { return new static; }
        public function make($abstract, array $parameters = []): mixed { return null; }
    } }
    namespace Illuminate\Foundation { class Application extends \Illuminate\Container\Container {} }
    PHP);
$complete = $mode !== 'disabled';
$catalog = ['complete' => $complete, 'ids' => ['clock', '', '  : = ']];
if ($mode === 'malformed') {
    $catalog['ids'][] = 42;
}
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => $mode === 'absent' ? [] : ['service-ids' => $catalog]],
], JSON_THROW_ON_ERROR));

$warning = ['ichinya/laramago/laramago-uncataloged-service-id'];
$cases = [
    'known ID' => ['app("clock");', []],
    'uncataloged app ID' => ['app("TYPO");', $warning],
    'uncataloged resolve ID' => ['resolve("TYPO");', $warning],
    'parameters forwarded' => ['app("TYPO", ["zone" => "UTC"]);', $warning],
    'named abstract' => ['app(abstract: "TYPO");', $warning],
    'named resolve ID' => ['resolve(name: "TYPO");', $warning],
    'reordered named arguments' => ['resolve(parameters: [], name: "TYPO");', $warning],
    'qualified helper' => ['\\app("TYPO");', $warning],
    'imported helper' => ['native_app("TYPO");', $warning],
    'case sensitive' => ['app("Clock");', $warning],
    'raw slash identity' => ['app("\\\\clock");', $warning],
    'permitted unusual ID' => ['app("  : = ");', []],
    'permitted empty ID' => ['app("");', []],
    'class literal native priority' => ['app(\\stdClass::class);', []],
    'autowirable literal is a policy reference' => ['app("stdClass");', $warning],
    'native missing class' => ['app(\\MissingService::class);', ['non-existent-class-like']],
    'native invalid parameters' => ['app("TYPO", "invalid");', ['invalid-argument']],
    'dynamic ID' => ['app($key);', []],
    'concatenated ID' => ['app("T".$key);', []],
    'unpacked arguments' => ['app(...["TYPO"]);', []],
    'first-class callable' => ['$callable = app(...);', []],
    'container getter' => ['app();', []],
    'null container getter' => ['app(null);', []],
];
$source = "<?php\nnamespace App {\nuse function app as native_app;\n";
$expected = [];
$expectedSpans = [];
foreach ($cases as $label => [$body, $codes]) {
    $source .= 'function case'.count($expected).'(string $key): void { '.$body." }\n";
    $line = substr_count($source, "\n");
    $expected[$line] = [
        $label,
        $codes !== $warning || ($mode === 'native' || $mode === 'changed-resolve' && ! str_contains($body, 'resolve('))
            ? $codes
            : [],
    ];
    if ($codes === $warning) {
        preg_match('/"(?:\\\\.|[^"\\\\])*"/', $body, $literal);
        $expectedSpans[$line] = [$literal[0]];
        if ($label === 'reordered named arguments') {
            $expectedSpans[$line] = ['"TYPO"'];
        }
    }
}
$source .= "}\nnamespace Custom {\nfunction app(string \$key): string { return \$key; }\n";
$source .= "function local(): void { app(\"TYPO\"); }\n}\n";
$localLine = substr_count($source, "\n") - 1;
$expected[$localLine] = ['namespaced custom helper', []];
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            substr($helperPath, strlen($workspace) + 1),
            substr($containerPath, strlen($workspace) + 1),
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
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
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
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
$spans = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $actual[$line][] = $issue['code'];
    if ($issue['code'] === 'ichinya/laramago/laramago-uncataloged-service-id') {
        $spans[$line][] = substr(
            $source,
            $primary['span']['start']['offset'],
            $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
        );
    }
}
foreach ($expected as $line => [$label, $codes]) {
    sort($codes);
    $actual[$line] ??= [];
    sort($actual[$line]);
    if ($actual[$line] !== $codes) {
        throw new RuntimeException(
            $mode
            .' '
            .$label
            .': expected '
            .json_encode($codes)
            .', got '
            .json_encode($actual[$line])
            .'; inspect '
            .$workspace,
        );
    }
    if ($codes === $warning && ($spans[$line] ?? []) !== $expectedSpans[$line]) {
        throw new RuntimeException($mode.' '.$label.': wrong literal span; inspect '.$workspace);
    }
    unset($actual[$line]);
    echo 'PASS: '.$mode.' '.$label."\n";
}
if (
    $actual !== []
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/stderr.log'),
    )
) {
    throw new RuntimeException('Unexpected issues or worker failure; inspect '.$workspace);
}
if ($exit !== 1) {
    throw new RuntimeException('Unexpected exit '.$exit.'; inspect '.$workspace);
}
