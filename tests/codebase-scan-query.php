<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use PhpParser\Node;
use PhpParser\NodeFinder;

require dirname(__DIR__).'/vendor/autoload.php';

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
for ($index = 0; $index < 8192; $index++) {
    $entry('entry:'.$index);
}
$entry('entry:0');
$entry('entry:8192');
$check($entry('entry:0') === 1 && $entry('entry:1') === 2,
    'entry eviction preserves a recently read result and recomputes the least recently used one');
[$queries, $bytes] = $state();
$check(count($queries) === 8192 && $bytes === array_sum(array_map('strlen', $queries)) && $bytes <= 8 * 1024 * 1024,
    'query entry retention is bounded with exact serialized byte accounting');

CodebaseScanSyntax::clear();
$payload = str_repeat('x', 1024 * 1024);
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
$check($byteCalls['large:0'] === 1 && $byteCalls['large:1'] === 2,
    'byte eviction also respects access order');
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
