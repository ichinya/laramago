<?php

declare(strict_types=1);

// Source-only framework excerpts follow the license in fixtures/analysis/named-route-LICENSE.md.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$mode = $argv[1] ?? '';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago response route '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
foreach (['Routing', 'Support/Facades', 'Foundation', 'Contracts/Routing', 'Contracts/View'] as $directory) {
    mkdir($framework.'/'.$directory, 0777, true);
}
file_put_contents(
    $framework.'/Contracts/View/Factory.php',
    '<?php namespace Illuminate\Contracts\View; interface Factory {}',
);
file_put_contents($framework.'/Contracts/Routing/ResponseFactory.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Routing;
    interface ResponseFactory {
        /** @return \Illuminate\Http\RedirectResponse */
        public function redirectToRoute($route, $parameters = [], $status = 302, $headers = []);
    }
    PHP);
file_put_contents($framework.'/Routing/ResponseFactory.php', <<<'PHP'
    <?php
    namespace Illuminate\Routing;
    use Illuminate\Contracts\Routing\ResponseFactory as FactoryContract;
    use Illuminate\Contracts\View\Factory as ViewFactory;
    class ResponseFactory implements FactoryContract {
        protected $view;
        /** @var Redirector */
        protected $redirector;
        public function __construct(ViewFactory $view, Redirector $redirector) {
            $this->view = $view;
            $this->redirector = $redirector;
        }
        /** @param \BackedEnum|string $route @return \Illuminate\Http\RedirectResponse */
        public function redirectToRoute($route, $parameters = [], $status = 302, $headers = []) {
            return $this->redirector->route($route, $parameters, $status, $headers);
        }
    }
    PHP);
file_put_contents($framework.'/Routing/Redirector.php', <<<'PHP'
    <?php
    namespace Illuminate\Routing;
    class Redirector {
        protected UrlGenerator $generator;
        public function __construct(UrlGenerator $generator) { $this->generator = $generator; }
        /** @param \BackedEnum|string $route @return \Illuminate\Http\RedirectResponse */
        public function route($route, $parameters = [], $status = 302, $headers = []) {
            return $this->to($this->generator->route($route, $parameters), $status, $headers);
        }
        /** @return \Illuminate\Http\RedirectResponse */
        public function to($path, $status = 302, $headers = [], $secure = null) { return new \Illuminate\Http\RedirectResponse; }
    }
    PHP);
copy(__DIR__.'/fixtures/analysis/signed-route-UrlGenerator.php.stub', $framework.'/Routing/UrlGenerator.php');
if ($mode === '--changed-url-lookup') {
    $file = $framework.'/Routing/UrlGenerator.php';
    file_put_contents($file, str_replace('getByName($name)', "getByName('home')", file_get_contents($file)));
}
if ($mode === '--changed-redirect-forwarding') {
    $file = $framework.'/Routing/Redirector.php';
    file_put_contents($file, str_replace(
        'route($route, $parameters)',
        "route('home', \$parameters)",
        file_get_contents($file),
    ));
}
file_put_contents($framework.'/Support/Facades/Facade.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    abstract class Facade {
        protected static $app;
        protected static $resolvedInstance;
        protected static $cached = true;
        public static function getFacadeRoot() { return static::resolveFacadeInstance(static::getFacadeAccessor()); }
        protected static function getFacadeAccessor() { throw new \RuntimeException('No accessor'); }
        protected static function resolveFacadeInstance($name) {
            if (isset(static::$resolvedInstance[$name])) { return static::$resolvedInstance[$name]; }
            if (static::$app) {
                if (static::$cached) { return static::$resolvedInstance[$name] = static::$app[$name]; }
                return static::$app[$name];
            }
        }
        public static function __callStatic($method, $args) {
            $instance = static::getFacadeRoot();
            if (! $instance) { throw new \RuntimeException('No root'); }
            return $instance->$method(...$args);
        }
    }
    PHP);
file_put_contents($framework.'/Support/Facades/Response.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
    /** @method static \Illuminate\Http\RedirectResponse redirectToRoute(\BackedEnum|string $route, mixed $parameters = [], int $status = 302, array $headers = []) */
    class Response extends Facade {
        protected static function getFacadeAccessor() { return ResponseFactoryContract::class; }
    }
    PHP);
if ($mode === '--custom-accessor') {
    $file = $framework.'/Support/Facades/Response.php';
    file_put_contents($file, str_replace(
        'return ResponseFactoryContract::class;',
        "return 'custom';",
        file_get_contents($file),
    ));
}
file_put_contents($framework.'/Foundation/helpers.php', <<<'PHP'
    <?php
    use Illuminate\Contracts\Routing\ResponseFactory;
    use Illuminate\Http\Response as IlluminateResponse;
    /** @return ($content is null ? ResponseFactory : IlluminateResponse) */
    function response($content = null, $status = 200, array $headers = []): ResponseFactory|IlluminateResponse {
        $factory = app(ResponseFactory::class);
        if (func_num_args() === 0) { return $factory; }
        return $factory->make($content ?? '', $status, $headers);
    }
    /** @return ResponseFactory */
    function app($abstract = null, array $parameters = []) { return new \Illuminate\Routing\ResponseFactory(null, new \Illuminate\Routing\Redirector(new \Illuminate\Routing\UrlGenerator)); }
    PHP);
if ($mode === '--changed-helper') {
    $file = $framework.'/Foundation/helpers.php';
    file_put_contents($file, str_replace('func_num_args() === 0', 'func_num_args() === 1', file_get_contents($file)));
}
file_put_contents($workspace.'/support.php', <<<'PHP'
    <?php
    namespace Illuminate\Http { class RedirectResponse {} class Response {} }
    namespace App {
        class CustomResponse extends \Illuminate\Support\Facades\Response {}
        class CustomFactory extends \Illuminate\Routing\ResponseFactory {}
        class Response { public static function redirectToRoute(string $route): string { return $route; } }
        function response(): \Illuminate\Contracts\Routing\ResponseFactory { throw new \RuntimeException('Never execute fixture'); }
    }
    PHP);
if ($mode === '--changed-forwarding') {
    $file = $framework.'/Routing/ResponseFactory.php';
    file_put_contents($file, str_replace(
        'route($route, $parameters, $status, $headers)',
        'route("home", $parameters, $status, $headers)',
        file_get_contents($file),
    ));
}
$binding = match ($mode) {
    '--response-binding' => 'Illuminate\\Contracts\\Routing\\ResponseFactory',
    '--redirect-binding' => 'redirect',
    '--url-binding' => 'url',
    default => null,
};
if ($binding !== null) {
    mkdir($workspace.'/bootstrap');
    file_put_contents(
        $workspace.'/bootstrap/bindings.php',
        '<?php \\app()->bind('.var_export($binding, true).', \\stdClass::class);',
    );
}
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'named-routes' => match ($mode) {
                '--no-catalog' => null,
                '--incomplete-catalog' => ['complete' => false, 'missing-route-resolver' => false, 'names' => ['home']],
                default => ['complete' => true, 'missing-route-resolver' => false, 'names' => ['home']],
            },
            'binding-files' => $binding === null ? [] : ['bootstrap/bindings.php'],
        ],
    ],
], JSON_THROW_ON_ERROR));
$warn = ['ichinya/laramago/laramago-missing-named-route'];
$active = $binding === null
&& ! in_array(
    $mode,
    [
        '--changed-forwarding',
        '--changed-redirect-forwarding',
        '--changed-url-lookup',
        '--no-catalog',
        '--incomplete-catalog',
    ],
    true,
);
$facadeActive = $active && $mode !== '--custom-accessor';
$helperActive = $active && $mode !== '--changed-helper';
$cases = [
    'native facade literal' => ['NativeResponse::redirectToRoute("typo");', $facadeActive ? $warn : []],
    'native facade known' => ['NativeResponse::redirectToRoute("home");', []],
    'native facade named' => [
        'NativeResponse::redirectToRoute(status: 301, route: "typo");',
        $facadeActive ? $warn : [],
    ],
    'native factory literal' => ['$factory->redirectToRoute("typo");', $active ? $warn : []],
    'native factory named' => ['$factory->redirectToRoute(headers: [], route: "typo");', $active ? $warn : []],
    'arbitrary contract deferred' => ['$contract->redirectToRoute("typo");', []],
    'response helper zero argument' => ['\\response()->redirectToRoute("typo");', $helperActive ? $warn : []],
    'imported response helper alias' => ['makeResponse()->redirectToRoute("typo");', $helperActive ? $warn : []],
    'custom namespaced response helper' => ['\\App\\response()->redirectToRoute("typo");', []],
    'response helper nonempty arguments' => ['\\response("body")->redirectToRoute("typo");', ['non-existent-method']],
    'dynamic route' => ['NativeResponse::redirectToRoute($name);', []],
    'concatenated route' => ['NativeResponse::redirectToRoute("prefix.".$name);', []],
    'unpacked route' => ['NativeResponse::redirectToRoute(...["typo"]);', []],
    'first class callable' => ['$callback = NativeResponse::redirectToRoute(...);', []],
    'facade subclass' => ['CustomResponse::redirectToRoute("typo");', []],
    'factory subclass' => ['$custom->redirectToRoute("typo");', []],
    'unrelated short class' => ['Response::redirectToRoute("typo");', []],
    'facade invalid argument retained' => ['NativeResponse::redirectToRoute(3);', ['invalid-argument']],
    'facade unknown method retained' => ['NativeResponse::nonexistent();', ['non-documented-method']],
];
$source = "<?php\nnamespace App;\nuse Illuminate\\Support\\Facades\\Response as NativeResponse;\nuse function response as makeResponse;\n";
$lines = [];
foreach ($cases as $label => [$body, $codes]) {
    $source .=
        'function scenario'
        .count($lines)
        .'(string $name, \\Illuminate\\Routing\\ResponseFactory $factory, \\Illuminate\\Contracts\\Routing\\ResponseFactory $contract, CustomFactory $custom): void { '
        .$body
        ." }\n";
    $lines[substr_count($source, "\n")] = [$label, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['vendor', 'support.php']],
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
    throw new RuntimeException('Cannot start Mago');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $actual[$line][] = $issue['code'];
    if ($issue['code'] === $warn[0]) {
        $literal = substr(
            $source,
            $primary['span']['start']['offset'],
            $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
        );
        if ($literal !== '"typo"') {
            throw new RuntimeException('Expected exact literal span, got '.$literal);
        }
    }
}
foreach ($lines as $line => [$label, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException(
            $label.': expected '.json_encode($expected).' got '.json_encode($codes).'; see '.$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$label."\n";
}
if (
    $actual !== []
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/stderr.log'),
    )
) {
    throw new RuntimeException('Unexpected issues/worker failure; inspect '.$workspace);
}
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
        throw new RuntimeException('Refusing cleanup outside the test workspace');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);
