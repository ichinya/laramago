<?php

declare(strict_types=1);

// Check literal controller actions through the real SDK worker without executing fixtures.
$binary = getenv('MAGO_BINARY') ?: __DIR__ . '/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()) . '/laramago controller classes ' . bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__ . '/fixtures/analysis/framework.php.stub', $workspace . '/framework.php');
$framework = $workspace . '/vendor/laravel/framework/src/Illuminate';
mkdir($framework . '/Routing', 0777, true);
mkdir($framework . '/Support/Facades', 0777, true);
copy(__DIR__ . '/fixtures/analysis/controller-action-router.php.stub', $framework . '/Routing/Router.php');
copy(__DIR__ . '/fixtures/analysis/route-facade-base.php.stub', $framework . '/Support/Facades/Facade.php');
copy(__DIR__ . '/fixtures/analysis/route-facade.php.stub', $framework . '/Support/Facades/Route.php');
mkdir($workspace . '/app/Http/Controllers', 0777, true);
copy(
    __DIR__ . '/fixtures/analysis/controller-action-classes.php.stub',
    $workspace . '/app/Http/Controllers/ExistingController.php',
);
$warning = ['ichinya/laramago/laramago-missing-controller-class'];
$cases = [
    'existing controller class' => [
        '$router->get("/existing", "\\\\App\\\\Http\\\\Controllers\\\\ExistingController@index");',
        [],
    ],
    'existing class does not require method proof' => [
        '$router->get("/method-later", "\\\\App\\\\Http\\\\Controllers\\\\MethodlessController@missing");',
        [],
    ],
    'relative namespaced controller deferred' => [
        '$router->post("/missing", "App\\\\Http\\\\Controllers\\\\MissingController@index");',
        [],
    ],
    'absolute missing controller class' => [
        '$router->put("/absolute", "\\\\Missing\\\\AbsoluteController@store");',
        $warning,
    ],
    'named action argument' => [
        '$router->patch(uri: "/named", action: "\\\\App\\\\Http\\\\Controllers\\\\MissingController@update");',
        $warning,
    ],
    'match action position' => [
        '$router->match(["GET"], "/matched", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        $warning,
    ],
    'addRoute action position' => [
        '$router->addRoute(["GET"], "/added", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        $warning,
    ],
    'imported route facade alias' => [
        'LaravelRoute::get("/facade", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        $warning,
    ],
    'bare legacy controller deferred' => [
        '$router->get("/legacy", "MissingController@index");',
        [],
    ],
    'relative legacy namespace deferred' => [
        '$router->get("/legacy-admin", "Admin\\\\MissingController@index");',
        [],
    ],
    'PHP imports do not resolve string actions' => [
        '$router->get("/imported", "ImportedController@index");',
        [],
    ],
    'dynamic action deferred' => ['$router->get("/dynamic", $action);', []],
    'concatenated action deferred' => [
        '$router->get("/concatenated", ExistingController::class."@index");',
        [],
    ],
    'array action deferred' => ['$router->get("/array", [ExistingController::class, "index"]);', []],
    'unpacked arguments deferred' => [
        '$router->get(...["/unpacked", "App\\\\Http\\\\Controllers\\\\MissingController@index"]);',
        [],
    ],
    'known namespace in unknown route group deferred' => [
        '$router->group($attributes, function () use ($router): void { '
            . '$router->get("/grouped", "App\\\\Http\\\\Controllers\\\\MissingController@index"); });',
        [],
    ],
    'inherited router deferred' => [
        '(new \\Illuminate\\Routing\\ExtendedRouter)->get('
            . '"/extended", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        [],
    ],
    'custom router override deferred' => [
        '(new \\Illuminate\\Routing\\CustomRouter)->get('
            . '"/custom", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        [],
    ],
    'facade subclass deferred' => [
        '\\Illuminate\\Support\\Facades\\CustomRouteFacade::get('
            . '"/custom-facade", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        [],
    ],
    'non action literal deferred' => ['$router->get("/plain", "not-an-action");', []],
];
$source = <<<'PHP'
    <?php
    namespace Routes;

    use App\Http\Controllers\ExistingController;
    use App\Http\Controllers\ExistingController as ImportedController;
    use Illuminate\Routing\Router as NativeRouter;
    use Illuminate\Support\Facades\Route as LaravelRoute;
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $codes]) {
    $source .=
        'function scenario'
        . count($lines)
        . '(NativeRouter $router, mixed $action, array $attributes): void { '
        . $body
        . ' }'
        . "\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace . '/cases.php', $source);
file_put_contents($workspace . '/mago.json', json_encode([
    'extends' => $package . '/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php', 'app'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Routing/Router.php',
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Route.php',
        ],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                $package . '/bin/laramago-worker.php',
                $package . '/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 3,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$analyze = static function (string $report, string $log) use ($command, $workspace): void {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace . '/' . $report, 'w'],
            2 => ['file', $workspace . '/' . $log, 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace . '/' . $log);
    if (
        $exit !== 0
        || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)
    ) {
        throw new RuntimeException('Expected successful analysis with extension warnings and no fallback; inspect '
        . $workspace);
    }
};

$analyze('report.json', 'stderr.log');
$report = json_decode(file_get_contents($workspace . '/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn(array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException(
            $name . ': expected ' . json_encode($expected) . ', got ' . json_encode($codes) . '; see ' . $workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: ' . $name . "\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics outside controller-action scenarios; inspect ' . $workspace);
}

$configuration = json_decode(file_get_contents($workspace . '/mago.json'), true, flags: JSON_THROW_ON_ERROR);
unset($configuration['extension-hosts']);
file_put_contents($workspace . '/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$analyze('disabled.json', 'disabled.log');
$report = json_decode(file_get_contents($workspace . '/disabled.json'), true, flags: JSON_THROW_ON_ERROR);
if (($report['issues'] ?? []) !== []) {
    throw new RuntimeException('Disabled analyzer must leave literal route actions native; inspect ' . $workspace);
}
echo "PASS: disabled analyzer leaves literal route actions native\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
$resolvedWorkspace = realpath($workspace);
foreach ($iterator as $file) {
    $resolvedFile = realpath($file->getPathname());
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || !str_starts_with($resolvedFile, $resolvedWorkspace . DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);
