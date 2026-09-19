<?php

declare(strict_types=1);

// Source-only framework excerpts follow the license in fixtures/analysis/named-route-LICENSE.md.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$mode = $argv[1] ?? '';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago response view '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
foreach (['Routing', 'Support/Facades', 'Foundation', 'Contracts/Routing', 'Contracts/View'] as $directory) {
    mkdir($framework.'/'.$directory, 0777, true);
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
    /** @method static \Illuminate\Http\Response view(string|array $view, array $data = [], int $status = 200, array $headers = []) */
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

foreach (['View', 'Support/Traits', 'Container'] as $directory) {
    mkdir($framework.'/'.$directory, 0777, true);
}
copy(__DIR__.'/fixtures/analysis/response-view-ResponseFactory.php.stub', $framework.'/Routing/ResponseFactory.php');
copy(__DIR__.'/fixtures/analysis/response-view-helpers.php.stub', $framework.'/Foundation/helpers.php');
// The native View factory fixture and license are shared with view factory references.
copy(__DIR__.'/fixtures/analysis/view-reference-Factory.php.stub', $framework.'/View/Factory.php');
copy(__DIR__.'/fixtures/analysis/view-reference-ViewName.php.stub', $framework.'/View/ViewName.php');
copy(
    __DIR__.'/fixtures/analysis/view-reference-ViewFinderInterface.php.stub',
    $framework.'/View/ViewFinderInterface.php',
);
file_put_contents(
    $framework.'/Contracts/View/Factory.php',
    '<?php namespace Illuminate\Contracts\View; interface Factory {}',
);
file_put_contents($framework.'/Contracts/Routing/ResponseFactory.php', <<<'PHP'
    <?php namespace Illuminate\Contracts\Routing;
    interface ResponseFactory {
        public function view($view, $data = [], $status = 200, array $headers = []);
    }
    PHP);
file_put_contents($framework.'/Routing/Redirector.php', '<?php namespace Illuminate\Routing; class Redirector {}');
file_put_contents($workspace.'/support.php', <<<'PHP'
    <?php
    namespace Illuminate\Http { class Response {} }
    namespace Illuminate\Support\Traits { trait Macroable {} }
    namespace Illuminate\Container { class Container { public static function getInstance(): self { return new self; } public function make($abstract, $parameters = []): mixed { throw new \RuntimeException; } } }
    namespace App {
    class CustomResponse extends \Illuminate\Support\Facades\Response {}
    class CustomFactory extends \Illuminate\Routing\ResponseFactory {}
    class Response { public static function view(string $view): string { return $view; } }
    function response(): \Illuminate\Contracts\Routing\ResponseFactory { throw new \RuntimeException('Never execute'); }
    }
    PHP);
$binding = match ($mode) {
    '--response-binding' => 'Illuminate\\Contracts\\Routing\\ResponseFactory',
    '--view-binding' => 'view',
    '--finder-binding' => 'view.finder',
    default => null,
};
if ($binding !== null) {
    mkdir($workspace.'/bootstrap');
    file_put_contents(
        $workspace.'/bootstrap/bindings.php',
        '<?php \\app()->bind('.var_export($binding, true).', \\stdClass::class);',
    );
}
$changes = [
    '--changed-forwarding' => [
        'Routing/ResponseFactory.php',
        '$this->view->make($view, $data)',
        '$this->view->make("home", $data)',
    ],
    '--changed-constructor' => ['Routing/ResponseFactory.php', '$this->view = $view;', '$this->view = null;'],
    '--changed-make' => [
        'Routing/ResponseFactory.php',
        'return new Response($content, $status, $headers);',
        'return new Response("changed", $status, $headers);',
    ],
    '--class-doc' => [
        'Routing/ResponseFactory.php',
        'class ResponseFactory implements FactoryContract',
        '/** @method \\Illuminate\\Http\\Response view($view, $data = [], $status = 200, array $headers = []) */'
            ."\n"
            .'class ResponseFactory implements FactoryContract',
    ],
    '--changed-doc' => ['Routing/ResponseFactory.php', '@param  string|array  $view', '@param  mixed  $view'],
    '--changed-helper' => ['Foundation/helpers.php', 'func_num_args() === 0', 'func_num_args() === 1'],
];
if (isset($changes[$mode])) {
    [$file, $search, $replace] = $changes[$mode];
    file_put_contents($framework.'/'.$file, str_replace($search, $replace, file_get_contents($framework.'/'.$file)));
}
mkdir($workspace.'/resources/views', 0777, true);
file_put_contents($workspace.'/resources/views/home.blade.php', 'Hello');
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'reference-catalogs' => [
                'views' => $mode === '--no-catalog'
                    ? null
                    : [
                        'complete' => $mode !== '--incomplete-catalog',
                        'paths' => ['resources/views'],
                        'namespaces' => ['billing' => ['resources/views']],
                    ],
            ],
            'binding-files' => $binding === null ? [] : ['bootstrap/bindings.php'],
        ],
    ],
], JSON_THROW_ON_ERROR));
$warn = ['ichinya/laramago/laramago-missing-view'];
$active = $binding === null
&& ! in_array(
    $mode,
    [
        '--changed-forwarding',
        '--changed-constructor',
        '--class-doc',
        '--changed-doc',
        '--changed-make',
        '--no-catalog',
        '--incomplete-catalog',
    ],
    true,
);
$facadeActive = $active && $mode !== '--custom-accessor';
$helperActive = $active && $mode !== '--changed-helper';
$cases = [
    'namespace facade present' => ['NativeResponse::view("billing::home");', []],
    'namespace facade missing' => ['NativeResponse::view("billing::typo");', $facadeActive ? $warn : []],
    'namespace helper missing' => ['\\response()->view("billing::typo");', $helperActive ? $warn : []],
    'namespace unknown' => ['NativeResponse::view("unknown::typo");', []],
    'native facade literal' => ['NativeResponse::view("typo");', $facadeActive ? $warn : []],
    'native facade known' => ['NativeResponse::view("home");', []],
    'native facade named' => ['NativeResponse::view(status: 201, view: "typo");', $facadeActive ? $warn : []],
    'native concrete factory literal' => ['$factory->view("typo");', $active ? $warn : []],
    'arbitrary contract deferred' => ['$contract->view("typo");', []],
    'response helper zero argument' => ['\\response()->view("typo");', $helperActive ? $warn : []],
    'response helper imported alias' => ['makeResponse()->view(view: "typo");', $helperActive ? $warn : []],
    'shadow helper deferred' => ['response()->view("typo");', []],
    'nonempty response helper defers to native type' => ['\\response("body")->view("typo");', ['non-existent-method']],
    'array candidate selection deferred' => ['NativeResponse::view(["typo", "home"]);', []],
    'empty candidate list deferred' => ['NativeResponse::view([]);', []],
    'dynamic name deferred' => ['NativeResponse::view($name);', []],
    'unpacked arguments deferred' => ['NativeResponse::view(...["typo"]);', []],
    'first class callable deferred' => ['$callback = NativeResponse::view(...);', []],
    'facade subclass deferred' => ['CustomResponse::view("typo");', []],
    'factory subclass deferred' => ['$custom->view("typo");', []],
    'unrelated class deferred' => ['Response::view("typo");', []],
    'native invalid argument retained' => ['NativeResponse::view(3);', ['invalid-argument']],
    'native unknown method retained' => ['NativeResponse::nonexistent();', ['non-documented-method']],
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
        if (! in_array($literal, ['"typo"', '"billing::typo"'], true)) {
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
