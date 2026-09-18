<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach (['enabled', 'disabled', 'native', 'incomplete', 'custom', 'malformed-json', 'invalid-root'] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago references '.bin2hex(random_bytes(8));
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
    if ($mode === 'invalid-root') {
        $catalogs['views']['paths'] = ['../outside'];
        file_put_contents($workspace.'/composer.json', json_encode([
            'extra' => ['laramago' => ['reference-catalogs' => $catalogs]],
        ], JSON_THROW_ON_ERROR));
    }
    $on = $mode === 'enabled';
    $viewsOn = $on || $mode === 'malformed-json';
    $cases = [
        ['view("nested.exists");', []],
        ['view("nested/exists");', []],
        ['view(view: "other");', []],
        ['view("absent");', $viewsOn ? ['ichinya/laramago/laramago-missing-view'] : []],
        ['view("pkg::absent");', []],
        ['view("../outside");', []],
        ['view();', []],
        ['$name = (string) random_int(1, 2); view($name);', []],
        ['$arguments = ["absent"]; view(...$arguments);', []],
        ['trans("messages.exists", [], "en");', []],
        ['trans("messages.nested.exists", [], "en");', []],
        ['trans("messages.nested.absent", [], "en");', $on ? ['ichinya/laramago/laramago-missing-translation'] : []],
        ['trans("messages.fallback", [], "en");', []],
        ['trans("messages.json", [], "en");', []],
        ['__(locale: "en", key: "messages.absent");', $on ? ['ichinya/laramago/laramago-missing-translation'] : []],
        ['trans("absent.group", [], "en");', $on ? ['ichinya/laramago/laramago-missing-translation'] : []],
        ['trans("messages.duplicate.first", [], "en");', $on ? ['ichinya/laramago/laramago-missing-translation'] : []],
        ['trans("messages.duplicate.last", [], "en");', []],
        ['trans("messages.dynamic.child", [], "en");', []],
        ['trans("messages.absent");', []],
        ['trans("messages.absent", [], "de");', []],
        ['trans("pkg::messages.absent", [], "en");', []],
        ['trans("dotted.literal.key", [], "en");', []],
        ['trans("spread.absent", [], "en");', []],
        ['trans("trap.absent", [], "en");', []],
        ['\\view("absent");', $viewsOn ? ['ichinya/laramago/laramago-missing-view'] : []],
        ['view(42);', ['invalid-argument']],
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
