<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago optional replace '.bin2hex(random_bytes(8));
mkdir($workspace, recursive: true);
file_put_contents($workspace.'/composer.json', '{}');
$cases = [
    'named constructor overrides' => ["array{left?:int,right?:int}", 'array_replace(["left" => 1, "right" => 2], $overrides)', 'new Pair(...$row);', true],
    'optional union value' => ['array{left?:string}', 'array_replace(["left" => 1], $overrides)', 'takeIntOrString($row["left"]);', true],
    'nullable replacement' => ['array{left?:null}', 'array_replace(["left" => 1], $overrides)', 'takeNullableInt($row["left"]);', true],
    'two optional replacements' => ['array{left?:int}', 'array_replace(["left" => 1], $overrides, $overrides)', 'takeInt($row["left"]);', true],
    'required last replacement wins' => ['array{left?:string}', 'array_replace(["left" => 1], $overrides, ["left" => 3])', 'takeInt($row["left"]);', true],
    'only later argument has optional keys' => ['array{left?:int}', 'array_replace(["left" => 1], ["right" => 2], $overrides)', 'new Pair(...$row);', true],
    'optional key order preserves base order' => ['array{right?:int,left?:int}', 'array_replace(["left" => 1, "right" => 2], $overrides)', 'new Pair(...$row);', true],
    'wrong optional constructor argument' => ['array{left?:string}', 'array_replace(["left" => 1, "right" => 2], $overrides)', 'new Pair(...$row);', true],
    'wrong optional receiving argument' => ['array{left?:string}', 'array_replace(["left" => 1], $overrides)', 'takeInt($row["left"]);', true],
    'wrong nullable receiving argument' => ['array{left?:null}', 'array_replace(["left" => 1], $overrides)', 'takeInt($row["left"]);', true],
    'optional new key defers' => ['array{extra?:int}', 'array_replace(["left" => 1], $overrides)', 'takeInt($row["left"]);', false],
    'optional base defers' => ['array{left?:int}', 'array_replace($overrides, ["right" => 2])', 'takeInt($row["right"]);', false],
    'optional numeric key defers' => ['array{0?:int}', 'array_replace([1], $overrides)', 'takeInt($row[0]);', false],
    'numeric string key defers' => ["array{'01'?:int}", 'array_replace(["01" => 1], $overrides)', 'takeInt($row["01"]);', false],
    'open replacement defers' => ['array{left?:int,...}', 'array_replace(["left" => 1], $overrides)', 'takeInt($row["left"]);', false],
    'unknown replacement defers' => ['array<string,mixed>', 'array_replace(["left" => 1], $overrides)', 'takeInt($row["left"]);', false],
    'union replacement defers' => ['array{left?:int}|array{right?:int}', 'array_replace(["left" => 1, "right" => 2], $overrides)', 'new Pair(...$row);', false],
    'unpacked arguments defer' => ['array{left?:int}', 'array_replace(...[["left" => 1], $overrides])', 'takeInt($row["left"]);', false],
    'bad replacement argument retains error' => ['int', 'array_replace(["left" => 1], $overrides)', '', false],
    'namespaced replacement defers' => ['array{left?:int}', 'Other\\array_replace(["left" => 1], $overrides)', 'takeInt($row);', false],
];
$cases['bounded optional unions'] = ['array{left?:int}', 'array_replace(["left" => 1], '.implode(', ', array_fill(0, 63, '$overrides')).')', 'takeInt($row["left"]);', true];
$cases['over-bound optional unions defer'] = ['array{left?:int}', 'array_replace(["left" => 1], '.implode(', ', array_fill(0, 64, '$overrides')).')', 'takeInt($row["left"]);', false];
$source = '<?php namespace Other { function array_replace(array $base, array $overrides): string { return "other"; } } namespace {'."\n"
    .'final readonly class Pair { public function __construct(public int $left, public int $right) {} }'."\n"
    .'function takeInt(int $value): void {} function takeIntOrString(int|string $value): void {} function takeNullableInt(?int $value): void {}'."\n";
$ranges = [];
foreach ($cases as $name => [$input, $call, $use, $supported]) {
    $source .= '/** @param '.$input.' $overrides */ function case'.count($ranges).'($overrides): void {'."\n";
    $start = strlen($source);
    $source .= ' $row = '.$call.'; '.$use."\n";
    $ranges[$name] = [$start, strlen($source), $supported];
    $source .= "}\n";
}
$source .= "}\n";
file_put_contents($workspace.'/cases.php', $source);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$reports = [];
foreach (['native', 'one', 'three'] as $mode) {
    $config = ['php-version' => '8.2', 'source' => ['paths' => ['cases.php']]];
    if ($mode !== 'native') {
        $config['extension-hosts']['replacements'] = ['command' => [PHP_BINARY, $package.'/tests/fixtures/array-replace-optionals-worker.php', $package.'/vendor/autoload.php', $workspace.'/'.$mode.'.jsonl'], 'workers' => $mode === 'one' ? 1 : 3];
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    if (! in_array($exit, [0, 1], true) || preg_match('/External analyzer provider failed|rejected request/i', file_get_contents($workspace.'/'.$mode.'.log'))) { throw new RuntimeException('Mago failed: '.$workspace); }
    $report = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $reports[$mode] = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $a): bool => $a['kind'] === 'Primary'))[0];
        $offset = $primary['span']['start']['offset'];
        foreach ($ranges as $name => [$start, $end]) {
            if ($offset >= $start && $offset < $end) { $reports[$mode][$name][] = $issue; continue 2; }
        }
        throw new RuntimeException('Unexpected issue outside cases: '.$workspace.' '.json_encode($issue));
    }
    if ($mode !== 'native') {
        $observations = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($workspace.'/'.$mode.'.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        foreach ($ranges as $name => [$start, $end, $supported]) {
            $calls = array_values(array_filter($observations, static fn (array $call): bool => $call['offset'] >= $start && $call['offset'] < $end));
            if ($calls === [] && ! $supported) { continue; }
            if ($calls === [] || count(array_unique(array_map(static fn (array $call): bool => $call['result'] !== null, $calls))) !== 1
                || ($calls[0]['result'] !== null) !== $supported) { throw new RuntimeException('Wrong actual provider decision: '.$name.' '.$workspace); }
        }
    }
}
foreach ($ranges as $name => [$_, $end, $supported]) {
    $native = $reports['native'][$name] ?? [];
    $actual = $reports['one'][$name] ?? [];
    if ($actual !== ($reports['three'][$name] ?? [])) { throw new RuntimeException('Worker count changed issues: '.$name); }
    if (! $supported) {
        if ($native !== $actual) { throw new RuntimeException('Deferred complete diagnostic changed: '.$name.' '.$workspace); }
    } elseif (str_starts_with($name, 'wrong ')) {
        if (array_intersect(['invalid-argument', 'possibly-invalid-argument', 'possibly-null-argument', 'null-argument'], array_column($actual, 'code')) === []) { throw new RuntimeException('Real argument error removed: '.$name); }
    } elseif ($actual !== []) { throw new RuntimeException('Optional replacement lost: '.$name.' '.$workspace.' '.json_encode($actual)); }
    echo 'PASS: '.$name."\n";
}
$pair = new class { public function __construct(public int $left = 1, public int $right = 2) {} };
foreach ([[], ['left' => 3], ['right' => 4], ['left' => 3, 'right' => 4]] as $overrides) {
    $values = array_replace(['left' => 1, 'right' => 2], $overrides);
    $actual = new ($pair::class)(...$values);
    if ($actual->left !== ($overrides['left'] ?? 1) || $actual->right !== ($overrides['right'] ?? 2)) { throw new RuntimeException('PHP named replacement semantics changed.'); }
}
echo 'PASS: native/one/three whole diagnostics, genuine SDK decisions, four runtime combinations; '.$workspace."\n";
