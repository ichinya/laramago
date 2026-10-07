<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\DefensiveArrayGuardProofs;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\Internal\Syntax\NodeStore;
use Mago\Sdk\Internal\Syntax\ResolvedNameStore;
use Mago\Sdk\Internal\Syntax\TriviaStore;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;

// Analyze invented declarations. Their bodies, bootstrap and migrations never execute.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago defensive guards '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/packages');
mkdir($workspace.'/database');
mkdir($workspace.'/database/migrations');
file_put_contents($workspace.'/composer.json', '{"config":{"vendor-dir":"packages"},"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php', '<?php file_put_contents(__DIR__."/executed", "bootstrap"); throw new RuntimeException("Bootstrap executed.");');
file_put_contents($workspace.'/database/migrations/0001_trap.php', '<?php file_put_contents(__DIR__."/../../executed", "migration"); throw new RuntimeException("Migration executed.");');
$origin = '$data = packet($input); ';
$guard = '$value = is_array($data) ? ($data["field"] ?? null) : null; ';
$throw = 'if (!is_string($value)) { throw new \\UnexpectedValueException("Expected text."); } ';
$finish = 'return $value;';
$normal = $origin.$guard.$throw.$finish;
$cases = [
    'plain protective guard' => [$normal, true],
    'qualified predicates' => [str_replace(['is_array(', 'is_string('], ['\\is_array(', '\\is_string('], $normal), true],
    'integer key' => [str_replace('["field"]', '[0]', $normal), true],
    'unrelated intervening call' => [$origin.'$other = unrelated(); '.$guard.$throw.$finish, true],
    'parentheses' => [str_replace('is_array($data)', 'is_array(($data))', $normal), true],
    'invalid producer argument remains' => [str_replace('packet($input)', 'packet("wrong")', $normal), true],
    'invalid consumer argument remains' => [$origin.$guard.$throw.'requiresInteger($value); '.$finish, true],
    'different invented identifiers' => [str_replace(['$data', '$value', '"field"'], ['$payload', '$text', '"item"'], $normal), true],
    'missing scalar throw' => [$origin.$guard.$finish, false],
    'nonthrowing scalar branch' => [$origin.$guard.str_replace('throw new \\UnexpectedValueException("Expected text.");', 'return null;', $throw).$finish, false],
    'moved scalar throw' => [$origin.$guard.'unrelated(); '.$throw.$finish, false],
    'side effect in failure body' => [$origin.$guard.str_replace('{ throw', '{ unrelated(); throw', $throw).$finish, false],
    'else branch' => [$origin.$guard.$throw.'else { unrelated(); } '.$finish, false],
    'wrong scalar variable' => [$origin.$guard.str_replace('is_string($value)', 'is_string($input)', $throw).$finish, false],
    'different array base' => [$origin.str_replace('$data["field"]', '$input["field"]', $guard).$throw.$finish, false],
    'dynamic key' => [$origin.str_replace('["field"]', '[$key]', $guard).$throw.$finish, false, 'array $input, string $key'],
    'non-null fallback' => [$origin.str_replace(': null;', ': "fallback";', $guard).$throw.$finish, false],
    'non-null coalesce' => [$origin.str_replace('?? null', '?? "fallback"', $guard).$throw.$finish, false],
    'branch side effect' => [$origin.str_replace('($data["field"] ?? null)', 'branchValue($data)', $guard).$throw.$finish, false],
    'constant true branch' => [$origin.str_replace('($data["field"] ?? null)', '"text"', $guard).$throw.$finish, false],
    'business branch' => [$origin.'if (is_array($data)) { unrelated(); } '.$finish, false],
    'parameter origin' => [str_replace($origin, '$data = $input; ', $normal), false],
    'literal origin' => [str_replace($origin, '$data = ["field" => "text"]; ', $normal), false],
    'already read local' => ['$data = packet($input); unrelated($data); '.$guard.$throw.$finish, false],
    'reference alias' => [$origin.'$alias =& $data; '.$guard.$throw.$finish, false],
    'captured local' => [$origin.'$callback = function () use ($data): void {}; '.$guard.$throw.$finish, false],
    'reference parameter' => [$normal, false, 'array &$input'],
    'prior output' => [$origin.'$value = null; '.$guard.$throw.$finish, false],
    'mutated input' => [$origin.'$data["field"] = "changed"; '.$guard.$throw.$finish, false],
    'inline var annotation' => [$origin.'/** @var array $data */ '.$guard.$throw.$finish, false],
    'named predicate argument' => [$origin.str_replace('is_array($data)', 'is_array(value: $data)', $guard).$throw.$finish, false],
    'wrong exception' => [$origin.$guard.str_replace('UnexpectedValueException', 'RuntimeException', $throw).$finish, false],
    'custom exception' => [$origin.$guard.str_replace('\\UnexpectedValueException', 'LocalFailure', $throw).$finish, false],
    'wrong exception argument' => [$origin.$guard.str_replace('"Expected text."', '[]', $throw).$finish, false],
    'opaque exception message' => [$origin.$guard.str_replace('"Expected text."', 'unrelated()', $throw).$finish, false],
    'dynamic callee' => [str_replace('is_array($data)', '$predicate($data)', $normal), false, 'array $input, Closure $predicate'],
    'impossible array branch' => [str_replace($origin, '$data = 7; ', $normal), false],
    'impossible scalar check' => [$origin.$guard.'$integer = 7; if (is_string($integer)) { unrelated(); } '.$throw.$finish, false],
    'nullable input contract' => [str_replace($origin, '$data = nullablePacket($input); ', $normal), false],
    'mixed input contract' => [str_replace($origin, '$data = mixedPacket($input); ', $normal), false],
    'narrow documented array contract' => [str_replace('packet($input)', 'documentedPacket($input)', $normal), false],
    'local inspection escape' => [$origin.'compact("data"); '.$guard.$throw.$finish, false],
    'locals extraction' => [$origin.'extract([]); '.$guard.$throw.$finish, false],
    'reference return scope' => [$normal, false, 'array $input', true],
];
$source = <<<'PHP'
<?php
declare(strict_types=1);
function packet(array $input): array { return $input; }
function nullablePacket(array $input): ?array { return $input; }
function mixedPacket(array $input): mixed { return $input; }
/** @return array<string,string> */
function documentedPacket(array $input): array { return []; }
function unrelated(mixed $value = null): mixed { return $value; }
function branchValue(array $input): mixed { return $input['field'] ?? null; }
function requiresInteger(int $value): int { return $value; }
class LocalFailure extends UnexpectedValueException {}

PHP;
$ranges = [];
foreach ($cases as $label => [$body, $positive]) {
    $start = strlen($source);
    $source .= 'function '.(($cases[$label][3] ?? false) ? '&' : '').'guardCase'.count($ranges).'('.($cases[$label][2] ?? 'array $input').'): mixed { '.$body." }\n";
    $ranges[$label] = [$start, strlen($source), $positive];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/methods.php', '<?php class PacketEndpoint { public function consume(array $input): mixed { '.$normal.' } }');
file_put_contents($workspace.'/crlf.php', str_replace("\n", "\r\n", '<?php'."\n".'// An independently invented prefix: '."\u{00E9}"."\n".'function crlfPacket(array $input): array { return $input; }'."\n".'function crlfGuard(array $input): mixed { '.str_replace('packet(', 'crlfPacket(', $normal).' }'."\n"));
file_put_contents($workspace.'/shadows.php', <<<'PHP'
<?php
namespace GuardShadow;
function is_array(mixed $value): bool { return true; }
function is_string(mixed $value): bool { return true; }
function packet(array $input): array { return $input; }
function shadowGuard(array $input): mixed {
    $data = packet($input);
    $value = is_array($data) ? ($data['field'] ?? null) : null;
    if (!is_string($value)) { throw new \UnexpectedValueException('Expected text.'); }
    return strlen($value);
}
namespace ImportedGuard;
use function GuardShadow\is_array;
function packet(array $input): array { return $input; }
function importedGuard(array $input): mixed {
    $data = packet($input);
    $value = is_array($data) ? ($data['field'] ?? null) : null;
    if (!is_string($value)) { throw new \UnexpectedValueException('Expected text.'); }
    return $value;
}
PHP);
file_put_contents($workspace.'/context.php', '<?php function contextPacket(array $input): array { return $input; } function contextGuard(array $input): mixed { '.str_replace('packet(', 'contextPacket(', $normal).' }');
if (in_array('--integrated', $argv, true)) {
    // Keep the complete current worker, removing only this policy's one registration.
    $workerSource = file_get_contents($package.'/bin/laramago-worker.php');
    $anchor = '        new DefensiveArrayGuardPlugin($projectRoot),';
    if ($workerSource === false || substr_count($workerSource, $anchor) !== 1) { throw new RuntimeException('Expected exactly one defensive policy worker registration.'); }
    $controlSource = str_replace($anchor, '', $workerSource, $removedRegistration);
    if ($removedRegistration !== 1) { throw new RuntimeException('Could not isolate the defensive policy worker registration.'); }
    file_put_contents($workspace.'/integrated-control-worker.php', $controlSource);
    file_put_contents($workspace.'/integrated-control-source.json', json_encode([
        'workerHash' => hash('sha256', $workerSource), 'controlHash' => hash('sha256', $controlSource),
        'removedRegistrations' => $removedRegistration, 'removedAnchor' => $anchor,
    ], JSON_THROW_ON_ERROR));
}
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$root = $argv[2];
$mode = $argv[3];
$plugin = new \Ichinya\Laramago\Analyzer\DefensiveArrayGuardPlugin($root);
$plugins = [$plugin];
if ($mode === 'contexts' || $mode === 'observe') {
    $plugins = [new class($root, $mode) implements \Mago\Sdk\Analyzer\Plugin {
        public function __construct(private string $root, private string $mode) {}
        public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
            return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/defensive-controls', 'Defensive controls', 'Exact native source and issue policy controls');
        }
        public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
            $proofs = new \Ichinya\Laramago\Analyzer\StaticAnalysis\DefensiveArrayGuardProofs($this->root);
            $registry->registerInitializationHook($proofs);
            $registry->registerCodebaseScanHook($proofs);
            $filter = new \Ichinya\Laramago\Analyzer\DefensiveArrayGuardIssueFilter($proofs);
            $registry->registerIssueFilterHook(new class($filter, $this->root, $this->mode) implements \Mago\Sdk\Analyzer\IssueFilterHook {
                public function __construct(private $filter, private string $root, private string $mode) {}
                public function getCodes(): array { return $this->filter->getCodes(); }
                public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                    $result = $this->filter->filterIssue($context);
                    file_put_contents($this->root.'/native-metadata.txt', var_export([
                        'predicate' => $context->codebase->getFunction('is_array'),
                        'stringPredicate' => $context->codebase->getFunction('is_string'),
                        'exception' => $context->codebase->getClass('UnexpectedValueException'),
                        'constructor' => $context->codebase->getDeclaringMethod('UnexpectedValueException', '__construct'),
                        'caller' => $context->codebase->getFunction('contextGuard'),
                        'proofs' => count($this->filter->proofs->proofs($context->file, $context->contents)),
                        'decision' => $result,
                    ], true));
                    if ($this->mode === 'observe') { return $result; }
                    if ($result !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { throw new \RuntimeException('Expected a certified policy context.'); }
                    $checks = 0;
                    foreach (['severity', 'code', 'message', 'note', 'extra-note', 'help', 'link', 'edit', 'kind', 'annotation', 'span-start', 'span-end', 'foreign', 'extra-annotation', 'file', 'contents'] as $variant) {
                        $issue = $context->issue;
                        $annotation = $issue->annotations[0];
                        $annotations = [new \Mago\Sdk\Reporting\Annotation(
                            $variant === 'kind' ? \Mago\Sdk\Reporting\AnnotationKind::Secondary : $annotation->kind,
                            new \Mago\Sdk\Span($annotation->span->start + ($variant === 'span-start' ? 1 : 0), $annotation->span->end + ($variant === 'span-end' ? 1 : 0)),
                            $variant === 'annotation' ? 'Different array annotation.' : $annotation->message,
                            $variant === 'foreign' ? 'other.php' : $annotation->file,
                        )];
                        if ($variant === 'extra-annotation') { $annotations[] = $annotation; }
                        $changed = new \Mago\Sdk\Reporting\ReportedIssue(
                            $variant === 'severity' ? \Mago\Sdk\Reporting\Level::Error : $issue->level,
                            $variant === 'code' ? 'impossible-type-comparison' : $issue->code,
                            $variant === 'message' ? 'Different native issue.' : $issue->message,
                            $variant === 'note' ? ['Different note.'] : ($variant === 'extra-note' ? [...$issue->notes, 'Extra note.'] : $issue->notes),
                            $variant === 'help' ? 'Different help.' : $issue->help,
                            $variant === 'link' ? 'https://example.invalid' : $issue->link,
                            $annotations,
                            $variant === 'edit' ? [\Mago\Sdk\Reporting\TextEdit::replace($annotation->span, 'changed')] : $issue->edits,
                        );
                        $probe = new \Mago\Sdk\Analyzer\IssueFilterContext($context->phpVersion, $context->codebase, $context->types, $context->cancellation,
                            $variant === 'file' ? 'other.php' : $context->file,
                            $variant === 'contents' ? $context->contents."\n// changed bytes" : $context->contents, $changed);
                        if ($this->filter->filterIssue($probe) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Defensive policy accepted issue '.$variant); }
                        $checks++;
                    }
                    $bytes = file_get_contents($this->root.'/context.php');
                    file_put_contents($this->root.'/context.php', $bytes."\n// user edit");
                    try {
                        if ($this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Policy accepted edited disk source.'); }
                        $checks++;
                    } finally { file_put_contents($this->root.'/context.php', $bytes); }
                    $targets = [
                        'caller' => $context->codebase->getFunction('contextGuard'),
                        'is_array' => $context->codebase->getFunction('is_array'),
                        'is_string' => $context->codebase->getFunction('is_string'),
                        'exception' => $context->codebase->getClass('UnexpectedValueException'),
                        'constructor' => $context->codebase->getDeclaringMethod('UnexpectedValueException', '__construct'),
                    ];
                    $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                    $snapshot = $cache->values;
                    foreach ($targets as $role => $metadata) {
                        if ($metadata === null) { throw new \RuntimeException('Missing native control '.$role); }
                        $variants = $role === 'caller'
                            ? ['file', 'identifier', 'body-span', 'name-span', 'reference', 'parameter-name', 'parameter-span']
                            : ($role === 'exception' ? ['builtin', 'identifier', 'hierarchy']
                                : ['builtin', 'reference', 'identifier', 'parameter-reference', 'parameter-variadic', 'parameter-type', 'return-type',
                                    ...($role === 'constructor' ? [] : ['conditional-subject', 'conditional-target', 'conditional-then', 'conditional-else', 'conditional-negated', 'assertion'])]);
                        foreach ($variants as $variant) {
                            $values = get_object_vars($metadata);
                            if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('other.php', $metadata->location->span); }
                            if ($variant === 'body-span') { $values['location'] = new \Mago\Sdk\SourceLocation($metadata->location->file, new \Mago\Sdk\Span($metadata->location->span->start, $metadata->location->span->end - 1)); }
                            if ($variant === 'name-span') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation($metadata->nameLocation->file, new \Mago\Sdk\Span($metadata->nameLocation->span->start + 1, $metadata->nameLocation->span->end)); }
                            if ($variant === 'builtin') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                            if ($variant === 'reference') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                            if ($variant === 'identifier') {
                                if ($role === 'exception') { $values['name'] = 'OtherException'; }
                                else { $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($metadata->identifier->kind, 'other', $metadata->identifier->class); }
                            }
                            if ($variant === 'hierarchy') { $values['directParentClass'] = 'OtherException'; }
                            if ($variant === 'return-type') { $values['returnType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($metadata->returnType?->location ?? $metadata->location, \Mago\Sdk\Analyzer\Type::string(), false, false); }
                            if ($variant === 'assertion') { $values['ifTrueAssertions'] = []; }
                            if (str_starts_with($variant, 'conditional-')) {
                                $conditional = $metadata->returnType->type->atomicTypes[0];
                                $changedType = new \Mago\Sdk\Analyzer\Type\ConditionalType(
                                    $variant === 'conditional-subject' ? \Mago\Sdk\Analyzer\Type::fromAtomics(new \Mago\Sdk\Analyzer\Type\VariableType('$other')) : $conditional->subject,
                                    $variant === 'conditional-target' ? \Mago\Sdk\Analyzer\Type::int() : $conditional->target,
                                    $variant === 'conditional-then' ? \Mago\Sdk\Analyzer\Type::false() : $conditional->then,
                                    $variant === 'conditional-else' ? \Mago\Sdk\Analyzer\Type::true() : $conditional->otherwise,
                                    $variant === 'conditional-negated' ? !$conditional->negated : $conditional->negated,
                                );
                                $values['returnType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($metadata->returnType->location, \Mago\Sdk\Analyzer\Type::fromAtomics($changedType), true, false);
                            }
                            if (str_starts_with($variant, 'parameter-')) {
                                $parameter = get_object_vars($metadata->parameters[0]);
                                if ($variant === 'parameter-name') { $parameter['name'] = '$other'; }
                                if ($variant === 'parameter-span') { $parameter['nameLocation'] = new \Mago\Sdk\SourceLocation($parameter['nameLocation']->file, new \Mago\Sdk\Span($parameter['nameLocation']->span->start + 1, $parameter['nameLocation']->span->end)); }
                                if ($variant === 'parameter-reference') { $parameter['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter['flags']->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                                if ($variant === 'parameter-variadic') { $parameter['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter['flags']->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC); }
                                if ($variant === 'parameter-type') { $parameter['type'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter['type']->location, \Mago\Sdk\Analyzer\Type::int(), false, false); }
                                $values['parameters'][0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$parameter);
                            }
                            $class = $metadata::class;
                            $mutated = new $class(...$values);
                            $replaced = 0;
                            foreach ($snapshot as $operation => $entries) {
                                foreach ($entries as $key => $entry) {
                                    if ($entry === $metadata) { $cache->values[$operation][$key] = $mutated; $replaced++; }
                                }
                            }
                            try {
                                if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Defensive policy accepted metadata '.$role.' '.$variant); }
                                $checks++;
                            } finally { $cache->values = $snapshot; }
                        }
                    }
                    if ($this->filter->filterIssue($context) !== $result) { throw new \RuntimeException('Policy controls damaged the valid certificate.'); }
                    file_put_contents($this->root.'/context-checks.log', $checks."\n", FILE_APPEND);
                    return $result;
                }
            });
        }
    }];
}
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/defensive-guards', name: 'Defensive guards', version: '1', analyzerPlugins: $plugins)))->run();
PHP);

$analyze = static function (string $mode, array $paths = ['cases.php', 'methods.php', 'crlf.php', 'shadows.php'], int $workers = 1, bool $external = false) use ($workspace, $package): array {
    $configuration = $external ? $workspace.'/external configuration' : $workspace;
    if (!is_dir($configuration)) { mkdir($configuration); }
    $config = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5', 'source' => ['paths' => $paths, 'includes' => ['packages']],
        'analyzer' => ['find-unused-expressions' => false, 'check-throws' => false, 'ignore' => []], 'threads' => $workers];
    if ($mode !== 'native') {
        $workerCommand = in_array($mode, ['integrated', 'integrated-control'], true)
            ? [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/integrated-control-worker.php', $package.'/vendor/autoload.php', $workspace]
            : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace, $mode];
        $config['extension-hosts'] = ['fixture' => ['command' => $workerCommand, 'workers' => $workers, 'request-timeout-ms' => 120000]];
    }
    file_put_contents($configuration.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $artifact = $mode.'-'.$workers.($external ? '-external' : '');
    if ($paths !== ['cases.php', 'methods.php', 'crlf.php', 'shadows.php']) {
        $artifact .= '-'.implode('-', array_map(static fn (string $path): string => str_replace(['/', '\\', '.'], '-', $path), $paths));
    }
    $stderr = $workspace.'/'.$artifact.'.stderr';
    $process = proc_open([...$command, '--workspace', $workspace, '--config', $configuration.'/mago.json', 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']], $pipes, $workspace);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start genuine Mago.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $exit = proc_close($process);
    if (!in_array($exit, [0, 1], true) || preg_match('/failed to (?:load|initialize)|extension.*(?:failed|error)|request timed out/i', file_get_contents($stderr)) === 1) {
        throw new RuntimeException('Genuine Mago failed: '.$mode.' '.$exit.' '.$workspace.' '.file_get_contents($stderr));
    }
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($workspace.'/'.$artifact.'.json', $output);
    file_put_contents($workspace.'/report-scopes.jsonl', json_encode(['artifact' => $artifact, 'mode' => $mode, 'paths' => $paths,
        'workers' => $workers, 'external' => $external, 'exit' => $exit], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    return $report['issues'] ?? [];
};
$signature = static function (array $issues): array {
    $signatures = [];
    foreach ($issues as $issue) {
        $primary = array_values(array_filter($issue['annotations'] ?? [], static fn (array $a): bool => ($a['kind'] ?? '') === 'Primary'))[0] ?? null;
        $signatures[] = [$issue['code'], $issue['level'], str_replace('\\', '/', $primary['span']['file_id']['name'] ?? ''),
            $primary['span']['start']['offset'] ?? -1, $primary['span']['end']['offset'] ?? -1,
            $issue['message'], $issue['notes'] ?? [], $issue['help'] ?? null,
            array_map(static fn (array $a): array => [$a['kind'], $a['message'] ?? null, $a['span']['start']['offset'], $a['span']['end']['offset'], str_replace('\\', '/', $a['span']['file_id']['name'])], $issue['annotations'] ?? []),
            $issue['link'] ?? null, $issue['edits'] ?? [], $issue];
    }
    usort($signatures, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
    return $signatures;
};
$group = static function (array $reports) use ($ranges): array {
    $groups = [];
    foreach ($reports as $issue) {
        if ($issue[2] !== 'cases.php') { continue; }
        foreach ($ranges as $label => [$start, $end]) {
            if ($issue[3] >= $start && $issue[3] < $end) { $groups[$label][] = $issue; break; }
        }
    }
    return $groups;
};
if (in_array('--observe', $argv, true)) {
    $native = $analyze('native', ['context.php']);
    $actual = $analyze('observe', ['context.php']);
    echo 'Defensive guard observation: native='.count($native).', policy='.count($actual).', workspace='.$workspace.".\n";
    exit(0);
}
$native = $signature($analyze('native'));
$standalone = $signature($analyze('standalone'));
$before = $group($native);
$after = $group($standalone);
$removed = $retained = 0;
$permitted = [];
foreach ($cases as $label => [, $positive]) {
    $original = $before[$label] ?? [];
    $actual = $after[$label] ?? [];
    if ($positive) {
        $warnings = array_values(array_filter($original, static fn (array $issue): bool => $issue[0] === 'redundant-type-comparison' && $issue[1] === 'Warning'
            && str_starts_with($issue[5], 'Redundant type assertion: `')));
        if (count($warnings) !== 1 || $actual !== array_values(array_filter($original, static fn (array $issue): bool => $issue !== $warnings[0]))) {
            throw new RuntimeException('Missing exact defensive policy: '.$label.' '.json_encode([$original, $actual]).' '.$workspace);
        }
        $permitted[] = $warnings[0];
        $removed++;
    } else {
        if ($actual !== $original) { throw new RuntimeException('Unsafe defensive policy: '.$label.' '.json_encode([$original, $actual]).' '.$workspace); }
        if ($original !== []) { $retained++; }
    }
}
foreach (['invalid producer argument remains', 'invalid consumer argument remains', 'impossible array branch', 'impossible scalar check', 'wrong exception argument'] as $label) {
    if (!array_filter($before[$label] ?? [], static fn (array $issue): bool => $issue[1] === 'Error')) { throw new RuntimeException('Vacuous error preservation control: '.$label.' '.$workspace); }
}
$nativeErrors = array_values(array_filter($native, static fn (array $issue): bool => $issue[1] === 'Error'));
$actualErrors = array_values(array_filter($standalone, static fn (array $issue): bool => $issue[1] === 'Error'));
if ($nativeErrors !== $actualErrors) { throw new RuntimeException('Defensive policy changed an Error diagnostic. '.$workspace); }
foreach (['methods.php', 'crlf.php'] as $file) {
    $old = array_values(array_filter($native, static fn (array $issue): bool => $issue[2] === $file));
    $new = array_values(array_filter($standalone, static fn (array $issue): bool => $issue[2] === $file));
    $warnings = array_values(array_filter($old, static fn (array $issue): bool => $issue[0] === 'redundant-type-comparison' && $issue[1] === 'Warning'));
    if (count($warnings) !== 1 || $new !== array_values(array_filter($old, static fn (array $issue): bool => $issue !== $warnings[0]))) { throw new RuntimeException('Missing generic method/byte policy: '.$file.' '.$workspace); }
    $permitted[] = $warnings[0];
    $removed++;
}
$oldShadow = array_values(array_filter($native, static fn (array $issue): bool => $issue[2] === 'shadows.php'));
$newShadow = array_values(array_filter($standalone, static fn (array $issue): bool => $issue[2] === 'shadows.php'));
if ($oldShadow === [] || $oldShadow !== $newShadow) { throw new RuntimeException('Shadow predicates changed diagnostics. '.$workspace); }
if ($signature($analyze('standalone', external: true)) !== $standalone) { throw new RuntimeException('External configuration changed policy. '.$workspace); }
$single = $signature($analyze('standalone', ['cases.php']));
if ($single !== array_values(array_filter($standalone, static fn (array $issue): bool => $issue[2] === 'cases.php'))) { throw new RuntimeException('Single-file policy changed unrelated diagnostics. '.$workspace); }
$contexts = $analyze('contexts', ['context.php']);
$contextChecks = file_exists($workspace.'/context-checks.log') ? array_sum(array_map('intval', file($workspace.'/context-checks.log', FILE_IGNORE_NEW_LINES))) : 0;
if ($contextChecks !== 60 || array_filter($contexts, static fn (array $issue): bool => $issue['code'] === 'redundant-type-comparison')) { throw new RuntimeException('Missing native issue/metadata controls: '.$contextChecks.' '.$workspace); }
if (in_array('--integrated', $argv, true)) {
    $control = $signature($analyze('integrated-control'));
    $controlWarnings = array_values(array_filter($control, static fn (array $issue): bool => in_array($issue, $permitted, true)));
    if (count($controlWarnings) !== $removed) { throw new RuntimeException('The full worker control changed a certified native Warning. '.$workspace); }
    $expected = array_values(array_filter($control, static fn (array $issue): bool => !in_array($issue, $permitted, true)));
    $controlErrors = array_values(array_filter($control, static fn (array $issue): bool => $issue[1] === 'Error'));
    foreach ([1, 3] as $workers) {
        $actual = $signature($analyze('integrated', workers: $workers));
        if ($actual !== $expected || array_values(array_filter($actual, static fn (array $issue): bool => $issue[1] === 'Error')) !== $controlErrors) {
            throw new RuntimeException('Integrated defensive policy differs from its complete worker control: '.$workers.' workers. '.$workspace);
        }
    }
}
if (file_exists($workspace.'/executed')) { throw new RuntimeException('A bootstrap or migration body executed.'); }

require $package.'/vendor/autoload.php';
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
    $index = new DefensiveArrayGuardProofs($root);
    $file = static fn (string $path, string $contents): SourceFile => new SourceFile($version, $path, $contents, [],
        new NodeStore([], '', 0), new ResolvedNameStore('', '', '', 0), new TriviaStore('', 0), null);
    $scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $token): void {
        $index->scan(new CodebaseScanContext($version, $token, $files, $first, $last));
    };
    $checks = 0;
    $expect = static function (string $label, bool $value) use (&$checks): void {
        if (!$value) { throw new RuntimeException('Defensive scan failed: '.$label); }
        $checks++;
    };
    $host = $file('context.php', $source);
    $expect('no generation', $index->proofs('context.php', $source) === []);
    $scan([$host], first: false);
    $expect('missing first batch', $index->proofs('context.php', $source) === []);
    $scan([$host], last: false);
    $expect('incomplete generation', $index->proofs('context.php', $source) === []);
    $scan([], first: false);
    $proofs = $index->proofs('context.php', $source);
    $expect('complete generation', count($proofs) === 1);
    $expect('current bytes', DefensiveArrayGuardProofs::current($proofs[0]));
    $expect('foreign file', $index->proofs('other.php', $source) === []);
    $expect('different bytes', $index->proofs('context.php', $source."\n") === []);
    file_put_contents($root.'/context.php', $source."\n");
    $expect('user edit', !DefensiveArrayGuardProofs::current($proofs[0]));
    file_put_contents($root.'/context.php', $source);
    $expect('restored source', DefensiveArrayGuardProofs::current($proofs[0]));
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
    $expect('new generation recovers', count($index->proofs('context.php', $source)) === 1);
    $scan([], first: false);
    $expect('batch after completed generation', $index->proofs('context.php', $source) === []);
    $scan([$host]);
    $index->initialize(new InitializationContext($version, $token));
    $expect('initialization resets', $index->proofs('context.php', $source) === []);
    $scan([$host, $file('other.php', str_replace('contextGuard', 'otherGuard', $source))]);
    $expect('foreign proof separately indexed', count($index->proofs('context.php', $source)) === 1 && count($index->proofs('other.php', str_replace('contextGuard', 'otherGuard', $source))) === 1);
    return $checks;
})($workspace.'/scan root', file_get_contents($workspace.'/context.php'));
echo 'Defensive guard policy checks passed: '.count($cases).' source cases, '.$removed.' exact defensive advisories permitted, '.$retained.' retained negative groups, '.$contextChecks.' issue/metadata and '.$scanChecks.' lifecycle controls, unchanged native Error signatures, root with spaces, custom vendor directory and single-file analysis'.(in_array('--integrated', $argv, true) ? ', one and three integrated workers' : '').'. Workspace: '.$workspace.".\n";
