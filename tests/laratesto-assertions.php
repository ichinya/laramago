<?php

declare(strict_types=1);

// Verify flow-sensitive narrowing through the real Mago engine and SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago assertions '.bin2hex(random_bytes(8));
$vendor = $workspace.'/vendor/ichinya/laratesto/src/Testing';
mkdir($vendor, recursive: true);
mkdir($workspace.'/vendor/testo/assert/src/Internal', recursive: true);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$fixture = file_get_contents(__DIR__.'/fixtures/analysis/laratesto-assertions.php.stub');
if ($fixture === false) {
    throw new RuntimeException('Cannot read the assertion fixture.');
}
$assertFixture = file_get_contents(__DIR__.'/fixtures/analysis/testo-assertions.php.stub');
$stateFixture = file_get_contents(__DIR__.'/fixtures/analysis/testo-static-state.php.stub');
if ($assertFixture === false || $stateFixture === false) {
    throw new RuntimeException('Cannot read the delegated assertion fixtures.');
}
$sdkAutoload = getenv('MAGO_SDK_AUTOLOAD');
$autoload = $package.'/vendor/autoload.php';
if (is_string($sdkAutoload) && $sdkAutoload !== '') {
    $autoload = $workspace.'/combined-autoload.php';
    file_put_contents(
        $autoload,
        '<?php require '
        .var_export($package.'/vendor/autoload.php', true)
        .'; require '
        .var_export($sdkAutoload, true)
        .';',
    );
}

$cases = [
    'string' => 'function stringCheck(string|false $value): void { Check::assertIsString($value); acceptString($value); }',
    'named' => 'function namedCheck(string|false $value): void { Check::assertIsString(message: "ok", actual: $value); acceptString($value); }',
    'alias' => 'function aliasCheck(string|false $value): void { \\Laratesto\\Testing\\PhpUnitCompatibility::assertIsString($value); acceptString($value); }',
    'array' => 'function arrayCheck(array|false $value): void { Check::assertIsArray($value); acceptArray($value); }',
    'int' => 'function intCheck(int|false $value): void { Check::assertIsInt($value); acceptInt($value); }',
    'bool' => 'function boolCheck(bool|null $value): void { Check::assertIsBool($value); acceptBool($value); }',
    'object' => 'function objectCheck(\\stdClass|null $value): void { Check::assertIsObject($value); acceptObject($value); }',
    'not-false' => 'function notFalseCheck(int|false $value): void { Check::assertNotFalse($value); acceptInt($value); }',
    'unasserted' => 'function unasserted(string|false $value): void { acceptString($value); }',
    'mutable-property' => 'function mutableProperty(MutableValue $holder): void { Check::assertIsString($holder->value); $holder->reset(); acceptString($holder->value); }',
    'custom-class' => 'function customClass(string|false $value): void { CustomCheck::assertIsString($value); acceptString($value); }',
    'first-class' => 'function firstClass(string|false $value): void { $check = Check::assertIsString(...); acceptString($value); }',
    'unpacked' => 'function unpacked(string|false $value): void { Check::assertIsString(...[$value]); acceptString($value); }',
];
$preamble = <<<'PHP'
    <?php
    use Laratesto\Testing\PhpUnitCompatibility as Check;
    final class CustomCheck { public static function assertIsString(mixed $actual): void {} }
    final class MutableValue { public string|false $value = false; public function reset(): void { $this->value = false; } }
    function acceptString(string $value): void {}
    function acceptArray(array $value): void {}
    function acceptInt(int $value): void {}
    function acceptBool(bool $value): void {}
    function acceptObject(object $value): void {}
    PHP;
$lines = explode("\n", $preamble);
$caseLines = [];
foreach ($cases as $name => $source) {
    $caseLines[count($lines)] = $name;
    $lines[] = $source;
}
file_put_contents($workspace.'/cases.php', implode("\n", $lines)."\n");

$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'vendor/ichinya/laratesto/src/Testing/PhpUnitCompatibility.php',
            'vendor/testo/assert/Assert.php',
            'vendor/testo/assert/src/Internal/StaticState.php',
        ],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                $package.'/bin/laramago-worker.php',
                $autoload,
                $workspace,
            ],
            'workers' => 1,
        ],
    ],
];

$runs = [
    'verified' => ['fixture' => $fixture, 'enabled' => true],
    'changed-body' => [
        'fixture' => str_replace(
            'Assert::true(\\is_string($actual), $message);',
            'Assert::true(true, $message);',
            $fixture,
        ),
        'enabled' => true,
    ],
    'changed-doc' => [
        'fixture' => str_replace(
            'public static function assertIsString',
            '/** @return void */ public static function assertIsString',
            $fixture,
        ),
        'enabled' => true,
    ],
    'non-final' => [
        'fixture' => str_replace('final class PhpUnitCompatibility', 'class PhpUnitCompatibility', $fixture),
        'enabled' => true,
    ],
    'other-origin' => ['fixture' => $fixture, 'enabled' => true, 'path' => 'lookalikes/PhpUnitCompatibility.php'],
    'changed-assert' => [
        'fixture' => $fixture,
        'enabled' => true,
        'assert' => str_replace('$actual === true', '$actual !== true', $assertFixture),
    ],
    'changed-not-same' => [
        'fixture' => $fixture,
        'enabled' => true,
        'assert' => str_replace('$actual !== $expected', '$actual === $expected', $assertFixture),
    ],
    'changed-fail' => [
        'fixture' => $fixture,
        'enabled' => true,
        'state' => str_replace(
            'public static function fail(\\Throwable $failure): never',
            'public static function fail(\\Throwable $failure): void',
            $stateFixture,
        ),
    ],
    'disabled' => ['fixture' => $fixture, 'enabled' => false],
];
foreach ($runs as $mode => $setup) {
    $path = $setup['path'] ?? 'vendor/ichinya/laratesto/src/Testing/PhpUnitCompatibility.php';
    @unlink($vendor.'/PhpUnitCompatibility.php');
    if (! is_dir(dirname($workspace.'/'.$path))) {
        mkdir(dirname($workspace.'/'.$path), recursive: true);
    }
    file_put_contents($workspace.'/'.$path, $setup['fixture']);
    file_put_contents($workspace.'/vendor/testo/assert/Assert.php', $setup['assert'] ?? $assertFixture);
    file_put_contents($workspace.'/vendor/testo/assert/src/Internal/StaticState.php', $setup['state'] ?? $stateFixture);
    $current = $config;
    $current['source']['includes'] = [
        $path,
        'vendor/testo/assert/Assert.php',
        'vendor/testo/assert/src/Internal/StaticState.php',
    ];
    if (! $setup['enabled']) {
        $current['analyzer'] = ['disable-default-plugins' => true];
    }
    file_put_contents($workspace.'/mago.json', json_encode($current, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--config', 'mago.json', 'analyze', '--reporting-format=json'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workspace,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $report = json_decode($output, true);
    if (! is_array($report) || ! isset($report['issues'])) {
        throw new RuntimeException("Mago $mode failed (exit $exit): $output\n$errors");
    }
    $byCase = [];
    foreach ($report['issues'] as $issue) {
        $span = $issue['annotations'][0]['span'] ?? null;
        if (($span['file_id']['name'] ?? null) !== 'cases.php') {
            throw new RuntimeException("Unexpected $mode issue outside cases.php: ".json_encode($issue));
        }
        $case = $caseLines[$span['start']['line'] ?? -1] ?? null;
        if ($case === null) {
            throw new RuntimeException("Unexpected $mode issue on line ".($span['start']['line'] ?? '?'));
        }
        $byCase[$case][] = $issue['code'];
    }
    $narrowed = ['string', 'named', 'alias', 'array', 'int', 'bool', 'object', 'not-false'];
    foreach ($cases as $name => $source) {
        $codes = $byCase[$name] ?? [];
        $shouldNarrow =
            $mode === 'verified'
            || in_array($mode, ['changed-body', 'changed-doc'], true)
            && ! in_array($name, ['string', 'named', 'alias'], true)
            || $mode === 'changed-assert' && $name === 'not-false'
            || $mode === 'changed-not-same' && $name !== 'not-false';
        $shouldNarrow = $shouldNarrow && in_array($name, $narrowed, true);
        $expected = $shouldNarrow
            ? []
            : [in_array($name, ['bool', 'object'], true) ? 'possibly-null-argument' : 'possibly-false-argument'];
        if ($codes !== $expected) {
            throw new RuntimeException("Unexpected $mode diagnostics for $name: ".json_encode($codes));
        }
    }
    echo "$mode: ".count($report['issues'])." expected diagnostics\n";
}
