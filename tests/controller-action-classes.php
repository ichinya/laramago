<?php

declare(strict_types=1);

// Check literal controller actions through the real SDK worker without executing fixtures.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago controller classes '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{}');
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
mkdir($framework.'/Routing', 0777, true);
mkdir($framework.'/Support/Facades', 0777, true);
copy(__DIR__.'/fixtures/analysis/controller-action-controller.php.stub', $framework.'/Routing/Controller.php');
copy(__DIR__.'/fixtures/analysis/controller-action-router.php.stub', $framework.'/Routing/Router.php');
copy(__DIR__.'/fixtures/analysis/route-facade-base.php.stub', $framework.'/Support/Facades/Facade.php');
copy(__DIR__.'/fixtures/analysis/route-facade.php.stub', $framework.'/Support/Facades/Route.php');
mkdir($workspace.'/app/Http/Controllers', 0777, true);
copy(
    __DIR__.'/fixtures/analysis/controller-action-classes.php.stub',
    $workspace.'/app/Http/Controllers/ExistingController.php',
);
$classWarning = ['ichinya/laramago/laramago-missing-controller-class'];
$methodWarning = ['ichinya/laramago/laramago-missing-controller-method'];
$visibilityWarning = ['ichinya/laramago/laramago-inaccessible-controller-method'];
$cases = [
    'existing controller class' => [
        '$router->get("/existing", "\\\\App\\\\Http\\\\Controllers\\\\ExistingController@index");',
        [],
    ],
    'missing method with standard dispatch' => [
        '$router->get("/method-later", "\\\\App\\\\Http\\\\Controllers\\\\MethodlessController@missing");',
        $methodWarning,
    ],
    'inherited controller method' => [
        '$router->get("/inherited", "\\\\App\\\\Http\\\\Controllers\\\\ExistingController@inherited");',
        [],
    ],
    'protected controller action through standard callAction' => [
        '$router->get("/protected", "\\\\App\\\\Http\\\\Controllers\\\\ExistingController@protectedAction");',
        [],
    ],
    'inherited protected controller action through standard callAction' => [
        '$router->get("/inherited-protected", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\ExistingController@inheritedProtected");',
        [],
    ],
    'private controller action through standard callAction' => [
        '$router->get("/private", "\\\\App\\\\Http\\\\Controllers\\\\ExistingController@privateAction");',
        $visibilityWarning,
    ],
    'inherited private controller metadata unavailable deferred' => [
        '$router->get("/inherited-private", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\ExistingController@inheritedPrivate");',
        [],
    ],
    'plain controller missing method' => [
        '$router->get("/plain", "\\\\App\\\\Http\\\\Controllers\\\\PlainMethodlessController@missing");',
        $methodWarning,
    ],
    'plain protected controller action' => [
        '$router->get("/plain-protected", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\PlainVisibilityController@protectedAction");',
        $visibilityWarning,
    ],
    'plain private controller action' => [
        '$router->get("/plain-private", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\PlainVisibilityController@privateAction");',
        $visibilityWarning,
    ],
    'custom magic dispatch deferred' => [
        '$router->get("/magic", "\\\\App\\\\Http\\\\Controllers\\\\MagicController@missing");',
        [],
    ],
    'custom magic visibility deferred' => [
        '$router->get("/magic-private", "\\\\App\\\\Http\\\\Controllers\\\\MagicController@privateAction");',
        [],
    ],
    'custom callAction dispatch deferred' => [
        '$router->get("/dispatch", "\\\\App\\\\Http\\\\Controllers\\\\DispatchController@missing");',
        [],
    ],
    'custom callAction visibility deferred' => [
        '$router->get("/dispatch-private", '.'"\\\\App\\\\Http\\\\Controllers\\\\DispatchController@privateAction");',
        [],
    ],
    'relative namespaced controller deferred' => [
        '$router->post("/missing", "App\\\\Http\\\\Controllers\\\\MissingController@index");',
        [],
    ],
    'absolute missing controller class' => [
        '$router->put("/absolute", "\\\\Missing\\\\AbsoluteController@store");',
        $classWarning,
    ],
    'named action argument' => [
        '$router->patch(uri: "/named", action: "\\\\App\\\\Http\\\\Controllers\\\\MissingController@update");',
        $classWarning,
    ],
    'match action position' => [
        '$router->match(["GET"], "/matched", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        $classWarning,
    ],
    'addRoute action position' => [
        '$router->addRoute(["GET"], "/added", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        $classWarning,
    ],
    'imported route facade alias' => [
        'LaravelRoute::get("/facade", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        $classWarning,
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
    'missing action deferred' => ['$router->get("/missing-action");', []],
    'explicit null action deferred' => ['$router->get("/null-action", null);', []],
    'concatenated action deferred' => [
        '$router->get("/concatenated", ExistingController::class."@index");',
        [],
    ],
    'invokable class constant external namespace uncertainty deferred' => [
        '$router->get("/invokable-class", MethodlessController::class);',
        [],
    ],
    'absolute invokable string' => [
        '$router->get("/invokable-string", "\\\\App\\\\Http\\\\Controllers\\\\InvokableController");',
        [],
    ],
    'real invokable method wins over same-name PHPDoc method' => [
        '$router->get("/invokable-real-documented", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\DocumentedRealInvokableController");',
        [],
    ],
    'invokable missing method' => [
        '$router->get("/invokable-missing", "\\\\App\\\\Http\\\\Controllers\\\\MethodlessController");',
        $methodWarning,
    ],
    'invokable PHPDoc method does not satisfy native registration' => [
        '$router->get("/invokable-documented", '.'"\\\\App\\\\Http\\\\Controllers\\\\DocumentedInvokableController");',
        $methodWarning,
    ],
    'invokable custom magic does not satisfy native registration' => [
        '$router->get("/invokable-magic", '.'"\\\\App\\\\Http\\\\Controllers\\\\MagicMethodlessInvokableController");',
        $methodWarning,
    ],
    'invokable custom callAction does not satisfy native registration' => [
        '$router->get("/invokable-dispatch", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\DispatchMethodlessInvokableController");',
        $methodWarning,
    ],
    'inherited invokable method' => [
        '$router->get("/invokable-inherited", '.'"\\\\App\\\\Http\\\\Controllers\\\\InheritedInvokableController");',
        [],
    ],
    'trait invokable method' => [
        '$router->get("/invokable-trait", "\\\\App\\\\Http\\\\Controllers\\\\TraitInvokableController");',
        [],
    ],
    'real trait invokable method wins over same-name PHPDoc method' => [
        '$router->get("/invokable-trait-real-documented", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\DocumentedRealTraitInvokableController");',
        [],
    ],
    'aliased trait invokable deferred without adaptation metadata' => [
        '$router->get("/invokable-trait-alias", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\AliasedTraitInvokableController");',
        [],
    ],
    'relative invokable string deferred' => [
        '$router->get("/invokable-relative", "App\\\\Http\\\\Controllers\\\\MethodlessController");',
        [],
    ],
    'bare invokable string deferred' => ['$router->get("/invokable-bare", "MethodlessController");', []],
    'absolute missing invokable class' => [
        '$router->get("/invokable-missing-class", "\\\\Missing\\\\InvokableController");',
        $classWarning,
    ],
    'native facade invokable action' => [
        'LaravelRoute::get("/invokable-facade", '.'"\\\\App\\\\Http\\\\Controllers\\\\InvokableController");',
        [],
    ],
    'array action existing controller' => ['$router->get("/array", [ExistingController::class, "index"]);', []],
    'array action missing method' => [
        '$router->get("/array-method", [ExistingController::class, "missing"]);',
        $methodWarning,
    ],
    'array action imported alias resolution' => [
        '$router->get("/array-imported", [ImportedController::class, "missing"]);',
        $methodWarning,
    ],
    'array action namespace resolution' => [
        '$router->get("/array-relative", [NamespacedController::class, "missing"]);',
        $methodWarning,
    ],
    'array action protected through standard callAction' => [
        '$router->get("/array-protected", [ExistingController::class, "protectedAction"]);',
        [],
    ],
    'array action private through standard callAction' => [
        '$router->get("/array-private", [ExistingController::class, "privateAction"]);',
        $visibilityWarning,
    ],
    'array action PHPDoc method wins' => [
        '$router->get("/array-documented", [DocumentedController::class, "documentedAction"]);',
        [],
    ],
    'array action custom dispatch deferred' => [
        '$router->get("/array-dispatch", [DispatchController::class, "missing"]);',
        [],
    ],
    'array action dynamic method deferred' => [
        '$router->get("/array-dynamic", [ExistingController::class, $action]);',
        [],
    ],
    'array action dynamic class deferred' => [
        '$router->get("/array-dynamic-class", [$action, "missing"]);',
        [],
    ],
    'array action explicit keys deferred' => [
        '$router->get("/array-keys", [0 => ExistingController::class, 1 => "missing"]);',
        [],
    ],
    'array action configuration deferred' => [
        '$router->get("/array-config", ["uses" => [ExistingController::class, "missing"], '.'"middleware" => "auth"]);',
        [],
    ],
    'array action in namespace group' => [
        '$router->group(["namespace" => "Admin"], function () use ($router): void { '
            .'$router->get("/array-group", [ExistingController::class, "missing"]); });',
        $methodWarning,
    ],
    'array action in dynamic group' => [
        '$router->group($attributes, function () use ($router): void { '
            .'$router->get("/array-group", [ExistingController::class, "missing"]); });',
        $methodWarning,
    ],
    'array action in controller and prefix group' => [
        '$router->group(["controller" => "OtherController", "prefix" => "admin"], function () use ($router): void { '
            .'$router->get("/array-group", [ExistingController::class, "missing"]); });',
        $methodWarning,
    ],
    'nested namespace and controller groups preserve arrays' => [
        '$router->group(["namespace" => "Outer"], function () use ($router): void { '
            .'$router->group(["controller" => "OtherController"], function () use ($router): void { '
            .'$router->get("/nested", [ExistingController::class, "missing"]); }); });',
        $methodWarning,
    ],
    'method-only group action deferred' => [
        '$router->group(["controller" => ExistingController::class], function () use ($router): void { '
            .'$router->get("/method-only", "missing"); });',
        [],
    ],
    'native facade array action' => [
        'LaravelRoute::get("/array-facade", [ExistingController::class, "missing"]);',
        $methodWarning,
    ],
    'inherited router array action deferred' => [
        '(new \\Illuminate\\Routing\\ExtendedRouter)->get('
            .'"/extended-array", [ExistingController::class, "missing"]);',
        [],
    ],
    'custom router array action deferred' => [
        '(new \\Illuminate\\Routing\\CustomRouter)->get('.'"/custom-array", [ExistingController::class, "missing"]);',
        [],
    ],
    'facade subclass array action deferred' => [
        '\\Illuminate\\Support\\Facades\\CustomRouteFacade::get('
            .'"/custom-facade-array", [ExistingController::class, "missing"]);',
        [],
    ],
    'unpacked arguments deferred' => [
        '$router->get(...["/unpacked", "App\\\\Http\\\\Controllers\\\\MissingController@index"]);',
        [],
    ],
    'known namespace in unknown route group deferred' => [
        '$router->group($attributes, function () use ($router): void { '
            .'$router->get("/grouped", "App\\\\Http\\\\Controllers\\\\MissingController@index"); });',
        [],
    ],
    'inherited router deferred' => [
        '(new \\Illuminate\\Routing\\ExtendedRouter)->get('
            .'"/extended", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        [],
    ],
    'custom router override deferred' => [
        '(new \\Illuminate\\Routing\\CustomRouter)->get('
            .'"/custom", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        [],
    ],
    'facade subclass deferred' => [
        '\\Illuminate\\Support\\Facades\\CustomRouteFacade::get('
            .'"/custom-facade", "\\\\App\\\\Http\\\\Controllers\\\\MissingController@index");',
        [],
    ],
    'non action literal deferred' => ['$router->get("/plain", "not-an-action");', []],
];
foreach ([
    'absolute missing controller class',
    'existing controller class',
    'missing method with standard dispatch',
] as $label) {
    [$body, $issues] = $cases[$label];
    $cases['grouped '.$label] = [
        '$router->group(["controller" => "OtherController", "namespace" => "Admin", "prefix" => "admin"], '
            .'function () use ($router): void { '
            .$body
            .' });',
        $issues,
    ];
}
[$body] = $cases['absolute missing invokable class'];
$cases['grouped missing invokable class deferred'] = [
    '$router->group(["controller" => "OtherController"], function () use ($router): void { '.$body.' });',
    [],
];
$source = <<<'PHP'
    <?php
    namespace Routes;

    use App\Http\Controllers\ExistingController;
    use App\Http\Controllers\DispatchController;
    use App\Http\Controllers\DocumentedController;
    use App\Http\Controllers\ExistingController as ImportedController;
    use App\Http\Controllers\MethodlessController;
    use Illuminate\Routing\Router as NativeRouter;
    use Illuminate\Support\Facades\Route as LaravelRoute;

    class NamespacedController extends \Illuminate\Routing\Controller {}
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $codes]) {
    $source .=
        'function scenario'
        .count($lines)
        .'(NativeRouter $router, mixed $action, array $attributes): void { '
        .$body
        .' }'
        ."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
$source .=
    'class SelfActionRegistrar { public function register(NativeRouter $router): void { '
    .'$router->get("/self-array", [self::class, "missing"]); } }'
    ."\n";
$lines[substr_count($source, "\n")] = ['self array class deferred', []];
$source .=
    'class StaticActionRegistrar { public function register(NativeRouter $router): void { '
    .'$router->get("/static-array", [static::class, "missing"]); } }'
    ."\n";
$lines[substr_count($source, "\n")] = ['static array class deferred', []];
$source .=
    'class ParentActionRegistrar extends NamespacedController { public function register(NativeRouter $router): void { '
    .'$router->get("/parent-array", [parent::class, "missing"]); } }'
    ."\n";
$lines[substr_count($source, "\n")] = ['parent array class deferred', []];
file_put_contents($workspace.'/cases.php', $source);
$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php', 'app'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Routing/Router.php',
            'vendor/laravel/framework/src/Illuminate/Routing/Controller.php',
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Route.php',
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
            'workers' => 3,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$analyze = static function (string $report, string $log) use ($command, $workspace): void {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$report, 'w'],
            2 => ['file', $workspace.'/'.$log, 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$log);
    if (
        $exit !== 0
        || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)
    ) {
        throw new RuntimeException('Expected successful analysis with extension warnings and no fallback; inspect '
        .$workspace);
    }
};

$analyze('report.json', 'stderr.log');
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
$actualSpans = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $actual[$line][] = $issue['code'];
    $actualSpans[$line][] = substr(
        $source,
        $primary['span']['start']['offset'],
        $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
    );
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
    $expectedSpan = match ($name) {
        'array action missing method' => '"missing"',
        'array action private through standard callAction' => '"privateAction"',
        'invokable missing method' => '"\\\\App\\\\Http\\\\Controllers\\\\MethodlessController"',
        'invokable PHPDoc method does not satisfy native registration'
            => '"\\\\App\\\\Http\\\\Controllers\\\\DocumentedInvokableController"',
        'invokable custom magic does not satisfy native registration'
            => '"\\\\App\\\\Http\\\\Controllers\\\\MagicMethodlessInvokableController"',
        'invokable custom callAction does not satisfy native registration'
            => '"\\\\App\\\\Http\\\\Controllers\\\\DispatchMethodlessInvokableController"',
        'absolute missing invokable class' => '"\\\\Missing\\\\InvokableController"',
        default => null,
    };
    if ($expectedSpan !== null && ! in_array($expectedSpan, $actualSpans[$line] ?? [], true)) {
        throw new RuntimeException(
            $name
            .': expected diagnostic span '
            .$expectedSpan
            .', got '
            .json_encode($actualSpans[$line] ?? [])
            .'; see '
            .$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics outside controller-action scenarios; inspect '.$workspace);
}

mkdir($workspace.'/bootstrap');
$catalogCases = [
    'explicit controller class binding defers class diagnostic' => [
        <<<'PHP'
                <?php
                throw new \RuntimeException('Binding catalog must never execute.');
                \app()->bind(\Missing\BoundController::class, \App\Http\Controllers\ExistingController::class);
            PHP,
        '$router->get("/bound", "\\\\Missing\\\\BoundController@index");',
        [],
        [],
    ],
    'explicit controller dispatcher binding defers route diagnostics' => [
        <<<'PHP'
            <?php
            throw new \RuntimeException('Binding catalog must never execute.');
            \app()->bind(
                \Illuminate\Routing\Contracts\ControllerDispatcher::class,
                \App\Http\Controllers\CustomControllerDispatcher::class,
            );
            PHP,
        '$router->get("/dispatcher-method", "\\\\App\\\\Http\\\\Controllers\\\\MethodlessController@missing");'
            .'$router->get("/dispatcher-class", "\\\\Missing\\\\UnboundController@index");'
            .'$router->get("/dispatcher-visibility", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\ExistingController@privateAction");'
            .'$router->get("/dispatcher-array-method", '
            .'[\\App\\Http\\Controllers\\MethodlessController::class, "missing"]);'
            .'$router->get("/dispatcher-array-visibility", '
            .'[\\App\\Http\\Controllers\\ExistingController::class, "privateAction"]);',
        [],
        [],
    ],
    'explicit controller binding defers visibility diagnostic' => [
        <<<'PHP'
            <?php
            throw new \RuntimeException('Binding catalog must never execute.');
            \app()->bind(
                \App\Http\Controllers\ExistingController::class,
                \App\Http\Controllers\ExistingController::class,
            );
            PHP,
        '$router->get("/bound-visibility", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\ExistingController@privateAction");'
            .'$router->get("/bound-array-visibility", '
            .'[\\App\\Http\\Controllers\\ExistingController::class, "privateAction"]);',
        [],
        [],
    ],
    'controller binding cannot satisfy native invokable registration' => [
        <<<'PHP'
            <?php
            throw new \RuntimeException('Binding catalog must never execute.');
            \app()->bind(
                \App\Http\Controllers\MethodlessController::class,
                \App\Http\Controllers\InvokableController::class,
            );
            PHP,
        '$router->get("/bound-invokable", "\\\\App\\\\Http\\\\Controllers\\\\MethodlessController");',
        $methodWarning,
        ['"\\\\App\\\\Http\\\\Controllers\\\\MethodlessController"'],
    ],
    'dispatcher binding cannot satisfy native invokable registration' => [
        <<<'PHP'
                <?php
                throw new \RuntimeException('Binding catalog must never execute.');
                \app()->bind(
                    \Illuminate\Routing\Contracts\ControllerDispatcher::class,
                    \App\Http\Controllers\CustomControllerDispatcher::class,
                );
            PHP,
        '$router->get("/dispatcher-invokable", '
            .'"\\\\App\\\\Http\\\\Controllers\\\\DispatchMethodlessInvokableController");',
        $methodWarning,
        ['"\\\\App\\\\Http\\\\Controllers\\\\DispatchMethodlessInvokableController"'],
    ],
    'controller binding cannot satisfy missing invokable class registration' => [
        <<<'PHP'
            <?php
            throw new \RuntimeException('Binding catalog must never execute.');
            \app()->bind(
                \Missing\BoundInvokableController::class,
                \App\Http\Controllers\InvokableController::class,
            );
            PHP,
        '$router->get("/bound-missing-invokable", "\\\\Missing\\\\BoundInvokableController");',
        $classWarning,
        ['"\\\\Missing\\\\BoundInvokableController"'],
    ],
];
foreach ($catalogCases as $name => [$bindings, $body, $expectedCodes, $expectedSpans]) {
    file_put_contents($workspace.'/bootstrap/bindings.php', $bindings);
    $catalogSource = <<<'PHP'
        <?php
        use Illuminate\Routing\Router as NativeRouter;
        function catalogScenario(NativeRouter $router): void {
        PHP.$body.'}';
    file_put_contents($workspace.'/catalog-cases.php', $catalogSource);
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['binding-files' => ['bootstrap/bindings.php']]],
    ], JSON_THROW_ON_ERROR));
    $configuration['source']['paths'] = ['catalog-cases.php', 'app'];
    file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $analyze('catalog.json', 'catalog.log');
    $report = json_decode(file_get_contents($workspace.'/catalog.json'), true, flags: JSON_THROW_ON_ERROR);
    $issues = $report['issues'] ?? [];
    $codes = array_column($issues, 'code');
    $spans = [];
    foreach ($issues as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
        ))[0];
        $spans[] = substr(
            $catalogSource,
            $primary['span']['start']['offset'],
            $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
        );
    }
    if ($codes !== $expectedCodes || $spans !== $expectedSpans) {
        throw new RuntimeException(
            $name
            .': expected '
            .json_encode([$expectedCodes, $expectedSpans])
            .', got '
            .json_encode([$codes, $spans])
            .'; inspect '
            .$workspace,
        );
    }
    echo 'PASS: '.$name."\n";
}

file_put_contents($workspace.'/composer.json', '{}');
$configuration['source']['paths'] = ['native-class-actions.php', 'app'];
$nativeClassSource = <<<'PHP'
    <?php
    use Illuminate\Routing\Router;
    function nativeClassActions(Router $router): void {
        $router->get('/missing-array-class', [\Missing\ArrayController::class, 'index']);
        $router->get('/missing-invokable-class', \Missing\InvokableController::class);
    }
    PHP;
file_put_contents($workspace.'/native-class-actions.php', $nativeClassSource);
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/native-array-class.json', 'w'],
        2 => ['file', $workspace.'/native-array-class.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$stderr = file_get_contents($workspace.'/native-array-class.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)) {
    throw new RuntimeException('Expected the native missing class diagnostic; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/native-array-class.json'), true, flags: JSON_THROW_ON_ERROR);
$codes = array_column($report['issues'] ?? [], 'code');
$nativeSpans = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $nativeSpans[] = substr(
        $nativeClassSource,
        $primary['span']['start']['offset'],
        $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
    );
}
if (
    $codes !== ['non-existent-class-like', 'non-existent-class-like']
    || $nativeSpans !== ['\\Missing\\ArrayController', '\\Missing\\InvokableController']
) {
    throw new RuntimeException(
        'Native class-action priority: expected precise native missing-class diagnostics, got '
        .json_encode($codes)
        .' at '
        .json_encode($nativeSpans)
        .'; see '
        .$workspace,
    );
}
echo "PASS: native missing class diagnostics retain precise priority for class actions\n";

$visibilitySource = <<<'PHP'
    <?php
    use Illuminate\Routing\Router;
    class NativeProtectedInvokableController {
        protected function __invoke(): void {}
    }
    function nativeInvokableVisibility(Router $router): void {
        $router->get('/protected-invokable', NativeProtectedInvokableController::class);
    }
    PHP;
file_put_contents($workspace.'/native-invokable-visibility.php', $visibilitySource);
$configuration['source']['paths'] = ['native-invokable-visibility.php', 'app'];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/native-invokable-visibility.json', 'w'],
        2 => ['file', $workspace.'/native-invokable-visibility.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$stderr = file_get_contents($workspace.'/native-invokable-visibility.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)) {
    throw new RuntimeException('Expected native invokable visibility diagnostic; inspect '.$workspace);
}
$report = json_decode(
    file_get_contents($workspace.'/native-invokable-visibility.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$issues = $report['issues'] ?? [];
$primary =
    array_values(array_filter(
        $issues[0]['annotations'] ?? [],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0] ?? null;
$span = $primary === null
    ? null
    : substr(
        $visibilitySource,
        $primary['span']['start']['offset'],
        $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
    );
if (array_column($issues, 'code') !== ['semantics'] || $span !== 'protected') {
    throw new RuntimeException(
        'Invokable visibility priority: expected only the precise native semantics diagnostic; see '.$workspace,
    );
}
echo "PASS: native invokable visibility diagnostic retains precise priority\n";

$configuration['source']['paths'] = ['cases.php', 'app'];
unset($configuration['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$analyze('disabled.json', 'disabled.log');
$report = json_decode(file_get_contents($workspace.'/disabled.json'), true, flags: JSON_THROW_ON_ERROR);
if (($report['issues'] ?? []) !== []) {
    throw new RuntimeException('Disabled analyzer must leave literal route actions native; inspect '.$workspace);
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
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);
