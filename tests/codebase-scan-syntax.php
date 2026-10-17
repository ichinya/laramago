<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use PhpParser\Node;
use PhpParser\NodeFinder;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';

$check = static function (bool $condition, string $label): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    echo 'PASS: '.$label."\n";
};
$source = '<?php namespace Example; use Vendor\Widget as Alias; /** Retain comment. */ final class Demo { public function make(): Alias { throw new \RuntimeException; } }';
$method = static fn (array $nodes): Node\Stmt\ClassMethod => (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\ClassMethod::class);

CodebaseScanSyntax::clear();
$original = CodebaseScanSyntax::parse($source);
$originalMethod = $method($original);
$expectedStart = $originalMethod->getStartFilePos();
$originalMethod->returnType = new Node\Name('Changed');
$originalMethod->setAttribute('startFilePos', 0);
$fresh = CodebaseScanSyntax::parse($source);
$freshMethod = $method($fresh);
$check($freshMethod->returnType->toString() === 'Vendor\Widget' && $freshMethod->getStartFilePos() === $expectedStart,
    'another scan receives independent resolved names and original byte spans');
$class = (new NodeFinder)->findFirstInstanceOf($fresh, Node\Stmt\Class_::class);
$check($class->getDocComment()->getText() === '/** Retain comment. */', 'comments survive reuse');
$changed = CodebaseScanSyntax::parse(str_replace('Vendor\Widget', 'Vendor\Other', $source));
$check($method($changed)->returnType->toString() === 'Vendor\Other', 'changed contents cannot reuse stale syntax');

for ($attempt = 0; $attempt < 2; $attempt++) {
    try {
        CodebaseScanSyntax::parse('<?php function {');
        throw new RuntimeException('Invalid PHP was accepted.');
    } catch (\PhpParser\Error) {
        $check(true, 'invalid syntax remains a parse error');
    }
}
for ($index = 0; $index < 1100; $index++) {
    CodebaseScanSyntax::parse('<?php final class CacheEntry'.$index.' {}');
}
$trees = (new ReflectionProperty(CodebaseScanSyntax::class, 'trees'))->getValue();
$bytes = (new ReflectionProperty(CodebaseScanSyntax::class, 'bytes'))->getValue();
$check(count($trees) <= 1024 && $bytes <= 64 * 1024 * 1024 && $bytes === array_sum(array_map('strlen', $trees)),
    'long-lived scans retain a bounded cache with accurate byte accounting');
$check(! isset($trees[hash('sha256', $source)]), 'least recently used snapshots are evicted');
$check($method(CodebaseScanSyntax::parse($source))->returnType->toString() === 'Vendor\Widget', 'eviction preserves parsing behavior');
CodebaseScanSyntax::clear();
$check((new ReflectionProperty(CodebaseScanSyntax::class, 'trees'))->getValue() === [], 'new registrations release old cache entries');

$largeLiteral = str_repeat('x', 1024 * 1024);
for ($index = 0; $index < 40; $index++) {
    CodebaseScanSyntax::parse('<?php $value = \''.$index.$largeLiteral.'\';');
}
$trees = (new ReflectionProperty(CodebaseScanSyntax::class, 'trees'))->getValue();
$bytes = (new ReflectionProperty(CodebaseScanSyntax::class, 'bytes'))->getValue();
$check(count($trees) < 40 && $bytes <= 64 * 1024 * 1024 && $bytes === array_sum(array_map('strlen', $trees)),
    'large snapshots trigger byte eviction before the entry-count limit');
CodebaseScanSyntax::clear();
