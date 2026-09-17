<?php

declare(strict_types=1);

// Check local scope signatures and return types through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago scopes '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/scopes.php.stub', $workspace.'/models.php');
$cases = [
    'legacy scope' => ['return ScopeRecord::active();', 'Builder<ScopeRecord>', []],
    'scope chain' => ['return ScopeRecord::active()->findOrFail(1);', 'ScopeRecord', []],
    'builder scope' => ['return ScopeRecord::query()->active();', 'Builder<ScopeRecord>', []],
    'instance scope' => ['return (new ScopeRecord)->active();', 'Builder<ScopeRecord>', []],
    'inherited scope' => ['return ChildScopeRecord::active();', 'Builder<ChildScopeRecord>', []],
    'trait scope' => ['return TraitScopeRecord::visible();', 'Builder<TraitScopeRecord>', []],
    'named argument' => ['return ScopeRecord::named(name: "test");', 'Builder<ScopeRecord>', []],
    'variadic scope' => ['return ScopeRecord::tags("one", "two");', 'Builder<ScopeRecord>', []],
    'scalar result' => ['return ScopeRecord::total();', 'int', []],
    'nullable result' => ['return ScopeRecord::maybe();', 'int|Builder<ScopeRecord>', []],
    'explicit builder result' => ['return ScopeRecord::explicit();', 'Builder<ScopeRecord>', []],
    'direct attribute scope stays native' => [
        'ScopeRecord::recent();',
        'void',
        ['invalid-method-access', 'invalid-static-method-access'],
    ],
    'attribute builder scope' => ['return ScopeRecord::query()->recent(days: 2);', 'Builder<ScopeRecord>', []],
    'missing argument' => ['ScopeRecord::named();', 'void', ['too-few-arguments']],
    'wrong argument' => ['ScopeRecord::named(123);', 'void', ['invalid-argument']],
    'extra argument' => ['ScopeRecord::named("one", "two");', 'void', ['too-many-arguments']],
    'wrong name' => ['ScopeRecord::named(typo: "one");', 'void', ['invalid-named-argument']],
    'typo scope' => ['ScopeRecord::activve();', 'void', ['non-documented-method']],
    'private scope' => ['ScopeRecord::secret();', 'void', ['non-documented-method']],
    'custom dispatcher' => ['CustomScopeRecord::active();', 'void', ['non-documented-method']],
    'documented method' => ['return DocumentedScopeRecord::active();', 'string', []],
    'declared method' => ['return DeclaredScopeRecord::active();', 'string', []],
    'scalar is not builder' => ['ScopeRecord::total()->find(1);', 'void', ['invalid-method-access']],
    'attribute inherited builder' => ['return ChildScopeRecord::query()->recent();', 'Builder<ChildScopeRecord>', []],
    'attribute chain' => ['return ScopeRecord::query()->recent()->findOrFail(1);', 'ScopeRecord', []],
    'attribute wrong argument' => ['ScopeRecord::query()->recent("bad");', 'void', ['invalid-argument']],
    'attribute private deferred' => ['ScopeRecord::query()->hidden();', 'void', ['non-documented-method']],
    'public attribute direct preserved' => ['(new ScopeRecord)->direct(ScopeRecord::query());', 'void', []],
    'public attribute builder' => ['return ScopeRecord::query()->direct();', 'Builder<ScopeRecord>', []],
    'case insensitive legacy scope' => ['return ScopeRecord::ACTIVE();', 'Builder<ScopeRecord>', []],
    'null becomes builder' => ['return ScopeRecord::none();', 'Builder<ScopeRecord>', []],
    'never scope call' => ['ScopeRecord::stop();', 'void', []],
    'unknown return stays unknown' => [
        'return ScopeRecord::unknown();',
        'Builder<ScopeRecord>',
        ['mixed-return-statement'],
    ],
    'malformed query parameter deferred' => ['ScopeRecord::malformed();', 'void', ['non-documented-method']],
    'by reference argument' => ['$name = "test"; ScopeRecord::byReference($name);', 'void', []],
    'docblock parameter' => ['ScopeRecord::names(["test"]);', 'void', []],
    'invalid docblock parameter' => ['ScopeRecord::names([123]);', 'void', ['possibly-invalid-argument']],
    'custom builder deferred' => ['CustomBuilderScopeRecord::active();', 'void', ['non-documented-method']],
    'custom inherited dispatcher' => ['InheritedCustomScopeRecord::active();', 'void', ['non-documented-method']],
    'inherited docblock preserved' => ['return ChildDocumentedScopeRecord::active();', 'string', []],
    'native builder priority' => ['return ScopeRecord::where("active", true);', 'Builder<ScopeRecord>', []],
    'first class callable' => ['return ScopeRecord::active(...);', 'Closure', []],
    'unpacked arguments' => ['return ScopeRecord::named(...["test"]);', 'Builder<ScopeRecord>', []],
];
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
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
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
