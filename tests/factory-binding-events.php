<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\FactoryReflection;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;

require dirname(__DIR__).'/vendor/autoload.php';
$candidate = new ReflectionMethod(FactoryReflection::class, 'bindingFromNodes');
// Independent full-tree traversal is the previous binding algorithm.
$oracle = static function (array $nodes, string $class, string $base): ?string {
    $resolver = new NameResolver;
    $visitor = new class($resolver, $class, $base) extends NodeVisitorAbstract {
        public ?string $binding = null;
        private bool $inside = false;
        public function __construct(private NameResolver $resolver, private string $class, private string $base) {}
        public function enterNode(Node $node): null
        {
            if ($node instanceof Node\Stmt\ClassLike) {
                $this->inside = strcasecmp($node->namespacedName?->toString() ?? '', $this->class) === 0;
            }
            if (!$this->inside || !($node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\TraitUse)) { return null; }
            preg_match_all('/@(?:phpstan-|psalm-)?(?:extends|use|template-extends|template-use)\s+([\\\\\w]+)\s*<\s*([\\\\\w]+)\s*>/', $node->getDocComment()?->getText() ?? '', $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $parent = str_starts_with($match[1], '\\') ? new Node\Name\FullyQualified(substr($match[1], 1)) : new Node\Name($match[1]);
                $argument = str_starts_with($match[2], '\\') ? new Node\Name\FullyQualified(substr($match[2], 1)) : new Node\Name($match[2]);
                if (strcasecmp($this->resolver->getNameContext()->getResolvedClassName($parent)->toString(), $this->base) === 0) {
                    $this->binding = $this->resolver->getNameContext()->getResolvedClassName($argument)->toString();
                }
            }
            return null;
        }
    };
    (new NodeTraverser($resolver, $visitor))->traverse($nodes);
    return $visitor->binding;
};
$checks = 0;
$check = static function (string $source, string $owner, string $base, ?string $expected, string $label) use ($candidate, $oracle, &$checks): void {
    $nodes = CodebaseScanSyntax::parse($source);
    $original = $oracle(unserialize(serialize($nodes)), $owner, $base);
    $actual = $candidate->invoke(null, $nodes, $owner, $base);
    if ($original !== $expected || $actual !== $expected) { throw new RuntimeException($label.': binding differs from the full-tree oracle or expected class'); }
    ++$checks;
};
try {
    $contexts = [
        ['namespace Probe; use Vendor\Base as B; use App\Row as R;', 'Probe\Example', 'Vendor\Base', 'B', 'R', 'App\Row'],
        ['namespace Probe; use Vendor\{Base as B, Row as R};', 'Probe\Example', 'Vendor\Base', 'B', 'R', 'Vendor\Row'],
        ['use Vendor\Base as B; use App\Row as R;', 'Example', 'Vendor\Base', 'B', 'R', 'App\Row'],
        ['namespace Probe; use function Other\B; use const Other\R; use Vendor\Base as B; use App\Row as R;', 'Probe\Example', 'Vendor\Base', 'B', 'R', 'App\Row'],
        ['namespace Probe;', 'Probe\Example', 'Vendor\Base', '\\Vendor\\Base', '\\App\\Row', 'App\Row'],
        ['namespace Probe;', 'Probe\Example', 'Probe\Base', 'Base', 'Row', 'Probe\Row'],
    ];
    $body = 'public function f(\Vendor\Base $p): \App\Row { $c = fn(\Vendor\Base $v): \App\Row => new \App\Row; try { $p::method(); } catch (\Exception $e) {} return $c($p); }';
    foreach ($contexts as [$imports, $owner, $base, $parent, $argument, $expected]) {
        foreach (['', 'phpstan-', 'psalm-'] as $prefix) {
            foreach (['extends', 'use', 'template-extends', 'template-use'] as $tag) {
                $doc = '/** @'.$prefix.$tag.' '.$parent.'<'.$argument.'> */';
                foreach (['class', 'trait', 'interface', 'enum'] as $kind) {
                    $members = in_array($kind, ['class', 'trait'], true) ? $body : '';
                    $check('<?php '.$imports.' '.$doc.' '.$kind.' Example {'.$members.'}', $owner, $base, $expected, 'declaration alias/context '.$kind);
                }
                $check('<?php '.$imports.' class Example {'.$body.' '.$doc.' use T;}', $owner, $base, $expected, 'trait-use alias/context');
                $check('<?php '.$imports.' class Example { public function f() { return new class {}; } '.$doc.' use T;}', $owner, $base, null, 'anonymous class owner event before trait use');
                $check('<?php '.$imports.' class Example { '.$doc.' use T; public function f() { class Nested {} } }', $owner, $base, $expected, 'nested class owner event after trait use');
                $check('<?php '.$imports.' class Example { public function f() { class Nested {} } '.$doc.' use T;}', $owner, $base, null, 'nested class owner event before trait use');
            }
        }
    }
    $check('<?php namespace One { use A\Base as B; use A\Row as R; /** @extends B<R> */ class Example {} } namespace Two { use Z\Base as B; use Z\Row as R; /** @extends B<R> */ class Example {} }', 'Two\Example', 'Z\Base', 'Z\Row', 'bracketed namespace resets aliases');
    $check('<?php namespace One { class Other {} } namespace { use A\Base as B; /** @extends B<\A\Row> */ class Example {} }', 'Example', 'A\Base', 'A\Row', 'global namespace event');
    $check('<?php namespace Probe; /** @extends Base<One> @psalm-extends Base<Two> */ class Example {}', 'Probe\Example', 'Probe\Base', 'Probe\Two', 'last matching tag wins');
    $check('<?php namespace Probe; /** @extends Base<One|Two> */ class Example {}', 'Probe\Example', 'Probe\Base', null, 'unsupported union binding stays unknown');
    $check('<?php namespace Probe; class Example { /** @extends Base<Row> */ public function f() {} }', 'Probe\Example', 'Probe\Base', null, 'method docblocks do not declare a class binding');
    $check('<?php namespace Probe; /** @extends Base<Row> */ class Example {}', 'probe\example', 'probe\base', 'Probe\Row', 'case-insensitive class/base lookup');
    echo 'Factory binding event parity passed: '.$checks." controls.\n";
} finally { CodebaseScanSyntax::clear(); }
