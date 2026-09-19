<?php

declare(strict_types=1);

// The optional package fixtures preserve inertiaui/modal 3.x provider and Modal source.
// Original package: https://github.com/inertiaui/modal (MIT; see fixture LICENSE).
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago inertia modal '.bin2hex(random_bytes(8));
$pages = $workspace.'/resources/js/Pages/Admin';
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
$inertia = $workspace.'/vendor/inertiajs/inertia-laravel/src';
$modal = $workspace.'/vendor/inertiaui/modal/src';
foreach ([
    $pages,
    $framework.'/Support/Facades',
    $framework.'/Support',
    $framework.'/Macroable/Traits',
    $inertia,
    $modal,
] as $path) {
    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }
}
file_put_contents($pages.'/Users.vue', '<script>unknown()</script>');
copy(__DIR__.'/fixtures/analysis/route-facade-base.php.stub', $framework.'/Support/Facades/Facade.php');
copy(__DIR__.'/fixtures/analysis/laravel-macroable.php.stub', $framework.'/Macroable/Traits/Macroable.php');
file_put_contents(
    $framework.'/Support/ServiceProvider.php',
    '<?php namespace Illuminate\Support; class ServiceProvider {}',
);
copy(__DIR__.'/fixtures/analysis/inertia-facade.php.stub', $inertia.'/Inertia.php');
copy(__DIR__.'/fixtures/analysis/inertia-modal-response-factory.php.stub', $inertia.'/ResponseFactory.php');
copy(__DIR__.'/fixtures/analysis/inertia-helpers.php.stub', dirname($inertia).'/helpers.php');
copy(__DIR__.'/fixtures/analysis/inertiaui-modal-provider.php.stub', $modal.'/ModalServiceProvider.php');
copy(__DIR__.'/fixtures/analysis/inertiaui-modal.php.stub', $modal.'/Modal.php');

$catalog = [
    'inertia-pages' => ['complete' => true, 'paths' => ['resources/js/Pages'], 'extensions' => ['vue']],
    'inertia-modal' => ['provider-active' => true, 'macro-unmodified' => true, 'native-helper-active' => true],
];
$writeCatalog = static function (array $value) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['reference-catalogs' => $value]],
    ], JSON_THROW_ON_ERROR));
};
$writeCatalog($catalog);
$cases = [
    'known facade modal page' => 'Inertia::modal("Admin/Users");',
    'missing facade modal page' => 'Inertia::modal("Admin/User");',
    'named modal component' => 'Inertia::modal(component: "Admin/User");',
    'known factory modal page' => '$factory->modal("Admin/Users");',
    'missing factory modal page' => '$factory->modal("Admin/User");',
    'dynamic modal page' => 'Inertia::modal($page);',
    'unpacked modal page' => 'Inertia::modal(...["Admin/User"]);',
    'native render unaffected' => 'Inertia::render("Admin/User");',
    'uppercase facade macro is not registered' => 'Inertia::MODAL("Admin/User");',
    'uppercase factory macro is not registered' => '$factory->MODAL("Admin/User");',
];
$source = "<?php\nuse Inertia\\Inertia;\nuse Inertia\\ResponseFactory;\n";
$lines = [];
foreach ($cases as $name => $body) {
    $source .= 'function scenario'.count($lines).'(ResponseFactory $factory, string $page): void { '.$body." }\n";
    $lines[substr_count($source, "\n")] = $name;
}
file_put_contents($workspace.'/cases.php', $source);
$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php',
            'vendor/laravel/framework/src/Illuminate/Macroable/Traits/Macroable.php',
            'vendor/inertiajs/inertia-laravel/src/Inertia.php',
            'vendor/inertiajs/inertia-laravel/src/ResponseFactory.php',
            'vendor/inertiajs/inertia-laravel/helpers.php',
            'vendor/inertiaui/modal/src/ModalServiceProvider.php',
            'vendor/inertiaui/modal/src/Modal.php',
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
$analyze = static function () use ($command, $workspace): array {
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
        throw new RuntimeException('Mago worker failed; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        if ($issue['code'] !== 'ichinya/laramago/laramago-missing-inertia-page') {
            continue;
        }
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }

    return $actual;
};
$expected = [];
foreach ($lines as $line => $name) {
    if (
        str_starts_with($name, 'missing')
        || $name === 'named modal component'
        || $name === 'native render unaffected'
    ) {
        $expected[$line] = ['ichinya/laramago/laramago-missing-inertia-page'];
    }
}
$actual = $analyze();
if ($actual !== $expected) {
    throw new RuntimeException(
        'Active modal: expected '.json_encode($expected).', got '.json_encode($actual).'; inspect '.$workspace,
    );
}
echo "PASS: active official modal provider and literal pages\n";

foreach ([
    'provider not asserted active' => ['inertia-modal' => [
        'provider-active' => false,
        'macro-unmodified' => true,
        'native-helper-active' => true,
    ]],
    'macro not asserted unmodified' => ['inertia-modal' => [
        'provider-active' => true,
        'macro-unmodified' => false,
        'native-helper-active' => true,
    ]],
    'native helper not asserted active' => ['inertia-modal' => [
        'provider-active' => true,
        'macro-unmodified' => true,
        'native-helper-active' => false,
    ]],
    'malformed modal assertion' => ['inertia-modal' => 'active'],
    'incomplete page catalog' => [
        'inertia-pages' => ['complete' => false, 'paths' => ['resources/js/Pages'], 'extensions' => ['vue']],
    ],
] as $label => $change) {
    $writeCatalog(array_replace($catalog, $change));
    $actual = $analyze();
    $allowed = $label === 'incomplete page catalog'
        ? []
        : [
            $line = array_search('native render unaffected', $lines, true) => [
                'ichinya/laramago/laramago-missing-inertia-page',
            ],
        ];
    if ($actual !== $allowed) {
        throw new RuntimeException(
            $label.': expected '.json_encode($allowed).', got '.json_encode($actual).'; inspect '.$workspace,
        );
    }
    echo 'PASS: '.$label."\n";
}

$nativeOnly = [
    array_search('native render unaffected', $lines, true) => ['ichinya/laramago/laramago-missing-inertia-page'],
];
foreach ([
    'changed provider registration' => [
        $modal.'/ModalServiceProvider.php',
        "ResponseFactory::macro('modal',",
        "ResponseFactory::macro('other',",
    ],
    'changed promoted component' => [$modal.'/Modal.php', 'protected string $component', 'protected string $other'],
    'changed modal forwarding' => [
        $modal.'/Modal.php',
        'inertia()->render($this->component, $this->props)',
        "inertia()->render('Other', \$this->props)",
    ],
    'changed macro dispatch' => [
        $framework.'/Macroable/Traits/Macroable.php',
        'return $macro(...$parameters);',
        'return null;',
    ],
    'changed native helper' => [
        dirname($inertia).'/helpers.php',
        'return $instance;',
        'return null;',
    ],
    'concrete facade modal' => [
        $inertia.'/Inertia.php',
        'protected static function getFacadeAccessor',
        'public static function modal(string $component): mixed { return null; } protected static function getFacadeAccessor',
    ],
    'concrete factory modal' => [
        $inertia.'/ResponseFactory.php',
        'public function render',
        'public function modal(string $component): mixed { return null; } public function render',
    ],
] as $label => [$file, $original, $changed]) {
    $writeCatalog($catalog);
    $contents = file_get_contents($file);
    if (! str_contains($contents, $original)) {
        throw new RuntimeException($label.': expected upstream source token missing.');
    }
    file_put_contents($file, str_replace($original, $changed, $contents));
    try {
        $actual = $analyze();
        if ($actual !== $nativeOnly) {
            throw new RuntimeException(
                $label.': expected '.json_encode($nativeOnly).', got '.json_encode($actual).'; inspect '.$workspace,
            );
        }
        echo 'PASS: '.$label."\n";
    } finally {
        file_put_contents($file, $contents);
    }
}

mkdir($workspace.'/bootstrap');
file_put_contents(
    $workspace.'/bootstrap/macros.php',
    "<?php \\Inertia\\ResponseFactory::macro('modal', fn (string \$component): string => \$component);",
);
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'reference-catalogs' => $catalog,
            'macro-files' => ['bootstrap/macros.php'],
        ],
    ],
], JSON_THROW_ON_ERROR));
$actual = $analyze();
if ($actual !== $nativeOnly) {
    throw new RuntimeException(
        'Cataloged modal macro override: expected '
        .json_encode($nativeOnly)
        .', got '
        .json_encode($actual)
        .'; inspect '
        .$workspace,
    );
}
echo "PASS: cataloged modal macro override\n";

$writeCatalog($catalog);
file_put_contents(
    $workspace.'/namespace-helper.php',
    '<?php namespace InertiaUI\\Modal; function inertia(): mixed { return null; }',
);
$configuration['source']['includes'][] = 'namespace-helper.php';
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$actual = $analyze();
if ($actual !== $nativeOnly) {
    throw new RuntimeException(
        'Namespaced helper override: expected '
        .json_encode($nativeOnly)
        .', got '
        .json_encode($actual)
        .'; inspect '
        .$workspace,
    );
}
echo "PASS: namespaced helper override\n";

$configuration['source']['includes'] = array_values(array_filter(
    $configuration['source']['includes'],
    static fn (string $path): bool => (
        ! str_contains($path, 'vendor/inertiaui/modal/')
        && $path !== 'namespace-helper.php'
    ),
));
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$actual = $analyze();
if ($actual !== $nativeOnly) {
    throw new RuntimeException(
        'Absent optional package: expected '
        .json_encode($nativeOnly)
        .', got '
        .json_encode($actual)
        .'; inspect '
        .$workspace,
    );
}
echo "PASS: absent optional package\n";

// Leave the generated fixture for inspection on failure only.
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
