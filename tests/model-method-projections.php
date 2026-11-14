<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\EloquentAggregateProjectionProvider;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

require dirname(__DIR__).'/vendor/autoload.php';

// Selection-only DTOs: these tests make no native metadata or final-proof claim.
$metadata = static function (?string $file, string $name, int $start, int $end): FunctionLikeMetadata {
    $method = (new ReflectionClass(FunctionLikeMetadata::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($method, 'location'))->setValue($method, new SourceLocation($file, new Span($start, $end)));
    (new ReflectionProperty($method, 'originalName'))->setValue($method, $name);
    return $method;
};
$codebase = (new ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
$root = sys_get_temp_dir().'/laramago-method-projections-'.bin2hex(random_bytes(8)); mkdir($root);
$source = <<<'PHP'
<?php
namespace Example;
use Vendor\Row as Alias;
class First { /** Kept */ public function same(): Alias { return new Alias; } }
trait Shared { protected function SAME(): int { return 2; } }
interface Contract { public function same(): int; }
enum Choice { case One; public function other(): void {} }
$anonymous = new class { public function same(): int { return 3; } };
PHP;
$path = $root.'/source.php'; file_put_contents($path, $source);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (! $ok) { throw new RuntimeException($label); } ++$checks; };
$collect = static function (string $bytes): array {
    $visitor = new class extends NodeVisitorAbstract {
        public array $methods = [];
        public function enterNode(Node $node): null { if ($node instanceof Node\Stmt\ClassMethod) { $this->methods[] = $node; } return null; }
    };
    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($bytes) ?? [];
    (new NodeTraverser(new NameResolver, $visitor))->traverse($nodes);
    return $visitor->methods;
};
$fingerprint = static fn (Node\Stmt\ClassMethod $node): array => [$node->name->name, $node->getStartFilePos(), $node->getEndFilePos(), (new Standard)->prettyPrint($node->stmts ?? [])];
$bodyTokens = new ReflectionMethod(EloquentAggregateProjectionProvider::class, 'bodyTokens');
$legacyTokens = static function (Node\Stmt\ClassMethod $node): string {
    $result = '';
    foreach (token_get_all('<?php '.(new Standard)->prettyPrint($node->stmts ?? [])) as $token) {
        if (is_string($token)) { $result .= $token; }
        elseif (! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $result .= $token[1]; }
    }
    return $result;
};
try {
    CodebaseScanSyntax::clear(); PhpSource::clearSharedCache();
    $methods = $collect($source);
    $ranges = [[0, 0], [0, strlen($source)]];
    foreach ($methods as $node) {
        $start = $node->getStartFilePos(); $end = $node->getEndFilePos() + 1;
        array_push($ranges, [$start, $start], [$start, $start + 1], [$start + 1, $end], [max(0, $start - 1), $end]);
    }
    $reader = new PhpSource($root); $reflection = new ModelReflection($codebase, $reader);
    foreach (['same', 'SAME', 'other', 'missing'] as $name) {
        foreach ($ranges as [$start, $end]) {
            $expected = null;
            foreach ($methods as $node) {
                if (strcasecmp($node->name->name, $name) === 0 && $node->getStartFilePos() >= $start && $node->getStartFilePos() < $end) { $expected = $node; break; }
            }
            $method = $metadata($path, $name, $start, $end);
            $actual = $reflection->projectMethod($method, 'test-method:fingerprint', $fingerprint);
            $check($actual === ($expected === null ? null : $fingerprint($expected)), 'Projection differs from independent fresh-parse preorder/location oracle');
            $check($reflection->projectMethod($method, 'test-method:tokens', $legacyTokens) === ($expected === null ? null : $legacyTokens($expected)), 'Policy or selector inputs reuse the wrong source result');
            if ($expected !== null) { $check($bodyTokens->invoke(null, $expected) === $legacyTokens($expected), 'Native body projection differs from the previous printer/token algorithm'); }
        }
    }
    $first = $methods[0]; $method = $metadata($path, 'same', $first->getStartFilePos(), $first->getEndFilePos() + 1);
    $original = $reflection->projectMethod($method, 'test-method:fingerprint', $fingerprint);
    $changed = str_replace('return new Alias;', 'throw new \Exception;', $source); file_put_contents($path, $changed);
    $check($reflection->projectMethod($method, 'test-method:fingerprint', $fingerprint) === $original, 'Unexposed method projection keeps its reader snapshot');
    $mutated = $reflection->methodNode($method); $mutated->stmts = [new Node\Stmt\Return_(new Node\Scalar\Int_(42))];
    $check($reflection->projectMethod($method, 'test-method:fingerprint', $fingerprint) === $fingerprint($mutated), 'Existing public AST mutation remains visible after projection');
    $check($reflection->methodNode($method) === $mutated, 'Public methodNode still returns the reader-owned mutable node');
    $other = new ModelReflection($codebase, new PhpSource($root));
    $check($other->projectMethod($method, 'test-method:fingerprint', $fingerprint) !== $original, 'A new reader observes current physical bytes');
    $check($other->projectMethod($method, 'test-method:fingerprint', $fingerprint) !== $fingerprint($mutated), 'An exposed reader cannot poison another reader or the pure source cache');
    foreach ([null, '@native', $root.'/missing.php'] as $file) {
        $calls = 0;
        $check($reflection->projectMethod($metadata($file, 'same', 0, strlen($source)), 'test:missing', static function () use (&$calls): string { ++$calls; return 'unexpected'; }) === null && $calls === 0, 'Absent/native locations do not reach method extraction');
    }
    file_put_contents($root.'/invalid.php', '<?php malformed syntax');
    $badSource = new PhpSource($root); $bad = new ModelReflection($codebase, $badSource);
    $check($bad->projectMethod($metadata('invalid.php', 'same', 0, 999), 'test:invalid', $fingerprint) === null && count($badSource->warnings) === 1, 'Parse failures retain source-reader warnings');
    echo 'PASS model method projections: '.$checks." controls.\n";
} finally {
    foreach (glob($root.'/*') as $file) { unlink($file); }
    rmdir($root); CodebaseScanSyntax::clear(); PhpSource::clearSharedCache();
}
