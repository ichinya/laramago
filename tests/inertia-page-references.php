<?php

declare(strict_types=1);

// Check native Inertia render calls through the real SDK worker without executing fixtures.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago inertia references '.bin2hex(random_bytes(8));
$pages = $workspace.'/resources/js/Pages/Admin';
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Support/Facades';
$inertia = $workspace.'/vendor/inertiajs/inertia-laravel/src';
mkdir($pages, 0777, true);
mkdir($framework, 0777, true);
mkdir($inertia, 0777, true);
file_put_contents($pages.'/Users.vue', '<script>unknown()</script>');
file_put_contents($pages.'/Users.tsx', 'unknown()');
copy(__DIR__.'/fixtures/analysis/route-facade-base.php.stub', $framework.'/Facade.php');
copy(__DIR__.'/fixtures/analysis/inertia-facade.php.stub', $inertia.'/Inertia.php');
copy(__DIR__.'/fixtures/analysis/inertia-response-factory.php.stub', $inertia.'/ResponseFactory.php');
$catalog = [
    'complete' => true,
    'paths' => ['resources/js/Pages'],
    'extensions' => ['vue', 'tsx'],
];
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['reference-catalogs' => ['inertia-pages' => $catalog]]],
], JSON_THROW_ON_ERROR));
$missing = ['ichinya/laramago/laramago-missing-inertia-page'];
$cases = [
    'known facade page' => ['Inertia::render("Admin/Users");', []],
    'missing facade page' => ['Inertia::render("Admin/User");', $missing],
    'named component' => ['Inertia::render(component: "Admin/User");', $missing],
    'case-sensitive page' => ['Inertia::render("admin/Users");', $missing],
    'known factory page' => ['$factory->render("Admin/Users");', []],
    'missing factory page' => ['$factory->render("Admin/User");', $missing],
    'dynamic page deferred' => ['Inertia::render($page);', []],
    'concatenated page deferred' => ['Inertia::render("Admin/".$page);', []],
    'unpacked arguments deferred' => ['Inertia::render(...["Admin/User"]);', []],
    'facade subclass deferred' => ['CustomInertia::render("Admin/User");', []],
    'factory override deferred' => ['$custom->render("Admin/User");', []],
];
$source = <<<'PHP'
    <?php
    use Inertia\Inertia;
    use Inertia\CustomInertia;
    use Inertia\ResponseFactory;
    use Inertia\CustomResponseFactory;
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $codes]) {
    $source .=
        'function scenario'
        .count($lines)
        .'(ResponseFactory $factory, CustomResponseFactory $custom, string $page): void { '
        .$body
        .' }'
        ."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
$configuration = [
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
            'workers' => 3,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$analyze = static function (string $report, string $log) use ($command, $workspace): int {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$report, 'w'], 2 => ['file', $workspace.'/'.$log, 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);

    return proc_close($process);
};

$exit = $analyze('report.json', 'stderr.log');
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 0 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Expected extension warnings without fallback; inspect '.$workspace);
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
    throw new RuntimeException('Unexpected diagnostics outside Inertia scenarios; inspect '.$workspace);
}

file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['reference-catalogs' => ['inertia-pages' => [...$catalog, 'unique' => true]]]],
], JSON_THROW_ON_ERROR));
$exit = $analyze('unique.json', 'unique.log');
$report = json_decode(file_get_contents($workspace.'/unique.json'), true, flags: JSON_THROW_ON_ERROR);
if (
    $exit !== 0
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/unique.log'),
    )
) {
    throw new RuntimeException('Expected unique-page warnings without extension fallback; inspect '.$workspace);
}
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    if (in_array($name, ['known facade page', 'known factory page'], true)) {
        $expected[] = 'ichinya/laramago/laramago-ambiguous-inertia-page';
    }
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException(
            $name
            .' with unique assertion: expected '
            .json_encode($expected)
            .', got '
            .json_encode($codes)
            .'; see '
            .$workspace,
        );
    }
    unset($actual[$line]);
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics under unique-page assertion; inspect '.$workspace);
}
echo "PASS: explicit unique assertion warns at native render calls only\n";

foreach ([
    'incomplete catalog' => [...$catalog, 'complete' => false],
    'missing catalog' => null,
] as $label => $contract) {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['reference-catalogs' => ['inertia-pages' => $contract]]],
    ], JSON_THROW_ON_ERROR));
    $exit = $analyze('contract.json', 'contract.log');
    $report = json_decode(file_get_contents($workspace.'/contract.json'), true, flags: JSON_THROW_ON_ERROR);
    if (
        $exit !== 0
        || ($report['issues'] ?? []) !== []
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($workspace.'/contract.log'),
        )
    ) {
        throw new RuntimeException($label.': expected no diagnostics; inspect '.$workspace);
    }
    echo 'PASS: '.$label."\n";
}

mkdir($workspace.'/custom', 0777, true);
copy(__DIR__.'/fixtures/analysis/inertia-facade.php.stub', $workspace.'/custom/Inertia.php');
copy(__DIR__.'/fixtures/analysis/inertia-response-factory.php.stub', $workspace.'/custom/ResponseFactory.php');
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['reference-catalogs' => ['inertia-pages' => [...$catalog, 'unique' => true]]]],
], JSON_THROW_ON_ERROR));
$configuration['source']['includes'] = [
    'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
    'custom/Inertia.php',
    'custom/ResponseFactory.php',
];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$exit = $analyze('custom.json', 'custom.log');
$report = json_decode(file_get_contents($workspace.'/custom.json'), true, flags: JSON_THROW_ON_ERROR);
if (
    $exit !== 0
    || ($report['issues'] ?? []) !== []
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/custom.log'),
    )
) {
    throw new RuntimeException('Custom Inertia replacements must defer; inspect '.$workspace);
}
echo "PASS: custom Inertia replacements defer\n";

file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['reference-catalogs' => ['inertia-pages' => [...$catalog, 'unique' => true]]]],
], JSON_THROW_ON_ERROR));
$configuration['source']['includes'] = [
    'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
    'vendor/inertiajs/inertia-laravel/src/Inertia.php',
    'vendor/inertiajs/inertia-laravel/src/ResponseFactory.php',
];
unset($configuration['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$exit = $analyze('disabled.json', 'disabled.log');
$report = json_decode(file_get_contents($workspace.'/disabled.json'), true, flags: JSON_THROW_ON_ERROR);
if ($exit !== 0 || ($report['issues'] ?? []) !== []) {
    throw new RuntimeException('Disabled analyzer must leave Inertia render calls native; inspect '.$workspace);
}
echo "PASS: disabled analyzer leaves Inertia render calls native\n";

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
