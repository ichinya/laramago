<?php

declare(strict_types=1);

// Check bounded magic facade calls through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago facade calls '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/facade-calls.php.stub', $workspace.'/facades.php');
$cases = [
    'literal accessor call' => ['return LiteralCallFacade::label();', 'string', []],
    'inherited facade call' => ['return InheritedCallFacade::label("child");', 'string', []],
    'interface accessor call' => ['return InterfaceCallFacade::enabled(1);', 'bool', []],
    'nullable service result' => ['return LiteralCallFacade::count();', 'int|null', []],
    'late static result uses service' => ['return LiteralCallFacade::fluent();', 'FacadeService', []],
    'late static result chain' => ['return LiteralCallFacade::fluent()->label();', 'string', []],
    'named argument' => ['return LiteralCallFacade::active(enabled: true);', 'bool', []],
    'wrong argument retained' => ['LiteralCallFacade::active("yes");', 'void', ['invalid-argument']],
    'missing argument retained' => ['LiteralCallFacade::active();', 'void', ['too-few-arguments']],
    'extra argument retained' => ['LiteralCallFacade::label("a", "b");', 'void', ['too-many-arguments']],
    'wrong return retained' => ['return LiteralCallFacade::label();', 'int', ['invalid-return-statement']],
    'method typo retained' => ['LiteralCallFacade::lable();', 'void', ['non-documented-method']],
    'alias accessor deferred' => ['AliasCallFacade::label();', 'void', ['non-documented-method']],
    'dynamic accessor deferred' => ['DynamicCallFacade::label();', 'void', ['non-documented-method']],
    'multiple statements deferred' => ['MultipleCallFacade::label();', 'void', ['non-documented-method']],
    'custom resolver deferred' => ['CustomResolverCallFacade::label();', 'void', ['non-documented-method']],
    'custom dispatcher deferred' => ['CustomDispatcherCallFacade::label();', 'void', ['non-documented-method']],
    'native facade method preserved' => ['return NativeCallFacade::label();', 'int', []],
    'documented facade method preserved' => ['return DocumentedCallFacade::label();', 'int', []],
    'protected service method deferred' => ['LiteralCallFacade::internal();', 'void', ['non-documented-method']],
    'private service method deferred' => ['LiteralCallFacade::secret();', 'void', ['non-documented-method']],
    'static service method deferred' => ['LiteralCallFacade::staticLabel();', 'void', ['non-documented-method']],
    'generic service method deferred' => ['LiteralCallFacade::mirror("value");', 'void', ['non-documented-method']],
    'generic service class deferred' => ['GenericServiceCallFacade::value();', 'void', ['non-documented-method']],
    'bound generic ancestor deferred' => [
        'BoundGenericCallFacade::inheritedValue();',
        'void',
        ['non-documented-method'],
    ],
    'nested contextual return deferred' => [
        'return LiteralCallFacade::copies();',
        'array',
        ['mixed-return-statement'],
    ],
    'by-reference service method deferred' => [
        '$value = "before"; LiteralCallFacade::touch($value);',
        'void',
        ['non-documented-method'],
    ],
    'first class callable' => ['return LiteralCallFacade::label(...);', 'Closure', []],
];
$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'facades.php']],
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
    throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '.$workspace);
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
    throw new RuntimeException('Unexpected diagnostics outside facade call scenarios; inspect '.$workspace);
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
    unlink($resolvedFile);
}
rmdir($workspace);
