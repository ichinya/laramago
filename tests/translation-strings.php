<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
foreach (['enabled', 'native', 'legacy', 'ambiguous', 'json', 'custom', 'contract'] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago translations '.bin2hex(random_bytes(8));
    $catalogRoot = $mode === 'legacy' ? 'resources/lang' : 'lang';
    mkdir($workspace.'/'.$catalogRoot.'/en', 0777, true);
    $vendorDirectory = $mode === 'enabled' ? 'dependencies with spaces' : 'vendor';
    mkdir($workspace.'/'.$vendorDirectory.'/laravel/framework/src/Illuminate/Foundation', 0777, true);
    $helpers = $mode === 'custom'
        ? 'custom.php'
        : $vendorDirectory.'/laravel/framework/src/Illuminate/Foundation/helpers.php';
    copy(__DIR__.'/fixtures/analysis/translation-helpers.php.stub', $workspace.'/'.$helpers);
    if ($mode === 'contract') {
        file_put_contents($workspace.'/'.$helpers, str_replace(
            ': array|string)',
            ': array)',
            file_get_contents($workspace.'/'.$helpers),
        ));
    }
    file_put_contents($workspace.'/'.$catalogRoot.'/en/messages.php', <<<'PHP'
        <?php
        return [
            'welcome' => 'Welcome :name',
            'nested' => ['title' => 'A title'],
            'group' => ['one' => 'One', 'two' => 'Two'],
            'dynamic' => file_put_contents(__DIR__.'/executed.txt', 'forbidden'),
            'duplicate' => 'First',
            'duplicate' => ['last' => 'An array'],
        ];
        PHP);
    file_put_contents($workspace.'/'.$catalogRoot.'/en/trap.php', <<<'PHP'
        <?php
        file_put_contents(__DIR__.'/executed.txt', 'forbidden');
        return ['title' => 'Untrusted top-level execution'];
        PHP);
    if ($mode === 'ambiguous') {
        mkdir($workspace.'/resources/lang/en', 0777, true);
    }
    if ($mode === 'json') {
        file_put_contents($workspace.'/lang/en.json', '{"messages.welcome": ["override"]}');
    }
    $narrow = in_array($mode, ['enabled', 'legacy'], true);
    $cases = [
        'literal string' => [
            'return trans("messages.welcome", [], "en");',
            'string',
            $narrow ? [] : ['invalid-return-statement'],
        ],
        'double underscore' => [
            'return __("messages.welcome", [], "en");',
            'string',
            $narrow ? [] : ['invalid-return-statement'],
        ],
        'named nested key' => [
            'return trans(locale: "en", key: "messages.nested.title");',
            'string',
            $narrow ? [] : ['invalid-return-statement'],
        ],
        'wrong expected result' => ['return trans("messages.welcome", [], "en");', 'int', ['invalid-return-statement']],
        'group remains union' => ['return trans("messages.group", [], "en");', 'array|string', []],
        'group cannot become string' => [
            'return trans("messages.group", [], "en");',
            'string',
            ['invalid-return-statement'],
        ],
        'dynamic remains union' => ['return trans("messages.dynamic", [], "en");', 'array|string', []],
        'duplicate respects final entry' => [
            'return trans("messages.duplicate", [], "en");',
            'string',
            ['invalid-return-statement'],
        ],
        'missing key allowed' => ['return trans("messages.missing", [], "en");', 'array|string', []],
        'missing group allowed' => ['return trans("missing.title", [], "en");', 'array|string', []],
        'provider namespace allowed' => ['return trans("package::messages.title", [], "en");', 'array|string', []],
        'implicit locale defers' => ['return trans("messages.welcome");', 'string', ['invalid-return-statement']],
        'dynamic locale defers' => [
            '$locale = (string) random_int(1, 2); return trans("messages.welcome", [], $locale);',
            'array|string',
            [],
        ],
        'dynamic key defers' => ['$key = (string) random_int(1, 2); return trans($key, [], "en");', 'array|string', []],
        'top level execution defers' => [
            'return trans("trap.title", [], "en");',
            'string',
            ['invalid-return-statement'],
        ],
        'path traversal defers' => ['return trans("../messages.welcome", [], "en");', 'array|string', []],
        'unpacked arguments defer' => [
            '$arguments = ["messages.welcome", [], "en"]; return trans(...$arguments);',
            'array|string',
            [],
        ],
        'invalid native argument' => ['trans(123);', 'void', ['invalid-argument']],
        'translator factory native' => ['return trans();', '\\Illuminate\\Contracts\\Translation\\Translator', []],
        'null helper native' => ['return __();', 'null', []],
        'view factory native' => ['return view();', '\\Illuminate\\Contracts\\View\\Factory', []],
        'missing view native' => ['return view("missing.or.provider");', '\\Illuminate\\Contracts\\View\\View', []],
    ];
    $source = "<?php\n";
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= "/**\n * @return ".$return."\n */\n";
        $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = [$name, $codes];
    }
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
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $mode.' '.$name.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$mode.' '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics; inspect '.$workspace);
    }
    if (is_file($workspace.'/'.$catalogRoot.'/en/executed.txt')) {
        throw new RuntimeException('Translation catalog executed.');
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
