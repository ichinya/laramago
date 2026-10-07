<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\ScriptMixedAssignmentFilter;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\TypeComparator;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Reporting\Annotation;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\ReportedIssue;
use Mago\Sdk\Reporting\TextEdit;
use Mago\Sdk\Span;

require __DIR__.'/../vendor/autoload.php';

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago script includes '.bin2hex(random_bytes(8));
mkdir($workspace.'/cases', recursive: true);
mkdir($workspace.'/bootstrap', recursive: true);
mkdir($workspace.'/database/migrations', recursive: true);
mkdir($workspace.'/packages/composer', recursive: true);
$trap = '<?php file_put_contents(__DIR__."/executed", "executed"); throw new RuntimeException("Analyzed body executed.");';
file_put_contents($workspace.'/bootstrap/app.php', $trap);
file_put_contents($workspace.'/bootstrap.php', $trap);
file_put_contents($workspace.'/database/migrations/001_trap.php', $trap);
file_put_contents($workspace.'/packages/composer/trap.php', $trap);
file_put_contents($workspace.'/composer.json', json_encode([
    'config' => ['vendor-dir' => 'packages'],
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/packages/composer/installed.json', '{"packages":[]}');
file_put_contents($workspace.'/packages/composer/autoload_files.php', '<?php return ["trap" => __DIR__."/trap.php"];');
file_put_contents($workspace.'/cases/contracts.php', <<<'PHP'
<?php
function consumeString(string $value): void {}
function rawScriptInput(): mixed { return null; }
class ScriptKernel { public function boot(): void {} }
interface ScriptApplication { public function make(): ScriptKernel; }
class ScriptBox { public string $name; public static string $shared; }
PHP);

/** @var array<string, array{source: string, removed: int, warning?: bool, codes?: list<string>, error?: bool}> $cases */
$cases = [
    'require' => ['source' => '$value = require "../bootstrap/app.php";', 'removed' => 1],
    'require once' => ['source' => '$value = require_once "../bootstrap/app.php";', 'removed' => 1],
    'include' => ['source' => '$value = include "../bootstrap/app.php";', 'removed' => 1],
    'include once' => ['source' => '$value = include_once "../bootstrap/app.php";', 'removed' => 1],
    'parenthesized include' => ['source' => '$value = (include "../bootstrap/app.php");', 'removed' => 1],
    'variable include operand' => ['source' => '$path = "../bootstrap/app.php"; $value = require $path;', 'removed' => 1],
    'namespace script' => ['source' => 'namespace ScriptExample; $value = require "../bootstrap/app.php";', 'removed' => 1],
    'braced namespace script' => ['source' => 'namespace ScriptBlock { $value = require "../bootstrap/app.php"; }', 'removed' => 1],
    'native object guard' => [
        'source' => '$value = require "../bootstrap/app.php"; if (! $value instanceof ScriptApplication) { throw new RuntimeException("Expected application."); } $value->make()->boot();',
        'removed' => 1,
    ],
    'unsafe typed argument' => ['source' => '$value = require "../bootstrap/app.php"; consumeString($value);', 'removed' => 1, 'codes' => ['mixed-argument'], 'error' => true],
    'unsafe method access' => ['source' => '$value = require "../bootstrap/app.php"; $value->run();', 'removed' => 1, 'codes' => ['mixed-method-access'], 'error' => true],
    'unsafe property access' => ['source' => '$value = require "../bootstrap/app.php"; $value->name;', 'removed' => 1, 'codes' => ['mixed-property-access'], 'error' => true],
    'unsafe array access' => ['source' => '$value = require "../bootstrap/app.php"; $value["name"];', 'removed' => 1, 'codes' => ['mixed-array-access'], 'error' => true],
    'unsafe numeric argument' => ['source' => '$value = require "../bootstrap/app.php"; abs($value);', 'removed' => 1, 'codes' => ['mixed-argument'], 'error' => true],
    'ordinary function result' => ['source' => '$value = rawScriptInput();', 'removed' => 0, 'warning' => true],
    'conditional assignment' => ['source' => 'if ($value = require "../bootstrap/app.php") {}', 'removed' => 0, 'warning' => true],
    'conditional statement' => ['source' => 'if (rawScriptInput()) { $value = require "../bootstrap/app.php"; }', 'removed' => 0, 'warning' => true],
    'chained assignment' => ['source' => '$outer = $value = require "../bootstrap/app.php";', 'removed' => 0, 'warning' => true],
    'reference assignment' => ['source' => '$value =& $input; $value = require "../bootstrap/app.php";', 'removed' => 0, 'warning' => true],
    'reference captured later' => ['source' => '$value = require "../bootstrap/app.php"; $callback = function () use (&$value): void {};', 'removed' => 0, 'warning' => true],
    'reference foreach binding' => ['source' => '$items = []; foreach ($items as &$value) {} $value = require "../bootstrap/app.php";', 'removed' => 0, 'warning' => true],
    'explicit global binding' => ['source' => 'global $value; $value = require "../bootstrap/app.php";', 'removed' => 0, 'warning' => true],
    'explicit static binding' => ['source' => 'static $value; $value = require "../bootstrap/app.php";', 'removed' => 0, 'warning' => true],
    'function reference binding' => ['source' => 'function scopedScript(mixed &$value): void { $value = require "../bootstrap/app.php"; }', 'removed' => 0, 'warning' => true],
    'strong variable annotation' => ['source' => '/** @var string $value */ $value = require "../bootstrap/app.php"; $value->run();', 'removed' => 0, 'error' => true],
    'malformed variable annotation' => ['source' => '/** @var broken< $value */ $value = require "../bootstrap/app.php";', 'removed' => 0],
    'extension variable annotation' => ['source' => '/** @phpstan-var string $value */ $value = require "../bootstrap/app.php"; $value->run();', 'removed' => 0, 'error' => true],
    'superglobal binding' => ['source' => '$_ENV = require "../bootstrap/app.php"; consumeString($_ENV);', 'removed' => 0, 'error' => true],
    'globals binding' => ['source' => '$GLOBALS["value"] = require "../bootstrap/app.php"; consumeString($GLOBALS["value"]);', 'removed' => 0, 'error' => true],
    'property binding' => ['source' => '$box = new ScriptBox; $box->name = require "../bootstrap/app.php";', 'removed' => 0, 'codes' => ['mixed-property-type-coercion']],
    'static property binding' => ['source' => 'ScriptBox::$shared = require "../bootstrap/app.php";', 'removed' => 0, 'codes' => ['mixed-property-type-coercion']],
    'array element binding' => ['source' => '$values = []; $values["field"] = require "../bootstrap/app.php"; consumeString($values["field"]);', 'removed' => 0, 'error' => true],
    'destructuring binding' => ['source' => '[$first, $second] = require "../bootstrap/app.php";', 'removed' => 0],
    'dynamic variable binding' => ['source' => '$name = "value"; $$name = require "../bootstrap/app.php";', 'removed' => 0],
    'dynamic binding elsewhere' => ['source' => '$name = "other"; $$name = null; $value = require "../bootstrap/app.php";', 'removed' => 0, 'warning' => true],
    'extract binding' => ['source' => 'extract([]); $value = require "../bootstrap/app.php";', 'removed' => 0, 'warning' => true],
    'imported extract binding' => ['source' => 'use function extract as injectValues; injectValues([]); $value = require "../bootstrap/app.php";', 'removed' => 0, 'warning' => true],
    'parse string binding' => ['source' => 'parse_str("value=text", $output); $value = require "../bootstrap/app.php";', 'removed' => 0, 'warning' => true],
    'eval binding' => ['source' => 'eval("\$other = null;"); $value = require "../bootstrap/app.php";', 'removed' => 0, 'warning' => true],
];
$files = [];
foreach ($cases as $name => $case) {
    $file = 'cases/case'.count($files).'.php';
    file_put_contents($workspace.'/'.$file, "<?php\n".$case['source']."\n");
    $files[$file] = $name;
}

file_put_contents($workspace.'/worker.php', '<?php require '.var_export($package.'/vendor/autoload.php', true).';'
    .'(new Mago\\Sdk\\Worker(new Mago\\Sdk\\Extension(identifier: "script-policy", name: "Script policy", version: "1",'
    .' analyzerPlugins: [new Ichinya\\Laramago\\Analyzer\\ScriptMixedAssignmentPlugin])))->run();');
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (string $label, ?string $worker = null, int $workers = 1, ?string $single = null) use ($package, $workspace, $command): array {
    $config = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => [$single ?? 'cases'], 'includes' => $single === null ? [] : ['cases/contracts.php']]];
    if ($worker !== null) {
        $config['extension-hosts'] = ['script-policy' => [
            'command' => [PHP_BINARY, $worker, $package.'/vendor/autoload.php', $workspace], 'workers' => $workers,
        ]];
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$label.'.json', 'w'], 2 => ['file', $workspace.'/'.$label.'.log', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago; inspect '.$workspace); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$label.'.log');
    file_put_contents($workspace.'/'.$label.'.process.json', json_encode(['exit' => $exit, 'processClosed' => true], JSON_THROW_ON_ERROR));
    if (! in_array($exit, [0, 1], true) || preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|invalid[^\r\n]*extension[^\r\n]*frame|hook[^\r\n]*failed|fatal|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i', $stderr) === 1) {
        throw new RuntimeException('Mago failed in '.$label.'; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! isset($report['issues']) || ! is_array($report['issues'])) { throw new RuntimeException('Invalid report '.$label); }
    return $report['issues'];
};
$signatures = static function (array $issues): array {
    $values = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues);
    sort($values);
    return $values;
};
$primaryFile = static function (array $issue): string {
    foreach ($issue['annotations'] as $annotation) {
        if ($annotation['kind'] === 'Primary') { return str_replace('\\', '/', $annotation['span']['file_id']['name']); }
    }
    throw new RuntimeException('Missing Primary in native report.');
};
$native = $run('native');
$isolated = $run('isolated', $workspace.'/worker.php');
$expected = $native;
$removed = 0;
$certifiedScriptRemovals = [];
foreach ($files as $file => $name) {
    $case = $cases[$name];
    $before = array_values(array_filter($native, static fn (array $issue): bool => $primaryFile($issue) === $file));
    $after = array_values(array_filter($isolated, static fn (array $issue): bool => $primaryFile($issue) === $file));
    if ($before === []) { throw new RuntimeException('Vacuous native control '.$name.'; inspect '.$workspace); }
    $codes = array_column($after, 'code');
    if (($case['warning'] ?? false) && ! in_array('mixed-assignment', $codes, true)
        || array_diff($case['codes'] ?? [], $codes) !== []
        || ($case['error'] ?? false) && ! in_array('Error', array_column($after, 'level'), true)) {
        throw new RuntimeException('Required native diagnostic missing: '.$name.'; inspect '.$workspace);
    }
    $matches = array_keys(array_filter($expected, static fn (array $issue): bool => $primaryFile($issue) === $file && $issue['code'] === 'mixed-assignment'));
    if ($case['removed'] > count($matches)) { throw new RuntimeException('Missing native advisory '.$name); }
    foreach (array_slice($matches, 0, $case['removed']) as $index) {
        $certifiedScriptRemovals[] = $expected[$index];
        unset($expected[$index]);
        $removed++;
    }
    $perCase = array_values(array_filter($expected, static fn (array $issue): bool => $primaryFile($issue) === $file));
    if ($signatures($after) !== $signatures($perCase)) { throw new RuntimeException('Exact diagnostic mismatch '.$name.'; inspect '.$workspace); }
    echo 'PASS: '.$name."\n";
}
if ($signatures($isolated) !== $signatures(array_values($expected))) { throw new RuntimeException('Unclassified diagnostic changed; inspect '.$workspace); }
echo 'PASS: '.count($cases).' genuine cases, '.$removed." exact storage advisories removed; all other report fields preserved\n";
if (in_array('--integrated', $argv, true)) {
    $currentWorker = file_get_contents($package.'/bin/laramago-worker.php');
    $anchor = 'new ScriptMixedAssignmentPlugin,';
    if (substr_count($currentWorker, $anchor) !== 1) { throw new RuntimeException('Expected one Script policy registration.'); }
    $controlWorker = str_replace($anchor, '', $currentWorker);
    file_put_contents($workspace.'/full-control-worker.php', $controlWorker);
    $otherPolicies = $run('integrated-control', $workspace.'/full-control-worker.php');
    file_put_contents($workspace.'/ordinary-worker.php', '<?php require '.var_export($package.'/vendor/autoload.php', true).';'
        .'(new Mago\\Sdk\\Worker(new Mago\\Sdk\\Extension(identifier: "ordinary-policy-control", name: "Ordinary policy control", version: "1",'
        .' analyzerPlugins: [new Ichinya\\Laramago\\Analyzer\\OrdinaryMixedAssignmentPlugin])))->run();');
    $ordinary = $run('ordinary-control', $workspace.'/ordinary-worker.php');
    if ($signatures($otherPolicies) !== $signatures($ordinary)) {
        throw new RuntimeException('Other integrated policies differ from the independently measured Ordinary policy; inspect '.$workspace);
    }
    $errors = static fn (array $issues): array => array_values(array_filter($issues, static fn (array $issue): bool => $issue['level'] === 'Error'));
    if ($signatures($errors($native)) !== $signatures($errors($otherPolicies))) {
        throw new RuntimeException('Integrated baseline changed unsafe-use Errors; inspect '.$workspace);
    }
    $integratedExpected = $otherPolicies;
    $certifiedSignatures = $signatures($certifiedScriptRemovals);
    $integratedExpected = array_values(array_filter($integratedExpected,
        static fn (array $issue): bool => ! in_array(json_encode($issue, JSON_THROW_ON_ERROR), $certifiedSignatures, true)));
    foreach ([1, 3] as $workers) {
        $integrated = $run('integrated'.$workers, $package.'/bin/laramago-worker.php', $workers);
        if ($signatures($integrated) !== $signatures($integratedExpected)) { throw new RuntimeException('Integrated signature mismatch '.$workers.'; inspect '.$workspace); }
    }
    file_put_contents($workspace.'/integrated-policy-receipt.json', json_encode([
        'currentWorkerSha256' => hash('sha256', $currentWorker), 'controlWorkerSha256' => hash('sha256', $controlWorker),
        'exactSingleRegistrationRemoved' => true, 'otherPoliciesEqualGenuineOrdinaryControl' => true,
        'isolatedScriptRemovals' => count($certifiedScriptRemovals), 'allNativeErrorsPreserved' => true,
        'oneThreeWholeSignaturesMatchMeasuredPolicyUnion' => true,
    ], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "PASS: isolated Script controls and integrated policy union; complete native Errors and one/three-worker signatures preserved\n";
}
$singleNative = $run('single-native', single: 'cases/case0.php');
$single = $run('single-isolated', $workspace.'/worker.php', single: 'cases/case0.php');
if (count($singleNative) !== 1 || $singleNative[0]['code'] !== 'mixed-assignment' || $single !== []) {
    throw new RuntimeException('Single-file native/adapted check failed; inspect '.$workspace);
}
echo "PASS: single-file include storage, root with spaces and custom vendor directory\n";

// These controls exercise the SDK's exact in-memory native issue envelope.
$cancel = new class implements CancellationTokenInterface {
    public bool $cancelled = false;
    public function isCancelled(): bool { return $this->cancelled; }
    public function throwIfCancelled(): void { if ($this->cancelled) { throw new RuntimeException('Cancelled.'); } }
    public function subscribe(Closure $callback): int { return 0; }
    public function unsubscribe(int $subscription): void {}
};
$codebase = (new ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
$types = (new ReflectionClass(TypeComparator::class))->newInstanceWithoutConstructor();
$filter = new ScriptMixedAssignmentFilter;
$source = '<?php $value = require "not-on-disk.php";';
$offset = strpos($source, '$value');
$issue = new ReportedIssue(Level::Warning, 'mixed-assignment', 'Assigning `mixed` type to a variable may lead to unexpected behavior.',
    ['Using `mixed` can lead to runtime errors if the variable is used in a way that assumes a specific type.'],
    'Consider using a more specific type to avoid potential issues.', null,
    [new Annotation(AnnotationKind::Primary, new Span($offset, $offset + 6), 'Assigning `mixed` type here.')], []);
$context = static fn (string $bytes, ReportedIssue $report): IssueFilterContext => new IssueFilterContext(
    PHPVersion::fromParts(8, 5), $codebase, $types, $cancel, $workspace.'/unsaved.php', $bytes, $report);
if ($filter->filterIssue($context($source, $issue)) !== IssueFilterDecision::Remove) { throw new RuntimeException('Vacuous SDK positive.'); }
$change = static fn (array $fields): ReportedIssue => new ReportedIssue(...array_replace([
    'level' => $issue->level, 'code' => $issue->code, 'message' => $issue->message, 'notes' => $issue->notes,
    'help' => $issue->help, 'link' => $issue->link, 'annotations' => $issue->annotations, 'edits' => $issue->edits,
], $fields));
$controls = [
    'Error severity' => $change(['level' => Level::Error]),
    'other code' => $change(['code' => 'mixed-argument']),
    'changed message' => $change(['message' => 'Another mixed assignment.']),
    'missing note' => $change(['notes' => []]),
    'changed note' => $change(['notes' => ['Different note.']]),
    'extra note' => $change(['notes' => [...$issue->notes, 'Extra note.']]),
    'changed help' => $change(['help' => null]),
    'foreign link' => $change(['link' => 'https://example.invalid/policy']),
    'suggested edit' => $change(['edits' => [TextEdit::replace(new Span($offset, $offset + 6), '$typed')]]),
    'extra annotation' => $change(['annotations' => [...$issue->annotations, new Annotation(AnnotationKind::Secondary, new Span($offset, $offset + 6))]]),
    'secondary annotation' => $change(['annotations' => [new Annotation(AnnotationKind::Secondary, new Span($offset, $offset + 6), 'Assigning `mixed` type here.')]]),
    'foreign annotation file' => $change(['annotations' => [new Annotation(AnnotationKind::Primary, new Span($offset, $offset + 6), 'Assigning `mixed` type here.', 'foreign.php')]]),
    'annotation text' => $change(['annotations' => [new Annotation(AnnotationKind::Primary, new Span($offset, $offset + 6), 'Different text.')]]),
    'nearby span' => $change(['annotations' => [new Annotation(AnnotationKind::Primary, new Span($offset + 1, $offset + 6), 'Assigning `mixed` type here.')]]),
    'overlong span' => $change(['annotations' => [new Annotation(AnnotationKind::Primary, new Span($offset, strlen($source) + 1), 'Assigning `mixed` type here.')]]),
    'empty span' => $change(['annotations' => [new Annotation(AnnotationKind::Primary, new Span($offset, $offset), 'Assigning `mixed` type here.')]]),
];
foreach ($controls as $name => $report) {
    if ($filter->filterIssue($context($source, $report)) !== IssueFilterDecision::Keep) { throw new RuntimeException('Issue envelope control failed: '.$name); }
}
$contents = [
    'same-path edited content' => '<?php $value = producer("not-on-disk.php");',
    'function binding' => '<?php function probe(): void { $value = require "not-on-disk.php"; }',
    'malformed syntax' => '<?php $value = require (;',
    'strong annotation' => '<?php /** @var string $value */ $value = require "not-on-disk.php";',
    'malformed annotation' => '<?php /** @var broken< */ $value = require "not-on-disk.php";',
    'dynamic binding' => '<?php $$value = require "not-on-disk.php";',
    'oversized file' => $source.str_repeat(' ', 1024 * 1024),
    'oversized node count' => $source.str_repeat('0;', 10001),
];
foreach ($contents as $name => $bytes) {
    $position = strpos($bytes, '$value');
    $report = $position === false ? $issue : $change(['annotations' => [new Annotation(AnnotationKind::Primary, new Span($position, $position + 6), 'Assigning `mixed` type here.')]]);
    if ($filter->filterIssue($context($bytes, $report)) !== IssueFilterDecision::Keep) { throw new RuntimeException('Source control failed: '.$name); }
}
$cancel->cancelled = true;
if ($filter->filterIssue($context($source, $issue)) !== IssueFilterDecision::Keep) { throw new RuntimeException('Cancellation control failed.'); }
$cancel->cancelled = false;
$filter->initialize(new InitializationContext(PHPVersion::fromParts(8, 5), $cancel));
if ($filter->filterIssue($context($source, $issue)) !== IssueFilterDecision::Remove) { throw new RuntimeException('Initialization reset control failed.'); }
echo 'PASS: '.(count($controls) + count($contents) + 3)." exact SDK envelope, in-memory source, cancellation and reset controls\n";
foreach ([$workspace.'/bootstrap/executed', $workspace.'/executed', $workspace.'/database/migrations/executed', $workspace.'/packages/composer/executed'] as $marker) {
    if (file_exists($marker)) { throw new RuntimeException('Analyzed source executed; inspect '.$workspace); }
}
if (file_exists($workspace.'/.env')) { throw new RuntimeException('Unexpected environment file.'); }
echo "PASS: included, bootstrap, migration and Composer-file bodies were not executed; no environment or database required\n";
echo 'Evidence: '.$workspace."\n";
