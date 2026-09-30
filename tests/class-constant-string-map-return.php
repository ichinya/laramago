<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago constant maps '.bin2hex(random_bytes(8));
mkdir($workspace);
$worker = <<<'PHP'
<?php
require $argv[1];
$plugin = new class implements \Mago\Sdk\Analyzer\Plugin {
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('constant-map-fixture', 'Constant map fixture', 'Literal map return contracts');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $filter = new \Ichinya\Laramago\Analyzer\ClassConstantStringMapReturnFilter;
        $registry->registerIssueFilterHook(new class($filter) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            public function __construct(private \Ichinya\Laramago\Analyzer\ClassConstantStringMapReturnFilter $filter) {}
            public function getCodes(): array { return $this->filter->getCodes(); }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                $result = $this->filter->filterIssue($context);
                if ($result !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { return $result; }
                foreach (['span', 'foreign', 'duplicate', 'code', 'message', 'source-value', 'source-constant', 'source-syntax', 'source-size', 'message-size'] as $variant) {
                    $annotations = $context->issue->annotations;
                    foreach ($annotations as $index => $annotation) {
                        if ($annotation->kind !== \Mago\Sdk\Reporting\AnnotationKind::Primary) { continue; }
                        $annotations[$index] = new \Mago\Sdk\Reporting\Annotation(
                            $annotation->kind,
                            new \Mago\Sdk\Span($annotation->span->start + ($variant === 'span' ? 1 : 0), $annotation->span->end),
                            $annotation->message, $variant === 'foreign' ? 'other.php' : $annotation->file,
                        );
                        if ($variant === 'duplicate') { $annotations[] = $annotation; }
                    }
                    $contents = match ($variant) {
                        'source-value' => str_replace('"Visible"', '123456789', $context->contents),
                        'source-constant' => str_replace('self::MAP', 'self::BAD', $context->contents),
                        'source-syntax' => $context->contents.'(',
                        'source-size' => $context->contents.str_repeat(' ', 1024 * 1024),
                        default => $context->contents,
                    };
                    $issue = new \Mago\Sdk\Reporting\ReportedIssue(
                        $context->issue->level, $variant === 'code' ? 'invalid-return-statement' : $context->issue->code,
                        $variant === 'message' ? 'Unknown return diagnostic.' : ($variant === 'message-size' ? str_repeat('x', 16385) : $context->issue->message),
                        $context->issue->notes, $context->issue->help, $context->issue->link, $annotations, $context->issue->edits,
                    );
                    $changed = new \Mago\Sdk\Analyzer\IssueFilterContext(
                        $context->phpVersion, $context->codebase, $context->types, $context->cancellation, $context->file, $contents, $issue,
                    );
                    if ($this->filter->filterIssue($changed) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) {
                        throw new \RuntimeException('Unsafe direct guard: '.$variant);
                    }
                }
                if ($this->filter->filterIssue($context) !== $result) { throw new \RuntimeException('Changed cached source identity.'); }
                return $result;
            }
        });
        $registry->registerInitializationHook($filter);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'constant-map-fixture', name: 'Constant map fixture', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP;
file_put_contents($workspace.'/worker.php', $worker);
$cases = [
    'external literal key' => ['[Keys::NAME => "Visible"]', 'self::MAP', '', true],
    'multiple literal keys' => ['[Keys::NAME => "Visible", Keys::OTHER => "Other"]', 'self::MAP', '', true],
    'explicit string key' => ['[Keys::NAME => "Visible", "literal" => "Other"]', 'self::MAP', '', true],
    'same class key' => ['[self::NAME => "Visible"]', 'self::MAP', '', true],
    'integer key' => ['[Keys::NUMBER => "Visible"]', 'self::MAP', '', false],
    'numeric string key' => ['[Keys::NUMERIC => "Visible"]', 'self::MAP', '', false],
    'wrong value' => ['[Keys::NAME => 42]', 'self::MAP', '', false],
    'unkeyed value' => ['[Keys::NAME => "Visible", "Other"]', 'self::MAP', '', false],
    'late bound map' => ['[Keys::NAME => "Visible"]', 'static::MAP', '', false],
    'spread map' => ['[...[Keys::NAME => "Visible"]]', 'self::MAP', '', false],
    'duplicate key' => ['[Keys::NAME => "Visible", Keys::NAME => "Other"]', 'self::MAP', '', false],
    'private external key' => ['[Keys::HIDDEN => "Visible"]', 'self::MAP', '', false],
    'missing external key' => ['[Keys::ABSENT => "Visible"]', 'self::MAP', '', false],
    'documented integer keys' => ['[Keys::NAME => "Visible"]', 'self::MAP', '/** @var array<int, string> */', false],
    'reference return' => ['[Keys::NAME => "Visible"]', 'self::MAP', '', false],
    'nested return' => ['[Keys::NAME => "Visible"]', '(static function (): array { return self::MAP; })()', '', false],
];
$files = [];
foreach ($cases as $name => [$map, $read, $doc, $accepted]) {
    $index = count($files);
    $file = 'case-'.$index.'.php';
    $reference = $name === 'reference return' ? '&' : '';
    $source = "<?php\nnamespace Fixture\\Case".$index.";\n"
        .'class Keys { public const NAME = "name"; public const OTHER = "other"; public const NUMBER = 3; public const NUMERIC = "14"; private const HIDDEN = "secret"; }'."\n"
        .'class Catalog { private const NAME = "name"; '.$doc.' private const MAP = '.$map.';'
        .' /** @return array<string, string> */ public static function '.$reference.'labels(): array { return '.$read.'; } }'."\n";
    file_put_contents($workspace.'/'.$file, $source);
    $files[$file] = [$name, $accepted];
}
$reports = [];
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach (['native', 'isolated', 'integrated'] as $mode) {
    if ($mode === 'integrated' && ! in_array('--integrated', $argv, true)) {
        continue;
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => array_keys($files)],
        'extension-hosts' => $mode === 'native' ? new stdClass : ['fixture' => [
            'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 1,
        ]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit !== 1 || preg_match('/failed|rejected request|parse error/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    foreach (json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'] as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary') {
                $reports[$mode][$annotation['span']['file_id']['name']][] = $issue;
                break;
            }
        }
    }
}
$normalize = static function (array $issues): array {
    $values = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues);
    sort($values);
    return $values;
};
foreach ($files as $file => [$name, $accepted]) {
    $native = $reports['native'][$file] ?? [];
    if ($accepted && ! in_array('less-specific-return-statement', array_column($native, 'code'), true)) {
        throw new RuntimeException('Missing native control for '.$name.'; inspect '.$workspace);
    }
    foreach (array_keys($reports) as $mode) {
        $expected = $accepted && $mode !== 'native'
            ? array_values(array_filter($native, static fn (array $issue): bool => $issue['code'] !== 'less-specific-return-statement')) : $native;
        if ($normalize($reports[$mode][$file] ?? []) !== $normalize($expected)) {
            throw new RuntimeException('Unexpected '.$mode.' delta for '.$name.'; inspect '.$workspace);
        }
        echo 'PASS: '.$mode.' '.$name.PHP_EOL;
    }
}
