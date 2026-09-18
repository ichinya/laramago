<?php

declare(strict_types=1);

// Verify explicit complete relation contracts through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago relation names '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/relation-name-contracts.php.stub', $workspace.'/models.php');
$complete = ['complete' => true, 'dynamic' => ['registered', 'label']];
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'relation-names' => [
                'RelationNameRecord' => $complete,
                'StaticDispatcherRecord' => $complete,
                'CustomQueryRelationRecord' => $complete,
                'DuplicateContractRecord' => $complete,
                'duplicatecontractrecord' => ['complete' => true],
                'PartialContractRecord' => ['complete' => false],
                'UnknownContractClass' => $complete,
                'RelationNameChild' => ['complete' => true],
                'DynamicRelationNameRecord' => $complete,
                'ResolverRelationRecord' => $complete,
                'MalformedRelationRecord' => ['complete' => true, 'dynamic' => [false]],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
$cases = [
    'static custom query' => ['CustomQueryRelationRecord::with("missing");', 'void', []],
    'alias static with' => ['RelationAlias::with("missing");', 'void', ['ichinya/laramago/laramago-missing-relation']],
    'static valid' => ['RelationNameRecord::with("children");', 'void', []],
    'static dynamic registration' => ['RelationNameRecord::whereHas("registered");', 'void', []],
    'custom static dispatch' => ['StaticDispatcherRecord::whereHas("missing");', 'void', ['non-documented-method']],
    'duplicate class contract' => ['DuplicateContractRecord::query()->with("missing");', 'void', []],
    'incomplete contract' => ['PartialContractRecord::query()->with("missing");', 'void', []],
    'static with' => ['RelationNameRecord::with("missing");', 'void', ['ichinya/laramago/laramago-missing-relation']],
    'static whereHas' => [
        'RelationNameRecord::whereHas("missing");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'static orWhereHas' => [
        'RelationNameRecord::orWhereHas("missing");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'static loadMissing stays native' => [
        'RelationNameRecord::loadMissing("missing");',
        'void',
        ['invalid-static-method-access'],
    ],
    'additional custom static dispatch' => [
        'StaticDispatcherRecord::orWhereHas("missing");',
        'void',
        ['non-documented-method'],
    ],
    'declared relation' => ['RelationNameRecord::query()->with("children");', 'void', []],
    'with selected columns' => ['RelationNameRecord::query()->with("children:id");', 'void', []],
    'load selected columns' => ['(new RelationNameRecord)->load("children:id");', 'void', []],
    'loadMissing selected columns' => ['(new RelationNameRecord)->loadMissing("children:id");', 'void', []],
    'has does not accept selected columns' => [
        'RelationNameRecord::query()->has("children:id");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'whereHas does not accept selected columns' => [
        'RelationNameRecord::query()->whereHas("children:id");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'missing relation' => [
        'RelationNameRecord::query()->with("childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'nested missing relation' => [
        'RelationNameRecord::query()->with("children.missing");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'load missing relation' => [
        '(new RelationNameRecord)->load("childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'loadMissing missing relation' => [
        '(new RelationNameRecord)->loadMissing("childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'has missing relation' => [
        'RelationNameRecord::query()->has(relation: "childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'orHas missing relation' => [
        'RelationNameRecord::query()->orHas(relation: "childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'doesntHave missing relation' => [
        'RelationNameRecord::query()->doesntHave(relation: "childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'orDoesntHave missing relation' => [
        'RelationNameRecord::query()->orDoesntHave(relation: "childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'orWhereHas missing relation' => [
        'RelationNameRecord::query()->orWhereHas(relation: "childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'whereDoesntHave missing relation' => [
        'RelationNameRecord::query()->whereDoesntHave(relation: "childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'orWhereDoesntHave missing relation' => [
        'RelationNameRecord::query()->orWhereDoesntHave(relation: "childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'withWhereHas missing relation' => [
        'RelationNameRecord::query()->withWhereHas(relation: "childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'named whereHas' => [
        'RelationNameRecord::query()->whereHas(relation: "childen");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'keyed missing relation' => [
        'RelationNameRecord::query()->with(["childen" => fn () => null]);',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'columns missing relation' => [
        'RelationNameRecord::query()->with("childen:id");',
        'void',
        ['ichinya/laramago/laramago-missing-relation'],
    ],
    'registered relation' => ['RelationNameRecord::query()->with("registered");', 'void', []],
    'registered relation target unknown' => ['RelationNameRecord::query()->with("registered.missing");', 'void', []],
    'registered overrides method' => ['RelationNameRecord::query()->with("label");', 'void', []],
    'no inherited completeness' => ['OpenRelationRecord::query()->with("missing");', 'void', []],
    'nested open model' => ['RelationNameRecord::query()->with("open.missing");', 'void', []],
    'custom dispatcher' => ['DynamicRelationNameRecord::query()->with("missing");', 'void', []],
    'custom resolver' => ['ResolverRelationRecord::query()->with("missing");', 'void', []],
    'nested custom dispatcher' => ['RelationNameRecord::query()->with("custom.missing");', 'void', []],
    'malformed contract' => ['MalformedRelationRecord::query()->with("missing");', 'void', []],
    'unknown return' => ['RelationNameRecord::query()->with("unknown.missing");', 'void', []],
    'dynamic name' => ['$name = (string) rand(); RelationNameRecord::query()->with($name);', 'void', []],
    'unpacked arguments' => ['RelationNameRecord::query()->with(...["missing"]);', 'void', []],
    'nonrelation remains' => [
        'RelationNameRecord::query()->with("children.label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Database\Eloquent\Builder;
    use RelationNameRecord as RelationAlias;
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
    'source' => ['paths' => ['cases.php'], 'includes' => ['models.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
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
if (
    ! in_array($exit, [0, 1], true)
    || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
) {
    throw new RuntimeException('Expected completed analysis with extension diagnostics and no fallback; inspect '
    .$workspace);
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
    throw new RuntimeException('Unexpected diagnostics outside relation-name scenarios; inspect '.$workspace);
}
$nativeConfig = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
unset($nativeConfig['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($nativeConfig, JSON_THROW_ON_ERROR));
$native = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/native.json', 'w'], 2 => ['file', $workspace.'/native.log', 'w']],
    $pipes,
);
if (! is_resource($native)) {
    throw new RuntimeException('Cannot start native comparison.');
}
fclose($pipes[0]);
$nativeExit = proc_close($native);
if (! in_array($nativeExit, [0, 1], true)) {
    throw new RuntimeException('Native comparison failed: '.$workspace);
}
$nativeReport = json_decode(file_get_contents($workspace.'/native.json'), true, flags: JSON_THROW_ON_ERROR);
$nativeCodes = [];
foreach ($nativeReport['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $nativeCodes[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
foreach ($lines as $line => [$name]) {
    $expected = match ($name) {
        'static loadMissing stays native' => ['invalid-static-method-access'],
        'static whereHas',
        'static orWhereHas',
        'static dynamic registration',
        'custom static dispatch',
        'additional custom static dispatch',
            => ['non-documented-method'],
        default => [],
    };
    if (($nativeCodes[$line] ?? []) !== $expected) {
        throw new RuntimeException('Native '.$name.' mismatch: '.$workspace);
    }
    unset($nativeCodes[$line]);
}
if ($nativeCodes !== []) {
    throw new RuntimeException('Unexpected native diagnostics: '.$workspace);
}
echo "PASS: provider-disabled comparison\n";
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
