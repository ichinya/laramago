<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConditionalArrayGuards;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\Internal\Syntax\NodeStore;
use Mago\Sdk\Internal\Syntax\ResolvedNameStore;
use Mago\Sdk\Internal\Syntax\TriviaStore;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;

// Analyze independently invented declarations without executing their bodies.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago conditional array '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php', '<?php file_put_contents(__DIR__."/executed", "bootstrap"); throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$mode = $argv[3];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/conditional-array', 'Conditional array guard', 'Exact native nested array facts');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ConditionalArrayGuards($this->root);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $registry->registerIssueFilterHook(new \Ichinya\Laramago\Analyzer\ConditionalArrayGuardIssueFilter($index));
    }
};
$plugins = $mode === 'standalone' ? [] : [new \Ichinya\Laramago\Analyzer\LaravelPlugin($argv[2])];
if (in_array($mode, ['standalone', 'proven'], true)) { array_unshift($plugins, $plugin); }
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/conditional-array', name: 'Conditional array fixture', version: '1', analyzerPlugins: $plugins)))->run();
PHP);

$decode = '$data = json_decode($text, true); ';
$rootGuard = 'if (!is_array($data)) { throw new RuntimeException("Expected an array."); } if ($selection !== null && !is_string($selection)) { throw new RuntimeException("Expected a string key."); } ';
$guard = 'if ($selection !== null && (!isset($data["sections"]) || !is_array($data["sections"]) || !array_key_exists($selection, $data["sections"]))) { throw new RuntimeException("Expected a section."); } ';
$target = '$data["sections"][$selection]';
$ternary = '$selection === null ? ($data["entries"] ?? null) : '.$target;
$finish = 'return receiveValue('.$ternary.');';
$normal = $decode.$rootGuard.$guard.$finish;
$cases = [
    'guarded named consumer' => [$normal, true],
    'guarded direct return' => [$decode.$rootGuard.$guard.'return '.$ternary.';', true],
    'guarded assignment' => [$decode.$rootGuard.$guard.'$result = '.$ternary.'; return $result;', true],
    'fully qualified native helpers' => [str_replace(['json_decode(', 'is_array(', 'array_key_exists('], ['\\json_decode(', '\\is_array(', '\\array_key_exists('], $normal), true],
    'native throwing JSON flag' => [str_replace('json_decode($text, true)', 'json_decode($text, true, 512, JSON_THROW_ON_ERROR)', $normal), true],
    'multiline guard' => [str_replace(' || ', "\n        || ", $normal), true],
    'consumer body mutates after capture' => [str_replace('receiveValue('.$ternary.')', 'receiveAfterCapture('.$ternary.')', $normal), true],
    'missing array condition' => [str_replace(' || !is_array($data["sections"])', '', $normal), false],
    'missing existence condition' => [str_replace(' || !array_key_exists($selection, $data["sections"])', '', $normal), false],
    'missing isset condition' => [str_replace('!isset($data["sections"]) || ', '', $normal), false],
    'different field' => [str_replace($guard, str_replace('sections', 'other', $guard), $normal), false],
    'different key' => [str_replace($guard, str_replace('$selection,', '$otherSelection,', $guard), $normal), false, 'string $text, ?string $selection, string $otherSelection'],
    'different root' => [$decode.'$otherData = $data; '.$rootGuard.str_replace('$data', '$otherData', $guard).$finish, false],
    'inverted nullable guard' => [str_replace('$selection !== null &&', '$selection === null &&', $normal), false],
    'loose nullable guard' => [str_replace('$selection !== null &&', '$selection != null &&', $normal), false],
    'inverted ternary' => [$decode.$rootGuard.$guard.'return receiveValue($selection !== null ? ($data["entries"] ?? null) : '.$target.');', false],
    'different ternary key' => [$decode.$rootGuard.$guard.str_replace('$selection === null', '$otherSelection === null', $finish), false, 'string $text, ?string $selection, ?string $otherSelection'],
    'nonthrowing guard' => [str_replace('throw new RuntimeException("Expected a section.");', 'receiveValue(null);', $normal), false],
    'returning guard' => [str_replace('throw new RuntimeException("Expected a section.");', 'return null;', $normal), false],
    'caught guard' => [$decode.$rootGuard.'try { '.$guard.' } catch (RuntimeException) {} '.$finish, false],
    'conditional guard' => [$decode.$rootGuard.'if ($enabled) { '.$guard.' } '.$finish, false, 'string $text, ?string $selection, bool $enabled'],
    'root reset' => [$decode.$rootGuard.$guard.'$data = json_decode($text, true); '.$finish, false],
    'field reset' => [$decode.$rootGuard.$guard.'$data["sections"] = $opaque; '.$finish, false, 'string $text, ?string $selection, mixed $opaque'],
    'key reset' => [$decode.$rootGuard.$guard.'$selection = $otherSelection; '.$finish, false, 'string $text, ?string $selection, ?string $otherSelection'],
    'reference alias declared after guard' => [$decode.$rootGuard.'$alias =& $data; '.$guard.$finish, false],
    'prior reference alias' => ['$alias =& $data; '.$normal, false],
    'fresh local array copy' => [$decode.$rootGuard.'$alias = $data; '.$guard.$finish, true],
    'reference alias field mutation' => ['$alias =& $data; '.$decode.$rootGuard.$guard.'$alias["sections"] = $opaque; '.$finish, false, 'string $text, mixed $selection, mixed $opaque'],
    'reference alias object payload' => ['$alias =& $data; '.$decode.$rootGuard.'$alias = ["item" => $opaque]; $alias = $data["item"]; '.$guard.$finish, false, 'string $text, mixed $selection, ArrayAccess $opaque'],
    'captured array' => [$decode.$rootGuard.'$callback = function () use (&$data): void {}; '.$guard.$finish, false],
    'hidden compact alias' => [$decode.$rootGuard.'receiveValue(compact("data")); '.$guard.$finish, false],
    'hidden local alias' => [$decode.$rootGuard.'receiveValue(get_defined_vars()); '.$guard.$finish, false],
    'dynamic local' => [$decode.$rootGuard.'${$name} = null; '.$guard.$finish, false, 'string $text, ?string $selection, string $name'],
    'extract locals' => [$decode.$rootGuard.'extract([]); '.$guard.$finish, false],
    'parse str locals' => [$decode.$rootGuard.'parse_str("data=changed", $parsed); '.$guard.$finish, false],
    'opaque callback' => [$decode.$rootGuard.'mutateGlobal(); '.$guard.$finish, false],
    'opaque callback after guard' => [$decode.$rootGuard.$guard.'mutateGlobal(); '.$finish, false],
    'preceding argument mutation' => [$decode.$rootGuard.$guard.'return receivePair(mutateGlobal(), '.$ternary.');', false],
    'dynamic callee mutation' => [$decode.$rootGuard.$guard.'return (resolveConsumer())('.$ternary.');', false],
    'static autoload callee' => [$decode.$rootGuard.$guard.'return DeferredConsumer::receive('.$ternary.');', false],
    'named argument order' => [$decode.$rootGuard.$guard.'return receivePair(second: mutateGlobal(), first: '.$ternary.');', false],
    'by reference consumer' => [$decode.$rootGuard.$guard.'return receiveReference('.$ternary.');', false],
    'unrelated mixed access' => [$decode.$rootGuard.$guard.'receiveValue($opaque["value"]); '.$finish, false, 'string $text, ?string $selection, mixed $opaque'],
    'nullable array origin' => ['$data = $input; '.$rootGuard.$guard.$finish, false, 'array|null $input, ?string $selection'],
    'object array access origin' => ['$data = $input; '.$guard.$finish, false, 'ArrayAccess $input, ?string $selection'],
    'nonassociative decode' => [str_replace('json_decode($text, true)', 'json_decode($text, false)', $normal), false],
    'dynamic associative option' => [str_replace('json_decode($text, true)', 'json_decode($text, $associative)', $normal), false, 'string $text, ?string $selection, bool $associative'],
    'unknown key domain' => [str_replace('if ($selection !== null && !is_string($selection)) { throw new RuntimeException("Expected a string key."); } ', '', $normal), false, 'string $text, mixed $selection'],
    'documented root override' => [$decode.'/** @var object $data */ '.$rootGuard.$guard.$finish, false],
    'documented key override' => [$decode.$rootGuard.'/** @var int $selection */ '.$guard.$finish, false],
];
$source = <<<'PHP'
<?php
declare(strict_types=1);
function receiveValue(mixed $value): mixed { return $value; }
function receivePair(mixed $first, mixed $second): mixed { return $second; }
function receiveReference(mixed &$value): mixed { return $value; }
function receiveAfterCapture(mixed $value): mixed { $GLOBALS['data'] = null; return $value; }
function mutateGlobal(): null { $GLOBALS['data'] = null; return null; }
function resolveConsumer(): Closure { mutateGlobal(); return static fn (mixed $value): mixed => $value; }

PHP;
$ranges = [];
foreach ($cases as $label => [$body, $positive]) {
    $start = strlen($source);
    $source .= 'function selection'.count($ranges).'('.($cases[$label][2] ?? 'string $text, mixed $selection').'): mixed { '.$body." }\n";
    $ranges[$label] = [$start, strlen($source), $positive];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/shadows.php', <<<'PHP'
<?php
namespace ArrayShadow;
function json_decode(string $text, bool $associative): mixed { return new \ArrayObject; }
function is_array(mixed $value): bool { return true; }
function array_key_exists(string $key, mixed $value): bool { return true; }
function selected(string $text, ?string $selection): mixed {
    $data = json_decode($text, true);
    if (!is_array($data)) { throw new \RuntimeException; }
    if ($selection !== null && (!isset($data['sections']) || !is_array($data['sections']) || !array_key_exists($selection, $data['sections']))) { throw new \RuntimeException; }
    return $selection === null ? ($data['entries'] ?? null) : $data['sections'][$selection];
}
PHP);
file_put_contents($workspace.'/flag-shadow.php', '<?php namespace ArrayFlags; const JSON_THROW_ON_ERROR = 0; function flagged(string $text, mixed $selection): mixed { '.str_replace(['json_decode($text, true)', $finish], ['json_decode($text, true, 512, JSON_THROW_ON_ERROR)', 'return '.$ternary.';'], $normal).' }');
file_put_contents($workspace.'/top.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
function receiveTop(mixed $value): mixed { $GLOBALS['data'] = null; return $value; }
try {
    $text = file_get_contents('/declaration-only-input.json');
    if ($text === false) { throw new RuntimeException('Unreadable declaration input.'); }
    $data = json_decode($text, true);
    $options = getopt('', ['section::']);
    $selection = $options['section'] ?? null;
    if (!is_array($data)) { throw new RuntimeException('Expected a native array.'); }
    if ($selection !== null && !is_string($selection)) { throw new RuntimeException('Expected a string key.'); }
    if ($selection !== null && (!isset($data['sections']) || !is_array($data['sections']) || !array_key_exists($selection, $data['sections']))) { throw new RuntimeException('Expected a section.'); }
    $chosen = receiveTop($selection === null ? ($data['entries'] ?? null) : $data['sections'][$selection]);
} catch (RuntimeException) {}
PHP);
$payload = <<<'PHP'
<?php
declare(strict_types=1);
class PayloadReader implements ArrayAccess {
    private int $reads = 0;
    public function offsetExists(mixed $offset): bool { return true; }
    public function offsetGet(mixed $offset): mixed { return ++$this->reads <= 2 ? ['red' => ['original']] : 17; }
    public function offsetSet(mixed $offset, mixed $value): void {}
    public function offsetUnset(mixed $offset): void {}
}
__PREFIX__
$text = '{"sections":{"red":["original"]}}';
$data = json_decode($text, true);
if (!is_array($data)) { throw new RuntimeException; }
__MUTATION__
$selection = 'red';
if ($selection !== null && !is_string($selection)) { throw new RuntimeException; }
if ($selection !== null && (!isset($data['sections']) || !is_array($data['sections']) || !array_key_exists($selection, $data['sections']))) { throw new RuntimeException; }
return $selection === null ? ($data['entries'] ?? null) : $data['sections'][$selection];
PHP;
file_put_contents($workspace.'/payload-relay.php', str_replace(['PayloadReader', '__PREFIX__', '__MUTATION__'], ['RelayReader', '$relay =& $data; $opaque = new RelayReader;', '$relay = ["item" => $opaque]; $relay = $data["item"];'], $payload));
file_put_contents($workspace.'/payload-destructor.php', str_replace(['PayloadReader', '__PREFIX__', '__MUTATION__'], ['DestructorReader', 'class OptionDestructor { public function __destruct() { $GLOBALS["data"] = new DestructorReader; } } $options = new OptionDestructor;', '$options = getopt("", ["section:"]);'], $payload));
file_put_contents($workspace.'/guard.php', '<?php function receiveGuard(mixed $value): mixed { return $value; } function guardedProof(string $text, mixed $selection): mixed { '.str_replace('receiveValue(', 'receiveGuard(', $normal).' }');
file_put_contents($workspace.'/guard-worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/array-context', 'Conditional array context', 'Exact context and metadata controls');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ConditionalArrayGuards($this->root);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $filter = new \Ichinya\Laramago\Analyzer\ConditionalArrayGuardIssueFilter($index);
        $registry->registerIssueFilterHook(new class($filter, $this->root) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            public function __construct(private $filter, private string $root) {}
            public function getCodes(): array { return $this->filter->getCodes(); }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                $result = $this->filter->filterIssue($context);
                if ($result !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { return $result; }
                $checks = 0;
                foreach (['span-start', 'span-end', 'foreign', 'duplicate', 'secondary', 'kind', 'primary-message', 'code', 'message', 'context-source', 'context-file'] as $variant) {
                    $annotations = $context->issue->annotations;
                    $original = $annotations[0];
                    $annotations[0] = new \Mago\Sdk\Reporting\Annotation(
                        $variant === 'kind' ? \Mago\Sdk\Reporting\AnnotationKind::Secondary : $original->kind,
                        new \Mago\Sdk\Span($original->span->start + ($variant === 'span-start' ? 1 : 0), $original->span->end + ($variant === 'span-end' ? 1 : 0)),
                        $variant === 'primary-message' ? 'Unknown array annotation.' : $original->message,
                        $variant === 'foreign' ? 'other.php' : $original->file);
                    if ($variant === 'duplicate') { $annotations[] = $original; }
                    if ($variant === 'secondary') { $annotations[] = new \Mago\Sdk\Reporting\Annotation(\Mago\Sdk\Reporting\AnnotationKind::Secondary, $original->span, 'Additional annotation.'); }
                    $issue = new \Mago\Sdk\Reporting\ReportedIssue($context->issue->level,
                        $variant === 'code' ? 'invalid-argument' : $context->issue->code,
                        $variant === 'message' ? 'Unknown array issue.' : $context->issue->message,
                        $context->issue->notes, $context->issue->help, $context->issue->link, $annotations, $context->issue->edits);
                    $changed = new \Mago\Sdk\Analyzer\IssueFilterContext($context->phpVersion, $context->codebase, $context->types, $context->cancellation,
                        $variant === 'context-file' ? 'other.php' : $context->file,
                        $variant === 'context-source' ? str_replace('sections', 'branches', $context->contents) : $context->contents, $issue);
                    if ($this->filter->filterIssue($changed) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Array context accepted '.$variant); }
                    $checks++;
                }
                $bytes = file_get_contents($this->root.'/guard.php');
                file_put_contents($this->root.'/guard.php', str_replace('sections', 'branches', $bytes));
                try {
                    if ($this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Array context accepted changed disk bytes.'); }
                    $checks++;
                } finally { file_put_contents($this->root.'/guard.php', $bytes); }
                $targets = [
                    'caller' => $context->codebase->getFunction('guardedProof'),
                    'consumer' => $context->codebase->getFunction('receiveGuard'),
                    'native JSON' => $context->codebase->getFunction('json_decode'),
                    'native array guard' => $context->codebase->getFunction('is_array'),
                    'native string guard' => $context->codebase->getFunction('is_string'),
                    'native key guard' => $context->codebase->getFunction('array_key_exists'),
                ];
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                foreach ($targets as $role => $metadata) {
                    $variants = in_array($role, ['caller', 'consumer'], true)
                        ? ['file', 'identifier', 'name-span', 'body-span', 'reference', 'parameter-name', 'parameter-reference', 'parameter-variadic', 'parameter-span']
                        : ['builtin', 'reference', 'parameter-reference'];
                    foreach ($variants as $variant) {
                        $values = get_object_vars($metadata);
                        if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('other.php', $metadata->location->span); }
                        if ($variant === 'identifier') { $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($metadata->identifier->kind, 'other'); }
                        if ($variant === 'name-span') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation($metadata->nameLocation->file, new \Mago\Sdk\Span($metadata->nameLocation->span->start + 1, $metadata->nameLocation->span->end)); }
                        if ($variant === 'body-span') { $values['location'] = new \Mago\Sdk\SourceLocation($metadata->location->file, new \Mago\Sdk\Span($metadata->location->span->start, $metadata->location->span->end - 1)); }
                        if ($variant === 'reference') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                        if ($variant === 'builtin') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                        if (str_starts_with($variant, 'parameter-')) {
                            $parameter = get_object_vars($metadata->parameters[0]);
                            if ($variant === 'parameter-name') { $parameter['name'] = '$other'; }
                            if ($variant === 'parameter-reference') { $parameter['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter['flags']->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                            if ($variant === 'parameter-variadic') { $parameter['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter['flags']->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC); }
                            if ($variant === 'parameter-span') { $parameter['nameLocation'] = new \Mago\Sdk\SourceLocation($parameter['nameLocation']->file, new \Mago\Sdk\Span($parameter['nameLocation']->span->start + 1, $parameter['nameLocation']->span->end)); }
                            $values['parameters'][0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$parameter);
                        }
                        $class = $metadata::class;
                        $changed = new $class(...$values);
                        $replaced = 0;
                        foreach ($snapshot as $operation => $entries) {
                            foreach ($entries as $key => $entry) {
                                if ($entry === $metadata) { $cache->values[$operation][$key] = $changed; $replaced++; }
                            }
                        }
                        try {
                            if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Array metadata accepted '.$role.' '.$variant); }
                            $checks++;
                        } finally { $cache->values = $snapshot; }
                    }
                }
                if ($this->filter->filterIssue($context) !== $result) { throw new \RuntimeException('Array context controls mutated a valid proof.'); }
                file_put_contents($this->root.'/context-checks.log', $checks."\n", FILE_APPEND);
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/array-context', name: 'Array context', version: '1', analyzerPlugins: [$plugin])))->run();
PHP);

$analyze = static function (string $mode, array $paths = ['cases.php', 'shadows.php', 'flag-shadow.php', 'top.php', 'payload-relay.php', 'payload-destructor.php'], int $workers = 1) use ($workspace, $package): array {
    $configuration = $mode === 'external' ? $workspace.' external configuration' : $workspace;
    if (!is_dir($configuration)) { mkdir($configuration); }
    $host = $mode === 'native' ? new stdClass : ['fixture' => ['command' => $mode === 'integrated'
        ? [PHP_BINARY, '-d', 'opcache.enable_cli=0', $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]
        : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/'.($mode === 'guards' ? 'guard-worker.php' : 'worker.php'), $package.'/vendor/autoload.php', $workspace, $mode === 'external' ? 'proven' : $mode], 'workers' => $workers]];
    file_put_contents($configuration.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $paths], 'extension-hosts' => $host,
    ], JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $name = $mode.'-'.$workers;
    $process = proc_open([...$command, '--workspace', $workspace, '--config', $configuration.'/mago.json', 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$name.'.json', 'w'], 2 => ['file', $workspace.'/'.$name.'.log', 'w'],
    ], $pipes, $configuration);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$name.'.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request|hook .* failed|parse error|worker .* exited/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    return json_decode(file_get_contents($workspace.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$signature = static function (array $issues): array {
    $result = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] !== 'Primary') { continue; }
            $result[] = [$issue['code'], $issue['message'], $annotation['span']['file_id']['name'], $annotation['span']['start']['offset'], $annotation['span']['end']['offset']];
            break;
        }
    }
    sort($result);
    return $result;
};
$group = static function (array $issues) use ($ranges, $signature): array {
    $grouped = [];
    foreach ($signature($issues) as $issue) {
        if ($issue[2] !== 'cases.php') { continue; }
        foreach ($ranges as $label => [$start, $end]) {
            if ($issue[3] >= $start && $issue[3] < $end) { $grouped[$label][] = $issue; break; }
        }
    }
    return $grouped;
};
$native = $analyze('native');
$control = $analyze('control');
$nativeFlowCases = ['missing existence condition', 'different key'];
if (in_array('--baseline', $argv, true)) {
    foreach (['native' => $native, 'control' => $control] as $mode => $reports) {
        $grouped = $group($reports);
        foreach ($cases as $label => [, $positive]) {
            if (($grouped[$label] ?? []) === []) { echo 'Empty '.$mode.' baseline: '.$label.".\n"; }
        }
    }
    echo 'Conditional array baseline: native='.count($native).', control='.count($control).', workspace='.$workspace.".\n";
    exit(0);
}
$standalone = $analyze('standalone');
$proven = $analyze('proven');
$corrected = 0;
foreach ([[$native, $standalone], [$control, $proven]] as [$beforeReports, $afterReports]) {
    $before = $group($beforeReports);
    $after = $group($afterReports);
    foreach ($cases as $label => [, $positive]) {
        $original = $before[$label] ?? [];
        $actual = $after[$label] ?? [];
        if ($original === []) {
            if (!$positive && in_array($label, $nativeFlowCases, true) && $actual === []) { continue; }
            throw new RuntimeException('Empty baseline: '.$label.' '.$workspace);
        }
        if (!$positive) {
            if ($actual !== $original) { throw new RuntimeException('Unsafe conditional array correction: '.$label.' '.json_encode([$original, $actual]).' '.$workspace); }
            continue;
        }
        $removed = array_values(array_filter($original, static fn (array $issue): bool => $issue[0] === 'mixed-array-access'));
        $expected = array_values(array_filter($original, static fn (array $issue): bool => $issue[0] !== 'mixed-array-access'));
        if (count($removed) !== 1 || $actual !== $expected) { throw new RuntimeException('Missing exact correction: '.$label.' '.json_encode([$original, $actual]).' '.$workspace); }
        $corrected++;
    }
    $beforeShadows = array_values(array_filter($signature($beforeReports), static fn (array $issue): bool => $issue[2] === 'shadows.php'));
    $afterShadows = array_values(array_filter($signature($afterReports), static fn (array $issue): bool => $issue[2] === 'shadows.php'));
    if ($beforeShadows !== $afterShadows) { throw new RuntimeException('Native helper shadow correction changed diagnostics. '.$workspace); }
    $beforeTop = array_values(array_filter($signature($beforeReports), static fn (array $issue): bool => $issue[2] === 'top.php'));
    $afterTop = array_values(array_filter($signature($afterReports), static fn (array $issue): bool => $issue[2] === 'top.php'));
    $topAccess = array_values(array_filter($beforeTop, static fn (array $issue): bool => $issue[0] === 'mixed-array-access'));
    $expectedTop = array_values(array_filter($beforeTop, static fn (array $issue): bool => $issue[0] !== 'mixed-array-access'));
    if (count($topAccess) !== 1 || $afterTop !== $expectedTop) { throw new RuntimeException('Missing top-level source proof: '.json_encode([$beforeTop, $afterTop]).' '.$workspace); }
    $corrected++;
    foreach (['payload-relay.php', 'payload-destructor.php', 'flag-shadow.php'] as $file) {
        $original = array_values(array_filter($signature($beforeReports), static fn (array $issue): bool => $issue[2] === $file));
        $actual = array_values(array_filter($signature($afterReports), static fn (array $issue): bool => $issue[2] === $file));
        if ($original === [] || !in_array('mixed-array-access', array_column($original, 0), true) || $original !== $actual) { throw new RuntimeException('Unsafe payload correction: '.$file.' '.json_encode([$original, $actual]).' '.$workspace); }
    }
}
if ($signature($analyze('external')) !== $signature($proven)) { throw new RuntimeException('External configuration lost source-root proof. '.$workspace); }
$guardReports = $analyze('guards', ['guard.php']);
$contextChecks = file_exists($workspace.'/context-checks.log') ? array_sum(array_map('intval', file($workspace.'/context-checks.log', FILE_IGNORE_NEW_LINES))) : 0;
if ($contextChecks !== 42 || in_array('mixed-array-access', array_column($guardReports, 'code'), true)) { throw new RuntimeException('Missing real-Mago context controls: '.$contextChecks.' '.$workspace); }
if (in_array('--integrated', $argv, true)) {
    foreach ([1, 3] as $workers) {
        if ($signature($analyze('integrated', workers: $workers)) !== $signature($proven)) { throw new RuntimeException('Integrated conditional array diagnostics differ: '.$workers.' workers. '.$workspace); }
    }
}
if (file_exists($workspace.'/executed')) { throw new RuntimeException('Analyzed bootstrap executed.'); }
file_put_contents($workspace.'/runtime-control.php', <<<'PHP'
<?php
declare(strict_types=1);
$warnings = [];
set_error_handler(static function (int $level, string $message) use (&$warnings): bool { $warnings[] = $message; return true; });
$result = require $argv[1];
restore_error_handler();
echo json_encode(['result' => $result, 'root' => get_debug_type($data), 'warnings' => $warnings], JSON_THROW_ON_ERROR);
PHP);
foreach (['payload-relay.php', 'payload-destructor.php'] as $file) {
    // These two standalone invented controls do not require any package or application.
    $process = proc_open([PHP_BINARY, $workspace.'/runtime-control.php', $workspace.'/'.$file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workspace);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start standalone payload control.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    $runtime = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    if ($exit !== 0 || $runtime['result'] !== null || $runtime['warnings'] === [] || !str_contains(implode(' ', $runtime['warnings']), 'array offset')) { throw new RuntimeException('Payload runtime control did not expose its unsafe access: '.$file.' '.$output.' '.$stderr); }
}

require $package.'/vendor/autoload.php';
$scanChecks = (static function (string $root, string $source): int {
    mkdir($root);
    file_put_contents($root.'/guard.php', $source);
    $version = PHPVersion::fromParts(8, 5);
    $cancellation = new class implements CancellationTokenInterface {
        public function isCancelled(): bool { return false; }
        public function throwIfCancelled(): void {}
        public function subscribe(Closure $callback): int { return 0; }
        public function unsubscribe(int $subscription): void {}
    };
    $index = new ConditionalArrayGuards($root);
    $file = static fn (string $path, string $contents): SourceFile => new SourceFile(
        $version, $path, $contents, [], new NodeStore([], '', 0),
        new ResolvedNameStore('', '', '', 0), new TriviaStore('', 0), null,
    );
    $scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $cancellation): void {
        $index->scan(new CodebaseScanContext($version, $cancellation, $files, $first, $last));
    };
    $checks = 0;
    $expect = static function (string $label, bool $actual) use (&$checks): void {
        if (!$actual) { throw new RuntimeException('Conditional array scan failed: '.$label); }
        $checks++;
    };
    $host = $file('guard.php', $source);
    $expect('absent scan', $index->proofs('guard.php', $source) === []);
    $scan([$host], last: false);
    $expect('incomplete scan', $index->proofs('guard.php', $source) === []);
    $scan([], first: false);
    $proofs = $index->proofs('guard.php', $source);
    $expect('completed positive proof', count($proofs) === 1);
    $proof = $proofs[0];
    $expect('relative root current bytes', ConditionalArrayGuards::current($proof));
    $expect('wrong source file', $index->proofs('other.php', $source) === []);
    $expect('wrong context bytes', $index->proofs('guard.php', $source."\n// changed source") === []);
    $changed = str_replace('sections', 'branches', $source);
    $expect('equal length source control', $changed !== $source && strlen($changed) === strlen($source));
    file_put_contents($root.'/guard.php', $changed);
    try { $expect('changed current disk bytes', !ConditionalArrayGuards::current($proof)); }
    finally { file_put_contents($root.'/guard.php', $source); }
    $expect('restored current bytes', ConditionalArrayGuards::current($proof));
    $scan([$file('guard.php', $changed)]);
    $overlay = $index->proofs('guard.php', $changed);
    $expect('overlay independently indexed', count($overlay) === 1);
    $expect('overlay differs from disk', !ConditionalArrayGuards::current($overlay[0]));
    $scan([$host, $host]);
    $expect('duplicate path veto', $index->proofs('guard.php', $source) === []);
    $scan([$host, $file('broken.php', '<?php function broken( {')]);
    $expect('failed parse veto', $index->proofs('guard.php', $source) === []);
    $scan([$host, $file('oversized.php', str_repeat(' ', 2_000_001))]);
    $expect('oversized source veto', $index->proofs('guard.php', $source) === []);
    $scan([$host]);
    $expect('first batch resets veto', count($index->proofs('guard.php', $source)) === 1);
    $access = $proof['access'];
    $prefix = '<?php ';
    $collision = $prefix.str_repeat(' ', $access->getStartFilePos() - strlen($prefix)).'$other["sections"][$selection];';
    $scan([$host, $file('unrelated.php', $collision)]);
    $expect('same-span foreign access has no proof', $index->proofs('unrelated.php', $collision) === []);
    $expect('same-span foreign access keeps original proof', count($index->proofs('guard.php', $source)) === 1);
    $index->initialize(new InitializationContext($version, $cancellation));
    $expect('initialization clears state', $index->proofs('guard.php', $source) === []);
    $scan([$host]);
    $expect('completed after initialization', count($index->proofs('guard.php', $source)) === 1);
    return $checks;
})($workspace.'/scan root', file_get_contents($workspace.'/guard.php'));
echo 'Conditional array guard checks passed: '.count($cases).' source cases, '.$corrected.' native/control corrections, '.$scanChecks.' scan and '.$contextChecks.' issue/metadata controls, 2 unsafe runtime controls'.(in_array('--integrated', $argv, true) ? ', one and three integrated workers' : '').".\n";
