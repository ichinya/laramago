<?php

declare(strict_types=1);

// Check named-route calls through the real SDK worker without executing fixtures.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago named route contracts '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Routing';
mkdir($framework, 0777, true);
foreach (['UrlGenerator', 'Redirector'] as $class) {
    copy(__DIR__.'/fixtures/analysis/named-route-'.$class.'.php.stub', $framework.'/'.$class.'.php');
}
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'named-routes' => [
                'complete' => true,
                'missing-route-resolver' => false,
                'names' => ['home', 'provider.added'],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
$missing = ['ichinya/laramago/laramago-missing-named-route'];
$cases = [
    'known route' => ['$url->route("home");', 'void', []],
    'provider route listed' => ['$url->route("provider.added");', 'void', []],
    'missing URL route' => ['$url->route("typo");', 'void', $missing],
    'missing redirect route' => ['$redirect->route("typo");', 'void', $missing],
    'named URL argument' => ['$url->route(name: "typo");', 'void', $missing],
    'named redirect argument' => ['$redirect->route(route: "typo");', 'void', $missing],
    'case sensitive' => ['$url->route("Home");', 'void', $missing],
    'dynamic name deferred' => ['$url->route($name);', 'void', []],
    'concatenation deferred' => ['$url->route("home.".$name);', 'void', []],
    'unpacked arguments deferred' => ['$url->route(...["typo"]);', 'void', []],
    'subclass deferred' => ['(new \Illuminate\Routing\ExtendedUrlGenerator)->route("typo");', 'void', []],
    'redirect subclass deferred' => ['(new \Illuminate\Routing\ExtendedRedirector)->route("typo");', 'void', []],
    'unknown method retained' => ['$url->missing();', 'void', ['non-existent-method']],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Routing\UrlGenerator;
    use Illuminate\Routing\Redirector;
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/**'."\n".' * @return '.$return."\n".' */'."\n";
    $source .=
        'function scenario'.count($lines).'(UrlGenerator $url, Redirector $redirect, string $name) { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php',
            'vendor/laravel/framework/src/Illuminate/Routing/Redirector.php',
        ],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 3,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
    throw new RuntimeException('Expected successful analysis with extension warnings and no fallback; inspect '
    .$workspace);
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
    throw new RuntimeException('Unexpected diagnostics outside named-route scenarios; inspect '.$workspace);
}
foreach ([
    'no catalog' => null,
    'incomplete catalog' => ['complete' => false, 'missing-route-resolver' => false, 'names' => []],
    'fallback unspecified' => ['complete' => true, 'names' => []],
    'fallback enabled' => ['complete' => true, 'missing-route-resolver' => true, 'names' => []],
    'malformed names' => ['complete' => true, 'missing-route-resolver' => false, 'names' => ['home', false]],
] as $label => $catalog) {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['named-routes' => $catalog]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/contract.json', 'w'],
            2 => ['file', $workspace.'/contract.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $report = json_decode(file_get_contents($workspace.'/contract.json'), true, flags: JSON_THROW_ON_ERROR);
    if (
        $exit !== 1
        || array_column($report['issues'] ?? [], 'code') !== ['non-existent-method']
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($workspace.'/contract.log'),
        )
    ) {
        throw new RuntimeException($label.': expected native diagnostics only; inspect '.$workspace);
    }
    echo 'PASS: '.$label."\n";
}
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'named-routes' => [
                'complete' => true,
                'missing-route-resolver' => false,
                'names' => ['home', 'provider.added'],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
$configuration = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
unset($configuration['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/disabled.json', 'w'],
        2 => ['file', $workspace.'/disabled.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/disabled.json'), true, flags: JSON_THROW_ON_ERROR);
if ($exit !== 1 || array_column($report['issues'] ?? [], 'code') !== ['non-existent-method']) {
    throw new RuntimeException('Disabled analyzer must leave named-route calls native; inspect '.$workspace);
}
echo "PASS: disabled analyzer leaves named-route calls native\n";
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
