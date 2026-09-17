<?php

declare(strict_types=1);

// Check route declarations through the real SDK worker without executing fixtures.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago route parameters '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/route-parameters.php.stub', $workspace.'/routes.php');
$cases = [
    'distinct parameters' => ['$router->get("/items/{item}/parts/{part}");', 'void', []],
    'repeated parameter' => [
        '$router->get("/items/{item}/parts/{item}");',
        'void',
        ['ichinya/laramago/laramago-duplicate-route-parameter'],
    ],
    'optional repeated parameter' => [
        '$router->post("/items/{item}/{item?}");',
        'void',
        ['ichinya/laramago/laramago-duplicate-route-parameter'],
    ],
    'binding fields normalized' => [
        '$router->put("/items/{item:slug}/{item:id}");',
        'void',
        ['ichinya/laramago/laramago-duplicate-route-parameter'],
    ],
    'named uri argument' => [
        '$router->patch(action: null, uri: "/{item}/{item}");',
        'void',
        ['ichinya/laramago/laramago-duplicate-route-parameter'],
    ],
    'match uri position' => [
        '$router->match(["GET"], "/{item}/{item}");',
        'void',
        ['ichinya/laramago/laramago-duplicate-route-parameter'],
    ],
    'addRoute uri position' => [
        '$router->addRoute(["GET"], "/{item}/{item}", null);',
        'void',
        ['ichinya/laramago/laramago-duplicate-route-parameter'],
    ],
    'setUri repeated parameter' => [
        '(new \Illuminate\Routing\Route)->setUri("/{item}/{item}");',
        'void',
        ['ichinya/laramago/laramago-duplicate-route-parameter'],
    ],
    'dynamic uri deferred' => ['$router->get($uri);', 'void', []],
    'concatenation deferred' => ['$router->get("/{item}/".$uri);', 'void', []],
    'unpacked arguments deferred' => ['$router->get(...["/{item}/{item}"]);', 'void', []],
    'inherited router deferred' => ['(new \Illuminate\Routing\ExtendedRouter)->get("/{item}/{item}");', 'void', []],
    'custom router override deferred' => ['(new \Illuminate\Routing\CustomRouter)->get("/{item}/{item}");', 'void', []],
    'custom route override deferred' => [
        '(new \Illuminate\Routing\CustomRoute)->setUri("/{item}/{item}");',
        'void',
        [],
    ],
    'parameter names case sensitive' => ['$router->get("/{item}/{Item}");', 'void', []],
    'route name existence not guessed' => ['$router->get("/provider-defined");', 'void', []],
    'facade declaration deferred' => ['\Illuminate\Support\Facades\Route::get("/{item}/{item}");', 'void', []],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Routing\Router;
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/**'."\n".' * @return '.$return."\n".' */'."\n";
    $source .= 'function scenario'.count($lines).'(Router $router, string $uri) { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['routes.php']],
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
if ($exit !== 0 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Expected successful analysis with extension warnings and no fallback; inspect '
    .$workspace);
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
    throw new RuntimeException('Unexpected diagnostics outside route-parameter scenarios; inspect '.$workspace);
}
$resolvedWorkspace = realpath($workspace);
foreach (glob($workspace.'/*') ?: [] as $file) {
    $resolvedFile = realpath($file);
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    unlink($resolvedFile);
}
rmdir($workspace);
