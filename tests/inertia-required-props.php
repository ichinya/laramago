<?php

declare(strict_types=1);

// Real Mago worker checks; fixture PHP is parsed but never executed.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago inertia required '.bin2hex(random_bytes(8));
foreach ([
    'resources/js/Pages/Admin',
    'vendor/laravel/framework/src/Illuminate/Support/Facades',
    'vendor/inertiajs/inertia-laravel/src',
] as $directory) {
    mkdir($workspace.'/'.$directory, 0777, true);
}
file_put_contents($workspace.'/resources/js/Pages/Admin/Users.vue', '<script>unknown()</script>');
copy(
    __DIR__.'/fixtures/analysis/route-facade-base.php.stub',
    $workspace.'/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
);
copy(
    __DIR__.'/fixtures/analysis/inertia-facade.php.stub',
    $workspace.'/vendor/inertiajs/inertia-laravel/src/Inertia.php',
);
$factorySource = getenv('MAGO_INERTIA_FACTORY_SOURCE') ?: __DIR__.'/fixtures/analysis/inertia-props-factory.php.stub';
copy($factorySource, $workspace.'/vendor/inertiajs/inertia-laravel/src/ResponseFactory.php');
$nativeSource = getenv('MAGO_INERTIA_FACTORY_SOURCE') !== false;
if ($nativeSource) {
    file_put_contents($workspace.'/native-test-stubs.php', <<<'PHP'
        <?php
        namespace Inertia;
        class Response {
            public function with(string $key, mixed $value): self { return $this; }
        }
        class CustomResponseFactory extends ResponseFactory {
            public function render($component, $props = []): Response {
                return parent::render($component, $props);
            }
        }
        PHP);
}

$catalog = [
    'complete' => true,
    'paths' => ['resources/js/Pages'],
    'extensions' => ['vue'],
    'required-props' => ['Admin/Users' => ['title', 'user']],
    'shared-props' => ['complete' => true, 'names' => ['user']],
    'render-props-complete' => true,
];
$writeCatalog = static function (array $value) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['reference-catalogs' => ['inertia-pages' => $value]]],
    ], JSON_THROW_ON_ERROR));
};
$writeCatalog($catalog);
$missing = ['ichinya/laramago/laramago-missing-inertia-prop'];
$cases = [
    'complete literal props' => ['Inertia::render("Admin/Users", ["title" => "List"]);', []],
    'missing required prop' => ['Inertia::render("Admin/Users", []);', $missing],
    'named literal props' => ['Inertia::render(component: "Admin/Users", props: ["title" => "List"]);', []],
    'named missing props' => ['Inertia::render(component: "Admin/Users", props: []);', $missing],
    'reordered named props' => ['Inertia::render(props: ["title" => "List"], component: "Admin/Users");', []],
    'factory missing props' => ['$factory->render("Admin/Users");', $missing],
    'dynamic props unknown' => ['Inertia::render("Admin/Users", $props);', []],
    'unpacked props unknown' => ['Inertia::render("Admin/Users", [...$props]);', []],
    'computed key unknown' => ['Inertia::render("Admin/Users", [$key => "value"]);', []],
    'dotted key unknown' => ['Inertia::render("Admin/Users", ["user.name" => "A"]);', []],
    'provider prop unknown' => ['Inertia::render("Admin/Users", [new \stdClass]);', []],
    'chained response augmentation' => ['Inertia::render("Admin/Users", [])->with("title", "List");', []],
    'dynamic page unknown' => ['Inertia::render($page, []);', []],
    'custom factory deferred' => ['$custom->render("Admin/Users", []);', []],
];
$source = <<<'PHP'
    <?php
    use Inertia\Inertia;
    use Inertia\ResponseFactory;
    use Inertia\CustomResponseFactory;
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $codes]) {
    $source .=
        'function scenario'
        .count($lines)
        .'(ResponseFactory $factory, CustomResponseFactory $custom, string $page, array $props, string $key): void { '
        .$body
        .' }'
        ."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'vendor/inertiajs/inertia-laravel/src/Inertia.php',
            'vendor/inertiajs/inertia-laravel/src/ResponseFactory.php',
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
if ($nativeSource) {
    $config['source']['includes'][] = 'native-test-stubs.php';
}
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$check = static function (array $expected) use ($workspace, $command, $lines): void {
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
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    if (
        $exit !== 0
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($workspace.'/stderr.log'),
        )
    ) {
        throw new RuntimeException('Worker failed; inspect '.$workspace);
    }
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $base]) {
        $want = $expected[$name] ?? $base;
        $got = $actual[$line] ?? [];
        sort($want);
        sort($got);
        if ($want !== $got) {
            throw new RuntimeException(
                $name.': expected '.json_encode($want).', got '.json_encode($got).'; inspect '.$workspace,
            );
        }
        unset($actual[$line]);
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics; inspect '.$workspace);
    }
};
$check([]);
echo "PASS: asserted required props on native literal renders\n";

$writeCatalog([...$catalog, 'shared-props' => ['complete' => true, 'names' => []]]);
$unshared = array_fill_keys(array_keys($cases), []);
foreach (['complete literal props', 'named literal props', 'reordered named props'] as $name) {
    $unshared[$name] = $missing;
}
foreach (['missing required prop', 'named missing props', 'factory missing props'] as $name) {
    $unshared[$name] = [...$missing, ...$missing];
}
$check($unshared);
echo "PASS: required names absent from asserted shared props warn\n";

foreach ([
    'shared catalog incomplete' => ['shared-props' => ['complete' => false, 'names' => ['user']]],
    'render props incomplete' => ['render-props-complete' => false],
    'required contract absent' => ['required-props' => null],
    'malformed shared names' => ['shared-props' => ['complete' => true, 'names' => ['user', 123]]],
] as $label => $override) {
    $writeCatalog([...$catalog, ...$override]);
    $check(array_fill_keys(array_keys($cases), []));
    echo 'PASS: '.$label."\n";
}

$writeCatalog($catalog);
$factoryFile = $workspace.'/vendor/inertiajs/inertia-laravel/src/ResponseFactory.php';
$contents = file_get_contents($factoryFile);
file_put_contents($factoryFile, str_replace('$this->sharedProps,', '[],', $contents));
$check(array_fill_keys(array_keys($cases), []));
echo "PASS: altered native factory merge defers\n";

$resolved = realpath($workspace);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($iterator as $file) {
    $path = realpath($file->getPathname());
    if ($resolved === false || $path === false || ! str_starts_with($path, $resolved.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refusing cleanup outside test workspace.');
    }
    $file->isDir() ? rmdir($path) : unlink($path);
}
rmdir($workspace);
