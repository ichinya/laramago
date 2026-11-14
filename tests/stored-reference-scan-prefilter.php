<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis {
    /** Test-only oracle: force the unchanged parser/candidate path selected by the old raw-& gate. */
    function token_get_all(string $source): array {
        if ($GLOBALS['laramago_test_reference_lexer_error'] ?? false) { throw new \ParseError('Invented lexical failure'); }
        if ($GLOBALS['laramago_test_force_reference_resolver'] ?? false) {
            return [[T_USE, 'use', 1], '(', [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, '&', 1], [T_VARIABLE, '$cell', 1], ')'];
        }
        return \token_get_all($source);
    }
}

namespace {

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\StoredReferencePossibleWrites;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); }
    ++$checks;
};
$state = static fn (object $scanner, string $property): mixed => (new ReflectionProperty($scanner, $property))->getValue($scanner);
$version = PHPVersion::fromParts(8, 5);
$token = new class implements CancellationTokenInterface {
    public bool $cancelled = false;
    public function isCancelled(): bool { return $this->cancelled; }
    public function throwIfCancelled(): void { if ($this->cancelled) { throw new RuntimeException('Scan cancelled.'); } }
    public function subscribe(Closure $callback): int { return 0; }
    public function unsubscribe(int $subscription): void {}
};
$file = static fn (string $path, string $contents): SourceFile => new SourceFile($version, $path, $contents, [],
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-reference-scan-'.bin2hex(random_bytes(8));
mkdir($root);
$source = <<<'PHP'
<?php
namespace ScanControl;
use Vendor\Thing as Alias;
use Vendor\{Other as Grouped, Third};
trait Shared { public function body(): Alias { throw new \Exception; } }
interface Contract {}
enum Choice { case One; }
/** @see Alias */
class First { /** @var Alias|null */ public ?Alias $item = null; }
class Second extends First { use Shared; }
PHP;
// Both runs receive identical bytes. The inert marker activates the previous
// raw-& gate; only the oracle's test lexer forces the unchanged custom path.
$marked = static fn (string $text): string => $text.' /* & */';
$snapshot = static function (StoredReferencePossibleWrites $scanner) use ($state): array {
    $result = [];
    foreach (['files', 'classes', 'complete', 'failed', 'started', 'bytes', 'scanFailure'] as $name) {
        $result[$name] = $state($scanner, $name);
    }
    $inner = $state($scanner, 'sources');
    foreach (['files', 'classes', 'complete', 'failed', 'bytes'] as $name) { $result['declarations'][$name] = $state($inner, $name); }
    return $result;
};
$run = static function (array $batches, bool $legacy) use ($root, $token, $file, $version, $marked, $snapshot): array {
    $scanner = new StoredReferencePossibleWrites($root);
    $receipts = [];
    $GLOBALS['laramago_test_force_reference_resolver'] = $legacy;
    try {
        foreach ($batches as [$inputs, $first, $last, $cancelled]) {
            $token->cancelled = $cancelled;
            $files = [];
            foreach ($inputs as [$path, $contents]) { $files[] = $file($path, $marked($contents)); }
            $exception = null;
            try { $scanner->scan(new CodebaseScanContext($version, $token, $files, $first, $last)); }
            catch (Throwable $failure) { $exception = [$failure::class, $failure->getMessage()]; }
            $receipts[] = [$snapshot($scanner), $exception];
        }
        $token->cancelled = false;
        return $receipts;
    } finally { unset($GLOBALS['laramago_test_force_reference_resolver']); $token->cancelled = false; }
};
try {
    $capture = new ReflectionMethod(StoredReferencePossibleWrites::class, 'mayCaptureByReference');
    $vectors = [
        'direct' => ['<?php $f = function () use (&$cell) {};', true],
        'uppercase' => ['<?php $f = FUNCTION () USE (&$cell) {};', true],
        'comments before list' => ['<?php $f = function () use /* marker */ ( /* next */ &$cell) {};', true],
        'comments after ampersand' => ['<?php $f = function () use (& /* next */ $cell) {};', true],
        'doc comments' => ['<?php $f = function () use /** marker */ ( &/** next */$cell) {};', true],
        'later reference' => ['<?php $f = function () use ($other, &$cell) {};', true],
        'multiple references' => ['<?php $f = function () use (&$first, &$cell, $last) {};', true],
        'nested closure' => ['<?php $f = function () { return function () use (&$cell) {}; };', true],
        'by value' => ['<?php $f = function () use ($cell) {};', false],
        'by-reference parameter' => ['<?php function f(&$cell) {}', false],
        'by-reference return' => ['<?php function &f() { static $cell; return $cell; }', false],
        'logical and' => ['<?php $cell = $first && $second;', false],
        'bitwise and' => ['<?php $cell = $first & $second;', false],
        'intersection type' => ['<?php function f(A&B $value): void {}', false],
        'trait and namespace imports' => ['<?php use Vendor\A; class Example { use SomeTrait; } /* & */', false],
        'docblock text' => ['<?php /** use (&$cell) */ class Example {}', false],
        'quoted text' => ['<?php $text = \'use (&$cell)\';', false],
        'interpolated text' => ['<?php $text = "use (&$cell)";', false],
        'nowdoc' => ["<?php \$text = <<<'TEXT'\nuse (&\$cell)\nTEXT;", false],
        'inline HTML' => ['use (&$cell) <?php class Example {}', false],
        'string then capture' => ['<?php $text = "use (&$fake)"; $f = function () use ($other, &$cell) {};', true],
    ];
    foreach ($vectors as $label => [$bytes, $expected]) {
        $check($capture->invoke(null, $bytes) === $expected, 'real lexer selection: '.$label);
        $nodes = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($bytes) ?? [];
        $references = (new \PhpParser\NodeFinder)->find($nodes, static fn (\PhpParser\Node $node): bool => $node instanceof \PhpParser\Node\ClosureUse && $node->byRef);
        $check(($references !== []) === $expected, 'fresh AST independently confirms the capture witness: '.$label);
        $check($references === [] || $capture->invoke(null, $bytes), 'fresh AST reference captures are never rejected: '.$label);
    }
    $GLOBALS['laramago_test_reference_lexer_error'] = true;
    $check($capture->invoke(null, '<?php class Example {} /* & */'), 'lexical failures conservatively retain the original custom parser');
    unset($GLOBALS['laramago_test_reference_lexer_error']);
    $scenarios = [
        'all declarations' => [[[['one.php', $source]], true, true, false]],
        'ticks' => [[[['one.php', '<?php declare(ticks=1); class Tick {}']], true, true, false]],
        'logical ampersands' => [[[['one.php', '<?php class Example { function f($a, $b) { return $a && $b; } }']], true, true, false]],
        'string captures' => [[[['one.php', '<?php class Example { function f() { return \'use (&$cell)\'; } }']], true, true, false]],
        'by-reference parameters' => [[[['one.php', '<?php class Example { function f(&$cell) {} }']], true, true, false]],
        'malformed syntax' => [[[['one.php', '<?php class Broken {']], true, true, false]],
        'duplicate imports' => [[[['one.php', '<?php use A\B as Same; use A\C as Same; class Broken {}']], true, true, false]],
        'duplicate classes' => [[[['one.php', $source], ['two.php', $source]], true, true, false]],
        'duplicate paths' => [[[['one.php', $source], ['one.php', '<?php class Other {}']], true, true, false]],
        'empty path' => [[[['', $source]], true, true, false]],
        'file bound' => [[[['large.php', '<?php '.str_repeat(' ', 2_000_000)]], true, true, false]],
        'batch completion' => [[[['one.php', $source]], true, false, false], [[['two.php', '<?php class Later {}']], false, true, false]],
        'absent first batch' => [[[['one.php', $source]], false, true, false]],
        'after last batch' => [[[['one.php', $source]], true, true, false], [[['two.php', '<?php class Later {}']], false, true, false]],
        'generation reset' => [[[['one.php', '<?php class Broken {']], true, true, false], [[['one.php', $source]], true, true, false]],
        'cancelled' => [[[['one.php', $source]], true, true, true]],
        'cancelled continuation' => [[[['one.php', $source]], true, false, false], [[['two.php', '<?php class Later {}']], false, true, true]],
    ];
    foreach ($scenarios as $label => $batches) {
        CodebaseScanSyntax::clear();
        $check($run($batches, false) === $run($batches, true), $label.' matches the unchanged parser and candidate traversal');
    }
    CodebaseScanSyntax::clear();
    $bytes = $marked($source);
    file_put_contents($root.'/one.php', $bytes);
    $scanner = new StoredReferencePossibleWrites($root);
    $scanner->scan(new CodebaseScanContext($version, $token, [$file('one.php', $bytes)], true, true));
    $check($state($scanner, 'complete') && count($state($scanner, 'classes')) === 2, 'every named class remains indexed');
    $check(count($state($state($scanner, 'sources'), 'classes')) === 5, 'trait, interface and enum declarations remain available to native checks');
    $trees = (new ReflectionProperty(CodebaseScanSyntax::class, 'trees'))->getValue();
    $check(isset($trees[hash('sha256', $bytes)]), 'marker-negative input uses the shared bounded syntax cache');
    $check($scanner->proofs('one.php', $bytes) === [], 'marker-negative source produces no reference write proofs');
    $binding = $scanner->declaration('ScanControl\First');
    $parse = new ReflectionMethod(StoredReferencePossibleWrites::class, 'parse');
    $oracle = (new \PhpParser\NodeFinder)->findFirstInstanceOf($parse->invoke(null, $bytes), \PhpParser\Node\Stmt\Class_::class);
    $check($binding !== null && serialize($binding['node']) === serialize($oracle), 'late hydration retains the original PHPDoc alias resolver and all AST attributes');
    $check($binding['node']->getAttribute('storedDocNames')['alias'] === 'Vendor\Thing', 'late PHPDoc alias binding is retained');
    file_put_contents($root.'/one.php', str_replace('Vendor\Thing', 'Vendor\Other', $bytes));
    $check($scanner->declaration('ScanControl\First') === null, 'physical source mutation vetoes an already hydrated declaration');
    unlink($root.'/one.php');
    $check($scanner->declaration('ScanControl\Second') === null, 'deleted source cannot hydrate a retained class index');
    echo 'PASS stored reference scan prefilter: '.$checks." controls.\n";
} finally {
    unset($GLOBALS['laramago_test_force_reference_resolver'], $GLOBALS['laramago_test_reference_lexer_error']);
    if (is_file($root.'/one.php')) { unlink($root.'/one.php'); }
    rmdir($root);
    CodebaseScanSyntax::clear();
}

}
