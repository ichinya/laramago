<?php

declare(strict_types=1);

// Check local scope signatures and return types through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago relation names '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/relation-names.php.stub', $workspace.'/models.php');
$cases = [
    'interface could be relation' => ['RelationNameRecord::query()->with("contract");', 'void', []],
    'known relation' => ['RelationNameRecord::query()->with("children");', 'void', []],
    'additional known relation' => ['RelationNameRecord::query()->withWhereHas("children");', 'void', []],
    'wrong declared method' => [
        'RelationNameRecord::query()->with("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'nested wrong method' => [
        'RelationNameRecord::query()->with("children.label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'load wrong method' => [
        '(new RelationNameRecord)->load("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'whereHas wrong method' => [
        'RelationNameRecord::query()->whereHas("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'has wrong method' => [
        'RelationNameRecord::query()->has("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'orHas wrong method' => [
        'RelationNameRecord::query()->orHas("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'doesntHave wrong method' => [
        'RelationNameRecord::query()->doesntHave("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'orDoesntHave wrong method' => [
        'RelationNameRecord::query()->orDoesntHave("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'orWhereHas wrong method' => [
        'RelationNameRecord::query()->orWhereHas("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'whereDoesntHave wrong method' => [
        'RelationNameRecord::query()->whereDoesntHave("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'orWhereDoesntHave wrong method' => [
        'RelationNameRecord::query()->orWhereDoesntHave("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'withWhereHas wrong method' => [
        'RelationNameRecord::query()->withWhereHas("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'loadMissing wrong method' => [
        '(new RelationNameRecord)->loadMissing("label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'named relation argument' => [
        'RelationNameRecord::query()->withWhereHas(callback: null, relation: "label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'named relations argument' => [
        '(new RelationNameRecord)->loadMissing(relations: "label");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'list relation argument' => [
        'RelationNameRecord::query()->with(["children", "label"]);',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'keyed relation argument' => [
        'RelationNameRecord::query()->with(["label" => fn () => null]);',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'mixed list and callback relations' => [
        'RelationNameRecord::query()->with(["children", "label" => fn () => null]);',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'overwritten numeric relation' => [
        'RelationNameRecord::query()->with([0 => "label", 0 => "children"]);',
        'void',
        ['duplicate-array-key'],
    ],
    'numeric string key is a list offset' => [
        'RelationNameRecord::query()->with(["0" => "children"]);',
        'void',
        [],
    ],
    'leading-zero numeric key uses relation value' => [
        'RelationNameRecord::query()->with(["01" => "label"]);',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'exponent numeric key uses relation value' => [
        'RelationNameRecord::query()->with(["1e2" => "label"]);',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'negative numeric key deferred' => [
        'RelationNameRecord::query()->with(["-5" => "label", "children"]);',
        'void',
        [],
    ],
    'dynamic key deferred' => [
        '$key = rand() ? 0 : "relation"; RelationNameRecord::query()->with([$key => "label"]);',
        'void',
        [],
    ],
    'array unpack deferred' => [
        'RelationNameRecord::query()->with(["label", ...["children"]]);',
        'void',
        [],
    ],
    'selected columns' => [
        'RelationNameRecord::query()->with("label:id");',
        'void',
        ['ichinya/laramago/laramago-invalid-relation'],
    ],
    'absent method could be dynamic' => ['RelationNameRecord::query()->with("dynamic");', 'void', []],
    'unknown return deferred' => ['RelationNameRecord::query()->with("unknown.label");', 'void', []],
    'custom dispatcher deferred' => ['(new DynamicRelationNameRecord)->load("label");', 'void', []],
    'dynamic name deferred' => ['$name = (string) rand(); RelationNameRecord::query()->with($name);', 'void', []],
    'unpacked argument deferred' => ['RelationNameRecord::query()->with(...["label"]);', 'void', []],
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
    'source' => ['paths' => ['cases.php'], 'includes' => ['models.php']],
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
if (
    ! in_array($exit, [0, 1], true)
    || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
) {
    throw new RuntimeException('Expected successful analysis with extension warnings and no fallback; inspect '
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
