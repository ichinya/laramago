<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach ([
    'enabled',
    'disabled',
    'native',
    'incomplete',
    'custom',
    'custom-make',
    'custom-class-doc',
    'custom-normalize',
    'custom-delimiter',
    'binding',
    'finder-binding',
    'shadow-normalize',
    'custom-accessor',
    'custom-facade-method',
] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago references '.bin2hex(random_bytes(8));
    foreach ([
        'resources/views/nested',
        'custom-views',
        'vendor/laravel/framework/src/Illuminate/View',
        'vendor/laravel/framework/src/Illuminate/Support/Facades',
        'app',
    ] as $directory) {
        mkdir($workspace.'/'.$directory, 0777, true);
    }
    $framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
    foreach (['Factory', 'ViewName', 'ViewFinderInterface'] as $class) {
        copy(__DIR__.'/fixtures/analysis/view-reference-'.$class.'.php.stub', $framework.'/View/'.$class.'.php');
    }
    foreach (['Facade', 'View'] as $class) {
        copy(
            __DIR__.'/fixtures/analysis/view-reference-'.$class.'.php.stub',
            $framework.'/Support/Facades/'.$class.'.php',
        );
    }
    file_put_contents($workspace.'/dependencies.php', <<<'PHP'
        <?php
        namespace Illuminate\Contracts\View { interface View {} }
        namespace Illuminate\Contracts\Support { interface Arrayable {} }
        namespace Custom {
            class Factory extends \Illuminate\View\Factory {}
            class View extends \Illuminate\Support\Facades\View {}
        }
        PHP);
    if ($mode === 'custom') {
        rename($framework.'/View/Factory.php', $workspace.'/Factory.php');
    }
    if ($mode === 'custom-class-doc') {
        $path = $framework.'/View/Factory.php';
        file_put_contents($path, str_replace(
            'class Factory',
            '/** @method \\Illuminate\\Contracts\\View\\View make(mixed $view, array $data = [], array $mergeData = []) */ class Factory',
            file_get_contents($path),
        ));
    }
    if ($mode === 'custom-make') {
        $path = $framework.'/View/Factory.php';
        file_put_contents($path, str_replace(
            '$view = $this->normalizeName($view)',
            '$view = "nested.exists"',
            file_get_contents($path),
        ));
    }
    if ($mode === 'custom-normalize') {
        $path = $framework.'/View/ViewName.php';
        file_put_contents($path, str_replace(
            "str_replace('/', '.', \$name)",
            '"nested.exists"',
            file_get_contents($path),
        ));
    }
    if ($mode === 'custom-delimiter') {
        $path = $framework.'/View/ViewFinderInterface.php';
        file_put_contents($path, str_replace("'::'", "'.'", file_get_contents($path)));
    }
    if ($mode === 'shadow-normalize') {
        file_put_contents(
            $workspace.'/shadow.php',
            '<?php namespace Illuminate\\View; function str_replace($search, $replace, $subject) { return "nested.exists"; }',
        );
    }
    if ($mode === 'custom-accessor') {
        $path = $framework.'/Support/Facades/View.php';
        file_put_contents($path, str_replace("return 'view';", "return 'other';", file_get_contents($path)));
    }
    if ($mode === 'custom-facade-method') {
        $path = $framework.'/Support/Facades/View.php';
        $text = file_get_contents($path);
        $offset = strpos($text, '{', strpos($text, 'class View extends Facade')) + 1;
        file_put_contents(
            $path,
            substr($text, 0, $offset).' public static function make($view, $data = [], $mergeData = []): void {} '
                .substr($text, $offset),
        );
    }
    file_put_contents($workspace.'/resources/views/nested/exists.blade.php', 'Never execute {{ unknown() }}');
    file_put_contents($workspace.'/custom-views/other.html', 'Exists');
    $settings = [
        'reference-catalogs' => [
            'views' => [
                'complete' => $mode !== 'incomplete',
                'paths' => ['resources/views', 'custom-views'],
                'namespaces' => ['billing' => ['custom-views', 'resources/views']],
            ],
        ],
    ];
    if (in_array($mode, ['binding', 'finder-binding'], true)) {
        $settings['binding-files'] = ['app/bindings.php'];
        file_put_contents(
            $workspace.'/app/bindings.php',
            '<?php app()->bind("'.($mode === 'binding' ? 'view' : 'view.finder').'", Custom\\Factory::class);',
        );
    }
    file_put_contents($workspace.'/composer.json', json_encode(
        $mode === 'disabled' ? [] : ['extra' => ['laramago' => $settings]],
        JSON_THROW_ON_ERROR,
    ));
    $missing = ['ichinya/laramago/laramago-missing-view'];
    $on = $mode === 'enabled';
    $factoryOn = $on || in_array($mode, ['custom-accessor', 'custom-facade-method'], true);
    $cases = [
        ['View::make("nested.exists");', []],
        ['View::make("nested/exists");', []],
        ['View::make(view: "other");', []],
        ['View::make("absent");', $on ? $missing : []],
        ['View::make(view: "absent");', $on ? $missing : []],
        ['$factory->make("absent");', $factoryOn ? $missing : []],
        ['$factory->make(mergeData: [], view: "absent");', $factoryOn ? $missing : []],
        ['$factory->make("nested/exists");', []],
        ['View::make("pkg::absent");', []],
        ['View::make("billing::nested.exists");', []],
        ['View::make("billing::other");', []],
        ['View::make("billing::nested.EXISTS");', []],
        ['View::make("billing::absent");', $on ? ['ichinya/laramago/laramago-missing-view'] : []],
        ['View::make("Billing::absent");', []],
        ['View::make("billing::../outside");', []],
        ['View::make("../outside");', []],
        ['View::make($name);', []],
        ['View::make(...["absent"]);', []],
        ['CustomView::make("absent");', []],
        ['$custom->make("absent");', []],
        ['View::exists("absent");', []],
        ['View::first(["absent", "nested.exists"]);', []],
        ['View::file("absent");', []],
        ['View::renderWhen(false, "absent");', []],
        ['$factory->make(42);', $mode === 'custom-class-doc' ? [] : ['invalid-argument']],
    ];
    $source = "<?php\nuse Illuminate\\Support\\Facades\\View;\nuse Illuminate\\View\\Factory;\nuse Custom\\View as CustomView;\n";
    $lines = [];
    foreach ($cases as $index => [$body, $expected]) {
        $source .=
            'function scenario'
            .$index
            .'(Factory $factory, \\Custom\\Factory $custom, string $name): void { '
            .$body
            .' }'
            ."\n";
        $lines[substr_count($source, "\n")] = [$body, $expected];
    }
    file_put_contents($workspace.'/cases.php', $source);
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => [
            'paths' => ['cases.php'],
            'includes' => array_values(array_filter([
                'vendor',
                'dependencies.php',
                $mode === 'custom' ? 'Factory.php' : null,
                $mode === 'shadow-normalize' ? 'shadow.php' : null,
            ])),
        ],
    ];
    if ($mode !== 'native') {
        $config['extension-hosts'] = [
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
        ];
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
    if (
        $exit !== ($mode === 'custom-class-doc' ? 0 : 1)
        || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
    ) {
        throw new RuntimeException('Unexpected worker result; inspect '.$workspace);
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
    foreach ($lines as $line => [$body, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $mode.' '.$body.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$mode.' '.$body."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics or catalog execution; inspect '.$workspace);
    }
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
}
