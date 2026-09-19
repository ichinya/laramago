<?php

declare(strict_types=1);

// Exercise installed Inertia helper and route forwarding through the real Mago worker.
// Native excerpts derive from inertiajs/inertia-laravel (MIT); see fixtures/analysis/inertia-LICENSE.md.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago inertia entries '.bin2hex(random_bytes(8));
$inertia = $workspace.'/vendor/inertiajs/inertia-laravel';
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
foreach ([
    $inertia.'/src',
    $framework.'/Routing',
    $framework.'/Support/Facades',
    $workspace.'/resources/js/Pages/Admin',
] as $directory) {
    mkdir($directory, 0777, true);
}
file_put_contents($workspace.'/resources/js/Pages/Admin/Users.vue', '<script>unknown()</script>');
copy(__DIR__.'/fixtures/analysis/route-facade-base.php.stub', $framework.'/Support/Facades/Facade.php');
copy(__DIR__.'/fixtures/analysis/inertia-facade.php.stub', $inertia.'/src/Inertia.php');
file_put_contents($inertia.'/src/ResponseFactory.php', <<<'PHP'
    <?php
    namespace Inertia;
    use BackedEnum;
    use UnitEnum;
    use Illuminate\Contracts\Support\Arrayable;
    use InvalidArgumentException;
    use Inertia\DevTools\DevTools;
    class Response {}
    class ResponseFactory {
        /**
         * @param BackedEnum|UnitEnum|string $component
         * @param array<array-key, mixed>|Arrayable<array-key, mixed>|ProvidesInertiaProperties $props
         */
        public function render($component, $props = []): Response {
            $component = $this->transformComponent($component);
            $component = match (true) {
                $component instanceof BackedEnum => $component->value,
                $component instanceof UnitEnum => $component->name,
                default => $component,
            };
            if (! is_string($component)) {
                throw new InvalidArgumentException('Component argument must be of type string or a string BackedEnum');
            }
            if (config('inertia.pages.ensure_pages_exist', false)) {
                $this->findComponentOrFail($component);
            }
            if ($props instanceof Arrayable) {
                $props = $props->toArray();
            } elseif ($props instanceof ProvidesInertiaProperties) {
                $props = [$props];
            }
            $response = new Response(
                $component,
                $this->sharedProps,
                $props,
                $this->rootView,
                $this->getVersion(),
                $this->encryptHistory ?? config('inertia.history.encrypt', false),
                $this->urlResolver,
            );
            DevTools::recorder()?->pageRendering($component, $response, $this->sharedProps);
            return $response;
        }
    }
    PHP);
file_put_contents($inertia.'/helpers.php', <<<'PHP'
    <?php
    use Illuminate\Contracts\Support\Arrayable;
    use Inertia\Inertia;
    use Inertia\Response;
    use Inertia\ResponseFactory;

    if (! function_exists('inertia')) {
        /**
         * @param null|string $component
         * @param array|Arrayable $props
         * @return ($component is null ? ResponseFactory : Response)
         */
        function inertia($component = null, $props = [])
        {
            $instance = Inertia::getFacadeRoot();

            if ($component) {
                return $instance->render($component, $props);
            }

            return $instance;
        }
    }
    PHP);
file_put_contents($framework.'/Routing/Router.php', <<<'PHP'
    <?php
    namespace Illuminate\Routing;
    class Router {
        public function match(array $methods, string $uri, string $action): Route { return new Route; }
    }
    class Route {
        public function defaults(string $key, mixed $value): self { return $this; }
    }
    PHP);
file_put_contents($framework.'/Support/Facades/Route.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    class Route extends Facade {
        protected static function getFacadeAccessor() { return 'router'; }
    }
    PHP);
file_put_contents($inertia.'/src/Controller.php', <<<'PHP'
    <?php
    namespace Inertia;
    use Illuminate\Http\Request;
    class Controller {
        public function __invoke(Request $request): Response {
            return Inertia::render(
                $request->route()->defaults['component'],
                $request->route()->defaults['props']
            );
        }
    }
    PHP);
file_put_contents($inertia.'/src/ServiceProvider.php', <<<'PHP'
    <?php
    namespace Inertia;
    use Illuminate\Routing\Router;
    use Inertia\DevTools\DevTools;
    use Inertia\DevTools\SourceLocator;
    class ServiceProvider {
        public function register(): void {
            $this->registerRouterMacro();
        }
        protected function registerRouterMacro(): void {
            Router::macro('inertia', function ($uri, $component, $props = []) {
                $route = $this->match(['GET', 'HEAD'], $uri, '\\'.Controller::class)
                    ->defaults('component', $component)
                    ->defaults('props', $props);
                if (DevTools::enabled()) {
                    $source = app(SourceLocator::class)->captureCallerSource();
                    if ($source !== null) {
                        $route->defaults(DevTools::RENDER_SOURCE_KEY, $source);
                    }
                }
                return $route;
            });
        }
    }
    PHP);
$upstream = getenv('INERTIA_UPSTREAM_DIR');
if ($upstream !== false) {
    foreach ([
        'helpers.php' => $inertia.'/helpers.php',
        'Controller.php' => $inertia.'/src/Controller.php',
        'ServiceProvider.php' => $inertia.'/src/ServiceProvider.php',
        'ResponseFactory.php' => $inertia.'/src/ResponseFactory.php',
        'Route.php' => $framework.'/Support/Facades/Route.php',
        'Router.php' => $framework.'/Routing/Router.php',
    ] as $name => $target) {
        if (! copy($upstream.'/'.$name, $target)) {
            throw new RuntimeException('Cannot load upstream source '.$name);
        }
    }
}
$catalog = [
    'complete' => true,
    'paths' => ['resources/js/Pages'],
    'extensions' => ['vue'],
    'route-macro-active' => true,
];
$composer = static function (?array $configuration) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['reference-catalogs' => ['inertia-pages' => $configuration]]],
    ], JSON_THROW_ON_ERROR));
};
$composer($catalog);
$source = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Route;
    function knownHelper(): void { inertia('Admin/Users'); }
    function missingHelper(): void { inertia('Admin/User'); }
    function namedHelper(): void { inertia(component: 'Admin/User'); }
    function factoryHelper(): void { inertia(); }
    function emptyHelper(): void { inertia(''); }
    function zeroHelper(): void { inertia('0'); }
    function dynamicHelper(string $name): void { inertia($name); }
    function unpackedHelper(): void { inertia(...['Admin/User']); }
    function knownRoute(): void { Route::inertia('/users', 'Admin/Users'); }
    function missingRoute(): void { Route::inertia('/users', 'Admin/User'); }
    function namedRoute(): void { Route::inertia(component: 'Admin/User', uri: '/users'); }
    function dynamicRoute(string $name): void { Route::inertia('/users', $name); }
    function unpackedRoute(): void { Route::inertia(...['/users', 'Admin/User']); }
    function uppercaseMacro(): void { Route::INERTIA('/users', 'Admin/User'); }
    PHP;
file_put_contents($workspace.'/cases.php', $source);
$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Route.php',
            'vendor/laravel/framework/src/Illuminate/Routing/Router.php',
            'vendor/inertiajs/inertia-laravel/helpers.php',
            'vendor/inertiajs/inertia-laravel/src/Inertia.php',
            'vendor/inertiajs/inertia-laravel/src/ResponseFactory.php',
            'vendor/inertiajs/inertia-laravel/src/ServiceProvider.php',
            'vendor/inertiajs/inertia-laravel/src/Controller.php',
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
$analyze = static function () use ($workspace, $configuration): array {
    file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: dirname(__DIR__).'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
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
        throw new RuntimeException('Worker failure; inspect '.$workspace.'; '.$log);
    }

    return json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'] ?? [];
};
$check = static function (array $issues, array $expected) use ($source, $workspace): void {
    $lines = explode("\n", $source);
    $actual = [];
    foreach ($issues as $issue) {
        if ($issue['code'] !== 'ichinya/laramago/laramago-missing-inertia-page') {
            continue; // Native Mago diagnostics retain their own contracts.
        }
        $line = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0]['span']['start']['line'];
        $actual[] = trim($lines[$line]);
    }
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException(
            'Expected '.json_encode($expected).', got '.json_encode($actual).'; inspect '.$workspace,
        );
    }
};
$expected = [
    "function missingHelper(): void { inertia('Admin/User'); }",
    "function namedHelper(): void { inertia(component: 'Admin/User'); }",
    "function missingRoute(): void { Route::inertia('/users', 'Admin/User'); }",
    "function namedRoute(): void { Route::inertia(component: 'Admin/User', uri: '/users'); }",
];
$issues = $analyze();
$check($issues, $expected);
if (count(array_filter($issues, static fn (array $issue): bool => $issue['code'] === 'non-documented-method')) < 4) {
    throw new RuntimeException('Native Route macro method warnings must remain visible; inspect '.$workspace);
}
echo "PASS: native helper and route exact literal references\n";
$composer([...$catalog, 'complete' => false]);
$check($analyze(), []);
echo "PASS: incomplete catalog defers\n";
$composer([...$catalog, 'route-macro-active' => false]);
$check(
    $analyze(),
    array_values(array_filter($expected, static fn (string $case): bool => ! str_contains($case, 'Route::'))),
);
echo "PASS: inactive route macro assertion defers route references\n";
$composer(null);
$check($analyze(), []);
echo "PASS: missing catalog defers\n";
$composer($catalog);
$helperFile = $inertia.'/helpers.php';
$originalHelper = file_get_contents($helperFile);
file_put_contents($helperFile, str_replace(
    '$instance->render($component, $props)',
    '$instance->location($component)',
    $originalHelper,
));
$check(
    $analyze(),
    array_values(array_filter($expected, static fn (string $case): bool => str_contains($case, 'Route::'))),
);
file_put_contents($helperFile, $originalHelper);
echo "PASS: modified helper forwarding defers\n";
$providerFile = $inertia.'/src/ServiceProvider.php';
$originalProvider = file_get_contents($providerFile);
file_put_contents($providerFile, str_replace(
    "->defaults('component', \$component)",
    "->defaults('component', 'Other')",
    $originalProvider,
));
$check(
    $analyze(),
    array_values(array_filter(
        $expected,
        static fn (string $case): bool => str_contains($case, 'inertia(') && ! str_contains($case, 'Route::'),
    )),
);
file_put_contents($providerFile, $originalProvider);
echo "PASS: modified route macro forwarding defers\n";
mkdir($workspace.'/app');
file_put_contents($workspace.'/app/macros.php', <<<'PHP'
    <?php
    \Illuminate\Routing\Router::macro('inertia', function ($uri, $component) { return null; });
    PHP);
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'reference-catalogs' => ['inertia-pages' => $catalog],
            'macro-files' => ['app/macros.php'],
        ],
    ],
], JSON_THROW_ON_ERROR));
$check(
    $analyze(),
    array_values(array_filter($expected, static fn (string $case): bool => ! str_contains($case, 'Route::'))),
);
echo "PASS: application router macro override defers route references\n";
$composer($catalog);
$helperFile = $inertia.'/helpers.php';
$originalHelper = file_get_contents($helperFile);
file_put_contents($helperFile, str_replace(
    '@return ($component is null ? ResponseFactory : Response)',
    '@return ResponseFactory',
    $originalHelper,
));
$check(
    $analyze(),
    array_values(array_filter($expected, static fn (string $case): bool => str_contains($case, 'Route::'))),
);
file_put_contents($helperFile, $originalHelper);
echo "PASS: modified helper PHPDoc contract defers\n";
$factoryFile = $inertia.'/src/ResponseFactory.php';
$originalFactory = file_get_contents($factoryFile);
file_put_contents($factoryFile, str_replace('return $response;', 'return new Response;', $originalFactory));
$check($analyze(), []);
file_put_contents($factoryFile, $originalFactory);
echo "PASS: modified native render contract defers\n";

// All fixture files remain in system TEMP for diagnosis on failure and are removed on success.
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
$resolvedRoot = realpath($workspace);
foreach ($iterator as $entry) {
    $resolved = realpath($entry->getPathname());
    if (
        $resolvedRoot === false
        || $resolved === false
        || ! str_starts_with($resolved, $resolvedRoot.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside temporary workspace.');
    }
    $entry->isDir() ? rmdir($resolved) : unlink($resolved);
}
rmdir($workspace);
