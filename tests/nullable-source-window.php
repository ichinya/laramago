<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\NullableCollectionOffsetContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use PhpParser\Node;
use PhpParser\NodeFinder;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$directory = sys_get_temp_dir().'/laramago-nullable-window-'.bin2hex(random_bytes(8));
mkdir($directory);
$path = $directory.'/source.php';
$twinPath = $directory.'/twin.php';
$source = '<?php namespace WindowControl; use Vendor\Item as Alias; class Items { public function get(): Alias { throw new \Exception; } }';
$contract = new NullableCollectionOffsetContract($directory, 'WindowControl\\Items');
$read = new ReflectionMethod($contract, 'source');
$within = new ReflectionMethod($contract, 'withSourceWindow');
$locate = new ReflectionMethod($contract, 'located');
$windows = new ReflectionProperty($contract, 'sourceWindows');
$class = static fn (array $file): Node\Stmt\Class_ => (new NodeFinder)->findFirstInstanceOf($file['nodes'], Node\Stmt\Class_::class);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); }
    ++$checks;
};
$run = static fn (Closure $proof): bool => $within->invoke($contract, $proof);
$get = static fn (?string $file = null): ?array => $read->invoke($contract, $file ?? $path);
try {
    file_put_contents($path, $source);
    file_put_contents($twinPath, $source);
    $held = null;
    $run(static function () use ($get, $class, $check, $path, $twinPath, $source, $locate, $contract, &$held): bool {
        $first = $get();
        $again = $get();
        $check($class($first) === $class($again), 'one proof reuses its private read-only syntax');
        $held = $first;
        $node = $class($first);
        $native = new \Mago\Sdk\SourceLocation($path, new \Mago\Sdk\Span($node->getStartFilePos(), $node->getEndFilePos() + 1));
        $twin = $get($twinPath);
        $check($class($first) === $class($twin) && $first['path'] !== $twin['path'], 'shared syntax retains separate physical identities');
        $check(!$locate->invoke($contract, $native, $class($twin), $twin), 'a different physical file cannot satisfy the native location');
        $mtime = filemtime($path);
        file_put_contents($path, str_replace('Vendor\Item', 'Vendor\Else', $source));
        touch($path, $mtime);
        $fresh = $get();
        $check($fresh['hash'] !== $first['hash'] && $class($fresh)->getMethod('get')->returnType->toString() === 'Vendor\Else', 'same-size same-mtime mutation observes current resolved names');
        $check(!$locate->invoke($contract, $native, $node, $first), 'post-metadata hash veto stays fresh inside the window');
        file_put_contents($path, '<?php class Broken {');
        $check($get() === null && $get() === null, 'repeated parse failures cannot reuse retained valid syntax');
        file_put_contents($path, '<?php '.str_repeat(' ', 1024 * 1024));
        $check($get() === null, 'physical source bound is checked before a window lookup');
        file_put_contents($path, $source);
        $check($locate->invoke($contract, $native, $class($get()), $get()), 'exact restoration recovers a current source witness');
        unlink($path);
        $check($get() === null, 'deletion vetoes an existing private syntax entry');
        file_put_contents($path, $source);
        return true;
    });
    $check(count($windows->getValue($contract)) === 0, 'proof exit releases its entire syntax window');
    $class($held)->name->name = 'MutatedOutsideProof';
    $check($class($get())->name->name === 'Items', 'retained test nodes cannot poison a later standalone read');
    $run(static function () use ($get, $class, $check, $run): bool {
        $outer = $get();
        $run(static function () use ($get, $class, $check, $outer): bool {
            $check($class($get()) !== $class($outer), 'nested proof has an independent syntax window');
            return true;
        });
        $check($class($get()) === $class($outer), 'nested exit restores the outer proof window');
        return true;
    });
    try { $run(static function () use ($get): bool { $get(); throw new RuntimeException('Invented proof failure'); }); }
    catch (RuntimeException $failure) { $check($failure->getMessage() === 'Invented proof failure', 'proof exceptions remain observable'); }
    $check(count($windows->getValue($contract)) === 0, 'exception exit releases the window');

    $seen = [];
    $make = static function (string $name) use ($run, $get, $class, $check, &$seen): Fiber { return new Fiber(static function () use ($name, $run, $get, $class, $check, &$seen): void {
        $run(static function () use ($name, $get, $class, $check, &$seen): bool {
            $seen[$name] = $class($get());
            Fiber::suspend();
            $check($class($get()) === $seen[$name], 'each resumed Fiber retains only its own proof syntax');
            return true;
        });
    }); };
    $a = $make('a'); $b = $make('b');
    $a->start(); $b->start();
    $check($seen['a'] !== $seen['b'], 'concurrent Fibers never share expanded proof nodes');
    $a->resume();
    $check(count($windows->getValue($contract)) === 1, 'out-of-order completion releases only the completing Fiber');
    $b->resume();
    $check(count($windows->getValue($contract)) === 0, 'all concurrent proof windows are released');
    $abandoned = new Fiber(static function () use ($run, $get): void {
        $run(static function () use ($get): bool { $get(); Fiber::suspend(); return true; });
    });
    $abandoned->start(); unset($abandoned); gc_collect_cycles();
    $check(count($windows->getValue($contract)) === 0, 'an abandoned Fiber cannot retain its AST window');

    $run(static function () use ($get, $class, $check, $path, $windows, $contract): bool {
        $oldest = null;
        for ($i = 0; $i < 12; ++$i) {
            file_put_contents($path, '<?php class Item'.$i.' {}');
            $file = $get();
            if ($i === 0) { $oldest = WeakReference::create($class($file)); }
        }
        unset($file); gc_collect_cycles();
        $check($oldest->get() === null, 'entry eviction releases expanded nodes while a proof is active');
        $window = $windows->getValue($contract)[$contract];
        $check(count($window->trees) <= 8 && $window->bytes <= 8 * 1024 * 1024, 'the active window has bounded entries and encoded weight');
        $large = '<?php class Large { function run() {'.str_repeat('$v=1;', 15000).'} }';
        file_put_contents($path, $large);
        $file = $get();
        $check($file !== null && strlen(serialize($file['nodes'])) > 8 * 1024 * 1024, 'oversized expanded syntax remains readable');
        $check(!isset($window->trees[$file['hash']]), 'oversized expanded syntax is not retained by the proof');
        return true;
    });
    $check(count($windows->getValue($contract)) === 0, 'bounded-window stress leaves no retained expanded ASTs');
    echo 'PASS nullable source windows: '.$checks." controls.\n";
} finally {
    foreach ([$path, $twinPath] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($directory);
    CodebaseScanSyntax::clear();
}
