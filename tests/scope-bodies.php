<?php

declare(strict_types=1);

// Check local scope signatures and return types through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago scope bodies '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/scope-bodies.php.stub', $workspace.'/models.php');
$changedBuilder = in_array('--changed-builder', $argv, true);
if ($changedBuilder) {
    $framework = file_get_contents($workspace.'/framework.php');
    $framework = str_replace('@return $this', '@return string', $framework);
    file_put_contents($workspace.'/framework.php', $framework);
}
$disabled = in_array('--disabled', $argv, true);
$macroForwarding = in_array('--macro-forwarding', $argv, true);
if ($macroForwarding) {
    mkdir($workspace.'/app');
    file_put_contents($workspace.'/app/macros.php', <<<'PHP'
        <?php
        Illuminate\Database\Eloquent\Builder::macro('orderBy', static fn (): string => 'custom');
        PHP);
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['macro-files' => ['app/macros.php']]],
    ], JSON_THROW_ON_ERROR));
}
$unknown = ['mixed-return-statement'];
$cases = [
    'simple where' => ['return BodyScopeRecord::active();', 'Builder<BodyScopeRecord>', []],
    'forwarded sorting' => ['return BodyScopeRecord::ordered();', 'Builder<BodyScopeRecord>', []],
    'forwarded range' => ['return BodyScopeRecord::between(1, 3);', 'Builder<BodyScopeRecord>', []],
    'forwarded predicates' => ['return BodyScopeRecord::listed();', 'Builder<BodyScopeRecord>', []],
    'forwarded date predicates' => ['return BodyScopeRecord::dated(2026);', 'Builder<BodyScopeRecord>', []],
    'forwarded column predicates' => ['return BodyScopeRecord::columns();', 'Builder<BodyScopeRecord>', []],
    'safe array argument' => ['return BodyScopeRecord::arrayWhere();', 'Builder<BodyScopeRecord>', []],
    'array call stays unknown' => ['return BodyScopeRecord::arrayEscape();', 'Builder<BodyScopeRecord>', $unknown],
    'array reference stays unknown' => [
        'return BodyScopeRecord::arrayReference(1);',
        'Builder<BodyScopeRecord>',
        $unknown,
    ],
    'array unpack stays unknown' => ['return BodyScopeRecord::arrayUnpack([]);', 'Builder<BodyScopeRecord>', $unknown],
    'child scope shadows forwarding' => [
        'return ShadowedBodyScopeRecord::ordered();',
        'Builder<ShadowedBodyScopeRecord>',
        $unknown,
    ],
    'child method shadows forwarding' => [
        'return DirectShadowedBodyScopeRecord::ordered();',
        'Builder<DirectShadowedBodyScopeRecord>',
        $unknown,
    ],
    'PHPDoc shadows forwarding' => [
        'return DocumentedForwardBodyScopeRecord::ordered();',
        'Builder<DocumentedForwardBodyScopeRecord>',
        $unknown,
    ],
    'inherited forwarding' => ['return ChildBodyScopeRecord::ordered();', 'Builder<ChildBodyScopeRecord>', []],
    'forwarded result remains typed' => ['return BodyScopeRecord::listed();', 'string', ['invalid-return-statement']],
    'backed enum value' => ['return BodyScopeRecord::enumValue();', 'Builder<BodyScopeRecord>', []],
    'unit enum name' => ['return BodyScopeRecord::enumName();', 'Builder<BodyScopeRecord>', []],
    'guarded backed enum' => [
        'return BodyScopeRecord::byStatus(BodyScopeStatus::Active);',
        'Builder<BodyScopeRecord>',
        [],
    ],
    'guarded string fallback' => ['return BodyScopeRecord::byStatus("active");', 'Builder<BodyScopeRecord>', []],
    'guarded unit enum' => ['return BodyScopeRecord::byFlag(BodyScopeFlag::Visible);', 'Builder<BodyScopeRecord>', []],
    'guarded enum chain' => ['return BodyScopeRecord::byStatus("active")->findOrFail(1);', 'BodyScopeRecord', []],
    'guarded wrong argument' => ['BodyScopeRecord::byStatus(42);', 'void', ['invalid-argument']],
    'guarded wrong return' => ['return BodyScopeRecord::byStatus("active");', 'string', ['invalid-return-statement']],
    'guarded unit value' => ['return BodyScopeRecord::badUnit("x");', 'Builder<BodyScopeRecord>', $unknown],
    'scalar branch' => ['return BodyScopeRecord::scalarBranch("x");', 'Builder<BodyScopeRecord>', $unknown],
    'scalar fallback' => ['return BodyScopeRecord::scalarFallback("x");', 'Builder<BodyScopeRecord>', $unknown],
    'guard does not leak' => ['return BodyScopeRecord::leakedGuard("x");', 'Builder<BodyScopeRecord>', $unknown],
    'branch mutation' => ['return BodyScopeRecord::mutatedBranch("x");', 'Builder<BodyScopeRecord>', $unknown],
    'non enum guard' => ['return BodyScopeRecord::classGuard("x");', 'Builder<BodyScopeRecord>', $unknown],
    'query is not a value parameter' => ['return BodyScopeRecord::queryGuard();', 'Builder<BodyScopeRecord>', $unknown],
    'enum scope chain' => ['return BodyScopeRecord::enumValue()->findOrFail(1);', 'BodyScopeRecord', []],
    'enum scope wrong result' => ['return BodyScopeRecord::enumValue();', 'string', ['invalid-return-statement']],
    'unknown enum case' => ['return BodyScopeRecord::missingCase();', 'Builder<BodyScopeRecord>', $unknown],
    'unit enum value' => ['return BodyScopeRecord::unitValue();', 'Builder<BodyScopeRecord>', $unknown],
    'non enum constant property' => ['return BodyScopeRecord::magicValue();', 'Builder<BodyScopeRecord>', $unknown],
    'literal parameter chain' => ['return BodyScopeRecord::named("test");', 'Builder<BodyScopeRecord>', []],
    'builder chain' => ['return BodyScopeRecord::query()->active()->findOrFail(1);', 'BodyScopeRecord', []],
    'direct identity' => ['return BodyScopeRecord::identity();', 'Builder<BodyScopeRecord>', []],
    'prepared typed scope preserves model' => [
        'return BodyScopeRecord::query()->prepared("2026-01-01")->first();',
        'BodyScopeRecord|null',
        [],
    ],
    'prepared typed scope remains generic' => [
        'return BodyScopeRecord::query()->prepared("2026-01-01");',
        'Builder<BodyScopeRecord>',
        [],
    ],
    'prepared query alias defers' => [
        'return BodyScopeRecord::query()->preparedEscaped("2026-01-01");',
        'Builder<BodyScopeRecord>',
        ['less-specific-return-statement'],
    ],
    'prepared closure capture defers' => [
        'return BodyScopeRecord::query()->preparedCaptured("2026-01-01");',
        'Builder<BodyScopeRecord>',
        ['less-specific-return-statement'],
    ],
    'typed scope PHPDoc wins' => [
        'return BodyScopeRecord::query()->documented();',
        'Builder<ChildBodyScopeRecord>',
        [],
    ],
    'inherited scope' => ['return ChildBodyScopeRecord::active();', 'Builder<ChildBodyScopeRecord>', []],
    'trait scope' => ['return BodyScopeRecord::visible();', 'Builder<BodyScopeRecord>', []],
    'untyped scalar' => ['return BodyScopeRecord::scalar();', 'Builder<BodyScopeRecord>', $unknown],
    'unknown method' => ['return BodyScopeRecord::unknown();', 'Builder<BodyScopeRecord>', $unknown],
    'mutated query' => ['return BodyScopeRecord::mutated();', 'Builder<BodyScopeRecord>', $unknown],
    'argument assignment' => ['return BodyScopeRecord::assignment();', 'Builder<BodyScopeRecord>', $unknown],
    'escaped query' => ['return BodyScopeRecord::escaped();', 'Builder<BodyScopeRecord>', $unknown],
    'closure reference' => ['return BodyScopeRecord::closure();', 'Builder<BodyScopeRecord>', $unknown],
    'array mutation' => ['return BodyScopeRecord::arrayMutation();', 'Builder<BodyScopeRecord>', $unknown],
    'explicit mixed' => ['return BodyScopeRecord::mixed();', 'Builder<BodyScopeRecord>', $unknown],
    'explicit scalar' => ['return BodyScopeRecord::typed();', 'string', []],
    'model PHPDoc' => ['return DocumentedBodyScopeRecord::active();', 'string', []],
    'custom builder' => ['CustomBodyScopeRecord::active();', 'void', ['non-documented-method']],
];
if ($macroForwarding) {
    $cases = [
        'macro shadows forwarded sorting' => [
            'return BodyScopeRecord::ordered();',
            'Builder<BodyScopeRecord>',
            $unknown,
        ],
        'macro leaves other forwarding intact' => [
            'return BodyScopeRecord::between(1, 3);',
            'Builder<BodyScopeRecord>',
            [],
        ],
        'macro leaves native method intact' => ['return BodyScopeRecord::active();', 'Builder<BodyScopeRecord>', []],
        'negative result control' => ['return BodyScopeRecord::active();', 'string', ['invalid-return-statement']],
    ];
}
if ($changedBuilder) {
    $cases = [
        'changed native return' => ['return BodyScopeRecord::active();', 'Builder<BodyScopeRecord>', $unknown],
        'changed forwarded return' => ['return BodyScopeRecord::ordered();', 'Builder<BodyScopeRecord>', $unknown],
        'changed typed scope return' => [
            'return BodyScopeRecord::query()->prepared("2026-01-01");',
            'Builder<BodyScopeRecord>',
            ['less-specific-return-statement'],
        ],
        'changed guarded native return' => [
            'return BodyScopeRecord::byStatus("active");',
            'Builder<BodyScopeRecord>',
            $unknown,
        ],
    ];
}
if ($disabled) {
    $cases = [
        'disabled scope body' => [
            'return BodyScopeRecord::active();',
            'Builder<BodyScopeRecord>',
            ['mixed-return-statement', 'non-documented-method'],
        ],
        'disabled forwarded body' => [
            'return BodyScopeRecord::ordered();',
            'Builder<BodyScopeRecord>',
            ['mixed-return-statement', 'non-documented-method'],
        ],
        'disabled guarded body' => [
            'return BodyScopeRecord::byStatus("active");',
            'Builder<BodyScopeRecord>',
            ['mixed-return-statement', 'non-documented-method'],
        ],
    ];
}
$source = <<<'PHP'
    <?php
    use Illuminate\Database\Eloquent\Builder;
    PHP;
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
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
    'extension-hosts' => $disabled
        ? new stdClass
        : [
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
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
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
    throw new RuntimeException('Unexpected diagnostics outside scope scenarios; inspect '.$workspace);
}
$resolvedWorkspace = realpath($workspace);
if ($macroForwarding) {
    unlink($workspace.'/app/macros.php');
    rmdir($workspace.'/app');
}
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
if (! $disabled && ! $changedBuilder && ! $macroForwarding) {
    $macroProcess = proc_open([PHP_BINARY, __FILE__, '--macro-forwarding'], [STDIN, STDOUT, STDERR], $macroPipes);
    if (! is_resource($macroProcess) || proc_close($macroProcess) !== 0) {
        throw new RuntimeException('Macro forwarding boundary scenarios failed.');
    }
}
