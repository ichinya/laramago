<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require dirname(__DIR__).'/vendor/autoload.php';

// Independent previous reader: two expanded snapshots, fresh parser, no shared facts.
final class ProjectionReaderOracle
{
    public array $files = [];
    public array $hashes = [];
    public array $warnings = [];
    public function __construct(private string $root) {}
    public function read(string $file): ?array
    {
        $path = str_starts_with($file, $this->root.'/') ? $file : $this->root.'/'.$file;
        if (array_key_exists($path, $this->files)) {
            $nodes = $this->files[$path]; unset($this->files[$path]);
            return $this->files[$path] = $nodes;
        }
        while (count($this->files) >= 2) { unset($this->files[array_key_first($this->files)]); }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $this->warnings[$path] = 'Cannot read PHP source for static model metadata.';
            return $this->files[$path] = null;
        }
        $this->hashes[$path] = hash('sha256', $contents);
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
            return $this->files[$path] = (new NodeTraverser(new NameResolver))->traverse($nodes);
        } catch (\PhpParser\Error $error) {
            $this->warnings[$path] = 'Cannot parse PHP source for static model metadata: '.$error->getMessage();
            return $this->files[$path] = null;
        }
    }
    public function isCurrent(): bool
    {
        foreach ($this->hashes as $path => $hash) { if (@hash_file('sha256', $path) !== $hash) { return false; } }
        return true;
    }
}

$root = sys_get_temp_dir().'/laramago-projections-'.bin2hex(random_bytes(8));
mkdir($root);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (! $ok) { throw new RuntimeException($label); }
    ++$checks;
};
$class = static fn (?array $nodes): ?Node\Stmt\Class_ => $nodes === null ? null : (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\Class_::class);
$names = static fn (array $nodes): ?string => $class($nodes)?->name?->toString();
$source = static fn (string $name): string => '<?php namespace Example; use Vendor\Thing as Alias; /** Snapshot */ class '.$name.' { function make(): Alias { throw new \Exception; } }';
$reader = new PhpSource($root);
$oracle = new ProjectionReaderOracle($root);
$policy = 'php-source-projection-test:names:v1';
$observe = static function (string $operation, string $file) use ($reader, $oracle, $root, $names, $check, $policy): void {
    $expected = $oracle->read($file);
    if ($operation === 'read') {
        $check(serialize($reader->read($file)) === serialize($expected), 'Expanded snapshot differs from independent reader');
    } else {
        $check($reader->project($file, $policy, $names) === ($expected === null ? null : $names($expected)), 'Projected fact differs from independent reader');
    }
    foreach (['a.php', 'b.php', 'c.php', 'missing.php'] as $name) {
        $path = $root.'/'.$name;
        $check($reader->contentHash($name) === ($oracle->hashes[$path] ?? null), 'Snapshot hash differs');
    }
    $check($reader->warnings === $oracle->warnings, 'Warning identities differ');
    $check($reader->isCurrent() === $oracle->isCurrent(), 'Freshness check differs');
};

try {
    CodebaseScanSyntax::clear(); PhpSource::clearSharedCache();
    foreach (['a.php'=>'First', 'b.php'=>'Other', 'c.php'=>'Third'] as $file => $name) { file_put_contents($root.'/'.$file, $source($name)); }
    $observe('project', 'a.php');
    file_put_contents($root.'/a.php', $source('Later'));
    $observe('project', 'a.php');
    $observe('read', $root.'/a.php'); // Lazy materialization keeps the old bytes.
    $class($reader->read('a.php'))->name->name = 'Edited';
    $class($oracle->read('a.php'))->name->name = 'Edited';
    $observe('project', 'a.php'); // Exposed mutable trees bypass pure-fact caching.
    $observe('project', 'b.php');
    $observe('project', 'a.php');
    $observe('read', 'c.php');
    $observe('project', 'b.php'); // Mixed expanded/lazy entries share one LRU.
    $observe('project', 'a.php');
    CodebaseScanSyntax::clear(); PhpSource::clearSharedCache();
    $observe('read', 'a.php');
    unlink($root.'/a.php');
    $observe('project', 'a.php');
    $observe('project', 'c.php'); $observe('read', 'b.php');
    $observe('project', 'a.php'); // Deleted source after eviction.
    $observe('project', 'missing.php');
    file_put_contents($root.'/missing.php', $source('Created'));
    $observe('read', 'missing.php');
    $observe('read', 'b.php'); $observe('project', 'c.php');
    $observe('project', 'missing.php');
    file_put_contents($root.'/a.php', '<?php invalid syntax');
    $observe('project', 'a.php');
    file_put_contents($root.'/a.php', $source('Fixed'));
    $observe('read', 'a.php');
    $observe('project', 'c.php'); $observe('project', 'b.php');
    $observe('project', 'a.php');

    // A shared result saves expansion but never skips a new reader's physical bytes.
    CodebaseScanSyntax::clear();
    $calls = 0;
    $extract = static function (array $nodes) use ($names, &$calls): ?string { ++$calls; return $names($nodes); };
    $one = new PhpSource($root); $two = new PhpSource($root);
    $check($one->project('a.php', $policy, $extract) === 'Fixed' && $two->project('a.php', $policy, $extract) === 'Fixed' && $calls === 1, 'Identical bytes share only the pure result');
    $stamp = filemtime($root.'/a.php'); file_put_contents($root.'/a.php', $source('Fresh')); touch($root.'/a.php', $stamp);
    $check((new PhpSource($root))->project('a.php', $policy, $extract) === 'Fresh' && $calls === 2, 'Same-size and same-mtime changes invalidate pure facts');
    $check($one->project('a.php', $policy, $extract) === 'Fixed', 'Existing lazy reader keeps its original snapshot');
    $check($one->project('a.php', $policy.':other', static fn (): string => 'Another policy') === 'Another policy', 'Policy identity separates projection options');
    $class($one->read('a.php'))->name->name = 'Mutated';
    $check($one->project('a.php', $policy, $extract) === 'Mutated', 'Public reader mutation remains visible to later projections');
    $check($two->project('a.php', $policy, $extract) === 'Fixed', 'Mutable reader cannot poison another snapshot or shared result');

    foreach ([new RuntimeException('extractor failure'), new \PhpParser\Error('extractor parser error')] as $failure) {
        $failed = new PhpSource($root); $id = $policy.':failure:'.$failure::class;
        try { $failed->project('a.php', $id, static function () use ($failure): never { throw $failure; }); throw new RuntimeException('Extractor exception swallowed'); }
        catch (Throwable $caught) { $check($caught === $failure && $failed->warnings === [], 'Extractor failure remains the same exception without reader warnings'); }
        file_put_contents($root.'/a.php', $source('After'));
        $check($class($failed->read('a.php'))->name->name === ($failure instanceof \PhpParser\Error ? 'After' : 'Fresh'), 'Failed extraction retains the already-read snapshot');
    }
    $unserializable = new PhpSource($root);
    try { $unserializable->project('a.php', $policy.':closure', static fn (): Closure => static fn (): bool => true); throw new RuntimeException('Serialization failure swallowed'); }
    catch (Exception $failure) { $check(str_contains($failure->getMessage(), 'Serialization of') && $unserializable->warnings === [], 'Serialization errors propagate without source warnings'); }

    $bounded = new PhpSource($root);
    for ($index = 0; $index < 20; ++$index) {
        $file = 'bounded-'.$index.'.php'; file_put_contents($root.'/'.$file, $source('Bounded'.$index));
        $check($bounded->project($file, $policy, $extract) === 'Bounded'.$index, 'Bounded reader returns the right class');
        $files = (new ReflectionProperty(PhpSource::class, 'files'))->getValue($bounded);
        $pending = (new ReflectionProperty(PhpSource::class, 'projectionSources'))->getValue($bounded);
        $check(count($files) <= 2 && count($pending) <= 2 && array_diff_key($pending, $files) === [], 'Raw and expanded snapshots share the two-entry bound');
    }
    echo 'PASS PHP source projections: '.$checks." controls.\n";
} finally {
    foreach (glob($root.'/*') as $file) { unlink($file); }
    rmdir($root); CodebaseScanSyntax::clear(); PhpSource::clearSharedCache();
}
