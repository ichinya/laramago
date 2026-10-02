<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago foreach returns '.bin2hex(random_bytes(8));
mkdir($workspace);
$worker = <<<'PHP'
<?php
require $argv[1];
$plugin = new class implements \Mago\Sdk\Analyzer\Plugin {
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('foreach-return-fixture', 'Foreach return fixture', 'Validated scalar array returns');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $filter = new \Ichinya\Laramago\Analyzer\ValidatedForeachReturnFilter;
        $registry->registerInitializationHook($filter);
        $registry->registerIssueFilterHook(new class($filter) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            public function __construct(private \Ichinya\Laramago\Analyzer\ValidatedForeachReturnFilter $filter) {}
            public function getCodes(): array { return $this->filter->getCodes(); }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                $result = $this->filter->filterIssue($context);
                if ($result !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { return $result; }
                foreach (['span', 'foreign', 'duplicate', 'code', 'message', 'source-guard', 'source-return', 'source-parameter', 'context-file', 'source-syntax', 'source-size', 'message-size'] as $variant) {
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
                        'source-guard' => str_replace('! ', '  ', $context->contents),
                        'source-return' => str_replace('return $values;', 'return $others;', $context->contents),
                        'source-parameter' => str_replace('$values', '$others', $context->contents),
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
                        $context->phpVersion, $context->codebase, $context->types, $context->cancellation,
                        $variant === 'context-file' ? '/different-file.php' : $context->file, $contents, $issue,
                    );
                    if ($this->filter->filterIssue($changed) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) {
                        throw new \RuntimeException('Unsafe direct guard: '.$variant);
                    }
                }
                // Match a real native diagnostic against intentionally stale callable metadata.
                // No host response or production codebase state is changed by these fixtures.
                preg_match('/for function `([^`]+)`/', $context->issue->message, $target);
                $metadata = str_contains($target[1], '::')
                    ? $context->codebase->getDeclaringMethod(...explode('::', $target[1], 2))
                    : $context->codebase->getFunction($target[1]);
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                foreach (['name-span', 'body-span', 'identifier', 'parameter-count', 'parameter-name', 'parameter-span', 'parameter-reference'] as $variant) {
                    $values = get_object_vars($metadata);
                    if ($variant === 'name-span') {
                        $values['nameLocation'] = new \Mago\Sdk\SourceLocation($metadata->nameLocation->file,
                            new \Mago\Sdk\Span($metadata->nameLocation->span->start + 1, $metadata->nameLocation->span->end));
                    } elseif ($variant === 'body-span') {
                        $values['location'] = new \Mago\Sdk\SourceLocation($metadata->location->file,
                            new \Mago\Sdk\Span($metadata->location->span->start, $metadata->location->span->end - 1));
                    } elseif ($variant === 'identifier') {
                        $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier(
                            $metadata->identifier->kind, 'unrelated', $metadata->identifier->class);
                    } elseif ($variant === 'parameter-count') {
                        $values['parameters'] = [];
                    } else {
                        $parameter = get_object_vars($metadata->parameters[0]);
                        if ($variant === 'parameter-name') { $parameter['name'] = '$others'; }
                        if ($variant === 'parameter-span') {
                            $location = $parameter['nameLocation'];
                            $parameter['nameLocation'] = new \Mago\Sdk\SourceLocation($location->file,
                                new \Mago\Sdk\Span($location->span->start + 1, $location->span->end));
                        }
                        if ($variant === 'parameter-reference') {
                            $parameter['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags(
                                $parameter['flags']->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE);
                        }
                        $values['parameters'][0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$parameter);
                    }
                    $changedMetadata = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                    $replaced = 0;
                    foreach ($snapshot as $operation => $entries) {
                        foreach ($entries as $key => $entry) {
                            if ($entry === $metadata) {
                                $cache->values[$operation][$key] = $changedMetadata;
                                $replaced++;
                            }
                        }
                    }
                    try {
                        if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) {
                            throw new \RuntimeException('Stale callable metadata accepted: '.$variant);
                        }
                    } finally {
                        $cache->values = $snapshot;
                    }
                }
                if ($this->filter->filterIssue($context) !== $result) { throw new \RuntimeException('Changed cached source identity.'); }
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'foreach-return-fixture', name: 'Foreach return fixture', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP;
file_put_contents($workspace.'/worker.php', $worker);
file_put_contents($workspace.'/control-worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('foreach-control', 'Foreach control', 'Existing Laravel policies without the tested rule');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        (new \Ichinya\Laramago\Analyzer\LaravelPlugin($this->root))->register($registry);
        $property = new \ReflectionProperty($registry, 'issueFilterHooks');
        $hooks = array_filter($property->getValue($registry), static fn ($hook): bool => ! $hook instanceof \Ichinya\Laramago\Analyzer\ValidatedForeachReturnFilter);
        $property->setValue($registry, array_values($hooks));
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'foreach-control', name: 'Foreach control', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP);
$guard = 'if (! is_array($values) || ! array_is_list($values)) { throw new \\RuntimeException; }';
$loop = 'foreach ($values as $item) { if (! is_string($item)) { throw new \\RuntimeException; } }';
$body = $guard.$loop.'return $values;';
// The exact native diagnostic set is retained for every unsupported or invalid case.
$cases = [
    'guarded strings' => [$body, 'mixed', 'list<string>', '', true],
    'empty list allowed' => [$loop.'return $values;', 'list<mixed>', 'list<string>', '', true],
    'integer elements' => [str_replace('is_string', 'is_int', $loop).'return $values;', 'list<mixed>', 'list<int>', '', true],
    'float elements' => [str_replace('is_string', 'is_float', $loop).'return $values;', 'list<mixed>', 'list<float>', '', true],
    'boolean elements' => [str_replace('is_string', 'is_bool', $loop).'return $values;', 'list<mixed>', 'list<bool>', '', true],
    'string keys preserved' => [$loop.'return $values;', 'array<string, mixed>', 'array<string, string>', '', true],
    'integer keys preserved' => [$loop.'return $values;', 'array<int, mixed>', 'array<int, string>', '', true],
    'fully qualified checks' => [str_replace(['is_array(', 'array_is_list(', 'is_string('], ['\\is_array(', '\\array_is_list(', '\\is_string('], $body), 'mixed', 'list<string>', '', true],
    'imported checks' => [str_replace('is_string(', 'text(', $body), 'mixed', 'list<string>', 'use function is_string as text;', true],
    'method declaration' => [$body, 'mixed', 'list<string>', '', true],
    'wrong value type' => [$body, 'mixed', 'list<int>', '', false],
    'wrong key type' => [$loop.'return $values;', 'array<int, mixed>', 'array<string, string>', '', false],
    'empty cannot prove nonempty' => [$body, 'mixed', 'non-empty-list<string>', '', false],
    'array cannot prove list' => [str_replace(' || ! array_is_list($values)', '', $body), 'mixed', 'list<string>', '', false],
    'unguarded unknown input' => [$loop.'return $values;', 'mixed', 'list<string>', '', false],
    'early break' => [str_replace('throw new \\RuntimeException;', 'break;', $loop).'return $values;', 'list<mixed>', 'list<string>', '', false],
    'early continue' => [str_replace('throw new \\RuntimeException;', 'continue;', $loop).'return $values;', 'list<mixed>', 'list<string>', '', false],
    'early return' => [str_replace('throw new \\RuntimeException;', 'return $values;', $loop).'return $values;', 'list<mixed>', 'list<string>', '', false],
    'caught guard failure' => ['try {'.$loop.'} catch (\\Throwable) {} return $values;', 'list<mixed>', 'list<string>', '', false],
    'root append' => [$loop.'$values[] = 7; return $values;', 'list<mixed>', 'list<string>', '', false],
    'root replacement' => [$loop.'$values = [7]; return $values;', 'list<mixed>', 'list<string>', '', false],
    'element mutation' => [str_replace('foreach ($values as $item) {', 'foreach ($values as $item) { $item = "safe";', $loop).'return $values;', 'list<mixed>', 'list<string>', '', false],
    'root mutation in loop' => [str_replace('foreach ($values as $item) {', 'foreach ($values as $item) { $values[] = 7;', $loop).'return $values;', 'list<mixed>', 'list<string>', '', false],
    'reference iteration' => [str_replace('as $item', 'as &$item', $loop).'return $values;', 'list<mixed>', 'list<string>', '', false],
    'reference input' => [$body, 'mixed', 'list<string>', '', false],
    'reference return' => [$body, 'mixed', 'list<string>', '', false],
    'reference alias' => ['$alias =& $values;'.$body, 'mixed', 'list<string>', '', false],
    'key variable collision' => [str_replace('as $item', 'as $values => $item', $loop).'return $values;', 'list<mixed>', 'list<string>', '', false],
    'existing element variable' => ['$item = new \\stdClass;'.$body, 'mixed', 'list<string>', '', false],
    'element parameter' => [$body, 'mixed', 'list<string>', '', false],
    'callback between loop and return' => [$loop.'touchValues($values); return $values;', 'list<mixed>', 'list<string>', 'function touchValues(array &$values): void { $values[] = 7; }', false],
    'closure capture before loop' => ['$callback = function () use (&$values): void { $values[] = 7; };'.$body, 'mixed', 'list<string>', '', false],
    'shadowed element checker' => [$body, 'mixed', 'list<string>', 'function is_string(mixed $value): bool { return true; }', false],
    'shadowed array checker' => [$body, 'mixed', 'list<string>', 'function is_array(mixed $value): bool { return true; }', false],
    'shadowed list checker' => [$body, 'mixed', 'list<string>', 'function array_is_list(mixed $value): bool { return true; }', false],
    'nonthrowing guard' => [str_replace('throw new \\RuntimeException;', 'new \\RuntimeException;', $loop).'return $values;', 'list<mixed>', 'list<string>', '', false],
    'inverted element guard' => [str_replace('! is_string', 'is_string', $loop).'return $values;', 'list<mixed>', 'list<string>', '', false],
    'conditional loop' => ['if (random_int(0, 1)) {'.$loop.'} return $values;', 'list<mixed>', 'list<string>', '', false],
    'dynamic variables' => ['${"item"} =& $values;'.$body, 'mixed', 'list<string>', '', false],
    'extract aliases' => ['extract(["item" => &$values], EXTR_REFS);'.$body, 'mixed', 'list<string>', '', false],
    'different return value' => [$loop.'return $others;', 'list<mixed>', 'list<string>', '', false],
];
$files = [];
foreach ($cases as $name => [$code, $input, $output, $extra, $accepted]) {
    $index = count($files);
    $file = 'case-'.$index.'.php';
    $parameterReference = $name === 'reference input' ? '&' : '';
    $returnReference = $name === 'reference return' ? '&' : '';
    $more = $name === 'element parameter' ? ', mixed $item' : '';
    $native = $input === 'mixed' ? 'mixed' : 'array';
    $function = "/**\n * @param ".$input.' $values'."\n * @return ".$output."\n */\n".'function '.$returnReference.'validated('.$native.' '.$parameterReference.'$values'.$more.'): array {'.$code.'}';
    if ($name === 'method declaration') {
        $function = 'class Fixture { '.$function.' }';
    }
    file_put_contents($workspace.'/'.$file, "<?php\nnamespace Fixture\\Case".$index.";\n".$extra."\n".$function."\n");
    $files[$file] = [$name, $accepted];
}
$reports = [];
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach (['native', 'isolated', 'control', 'integrated'] as $mode) {
    if (in_array($mode, ['control', 'integrated'], true) && ! in_array('--integrated', $argv, true)) {
        continue;
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => array_keys($files)],
        'extension-hosts' => $mode === 'native' ? new stdClass : ['fixture' => [
            'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', match ($mode) {
                'integrated' => $package.'/bin/laramago-worker.php',
                'control' => $workspace.'/control-worker.php',
                default => $workspace.'/worker.php',
            }, $package.'/vendor/autoload.php', $workspace],
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
    if ($accepted && ! in_array('less-specific-nested-return-statement', array_column($native, 'code'), true)) {
        throw new RuntimeException('Missing native control for '.$name.'; inspect '.$workspace);
    }
    foreach (array_keys($reports) as $mode) {
        if ($mode === 'control') {
            continue;
        }
        $baseline = $mode === 'integrated' ? ($reports['control'][$file] ?? []) : $native;
        if ($accepted && ! in_array('less-specific-nested-return-statement', array_column($baseline, 'code'), true)) {
            throw new RuntimeException('Missing '.$mode.' control for '.$name.'; inspect '.$workspace);
        }
        $expected = $accepted && $mode !== 'native'
            ? array_values(array_filter($baseline, static fn (array $issue): bool => $issue['code'] !== 'less-specific-nested-return-statement')) : $baseline;
        if ($normalize($reports[$mode][$file] ?? []) !== $normalize($expected)) {
            throw new RuntimeException('Unexpected '.$mode.' delta for '.$name.'; inspect '.$workspace);
        }
        echo 'PASS: '.$mode.' '.$name.PHP_EOL;
    }
}
