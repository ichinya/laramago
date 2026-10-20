<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\CoalesceModelPropertyIssueFilter;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use PhpParser\NodeFinder;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$directory = sys_get_temp_dir().'/laramago-coalesce-source-'.bin2hex(random_bytes(8));
mkdir($directory);
$path = $directory.'/source.php';
$twinPath = $directory.'/twin.php';
$source = '<?php namespace SourceControl; use Vendor\Item as Alias; /** Original. */ class Items { public function get(): Alias { throw new \Exception; } }';
$contract = new CoalesceModelPropertyIssueFilter($directory, 'SourceControl\\Items');
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
    $check($read->invoke($contract, $path, $source) !== null && $read->invoke($contract, $path, $source.' ') === null,
        'cached syntax cannot bypass equality with the analyzed source bytes');
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
    $probeSource = '<?php $a = $record->value ?? null; $b = $record->child->value ?? null; '
        .'$c = $GLOBALS->value ?? null; $d = $_SERVER->value ?? null; $e = $_ENV->value ?? null; '
        .'$f = $record?->value ?? null; $g = $record->{$field} ?? null; $h = $record->bare;';
    file_put_contents($path, $probeSource);
    $probes = $read->invoke($contract, $path, $probeSource, 'coalesce-probes');
    $check($probes !== null && array_column($probes['nodes'], 'name') === ['value', 'value'],
        'compact probes keep direct coalesce-left order and exclude intermediate, unsupported and dynamic reads');
    $receivers = [];
    foreach ($probes['nodes'] as $probe) {
        $check(substr($probeSource, $probe['nameSpan'][0], $probe['nameSpan'][1] - $probe['nameSpan'][0]) === $probe['name'],
            'compact name spans still identify original source bytes');
        $receivers[] = substr($probeSource, $probe['receiverSpan'][0], $probe['receiverSpan'][1] - $probe['receiverSpan'][0]);
    }
    $check($receivers === ['$record', '$record->child'], 'compact receiver spans preserve nested property selection');
    $probes['nodes'][0]['name'] = 'Poison';
    $check($read->invoke($contract, $path, $probeSource, 'coalesce-probes')['nodes'][0]['name'] === 'value',
        'scalar probe mutations cannot cross cached consumers');
    $check($read->invoke($contract, $path, $probeSource.' ', 'coalesce-probes') === null,
        'compact probes still require exact analyzed bytes');
    $modelSource = '<?php namespace SourceControl; class First { '
        .'/** Getter. */ public function __get($key) { return $this->getAttribute($key); } '
        .'public function ignored() { return new \stdClass; } '
        .'public function __isset($key) { return $this->offsetExists($key); } } '
        .'class Second { public function __get($key) { return $this->other($key); } }';
    file_put_contents($path, $modelSource);
    $firstModel = new CoalesceModelPropertyIssueFilter($directory, 'SourceControl\\First');
    $secondModel = new CoalesceModelPropertyIssueFilter($directory, 'SourceControl\\Second');
    $model = $read->invoke($firstModel, $path, null, 'model-declaration');
    $check(count($model['nodes']) === 1 && $model['nodes'][0]->name->name === 'First'
        && array_map(static fn (Node\Stmt\ClassMethod $method): string => $method->name->name, $model['nodes'][0]->getMethods()) === ['__get', '__isset'],
        'model projection keeps only the unique selected declaration and both complete magic methods');
    $fullModel = $read->invoke($firstModel, $path);
    $originalModel = (new NodeFinder)->findFirst($fullModel['nodes'], static fn (Node $node): bool => $node instanceof Node\Stmt\Class_ && $node->name?->name === 'First');
    foreach (['__get', '__isset'] as $method) {
        $check(serialize($model['nodes'][0]->getMethod($method)) === serialize($originalModel->getMethod($method)),
            'complete magic method syntax retains its body, signature, docblock and locations');
    }
    $check($read->invoke($secondModel, $path, null, 'model-declaration')['nodes'][0]->name->name === 'Second',
        'model identity is part of the pure projection policy');
    $model['nodes'][0]->getMethod('__get')->stmts = [];
    $check(serialize($read->invoke($firstModel, $path, null, 'model-declaration')['nodes'][0]->getMethod('__get')) === serialize($originalModel->getMethod('__get')),
        'model projection owns mutable magic-method nodes');
    $modelTime = filemtime($path);
    $renamed = str_replace('class First', 'class Third', $modelSource);
    file_put_contents($path, $renamed); touch($path, $modelTime);
    $check($read->invoke($firstModel, $path, null, 'model-declaration')['nodes'] === [],
        'same-size same-mtime replacement cannot reuse the former model declaration');
    file_put_contents($path, $modelSource.' class First {}');
    $check($read->invoke($firstModel, $path, null, 'model-declaration')['nodes'] === [],
        'ambiguous model declarations remain unsupported');
    unlink($path);
    $check($read->invoke($contract, $path) === null && $read->invoke($contract, null) === null, 'deleted or absent source cannot reuse retained syntax');
    echo 'PASS coalesce source cache: '.$checks." controls.\n";
} finally {
    if (is_file($path)) { unlink($path); }
    if (is_file($twinPath)) { unlink($twinPath); }
    rmdir($directory);
    CodebaseScanSyntax::clear();
}
