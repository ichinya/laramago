<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago http contracts '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/bootstrap');
mkdir($workspace.'/vendor/ichinya/laratesto/src/Testing', recursive: true);
copy(
    __DIR__.'/fixtures/analysis/http-contracts.php.stub',
    $workspace.'/vendor/ichinya/laratesto/src/Testing/LaravelResponse.php',
);
file_put_contents(
    $workspace.'/application.php',
    '<?php throw new RuntimeException("Application bootstrap must never execute.");',
);
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['application.php']],
], JSON_THROW_ON_ERROR));
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => ['vendor/ichinya/laratesto/src/Testing/LaravelResponse.php'],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 2,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$helpers = <<<'PHP'
    <?php
    function acceptArray(array $value): void {}
    function acceptBool(bool $value): void {}
    function acceptMixed(mixed $value): void {}
    function acceptNullableString(?string $value): void {}
    function acceptResponse(\Illuminate\Testing\TestResponse $value): void {}
    function acceptString(string $value): void {}
    PHP;
$parameters =
    '\Illuminate\Testing\TestResponse $response, '
    .'\Laratesto\Testing\LaravelResponse $laratesto, '
    .'ExplicitPageResponse $explicitPage, '
    .'ExplicitPropsResponse $explicitProps, '
    .'DocumentedPageResponse $documented, '
    .'CustomDispatchResponse $custom';
$cases = [
    'page envelope component' => ['acceptString($response->inertiaPage()["component"]);', 'void', []],
    'page envelope props' => ['acceptArray($response->inertiaPage()["props"]);', 'void', []],
    'page envelope url' => ['acceptString($response->inertiaPage()["url"]);', 'void', []],
    'page envelope nullable version' => [
        'acceptNullableString($response->inertiaPage()["version"]);',
        'void',
        [],
    ],
    'page envelope flash' => ['acceptArray($response->inertiaPage()["flash"]);', 'void', []],
    'page envelope rejects wrong field type' => [
        'acceptBool($response->inertiaPage()["component"]);',
        'void',
        ['invalid-argument'],
    ],
    'page macro takes no arguments' => ['$response->inertiaPage("props");', 'void', ['too-many-arguments']],
    'page macro rejects named typo' => [
        '$response->inertiaPage(key: "props");',
        'void',
        ['invalid-named-argument'],
    ],
    'all props are an array' => ['acceptArray($response->inertiaProps());', 'void', []],
    'explicit null returns all props' => ['acceptArray($response->inertiaProps(null));', 'void', []],
    'named null returns all props' => ['acceptArray($response->inertiaProps(propName: null));', 'void', []],
    'selected prop remains unknown' => ['acceptMixed($response->inertiaProps("user.name"));', 'void', []],
    'props reject invalid selector' => ['$response->inertiaProps(1);', 'void', ['invalid-argument']],
    'props reject named typo' => [
        '$response->inertiaProps(property: "user");',
        'void',
        ['invalid-named-argument'],
    ],
    'flash assertion is fluent' => [
        'return $response->assertInertiaFlash("notice")->assertStatus(200);',
        '\Illuminate\Testing\TestResponse',
        [],
    ],
    'flash assertion accepts expected value' => [
        '$response->assertInertiaFlash("notice", ["saved" => true]);',
        'void',
        [],
    ],
    'flash assertion requires string key' => [
        '$response->assertInertiaFlash(1);',
        'void',
        ['invalid-argument'],
    ],
    'flash assertion rejects null key' => [
        '$response->assertInertiaFlash(null);',
        'void',
        ['null-argument'],
    ],
    'flash assertion rejects named typo' => [
        '$response->assertInertiaFlash(name: "notice");',
        'void',
        ['invalid-named-argument'],
    ],
    'missing flash assertion is fluent' => [
        'return $response->assertInertiaFlashMissing("notice")->assertStatus(200);',
        '\Illuminate\Testing\TestResponse',
        [],
    ],
    'missing flash assertion requires key' => [
        '$response->assertInertiaFlashMissing();',
        'void',
        ['too-few-arguments'],
    ],
    'missing flash assertion rejects extra expected value' => [
        '$response->assertInertiaFlashMissing("notice", true);',
        'void',
        ['too-many-arguments'],
    ],
    'explicit page method wins' => ['return $explicitPage->inertiaPage();', 'string', []],
    'explicit props method wins' => ['return $explicitProps->inertiaProps();', 'string', []],
    'documented page method wins' => ['return $documented->inertiaPage();', 'string', []],
    'custom dispatcher remains unknown' => [
        'acceptString($custom->inertiaPage());',
        'void',
        ['mixed-argument', 'non-documented-method'],
    ],
    'laratesto no-argument page envelope' => [
        'acceptString($laratesto->inertiaPage()["component"]);',
        'void',
        [],
    ],
    'laratesto key is not treated as an envelope selector' => [
        'acceptString($laratesto->inertiaPage("component"));',
        'void',
        ['mixed-argument'],
    ],
    'laratesto explicit null still forwards an argument' => [
        'acceptString($laratesto->inertiaPage(null));',
        'void',
        ['mixed-argument'],
    ],
];
check_http_contracts($cases, $command, $workspace, $helpers, $parameters);

$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_http_contracts(
    [
        'disabled page macro remains unknown' => [
            'acceptString($response->inertiaPage());',
            'void',
            ['mixed-argument', 'non-documented-method'],
        ],
        'disabled laratesto page remains mixed' => [
            'acceptString($laratesto->inertiaPage());',
            'void',
            ['mixed-argument'],
        ],
    ],
    $command,
    $workspace,
    $helpers,
    $parameters,
);

unset($config['analyzer']);
$framework = file_get_contents($workspace.'/vendor/ichinya/laratesto/src/Testing/LaravelResponse.php');
file_put_contents(
    $workspace.'/vendor/ichinya/laratesto/src/Testing/LaravelResponse.php',
    str_replace(
        'public function inertiaPage(): \\Closure',
        'public function inertiaPage(string $selector): \\Closure',
        $framework,
    ),
);
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_http_contracts(
    [
        'altered page macro factory is deferred' => [
            'acceptString($response->inertiaPage());',
            'void',
            ['mixed-argument', 'non-documented-method'],
        ],
        'altered page macro defers laratesto refinement' => [
            'acceptString($laratesto->inertiaPage());',
            'void',
            ['mixed-argument'],
        ],
        'unchanged props macro remains typed' => ['acceptArray($response->inertiaProps());', 'void', []],
    ],
    $command,
    $workspace,
    $helpers,
    $parameters,
);
file_put_contents($workspace.'/vendor/ichinya/laratesto/src/Testing/LaravelResponse.php', $framework);

file_put_contents(
    $workspace.'/vendor/ichinya/laratesto/src/Testing/LaravelResponse.php',
    str_replace("'flash' => \$this->flash,", "'messages' => \$this->flash,", $framework),
);
check_http_contracts(
    [
        'same macro API with incompatible page body is deferred' => [
            'acceptString($response->inertiaPage());',
            'void',
            ['mixed-argument'],
        ],
        'incompatible page body defers laratesto envelope' => [
            'acceptString($laratesto->inertiaPage());',
            'void',
            ['mixed-argument'],
        ],
        'incompatible page body does not disable props helper' => [
            'acceptArray($response->inertiaProps());',
            'void',
            [],
        ],
    ],
    $command,
    $workspace,
    $helpers,
    $parameters,
);

file_put_contents(
    $workspace.'/vendor/ichinya/laratesto/src/Testing/LaravelResponse.php',
    str_replace('@return array<string, mixed>', '@return string', $framework),
);
check_http_contracts(
    [
        'incompatible page return PHPDoc is authoritative' => [
            'acceptString($response->inertiaPage());',
            'void',
            ['mixed-argument'],
        ],
        'incompatible page return PHPDoc defers laratesto envelope' => [
            'acceptString($laratesto->inertiaPage());',
            'void',
            ['mixed-argument'],
        ],
    ],
    $command,
    $workspace,
    $helpers,
    $parameters,
);

file_put_contents(
    $workspace.'/vendor/ichinya/laratesto/src/Testing/LaravelResponse.php',
    str_replace(
        "return \$this->frameworkAssertion('inertiaPage', [\$key]);",
        'return [];',
        $framework,
    ),
);
check_http_contracts(
    [
        'standard page remains typed with altered optional wrapper' => [
            'acceptString($response->inertiaPage()["component"]);',
            'void',
            [],
        ],
        'altered optional wrapper body remains mixed' => [
            'acceptString($laratesto->inertiaPage());',
            'void',
            ['mixed-argument'],
        ],
    ],
    $command,
    $workspace,
    $helpers,
    $parameters,
);
file_put_contents($workspace.'/vendor/ichinya/laratesto/src/Testing/LaravelResponse.php', $framework);

file_put_contents($workspace.'/bootstrap/macros.php', <<<'PHP'
    <?php
    \Illuminate\Testing\TestResponse::macro('inertiaProps', fn (): string => 'custom');
    PHP);
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['macro-files' => ['bootstrap/macros.php']]],
], JSON_THROW_ON_ERROR));
check_http_contracts(
    [
        'user props macro remains authoritative' => [
            'acceptString($response->inertiaProps());',
            'void',
            ['mixed-argument', 'non-documented-method'],
        ],
        'unreplaced page macro remains typed' => ['acceptString($response->inertiaPage()["url"]);', 'void', []],
    ],
    $command,
    $workspace,
    $helpers,
    $parameters,
);
file_put_contents($workspace.'/composer.json', '{}');

file_put_contents($workspace.'/bootstrap/macros.php', <<<'PHP'
    <?php
    \Illuminate\Testing\TestResponse::macro('inertiaPage', fn (): string => 'custom');
    PHP);
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['macro-files' => ['bootstrap/macros.php']]],
], JSON_THROW_ON_ERROR));
check_http_contracts(
    [
        'user page macro remains authoritative' => [
            'acceptString($response->inertiaPage());',
            'void',
            ['mixed-argument', 'non-documented-method'],
        ],
        'user page macro defers laratesto envelope' => [
            'acceptString($laratesto->inertiaPage());',
            'void',
            ['mixed-argument'],
        ],
    ],
    $command,
    $workspace,
    $helpers,
    $parameters,
);
file_put_contents($workspace.'/composer.json', '{}');

file_put_contents($workspace.'/bootstrap/macros.php', <<<'PHP'
    <?php
    if (\Illuminate\Testing\TestResponse::hasMacro('inertiaPage')) {
        \Illuminate\Testing\TestResponse::macro('inertiaPage', fn (): string => 'conditional');
    }
    PHP);
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['macro-files' => ['bootstrap/macros.php']]],
], JSON_THROW_ON_ERROR));
check_http_contracts(
    [
        'unproven conditional page replacement is deferred' => [
            'acceptString($response->inertiaPage());',
            'void',
            ['mixed-argument', 'non-documented-method'],
        ],
        'conditional page replacement defers laratesto envelope' => [
            'acceptString($laratesto->inertiaPage());',
            'void',
            ['mixed-argument'],
        ],
    ],
    $command,
    $workspace,
    $helpers,
    $parameters,
);
file_put_contents($workspace.'/composer.json', '{}');

file_put_contents(
    $workspace.'/vendor/ichinya/laratesto/src/Testing/LaravelResponse.php',
    str_replace(
        'public function inertiaPage(?string $key = null): mixed',
        '/** @return string */ public function inertiaPage(?string $key = null): mixed',
        $framework,
    ),
);
check_http_contracts(
    [
        'laratesto return PHPDoc wins' => ['return $laratesto->inertiaPage();', 'string', []],
        'laratesto return PHPDoc rejects envelope replacement' => [
            'acceptBool($laratesto->inertiaPage());',
            'void',
            ['invalid-argument'],
        ],
    ],
    $command,
    $workspace,
    $helpers,
    $parameters,
);

file_put_contents(
    $workspace.'/vendor/ichinya/laratesto/src/Testing/LaravelResponse.php',
    str_replace('class TestResponseMacros', 'class MissingTestResponseMacros', $framework),
);
check_http_contracts(
    [
        'missing Inertia macro package leaves page unknown' => [
            'acceptString($response->inertiaPage());',
            'void',
            ['mixed-argument', 'non-documented-method'],
        ],
        'missing Inertia macro package leaves laratesto mixed' => [
            'acceptString($laratesto->inertiaPage());',
            'void',
            ['mixed-argument'],
        ],
    ],
    $command,
    $workspace,
    $helpers,
    $parameters,
);

if (is_file($workspace.'/.env')) {
    throw new RuntimeException('The offline fixture must not have an environment file.');
}
$resolvedWorkspace = realpath($workspace);
if ($resolvedWorkspace === false) {
    throw new RuntimeException('Cannot resolve the test workspace for cleanup.');
}
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($resolvedWorkspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($files as $file) {
    $resolvedFile = realpath($file->getPathname());
    if ($resolvedFile === false || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);

/**
 * @param array<string, array{string, string, list<string>}> $cases
 * @param list<string> $command
 */
function check_http_contracts(
    array $cases,
    array $command,
    string $workspace,
    string $helpers,
    string $parameters,
): void {
    $source = $helpers;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .= 'function scenario'.count($lines).'('.$parameters.') { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = [$name, $codes];
    }
    file_put_contents($workspace.'/cases.php', $source);
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
        throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '
        .$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
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
        throw new RuntimeException('Unexpected diagnostics outside HTTP contract scenarios; inspect '.$workspace);
    }
}
