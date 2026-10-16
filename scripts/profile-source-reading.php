<?php

declare(strict_types=1);

// A bounded optimization experiment, not a production source change or end-to-end speedup.
$options = getopt('', ['autoload:', 'output:', 'iterations::']);
if (! isset($options['autoload'], $options['output'])) {
    throw new InvalidArgumentException('Required: --autoload --output');
}
require $options['autoload'];
use Ichinya\Laramago\Analyzer\GuardedStringCastContracts;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use PhpParser\Node;

$iterations = (int) ($options['iterations'] ?? 200);
if ($iterations < 10) {
    throw new InvalidArgumentException('Use at least ten reads per sample.');
}
$root = sys_get_temp_dir().'/laramago-source-reader-'.bin2hex(random_bytes(6));
mkdir($root);
$path = $root.'/cases.php';
$original = file_get_contents(dirname(__DIR__).'/tests/fixtures/analyzer-depth/cases.php');
file_put_contents($path, $original);
$guard = new GuardedStringCastContracts($root);
$cached = static function (?string $name, ?string $analyzed = null) use ($guard, $root): ?array {
    if ($name === null) {
        return null;
    }
    $path = $guard->path($name);
    $physicalRoot = $guard->path($root);
    if ($path === '' || $physicalRoot === '' || ! str_starts_with($path, rtrim($physicalRoot, '/').'/')) {
        return null;
    }
    $contents = @file_get_contents($path);
    if ($contents === false || strlen($contents) > 1024 * 1024 || $analyzed !== null && $contents !== $analyzed) {
        return null;
    }
    try {
        $nodes = CodebaseScanSyntax::parse($contents);
    } catch (\PhpParser\Error) {
        return null;
    }
    $parents = [];
    $visit = static function (mixed $value, ?Node $parent) use (&$visit, &$parents): void {
        if (is_array($value)) {
            foreach ($value as $item) { $visit($item, $parent); }
        } elseif ($value instanceof Node) {
            if ($parent !== null) { $parents[spl_object_id($value)] = $parent; }
            foreach ($value->getSubNodeNames() as $field) { $visit($value->$field, $value); }
        }
    };
    $visit($nodes, null);
    $visit = null;
    return ['path' => $path, 'contents' => $contents, 'hash' => hash('sha256', $contents), 'nodes' => $nodes, 'parents' => $parents];
};
$assert = static function (bool $condition): void {
    if (! $condition) { throw new RuntimeException('Source reader semantic control failed.'); }
};
$baseline = $guard->read($path, $original);
$candidate = $cached($path, $original);
$assert($baseline !== null && $candidate !== null && serialize($baseline['nodes']) === serialize($candidate['nodes']));
$candidate['nodes'][0]->setAttribute('consumerMutation', true);
$assert($cached($path, $original)['nodes'][0]->getAttribute('consumerMutation') === null);
file_put_contents($path, str_replace('takesInt', 'takesInteger', $original));
$assert($cached($path, $original) === null);
$changed = $cached($path);
$assert($changed !== null && $changed['hash'] !== $baseline['hash']);
file_put_contents($path, '<?php function broken( {');
$assert($cached($path) === null);
file_put_contents($path, '<?php '.str_repeat(' ', 1024 * 1024));
$assert($cached($path) === null);
file_put_contents($path, $original);
$assert($cached(dirname(__DIR__).'/composer.json') === null);
$assert($cached($path, $original)['hash'] === $baseline['hash']);

$readers = ['stock-fresh-parse' => $guard->read(...), 'prototype-shared-syntax' => $cached];
$measure = static function (callable $read) use ($iterations, $path, $original): array {
    $before = getrusage();
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $result = $read($path, $original);
        if ($result === null) { throw new RuntimeException('Reader unexpectedly declined unchanged source.'); }
    }
    $seconds = (hrtime(true) - $start) / 1e9;
    $after = getrusage();
    $cpu = ($after['ru_utime.tv_sec'] - $before['ru_utime.tv_sec']) + ($after['ru_utime.tv_usec'] - $before['ru_utime.tv_usec']) / 1e6
        + ($after['ru_stime.tv_sec'] - $before['ru_stime.tv_sec']) + ($after['ru_stime.tv_usec'] - $before['ru_stime.tv_usec']) / 1e6;
    return ['seconds' => $seconds, 'cpu_seconds' => $cpu, 'tree_sha256' => hash('sha256', serialize($result['nodes']))];
};
foreach ($readers as $reader) { $measure($reader); } // Excluded warmups.
$runs = [];
for ($round = 0; $round < 4; ++$round) {
    $order = $round % 2 ? array_reverse(array_keys($readers)) : array_keys($readers);
    foreach ($order as $name) {
        $runs[] = ['round' => $round + 1, 'reader' => $name] + $measure($readers[$name]);
    }
}
$assert(count(array_unique(array_column($runs, 'tree_sha256'))) === 1);
$report = ['kind' => 'public-corpus-source-reader-microbenchmark', 'production_patch' => false,
    'fixture_sha256' => hash('sha256', $original), 'harness_sha256' => hash_file('sha256', __FILE__),
    'iterations_per_sample' => $iterations, 'samples_per_reader' => 4, 'public_source_bytes' => strlen($original),
    'semantic_controls' => ['same_resolved_syntax' => true, 'consumer_isolation' => true,
        'changed_disk_veto' => true, 'changed_bytes_reparsed' => true, 'outside_root_veto' => true,
        'parse_failure_veto' => true, 'oversize_veto' => true, 'restoration' => true],
    'runs' => $runs, 'limitation' => 'One public source file; no native metadata or issue decisions cached; not an end-to-end improvement.' ];
file_put_contents($options['output'], json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['semantic_controls' => 'passed', 'samples' => count($runs)])."\n";
