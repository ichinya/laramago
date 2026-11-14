<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\NullableCollectionOffsetContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use PhpParser\NodeFinder;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$directory = sys_get_temp_dir().'/laramago-nullable-source-'.bin2hex(random_bytes(8));
mkdir($directory);
$path = $directory.'/source.php';
$twinPath = $directory.'/twin.php';
$source = '<?php namespace SourceControl; use Vendor\Item as Alias; /** Original. */ class Items { public function get(): Alias { throw new \Exception; } }';
$contract = new NullableCollectionOffsetContract($directory, 'SourceControl\\Items');
$read = new ReflectionMethod($contract, 'source');
$locate = new ReflectionMethod($contract, 'located');
$class = static fn (array $file): Node\Stmt\Class_ => (new NodeFinder)->findFirstInstanceOf($file['nodes'], Node\Stmt\Class_::class);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($label); }
    ++$checks;
    echo 'PASS: '.$label."\n";
};
try {
    CodebaseScanSyntax::clear();
    file_put_contents($path, $source);
    $first = $read->invoke($contract, $path);
    $oracle = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
    $oracle = (new NodeTraverser(new NameResolver))->traverse($oracle);
    $check($first !== null && serialize($first['nodes']) === serialize($oracle), 'cached syntax equals a fresh resolved parse including spans and comments');
    $cache = (new ReflectionProperty(CodebaseScanSyntax::class, 'trees'))->getValue();
    $check(isset($cache[hash('sha256', $source)]), 'physical reader uses the existing bounded exact-byte cache');
    $syntax = $class($first);
    $native = new \Mago\Sdk\SourceLocation($path, new \Mago\Sdk\Span($syntax->getStartFilePos(), $syntax->getEndFilePos() + 1));
    $check($locate->invoke($contract, $native, $syntax, $first), 'unchanged source remains located');
    $syntax->name->name = 'Mutated';
    $syntax->getMethod('get')->setAttribute('startFilePos', 0);
    $again = $read->invoke($contract, 'source.php');
    $check(serialize($again['nodes']) === serialize($oracle), 'cache hits isolate nested node mutations and retain original spans');
    file_put_contents($twinPath, $source);
    $twin = $read->invoke($contract, $twinPath);
    $check($twin['hash'] === $again['hash'] && $twin['path'] !== $again['path'], 'identical bytes at different paths share syntax without sharing file identity');
    $check(! $locate->invoke($contract, $native, $class($twin), $twin), 'identical syntax from a different file cannot satisfy a native location');
    $changed = str_replace('Vendor\Item', 'Vendor\Else', $source);
    $mtime = filemtime($path);
    file_put_contents($path, $changed);
    touch($path, $mtime);
    $fresh = $read->invoke($contract, $path);
    $check($fresh['hash'] !== $first['hash'] && $class($fresh)->getMethod('get')->returnType->toString() === 'Vendor\Else', 'same-size same-mtime mutation reads and resolves current bytes');
    $check(! $locate->invoke($contract, $native, $class($again), $again), 'post-metadata hash check rejects a previously located snapshot after disk mutation');
    file_put_contents($path, '<?php class Broken {');
    $check($read->invoke($contract, $path) === null && $read->invoke($contract, $path) === null, 'repeated malformed source cannot reuse valid syntax');
    file_put_contents($path, '<?php '.str_repeat(' ', 1024 * 1024));
    $check($read->invoke($contract, $path) === null, 'physical source size limit still applies');
    file_put_contents($path, $source);
    $restored = $read->invoke($contract, $path);
    $check($restored['hash'] === $first['hash'] && $locate->invoke($contract, $native, $class($restored), $restored), 'exact source restoration recovers the original proof');
    unlink($path);
    $check($read->invoke($contract, $path) === null && $read->invoke($contract, null) === null, 'deleted or absent source cannot reuse retained syntax');
    echo 'PASS nullable source cache: '.$checks." controls.\n";
} finally {
    if (is_file($path)) { unlink($path); }
    if (is_file($twinPath)) { unlink($twinPath); }
    rmdir($directory);
    CodebaseScanSyntax::clear();
}
