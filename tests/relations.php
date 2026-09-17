<?php

declare(strict_types=1);

// Check forwarded relation calls through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago relations '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = file_get_contents(__DIR__.'/fixtures/analysis/framework.php.stub');
$framework = str_replace(
    'class HasMany {}',
    'class HasMany { public function __call(string $method, array $arguments): mixed {} }',
    $framework,
);
file_put_contents($workspace.'/framework.php', $framework);
copy(__DIR__.'/fixtures/analysis/relations.php.stub', $workspace.'/models.php');
$cases = [
    'custom relation method wins' => ['return (new CustomOwner)->records()->firstOrFail();', 'string', []],
    'unknown related type stays unknown' => [
        'return (new UnknownOwner)->records()->firstOrFail();',
        'RelatedRecord',
        ['less-specific-return-statement'],
    ],
    'related nullable first' => ['return (new OwnerRecord)->records()->first();', '?RelatedRecord', []],
    'related first or fail' => ['return (new OwnerRecord)->records()->firstOrFail();', 'RelatedRecord', []],
    'related sole' => ['return (new OwnerRecord)->records()->sole();', 'RelatedRecord', []],
    'related collection' => [
        'return (new OwnerRecord)->records()->get();',
        '\Illuminate\Database\Eloquent\Collection<int, RelatedRecord>',
        [],
    ],
    'sorting retains relation' => [
        'return (new OwnerRecord)->records()->latest()->oldest()->orderBy("id")->orderByDesc("id");',
        '\Illuminate\Database\Eloquent\Relations\HasMany<RelatedRecord>',
        [],
    ],
    'sorted related result' => ['return (new OwnerRecord)->records()->latest()->firstOrFail()->title;', 'string', []],
    'related count' => ['return (new OwnerRecord)->records()->count();', 'int', []],
    'related exists' => ['return (new OwnerRecord)->records()->exists();', 'bool', []],
    'related absent' => ['return (new OwnerRecord)->records()->doesntExist();', 'bool', []],
    'unknown method remains' => ['(new OwnerRecord)->records()->typo();', 'void', ['non-documented-method']],
    'nullable remains' => [
        'echo (new OwnerRecord)->records()->first()->title;',
        'void',
        ['possibly-null-property-access'],
    ],
    'bad columns' => ['(new OwnerRecord)->records()->first(42);', 'void', ['invalid-argument']],
    'too many arguments' => ['(new OwnerRecord)->records()->exists(42);', 'void', ['too-many-arguments']],
    'wrong result type' => [
        'return (new OwnerRecord)->records()->firstOrFail();',
        'string',
        ['invalid-return-statement'],
    ],
    'unknown property remains' => [
        'echo (new OwnerRecord)->records()->firstOrFail()->typo;',
        'void',
        ['mixed-argument', 'non-documented-property'],
    ],
    'first class call' => ['return (new OwnerRecord)->records()->firstOrFail(...);', '\Closure', []],
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
    throw new RuntimeException('Unexpected diagnostics outside relation scenarios; inspect '.$workspace);
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
