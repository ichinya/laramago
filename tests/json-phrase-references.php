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
    'malformed-json',
    'numeric-json',
    'invalid-root',
] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago json phrases '.bin2hex(random_bytes(8));
    foreach ([
        'lang/en',
        'lang/fr',
        'resources/views/nested',
        'custom-views',
        'vendor/laravel/framework/src/Illuminate/Foundation',
    ] as $directory) {
        mkdir($workspace.'/'.$directory, 0777, true);
    }
    $helpers = $mode === 'custom' ? 'custom.php' : 'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php';
    copy(__DIR__.'/fixtures/analysis/translation-helpers.php.stub', $workspace.'/'.$helpers);
    file_put_contents($workspace.'/resources/views/nested/exists.blade.php', 'Never execute {{ unknown() }}');
    file_put_contents($workspace.'/custom-views/other.html', 'Exists');
    file_put_contents($workspace.'/lang/en/messages.php', <<<'PHP'
        <?php
        return ['exists' => 'Present', 'nested' => ['exists' => 'Present'], 'dynamic' => unknown(), 'duplicate' => ['first' => 'First'], 'duplicate' => ['last' => 'Last']];
        PHP);
    file_put_contents($workspace.'/lang/fr/messages.php', '<?php return ["fallback" => "Present"];');
    file_put_contents($workspace.'/lang/en/dotted.php', '<?php return ["literal.key" => "Present"];');
    file_put_contents($workspace.'/lang/en/spread.php', '<?php return [...unknown()];');
    file_put_contents(
        $workspace.'/lang/en/trap.php',
        '<?php file_put_contents(__DIR__."/executed.txt", "forbidden"); return [];',
    );
    file_put_contents($workspace.'/lang/en.json', '{"messages.json":"Present"}');
    file_put_contents($workspace.'/lang/en.json', json_encode([
        'Welcome back!' => 'Welcome',
        'There are :count items.' => 'Items',
        'False phrase' => false,
        'Empty phrase' => '',
        'Null phrase' => null,
        'Zero phrase' => 0,
        'Array phrase' => [],
        'messages.absent' => 'JSON takes priority',
        'pkg::group.key' => 'JSON namespace collision',
        '../outside' => 'Exact JSON path-shaped key',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($workspace.'/lang/fr.json', '{"Fallback JSON phrase":"Not consulted"}');
    file_put_contents($workspace.'/lang/fr/Fallback PHP phrase.php', '<?php return ["present" => "Yes"];');
    file_put_contents($workspace.'/lang/en/Empty PHP phrase.php', '<?php return [];');
    file_put_contents(
        $workspace.'/lang/en/Message group.php',
        '<?php return ["with spaces!" => "Yes", "empty" => "", "null" => null];',
    );
    $catalogs = [
        'views' => ['complete' => true, 'paths' => ['resources/views', 'custom-views']],
        'translations' => ['complete' => true, 'path' => 'lang', 'locales' => ['en' => ['en', 'fr']]],
    ];
    if ($mode === 'incomplete') {
        $catalogs['views']['complete'] = false;
        $catalogs['translations']['complete'] = false;
    }
    file_put_contents($workspace.'/composer.json', json_encode(
        $mode === 'disabled' ? [] : ['extra' => ['laramago' => ['reference-catalogs' => $catalogs]]],
        JSON_THROW_ON_ERROR,
    ));
    if ($mode === 'malformed-json') {
        file_put_contents($workspace.'/lang/en.json', '{');
    }
    if ($mode === 'numeric-json') {
        file_put_contents($workspace.'/lang/en.json', '{"2":"Renumbered to zero"}');
    }
    if ($mode === 'invalid-root') {
        $catalogs['views']['paths'] = ['../outside'];
        file_put_contents($workspace.'/composer.json', json_encode([
            'extra' => ['laramago' => ['reference-catalogs' => $catalogs]],
        ], JSON_THROW_ON_ERROR));
    }
    $on = $mode === 'enabled';
    $viewsOn = $on || $mode === 'malformed-json';
    $missing = $on ? ['ichinya/laramago/laramago-missing-translation'] : [];
    $cases = [
        ['__("Welcome back!", [], "en");', []],
        ['__("There are :count items.", [], "en");', []],
        ['__("Welcome absent!", [], "en");', $missing],
        ['trans(key: "Welcome absent!", locale: "en");', $missing],
        ['__("False phrase", [], "en");', []],
        ['__("Empty phrase", [], "en");', []],
        ['__("Null phrase", [], "en");', []],
        ['__("Zero phrase", [], "en");', []],
        ['__("Array phrase", [], "en");', []],
        ['__("messages.absent", [], "en");', []],
        ['__("pkg::group.key", [], "en");', []],
        ['__("../outside", [], "en");', []],
        ['__("Fallback JSON phrase", [], "en");', $missing],
        ['__("Fallback PHP phrase", [], "en");', []],
        ['__("Empty PHP phrase", [], "en");', []],
        ['__("Message group.with spaces!", [], "en");', []],
        ['__("Message group.absent value!", [], "en");', $missing],
        ['__("Message group.empty", [], "en");', []],
        ['__("Message group.null", [], "en");', []],
        ['__("messages", [], "en");', []],
        ['__("Missing phrase.with punctuation!", [], "en");', $missing],
        ['__("Missing phrase", [], "de");', []],
        ['__("Missing phrase");', []],
        ['__("Missing phrase", [], null);', []],
        ['__("pkg::absent.key", [], "en");', []],
        ['__("path/absent", [], "en");', []],
        ['__("path\\absent", [], "en");', []],
        ['__("Question?", [], "en");', []],
        ['__("", [], "en");', []],
        ['__(".hidden", [], "en");', []],
        ['$key = (string) random_int(1, 2); __($key, [], "en");', []],
        ['__("0", [], "en");', $missing],
        ['trans(42);', ['invalid-argument']],
    ];
    $source = "<?php\nnamespace App;\n";
    $lines = [];
    foreach ($cases as $index => [$body, $expected]) {
        $source .= 'function scenario'.$index.'(): void { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = [$body, $expected];
    }
    // Namespace fallback must not mistake an application function for Laravel's helper.
    $source .= "namespace Custom;\nfunction view(string \$name): void {}\nfunction local(): void { view('absent'); }\n";
    file_put_contents($workspace.'/cases.php', $source);
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => [$helpers]],
    ];
    if ($mode !== 'native') {
        $config['extension-hosts'] = [
            'laramago' => [
                'command' => [
                    PHP_BINARY,
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
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
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
    if ($actual !== [] || is_file($workspace.'/lang/en/executed.txt')) {
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
