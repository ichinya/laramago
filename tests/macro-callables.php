<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago macro callables '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{"extra":{"laramago":{"macro-files":["bootstrap/macros.php"]}}}');
mkdir($workspace.'/bootstrap');
copy(__DIR__.'/fixtures/analysis/macro-callables.php.stub', $workspace.'/framework.php');
file_put_contents($workspace.'/bootstrap/macros.php', <<<'PHP'
    <?php
    use MacroFixtures\Handler as CatalogHandler;
    use MacroFixtures\InvokableHandler as CatalogInvoker;
    use MacroFixtures\NotInvokable;

    CallableMacroBox::macro('label', [CatalogHandler::class, 'label']);
    CallableMacroBox::macro('numbers', [CatalogHandler::class, 'numbers']);
    CallableMacroBox::macro('mutate', [CatalogHandler::class, 'mutate']);
    CallableMacroBox::macro('closureReference', fn (int &$value): int => ++$value);
    CallableMacroBox::macro('invoke', new CatalogInvoker('value:'));
    CallableMacroBox::macro('instanceArray', [CatalogHandler::class, 'instanceOnly']);
    CallableMacroBox::macro('hidden', [CatalogHandler::class, 'hidden']);
    CallableMacroBox::macro('untyped', [CatalogHandler::class, 'untyped']);
    CallableMacroBox::macro('generic', [CatalogHandler::class, 'generic']);
    CallableMacroBox::macro('missing', [CatalogHandler::class, 'missing']);
    CallableMacroBox::macro('notInvokable', new NotInvokable);
    CallableMacroBox::macro('objectArray', [new CatalogHandler, 'instanceOnly']);
    CallableMacroBox::macro('firstClass', CatalogHandler::label(...));
    CallableMacroBox::macro('stringCallable', CatalogHandler::class.'::label');
    CallableMacroBox::macro('native', [CatalogHandler::class, 'numbers']);
    DocumentedCallableBox::macro('documented', [CatalogHandler::class, 'numbers']);
    CustomCallableBox::macro('custom', [CatalogHandler::class, 'label']);
    if ($unknownCondition) { CallableMacroBox::macro('conditional', [CatalogHandler::class, 'label']); }
    DuplicateCallableBox::macro('duplicate', [CatalogHandler::class, 'label']);
    DuplicateCallableBox::macro('duplicate', [CatalogHandler::class, 'numbers']);
    FlushedCallableBox::macro('flushed', [CatalogHandler::class, 'label']);
    FlushedCallableBox::flushMacros();
    throw new RuntimeException('Bootstrap executed.');
    PHP);
$deferred = ['mixed-return-statement', 'non-documented-method'];
$cases = [
    'literal static callable array' => ["return CallableMacroBox::label(3, '?');", 'string', []],
    'literal static callable instance dispatch' => ['return (new CallableMacroBox)->label(3);', 'string', []],
    'literal static callable named parameter' => [
        "return CallableMacroBox::label(number: 3, suffix: '?');",
        'string',
        [],
    ],
    'literal static callable wrong parameter' => [
        "return CallableMacroBox::label('wrong');",
        'string',
        ['invalid-argument'],
    ],
    'literal static callable variadic' => ['return CallableMacroBox::numbers(1, 2, 3);', 'int', []],
    'literal static callable wrong variadic parameter' => [
        "return CallableMacroBox::numbers(1, 'wrong');",
        'int',
        ['invalid-argument'],
    ],
    'invokable object' => ['return CallableMacroBox::invoke(1.5);', 'string', []],
    'reference callable defers' => ['return CallableMacroBox::mutate(1);', 'int', $deferred],
    'reference closure defers' => ['return CallableMacroBox::closureReference(1);', 'int', $deferred],
    'invokable object named default' => [
        'return CallableMacroBox::invoke(value: 1.5, strict: true);',
        'string',
        [],
    ],
    'invokable object wrong parameter' => [
        "return CallableMacroBox::invoke('wrong');",
        'string',
        ['invalid-argument'],
    ],
    'instance method array deferred' => ['return CallableMacroBox::instanceArray(1);', 'string', $deferred],
    'non-public static method deferred' => ['return CallableMacroBox::hidden(1);', 'string', $deferred],
    'untyped callable deferred' => ['return CallableMacroBox::untyped(1);', 'string', $deferred],
    'generic callable deferred' => ['return CallableMacroBox::generic(1);', 'string', $deferred],
    'missing static method deferred' => ['return CallableMacroBox::missing(1);', 'string', $deferred],
    'non-invokable object deferred' => ['return CallableMacroBox::notInvokable();', 'string', $deferred],
    'object method array deferred' => ['return CallableMacroBox::objectArray(1);', 'string', $deferred],
    'first-class closure deferred' => ['return CallableMacroBox::firstClass(1);', 'string', $deferred],
    'string callable deferred' => ['return CallableMacroBox::stringCallable(1);', 'string', $deferred],
    'native declaration wins' => ['return CallableMacroBox::native();', 'string', []],
    'documentation wins' => ['return DocumentedCallableBox::documented(1);', 'string', []],
    'custom dispatcher defers' => ['return (new CustomCallableBox)->custom(1);', 'string', $deferred],
    'unknown conditional callable deferred' => ['return CallableMacroBox::conditional(1);', 'string', $deferred],
    'duplicate callable deferred' => ['return DuplicateCallableBox::duplicate(1);', 'string', $deferred],
    'flush invalidates callable class' => ['return FlushedCallableBox::flushed(1);', 'string', $deferred],
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
    '<?php function noCallableOptIn(): string { return CallableMacroBox::label(1); }',
);
$exit = $analyze('absent.json', 'absent.log');
$report = json_decode(file_get_contents($workspace.'/absent.json'), true, flags: JSON_THROW_ON_ERROR);
$codes = array_column($report['issues'] ?? [], 'code');
sort($codes);
if ($exit !== 1 || $codes !== $deferred) {
    throw new RuntimeException('Absent catalog must preserve native diagnostics; inspect '.$workspace);
}
echo "PASS: absent callable catalog preserves native diagnostics\n";
