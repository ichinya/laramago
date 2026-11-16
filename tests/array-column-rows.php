<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago array rows '.bin2hex(random_bytes(8));
mkdir($workspace, recursive: true);
file_put_contents($workspace.'/composer.json', '{}');
$row = "array{key:string,type:'text',required:bool}|array{key:string,type:'select',required:bool,options:list<array{key:string,label:string}>}";
$cases = [
    'indexed union rows' => ['list<'.$row.'>', 'array_column($rows, null, "key")', 'takeRow($row);', true],
    'named indexed rows' => ['list<'.$row.'>', 'array_column(index_key: "key", array: $rows, column_key: null)', 'takeRow($row);', true],
    'reindexed union rows' => ['list<'.$row.'>', 'array_column($rows, null)', 'takeRow($row);', true],
    'null index reindexes rows' => ['list<'.$row.'>', 'array_column($rows, null, null)', 'takeRow($row);', true],
    'integer column index keeps rows' => ['list<'.$row.'>', 'array_column($rows, null, 0)', 'takeRow($row);', true],
    'array input union rows' => ['array<string, '.$row.'>', 'array_column($rows, null, "key")', 'takeRow($row);', true],
    'real row type mismatch' => ['list<'.$row.'>', 'array_column($rows, null, "key")', 'takeInt($row);', true],
    'selected scalar column defers' => ['list<'.$row.'>', 'array_column($rows, "key")', 'takeInt($row);', false],
    'dynamic column defers' => ['list<'.$row.'>', 'array_column($rows, $column)', '', false],
    'dynamic index defers' => ['list<'.$row.'>', 'array_column($rows, null, $index)', '', false],
    'scalar entries defer' => ['list<array{key:string}|int>', 'array_column($rows, null, "key")', '', false],
    'object entries defer' => ['list<array{key:string}|stdClass>', 'array_column($rows, null, "key")', '', false],
    'single row shape defers' => ['list<array{key:string}>', 'array_column($rows, null, "key")', '', false],
    'invalid source retains native error' => ['int', 'array_column($rows, null, "key")', '', false],
    'invalid index retains native error' => ['list<'.$row.'>', 'array_column($rows, null, [])', '', false],
    'unpacking defers' => ['list<'.$row.'>', 'array_column(...[$rows, null, "key"])', '', false],
    'too many arguments retain native error' => ['list<'.$row.'>', 'array_column($rows, null, "key", 1)', '', false],
    'too few arguments retain native error' => ['list<'.$row.'>', 'array_column($rows)', '', false],
    'unknown named argument retains native error' => ['list<'.$row.'>', 'array_column($rows, null, unexpected: "key")', '', false],
    'namespace shadow retains native return' => ['list<'.$row.'>', 'Other\\array_column($rows, null, "key")', '', false],
];
$source = '<?php namespace Other { function array_column(array $rows, mixed $column, mixed $index = null): int { return 1; } } namespace {'."\n".'/** @param '.$row.' $row */ function takeRow(array $row): void {}' ."\n".'function takeInt(int $row): void {}'."\n";
$ranges = [];
foreach ($cases as $name => [$input, $call, $use, $supported]) {
    $id = count($ranges);
    $source .= '/** @param '.$input.' $rows */' ."\n".'function case'.$id.'($rows, ?string $column = null, ?string $index = null): void {' ."\n";
    $start = strlen($source);
    $source .= ' $indexed = '.$call.'; foreach ($indexed as $row) { '.$use.' }' ."\n";
    $ranges[$name] = [$start, strlen($source), $supported];
    $source .= "}\n";
}
$source .= "}\n";
file_put_contents($workspace.'/cases.php', $source);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$reports = [];
$nativeCovered = [];
foreach (['native', 'one', 'three'] as $mode) {
    $config = ['php-version' => '8.2', 'source' => ['paths' => ['cases.php']]];
    if ($mode !== 'native') {
        $config['extension-hosts']['rows'] = ['command' => [PHP_BINARY, $package.'/tests/fixtures/array-column-rows-worker.php', $package.'/vendor/autoload.php', $workspace.'/'.$mode.'.jsonl'], 'workers' => $mode === 'one' ? 1 : 3];
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
        throw new RuntimeException('Unexpected diagnostic outside cases: '.$workspace);
    }
    if ($mode !== 'native') {
        $calls = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($workspace.'/'.$mode.'.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        foreach ($ranges as $name => [$start, $end, $supported]) {
            $observed = array_values(array_filter($calls, static fn (array $call): bool => $call['line'] >= $start && $call['line'] < $end));
            if ($observed === [] && ! $supported) { continue; } // Native inference may finish simple calls without requesting a provider.
            if ($observed === [] && $supported) {
                $native = $reports['native'][$name] ?? [];
                // A newer native implementation may finish before SDK dispatch.
                // Require its whole result, including the incompatible receiver,
                // to match this extended run before declaring native coverage.
                $proven = $name === 'real row type mismatch'
                    ? in_array('invalid-argument', array_column($native, 'code'), true)
                    : $native === [];
                if ($proven && $native === ($reports[$mode][$name] ?? [])) {
                    $nativeCovered[$mode][] = $name;

                    continue;
                }
            }
            if ($observed === [] || count(array_unique(array_map(static fn (array $call): bool => $call['result'] !== null, $observed))) !== 1
                || ($observed[0]['result'] !== null) !== $supported) { throw new RuntimeException('Wrong genuine provider decision: '.$name.' '.$workspace); }
        }
    }
}
foreach ($ranges as $name => [$_, $end, $supported]) {
    $native = $reports['native'][$name] ?? [];
    $actual = $reports['one'][$name] ?? [];
    if ($actual !== ($reports['three'][$name] ?? [])) { throw new RuntimeException('Worker count changed issues: '.$name); }
    if ($supported && $name !== 'real row type mismatch') {
        if ($actual !== []) { throw new RuntimeException('Union row type lost: '.$name.' '.$workspace); }
    } elseif (! $supported) {
        if ($native !== $actual) { throw new RuntimeException('Deferred native issues changed: '.$name.' '.$workspace); }
    } elseif (! in_array('invalid-argument', array_column($actual, 'code'), true)) { throw new RuntimeException('Real mismatch removed.'); }
    echo 'PASS: '.$name."\n";
}
// Exercise actual PHP semantics, including duplicate and absent index keys.
$rows = [['key' => 'a', 'type' => 'text', 'required' => false], ['key' => 'a', 'type' => 'select', 'required' => true, 'options' => [['key' => 'x', 'label' => 'X']]], ['type' => 'text', 'required' => false]];
if (array_column($rows, null, 'key') !== ['a' => $rows[1], 0 => $rows[2]]
    || array_column($rows, null) !== $rows || array_column($rows, null, null) !== $rows) { throw new RuntimeException('Runtime row semantics changed.'); }
echo 'PASS: native/one/three complete reports, genuine provider decisions, runtime rows; '.$workspace."\n";
echo 'Native-covered without SDK dispatch: '.json_encode($nativeCovered, JSON_THROW_ON_ERROR)."\n";
