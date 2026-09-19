<?php

declare(strict_types=1);

// Analyze pinned upstream Livewire sources through the real Mago worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago livewire references '.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Support/Facades';
$livewire = $workspace.'/vendor/livewire/livewire/src';
mkdir($framework, 0777, true);
mkdir($livewire, 0777, true);
copy(__DIR__.'/fixtures/analysis/route-facade-base.php.stub', $framework.'/Facade.php');

$missing = ['ichinya/laramago/laramago-missing-livewire-component'];
$cases = [
    'known static test' => ['Livewire::test("posts.show");', []],
    'missing static test' => ['Livewire::test("posts.missing");', $missing],
    'missing instance test' => ['$manager->test("posts.missing");', $missing],
    'missing static mount' => ['Livewire::mount("posts.missing");', $missing],
    'missing instance mount' => ['$manager->mount(name: "posts.missing");', $missing],
    'missing instance new' => ['$manager->new("posts.missing");', $missing],
    'simple string defers for possible class' => ['Livewire::test("Missing");', []],
    'class syntax defers' => ['Livewire::test(ShowPost::class);', []],
    'dynamic name defers' => ['Livewire::test($name);', []],
    'unpacked name defers' => ['Livewire::test(...["posts.missing"]);', []],
    'facade subclass defers' => ['CustomLivewire::test("posts.missing");', []],
    'manager subclass defers' => ['$custom->test("posts.missing");', []],
];

foreach ([3, 4] as $version) {
    copy(__DIR__."/fixtures/analysis/livewire-$version-manager.php.stub", $livewire.'/LivewireManager.php');
    copy(__DIR__."/fixtures/analysis/livewire-$version-facade.php.stub", $livewire.'/Livewire.php');
    file_put_contents(
        $livewire.'/CustomLivewire.php',
        '<?php namespace Livewire; class CustomLivewire extends Livewire {}',
    );
    file_put_contents(
        $livewire.'/CustomManager.php',
        '<?php namespace Livewire; class CustomManager extends LivewireManager {}',
    );
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'reference-catalogs' => [
                    'livewire-components' => ['complete' => true, 'names' => ['posts.show']],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $source = <<<'PHP'
        <?php
        use Livewire\Livewire;
        use Livewire\CustomLivewire;
        use Livewire\LivewireManager;
        use Livewire\CustomManager;
        class ShowPost {}
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $codes]) {
        $source .=
            'function scenario'
            .count($lines)
            .'(LivewireManager $manager, CustomManager $custom, string $name): void { '
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
                'vendor/livewire/livewire/src/Livewire.php',
                'vendor/livewire/livewire/src/LivewireManager.php',
                'vendor/livewire/livewire/src/CustomLivewire.php',
                'vendor/livewire/livewire/src/CustomManager.php',
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
        throw new RuntimeException("Livewire $version analyzer failed: $log; inspect $workspace");
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
                "Livewire $version $name: expected "
                .json_encode($expected)
                .', got '
                .json_encode($codes)
                ."; inspect $workspace",
            );
        }
        unset($actual[$line]);
    }
    if ($actual !== []) {
        throw new RuntimeException(
            "Livewire $version unexpected diagnostics: ".json_encode($actual)."; inspect $workspace",
        );
    }
    echo "PASS: Livewire $version native references and deferrals\n";
}

$analyzeQuiet = static function (string $label) use ($command, $workspace): void {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/quiet.json', 'w'], 2 => ['file', $workspace.'/quiet.log', 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $report = json_decode(file_get_contents($workspace.'/quiet.json'), true, flags: JSON_THROW_ON_ERROR);
    $log = file_get_contents($workspace.'/quiet.log');
    if (
        $exit !== 0
        || ($report['issues'] ?? []) !== []
        || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
    ) {
        throw new RuntimeException("$label should defer cleanly; inspect $workspace");
    }
    echo "PASS: $label\n";
};
file_put_contents($workspace.'/cases.php', '<?php use Livewire\\Livewire; Livewire::test("posts.missing");');
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'reference-catalogs' => [
                'livewire-components' => ['complete' => false, 'names' => ['posts.show']],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
$analyzeQuiet('incomplete effective catalog');
file_put_contents($workspace.'/composer.json', '{}');
$analyzeQuiet('absent effective catalog');
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'reference-catalogs' => [
                'livewire-components' => ['complete' => true, 'names' => ['posts.show']],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
$manager = file_get_contents($livewire.'/LivewireManager.php');
$changed = str_replace('return Testable::create(', 'return CustomTestable::create(', $manager, $replacements);
if ($replacements !== 1) {
    throw new RuntimeException('Pinned native test body changed unexpectedly.');
}
file_put_contents($livewire.'/LivewireManager.php', $changed);
$analyzeQuiet('modified manager dispatch');
file_put_contents($livewire.'/LivewireManager.php', $manager);
$facade = file_get_contents($livewire.'/Livewire.php');
$changed = str_replace(
    'return \\Livewire\\LivewireManager::class;',
    'return \\Livewire\\CustomManager::class;',
    $facade,
    $replacements,
);
if ($replacements !== 1) {
    throw new RuntimeException('Pinned native facade accessor changed unexpectedly.');
}
file_put_contents($livewire.'/Livewire.php', $changed);
$analyzeQuiet('modified facade accessor');
file_put_contents($livewire.'/Livewire.php', $facade);
file_put_contents(
    $workspace.'/cases.php',
    '<?php use Livewire\\LivewireManager; function scenario(LivewireManager $manager): void { $manager->mount("posts.missing"); }',
);
$changed = str_replace(
    'function mount($name, $params = [], $key = null, $slots = [])',
    'function mount($name, $params = [], $key = null, $slots = [], $extra = null)',
    $manager,
    $replacements,
);
if ($replacements !== 1) {
    throw new RuntimeException('Pinned native mount signature changed unexpectedly.');
}
file_put_contents($livewire.'/LivewireManager.php', $changed);
$analyzeQuiet('modified manager signature');
file_put_contents($livewire.'/LivewireManager.php', $manager);
file_put_contents(
    $workspace.'/cases.php',
    '<?php namespace Livewire; function app($key): mixed { return null; }'
    .' function scenario(LivewireManager $manager): void {'
    .' $manager->new("posts.missing"); $manager->mount("posts.missing"); }',
);
$analyzeQuiet('namespaced app helper shadows native forwarding');

$resolvedWorkspace = realpath($workspace);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
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
