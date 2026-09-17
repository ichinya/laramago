<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago macro contracts '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/bootstrap');
mkdir($workspace.'/app');
copy(__DIR__.'/fixtures/analysis/macro-contracts.php.stub', $workspace.'/framework.php');
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['macro-files' => ['bootstrap/macros.php', 'app/macros.php']]],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/bootstrap/macros.php', <<<'PHP'
    <?php

    OrderedConditionBox::macro('seed', fn (int $value): string => throw new RuntimeException('Closure executed.'));
    if (true) {
        LiteralConditionBox::macro('literalTrue', fn (int $value): string => throw new RuntimeException('Closure executed.'));
    }
    if (false) {
        LiteralConditionBox::macro('literalFalse', fn (): string => throw new RuntimeException('Closure executed.'));
    } else {
        LiteralConditionBox::macro('literalElse', fn (string $value): int => throw new RuntimeException('Closure executed.'));
    }
    if (false) {
        LiteralConditionBox::macro('literalIf', fn (): string => throw new RuntimeException('Closure executed.'));
    } elseif (true) {
        LiteralConditionBox::macro('literalElseIf', fn (float $value): bool => throw new RuntimeException('Closure executed.'));
    }
    if (true && ! false) {
        LiteralConditionBox::macro('literalBoolean', fn (bool $value): float => throw new RuntimeException('Closure executed.'));
    }
    if (true || $runtimeCondition) {
        LiteralConditionBox::macro('shortCircuitOr', fn (string $value): string => throw new RuntimeException('Closure executed.'));
    }
    if (false && $runtimeCondition) {
        LiteralConditionBox::macro('shortCircuitIf', fn (): string => throw new RuntimeException('Closure executed.'));
    } else {
        LiteralConditionBox::macro('shortCircuitElse', fn (int $value): bool => throw new RuntimeException('Closure executed.'));
    }
    if ($runtimeCondition) {
        UnknownConditionBox::macro('unknown', fn (): string => throw new RuntimeException('Closure executed.'));
    }
    DuplicateConditionBox::macro('duplicate', fn (): string => throw new RuntimeException('Closure executed.'));
    DuplicateConditionBox::macro('duplicate', fn (): int => throw new RuntimeException('Closure executed.'));
    FlushedConditionBox::macro('flushed', fn (): string => throw new RuntimeException('Closure executed.'));
    FlushedConditionBox::flushMacros();
    BaseRegistryGuard::macro('seed', fn (): string => throw new RuntimeException('Closure executed.'));
    ChildRegistryGuard::flushMacros();
    throw new RuntimeException('First catalog executed.');
    PHP);
file_put_contents($workspace.'/app/macros.php', <<<'PHP'
    <?php

    if (OrderedConditionBox::hasMacro('seed')) {
        OrderedConditionBox::macro('ordered', fn (float $value): int => throw new RuntimeException('Closure executed.'));
    }
    if (! OrderedConditionBox::hasMacro('seed')) {
        OrderedConditionBox::macro('wrongOrder', fn (): string => throw new RuntimeException('Closure executed.'));
    }
    if (! OrderedConditionBox::hasMacro('guarded')) {
        OrderedConditionBox::macro('guarded', fn (string $value): bool => throw new RuntimeException('Closure executed.'));
    }
    if (! OrderedConditionBox::hasMacro('guarded')) {
        OrderedConditionBox::macro('secondGuard', fn (): string => throw new RuntimeException('Closure executed.'));
    }
    if (OrderedConditionBox::hasMacro('guarded') && true) {
        OrderedConditionBox::macro('knownPresent', fn (bool $value): string => throw new RuntimeException('Closure executed.'));
    }
    if (false || ! OrderedConditionBox::hasMacro('orGuard')) {
        OrderedConditionBox::macro('orGuard', fn (int $value): int => throw new RuntimeException('Closure executed.'));
    }
    if (OrderedConditionBox::hasMacro('missing')) {
        OrderedConditionBox::macro('missingBranch', fn (): string => throw new RuntimeException('Closure executed.'));
    } else {
        OrderedConditionBox::macro('missingElse', fn (): string => throw new RuntimeException('Closure executed.'));
    }
    if (OrderedConditionBox::hasMacro($dynamicName)) {
        OrderedConditionBox::macro('dynamicGuard', fn (): string => throw new RuntimeException('Closure executed.'));
    }
    if (UnknownConditionBox::hasMacro('unknown')) {
        UnknownConditionBox::macro('unknownPresence', fn (): string => throw new RuntimeException('Closure executed.'));
    }
    if (! ForeignConditionGuard::hasMacro('ghost')) {
        OrderedConditionBox::macro('foreignGuard', fn (): string => throw new RuntimeException('Closure executed.'));
    }
    if (! InheritedConditionGuard::hasMacro('ghost')) {
        OrderedConditionBox::macro('inheritedGuard', fn (): string => throw new RuntimeException('Closure executed.'));
    }
    if (BaseRegistryGuard::hasMacro('seed')) {
        OrderedConditionBox::macro('sharedRegistryGuard', fn (): string => throw new RuntimeException('Closure executed.'));
    }
    if (true) {
        NativeConditionBox::macro('native', fn (): int => throw new RuntimeException('Closure executed.'));
        DocumentedConditionBox::macro('documented', fn (int $value): int => throw new RuntimeException('Closure executed.'));
        CustomConditionBox::macro('custom', fn (): string => throw new RuntimeException('Closure executed.'));
        ChildConditionBox::macro('child', fn (): string => throw new RuntimeException('Closure executed.'));
    }
    throw new RuntimeException('Second catalog executed.');
    PHP);

$deferred = ['mixed-return-statement', 'non-documented-method'];
$cases = [
    'literal true branch' => ['return LiteralConditionBox::literalTrue(1);', 'string', []],
    'literal true branch validates arguments' => [
        "return LiteralConditionBox::literalTrue('wrong');",
        'string',
        ['invalid-argument'],
    ],
    'literal false branch is skipped' => ['return LiteralConditionBox::literalFalse();', 'string', $deferred],
    'literal else branch' => ["return LiteralConditionBox::literalElse('value');", 'int', []],
    'literal elseif branch' => ['return LiteralConditionBox::literalElseIf(1.5);', 'bool', []],
    'literal boolean expression' => ['return LiteralConditionBox::literalBoolean(true);', 'float', []],
    'short-circuit or skips unknown operand' => [
        "return LiteralConditionBox::shortCircuitOr('value');",
        'string',
        [],
    ],
    'short-circuit and skips unknown operand' => [
        'return LiteralConditionBox::shortCircuitElse(1);',
        'bool',
        [],
    ],
    'short-circuit skipped branch stays absent' => [
        'return LiteralConditionBox::shortCircuitIf();',
        'string',
        $deferred,
    ],
    'ordered previous-file registration' => ['return OrderedConditionBox::ordered(1.5);', 'int', []],
    'known existing guard skips branch' => ['return OrderedConditionBox::wrongOrder();', 'string', $deferred],
    'known absent guard registers' => ["return OrderedConditionBox::guarded('value');", 'bool', []],
    'new registration changes later guard' => ['return OrderedConditionBox::secondGuard();', 'string', $deferred],
    'known present guard registers' => ['return OrderedConditionBox::knownPresent(true);', 'string', []],
    'boolean hasMacro guard' => ['return OrderedConditionBox::orGuard(1);', 'int', []],
    'known absent guard selects else' => ['return OrderedConditionBox::missingElse();', 'string', []],
    'unknown condition defers' => ['return UnknownConditionBox::unknown();', 'string', $deferred],
    'dynamic hasMacro name defers' => ['return OrderedConditionBox::dynamicGuard();', 'string', $deferred],
    'unknown prior presence defers' => ['return UnknownConditionBox::unknownPresence();', 'string', $deferred],
    'foreign hasMacro implementation defers' => ['return OrderedConditionBox::foreignGuard();', 'string', $deferred],
    'inherited hasMacro registry defers' => ['return OrderedConditionBox::inheritedGuard();', 'string', $deferred],
    'subclass registry mutation invalidates hasMacro guard' => [
        'return OrderedConditionBox::sharedRegistryGuard();',
        'string',
        $deferred,
    ],
    'duplicate remains deferred' => ['return DuplicateConditionBox::duplicate();', 'string', $deferred],
    'flush remains deferred' => ['return FlushedConditionBox::flushed();', 'string', $deferred],
    'native declaration wins' => ['return NativeConditionBox::native();', 'string', []],
    'documentation wins' => ['return DocumentedConditionBox::documented(1);', 'string', []],
    'custom hasMacro dispatcher defers' => ['return CustomConditionBox::custom();', 'string', $deferred],
    'inherited receiver defers' => ['return ChildConditionBox::child();', 'string', $deferred],
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

$analyze = static function (string $report, string $log) use ($command, $workspace): int {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$report, 'w'],
            2 => ['file', $workspace.'/'.$log, 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);

    return proc_close($process);
};

$exit = $analyze('report.json', 'stderr.log');
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Expected native diagnostics without extension fallback; inspect '.$workspace);
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
    throw new RuntimeException('Unexpected diagnostics; inspect '.$workspace);
}

file_put_contents($workspace.'/composer.json', '{}');
file_put_contents(
    $workspace.'/cases.php',
    '<?php function disabledConditionalMacros(): string { return LiteralConditionBox::literalTrue(1); }',
);
$exit = $analyze('disabled.json', 'disabled.log');
$report = json_decode(file_get_contents($workspace.'/disabled.json'), true, flags: JSON_THROW_ON_ERROR);
$codes = array_column($report['issues'] ?? [], 'code');
sort($codes);
if ($exit !== 1 || $codes !== $deferred) {
    throw new RuntimeException('Disabled macro provider must preserve native diagnostics; inspect '.$workspace);
}
echo "PASS: disabled macro provider preserves native diagnostics\n";

file_put_contents(
    $workspace.'/composer.json',
    '{"extra":{"laramago":{"macro-files":"bootstrap/macros.php"}}}',
);
$exit = $analyze('malformed.json', 'malformed.log');
$report = json_decode(file_get_contents($workspace.'/malformed.json'), true, flags: JSON_THROW_ON_ERROR);
$codes = array_column($report['issues'] ?? [], 'code');
sort($codes);
if ($exit !== 1 || $codes !== $deferred) {
    throw new RuntimeException('Malformed macro catalog must fail closed; inspect '.$workspace);
}
echo "PASS: malformed macro catalog fails closed\n";
