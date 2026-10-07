<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago foreach documentation '.bin2hex(random_bytes(8));
mkdir($workspace.'/cases', recursive: true);
mkdir($workspace.'/packages/composer', recursive: true);
mkdir($workspace.'/database/migrations', recursive: true);
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
class ParentAnchor {}
class Anchor extends ParentAnchor { public function ready(): bool { return true; } }
class OtherAnchor { public function ready(): bool { return true; } }
/** @template T */
class GenericAnchor { public function ready(): bool { return true; } }
function consumeText(string $value): void {}
function produce(): Anchor { return new Anchor; }
PHP);

$header = 'use DocFixtures\Anchor; /** @param list<Anchor> $items */ function scenario(array $items): void {';
$loop = 'foreach ($items as $item) { /** @var Anchor $item */ if ($item->ready()) {} }';
$unsafeLoop = 'foreach ($items as $item) { /** @var Anchor $item */ if ($item->ready()) {} \DocFixtures\consumeText($item); }';
$wrap = static fn (string $body): string => $header.$body.'}';
$cases = [
    'imported object' => ['source' => $wrap($loop), 'removed' => 1],
    'fully qualified object' => ['source' => $wrap(str_replace('@var Anchor', '@var \DocFixtures\Anchor', $loop)), 'removed' => 1],
    'import alias' => ['source' => str_replace(['use DocFixtures\Anchor;', 'list<Anchor>', '@var Anchor'], ['use DocFixtures\Anchor as LocalAnchor;', 'list<LocalAnchor>', '@var LocalAnchor'], $wrap($loop)), 'removed' => 1],
    'grouped import' => ['source' => str_replace('use DocFixtures\Anchor;', 'use DocFixtures\{Anchor};', $wrap($loop)), 'removed' => 1],
    'namespace object' => ['source' => 'namespace DocFixtures; '.str_replace(['use DocFixtures\Anchor; ', 'function scenario('], ['', 'function namespaceScenario('], $wrap($loop)), 'removed' => 1],
    'instance method' => ['source' => 'use DocFixtures\Anchor; class Inspector { /** @param list<Anchor> $items */ public function scenario(array $items): void {'.$loop.'} }', 'removed' => 1],
    'static method' => ['source' => 'use DocFixtures\Anchor; class Inspector { /** @param list<Anchor> $items */ public static function scenario(array $items): void {'.$loop.'} }', 'removed' => 1],
    'multiline tag' => ['source' => $wrap(str_replace('/** @var Anchor $item */', "/**\n * @var Anchor \$item\n */", $loop)), 'removed' => 1],
    'residual method error' => ['source' => $wrap(str_replace('if ($item->ready()) {}', 'if ($item->ready()) { $item->missing(); }', $loop)), 'removed' => 1, 'code' => 'non-existent-method'],
    'residual argument error' => ['source' => $wrap($unsafeLoop), 'removed' => 1, 'code' => 'invalid-argument'],
    'broader annotation' => ['source' => $wrap(str_replace('@var Anchor', '@var \DocFixtures\ParentAnchor', $unsafeLoop)), 'removed' => 0],
    'different annotation' => ['source' => $wrap(str_replace('@var Anchor', '@var \DocFixtures\OtherAnchor', $unsafeLoop)), 'removed' => 0],
    'unknown annotation' => ['source' => $wrap(str_replace('@var Anchor', '@var UnknownAnchor', $unsafeLoop)), 'removed' => 0],
    'scalar annotation' => ['source' => 'function scenario(): void { foreach (["text"] as $item) { /** @var string $item */ if ($item->ready()) {} } }', 'removed' => 0],
    'array annotation' => ['source' => 'function scenario(): void { foreach ([["text"]] as $item) { /** @var array<array-key, string> $item */ if ($item->ready()) {} } }', 'removed' => 0],
    'union annotation' => ['source' => $wrap(str_replace('@var Anchor', '@var Anchor|null', $unsafeLoop)), 'removed' => 0],
    'generic annotation' => ['source' => 'use DocFixtures\GenericAnchor; /** @param list<GenericAnchor<int>> $items */ function scenario(array $items): void { foreach ($items as $item) { /** @var GenericAnchor<int> $item */ if ($item->ready()) {} \DocFixtures\consumeText($item); } }', 'removed' => 0],
    'malformed annotation' => ['source' => $wrap(str_replace('@var Anchor', '@var Anchor<', $unsafeLoop)), 'removed' => 0],
    'multiple tags' => ['source' => $wrap(str_replace('@var Anchor $item', '@var Anchor $item @var Anchor $other', $unsafeLoop)), 'removed' => 0],
    'implicit variable' => ['source' => $wrap(str_replace('@var Anchor $item', '@var Anchor', $unsafeLoop)), 'removed' => 0],
    'different variable tag' => ['source' => $wrap(str_replace('@var Anchor $item', '@var Anchor $other', $unsafeLoop)), 'removed' => 0],
    'prior body statement' => ['source' => $wrap(str_replace('as $item) {', 'as $item) { $copy = $item;', $unsafeLoop)), 'removed' => 0],
    'different condition receiver' => ['source' => $wrap('$other = new Anchor; '.str_replace('if ($item->ready())', 'if ($other->ready())', $unsafeLoop)), 'removed' => 0],
    'negated condition' => ['source' => $wrap(str_replace('if ($item->ready())', 'if (! $item->ready())', $unsafeLoop)), 'removed' => 0],
    'dynamic method' => ['source' => $wrap('$method = "ready"; '.str_replace('$item->ready()', '$item->$method()', $unsafeLoop)), 'removed' => 0],
    'key binding' => ['source' => $wrap(str_replace('as $item)', 'as $key => $item)', $unsafeLoop)), 'removed' => 0],
    'reference iteration' => ['source' => $wrap(str_replace('as $item)', 'as &$item)', $unsafeLoop)), 'removed' => 0],
    'reference alias' => ['source' => $wrap('$alias =& $item; '.$unsafeLoop), 'removed' => 0],
    'captured reference' => ['source' => $wrap($unsafeLoop.' $callback = function () use (&$item): void {};'), 'removed' => 0],
    'global binding' => ['source' => $wrap('global $item; '.$unsafeLoop), 'removed' => 0],
    'static binding' => ['source' => $wrap('static $item; '.$unsafeLoop), 'removed' => 0],
    'dynamic binding' => ['source' => $wrap('$name = "other"; $$name = null; '.$unsafeLoop), 'removed' => 0],
    'top-level loop' => ['source' => 'use DocFixtures\Anchor; $items = [new Anchor]; '.$unsafeLoop, 'removed' => 0],
    'closure scope' => ['source' => 'use DocFixtures\Anchor; $callback = /** @param list<Anchor> $items */ function (array $items): void {'.$unsafeLoop.'};', 'removed' => 0],
    'anonymous class scope' => ['source' => 'use DocFixtures\Anchor; $inspector = new class { /** @param list<Anchor> $items */ public function scenario(array $items): void {'.$unsafeLoop.'} };', 'removed' => 0],
    'nested conditional loop' => ['source' => $wrap('if (count($items) > 0) { '.$unsafeLoop.' }'), 'removed' => 0],
    'destructured value' => ['source' => 'use DocFixtures\Anchor; /** @param list<list<Anchor>> $items */ function scenario(array $items): void { foreach ($items as [$item]) { /** @var Anchor $item */ if ($item->ready()) {} \DocFixtures\consumeText($item); } }', 'removed' => 0],
    'property value' => ['source' => 'use DocFixtures\Anchor; class Holder { public Anchor $item; } /** @param list<Anchor> $items */ function scenario(array $items): void { $holder = new Holder; foreach ($items as $holder->item) { /** @var Anchor $item */ if ($holder->item->ready()) {} $holder->item->missing(); } }', 'removed' => 0],
    'parameter shadow' => ['source' => str_replace('function scenario(array $items)', 'function scenario(array $items, Anchor $item)', $wrap($unsafeLoop)), 'removed' => 0],
    'superglobal binding' => ['source' => $wrap(str_replace('$item', '$_ENV', $unsafeLoop)), 'removed' => 0],
];
$cases += (static function (): array {
    $header = 'use DocFixtures\\Anchor; final class Inspector { private function accept(Anchor $item): void {} public function scenario(bool $flag): void { $value = \\DocFixtures\\produce(); ';
    $call = '/** @var Anchor $value */ $this->accept($value);';
    $wrap = static fn (string $body): string => $header.$body.' } }';
    $guarded = 'if ($value instanceof Anchor) { '.$call.' }';
    $unsafe = str_replace('accept(Anchor $item)', 'accept(string $item)', $wrap($guarded));
    return [
        'guarded immediate argument' => ['source' => $wrap($guarded), 'removed' => 1],
        'guarded elseif argument' => ['source' => $wrap('if ($flag) {} elseif ($value instanceof Anchor) { '.$call.' }'), 'removed' => 1],
        'guarded fully qualified tag' => ['source' => $wrap(str_replace('@var Anchor', '@var \\DocFixtures\\Anchor', $guarded)), 'removed' => 1],
        'guarded fully qualified condition' => ['source' => $wrap(str_replace('instanceof Anchor', 'instanceof \\DocFixtures\\Anchor', $guarded)), 'removed' => 1],
        'guarded imported alias' => ['source' => str_replace(['use DocFixtures\\Anchor;', 'Anchor $', 'instanceof Anchor', '@var Anchor'], ['use DocFixtures\\Anchor as LocalAnchor;', 'LocalAnchor $', 'instanceof LocalAnchor', '@var LocalAnchor'], $wrap($guarded)), 'removed' => 1],
        'guarded multiline tag' => ['source' => $wrap(str_replace('/** @var Anchor $value */', "/**\n * @var Anchor \$value\n */", $guarded)), 'removed' => 1],
        'guarded residual argument error' => ['source' => $unsafe, 'removed' => 1, 'code' => 'invalid-argument'],
        'guarded residual method error' => ['source' => $wrap($guarded.' $value->missing();'), 'removed' => 1, 'code' => 'non-existent-method'],
        'unguarded argument documentation' => ['source' => str_replace($guarded, $call, $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded nonmatching variable' => ['source' => str_replace('if ($value instanceof Anchor)', '$other = \\DocFixtures\\produce(); if ($other instanceof Anchor)', $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded compound condition' => ['source' => str_replace('if ($value instanceof Anchor)', 'if ($flag && $value instanceof Anchor)', $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded boolean condition' => ['source' => str_replace('if ($value instanceof Anchor)', 'if ($flag)', $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded intervening expression' => ['source' => str_replace('{ '.$call, '{ $value->ready(); '.$call, $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded nested conditional' => ['source' => str_replace($guarded, 'if ($flag) { '.$guarded.' }', $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded else branch' => ['source' => str_replace($guarded, 'if ($flag) {} else { '.$call.' }', $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded named argument' => ['source' => str_replace('accept($value)', 'accept(item: $value)', $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded multiple arguments' => ['source' => str_replace('accept($value)', 'accept($value, $value)', $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded reference capture' => ['source' => str_replace('$value = \\DocFixtures\\produce();', '$value = \\DocFixtures\\produce(); $capture = function () use (&$value): void {};', $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded different tag class' => ['source' => str_replace('@var Anchor', '@var \\DocFixtures\\OtherAnchor', $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
        'guarded other type condition' => ['source' => str_replace('instanceof Anchor', 'instanceof \\DocFixtures\\ParentAnchor', $unsafe), 'removed' => 0, 'code' => 'invalid-argument'],
    ];
})();
$files = [];
foreach ($cases as $label => $case) {
    $file = 'cases/case'.count($files).'.php'; $source = $case['source'];
    if (! str_starts_with($source, 'namespace ')) { $source = 'namespace Scenario'.count($files).'; '.$source; }
    file_put_contents($workspace.'/'.$file, "<?php\n".$source."\n"); $files[$file] = $label;
}
file_put_contents($workspace.'/focus.php', '<?php namespace Focus; use DocFixtures\Anchor; final class Inspector { /** @param list<Anchor> $items */ public function run(array $items): void { foreach ($items as $item) { /** @var Anchor $item */ if ($item->ready()) {} } } }');
file_put_contents($workspace.'/worker.php', '<?php require '.var_export($package.'/vendor/autoload.php', true).';'
    .'(new Mago\\Sdk\\Worker(new Mago\\Sdk\\Extension("fixture/foreach-docs", "Foreach docs", "1", analyzerPlugins: [new Ichinya\\Laramago\\Analyzer\\ForeachObjectDocblockPlugin])))->run();');
$guardHeader = '<?php require '.var_export($package.'/vendor/autoload.php', true).';'."\n";
file_put_contents($workspace.'/guard-worker.php', $guardHeader.<<<'PHP'
$plugin = new class implements \Mago\Sdk\Analyzer\Plugin {
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/foreach-doc-guards', 'Foreach doc guards', 'Exact native source and metadata controls'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $registry->registerIssueFilterHook(new class implements \Mago\Sdk\Analyzer\IssueFilterHook {
            private bool $checked = false;
            private \Ichinya\Laramago\Analyzer\ForeachObjectDocblockFilter $filter;
            public function __construct() { $this->filter = new \Ichinya\Laramago\Analyzer\ForeachObjectDocblockFilter; }
            public function getCodes(): array { return ['redundant-docblock-type']; }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                $result = $this->filter->filterIssue($context);
                if ($this->checked || basename($context->file) !== 'focus.php') { return $result; }
                $this->checked = true; $checks = [];
                $expect = function (string $label, \Mago\Sdk\Analyzer\IssueFilterContext $input, bool $remove) use (&$checks): void {
                    $actual = $this->filter->filterIssue($input) === \Mago\Sdk\Analyzer\IssueFilterDecision::Remove;
                    if ($actual !== $remove) { throw new RuntimeException('Foreach doc control failed: '.$label); }
                    $checks[$label] = true;
                    file_put_contents(__DIR__.'/native-foreach-controls-progress.json', json_encode($checks, JSON_THROW_ON_ERROR));
                };
                $expect('native positive', $context, true); $issue = $context->issue; $primary = $issue->annotations[0];
                $alter = static fn (array $changes): \Mago\Sdk\Reporting\ReportedIssue => new \Mago\Sdk\Reporting\ReportedIssue(...array_replace(get_object_vars($issue), $changes));
                $with = static fn ($report, ?string $bytes = null): \Mago\Sdk\Analyzer\IssueFilterContext => new \Mago\Sdk\Analyzer\IssueFilterContext(
                    $context->phpVersion, $context->codebase, $context->types, $context->cancellation, $context->file, $bytes ?? $context->contents, $report);
                $annotation = static fn (array $changes): \Mago\Sdk\Reporting\Annotation => new \Mago\Sdk\Reporting\Annotation(...array_replace(get_object_vars($primary), $changes));
                foreach ([
                    'Error severity' => ['level' => \Mago\Sdk\Reporting\Level::Error],
                    'other code' => ['code' => 'invalid-argument'],
                    'other variable message' => ['message' => 'Redundant docblock type for variable `$other`.'],
                    'extra note' => ['notes' => ['Other note.']], 'changed help' => ['help' => null],
                    'foreign link' => ['link' => 'https://example.invalid/docs'], 'suggested edit' => ['edits' => [\Mago\Sdk\Reporting\TextEdit::delete($primary->span)]],
                    'missing annotation' => ['annotations' => []], 'extra annotation' => ['annotations' => [$primary, $primary]],
                    'secondary kind' => ['annotations' => [$annotation(['kind' => \Mago\Sdk\Reporting\AnnotationKind::Secondary])]],
                    'foreign file' => ['annotations' => [$annotation(['file' => 'foreign.php'])]],
                    'neighboring span' => ['annotations' => [$annotation(['span' => new \Mago\Sdk\Span($primary->span->start + 1, $primary->span->end)])]],
                    'different equality envelope' => ['annotations' => [$annotation(['message' => 'Different equality claim.'])]],
                    'different object claim' => ['annotations' => [$annotation(['message' => str_replace('DocFixtures\Anchor', 'DocFixtures\OtherAnchor', $primary->message)])]],
                ] as $label => $changes) { $expect($label, $with($alter($changes)), false); }
                foreach ([
                    'changed same-path tag' => str_replace('@var Anchor', '@var OtherA', $context->contents),
                    'changed condition receiver' => str_replace('if ($item->', 'if ($copy->', $context->contents),
                    'changed loop binding' => str_replace('as $item)', 'as $copy)', $context->contents),
                    'changed same-length import' => str_replace('use DocFixtures\Anchor;', 'use DocFixtures\Absent;', $context->contents),
                    'malformed source' => '<?php function broken( {',
                    'oversized source' => $context->contents.str_repeat(' ', 1024 * 1024),
                ] as $label => $bytes) { $expect($label, $with($issue, $bytes), false); }
                $object = $context->codebase->getClass('DocFixtures\Anchor');
                $caller = $context->codebase->getMethod('Focus\Inspector', 'run');
                $owner = $context->codebase->getClass('Focus\Inspector');
                $generic = $context->codebase->getClass('DocFixtures\GenericAnchor');
                if ($object === null || $caller === null || $owner === null || $generic === null || $generic->templates === []) { throw new RuntimeException('Missing native metadata controls.'); }
                file_put_contents(__DIR__.'/native-foreach-metadata.txt', var_export(['object' => $object, 'caller' => $caller, 'owner' => $owner, 'issue' => $issue], true));
                $cache = (new ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase); $snapshot = $cache->values;
                file_put_contents(__DIR__.'/native-foreach-cache.txt', var_export($snapshot, true));
                $wrongSpan = static fn ($location): \Mago\Sdk\SourceLocation => new \Mago\Sdk\SourceLocation($location->file, new \Mago\Sdk\Span($location->span->start + 1, $location->span->end));
                $metadata = [
                    'missing native object' => [$object, null], 'object name' => [$object, ['name' => 'DocFixtures\OtherAnchor']],
                    'object kind' => [$object, ['kind' => \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface]],
                    'object incomplete hierarchy' => [$object, ['unresolvedHierarchyDependencies' => ['UnknownParent']]],
                    'object templates' => [$object, ['templates' => $generic->templates]],
                    'missing native caller' => [$caller, null],
                    'caller wrong file' => [$caller, ['location' => new \Mago\Sdk\SourceLocation('foreign.php', $caller->location->span)]],
                    'caller wrong span' => [$caller, ['location' => $wrongSpan($caller->location)]],
                    'caller wrong name span' => [$caller, ['nameLocation' => $wrongSpan($caller->nameLocation)]],
                    'caller reference' => [$caller, ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($caller->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)]],
                    'caller name foreign file' => [$caller, ['nameLocation' => new \Mago\Sdk\SourceLocation('foreign.php', $caller->nameLocation->span)]],
                    'missing native owner' => [$owner, null], 'owner name' => [$owner, ['name' => 'Focus\OtherInspector']],
                    'owner kind' => [$owner, ['kind' => \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface]],
                    'owner incomplete hierarchy' => [$owner, ['unresolvedHierarchyDependencies' => ['UnknownParent']]],
                    'owner templates' => [$owner, ['templates' => $generic->templates]],
                    'owner wrong file' => [$owner, ['location' => new \Mago\Sdk\SourceLocation('foreign.php', $owner->location->span)]],
                    'owner wrong span' => [$owner, ['location' => $wrongSpan($owner->location)]],
                    'owner aliases' => [$owner, ['typeAliases' => ['Marker' => $caller->returnType]]],
                ];
                foreach ($metadata as $label => [$original, $changes]) {
                    $class = $original::class; $replacement = $changes === null ? null : new $class(...array_replace(get_object_vars($original), $changes)); $replaced = 0;
                    foreach ($snapshot as $operation => $entries) { foreach ($entries as $key => $entry) { if ($entry === $original) { $cache->values[$operation][$key] = $replacement; $replaced++; } } }
                    try { if ($replaced === 0) { throw new RuntimeException('Vacuous native snapshot mutation: '.$label); } $expect($label, $context, false); }
                    finally { $cache->values = $snapshot; }
                }
                $expect('metadata restored', $context, true);
                $this->filter->initialize(new \Mago\Sdk\Analyzer\InitializationContext($context->phpVersion, $context->cancellation));
                $expect('initialization reset', $context, true);
                file_put_contents(__DIR__.'/native-foreach-controls.json', json_encode($checks, JSON_THROW_ON_ERROR));
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('fixture/foreach-doc-guards', 'Foreach guards', '1', analyzerPlugins: [$plugin])))->run();
PHP);

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (string $label, ?string $worker = null, int $workers = 1, bool $single = false) use ($package, $workspace, $command): array {
    $config = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $single ? ['focus.php'] : ['cases', 'focus.php'], 'includes' => ['contracts.php']]];
    if ($worker !== null) { $config['extension-hosts'] = ['foreach-docs' => ['command' => [PHP_BINARY, $worker, $package.'/vendor/autoload.php', $workspace], 'workers' => $workers]]; }
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
$guard = $run('guarded', $workspace.'/guard-worker.php', single: true);
$isolatedFocus = array_values(array_filter($isolated, static fn ($issue): bool => $fileOf($issue) === 'focus.php'));
if ($signature($guard) !== $signature($isolatedFocus)) { throw new RuntimeException('Guard observer changed complete focus signatures; inspect '.$workspace); }
$checks = json_decode(file_get_contents($workspace.'/native-foreach-controls.json'), true, flags: JSON_THROW_ON_ERROR);
if (count($checks) !== 42 || in_array(false, $checks, true)) { throw new RuntimeException('Missing or vacuous native controls; inspect '.$workspace); }
echo 'PASS: '.count($checks)." native envelope, source and metadata controls\n";
if (in_array('--integrated', $argv, true)) {
    foreach ([1, 3] as $workers) { $full = $run('integrated'.$workers, $package.'/bin/laramago-worker.php', $workers); if ($signature($full) !== $signature($isolated)) { throw new RuntimeException('Integrated signature mismatch '.$workers.'; inspect '.$workspace); } }
    echo "PASS: isolated, guarded, full one-worker and full three-worker complete signatures match\n";
}
$singleNative = $run('single-native', single: true); $single = $run('single-isolated', $workspace.'/worker.php', single: true);
$singleExpected = array_values(array_filter($singleNative, static fn ($issue): bool => $issue['code'] !== 'redundant-docblock-type'));
if (count($singleNative) - count($singleExpected) !== 1 || $signature($single) !== $signature($singleExpected)) { throw new RuntimeException('Single-file complete signature failure; inspect '.$workspace); }
foreach ([$workspace.'/executed', $workspace.'/database/migrations/executed', $workspace.'/packages/composer/executed'] as $marker) { if (file_exists($marker)) { throw new RuntimeException('Source body executed; inspect '.$workspace); } }
if (file_exists($workspace.'/.env')) { throw new RuntimeException('Unexpected environment.'); }
echo "PASS: single-file, root spaces, custom vendor directory; bootstrap/migration/Composer bodies never execute, no environment or database\n";
echo 'Evidence: '.$workspace."\n";
