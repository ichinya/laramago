<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago macros '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{"extra":{"laramago":{"macro-files":["bootstrap/macros.php"]}}}');
mkdir($workspace.'/bootstrap');
copy(__DIR__.'/fixtures/analysis/macros.php.stub', $workspace.'/framework.php');
file_put_contents($workspace.'/bootstrap/macros.php', <<<'PHP'
    <?php
    use stdClass as Payload;
    MacroBox::macro('label', fn(int $number): string => throw new RuntimeException('Closure executed.'));
    MacroBox::macro('payload', fn(?Payload $value): Payload|null => $value);
    MacroBox::macro('numbers', fn(int ...$values): int => 1);
    MacroBox::macro('staticClosure', static fn(): string => '');
    MacroBox::macro('size', function(string $text, int $extra = 0): int { throw new RuntimeException('Closure executed.'); });
    MacroBox::macro('native', fn(): int => 1);
    DocumentedBox::macro('documented', fn(int $value): int => $value);
    CustomBox::macro('custom', fn(): string => '');
    if ($unknownCondition) { MacroBox::macro('conditional', fn(): string => ''); }
    MacroBox::macro('duplicate', fn(): string => '');
    MacroBox::macro('duplicate', fn(): int => 1);
    MacroBox::macro('untyped', fn($value) => $value);
    DynamicBox::macro('known', fn(): string => '');
    DynamicBox::macro($name, fn(): int => 1);
    MixinBox::macro('known', fn(): string => '');
    MixinBox::mixin(new stdClass);
    FlushedBox::macro('known', fn(): string => '');
    FlushedBox::flushMacros();
    function registerLater(): void { MacroBox::macro('later', fn(): string => ''); }
    throw new RuntimeException('Bootstrap executed.');
    PHP);
$cases = [
    'case-sensitive lookup' => [
        'return MacroBox::LABEL(3);',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'inherited registry deferred' => [
        'return ChildMacroBox::label(3);',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'typed arrow' => ['return MacroBox::label(3);', 'string', []],
    'resolved alias nullable union' => ['return MacroBox::payload(null);', 'stdClass|null', []],
    'variadic parameter' => ['return MacroBox::numbers(1, 2, 3);', 'int', []],
    'variadic wrong parameter' => ["return MacroBox::numbers(1, 'wrong');", 'int', ['invalid-argument']],
    'static closure deferred' => [
        'return (new MacroBox)->staticClosure();',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'typed closure default' => ["return (new MacroBox)->size('abc');", 'int', []],
    'named parameter' => ['return MacroBox::label(number: 3);', 'string', []],
    'wrong parameter' => ["return MacroBox::label('wrong');", 'string', ['invalid-argument']],
    'missing parameter' => ['return MacroBox::label();', 'string', ['too-few-arguments']],
    'wrong return' => ['return MacroBox::label(3);', 'int', ['invalid-return-statement']],
    'native declaration wins' => ['return MacroBox::native();', 'string', []],
    'multiline documentation wins' => ['return DocumentedBox::documented(1);', 'string', []],
    'unknown conditional deferred' => [
        'return MacroBox::conditional();',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'duplicate deferred' => [
        'return MacroBox::duplicate();',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'untyped deferred' => [
        'return MacroBox::untyped(1);',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'custom dispatcher deferred' => [
        'return (new CustomBox)->custom();',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'dynamic name blocks class' => [
        'return DynamicBox::known();',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'mixin blocks class' => [
        'return MixinBox::known();',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'flush blocks class' => [
        'return FlushedBox::known();',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'function body deferred' => [
        'return MacroBox::later();',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
];
$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= "/**\n * @return ".$return."\n */\n";
    $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 1,
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
    throw new RuntimeException('Expected native diagnostics without extension fallback; inspect '.$workspace);
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
    throw new RuntimeException('Unexpected diagnostics; inspect '.$workspace);
}

// Omitting the explicit catalog must retain native unknown-macro diagnostics.
file_put_contents($workspace.'/composer.json', '{}');
file_put_contents($workspace.'/cases.php', '<?php function noOptIn(): string { return MacroBox::label(1); }');
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/absent.json', 'w'],
        2 => ['file', $workspace.'/absent.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/absent.json'), true, flags: JSON_THROW_ON_ERROR);
$codes = array_column($report['issues'] ?? [], 'code');
sort($codes);
if ($exit !== 1 || $codes !== ['mixed-return-statement', 'non-documented-method']) {
    throw new RuntimeException('Absent catalog must preserve native diagnostics; inspect '.$workspace);
}
echo "PASS: absent catalog preserves native diagnostics\n";
