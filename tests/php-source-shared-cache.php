<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use PhpParser\Node;
use PhpParser\NodeFinder;

require dirname(__DIR__).'/vendor/autoload.php';

$root = sys_get_temp_dir().'/laramago-php-source-cache-'.bin2hex(random_bytes(8));
mkdir($root);
$path = $root.'/source.php';
$first = <<<'PHP'
    <?php
    namespace Example;
    use Vendor\Widget as Alias;
    /** Keep this comment for other readers. */
    final class Demo {
        public function make(): Alias { throw new \RuntimeException('Never execute source'); }
    }
    PHP;
$second = '<?php namespace Example; final class Updated {}';
$assert = static function (bool $condition, string $label): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    echo 'PASS: '.$label."\n";
};
$class = static function (?array $nodes): ?Node\Stmt\Class_ {
    return $nodes === null ? null : (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\Class_::class);
};

try {
    file_put_contents($path, $first);
    $original = new PhpSource($root);
    $originalNodes = $original->read('source.php');
    $reader = new PhpSource($root);
    $readClass = $class($reader->read('source.php'));
    $originalClass = $class($originalNodes);
    $assert(
        $readClass?->getMethod('make')?->returnType?->toString() === 'Vendor\\Widget'
        && $readClass->getDocComment()?->getText() === '/** Keep this comment for other readers. */'
        && $readClass !== $originalClass,
        'shared resolved source retains imports and comments in an independent AST',
    );

    $readClass->getMethod('make')->returnType = new Node\Name('Changed');
    $readClass->setAttribute('comments', []);
    $later = new PhpSource($root);
    $laterClass = $class($later->read('source.php'));
    $assert(
        $laterClass?->getMethod('make')?->returnType?->toString() === 'Vendor\\Widget'
        && $laterClass->getDocComment() !== null
        && $originalClass?->getMethod('make')?->returnType?->toString() === 'Vendor\\Widget',
        'mutating one reader cannot change another reader or the shared copy',
    );

    file_put_contents($path, $second);
    $changed = new PhpSource($root);
    $assert(
        $class($changed->read('source.php'))?->name?->toString() === 'Updated'
        && $changed->contentHash('source.php') === hash('sha256', $second)
        && $original->read('source.php') === $originalNodes
        && $original->contentHash('source.php') === hash('sha256', $first),
        'new readers observe source edits while existing readers keep their exact snapshot',
    );

    PhpSource::clearSharedCache();
    $assert(
        $original->read('source.php') === $originalNodes
        && $class((new PhpSource($root))->read('source.php'))?->name?->toString() === 'Updated',
        'plugin cache reset leaves existing reader snapshots intact',
    );

    file_put_contents($path, '<?php broken syntax');
    $invalid = new PhpSource($root);
    $assert(
        $invalid->read('source.php') === null && isset($invalid->warnings[$path]),
        'parse failures keep reader warnings',
    );
    file_put_contents($path, $second);
    $assert(
        $invalid->read('source.php') === null
        && $class((new PhpSource($root))->read('source.php'))?->name?->toString() === 'Updated',
        'a failed reader stays a snapshot while a new reader can recover',
    );

    $missing = new PhpSource($root);
    $assert(
        $missing->read('missing.php') === null
        && $missing->contentHash('missing.php') === null
        && isset($missing->warnings[$root.'/missing.php']),
        'missing source keeps its warning and has no parsed content hash',
    );
    file_put_contents($root.'/missing.php', $second);
    $assert(
        $class((new PhpSource($root))->read('missing.php'))?->name?->toString() === 'Updated',
        'a later reader observes a previously missing file',
    );

    $bounded = new PhpSource($root);
    $oldest = null;
    for ($index = 0; $index < 12; $index++) {
        $file = 'bounded-'.$index.'.php';
        file_put_contents($root.'/'.$file, '<?php namespace Example; final class Cached'.$index.' {}');
        $nodes = $bounded->read($file);
        if ($index === 0) {
            $oldest = WeakReference::create($class($nodes));
        }
        unset($nodes);
        if ($index === 2) {
            gc_collect_cycles();
            $assert($oldest?->get() === null, 'a reader releases the oldest AST before retaining a third parsed file');
        }
    }
    gc_collect_cycles();
    $assert($oldest?->get() === null, 'a long-lived reader releases old AST objects within a bounded window');
    file_put_contents($root.'/bounded-0.php', '<?php namespace Example; final class AfterEviction {}');
    $assert(
        $class($bounded->read('bounded-0.php'))?->name?->toString() === 'AfterEviction',
        'an evicted source is reparsed from current bytes without executing it',
    );
} finally {
    foreach (['source.php', 'missing.php', ...array_map(static fn (int $index): string => 'bounded-'.$index.'.php', range(0, 11))] as $file) {
        if (is_file($root.'/'.$file)) {
            unlink($root.'/'.$file);
        }
    }
    rmdir($root);
}
