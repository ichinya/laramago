<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\LocalMixedAssignmentFilter;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\TypeComparator;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Reporting\Annotation;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\ReportedIssue;
use Mago\Sdk\Span;

require dirname(__DIR__).'/vendor/autoload.php';

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago local assignments '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Bootstrap executed.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));

/** @var array<string, array{source: string, removed: int, remaining: list<string>, signature?: string}> $cases */
$cases = [
    'plain local' => ['source' => '$local = $input;', 'removed' => 1, 'remaining' => []],
    'conditional assignment' => ['source' => 'if ($local = $input) {}', 'removed' => 1, 'remaining' => []],
    'foreach value' => ['source' => 'foreach ($items as $value) {}', 'removed' => 1, 'remaining' => []],
    'foreach key and value' => ['source' => 'foreach ($items as $key => $value) {}', 'removed' => 1, 'remaining' => []],
    'typed argument remains unsafe' => [
        'source' => '$local = $input; consumeString($local);',
        'removed' => 1,
        'remaining' => ['mixed-argument'],
    ],
    'method access remains unsafe' => [
        'source' => '$local = $input; $local->missing();',
        'removed' => 1,
        'remaining' => ['mixed-method-access'],
    ],
    'property access remains unsafe' => [
        'source' => '$local = $input; $local->missing;',
        'removed' => 1,
        'remaining' => ['mixed-property-access'],
    ],
    'array access remains unsafe' => [
        'source' => '$local = $input; $local["name"];',
        'removed' => 1,
        'remaining' => ['mixed-array-access'],
    ],
    'property write is retained' => [
        'source' => '$box = new LocalBox; $box->name = $input;',
        'removed' => 0,
        'remaining' => ['mixed-property-type-coercion'],
    ],
    'reference alias is retained' => [
        'source' => '$local =& $input; $local = $input;',
        'removed' => 0,
        'remaining' => ['mixed-assignment'],
    ],
    'captured reference is retained' => [
        'source' => '$local = $input; $callback = function () use (&$local): void {};',
        'removed' => 0,
        'remaining' => ['mixed-assignment'],
    ],
    'by-reference parameter is retained' => [
        'source' => '$input = $items["name"] ?? null;',
        'signature' => 'mixed &$input, array $items',
        'removed' => 0,
        'remaining' => ['mixed-assignment'],
    ],
    'global binding is retained' => [
        'source' => 'global $state; $state = $input;',
        'removed' => 0,
        'remaining' => ['mixed-assignment'],
    ],
    'static binding is retained' => [
        'source' => 'static $state; $state = $input;',
        'removed' => 0,
        'remaining' => ['mixed-assignment'],
    ],
    'foreach reference is retained' => [
        'source' => 'foreach ($items as &$value) {}',
        'removed' => 0,
        'remaining' => ['mixed-assignment'],
    ],
    'destructuring is retained' => [
        'source' => '[$first, $second] = $items;',
        'removed' => 0,
        'remaining' => ['mixed-assignment'],
    ],
];

$source = "<?php\nfunction consumeString(string \$value): void {}\nclass LocalBox { public string \$name; }\n";
$lines = [];
foreach ($cases as $name => $case) {
    $source .= 'function scenario'.count($lines).'('.($case['signature'] ?? 'mixed $input, array $items')
        .'): void { '.$case['source'].' }'."\n";
    $lines[substr_count($source, "\n")] = $name;
}
$source .= 'function rawInput(): mixed { return null; }'."\n";
$source .= '$globalAssignment = rawInput();'."\n";
$globalLine = substr_count($source, "\n");
file_put_contents($workspace.'/cases.php', $source);

$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.5',
    'source' => ['paths' => ['cases.php']],
];
$run = static function (bool $extended, bool $localOnly = false) use ($workspace, $configuration, $command, $package): array {
    $config = $configuration;
    if ($extended) {
        $worker = $package.'/bin/laramago-worker.php';
        if ($localOnly) {
            $worker = $workspace.'/local-worker.php';
            file_put_contents($worker, '<?php require '.var_export($package.'/vendor/autoload.php', true).';'
                .'$plugin = new class implements Mago\\Sdk\\Analyzer\\Plugin {'
                .'public function getDefinition(): Mago\\Sdk\\Analyzer\\PluginDefinition { return new Mago\\Sdk\\Analyzer\\PluginDefinition("fixture/local-storage", "Local storage", "Isolated native local assignment control"); }'
                .'public function register(Mago\\Sdk\\Analyzer\\PluginRegistry $registry): void { $registry->registerIssueFilterHook(new Ichinya\\Laramago\\Analyzer\\LocalMixedAssignmentFilter); } };'
                .'(new Mago\\Sdk\\Worker(new Mago\\Sdk\\Extension("fixture/local-storage", "Local storage", "1", analyzerPlugins: [$plugin])))->run();');
        }
        $config['extension-hosts'] = [
            'laramago' => [
                'command' => [PHP_BINARY, $worker, $package.'/vendor/autoload.php', $workspace],
                'workers' => 1,
            ],
        ];
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $name = $localOnly ? 'isolated-local' : ($extended ? 'adapted' : 'native');
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$name.'.json', 'w'],
            2 => ['file', $workspace.'/'.$name.'.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$name.'.log');
    file_put_contents($workspace.'/'.$name.'.process.json', json_encode(['exit' => $exit, 'processClosed' => true], JSON_THROW_ON_ERROR));
    if ($exit !== 1 || preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|invalid[^\r\n]*extension[^\r\n]*frame|hook[^\r\n]*failed|fatal|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i', $stderr)) {
        throw new RuntimeException('Mago analysis failed: '.$name.'; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $codes = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
        ))[0];
        if ($primary['span']['file_id']['name'] !== 'cases.php') {
            throw new RuntimeException('Unexpected diagnostic location; inspect '.$workspace);
        }
        $line = $primary['span']['start']['line'] + 1;
        $codes[$line][] = $issue['code'];
    }
    foreach ($codes as &$onLine) {
        sort($onLine);
    }

    return $codes;
};

$native = $run(false);
$isolatedLocal = $run(true, true);
$adapted = $run(true);
foreach ($lines as $line => $name) {
    $case = $cases[$name];
    $before = $native[$line] ?? [];
    $after = $adapted[$line] ?? [];
    $expected = $before;
    for ($i = 0; $i < $case['removed']; $i++) {
        $position = array_search('mixed-assignment', $expected, true);
        if ($position === false) {
            throw new RuntimeException('Native Mago did not report the expected local assignment: '.$name);
        }
        unset($expected[$position]);
    }
    $expected = array_values($expected);
    sort($expected);
    if ($after !== $expected || ($isolatedLocal[$line] ?? []) !== $expected || array_diff($case['remaining'], $after) !== []) {
        throw new RuntimeException(
            $name.': native '.json_encode($before).', adapted '.json_encode($after)
            .', expected '.json_encode($expected).'; inspect '.$workspace,
        );
    }
    unset($native[$line], $isolatedLocal[$line], $adapted[$line]);
    echo 'PASS: '.$name."\n";
}
if (
    ($native[$globalLine] ?? []) !== ['mixed-assignment']
    || ($isolatedLocal[$globalLine] ?? []) !== ['mixed-assignment']
    || ($adapted[$globalLine] ?? []) !== []
) {
    throw new RuntimeException('Script scope must retain the isolated Local advisory and follow the integrated Ordinary policy; inspect '.$workspace);
}
unset($native[$globalLine], $isolatedLocal[$globalLine], $adapted[$globalLine]);
if ($native !== [] || $isolatedLocal !== [] || $adapted !== []) {
    throw new RuntimeException('Unexpected diagnostics outside scenarios; inspect '.$workspace);
}
echo "PASS: script assignment retains isolated Local diagnostics and follows integrated Ordinary policy\n";

// The filter must use the SDK's in-memory contents, even when the same path has different bytes.
$cancel = new class implements CancellationTokenInterface {
    public function isCancelled(): bool { return false; }
    public function throwIfCancelled(): void {}
    public function subscribe(Closure $callback): int { return 0; }
    public function unsubscribe(int $subscription): void {}
};
$codebase = (new ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
$types = (new ReflectionClass(TypeComparator::class))->newInstanceWithoutConstructor();
$filter = new LocalMixedAssignmentFilter;
$issueFor = static fn (int $start, int $end): ReportedIssue => new ReportedIssue(
    Level::Warning,
    'mixed-assignment',
    'Assigning `mixed` type to a variable may lead to unexpected behavior.',
    [],
    null,
    null,
    [new Annotation(AnnotationKind::Primary, new Span($start, $end), 'Assigning `mixed` type here.')],
    [],
);
$contextFor = static fn (string $contents, ReportedIssue $issue): IssueFilterContext => new IssueFilterContext(
    PHPVersion::fromParts(8, 5),
    $codebase,
    $types,
    $cancel,
    $workspace.'/unsaved.php',
    $contents,
    $issue,
);
$local = '<?php function probe(mixed $input): void { $value = $input; }';
$global = '<?php $value = unknown();';
$superglobal = '<?php function probe(mixed $input): void { $_ENV = $input; }';
$offset = strpos($local, '$value');
$superglobalOffset = strpos($superglobal, '$_ENV');
if (
    $offset === false
    || $superglobalOffset === false
    || $filter->filterIssue($contextFor($local, $issueFor($offset, $offset + 6))) !== IssueFilterDecision::Remove
    || $filter->filterIssue($contextFor($global, $issueFor(strpos($global, '$value'), strpos($global, '$value') + 6))) !== IssueFilterDecision::Keep
    || $filter->filterIssue($contextFor($superglobal, $issueFor($superglobalOffset, $superglobalOffset + 5))) !== IssueFilterDecision::Keep
    || $filter->filterIssue($contextFor('<?php function broken( {', $issueFor(10, 16))) !== IssueFilterDecision::Keep
) {
    throw new RuntimeException('In-memory contents, cache invalidation, or parse failure handling changed.');
}
echo "PASS: in-memory contents and parse failure retain exact behavior\n";

$resolvedRoot = realpath($workspace);
foreach (glob($workspace.'/*') ?: [] as $file) {
    $resolved = realpath($file);
    if ($resolvedRoot === false || $resolved === false || ! str_starts_with($resolved, $resolvedRoot.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refusing cleanup outside temporary workspace.');
    }
    unlink($resolved);
}
rmdir($workspace);
