<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago volt routes '.bin2hex(random_bytes(8));
$volt = $workspace.'/vendor/livewire/volt/src';
$facades = $workspace.'/vendor/laravel/framework/src/Illuminate/Support/Facades';
mkdir($volt, 0777, true);
mkdir($facades, 0777, true);
copy(__DIR__.'/fixtures/analysis/route-facade-base.php.stub', $facades.'/Facade.php');
// Route declaration/body from livewire/volt 18ae5b1 (MIT, fixtures/analysis/volt-LICENSE.md); surrounding types are minimal.
file_put_contents($volt.'/Volt.php', <<<'PHP'
    <?php
    namespace Livewire\Volt;
    use Illuminate\Support\Facades\Facade;
    /**
     * @method static \Illuminate\Routing\Route route(string $uri, string $componentName)
     * @method static void mount(array|string $paths = [], array|string $uses = [])
     */
    class Volt extends Facade {
        public static function getFacadeAccessor(): string { return VoltManager::class; }
    }
    PHP);
file_put_contents($volt.'/VoltManager.php', <<<'PHP'
    <?php
    namespace Livewire\Volt;
    use Illuminate\Contracts\Routing\Registrar;
    use Illuminate\Routing\Route;
    class VoltManager {
        public function __construct(protected Registrar $router) {}
        public function route(string $uri, string $componentName): Route {
            return $this->router->get($uri, function () use ($componentName) {
                $container = \Illuminate\Container\Container::getInstance();
                return $container->call([
                    $container->make(LivewireManager::class)->new($componentName),
                    '__invoke',
                ]);
            });
        }
    }
    PHP);

$cases = [
    'known' => ['Volt::route("/known", "pages.home");', false],
    'missing' => ['Volt::route("/missing", "pages.hmoe");', true],
    'named' => ['Volt::route(componentName: "pages.hmoe", uri: "/named");', true],
    'wrong case' => ['Volt::route("/case", "Pages.Home");', true],
    'class-capable name' => ['Volt::route("/class", "Home");', false],
    'dynamic' => ['Volt::route("/dynamic", $name);', false],
    'wrong method' => ['Volt::mount("pages.hmoe");', false],
    'other class' => ['OtherVolt::route("/other", "pages.hmoe");', false],
];
$source = "<?php\nuse Livewire\\Volt\\Volt;\n";
$lines = [];
foreach ($cases as $label => [$body, $missing]) {
    $source .= 'function case'.count($lines).'(string $name): void { '.$body." }\n";
    $lines[substr_count($source, "\n") - 1] = [$label, $missing];
}
$source .= 'class OtherVolt { public static function route(string $uri, string $componentName): void {} }'."\n";
file_put_contents($workspace.'/cases.php', $source);

$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'vendor/livewire/volt/src/Volt.php',
            'vendor/livewire/volt/src/VoltManager.php',
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
            'workers' => 1,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$run = static function (string $label, array $catalog, bool $expect, bool $binding = false) use (
    $workspace,
    $command,
    $lines,
): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'reference-catalogs' => ['volt-route-components' => $catalog],
                ...($binding ? ['binding-files' => ['app/bindings.php']] : []),
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $output = $workspace.'/'.$label.'.json';
    $log = $workspace.'/'.$label.'.log';
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $log, 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $report = json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        if (($issue['code'] ?? null) !== 'ichinya/laramago/laramago-missing-volt-route-component') {
            continue;
        }
        $actual[] = $issue['annotations'][0]['span']['start']['line'] ?? null;
    }
    if (
        $exit !== 0
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($log),
        )
    ) {
        throw new RuntimeException($label.': analyzer failure; inspect '.$workspace);
    }
    $wanted = $expect ? array_keys(array_filter($lines, static fn (array $case): bool => $case[1])) : [];
    sort($actual);
    sort($wanted);
    if ($actual !== $wanted) {
        throw new RuntimeException(
            $label.': expected '.json_encode($wanted).', got '.json_encode($actual).'; inspect '.$workspace,
        );
    }
    echo 'PASS: '.$label."\n";
};
$run('complete', ['complete' => true, 'names' => ['pages.home']], true);
$run('incomplete', ['complete' => false, 'names' => ['pages.home']], false);
$run('invalid', ['complete' => true, 'names' => ['pages.home', 'pages.home']], false);
$managerSource = file_get_contents($volt.'/VoltManager.php');
file_put_contents($volt.'/VoltManager.php', str_replace('->new($componentName)', '->new("fixed")', $managerSource));
$run('changed-forwarding', ['complete' => true, 'names' => ['pages.home']], false);
file_put_contents($volt.'/VoltManager.php', str_replace(
    'return $this->router->get($uri, function () use ($componentName) {',
    'if (false) { $container->make(LivewireManager::class)->new($componentName); } return $this->router->get($uri, function () use ($componentName) {',
    str_replace('->new($componentName)', '->new("fixed")', $managerSource),
));
$run('dead-forwarding', ['complete' => true, 'names' => ['pages.home']], false);
file_put_contents($volt.'/VoltManager.php', $managerSource);
$facadeSource = file_get_contents($volt.'/Volt.php');
file_put_contents($volt.'/Volt.php', str_replace(
    'public static function getFacadeAccessor()',
    'public static function route(string $uri, string $componentName): void {} public static function getFacadeAccessor()',
    $facadeSource,
));
$run('custom-facade-route', ['complete' => true, 'names' => ['pages.home']], false);
file_put_contents($volt.'/Volt.php', $facadeSource);
mkdir($workspace.'/app');
file_put_contents(
    $workspace.'/app/bindings.php',
    '<?php \\app()->bind(\\Livewire\\Volt\\VoltManager::class, \\App\\CustomManager::class);',
);
$run('manager-binding', ['complete' => true, 'names' => ['pages.home']], false, true);

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
