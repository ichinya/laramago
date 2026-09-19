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
    'changed-condition',
    'changed-each',
    'changed-parse-data',
    'shadow-count',
    'shadow-prefix',
] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago conditional views '.bin2hex(random_bytes(8));
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
    $factoryPath = $framework.'/View/Factory.php';
    $factoryText = file_get_contents($factoryPath);
    $extra = file_get_contents(__DIR__.'/fixtures/analysis/conditional-view-Factory.php.stub');
    $extra = substr($extra, strpos($extra, '{') + 1);
    file_put_contents($factoryPath, substr(rtrim($factoryText), 0, -1).$extra);
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
            substr($text, 0, $offset)
                .' public static function renderWhen($condition, $view, $data = [], $mergeData = []): string { return ""; } '
                .substr($text, $offset),
        );
    }
    if (in_array($mode, ['changed-condition', 'changed-each', 'changed-parse-data'], true)) {
        $path = $framework.'/View/Factory.php';
        $replacements = [
            'changed-condition' => ['if (!$condition)', 'if ($condition)'],
            'changed-each' => ['count($data) > 0', 'count($data) > 1'],
            'changed-parse-data' => ['$data instanceof \\Illuminate\\Contracts\\Support\\Arrayable', 'false'],
        ];
        [$before, $after] = $replacements[$mode];
        $old = file_get_contents($path);
        $new = str_replace($before, $after, $old);
        if ($old === $new) {
            throw new RuntimeException('Mutation did not change native fixture: '.$mode);
        }
        file_put_contents($path, $new);
    }
    if (in_array($mode, ['shadow-count', 'shadow-prefix'], true)) {
        file_put_contents(
            $workspace.'/shadow.php',
            $mode === 'shadow-count'
                ? '<?php namespace Illuminate\\View; function count($data) { return 0; }'
                : '<?php namespace Illuminate\\View; function str_starts_with($data, $prefix) { return true; }',
        );
    }
    file_put_contents($workspace.'/resources/views/nested/exists.blade.php', 'Never execute {{ unknown() }}');
    file_put_contents($workspace.'/custom-views/other.html', 'Exists');
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
    $missing = ['ichinya/laramago/laramago-missing-view'];
    $on = $mode === 'enabled';
    $factoryOn = $on || in_array($mode, ['custom-accessor', 'custom-facade-method'], true);
    $cases = [
        ['$factory->renderEach(data: [], iterator: "item", empty: "missing-empty");', ['too-few-arguments']],
        ['$factory->renderWhen(view: "absent");', ['too-few-arguments']],
        ['$factory->renderWhen(condition: true);', ['too-few-arguments']],
        ['$factory->renderEach(view: "absent", iterator: "item");', ['too-few-arguments']],
        ['$factory->renderEach(view: "absent", data: [1]);', ['too-few-arguments']],
        ['View::renderWhen(true, "absent");', $on ? $missing : []],
        ['View::renderUnless(false, "absent");', $on || $mode === 'custom-facade-method' ? $missing : []],
        ['View::renderWhen(false, "absent");', []],
        ['View::renderUnless(true, "absent");', []],
        ['View::renderWhen($condition, "absent");', []],
        ['View::renderUnless($condition, "absent");', []],
        ['$factory->renderWhen(true, "absent");', $factoryOn ? $missing : []],
        ['$factory->renderUnless(view: "absent", condition: false);', $factoryOn ? $missing : []],
        ['View::renderWhen(view: "nested/exists", condition: true);', []],
        ['View::renderWhen(true, "pkg::absent");', []],
        ['View::renderWhen(true, "../outside");', []],
        ['View::renderWhen(true, $name);', []],
        ['View::renderWhen(...[true, "absent"]);', []],
        ['CustomView::renderWhen(true, "absent");', []],
        ['$custom->renderWhen(true, "absent");', []],
        ['View::renderEach("absent", [1], "item");', $on || $mode === 'custom-facade-method' ? $missing : []],
        ['$factory->renderEach("absent", ["key" => 1], "item");', $factoryOn ? $missing : []],
        [
            '$factory->renderEach(view: "absent", data: [1], iterator: "item", empty: "also-absent");',
            $factoryOn ? $missing : [],
        ],
        ['View::renderEach("absent", [], "item");', []],
        ['$factory->renderEach("absent", [], "item", "raw|not-a-view");', []],
        ['$factory->renderEach("absent", [], "item", "raw|nested.exists");', []],
        ['$factory->renderEach("absent", [], "item", "missing-empty");', $factoryOn ? $missing : []],
        ['$factory->renderEach("absent", [], "item", "nested.exists");', []],
        ['$factory->renderEach("absent", [], "item", "RAW|text");', []],
        ['$factory->renderEach("nested.exists", [1], "item", "missing-empty");', []],
        ['$factory->renderEach("absent", $data, "item", "missing-empty");', []],
        ['$factory->renderEach("absent", [...$data], "item", "missing-empty");', []],
        ['$factory->renderWhen(true, "absent", $data);', []],
        ['View::renderWhen(true, 42);', ['invalid-argument']],
    ];
    $source = "<?php\nuse Illuminate\\Support\\Facades\\View;\nuse Illuminate\\View\\Factory;\nuse Custom\\View as CustomView;\n";
    $lines = [];
    foreach ($cases as $index => [$body, $expected]) {
        $source .=
            'function scenario'
            .$index
            .'(Factory $factory, \\Custom\\Factory $custom, string $name, bool $condition, array $data): void { '
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
                in_array($mode, ['shadow-normalize', 'shadow-count', 'shadow-prefix'], true) ? 'shadow.php' : null,
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
