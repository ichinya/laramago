<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidatedYieldedLists;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\Internal\Syntax\NodeStore;
use Mago\Sdk\Internal\Syntax\ResolvedNameStore;
use Mago\Sdk\Internal\Syntax\TriviaStore;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;

$package = dirname(__DIR__);
require $package.'/vendor/autoload.php';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago yielded lists '.bin2hex(random_bytes(8));
mkdir($workspace.'/packages', 0777, true);
mkdir($workspace.'/bootstrap', 0777, true);
mkdir($workspace.'/database/migrations', 0777, true);
file_put_contents($workspace.'/composer.json', json_encode(['name' => 'fixture/yielded-lists', 'config' => ['vendor-dir' => 'packages']], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/bootstrap/app.php', '<?php file_put_contents(__DIR__."/../executed", "bootstrap"); throw new RuntimeException("Unexpected execution.");');
file_put_contents($workspace.'/database/migrations/0001_trap.php', '<?php file_put_contents(__DIR__."/../../executed", "migration"); throw new RuntimeException("Unexpected execution.");');
$body = <<<'PHP'
$items = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($items) || !array_is_list($items)) { throw new \RuntimeException('Expected a list of records.'); }
foreach ($items as $item) {
    if (!is_array($item)) { throw new \RuntimeException('Expected a record.'); }
    $label = $item['label'] ?? null;
    $entries = $item['entries'] ?? null;
    $ticks = $item['ticks'] ?? null;
    $messages = $item['messages'] ?? null;
    if (!is_string($label) || !is_array($entries) || !array_is_list($entries)
        || ($ticks !== null && !is_int($ticks))
        || ($messages !== null && (!is_array($messages) || !array_is_list($messages)))) {
        throw new \RuntimeException('Invalid record fields.');
    }
    $record = ['entries' => $entries];
    if ($ticks !== null) { $record['ticks'] = $ticks; }
    if ($messages !== null) {
        foreach ($messages as $message) {
            if (!is_string($message)) { throw new \RuntimeException('Expected text.'); }
        }
        $record['messages'] = $messages;
    }
    yield $label => [$record];
}
PHP;
$output = "iterable<string, array{array{entries: list<mixed>, ticks?: int, messages?: list<string>}}>";
$labelOrigin = '$label = $item[\'label\'] ?? null;';
$profileBody = str_replace($labelOrigin, '$profile = $item["profile"] ?? "general"; if (!is_string($profile)) { throw new \\RuntimeException("Expected a text profile."); } if ($profile !== "general") { continue; } '.$labelOrigin, $body);
$fileBody = '$json = file_get_contents(__DIR__."/records.json"); if (!is_string($json)) { throw new \\RuntimeException("Cannot read records."); } '.$profileBody;
$cases = [
    'nullable string list' => [$body, $output, true],
    'default decode options' => [str_replace(', flags: JSON_THROW_ON_ERROR', '', $body), $output, true],
    'fully qualified primitives' => [str_replace(['json_decode(', 'is_array(', 'array_is_list(', 'is_string(', 'is_int(', 'JSON_THROW_ON_ERROR'], ['\\json_decode(', '\\is_array(', '\\array_is_list(', '\\is_string(', '\\is_int(', '\\JSON_THROW_ON_ERROR'], $body), $output, true],
    'unrelated argument error preserved' => ['requiresInteger("bad"); '.$body, $output, true],
    'other invalid yield preserved' => [$body.' yield "wrong" => 7;', $output, true],
    'renamed field and locals' => [str_replace(['messages', 'message', 'record'], ['notes', 'note', 'packet'], $body), str_replace('messages', 'notes', $output), true],
    'profile early continue' => [$profileBody, $output, true],
    'file input with profile continue' => [$fileBody, $output, true, '', '', '', ''],
    'imported string predicate' => [str_replace('is_string(', 'text(', $body), $output, true],
    'break before validation completes' => [str_replace('throw new \\RuntimeException(\'Expected text.\');', 'break;', $body), $output, false],
    'continue on invalid element' => [str_replace('throw new \\RuntimeException(\'Expected text.\');', 'continue;', $body), $output, false],
    'return on invalid element' => [str_replace('throw new \\RuntimeException(\'Expected text.\');', 'return;', $body), $output, false],
    'nonthrowing validation' => [str_replace('throw new \\RuntimeException(\'Expected text.\');', 'new \\RuntimeException(\'Expected text.\');', $body), $output, false],
    'inverted validation' => [str_replace('!is_string($message)', 'is_string($message)', $body), $output, false],
    'integer validator' => [str_replace('is_string($message)', 'is_int($message)', $body), $output, false],
    'different list checked' => [str_replace('foreach ($messages as $message)', 'foreach ($entries as $message)', $body), $output, false],
    'reference element' => [str_replace('as $message', 'as &$message', $body), $output, false],
    'reference row' => [str_replace('as $item', 'as &$item', $body), $output, false],
    'reference input' => [$body, $output, false, '&'],
    'reference return' => [$body, $output, false, '', '&'],
    'reference alias' => ['$alias =& $json; '.$body, $output, false],
    'captured locals' => ['$callback = function () use (&$json): void {}; '.$body, $output, false],
    'element mutation' => [str_replace('if (!is_string($message))', '$message = "text"; if (!is_string($message))', $body), $output, false],
    'list append' => [str_replace('$record[\'messages\'] = $messages;', '$messages[] = 7; $record[\'messages\'] = $messages;', $body), $output, false],
    'list replacement' => [str_replace('$record[\'messages\'] = $messages;', '$messages = [7]; $record[\'messages\'] = $messages;', $body), $output, false],
    'row mutation' => [str_replace($labelOrigin, '$item["extra"] = 1; '.$labelOrigin, $body), $output, false],
    'different list copied' => [str_replace('$record[\'messages\'] = $messages;', '$record[\'messages\'] = $entries;', $body), $output, false],
    'conditional validation' => [str_replace('foreach ($messages as $message)', 'if ($ticks !== null) foreach ($messages as $message)', $body), $output, false],
    'caught invalid element' => [str_replace(['foreach ($messages as $message) {', '$record[\'messages\'] = $messages;'], ['try { foreach ($messages as $message) {', '} catch (\\RuntimeException) {} $record[\'messages\'] = $messages;'], $body), $output, false],
    'effect after decode' => [str_replace('$record[\'messages\'] = $messages;', 'mutateList($messages); $record[\'messages\'] = $messages;', $body), $output, false],
    'effect after record copy' => [str_replace('yield $label =>', 'mutateRecord($record); yield $label =>', $body), $output, false],
    'record field overwritten' => [str_replace('yield $label =>', '$record["messages"] = [7]; yield $label =>', $body), $output, false],
    'parameter tree' => [str_replace('$items = json_decode($json, true, flags: JSON_THROW_ON_ERROR);', '$items = $input;', $body), $output, false, '', '', ', array $input'],
    'object decoder' => [str_replace('json_decode($json, true', 'json_decode($json, false', $body), $output, false],
    'strong string contract' => [$body, str_replace('list<string>', 'list<non-empty-string>', $output), false],
    'numeric string contract' => [$body, str_replace('list<string>', 'list<numeric-string>', $output), false],
    'nonempty list contract' => [$body, str_replace('list<string>', 'non-empty-list<string>', $output), false],
    'wrong list element contract' => [$body, str_replace('list<string>', 'list<int>', $output), false],
    'unrelated field contract' => [$body, str_replace('entries: list<mixed>', 'entries: list<int>', $output), false],
    'required nullable field' => [$body, str_replace('messages?:', 'messages:', $output), false],
    'missing required field' => [$body, str_replace('ticks?: int', 'enabled: bool, ticks?: int', $output), false],
    'different yielded wrapper' => [str_replace('[$record]', '[$record, $record]', $body), $output, false],
    'nonzero yielded key' => [str_replace('[$record]', '[1 => $record]', $body), $output, false],
    'explicit zero yielded key' => [str_replace('[$record]', '[0 => $record]', $body), $output, false],
    'local documentation override' => [str_replace('$record =', '/** @var array{entries: list<mixed>} $record */ $record =', $body), $output, false],
    'custom exception' => [str_replace('\\RuntimeException', '\\FixtureFailure', $body), $output, false],
    'dynamic local' => ['$name = "json"; $$name = $json; '.$body, $output, false],
    'local extraction' => ['extract([]); '.$body, $output, false],
];
$source = <<<'PHP'
<?php
declare(strict_types=1);
use function is_string as text;
function requiresInteger(int $value): void {}
function mutateList(array &$items): void { $items[] = 7; }
function mutateRecord(array &$record): void { $record['messages'] = [7]; }
class FixtureFailure extends RuntimeException {}

PHP;
$ranges = [];
foreach ($cases as $label => [$code, $contract, $positive]) {
    $start = strlen($source);
    $parameters = $cases[$label][6] ?? ('string '.($cases[$label][3] ?? '').'$json'.($cases[$label][5] ?? ''));
    $source .= '/** @return '.$contract.' */ function '.($cases[$label][4] ?? '').'yieldCase'.count($ranges).'('.$parameters.'): iterable { '.$code." }\n";
    $ranges[$label] = [$start, strlen($source), $positive];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/methods.php', '<?php class RecordProvider { /** @return '.$output.' */ public static function samples(string $json): iterable { '.$body.' } }');
file_put_contents($workspace.'/crlf.php', str_replace("\n", "\r\n", '<?php'."\n// Independently invented byte prefix: \u{00E9}\n".'/** @return '.$output.' */ function crlfSamples(string $json): iterable { '.$body.' }'));
file_put_contents($workspace.'/shadows.php', '<?php namespace PredicateShadow; function is_string(mixed $value): bool { return true; } /** @return '.$output.' */ function samples(string $json): iterable { '.$body.' }');
file_put_contents($workspace.'/context.php', '<?php /** @return '.$output.' */ function contextSamples(string $json): iterable { '.$body.' }');
foreach (['cases.php', 'methods.php', 'crlf.php', 'shadows.php', 'context.php'] as $file) {
    try { (new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse(file_get_contents($workspace.'/'.$file)); }
    catch (\PhpParser\Error $error) { throw new RuntimeException('Invalid generated fixture '.$file.': '.$error->getMessage().' '.$workspace); }
}
if (in_array('--fixtures-only', $argv, true)) {
    echo 'Valid yielded-list fixtures prepared without analyzer execution. Workspace: '.$workspace.".\n";
    exit(0);
}
if (in_array('--integrated', $argv, true)) {
    $worker = file_get_contents($package.'/bin/laramago-worker.php');
    $anchor = '        new ValidatedYieldedListPlugin($projectRoot),';
    if ($worker === false || substr_count($worker, $anchor) !== 1) { throw new RuntimeException('Expected exactly one yielded-list worker registration.'); }
    $control = str_replace($anchor, '', $worker, $replacements);
    if ($replacements !== 1) { throw new RuntimeException('Cannot isolate the yielded-list worker registration.'); }
    file_put_contents($workspace.'/integrated-control-worker.php', $control);
    file_put_contents($workspace.'/integrated-control-source.json', json_encode(['workerHash' => hash('sha256', $worker), 'controlHash' => hash('sha256', $control), 'removedRegistrations' => 1], JSON_THROW_ON_ERROR));
}
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$root = $argv[2];
$mode = $argv[3];
$plugin = new \Ichinya\Laramago\Analyzer\ValidatedYieldedListPlugin($root);
if (in_array($mode, ['contexts', 'observe'], true)) {
    $plugin = new class($root, $mode) implements \Mago\Sdk\Analyzer\Plugin {
        public function __construct(private string $root, private string $mode) {}
        public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/yielded-controls', 'Yielded-list controls', 'Genuine source and metadata observations'); }
        public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
            $proofs = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ValidatedYieldedLists($this->root);
            $registry->registerInitializationHook($proofs);
            $registry->registerCodebaseScanHook($proofs);
            $registry->registerIssueFilterHook(new class($proofs, $this->root, $this->mode) implements \Mago\Sdk\Analyzer\IssueFilterHook {
                private $filter;
                public function __construct(private $proofs, private string $root, private string $mode) { $this->filter = new \Ichinya\Laramago\Analyzer\ValidatedYieldedListIssueFilter($proofs); }
                public function getCodes(): array { return $this->filter->getCodes(); }
                public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                    $sites = $this->proofs->proofs($context->file, $context->contents);
                    $stages = [];
                    if ($this->mode === 'observe') {
                        preg_match('/^Invalid value type yielded; expected `([^`]+)`, but found `([^`]+)`\.$/D', $context->issue->message, $reported);
                        $expected = isset($reported[1]) ? \Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticReturnTypes::parse($reported[1]) : null;
                        $found = isset($reported[2]) ? \Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticReturnTypes::parse($reported[2]) : null;
                        foreach ($sites as $site) {
                            $invoke = static fn (string $method, ...$arguments) => (new \ReflectionMethod(\Ichinya\Laramago\Analyzer\StaticAnalysis\ValidatedYieldedLists::class, $method))->invoke(null, ...$arguments);
                            $contract = $this->proofs->contract($context->codebase, $context->types, $site);
                            $narrowed = $found === null ? null : (new \ReflectionMethod($this->filter, 'narrow'))->invoke(null, $found, $site['field'], $context);
                            $containment = [];
                            $actualWrapper = $narrowed?->atomicTypes[0] ?? null;
                            $expectedWrapper = $expected?->atomicTypes[0] ?? null;
                            if ($actualWrapper instanceof \Mago\Sdk\Analyzer\Type\ListType && $expectedWrapper instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType
                                && count($actualWrapper->knownElements ?? []) === 1 && count($expectedWrapper->knownItems ?? []) === 1) {
                                $actualRecord = $actualWrapper->knownElements[0]->type;
                                $expectedRecord = $expectedWrapper->knownItems[0]->type;
                                $canonical = \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\KeyedArrayType([
                                    new \Mago\Sdk\Analyzer\Type\ArrayItem(new \Mago\Sdk\Analyzer\Type\ArrayKey(\Mago\Sdk\Analyzer\Type\ArrayKeyKind::Integer, 0), false, $actualRecord),
                                ], null, null, true));
                                $containment = [
                                    'recordContained' => $context->types->isContainedBy($actualRecord, $expectedRecord),
                                    'recordEqual' => $context->types->equals($actualRecord, $expectedRecord),
                                    'wrapperEqual' => $context->types->equals($narrowed, $expected),
                                    'wrapperSelfContained' => $context->types->isContainedBy($narrowed, $narrowed),
                                    'canonicalContained' => $context->types->isContainedBy($canonical, $expected),
                                    'canonicalEqual' => $context->types->equals($canonical, $expected),
                                    'listToCanonical' => $context->types->isContainedBy($narrowed, $canonical),
                                    'canonicalToList' => $context->types->isContainedBy($canonical, $narrowed),
                                    'canonical' => $canonical,
                                ];
                                $fields = [];
                                $actualShape = $actualRecord->atomicTypes[0] ?? null;
                                $expectedShape = $expectedRecord->atomicTypes[0] ?? null;
                                if ($actualShape instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType && $expectedShape instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType) {
                                    foreach ($expectedShape->knownItems ?? [] as $expectedField) {
                                        foreach ($actualShape->knownItems ?? [] as $actualField) {
                                            if ($actualField->key->kind === $expectedField->key->kind && $actualField->key->value === $expectedField->key->value) {
                                                $fields[] = ['key' => $actualField->key->value, 'actualOptional' => $actualField->optional, 'expectedOptional' => $expectedField->optional,
                                                    'contained' => $context->types->isContainedBy($actualField->type, $expectedField->type),
                                                    'equal' => $context->types->equals($actualField->type, $expectedField->type)];
                                            }
                                        }
                                    }
                                }
                                $containment['fields'] = $fields;
                            }
                            $stages[] = [
                                'span' => [$site['value']->getStartFilePos(), $site['value']->getEndFilePos() + 1],
                                'current' => $this->proofs::current($site), 'callerValid' => $invoke('caller', $context->codebase, $site) !== null,
                                'decoder' => $invoke('decoder', $context->codebase, $context->types, $site['decoder']),
                                'predicates' => array_map(static fn ($call): array => ['name' => $call->name->toString(), 'valid' => $invoke('predicate', $context->codebase, $context->types, $call)], $site['calls']),
                                'throws' => array_map(static fn ($syntax): array => ['name' => $syntax->class->toString(), 'valid' => $invoke('exception', $context->codebase, $context->types, $syntax)], $site['throws']),
                                'diff' => isset($reported[1], $reported[2], $context->issue->notes[1]) && (new \ReflectionMethod($this->filter, 'diff'))->invoke(null, $reported[1], $reported[2], $context->issue->notes[1]),
                                'expected' => $expected, 'found' => $found, 'contract' => $contract, 'narrowed' => $narrowed,
                                'contractMatches' => $contract !== null && $expected !== null && \Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes::same($contract, $expected, $context->types),
                                'contained' => $narrowed !== null && $expected !== null && $context->types->isContainedBy($narrowed, $expected),
                                'containment' => $containment,
                            ];
                        }
                    }
                    file_put_contents($this->root.'/native-metadata.txt', var_export([
                        'proofCount' => count($sites), 'decision' => $this->filter->filterIssue($context), 'stages' => $stages, 'issue' => $context->issue,
                        'caller' => $context->codebase->getFunction('contextSamples'), 'json_decode' => $context->codebase->getFunction('json_decode'),
                        'jsonFlag' => $context->codebase->getConstant('JSON_THROW_ON_ERROR'),
                        'is_array' => $context->codebase->getFunction('is_array'), 'array_is_list' => $context->codebase->getFunction('array_is_list'),
                        'is_string' => $context->codebase->getFunction('is_string'), 'is_int' => $context->codebase->getFunction('is_int'),
                        'exception' => $context->codebase->getClass('RuntimeException'), 'parentException' => $context->codebase->getClass('Exception'),
                        'constructor' => $context->codebase->getDeclaringMethod('RuntimeException', '__construct'),
                    ], true));
                    if ($this->mode === 'observe') { return $this->filter->filterIssue($context); }
                    $decision = $this->filter->filterIssue($context);
                    if ($decision !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { throw new \RuntimeException('Baseline yielded-list proof kept.'); }
                    $variants = [
                        ['level' => \Mago\Sdk\Reporting\Level::Warning], ['code' => 'invalid-return-statement'], ['message' => $context->issue->message.' changed'],
                        ['notes' => ['changed', $context->issue->notes[1]]], ['notes' => [$context->issue->notes[0]]],
                        ['notes' => [$context->issue->notes[0], 'changed']], ['help' => 'changed'], ['link' => 'https://example.invalid'],
                        ['annotations' => []], ['annotations' => [$context->issue->annotations[0], $context->issue->annotations[0]]],
                        ['annotations' => [new \Mago\Sdk\Reporting\Annotation(\Mago\Sdk\Reporting\AnnotationKind::Secondary, $context->issue->annotations[0]->span, $context->issue->annotations[0]->message)]],
                        ['annotations' => [new \Mago\Sdk\Reporting\Annotation(\Mago\Sdk\Reporting\AnnotationKind::Primary, $context->issue->annotations[0]->span, 'changed')]],
                        ['annotations' => [new \Mago\Sdk\Reporting\Annotation(\Mago\Sdk\Reporting\AnnotationKind::Primary, $context->issue->annotations[0]->span, $context->issue->annotations[0]->message, 'foreign.php')]],
                        ['edits' => [\Mago\Sdk\Reporting\TextEdit::insert($context->issue->annotations[0]->span->start, 'changed')]],
                        ['notes' => [$context->issue->notes[0], $context->issue->notes[1]." changed\n"]],
                        ['notes' => [$context->issue->notes[0], str_replace('list<string>', 'list<int>', $context->issue->notes[1])]],
                        ['notes' => [$context->issue->notes[0], str_replace('@@ -1,', '@@ -2,', $context->issue->notes[1])]],
                        ['annotations' => [new \Mago\Sdk\Reporting\Annotation(\Mago\Sdk\Reporting\AnnotationKind::Primary, new \Mago\Sdk\Span($context->issue->annotations[0]->span->start + 1, $context->issue->annotations[0]->span->end), $context->issue->annotations[0]->message)]],
                    ];
                    $checks = 0;
                    foreach ($variants as $changes) {
                        $issue = new \Mago\Sdk\Reporting\ReportedIssue(...array_replace(get_object_vars($context->issue), $changes));
                        $changed = new \Mago\Sdk\Analyzer\IssueFilterContext(...array_replace(get_object_vars($context), ['issue' => $issue]));
                        if ($this->filter->filterIssue($changed) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Changed native issue envelope accepted.'); }
                        $checks++;
                    }
                    $changed = new \Mago\Sdk\Analyzer\IssueFilterContext(...array_replace(get_object_vars($context), ['contents' => $context->contents."\n"]));
                    if ($this->filter->filterIssue($changed) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Changed source snapshot accepted.'); }
                    $checks++;
                    preg_match('/^Invalid value type yielded; expected `([^`]+)`, but found `([^`]+)`\.$/D', $context->issue->message, $nativeTypes);
                    $nativeFound = \Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticReturnTypes::parse($nativeTypes[2]);
                    $wrapper = $nativeFound->atomicTypes[0];
                    $recordType = $wrapper->knownElements[0]->type;
                    $record = $recordType->atomicTypes[0];
                    $field = $sites[0]['field'];
                    $narrow = new \ReflectionMethod($this->filter, 'narrow');
                    $canonical = $narrow->invoke(null, $nativeFound, $field, $context);
                    $canonicalAtom = $canonical?->atomicTypes[0] ?? null;
                    if (! $canonicalAtom instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType || $canonicalAtom->keyType !== null || $canonicalAtom->valueType !== null
                        || ! $canonicalAtom->nonEmpty || count($canonicalAtom->knownItems ?? []) !== 1 || $canonicalAtom->knownItems[0]->optional
                        || $canonicalAtom->knownItems[0]->key->kind !== \Mago\Sdk\Analyzer\Type\ArrayKeyKind::Integer || $canonicalAtom->knownItems[0]->key->value !== 0) { throw new \RuntimeException('Exact integer-zero wrapper certificate missing.'); }
                    $checks++;
                    $element = static fn (int $index = 0, bool $optional = false, $type = null) => new \Mago\Sdk\Analyzer\Type\ListElement($index, $optional, $type ?? $recordType);
                    $list = static fn ($type, $elements, $count = 1, bool $nonEmpty = true) => \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ListType($type, $elements, $count, $nonEmpty));
                    $required = $integers = [];
                    foreach ($record->knownItems as $item) {
                        $target = $item->key->kind === \Mago\Sdk\Analyzer\Type\ArrayKeyKind::String && $item->key->value === $field;
                        $required[] = $target ? new \Mago\Sdk\Analyzer\Type\ArrayItem($item->key, false, $item->type) : $item;
                        $integers[] = $target ? new \Mago\Sdk\Analyzer\Type\ArrayItem($item->key, $item->optional, \Mago\Sdk\Analyzer\Type::list(\Mago\Sdk\Analyzer\Type::int())) : $item;
                    }
                    $recordVariants = [
                        'record fallback' => \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\KeyedArrayType($record->knownItems, \Mago\Sdk\Analyzer\Type::int(), \Mago\Sdk\Analyzer\Type::mixed(), $record->nonEmpty)),
                        'required validated field' => \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\KeyedArrayType($required, null, null, $record->nonEmpty)),
                        'contradictory native element field' => \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\KeyedArrayType($integers, null, null, $record->nonEmpty)),
                    ];
                    $invalidWrappers = [
                        'extra item' => $list($recordType, [$element(), $element(1)], 2),
                        'nonzero item' => $list($recordType, [$element(1)]),
                        'optional item' => $list($recordType, [$element(0, true)]),
                        'unknown length' => $list($recordType, [$element()], null),
                        'broader element domain' => $list(\Mago\Sdk\Analyzer\Type::mixed(), [$element()]),
                        'possibly empty wrapper' => $list($recordType, [$element()], 1, false),
                        'unknown record' => $list(\Mago\Sdk\Analyzer\Type::mixed(), [$element(type: \Mago\Sdk\Analyzer\Type::mixed())]),
                        'non-list wrapper' => $canonical,
                    ];
                    foreach ($recordVariants as $label => $type) { $invalidWrappers[$label] = $list($type, [$element(type: $type)]); }
                    foreach ($invalidWrappers as $label => $type) {
                        if ($narrow->invoke(null, $type, $field, $context) !== null) { throw new \RuntimeException('Contradictory exact wrapper admitted: '.$label); }
                        $checks++;
                    }
                    $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                    foreach (['contextSamples', 'is_string', 'json_decode', 'array_is_list'] as $name) {
                        $metadata = $context->codebase->getFunction($name);
                        foreach (['flags', 'returnType', 'identifier'] as $variant) {
                            $values = get_object_vars($metadata);
                            if ($variant === 'flags') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                            if ($variant === 'returnType') { $values['returnType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(...array_replace(get_object_vars($metadata->returnType), ['type' => \Mago\Sdk\Analyzer\Type::string()])); }
                            if ($variant === 'identifier') { $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier(...array_replace(get_object_vars($metadata->identifier), ['name' => 'changed'])); }
                            $replacement = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                            $saved = $cache->values;
                            $replaced = 0;
                            foreach ($saved as $operation => $entries) { foreach ($entries as $key => $entry) { if ($entry === $metadata) { $cache->values[$operation][$key] = $replacement; $replaced++; } } }
                            try { if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Changed metadata accepted: '.$name.' '.$variant); } }
                            finally { $cache->values = $saved; }
                            $checks++;
                        }
                    }
                    $replaceMetadata = function (object $metadata, object $replacement, string $label) use ($context, $cache): void {
                        $saved = $cache->values;
                        $replaced = 0;
                        foreach ($saved as $operation => $entries) { foreach ($entries as $key => $entry) { if ($entry === $metadata) { $cache->values[$operation][$key] = $replacement; $replaced++; } } }
                        try { if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Native contract mutation accepted: '.$label); } }
                        finally { $cache->values = $saved; }
                    };
                    foreach (['is_string', 'array_is_list'] as $name) {
                        $metadata = $context->codebase->getFunction($name);
                        $conditional = $metadata->returnType->type->atomicTypes[0];
                        foreach (['subject', 'target', 'then', 'otherwise', 'negated'] as $variant) {
                            $values = get_object_vars($conditional);
                            $values[$variant] = match ($variant) {
                                'subject' => \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\VariableType('$different')),
                                'target' => \Mago\Sdk\Analyzer\Type::int(),
                                'then' => \Mago\Sdk\Analyzer\Type::false(),
                                'otherwise' => \Mago\Sdk\Analyzer\Type::true(),
                                'negated' => true,
                            };
                            $changed = \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ConditionalType(...$values));
                            $return = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(...array_replace(get_object_vars($metadata->returnType), ['type' => $changed]));
                            $replaceMetadata($metadata, new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...array_replace(get_object_vars($metadata), ['returnType' => $return])), $name.' conditional '.$variant);
                            $checks++;
                        }
                        $replaceMetadata($metadata, new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...array_replace(get_object_vars($metadata), ['ifTrueAssertions' => []])), $name.' missing assertion');
                        $checks++;
                    }
                    $metadata = $context->codebase->getFunction('array_is_list');
                    $template = $metadata->templates[0];
                    $replaceMetadata($metadata, new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...array_replace(get_object_vars($metadata), ['templates' => []])), 'missing native V');
                    $checks++;
                    foreach (['name', 'constraint', 'definingEntity', 'default', 'variance', 'readonly'] as $variant) {
                        $values = get_object_vars($template);
                        $values[$variant] = match ($variant) {
                            'name' => 'Other', 'constraint', 'default' => \Mago\Sdk\Analyzer\Type::int(),
                            'definingEntity' => new \Mago\Sdk\Analyzer\Type\GenericParent(\Mago\Sdk\Analyzer\Type\GenericParentKind::FunctionLike, '', 'other'),
                            'variance' => \Mago\Sdk\Analyzer\Type\Variance::Covariant, 'readonly' => true,
                        };
                        $changed = new \Mago\Sdk\Analyzer\Metadata\TemplateMetadata(...$values);
                        $replaceMetadata($metadata, new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...array_replace(get_object_vars($metadata), ['templates' => [$changed]])), 'native V '.$variant);
                        $checks++;
                    }
                    foreach (['json_decode', 'array_is_list', 'is_string'] as $name) {
                        $metadata = $context->codebase->getFunction($name);
                        $parameter = $metadata->parameters[0];
                        $type = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(...array_replace(get_object_vars($parameter->type), ['type' => \Mago\Sdk\Analyzer\Type::int()]));
                        $parameters = $metadata->parameters;
                        $parameters[0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...array_replace(get_object_vars($parameter), ['type' => $type]));
                        $replaceMetadata($metadata, new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...array_replace(get_object_vars($metadata), ['parameters' => $parameters])), $name.' wrong parameter');
                        $checks++;
                    }
                    $metadata = $context->codebase->getFunction('contextSamples');
                    foreach (['file', 'nameSpan', 'nativeReturn', 'documentedReturn'] as $variant) {
                        $values = get_object_vars($metadata);
                        if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('foreign.php', $metadata->location->span); }
                        if ($variant === 'nameSpan') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation($metadata->nameLocation->file, new \Mago\Sdk\Span($metadata->nameLocation->span->start + 1, $metadata->nameLocation->span->end)); }
                        if ($variant === 'nativeReturn') { $values['declaredReturnType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(...array_replace(get_object_vars($metadata->declaredReturnType), ['type' => \Mago\Sdk\Analyzer\Type::string()])); }
                        if ($variant === 'documentedReturn') { $values['returnType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(...array_replace(get_object_vars($metadata->returnType), ['fromDocblock' => false])); }
                        $replaceMetadata($metadata, new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values), 'caller '.$variant);
                        $checks++;
                    }
                    $metadata = $context->codebase->getConstant('JSON_THROW_ON_ERROR');
                    $replaceMetadata($metadata, new \Mago\Sdk\Analyzer\Metadata\ConstantMetadata(...array_replace(get_object_vars($metadata), ['inferredType' => \Mago\Sdk\Analyzer\Type::literalInt(0)])), 'JSON flag value');
                    $checks++;
                    $metadata = $context->codebase->getDeclaringMethod('RuntimeException', '__construct');
                    $replaceMetadata($metadata, new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...array_replace(get_object_vars($metadata), ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)])), 'reference exception constructor');
                    $checks++;
                    foreach (['RuntimeException', 'Exception'] as $name) {
                        $metadata = $context->codebase->getClass($name);
                        foreach (['name', 'originalName', 'directParentClass', 'flags'] as $variant) {
                            $values = get_object_vars($metadata);
                            $values[$variant] = $variant === 'flags'
                                ? new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::USER_DEFINED)
                                : 'UnrelatedException';
                            $replaceMetadata($metadata, new \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$values), $name.' exception '.$variant);
                            $checks++;
                        }
                    }
                    $path = $this->root.'/context.php';
                    $savedBytes = file_get_contents($path);
                    try { file_put_contents($path, $savedBytes."\n"); if ($this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Changed disk source accepted.'); } }
                    finally { file_put_contents($path, $savedBytes); }
                    $checks++;
                    if ($this->filter->filterIssue($context) !== $decision) { throw new \RuntimeException('Source or metadata mutation leaked.'); }
                    file_put_contents($this->root.'/context-checks.txt', (string) $checks);
                    return $decision;
                }
            });
        }
    };
}
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/yielded-lists', name: 'Yielded list fixture', version: '1', analyzerPlugins: [$plugin])))->run();
PHP);
$analyze = static function (string $mode, array $paths = ['cases.php', 'methods.php', 'crlf.php', 'shadows.php'], int $workers = 1, bool $external = false, bool $allowParse = false) use ($package, $workspace): array {
    $configuration = $external ? $workspace.'/external configuration' : $workspace;
    if (! is_dir($configuration)) { mkdir($configuration); }
    $settings = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5', 'source' => ['paths' => $paths], 'threads' => $workers,
        'analyzer' => ['find-unused-expressions' => false, 'check-throws' => false, 'ignore' => []]];
    if ($mode !== 'native') {
        $command = in_array($mode, ['integrated', 'integrated-control'], true)
            ? [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/integrated-control-worker.php', $package.'/vendor/autoload.php', $workspace]
            : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace, $mode];
        $settings['extension-hosts'] = ['fixture' => ['command' => $command, 'workers' => $workers, 'request-timeout-ms' => 120000]];
    }
    file_put_contents($configuration.'/mago.json', json_encode($settings, JSON_THROW_ON_ERROR));
    $artifact = $mode.'-'.$workers.($external ? '-external' : '').($paths === ['cases.php', 'methods.php', 'crlf.php', 'shadows.php'] ? '' : '-'.str_replace('.', '-', implode('-', $paths)));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $process = proc_open([...$command, '--workspace', $workspace, '--config', $configuration.'/mago.json', 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$artifact.'.json', 'w'], 2 => ['file', $workspace.'/'.$artifact.'.stderr', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago. '.$workspace); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$artifact.'.stderr');
    if (! in_array($exit, [0, 1], true) || preg_match('/failed|rejected request|fatal error/i', $stderr)
        || ! $allowParse && preg_match('/pars(?:e|ing) errors?/i', $stderr)) { throw new RuntimeException('Mago or worker failed: '.$stderr.' '.$workspace); }
    file_put_contents($workspace.'/report-scopes.jsonl', json_encode(['artifact' => $artifact, 'mode' => $mode, 'paths' => $paths, 'workers' => $workers, 'external' => $external, 'exit' => $exit], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    return json_decode(file_get_contents($workspace.'/'.$artifact.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$signature = static function (array $issues): array {
    $rows = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues);
    sort($rows, SORT_STRING);
    return $rows;
};
$primary = static function (array $issue): ?array {
    $annotations = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'));
    return count($annotations) === 1 ? $annotations[0] : null;
};
if (in_array('--observe', $argv, true)) {
    $analyze('native', ['context.php']);
    $analyze('observe', ['context.php']);
    echo 'Genuine yielded-list observation finished. Workspace: '.$workspace.".\n";
    exit(0);
}
$native = $analyze('native');
$isolated = $analyze('standalone');
$removed = [];
$negativeGroups = 0;
foreach ($ranges as $label => [$start, $end, $positive]) {
    $group = static fn (array $issue): bool => ($span = $primary($issue)) !== null && $span['span']['file_id']['name'] === 'cases.php'
        && $span['span']['start']['offset'] >= $start && $span['span']['end']['offset'] <= $end;
    $before = array_values(array_filter($native, $group));
    $after = array_values(array_filter($isolated, $group));
    $targets = array_values(array_filter($before, static fn (array $issue): bool => $issue['code'] === 'invalid-yield-value-type'
        && $issue['annotations'][0]['span']['end']['offset'] - $issue['annotations'][0]['span']['start']['offset'] === strlen('[$record]')));
    if ($positive && $label === 'renamed field and locals') { $targets = array_values(array_filter($before, static fn (array $issue): bool => $issue['code'] === 'invalid-yield-value-type')); }
    if ($positive) {
        if (count($targets) !== 1) { throw new RuntimeException('Missing unique native target: '.$label.' '.$workspace); }
        $target = json_encode($targets[0], JSON_THROW_ON_ERROR);
        $expected = array_values(array_filter($before, static fn (array $issue): bool => json_encode($issue, JSON_THROW_ON_ERROR) !== $target));
        if ($signature($after) !== $signature($expected)) { throw new RuntimeException('Incorrect complete issue delta: '.$label.' '.$workspace); }
        $removed[] = $target;
    } else {
        if ($before === []) { throw new RuntimeException('Vacuous negative group: '.$label.' '.$workspace); }
        if ($signature($after) !== $signature($before)) { throw new RuntimeException('Negative source diagnostics changed: '.$label.' '.$workspace); }
        $negativeGroups++;
    }
}
foreach (['methods.php', 'crlf.php'] as $file) {
    $before = array_values(array_filter($native, static fn (array $issue): bool => ($primary($issue)['span']['file_id']['name'] ?? null) === $file));
    $targets = array_values(array_filter($before, static fn (array $issue): bool => $issue['code'] === 'invalid-yield-value-type'));
    if (count($targets) !== 1) { throw new RuntimeException('Missing method or CRLF native target. '.$workspace); }
    $removed[] = json_encode($targets[0], JSON_THROW_ON_ERROR);
}
$expected = array_values(array_filter($native, static fn (array $issue): bool => ! in_array(json_encode($issue, JSON_THROW_ON_ERROR), $removed, true)));
if ($signature($isolated) !== $signature($expected)) { throw new RuntimeException('Whole native issue signatures differ beyond certified targets. '.$workspace); }
$otherErrors = array_values(array_filter($expected, static fn (array $issue): bool => $issue['level'] === 'Error'));
if (count($otherErrors) < 4 || ! in_array('invalid-argument', array_column($otherErrors, 'code'), true)) { throw new RuntimeException('Actual Error-preservation witnesses are missing. '.$workspace); }
if ($signature($analyze('standalone', external: true)) !== $signature($isolated)) { throw new RuntimeException('External configuration changed the rule. '.$workspace); }
$single = $analyze('standalone', ['cases.php']);
$expectedSingle = array_values(array_filter($isolated, static fn (array $issue): bool => ($primary($issue)['span']['file_id']['name'] ?? null) === 'cases.php'));
if ($signature($single) !== $signature($expectedSingle)) { throw new RuntimeException('Single-file analysis changed diagnostics. '.$workspace); }
$analyze('contexts', ['context.php']);
if ((int) file_get_contents($workspace.'/context-checks.txt') !== 80) { throw new RuntimeException('Incomplete native envelope and metadata controls. '.$workspace); }
if (in_array('--integrated', $argv, true)) {
    $control = $analyze('integrated-control');
    foreach ($removed as $target) { if (! in_array($target, $signature($control), true)) { throw new RuntimeException('A certified target disappeared in the full-worker control. '.$workspace); } }
    $expectedFull = array_values(array_filter($control, static fn (array $issue): bool => ! in_array(json_encode($issue, JSON_THROW_ON_ERROR), $removed, true)));
    foreach ([1, 3] as $workers) { if ($signature($analyze('integrated', workers: $workers)) !== $signature($expectedFull)) { throw new RuntimeException('Integrated full-worker issue signatures changed: '.$workers.' '.$workspace); } }
}
if (file_exists($workspace.'/executed') || file_exists($workspace.'/.env')) { throw new RuntimeException('Unexpected application execution or environment dependency.'); }
$scanChecks = (static function (string $root, string $source): int {
    mkdir($root);
    file_put_contents($root.'/context.php', $source);
    $version = PHPVersion::fromParts(8, 5);
    $token = new class implements CancellationTokenInterface {
        public bool $cancelled = false;
        public function isCancelled(): bool { return $this->cancelled; }
        public function throwIfCancelled(): void { if ($this->cancelled) { throw new \Mago\Sdk\Exception\CancelledException; } }
        public function subscribe(Closure $callback): int { return 0; }
        public function unsubscribe(int $subscription): void {}
    };
    $index = new ValidatedYieldedLists($root);
    $file = static fn (string $path, string $contents): SourceFile => new SourceFile($version, $path, $contents, [], new NodeStore([], '', 0), new ResolvedNameStore('', '', '', 0), new TriviaStore('', 0), null);
    $scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $token): void { $index->scan(new CodebaseScanContext($version, $token, $files, $first, $last)); };
    $checks = 0;
    $expect = static function (string $label, bool $value) use (&$checks): void { if (!$value) { throw new RuntimeException('Yielded-list scan failed: '.$label); } $checks++; };
    $host = $file('context.php', $source);
    $expect('no generation', $index->proofs('context.php', $source) === []);
    $scan([$host], first: false);
    $expect('missing first batch', $index->proofs('context.php', $source) === []);
    $scan([$host], last: false);
    $expect('incomplete generation', $index->proofs('context.php', $source) === []);
    $scan([], first: false);
    $proofs = $index->proofs('context.php', $source);
    $expect('complete generation', count($proofs) === 1);
    $expect('current bytes', ValidatedYieldedLists::current($proofs[0]));
    $expect('foreign file', $index->proofs('other.php', $source) === []);
    $expect('different SDK bytes', $index->proofs('context.php', $source."\n") === []);
    file_put_contents($root.'/context.php', $source."\n");
    $expect('user edit', !ValidatedYieldedLists::current($proofs[0]));
    file_put_contents($root.'/context.php', $source);
    $expect('restored source', ValidatedYieldedLists::current($proofs[0]));
    $scan([$host, $host]);
    $expect('duplicate path', $index->proofs('context.php', $source) === []);
    $scan([$host, $file('broken.php', '<?php function invalid( {')]);
    $expect('invalid syntax', $index->proofs('context.php', $source) === []);
    $scan([$host, $file('oversized.php', str_repeat(' ', 2_000_001))]);
    $expect('oversized source', $index->proofs('context.php', $source) === []);
    $token->cancelled = true;
    $scan([$host]);
    $expect('cancelled generation', $index->proofs('context.php', $source) === []);
    $token->cancelled = false;
    $scan([$host]);
    $expect('generation recovers', count($index->proofs('context.php', $source)) === 1);
    $scan([], first: false);
    $expect('batch after completion', $index->proofs('context.php', $source) === []);
    $scan([$host]);
    $index->initialize(new InitializationContext($version, $token));
    $expect('initialization resets', $index->proofs('context.php', $source) === []);
    $other = str_replace('contextSamples', 'otherSamples', $source);
    $scan([$host, $file('other.php', $other)]);
    $expect('foreign proof separately indexed', count($index->proofs('context.php', $source)) === 1 && count($index->proofs('other.php', $other)) === 1);
    $ticks = str_replace('<?php', '<?php declare(ticks=1);', $source);
    $scan([$file('context.php', $ticks)]);
    $expect('tick callbacks defer', $index->proofs('context.php', $ticks) === []);
    return $checks;
})($workspace.'/scan root', file_get_contents($workspace.'/context.php'));
if ($scanChecks !== 18) { throw new RuntimeException('Incomplete yielded-list lifecycle controls.'); }
// Malformed source is a separate genuine negative scope and never contaminates normal fixture acceptance.
file_put_contents($workspace.'/invalid.php', '<?php function broken( {');
$invalidNative = $analyze('native', ['context.php', 'invalid.php'], allowParse: true);
$invalidPolicy = $analyze('standalone', ['context.php', 'invalid.php'], allowParse: true);
if (! in_array('parse', array_column($invalidNative, 'code'), true) || ! in_array('invalid-yield-value-type', array_column($invalidNative, 'code'), true)
    || $signature($invalidNative) !== $signature($invalidPolicy)) { throw new RuntimeException('Malformed source did not fail closed with exact native diagnostics. '.$workspace); }
echo 'Validated yielded-list checks passed: '.count($cases).' cases, '.count($removed).' exact yield corrections, '.$negativeGroups.' retained negative groups, 80 native envelope/metadata/type and '.$scanChecks.' lifecycle controls, separate genuine malformed-source negative, unchanged non-target issue signatures, spaces/custom vendor/single-file analysis'.(in_array('--integrated', $argv, true) ? ', one and three integrated workers' : '').'. Workspace: '.$workspace.".\n";
