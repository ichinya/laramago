<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago object documentation '.bin2hex(random_bytes(8));
if (! is_dir($workspace.'/cases')) { mkdir($workspace.'/cases', recursive: true); }
if (! is_dir($workspace.'/packages/composer')) { mkdir($workspace.'/packages/composer', recursive: true); }
if (! is_dir($workspace.'/database/migrations')) { mkdir($workspace.'/database/migrations', recursive: true); }
$trap = '<?php file_put_contents(__DIR__."/executed", "executed"); throw new RuntimeException("Analyzed body executed.");';
file_put_contents($workspace.'/bootstrap.php', $trap);
file_put_contents($workspace.'/database/migrations/001_trap.php', $trap);
file_put_contents($workspace.'/packages/composer/trap.php', $trap);
file_put_contents($workspace.'/composer.json', '{"config":{"vendor-dir":"packages"},"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/packages/composer/installed.json', '{"packages":[]}');
file_put_contents($workspace.'/packages/composer/autoload_files.php', '<?php return ["trap" => __DIR__."/trap.php"];');
file_put_contents($workspace.'/contracts.php', <<<'PHP'
<?php
namespace DocFixtures;
file_put_contents(__DIR__.'/contract-body-executed', 'Analyzed declaration body must not execute.');
class ParentAnchor {}
interface NamedContract { public function name(): string; }
class Anchor extends ParentAnchor { public function name(): string { return "anchor"; } public function ready(): bool { return true; } }
class OtherAnchor {}
/** @template T */
class GenericAnchor {}
function produce(): Anchor { return new Anchor; }
/** @return list<Anchor> */ function produceMany(): array { return [new Anchor]; }
function consumeText(string $value): void {}
PHP);

/** @var array<string, array{source: string, removed: int, code?: string}> $cases */
$cases = [
    'imported object' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { /** @var Anchor $value */ $value = \DocFixtures\produce(); }', 'removed' => 1],
    'fully qualified object' => ['source' => 'function scenario(): void { /** @var \DocFixtures\Anchor $value */ $value = \DocFixtures\produce(); }', 'removed' => 1],
    'import alias' => ['source' => 'use DocFixtures\Anchor as LocalAnchor; function scenario(): void { /** @var LocalAnchor $value */ $value = \DocFixtures\produce(); }', 'removed' => 1],
    'namespace object' => ['source' => 'namespace DocFixtures; function scenario(): void { /** @var Anchor $value */ $value = produce(); }', 'removed' => 1],
    'grouped import' => ['source' => 'use DocFixtures\{Anchor as LocalAnchor}; function scenario(): void { /** @var LocalAnchor $value */ $value = \DocFixtures\produce(); }', 'removed' => 1],
    'instance method' => ['source' => 'use DocFixtures\Anchor; class Example { public function scenario(): void { /** @var Anchor $value */ $value = \DocFixtures\produce(); } }', 'removed' => 1],
    'static method' => ['source' => 'use DocFixtures\Anchor; class Example { public static function scenario(): void { /** @var Anchor $value */ $value = \DocFixtures\produce(); } }', 'removed' => 1],
    'multiline tag' => ['source' => "use DocFixtures\\Anchor; function scenario(): void { /**\n * @var Anchor \$value\n */ \$value = \\DocFixtures\\produce(); }", 'removed' => 1],
    'residual method error' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { /** @var Anchor $value */ $value = \DocFixtures\produce(); $value->missing(); }', 'removed' => 1, 'code' => 'non-existent-method'],
    'residual argument error' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { /** @var Anchor $value */ $value = \DocFixtures\produce(); \DocFixtures\consumeText($value); }', 'removed' => 1, 'code' => 'invalid-argument'],
    'broader annotation' => ['source' => 'use DocFixtures\ParentAnchor; function scenario(): void { /** @var ParentAnchor $value */ $value = \DocFixtures\produce(); \DocFixtures\consumeText($value); }', 'removed' => 0],
    'different object annotation' => ['source' => 'use DocFixtures\OtherAnchor; function scenario(): void { /** @var OtherAnchor $value */ $value = \DocFixtures\produce(); \DocFixtures\consumeText($value); }', 'removed' => 0],
    'unknown class annotation' => ['source' => 'function scenario(): void { /** @var UnknownAnchor $value */ $value = \DocFixtures\produce(); \DocFixtures\consumeText($value); }', 'removed' => 0],
    'scalar annotation' => ['source' => 'function scenario(string $input): void { /** @var string $value */ $value = $input; $value->missing(); }', 'removed' => 0],
    'array annotation' => ['source' => 'function scenario(array $input): void { /** @var array<array-key, mixed> $value */ $value = $input; \DocFixtures\consumeText($value); }', 'removed' => 0],
    'union annotation' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { /** @var Anchor|null $value */ $value = \DocFixtures\produce(); \DocFixtures\consumeText($value); }', 'removed' => 0],
    'generic object annotation' => ['source' => 'use DocFixtures\GenericAnchor; function scenario(GenericAnchor $input): void { /** @var GenericAnchor $value */ $value = $input; \DocFixtures\consumeText($value); }', 'removed' => 0],
    'malformed annotation' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { /** @var Anchor< $value */ $value = \DocFixtures\produce(); \DocFixtures\consumeText($value); }', 'removed' => 0],
    'multiple tags' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { /** @var Anchor $value @var Anchor $other */ $value = \DocFixtures\produce(); $value->missing(); }', 'removed' => 0],
    'implicit variable tag' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { /** @var Anchor */ $value = \DocFixtures\produce(); $value->missing(); }', 'removed' => 0],
    'tag for other variable' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { /** @var Anchor $other */ $value = \DocFixtures\produce(); $value->missing(); }', 'removed' => 0],
    'top-level binding' => ['source' => 'use DocFixtures\Anchor; /** @var Anchor $value */ $value = \DocFixtures\produce(); $value->missing();', 'removed' => 0],
    'conditional assignment' => ['source' => 'use DocFixtures\Anchor; function scenario(bool $condition): void { if ($condition) { /** @var Anchor $value */ $value = \DocFixtures\produce(); $value->missing(); } }', 'removed' => 0],
    'global binding' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { global $value; /** @var Anchor $value */ $value = \DocFixtures\produce(); $value->missing(); }', 'removed' => 0],
    'static binding' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { static $value; /** @var Anchor $value */ $value = \DocFixtures\produce(); $value->missing(); }', 'removed' => 0],
    'reference binding' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { $value =& $input; /** @var Anchor $value */ $value = \DocFixtures\produce(); $value->missing(); }', 'removed' => 0],
    'captured reference' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { /** @var Anchor $value */ $value = \DocFixtures\produce(); $callback = function () use (&$value): void {}; $value->missing(); }', 'removed' => 0],
    'closure scope' => ['source' => 'use DocFixtures\Anchor; $callback = function (): void { /** @var Anchor $value */ $value = \DocFixtures\produce(); $value->missing(); };', 'removed' => 0],
    'anonymous class scope' => ['source' => 'use DocFixtures\Anchor; $example = new class { public function scenario(): void { /** @var Anchor $value */ $value = \DocFixtures\produce(); $value->missing(); } };', 'removed' => 0],
    'dynamic binding' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { $name = "other"; $$name = null; /** @var Anchor $value */ $value = \DocFixtures\produce(); $value->missing(); }', 'removed' => 0],
];
$cases += (static function (): array {
// Invented test source only. The generated function, loop and object bodies are never included.
$header = 'use DocFixtures\Anchor; /** @param list<Anchor> $items */ function scenario(array $items): void {';
$loop = '/** @var Anchor $value */ foreach ($items as $value) { $value->ready(); }';
$unsafe = '/** @var Anchor $value */ foreach ($items as $value) { \DocFixtures\consumeText($value); }';
$wrap = static fn (string $body): string => $header.$body.'}';

return [
    'header imported object' => ['source' => $wrap($loop), 'removed' => 1],
    'header fully qualified object' => ['source' => $wrap(str_replace('@var Anchor', '@var \DocFixtures\Anchor', $loop)), 'removed' => 1],
    'header import alias' => ['source' => str_replace(['use DocFixtures\Anchor;', 'list<Anchor>', '@var Anchor'], ['use DocFixtures\Anchor as LocalAnchor;', 'list<LocalAnchor>', '@var LocalAnchor'], $wrap($loop)), 'removed' => 1],
    'header grouped import' => ['source' => str_replace('use DocFixtures\Anchor;', 'use DocFixtures\{Anchor};', $wrap($loop)), 'removed' => 1],
    'header namespace object' => ['source' => 'namespace DocFixtures; '.str_replace(['use DocFixtures\Anchor; ', 'function scenario('], ['', 'function headerNamespaceScenario('], $wrap($loop)), 'removed' => 1],
    'header instance method' => ['source' => 'use DocFixtures\Anchor; final class Inspector { /** @param list<Anchor> $items */ public function scenario(array $items): void {'.$loop.'} }', 'removed' => 1],
    'header static method' => ['source' => 'use DocFixtures\Anchor; final class Inspector { /** @param list<Anchor> $items */ public static function scenario(array $items): void {'.$loop.'} }', 'removed' => 1],
    'header multiline tag' => ['source' => $wrap(str_replace('/** @var Anchor $value */', "/**\n * @var Anchor \$value\n */", $loop)), 'removed' => 1],
    'header residual method error' => ['source' => $wrap(str_replace('$value->ready();', '$value->missing();', $loop)), 'removed' => 1, 'code' => 'non-existent-method'],
    'header residual argument error' => ['source' => $wrap($unsafe), 'removed' => 1, 'code' => 'invalid-argument'],
    'header broader annotation' => ['source' => $wrap(str_replace('@var Anchor', '@var \DocFixtures\ParentAnchor', $unsafe)), 'removed' => 0],
    'header different object' => ['source' => $wrap(str_replace('@var Anchor', '@var \DocFixtures\OtherAnchor', $unsafe)), 'removed' => 0],
    'header unknown class' => ['source' => $wrap(str_replace('@var Anchor', '@var UnknownAnchor', $unsafe)), 'removed' => 0],
    'header scalar type' => ['source' => 'function scenario(): void { $items = ["text"]; /** @var string $value */ foreach ($items as $value) { $value->missing(); } }', 'removed' => 0],
    'header array type' => ['source' => 'function scenario(): void { $items = [["text"]]; /** @var array<array-key, string> $value */ foreach ($items as $value) { \DocFixtures\consumeText($value); } }', 'removed' => 0],
    'header nullable type' => ['source' => $wrap(str_replace('@var Anchor', '@var ?Anchor', $unsafe)), 'removed' => 0],
    'header union type' => ['source' => $wrap(str_replace('@var Anchor', '@var Anchor|null', $unsafe)), 'removed' => 0],
    'header generic type' => ['source' => 'use DocFixtures\GenericAnchor; /** @param list<GenericAnchor<int>> $items */ function scenario(array $items): void { /** @var GenericAnchor<int> $value */ foreach ($items as $value) { \DocFixtures\consumeText($value); } }', 'removed' => 0],
    'header bare template object' => ['source' => 'use DocFixtures\GenericAnchor; /** @param list<GenericAnchor<int>> $items */ function scenario(array $items): void { /** @var GenericAnchor $value */ foreach ($items as $value) { \DocFixtures\consumeText($value); } }', 'removed' => 0],
    'header known interface object' => ['source' => 'use DocFixtures\NamedContract; /** @param list<NamedContract> $items */ function scenario(array $items): void { /** @var NamedContract $value */ foreach ($items as $value) { \DocFixtures\consumeText($value); } }', 'removed' => 0],
    'header explicit type alias' => ['source' => 'use DocFixtures\Anchor; /** @phpstan-type LocalAnchor Anchor */ final class Inspector { /** @param list<Anchor> $items */ public function scenario(array $items): void { /** @var LocalAnchor $value */ foreach ($items as $value) { \DocFixtures\consumeText($value); } } }', 'removed' => 0],
    'header malformed tag' => ['source' => $wrap(str_replace('@var Anchor', '@var Anchor<', $unsafe)), 'removed' => 0],
    'header multiple tags' => ['source' => $wrap(str_replace('@var Anchor $value', '@var Anchor $value @var Anchor $other', $unsafe)), 'removed' => 0],
    'header implicit variable' => ['source' => $wrap(str_replace('@var Anchor $value', '@var Anchor', $unsafe)), 'removed' => 0],
    'header other variable tag' => ['source' => $wrap(str_replace('@var Anchor $value', '@var Anchor $other', $unsafe)), 'removed' => 0],
    'header ordinary comment gap' => ['source' => $wrap(str_replace('*/ foreach', "*/ /* Unproven annotation attachment gap. */ foreach", $unsafe)), 'removed' => 0],
    'header intervening expression' => ['source' => $wrap(str_replace('*/ foreach', '*/ $copy = 1; foreach', $unsafe)), 'removed' => 0],
    'header storage alias' => ['source' => $wrap($unsafe.' $alias = $value;'), 'removed' => 0],
    'header iterable alias' => ['source' => $wrap('$alias = $items; '.$unsafe), 'removed' => 0],
    'header reference alias' => ['source' => $wrap('$alias =& $value; '.$unsafe), 'removed' => 0],
    'header value write' => ['source' => $wrap(str_replace('consumeText($value);', 'consumeText($value); $value = new Anchor;', $unsafe)), 'removed' => 0],
    'header value property write' => ['source' => $wrap(str_replace('consumeText($value);', 'consumeText($value); $value->unknown = 1;', $unsafe)), 'removed' => 0],
    'header iterable body write' => ['source' => $wrap(str_replace('consumeText($value);', 'consumeText($value); $items = [];', $unsafe)), 'removed' => 0],
    'header selected unset' => ['source' => $wrap($unsafe.' unset($value);'), 'removed' => 0],
    'header captured reference' => ['source' => $wrap($unsafe.' $callback = function () use (&$value): void {};'), 'removed' => 0],
    'header captured alias' => ['source' => $wrap($unsafe.' $callback = function () use ($value): void {};'), 'removed' => 0],
    'header arrow capture' => ['source' => $wrap($unsafe.' $callback = fn () => $value;'), 'removed' => 0],
    'header key binding' => ['source' => $wrap(str_replace('as $value)', 'as $key => $value)', $unsafe)), 'removed' => 0],
    'header reference iteration' => ['source' => $wrap(str_replace('as $value)', 'as &$value)', $unsafe)), 'removed' => 0],
    'header parameter shadow' => ['source' => str_replace('function scenario(array $items)', 'function scenario(array $items, Anchor $value)', $wrap($unsafe)), 'removed' => 0],
    'header reference iterable parameter' => ['source' => str_replace('function scenario(array $items)', 'function scenario(array &$items)', $wrap($unsafe)), 'removed' => 0],
    'header global binding' => ['source' => $wrap('global $value; '.$unsafe), 'removed' => 0],
    'header global iterable' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { global $items; '.$unsafe.'}', 'removed' => 0],
    'header static binding' => ['source' => $wrap('static $value; '.$unsafe), 'removed' => 0],
    'header dynamic binding' => ['source' => $wrap('$name = "other"; $$name = null; '.$unsafe), 'removed' => 0],
    'header top-level loop' => ['source' => 'use DocFixtures\Anchor; $items = [new Anchor]; '.$unsafe, 'removed' => 0],
    'header closure scope' => ['source' => 'use DocFixtures\Anchor; $callback = /** @param list<Anchor> $items */ function (array $items): void {'.$unsafe.'};', 'removed' => 0],
    'header anonymous class' => ['source' => 'use DocFixtures\Anchor; $inspector = new class { /** @param list<Anchor> $items */ public function scenario(array $items): void {'.$unsafe.'} };', 'removed' => 0],
    'header nested conditional' => ['source' => $wrap('if (count($items) > 0) { '.$unsafe.' }'), 'removed' => 0],
    'header destructured value' => ['source' => 'use DocFixtures\Anchor; /** @param list<list<Anchor>> $items */ function scenario(array $items): void { /** @var Anchor $value */ foreach ($items as [$value]) { \DocFixtures\consumeText($value); } }', 'removed' => 0],
    'header property storage' => ['source' => 'use DocFixtures\Anchor; class Holder { public Anchor $value; } /** @param list<Anchor> $items */ function scenario(array $items): void { $holder = new Holder; /** @var Anchor $value */ foreach ($items as $holder->value) { $holder->value->missing(); } }', 'removed' => 0],
    'header dynamic storage' => ['source' => $wrap('$name = "value"; '.str_replace('as $value)', 'as $$name)', $unsafe)), 'removed' => 0],
    'header superglobal storage' => ['source' => $wrap(str_replace('$value', '$_ENV', $unsafe)), 'removed' => 0],
    'header nonlocal iterable' => ['source' => $wrap(str_replace('foreach ($items', 'foreach (array_values($items)', $unsafe)), 'removed' => 0],
    'header scalar iterable expression' => ['source' => 'use DocFixtures\Anchor; function scenario(): void { /** @var Anchor $value */ foreach (42 as $value) { \DocFixtures\consumeText($value); } }', 'removed' => 0],
];

})();
$files = [];
foreach ($cases as $label => $case) {
    $file = 'cases/case'.count($files).'.php';
    // Namespaces distinguish declarations without changing ordinary local names.
    $source = $case['source'];
    if (! str_starts_with($source, 'namespace ')) { $source = 'namespace Scenario'.count($files).'; '.$source; }
    else { $source = str_replace('function scenario()', 'function namespaceScenario()', $source); }
    file_put_contents($workspace.'/'.$file, "<?php\n".$source."\n".'file_put_contents(__DIR__."/case-body-executed", "Analyzed scenario body must not execute.");'."\n");
    $files[$file] = $label;
}
file_put_contents($workspace.'/focus.php', '<?php namespace Focus; use DocFixtures\Anchor; final class Inspector { public function run(): void { $items = \DocFixtures\produceMany(); /** @var Anchor $value */ foreach ($items as $value) { $value->missing(); } } }');
file_put_contents($workspace.'/worker.php', '<?php require '.var_export($package.'/vendor/autoload.php', true).';'
    
    .'(new Mago\\Sdk\\Worker(new Mago\\Sdk\\Extension("fixture/docs", "Object docs", "1", analyzerPlugins: [new Ichinya\\Laramago\\Analyzer\\RedundantLocalObjectDocblockPlugin])))->run();');
$guardHeader = '<?php require '.var_export($package.'/vendor/autoload.php', true).';'."\n";
file_put_contents($workspace.'/guard-worker.php', $guardHeader.<<<'PHP'
$plugin = new class implements \Mago\Sdk\Analyzer\Plugin {
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/docs-guards', 'Doc policy guards', 'Exact native issue and metadata controls'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $registry->registerIssueFilterHook(new class implements \Mago\Sdk\Analyzer\IssueFilterHook {
            private bool $checked = false;
            private \Ichinya\Laramago\Analyzer\RedundantLocalObjectDocblockFilter $filter;
            public function __construct() { $this->filter = new \Ichinya\Laramago\Analyzer\RedundantLocalObjectDocblockFilter; }
            public function getCodes(): array { return ['redundant-docblock-type']; }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                $result = $this->filter->filterIssue($context);
                if ($this->checked || basename($context->file) !== 'focus.php') { return $result; }
                $this->checked = true; $checks = [];
                $expect = function (string $label, \Mago\Sdk\Analyzer\IssueFilterContext $input, bool $remove) use (&$checks): void {
                    $actual = $this->filter->filterIssue($input) === \Mago\Sdk\Analyzer\IssueFilterDecision::Remove;
                    if ($actual !== $remove) { throw new RuntimeException('Doc control failed: '.$label); }
                    $checks[$label] = true;
                    file_put_contents(__DIR__.'/native-doc-controls-progress.json', json_encode($checks, JSON_THROW_ON_ERROR));
                };
                $expect('native positive', $context, true);
                $issue = $context->issue;
                $alter = static function (array $changes) use ($issue): \Mago\Sdk\Reporting\ReportedIssue {
                    return new \Mago\Sdk\Reporting\ReportedIssue(...array_replace(get_object_vars($issue), $changes));
                };
                $with = static fn ($report, ?string $bytes = null): \Mago\Sdk\Analyzer\IssueFilterContext => new \Mago\Sdk\Analyzer\IssueFilterContext(
                    $context->phpVersion, $context->codebase, $context->types, $context->cancellation, $context->file, $bytes ?? $context->contents, $report);
                [$primary, $secondary] = $issue->annotations;
                $annotation = static fn ($original, array $changes): \Mago\Sdk\Reporting\Annotation => new \Mago\Sdk\Reporting\Annotation(...array_replace(get_object_vars($original), $changes));
                $variants = [
                    'Error severity' => ['level' => \Mago\Sdk\Reporting\Level::Error],
                    'other code' => ['code' => 'invalid-argument'],
                    'other variable message' => ['message' => 'Redundant docblock type for variable `$other`.'],
                    'missing note' => ['notes' => []], 'extra note' => ['notes' => [...$issue->notes, 'Other note.']],
                    'changed note' => ['notes' => ['Different equality claim.']],
                    'changed help' => ['help' => null], 'foreign link' => ['link' => 'https://example.invalid/docs'],
                    'suggested edit' => ['edits' => [\Mago\Sdk\Reporting\TextEdit::delete($primary->span)]],
                    'missing annotation' => ['annotations' => [$primary]],
                    'reversed annotations' => ['annotations' => [$secondary, $primary]],
                    'primary kind' => ['annotations' => [$annotation($primary, ['kind' => \Mago\Sdk\Reporting\AnnotationKind::Secondary]), $secondary]],
                    'secondary kind' => ['annotations' => [$primary, $annotation($secondary, ['kind' => \Mago\Sdk\Reporting\AnnotationKind::Primary])]],
                    'primary foreign file' => ['annotations' => [$annotation($primary, ['file' => 'foreign.php']), $secondary]],
                    'secondary foreign file' => ['annotations' => [$primary, $annotation($secondary, ['file' => 'foreign.php'])]],
                    'primary neighboring span' => ['annotations' => [$annotation($primary, ['span' => new \Mago\Sdk\Span($primary->span->start + 1, $primary->span->end)]), $secondary]],
                    'secondary neighboring span' => ['annotations' => [$primary, $annotation($secondary, ['span' => new \Mago\Sdk\Span($secondary->span->start + 1, $secondary->span->end)])]],
                    'primary other type' => ['annotations' => [$annotation($primary, ['message' => 'This docblock asserts the type should be `DocFixtures\OtherAnchor`, which is identical to the inferred type.']), $secondary]],
                    'secondary other type' => ['annotations' => [$primary, $annotation($secondary, ['message' => 'The variable `$value` type is known to be `DocFixtures\OtherAnchor` here.'])]],
                    'secondary iterable span' => ['annotations' => [$primary, $annotation($secondary, ['span' => new \Mago\Sdk\Span(strpos($context->contents, '$items as'), strpos($context->contents, '$items as') + strlen('$items'))])]],
                    'secondary body span' => ['annotations' => [$primary, $annotation($secondary, ['span' => new \Mago\Sdk\Span(strrpos($context->contents, '$value->missing'), strrpos($context->contents, '$value->missing') + strlen('$value'))])]],
                ];
                foreach ($variants as $label => $changes) { $expect($label, $with($alter($changes)), false); }
                foreach (['changed same-path tag' => str_replace('@var Anchor', '@var OtherA', $context->contents),
                    'changed same-path binding' => str_replace('as $value)', 'as $other)', $context->contents),
                    'changed import' => str_replace('use DocFixtures\Anchor;', 'use DocFixtures\OtherA;', $context->contents),
                    'changed nullable tag' => str_replace('@var Anchor', '@var ?Anchor', $context->contents),
                    'changed reference header' => str_replace('as $value)', 'as &$value)', $context->contents),
                    'changed keyed header' => str_replace('as $value)', 'as $key => $value)', $context->contents),
                    'changed expression iterable' => str_replace('foreach ($items as', 'foreach (array_values($items) as', $context->contents),
                    'malformed source' => '<?php function broken( {',
                    'oversized source' => $context->contents.str_repeat(' ', 1024 * 1024)] as $label => $bytes) { $expect($label, $with($issue, $bytes), false); }
                $object = $context->codebase->getClass('DocFixtures\Anchor');
                $caller = $context->codebase->getMethod('Focus\Inspector', 'run');
                $owner = $context->codebase->getClass('Focus\Inspector');
                $generic = $context->codebase->getClass('DocFixtures\GenericAnchor');
                if ($object === null || $caller === null || $owner === null || $generic === null || $generic->templates === []) { throw new RuntimeException('Missing native metadata controls.'); }
                file_put_contents(__DIR__.'/native-doc-metadata.txt', var_export(['object' => $object, 'caller' => $caller, 'owner' => $owner, 'issue' => $issue], true));
                $cache = (new ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase); $snapshot = $cache->values;
                file_put_contents(__DIR__.'/native-doc-cache.txt', var_export(['entryCount' => count($snapshot)], true));
                $metadata = [
                    'missing native object' => [$object, null],
                    'native object name' => [$object, ['name' => 'DocFixtures\OtherAnchor']],
                    'native object kind' => [$object, ['kind' => \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface]],
                    'native object incomplete hierarchy' => [$object, ['unresolvedHierarchyDependencies' => ['UnknownParent']]],
                    'native object templates' => [$object, ['templates' => $generic->templates]],
                    'missing native owner' => [$owner, null],
                    'native owner wrong kind' => [$owner, ['kind' => \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface]],
                    'native owner wrong name' => [$owner, ['name' => 'Foreign\Inspector']],
                    'native owner incomplete hierarchy' => [$owner, ['unresolvedHierarchyDependencies' => ['UnknownParent']]],
                    'native owner templates' => [$owner, ['templates' => $generic->templates]],
                    'native owner type alias' => [$owner, ['typeAliases' => ['ForeignAlias' => $caller->declaredReturnType]]],
                    'native owner wrong file' => [$owner, ['location' => new \Mago\Sdk\SourceLocation('foreign.php', $owner->location->span)]],
                    'native owner wrong span' => [$owner, ['location' => new \Mago\Sdk\SourceLocation($owner->location->file, new \Mago\Sdk\Span($owner->location->span->start + 1, $owner->location->span->end))]],
                    'missing native caller' => [$caller, null],
                    'native caller wrong file' => [$caller, ['location' => new \Mago\Sdk\SourceLocation('foreign.php', $caller->location->span)]],
                    'native caller wrong span' => [$caller, ['location' => new \Mago\Sdk\SourceLocation($caller->location->file, new \Mago\Sdk\Span($caller->location->span->start + 1, $caller->location->span->end))]],
                    'native caller wrong name span' => [$caller, ['nameLocation' => new \Mago\Sdk\SourceLocation($caller->nameLocation->file, new \Mago\Sdk\Span($caller->nameLocation->span->start + 1, $caller->nameLocation->span->end))]],
                    'native caller reference' => [$caller, ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($caller->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)]],
                    'native caller name foreign file' => [$caller, ['nameLocation' => new \Mago\Sdk\SourceLocation('foreign.php', $caller->nameLocation->span)]],
                ];
                foreach ($metadata as $label => [$original, $changes]) {
                    $class = $original::class; $replacement = $changes === null ? null : new $class(...array_replace(get_object_vars($original), $changes)); $replaced = 0;
                    foreach ($snapshot as $operation => $entries) { foreach ($entries as $key => $entry) { if ($entry === $original) { $cache->values[$operation][$key] = $replacement; $replaced++; } } }
                    try { if ($replaced === 0) { throw new RuntimeException('Vacuous native snapshot mutation: '.$label.' ('.$class.').'); } $expect($label, $context, false); }
                    finally { $cache->values = $snapshot; }
                }
                $expect('metadata restored', $context, true);
                $cancelled = new class implements \Mago\Sdk\CancellationTokenInterface {
                    public function isCancelled(): bool { return true; }
                    public function throwIfCancelled(): void { throw new \Mago\Sdk\Exception\CancelledException; }
                    public function subscribe(\Closure $callback): int { return 0; }
                    public function unsubscribe(int $subscription): void {}
                };
                $cancelledContext = new \Mago\Sdk\Analyzer\IssueFilterContext($context->phpVersion, $context->codebase, $context->types,
                    $cancelled, $context->file, $context->contents, $issue);
                $expect('cancelled native context', $cancelledContext, false);
                $this->filter->initialize(new \Mago\Sdk\Analyzer\InitializationContext($context->phpVersion, $context->cancellation));
                $expect('initialization reset', $context, true);
                file_put_contents(__DIR__.'/native-doc-controls.json', json_encode($checks, JSON_THROW_ON_ERROR));
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('fixture/docs-guards', 'Doc guards', '1', analyzerPlugins: [$plugin])))->run();
PHP);

if (in_array('--prepare-only', $argv, true)) {
    $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
    $receipts = [];
    foreach ([...array_keys($files), 'focus.php', 'contracts.php', 'worker.php', 'guard-worker.php', 'bootstrap.php', 'database/migrations/001_trap.php', 'packages/composer/trap.php'] as $file) {
        $contents = file_get_contents($workspace.'/'.$file);
        $parser->parse($contents);
        $receipts[$file] = ['bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
    }
    file_put_contents($workspace.'/source-parser-receipts.json', json_encode(['cases' => count($cases), 'sourceOnly' => true, 'analyzerJobsStarted' => 0, 'files' => $receipts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    foreach ([$workspace.'/executed', $workspace.'/database/migrations/executed', $workspace.'/packages/composer/executed', $workspace.'/contract-body-executed', $workspace.'/cases/case-body-executed'] as $marker) { if (file_exists($marker)) { throw new RuntimeException('Source body executed during preparation.'); } }
    echo 'Prepared '.count($cases)." source cases, native guard source and traps; parser only; zero analyzer jobs.\n";
    exit(0);
}
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (string $label, ?string $worker = null, int $workers = 1, bool $single = false) use ($package, $workspace, $command): array {
    $config = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $single ? ['focus.php'] : ['cases', 'focus.php'], 'includes' => ['contracts.php']]];
    if ($worker !== null) { $config['extension-hosts'] = ['docs-policy' => ['command' => [PHP_BINARY, $worker, $package.'/vendor/autoload.php', $workspace], 'workers' => $workers]]; }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$label.'.json', 'w'], 2 => ['file', $workspace.'/'.$label.'.log', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago; inspect '.$workspace); }
    fclose($pipes[0]); $exit = proc_close($process); $stderr = file_get_contents($workspace.'/'.$label.'.log');
    if (! in_array($exit, [0, 1], true) || preg_match('/provider failed|rejected request|protocol error|panicked/i', $stderr) === 1) { throw new RuntimeException('Mago failed '.$label.'; inspect '.$workspace); }
    $report = json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! isset($report['issues']) || ! is_array($report['issues'])) { throw new RuntimeException('Invalid Mago report '.$label); }
    return $report['issues'];
};
$signature = static function (array $issues): array { $result = array_map(static fn ($issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues); sort($result); return $result; };
$fileOf = static function (array $issue): string { foreach ($issue['annotations'] as $annotation) { if ($annotation['kind'] === 'Primary') { return str_replace('\\', '/', $annotation['span']['file_id']['name']); } } throw new RuntimeException('Missing Primary.'); };
$native = $run('native'); $isolated = $run('isolated', $workspace.'/worker.php'); $expected = $native; $removed = 0;
foreach ($files as $file => $label) {
    $case = $cases[$label]; $before = array_values(array_filter($native, static fn ($issue): bool => $fileOf($issue) === $file));
    $after = array_values(array_filter($isolated, static fn ($issue): bool => $fileOf($issue) === $file));
    if ($before === []) { throw new RuntimeException('Vacuous native case '.$label.'; inspect '.$workspace); }
    $matches = array_keys(array_filter($expected, static fn ($issue): bool => $fileOf($issue) === $file && $issue['code'] === 'redundant-docblock-type'));
    if (count($matches) < $case['removed']) { throw new RuntimeException('Missing native equality advisory '.$label.'; inspect '.$workspace); }
    foreach (array_slice($matches, 0, $case['removed']) as $index) { unset($expected[$index]); $removed++; }
    $perCase = array_values(array_filter($expected, static fn ($issue): bool => $fileOf($issue) === $file));
    if ($signature($after) !== $signature($perCase) || isset($case['code']) && ! in_array($case['code'], array_column($after, 'code'), true)) { throw new RuntimeException('Exact case signature mismatch '.$label.'; inspect '.$workspace); }
    echo 'PASS: '.$label."\n";
}
$focus = array_keys(array_filter($expected, static fn ($issue): bool => $fileOf($issue) === 'focus.php' && $issue['code'] === 'redundant-docblock-type'));
if (count($focus) !== 1) { throw new RuntimeException('Missing native guard candidate; inspect '.$workspace); }
unset($expected[$focus[0]]); $removed++;
if ($signature($isolated) !== $signature(array_values($expected))) { throw new RuntimeException('Unclassified signature mismatch; inspect '.$workspace); }
echo 'PASS: '.count($cases).' genuine cases and one native guard candidate, '.$removed." exact advisories removed, every other complete report preserved\n";
// Keep metadata mutation controls in one native request: concurrent source-file
// callbacks can replace the SDK's shared cache buckets during a host suspension.
$guard = $run('guarded', $workspace.'/guard-worker.php', single: true);
$isolatedFocus = array_values(array_filter($isolated, static fn ($issue): bool => $fileOf($issue) === 'focus.php'));
if ($signature($guard) !== $signature($isolatedFocus)) { throw new RuntimeException('Guard observer changed complete focus signatures; inspect '.$workspace); }
$checks = json_decode(file_get_contents($workspace.'/native-doc-controls.json'), true, flags: JSON_THROW_ON_ERROR);
if (count($checks) !== 53 || in_array(false, $checks, true)) { throw new RuntimeException('Missing or vacuous native controls; inspect '.$workspace); }
echo 'PASS: '.count($checks)." native envelope, source and metadata controls\n";
if (in_array('--integrated', $argv, true)) {
    foreach ([1, 3] as $workers) { $fullWorker = $package.'/bin/laramago-worker.php'; $full = $run('integrated'.$workers, $fullWorker, $workers); if ($signature($full) !== $signature($isolated)) { throw new RuntimeException('Integrated signature mismatch '.$workers.'; inspect '.$workspace); } }
    echo "PASS: isolated, guarded, full one-worker and full three-worker complete signatures match\n";
}
$singleNative = $run('single-native', single: true); $single = $run('single-isolated', $workspace.'/worker.php', single: true);
$singleWarnings = array_values(array_filter($singleNative, static fn (array $issue): bool => $issue['level'] === 'Warning' && $issue['code'] === 'redundant-docblock-type'));
$singleErrors = array_values(array_filter($singleNative, static fn (array $issue): bool => $issue['level'] === 'Error'));
if (count($singleWarnings) !== 1 || $singleErrors === [] || $signature($single) !== $signature($singleErrors)) { throw new RuntimeException('Single-file header policy or independent unsafe Error preservation failed; inspect '.$workspace); }
foreach ([$workspace.'/executed', $workspace.'/database/migrations/executed', $workspace.'/packages/composer/executed', $workspace.'/contract-body-executed', $workspace.'/cases/case-body-executed'] as $marker) { if (file_exists($marker)) { throw new RuntimeException('Source body executed; inspect '.$workspace); } }
if (file_exists($workspace.'/.env')) { throw new RuntimeException('Unexpected environment.'); }
echo "PASS: single-file, root spaces, custom vendor directory; bootstrap/migration/Composer bodies never execute, no environment or database\n";
echo 'Evidence: '.$workspace."\n";
