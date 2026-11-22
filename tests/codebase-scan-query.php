<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use PhpParser\Node;
use PhpParser\NodeFinder;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';

// Fault injection is confined to this test process; real writes exercise cleanup.
eval(<<<'PHP'
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;
function file_put_contents(string $path, string $bytes): int|false {
    if (!empty($GLOBALS['laramago_query_partial_write']) && str_ends_with($path, '.query')) {
        return \file_put_contents($path, substr($bytes, 0, 1));
    }
    return \file_put_contents($path, $bytes);
}
// Emulate glob implementations with only basic wildcard support.
function glob(string $pattern, int $flags = 0): array|false {
    if ($flags !== 0 || str_contains($pattern, '{')) {
        throw new \LogicException('Cleanup requires non-portable glob features.');
    }
    return \glob($pattern);
}
PHP);

$check = static function (bool $condition, string $label): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    echo 'PASS: '.$label."\n";
};
$state = static function (): array {
    return [
        (new ReflectionProperty(CodebaseScanSyntax::class, 'queries'))->getValue(),
        (new ReflectionProperty(CodebaseScanSyntax::class, 'queryBytes'))->getValue(),
    ];
};

CodebaseScanSyntax::clear();
$calls = 0;
$extract = static function (array $nodes) use (&$calls): array {
    $calls++;

    return ['statements' => count($nodes)];
};
$source = '<?php final class Example {}';
$check(CodebaseScanSyntax::query($source, 'statement-count:v1', $extract) === ['statements' => 1],
    'an extraction runs on the first source and policy pair');
$check(CodebaseScanSyntax::query($source, 'statement-count:v1', $extract) === ['statements' => 1] && $calls === 1,
    'an exact source and policy pair extracts once');
CodebaseScanSyntax::query($source, 'statement-count:v2', $extract);
$check($calls === 2, 'a changed extraction policy cannot reuse another policy result');
CodebaseScanSyntax::query($source."\n// Changed exact bytes.", 'statement-count:v1', $extract);
$check($calls === 3, 'changed source bytes cannot reuse a stale result');

foreach ([null, false, []] as $index => $value) {
    $emptyCalls = 0;
    $empty = static function (array $nodes) use (&$emptyCalls, $value): mixed {
        $emptyCalls++;

        return $value;
    };
    $first = CodebaseScanSyntax::query('<?php', 'empty:'.$index, $empty);
    $second = CodebaseScanSyntax::query('<?php', 'empty:'.$index, $empty);
    $check($first === $value && $second === $value && $emptyCalls === 1,
        'empty result '.$index.' is a cache hit');
}

$nodeSource = '<?php namespace Example; use Vendor\Widget as Alias; final class Demo { public function make(): Alias {} }';
$nodeCalls = 0;
$nodeExtract = static function (array $nodes) use (&$nodeCalls): array {
    $nodeCalls++;
    $method = (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\ClassMethod::class);

    return ['nested' => ['method' => $method, 'labels' => ['original']]];
};
$original = CodebaseScanSyntax::query($nodeSource, 'method:v1', $nodeExtract);
$start = $original['nested']['method']->getStartFilePos();
$original['nested']['method']->returnType = new Node\Name('Changed');
$original['nested']['method']->setAttribute('startFilePos', 0);
$original['nested']['labels'][0] = 'changed';
$hit = CodebaseScanSyntax::query($nodeSource, 'method:v1', $nodeExtract);
$check($hit['nested']['method']->returnType->toString() === 'Vendor\Widget'
    && $hit['nested']['method']->getStartFilePos() === $start
    && $hit['nested']['labels'] === ['original'],
    'first-return mutation leaves cached nodes, nested arrays and source spans intact');
$hit['nested']['method']->returnType = new Node\Name('ChangedAgain');
$hit['nested']['labels'][] = 'changed';
$next = CodebaseScanSyntax::query($nodeSource, 'method:v1', $nodeExtract);
$check($next['nested']['method']->returnType->toString() === 'Vendor\Widget'
    && $next['nested']['labels'] === ['original'] && $nodeCalls === 1,
    'each cache hit returns independent mutable nodes and arrays');

$failedCalls = 0;
$failedExtract = static function (array $nodes) use (&$failedCalls): never {
    $failedCalls++;
    $nodes[0]->setAttribute('startFilePos', 999);
    throw new RuntimeException('Extraction failed.');
};
for ($attempt = 0; $attempt < 2; $attempt++) {
    try {
        CodebaseScanSyntax::query($source, 'failed:v1', $failedExtract);
        throw new RuntimeException('A failed extraction was accepted.');
    } catch (RuntimeException $exception) {
        $check($exception->getMessage() === 'Extraction failed.', 'extractor exception propagates unchanged');
    }
}
$check($failedCalls === 2, 'extractor exceptions are not cached');
$recovered = CodebaseScanSyntax::query($source, 'failed:v1', static fn (array $nodes): int => $nodes[0]->getStartFilePos());
$check($recovered === 6, 'a failed extractor does not mutate a subsequently parsed snapshot');

$invalidCalls = 0;
$invalidExtract = static function (array $nodes) use (&$invalidCalls): bool {
    $invalidCalls++;

    return true;
};
for ($attempt = 0; $attempt < 2; $attempt++) {
    try {
        CodebaseScanSyntax::query('<?php function {', 'invalid:v1', $invalidExtract);
        throw new RuntimeException('Invalid PHP was accepted.');
    } catch (\PhpParser\Error) {
        $check(true, 'invalid source remains a parse error on every query');
    }
}
$check($invalidCalls === 0, 'invalid source never reaches the extractor');
$check(CodebaseScanSyntax::query('<?php', 'invalid:v1', $invalidExtract) && $invalidCalls === 1,
    'a valid source under the same policy is evaluated normally');

$serializationCalls = 0;
$unserializable = static function (array $nodes) use (&$serializationCalls): Closure {
    $serializationCalls++;

    return static fn (): bool => true;
};
for ($attempt = 0; $attempt < 2; $attempt++) {
    try {
        CodebaseScanSyntax::query('<?php', 'unserializable:v1', $unserializable);
        throw new RuntimeException('An unserializable extraction was accepted.');
    } catch (Exception $exception) {
        $check(str_contains($exception->getMessage(), 'Serialization of'), 'serialization exceptions propagate');
    }
}
$check($serializationCalls === 2, 'serialization failures do not become cached results');

CodebaseScanSyntax::clear();
$entryCalls = [];
$entry = static function (string $policy) use (&$entryCalls): int {
    return CodebaseScanSyntax::query('<?php', $policy, static function (array $nodes) use ($policy, &$entryCalls): int {
        $entryCalls[$policy] = ($entryCalls[$policy] ?? 0) + 1;

        return $entryCalls[$policy];
    });
};
for ($index = 0; $index < 16384; $index++) {
    $entry('entry:'.$index);
}
$entry('entry:0');
$entry('entry:16384');
$check($entry('entry:0') === 1 && $entry('entry:1') === 1,
    'entry eviction preserves recent results and restores evicted pure results without extraction');
[$queries, $bytes] = $state();
$check(count($queries) === 16384 && $bytes === array_sum(array_map('strlen', $queries)) && $bytes <= 8 * 1024 * 1024,
    'query entry retention is bounded with exact serialized byte accounting');

CodebaseScanSyntax::clear();
$payload = random_bytes(1024 * 1024); // Force the physical byte bound even with compression.
$byteCalls = [];
$large = static function (string $policy) use (&$byteCalls, $payload): string {
    return CodebaseScanSyntax::query('<?php', $policy, static function (array $nodes) use ($policy, &$byteCalls, $payload): string {
        $byteCalls[$policy] = ($byteCalls[$policy] ?? 0) + 1;

        return $payload;
    });
};
for ($index = 0; $index < 7; $index++) {
    $large('large:'.$index);
}
$large('large:0');
$large('large:7');
$large('large:0');
$large('large:1');
[$queries, $bytes] = $state();
$check($byteCalls['large:0'] === 1 && $byteCalls['large:1'] === 1,
    'byte eviction restores pure results without repeating extraction');
$check(count($queries) === 7 && $bytes === array_sum(array_map('strlen', $queries)) && $bytes <= 8 * 1024 * 1024,
    'large results trigger the byte bound before the entry bound');

$oversized = str_repeat('y', 8 * 1024 * 1024);
$oversizedCalls = 0;
$oversizedExtract = static function (array $nodes) use (&$oversizedCalls, $oversized): string {
    $oversizedCalls++;

    return $oversized;
};
$check(CodebaseScanSyntax::query('<?php', 'oversized:v1', $oversizedExtract) === $oversized
    && CodebaseScanSyntax::query('<?php', 'oversized:v1', $oversizedExtract) === $oversized && $oversizedCalls === 2,
    'oversized extraction results are returned but not retained');
[$afterQueries, $afterBytes] = $state();
$check($queries === $afterQueries && $bytes === $afterBytes,
    'an oversized result does not evict reusable bounded results');

CodebaseScanSyntax::clear();
[$queries, $bytes] = $state();
$check($queries === [] && $bytes === 0
    && (new ReflectionProperty(CodebaseScanSyntax::class, 'trees'))->getValue() === []
    && (new ReflectionProperty(CodebaseScanSyntax::class, 'bytes'))->getValue() === 0,
    'fresh registration clears both syntax and extraction caches and their counters');
$entry('entry:0');
$check($entryCalls['entry:0'] === 2, 'a cleared extraction is recomputed');
CodebaseScanSyntax::clear();

// Disk results own no mutable objects and cannot outlive policy registration.
$diskCalls = 0;
$diskExtract = static function () use (&$diskCalls): array {
    ++$diskCalls;
    return ['object' => (object) ['value' => 'original']];
};
$source = '<?php /* disk result */';
$policy = 'disk-ownership:v1';
CodebaseScanSyntax::query($source, $policy, $diskExtract);
$pressure = random_bytes(4 * 1024 * 1024);
$evict = static function () use ($pressure): void {
    foreach (['one', 'two'] as $name) {
        CodebaseScanSyntax::query('<?php', 'pressure:'.$name, static fn(): string => $pressure);
    }
};
$evict();
$key = hash('sha256', strlen($policy).':'.$policy.hash('sha256', $source));
$dir = (new ReflectionProperty(CodebaseScanSyntax::class, 'spillDir'))->getValue();
$path = $dir.'/'.$key.'.query';
$check(is_file($path), 'evicted query is stored in the authenticated second tier');
$copy = CodebaseScanSyntax::query($source, $policy, $diskExtract);
$copy['object']->value = 'mutated';
$check(CodebaseScanSyntax::query($source, $policy, $diskExtract)['object']->value === 'original' && $diskCalls === 1,
    'restored query returns independent mutable objects without extraction');
$evict();
$entry = file_get_contents($path);
$entry[0] = chr(ord($entry[0]) ^ 1);
file_put_contents($path, $entry);
$check(CodebaseScanSyntax::query($source, $policy, $diskExtract)['object']->value === 'original' && $diskCalls === 2,
    'tampered query entry is ignored and recomputed');
CodebaseScanSyntax::clear();
if (function_exists('gzdeflate') && function_exists('gzinflate')) {
    $compressible = str_repeat('dense-source-facts ', 65536);
    for ($index = 0; $index < 12; ++$index) {
        $check(CodebaseScanSyntax::query('<?php', 'compressed:'.$index, static fn(): string => $compressible) === $compressible,
            'compressed query retains exact result '.$index);
    }
    [$packedQueries, $packedBytes] = $state();
    $check(count($packedQueries) === 12 && $packedBytes < 1024 * 1024 && $packedBytes === array_sum(array_map('strlen', $packedQueries)),
        'lossless packing keeps compressible facts within the unchanged physical query budget');
    CodebaseScanSyntax::clear();
}
$check(! is_file($path) && (new ReflectionProperty(CodebaseScanSyntax::class, 'querySpillBytes'))->getValue() === 0,
    'generation reset removes policy-result files and byte accounting');
$check(CodebaseScanSyntax::query($source, $policy, static fn(): string => 'new extractor') === 'new extractor',
    'same policy identity after reset cannot restore an old extractor result');

// Exhausted/unavailable second-tier storage retains the original honest fallback.
CodebaseScanSyntax::clear();
$bytesProperty = new ReflectionProperty(CodebaseScanSyntax::class, 'querySpillBytes');
$bytesProperty->setValue(null, 64 * 1024 * 1024);
CodebaseScanSyntax::query($source, $policy, $diskExtract);
$evict();
CodebaseScanSyntax::query($source, $policy, $diskExtract);
$check($diskCalls === 4 && (new ReflectionProperty(CodebaseScanSyntax::class, 'querySpillSizes'))->getValue() === [],
    'full disk byte quota recomputes normally without retaining more results');
$bytesProperty->setValue(null, 0);
$sizesProperty = new ReflectionProperty(CodebaseScanSyntax::class, 'querySpillSizes');
$sizesProperty->setValue(null, array_fill_keys(range(0, 65535), 32));
(new ReflectionMethod(CodebaseScanSyntax::class, 'spillQuery'))->invoke(null, str_repeat('a', 64), 'i:9;');
$check($bytesProperty->getValue() === 0 && count($sizesProperty->getValue()) === 65536, 'disk entry quota is enforced independently of bytes');
$sizesProperty->setValue(null, []);
CodebaseScanSyntax::clear();
$GLOBALS['laramago_query_partial_write'] = true;
$partialKey = str_repeat('b', 64);
(new ReflectionMethod(CodebaseScanSyntax::class, 'spillQuery'))->invoke(null, $partialKey, pack('N', 4).'i:9;');
$check(! is_file($dir.'/'.$partialKey.'.query') && $sizesProperty->getValue() === [] && $bytesProperty->getValue() === 0,
    'a partial disk write is removed and cannot consume unaccounted scratch storage');
unset($GLOBALS['laramago_query_partial_write']);
(new ReflectionMethod(CodebaseScanSyntax::class, 'spillQuery'))->invoke(null, $partialKey, pack('N', 4).'i:9;');
$check(is_file($dir.'/'.$partialKey.'.query') && $bytesProperty->getValue() === 40,
    'a later successful write works after a partial-write failure');
CodebaseScanSyntax::clear();
if (function_exists('gzdeflate') && function_exists('gzinflate')) {
    $dense = str_repeat('source facts ', 100000);
    for ($index = 0; $index < 12; ++$index) {
        $check(CodebaseScanSyntax::query('<?php', 'dense:'.$index, static fn(): string => $dense) === $dense,
            'lossless compressed query '.$index);
    }
    [$queries, $bytes] = $state();
    $check(count($queries) === 12 && $bytes < 1024 * 1024 && $bytes === array_sum(array_map('strlen', $queries)),
        'compression retains the larger source cohort within the original physical byte budget');
    $evict();
    $denseCalls = 0;
    $check(CodebaseScanSyntax::query('<?php', 'dense:0', static function () use (&$denseCalls): string {
        ++$denseCalls;
        return 'unexpected extraction';
    }) === $dense && $denseCalls === 0, 'compressed disk restoration is lossless without extraction');
    CodebaseScanSyntax::clear();
}
(new ReflectionMethod(CodebaseScanSyntax::class, 'spillQuery'))->invoke(null, $partialKey, pack('N', 4).'i:9;');
$check((glob($dir.'/*.tree') ?: []) !== [] && is_file($dir.'/'.$partialKey.'.query'),
    'shutdown cleanup has both syntax and query files to remove');
register_shutdown_function(static function () use ($dir, $check): void {
    $check(! is_dir($dir), 'shutdown removes both tiers with basic glob support');
});
