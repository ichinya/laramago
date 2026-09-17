<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago http tests '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/bootstrap');
copy(__DIR__.'/fixtures/analysis/http-tests-framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/http-tests-inertia.php.stub', $workspace.'/inertia.php');
copy(__DIR__.'/fixtures/analysis/http-tests-macro.php.stub', $workspace.'/bootstrap/macros.php');
file_put_contents(
    $workspace.'/application.php',
    '<?php throw new RuntimeException("Application bootstrap must never execute.");',
);

$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'inertia.php']],
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
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['application.php']],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$cases = [
    'inertia callback and fluent response return' => [
        'return $response->assertInertia(fn ($page) => acceptInertia($page))->assertStatus(200);',
        '\\Illuminate\\Testing\\TestResponse',
        [],
    ],
    'inertia callback rejects unrelated type' => [
        '$response->assertInertia(fn ($page) => acceptResponse($page));',
        'void',
        ['invalid-argument'],
    ],
    'nested inertia has and first scopes' => [
        '$response->assertInertia(fn ($page) => $page->has("users", fn ($users) => $users->first(fn ($user) => acceptInertia($user))));',
        'void',
        [],
    ],
    'nested inertia scope rejects response' => [
        '$response->assertInertia(fn ($page) => $page->has("users", fn ($users) => acceptResponse($users)));',
        'void',
        ['invalid-argument'],
    ],
    'length callback scope' => [
        '$response->assertInertia(fn ($page) => $page->has("users", 1, fn ($user) => acceptInertia($user)));',
        'void',
        [],
    ],
    'each callback scope' => [
        '$response->assertInertia(fn ($page) => $page->each(fn ($item) => acceptInertia($item)));',
        'void',
        [],
    ],
    'json callback scope' => [
        '$response->assertJson(fn ($json) => acceptJson($json));',
        'void',
        [],
    ],
    'nested json scope' => [
        '$response->assertJson(fn ($json) => $json->has("users", fn ($users) => acceptJson($users)));',
        'void',
        [],
    ],
    'json array contract preserved' => [
        '$response->assertJson(["ok" => true], true);',
        'void',
        [],
    ],
    'reload callback scope' => [
        '$page->reload(fn ($fresh) => acceptInertia($fresh));',
        'void',
        [],
    ],
    'reload only callback scope' => [
        '$page->reloadOnly("users", fn ($fresh) => acceptInertia($fresh));',
        'void',
        [],
    ],
    'reload except callback scope' => [
        '$page->reloadExcept(["secret"], fn ($fresh) => acceptInertia($fresh));',
        'void',
        [],
    ],
    'deferred callback first argument' => [
        '$page->loadDeferredProps(fn ($fresh) => acceptInertia($fresh));',
        'void',
        [],
    ],
    'deferred groups callback' => [
        '$page->loadDeferredProps(["analytics"], fn ($fresh) => acceptInertia($fresh));',
        'void',
        [],
    ],
    'invalid inertia callback value' => [
        '$response->assertInertia(123);',
        'void',
        ['invalid-argument'],
    ],
    'named inertia callback' => [
        '$response->assertInertia(callback: fn ($page) => acceptInertia($page));',
        'void',
        [],
    ],
    'nullable inertia callback' => [
        'return $response->assertInertia();',
        '\\Illuminate\\Testing\\TestResponse',
        [],
    ],
    'explicit method wins' => [
        'return $explicit->assertInertia(fn ($value) => acceptMixed($value));',
        'string',
        [],
    ],
    'documented method wins' => [
        'return $documented->assertInertia(fn ($value) => acceptMixed($value));',
        'string',
        [],
    ],
    'custom dispatch remains unknown' => [
        'acceptString($custom->assertInertia(fn ($value) => acceptMixed($value)));',
        'void',
        ['mixed-argument', 'non-documented-method'],
    ],
    'altered response contract wins' => [
        'return $alteredResponse->assertJson(fn ($value) => acceptMixed($value));',
        'string',
        [],
    ],
    'altered fluent contract wins' => [
        'return $alteredScope->first(fn ($value) => acceptMixed($value));',
        'string',
        [],
    ],
    'required first callback preserved' => [
        '$page->first();',
        'void',
        ['too-few-arguments'],
    ],
    'first callback rejects null' => [
        '$page->first(null);',
        'void',
        ['null-argument'],
    ],
    'each callback rejects null' => [
        '$page->each(null);',
        'void',
        ['null-argument'],
    ],
    'required deferred selector preserved' => [
        '$page->loadDeferredProps();',
        'void',
        ['too-few-arguments'],
    ],
];
check_http_tests($cases, $command, $workspace);

$framework = file_get_contents($workspace.'/framework.php');
file_put_contents(
    $workspace.'/framework.php',
    str_replace('function first(\\Closure $callback)', 'function first(string $callback)', $framework),
);
check_http_tests(
    [
        'changed framework parameter remains authoritative' => ['$page->first("native");', 'void', []],
        'changed framework parameter rejects old callback' => [
            '$page->first(fn () => null);',
            'void',
            ['possibly-invalid-argument'],
        ],
    ],
    $command,
    $workspace,
);
file_put_contents($workspace.'/framework.php', $framework);

$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_http_tests(
    [
        'disabled inertia callback remains unknown' => [
            '$response->assertInertia(fn ($page) => acceptInertia($page));',
            'void',
            ['mixed-argument', 'non-documented-method'],
        ],
        'disabled json callback remains broad' => [
            '$response->assertJson(fn ($json) => acceptJson($json));',
            'void',
            ['mixed-argument'],
        ],
    ],
    $command,
    $workspace,
);

unset($config['analyzer']);
$config['source']['includes'] = ['framework.php'];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_http_tests(
    [
        'missing Inertia package remains unknown' => [
            'acceptString($response->assertInertia(fn ($page) => acceptMixed($page)));',
            'void',
            ['mixed-argument', 'non-documented-method'],
        ],
    ],
    $command,
    $workspace,
    false,
);

$config['source']['includes'] = ['framework.php', 'inertia.php'];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['macro-files' => ['bootstrap/macros.php']]],
], JSON_THROW_ON_ERROR));
check_http_tests(
    [
        'user macro override remains authoritative' => [
            'acceptString($response->assertInertia(1));',
            'void',
            ['mixed-argument', 'non-documented-method'],
        ],
    ],
    $command,
    $workspace,
);

if (is_file($workspace.'/.env')) {
    throw new RuntimeException('The offline fixture must not have an environment file.');
}
$resolvedWorkspace = realpath($workspace);
foreach (glob($workspace.'/*') ?: [] as $file) {
    $resolvedFile = realpath($file);
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    if (is_dir($resolvedFile)) {
        foreach (glob($resolvedFile.'/*') ?: [] as $nested) {
            unlink($nested);
        }
        rmdir($resolvedFile);
    } else {
        unlink($resolvedFile);
    }
}
rmdir($workspace);

/**
 * @param array<string, array{string, string, list<string>}> $cases
 * @param list<string> $command
 */
function check_http_tests(array $cases, array $command, string $workspace, bool $includeInertiaTypes = true): void
{
    if ($includeInertiaTypes) {
        $source = <<<'PHP'
            <?php
            function acceptInertia(\Inertia\Testing\AssertableInertia $value): void {}
            function acceptJson(\Illuminate\Testing\Fluent\AssertableJson $value): void {}
            function acceptResponse(\Illuminate\Testing\TestResponse $value): void {}
            function acceptString(string $value): void {}
            function acceptMixed(mixed $value): void {}
            PHP;
    } else {
        $source = <<<'PHP'
            <?php
            function acceptString(string $value): void {}
            function acceptMixed(mixed $value): void {}
            PHP;
    }
    $parameters = $includeInertiaTypes
        ? '\\Illuminate\\Testing\\TestResponse $response, '
        .'\\Inertia\\Testing\\AssertableInertia $page, '
        .'ExplicitInertiaResponse $explicit, '
        .'DocumentedInertiaResponse $documented, '
        .'CustomDispatchResponse $custom, '
        .'AlteredJsonResponse $alteredResponse, '
        .'AlteredInertiaScope $alteredScope'
        : '\\Illuminate\\Testing\\TestResponse $response';
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
        throw new RuntimeException('Unexpected diagnostics outside HTTP test scenarios; inspect '.$workspace);
    }
}
