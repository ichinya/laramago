<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach ([
    'enabled',
    'incomplete',
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
    file_put_contents($workspace.'/resources/views/nested/exists.blade.php', 'Never execute {{ unknown() }}');
    file_put_contents($workspace.'/custom-views/other.html', 'Exists');
    file_put_contents(
        $workspace.'/custom-views/123.php',
        '<?php throw new RuntimeException("Never execute templates");',
    );
    file_put_contents($workspace.'/resources/views/other.php', 'First configured path');
    file_put_contents($workspace.'/resources/views/other.css', 'Lower priority extension');
    file_put_contents($workspace.'/resources/views/dotted.name.php', 'Not a conventional dotted view name');
    foreach (['rank-a', 'rank-b', 'rank-c', 'rank-d'] as $candidate) {
        file_put_contents($workspace.'/resources/views/'.$candidate.'.html', 'Candidate');
    }
    $settings = [
        'reference-catalogs' => [
            'views' => ['complete' => $mode !== 'incomplete', 'paths' => ['resources/views', 'custom-views']],
        ],
    ];
    file_put_contents($workspace.'/composer.json', json_encode(
        ['extra' => ['laramago' => $settings]],
        JSON_THROW_ON_ERROR,
    ));
    $missing = ['ichinya/laramago/laramago-missing-view'];
    $on = $mode === 'enabled';
    $factoryOn = $on;
    $cases = [
        ['View::make("nested.exist");', $on ? $missing : []],
        ['$factory->make("nested/exist");', $on ? $missing : []],
        ['View::make("othr");', $on ? $missing : []],
        ['View::make("12");', $on ? $missing : []],
        ['View::make("rank-z");', $on ? $missing : []],
        ['View::make("dotted.nam");', $on ? $missing : []],

        ['View::make("nested.exists");', []],
        ['View::make("nested/exists");', []],
        ['View::make(view: "other");', []],
        ['View::make("absent");', $on ? $missing : []],
        ['View::make(view: "absent");', $on ? $missing : []],
        ['$factory->make("absent");', $factoryOn ? $missing : []],
        ['$factory->make(mergeData: [], view: "absent");', $factoryOn ? $missing : []],
        ['$factory->make("nested/exists");', []],
        ['View::make("pkg::absent");', []],
        ['View::make("../outside");', []],
        ['View::make($name);', []],
        ['View::make(...["absent"]);', []],
        ['CustomView::make("absent");', []],
        ['$custom->make("absent");', []],
        ['View::exists("absent");', []],
        ['View::first(["absent", "nested.exists"]);', []],
        ['View::file("absent");', []],
        ['View::renderWhen(false, "absent");', []],
        ['$factory->make(42);', ['invalid-argument']],
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
        $body = $lines[$primary['span']['start']['line'] + 1][0] ?? '';
        $expectedNotes = match ($body) {
            'View::make("nested.exist");', '$factory->make("nested/exist");' => [
                'Similar view "nested.exists" is declared at resources/views/nested/exists.blade.php.',
            ],
            'View::make("othr");' => ['Similar view "other" is declared at resources/views/other.php.'],
            'View::make("12");' => ['Similar view "123" is declared at custom-views/123.php.'],
            'View::make("rank-z");' => [
                'Similar view "rank-a" is declared at resources/views/rank-a.html.',
                'Similar view "rank-b" is declared at resources/views/rank-b.html.',
                'Similar view "rank-c" is declared at resources/views/rank-c.html.',
            ],
            default => [],
        };
        if ($issue['code'] === 'ichinya/laramago/laramago-missing-view' && ($issue['notes'] ?? []) !== $expectedNotes) {
            throw new RuntimeException('Unexpected suggestions: '.json_encode($issue).'; see '.$workspace);
        }
        if (
            $issue['code'] === 'ichinya/laramago/laramago-missing-view'
            && (count($issue['annotations']) !== 1
            || ($issue['edits'] ?? []) !== [])
        ) {
            throw new RuntimeException('Suggestions must not add foreign-file annotations or automatic edits.');
        }
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
