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
    'custom-first',
    'custom-exists',
    'custom-arr',
    'custom-value',
    'shadow-arr',
    'legacy-target',
    'custom-from',
] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago references '.bin2hex(random_bytes(8));
    foreach ([
        'resources/views/nested',
        'custom-views',
        'vendor/laravel/framework/src/Illuminate/View',
        'vendor/laravel/framework/src/Illuminate/Collections',
        'vendor/laravel/framework/src/Illuminate/Support/Facades',
        'app',
    ] as $directory) {
        mkdir($workspace.'/'.$directory, 0777, true);
    }
    $framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
    foreach (['Factory', 'ViewName', 'ViewFinderInterface'] as $class) {
        copy(__DIR__.'/fixtures/analysis/view-reference-'.$class.'.php.stub', $framework.'/View/'.$class.'.php');
    }
    $factoryPath = $framework.'/View/Factory.php';
    $first = file_get_contents(__DIR__.'/fixtures/analysis/view-first-Factory.php.stub');
    $members = substr($first, strpos($first, '{') + 1);
    $members = substr($members, 0, strrpos($members, '}'));
    $factory = file_get_contents($factoryPath);
    $offset = strrpos($factory, '}');
    file_put_contents($factoryPath, substr($factory, 0, $offset).$members.'}');
    foreach (['Arr', 'helpers'] as $name) {
        copy(__DIR__.'/fixtures/analysis/view-first-'.$name.'.php.stub', $framework.'/Collections/'.$name.'.php');
    }
    if (in_array($mode, ['custom-first', 'custom-exists'], true)) {
        $needle = $mode === 'custom-first'
            ? '$view = \\Illuminate\\Support\\Arr::first'
            : '$this->finder->find($view);';
        $replace = $mode === 'custom-first' ? '$view = $this->customFirst' : 'return true;';
        file_put_contents($factoryPath, str_replace($needle, $replace, file_get_contents($factoryPath)));
    }
    if ($mode === 'custom-arr') {
        $path = $framework.'/Collections/Arr.php';
        file_put_contents($path, str_replace('array_find_key', 'custom_array_find_key', file_get_contents($path)));
    }
    if ($mode === 'custom-from') {
        $path = $framework.'/Collections/Arr.php';
        file_put_contents($path, str_replace(
            'is_array($items) => $items',
            'is_array($items) => []',
            file_get_contents($path),
        ));
    }
    if ($mode === 'custom-value') {
        $path = $framework.'/Collections/helpers.php';
        file_put_contents($path, str_replace(
            'return $value instanceof',
            'return true ?: $value instanceof',
            file_get_contents($path),
        ));
    }
    if ($mode === 'shadow-arr') {
        file_put_contents(
            $workspace.'/shadow.php',
            '<?php namespace Illuminate\\Support; function array_find_key($a, $b) { return 0; }',
        );
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
            substr($text, 0, $offset).' public static function first($view, $data = [], $mergeData = []): void {} '
                .substr($text, $offset),
        );
    }
    file_put_contents($workspace.'/resources/views/nested/exists.blade.php', 'Never execute {{ unknown() }}');
    file_put_contents($workspace.'/custom-views/other.html', 'Exists');
    file_put_contents($workspace.'/resources/views/0.blade.php', 'Exists but falsey');
    $settings = [
        'reference-catalogs' => [
            'views' => ['complete' => $mode !== 'incomplete', 'paths' => ['resources/views', 'custom-views']],
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
    $missing = ['ichinya/laramago/laramago-missing-first-view'];
    $on = $mode === 'enabled';
    $factoryOn = $on || in_array($mode, ['custom-accessor', 'custom-facade-method'], true);
    $cases = [
        ['View::first(["absent", "missing"]);', $on ? $missing : []],
        ['View::first(views: ["absent", "missing"]);', $on ? $missing : []],
        ['$factory->first(["absent"]);', $factoryOn ? $missing : []],
        ['$factory->first(mergeData: [], views: ["absent"]);', $factoryOn ? $missing : []],
        ['View::first([]);', $on ? $missing : []],
        ['View::first(["absent", "nested.exists"]);', []],
        ['View::first(["nested/exists", "absent"]);', []],
        ['View::first(["absent", "pkg::unknown"]);', []],
        ['View::first(["absent", "../outside"]);', []],
        ['View::first(["absent", $name]);', []],
        ['View::first(["absent", ...[$name]]);', []],
        ['View::first(["key" => "absent"]);', []],
        ['View::first(["0"]);', []],
        ['View::first([""]);', []],
        ['CustomView::first(["absent"]);', []],
        ['$custom->first(["absent"]);', []],
        ['View::first(...[["absent"]]);', []],
        ['$factory->first(42);', ['invalid-argument']],
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
        'php-version' => $mode === 'legacy-target' ? '8.2' : '8.4',
        'source' => [
            'paths' => ['cases.php'],
            'includes' => array_values(array_filter([
                'vendor',
                'dependencies.php',
                $mode === 'custom' ? 'Factory.php' : null,
                in_array($mode, ['shadow-normalize', 'shadow-arr'], true) ? 'shadow.php' : null,
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
        $exit !== 1
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
