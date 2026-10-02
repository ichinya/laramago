<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago model refresh '.bin2hex(random_bytes(8));
$nativePath = '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php';
mkdir(dirname($workspace.$nativePath), recursive: true);
file_put_contents($workspace.'/composer.json', '{"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
$model = <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
abstract class Model {
    /** @param array|string $with
     * @return static|null */
    public function fresh($with = []) {
        if (! $this->exists) { return; }
        return $this->setKeysForSelectQuery($this->newQueryWithoutScopes())
            ->useWritePdo()->with(is_string($with) ? func_get_args() : $with)->first();
    }
    /** @return $this */
    public function refresh() {
        if (! $this->exists) { return $this; }
        return $this->refreshUsingQuery($this->newQueryWithoutScopes());
    }
}
PHP;
file_put_contents($workspace.$nativePath, $model);
file_put_contents($workspace.'/types.php', <<<'PHP'
<?php
namespace Fixtures;
class Item extends \Illuminate\Database\Eloquent\Model {}
class Overridden extends \Illuminate\Database\Eloquent\Model {
    /** @return \Illuminate\Database\Eloquent\Model|null */
    public function fresh($with = []) { return null; }
}
function acceptString(string $value): void {}
function effect(): void {}
PHP);
$doc = "/**\n * @template T of Model\n * @param T \$value\n * @return T\n */\n";
$nullableDoc = str_replace('@return T', '@return T|null', $doc);
$cases = [
    'refresh function' => [$doc.'function refreshed(Model $value): Model { return $value->refresh(); }', true],
    'guarded fresh' => [$doc.'function reloaded(Model $value): Model { $copy = $value->fresh(); if ($copy === null) { throw new \\RuntimeException(); } return $copy; }', true],
    'nullable fresh' => [$nullableDoc.'function nullableReloaded(Model $value): ?Model { return $value->fresh(); }', true],
    'method caller' => ['class Reader { '.$doc.'private function read(Model $value): Model { $copy = $value->fresh(); if ($copy === null) { throw new \\RuntimeException(); } return $copy; } }', true],
    'static method caller' => ['class StaticReader { '.$doc.'public static function read(Model $value): Model { return $value->refresh(); } }', true],
    'different template name' => [str_replace(['@template T', '@param T', '@return T'], ['@template U', '@param U', '@return U'], $doc).'function anotherOwner(Model $value): Model { return $value->refresh(); }', true],
    'second parameter' => [$doc.'function second(int $first, Model $value): Model { return $value->refresh(); }', true],
    'fresh remains nullable' => [$doc.'function unsafeFresh(Model $value): Model { return $value->fresh(); }', false],
    'wrong downstream type' => [$doc.'function wrongUse(Model $value): Model { $copy = $value->refresh(); acceptString($copy); return $copy; }', false],
    'parameter mutation before' => [$doc.'function mutationBefore(Model $value): Model { $value = new Item(); return $value->refresh(); }', false],
    'parameter mutation after' => [$doc.'function mutationAfter(Model $value): Model { $copy = $value->refresh(); $value = new Item(); return $copy; }', false],
    'alias before' => [$doc.'function aliasBefore(Model $value): Model { $alias =& $value; return $value->refresh(); }', false],
    'alias after' => [$doc.'function aliasAfter(Model $value): Model { $copy = $value->refresh(); $alias =& $value; return $copy; }', false],
    'reference parameter' => [$doc.'function reference(Model &$value): Model { return $value->refresh(); }', false],
    'unrelated reference parameter' => [$doc.'function otherReference(Model $value, int &$other): Model { return $value->refresh(); }', false],
    'reference return' => [$doc.'function &referenceReturn(Model $value): Model { return $value->refresh(); }', false],
    'closure capture' => [$doc.'function captured(Model $value): Model { $copy = $value->refresh(); $callback = function () use (&$value): void {}; return $copy; }', false],
    'nested closure return' => [$doc.'function closureReturn(Model $value): Model { return (function () use ($value): Model { return $value->refresh(); })(); }', false],
    'prior call' => [$doc.'function priorCall(Model $value): Model { effect(); return $value->refresh(); }', false],
    'branch scope' => [$doc.'function branched(Model $value): Model { if (true) { return $value->refresh(); } throw new \\RuntimeException(); }', false],
    'arguments deferred' => [$nullableDoc.'function withRelations(Model $value): ?Model { return $value->fresh([]); }', false],
    'nullsafe deferred' => [str_replace('@param T', '@param T|null', $nullableDoc).'function nullsafe(?Model $value): ?Model { return $value?->fresh(); }', false],
    'overridden model method' => [str_replace('T of Model', 'T of Overridden', $nullableDoc).'function overridden(Overridden $value): ?Model { return $value->fresh(); }', false],
    'dynamic variable scope' => [$doc.'function dynamicVariable(Model $value, string $name): Model { $copy = $value->refresh(); $$name = 1; return $copy; }', false],
    'extract alias scope' => [$doc.'function extraction(Model $value, array $data): Model { $copy = $value->refresh(); hydrate($data); return $copy; }', false],
    'global escape' => [$doc.'function globalEscape(Model $value): Model { $copy = $value->refresh(); global $other; return $copy; }', false],
    'unrelated model return' => [$doc.'function unrelated(Model $value): Model { $copy = $value->refresh(); return new Item(); }', false],
];
$source = "<?php\nnamespace Fixtures;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse function extract as hydrate;\n";
$ranges = [];
foreach ($cases as $label => [$code, $positive]) {
    $start = substr_count($source, "\n");
    $source .= $code."\n";
    $ranges[$label] = [$start, substr_count($source, "\n")];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('test/model-refresh', 'Model refresh templates', 'Template identity checks'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $provider = new \Ichinya\Laramago\Analyzer\ModelRefreshReturnTypeProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerMethodReturnTypeProvider(new class($provider, $this->root) implements \Mago\Sdk\Analyzer\MethodReturnTypeProvider {
            public function __construct(private readonly \Ichinya\Laramago\Analyzer\ModelRefreshReturnTypeProvider $provider, private readonly string $root) {}
            public function getTargets(): array { return $this->provider->getTargets(); }
            public function getReturnType(\Mago\Sdk\Analyzer\ReturnTypeProviderContext $context): ?\Mago\Sdk\Analyzer\Type {
                $type = $this->provider->getReturnType($context);
                if ($type === null) { return null; }
                foreach ($type->atomicTypes as $atomic) {
                    if ($atomic instanceof \Mago\Sdk\Analyzer\Type\GenericParameterType) {
                        file_put_contents($this->root.'/templates.jsonl', json_encode(['start' => $context->invocation->span->start,
                            'template' => $atomic->name, 'owner' => $atomic->definingEntity->name, 'member' => $atomic->definingEntity->member], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
                    }
                }
                // Mago currently accepts same-bound foreign templates. Independently
                // prove that corrupted lexical metadata cannot select such a template.
                $property = new \ReflectionProperty($this->provider->calls, 'calls');
                $inventory = $property->getValue($this->provider->calls);
                $key = $context->invocation->span->start.':'.$context->invocation->span->end;
                foreach (['file' => '/different-source.php', 'name' => 'Fixtures\\anotherOwner', 'parameter' => 999] as $field => $value) {
                    $changed = $inventory;
                    $changed[$key][$field] = $value;
                    if ($changed[$key] === $inventory[$key]) { continue; }
                    $property->setValue($this->provider->calls, $changed);
                    if ($this->provider->getReturnType($context) !== null) {
                        throw new \RuntimeException('Foreign caller metadata was accepted.');
                    }
                }
                $property->setValue($this->provider->calls, $inventory);
                return $type;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/model-refresh', 'Model refresh templates', '1', analyzerPlugins: [$plugin])))->run();
PHP);
$modes = ['native', 'isolated'];
if (in_array('--integrated', $argv, true)) {
    $modes[] = 'integrated';
}
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$reports = [];
foreach ($modes as $mode) {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => [ltrim($nativePath, '/'), 'types.php']],
        'extension-hosts' => $mode === 'native' ? new stdClass : ['test' => [
            'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 2,
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
    $report = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true);
    if (! is_array($report) || ! isset($report['issues'])) {
        throw new RuntimeException("Mago $mode failed ($exit): ".file_get_contents($workspace.'/'.$mode.'.log'));
    }
    foreach ($report['issues'] as $issue) {
        if (str_contains($issue['code'], 'extension')) {
            throw new RuntimeException('Extension failure: '.json_encode($issue));
        }
        $span = $issue['annotations'][0]['span'] ?? [];
        if ($issue['level'] !== 'Error' || ($span['file_id']['name'] ?? '') !== 'cases.php') {
            continue;
        }
        $line = $span['start']['line'] ?? -1;
        foreach ($ranges as $label => [$start, $end]) {
            if ($line >= $start && $line < $end) {
                $reports[$mode][$label][] = $issue['code'];
            }
        }
    }
    foreach ($cases as $label => [$code, $positive]) {
        $errors = $reports[$mode][$label] ?? [];
        if (($positive && $mode !== 'native') ? $errors !== [] : $errors === []) {
            throw new RuntimeException("Unexpected $mode result for $label: ".json_encode($errors)."; workspace $workspace");
        }
    }
    echo "$mode: ".count($cases)." model refresh cases verified\n";
}
$templates = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($workspace.'/templates.jsonl', FILE_IGNORE_NEW_LINES));
foreach (['refreshed' => ['T', '', 'Fixtures\\refreshed'], 'anotherOwner' => ['U', '', 'Fixtures\\anotherOwner'],
    'private function read' => ['T', 'Fixtures\\Reader', 'read'], 'public static function read' => ['T', 'Fixtures\\StaticReader', 'read']] as $marker => [$template, $owner, $member]) {
    $begin = strpos($source, $marker);
    $position = strpos($source, '$value->', $begin);
    $matching = array_filter($templates, static fn (array $entry): bool => $entry['start'] === $position);
    if ($matching === []) {
        throw new RuntimeException('No template identity evidence for '.$marker);
    }
    foreach ($matching as $entry) {
        if ($entry['template'] !== $template || strcasecmp($entry['owner'], $owner) !== 0 || strcasecmp($entry['member'], $member) !== 0) {
            throw new RuntimeException('Wrong template identity: '.json_encode($entry));
        }
    }
}
echo "Template provenance: distinct function and method owners verified; corrupted source metadata rejected\n";
foreach ($cases as $label => [$code, $positive]) {
    if ($positive || $label === 'wrong downstream type') {
        continue;
    }
    foreach (array_slice($modes, 1) as $mode) {
        $remaining = $reports[$mode][$label];
        foreach ($reports['native'][$label] as $code) {
            $position = array_search($code, $remaining, true);
            if ($position === false) {
                throw new RuntimeException("Native $code disappeared from $mode negative case $label");
            }
            unset($remaining[$position]);
        }
    }
}
echo "Model refresh templates passed. Workspace: $workspace\n";

// Changed implementations and PHPDoc must not receive a synthesized contract.
$variants = [
    'changed body' => [str_replace('if (! $this->exists)', 'if ($this->exists)', $model), $nativePath, ['fresh', 'refresh']],
    'changed fresh doc' => [str_replace('@return static|null', '@return Model|null', $model), $nativePath, ['fresh']],
    'changed refresh doc' => [str_replace('@return $this', '@return Model', $model), $nativePath, ['refresh']],
    'wrong source location' => [$model, '/custom-model.php', ['fresh', 'refresh']],
];
foreach ($variants as $label => [$fixture, $location, $blocked]) {
    file_put_contents($workspace.$location, $fixture);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => [ltrim($location, '/'), 'types.php']],
        'extension-hosts' => ['test' => ['command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace], 'workers' => 2]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/variant.json', 'w'], 2 => ['file', $workspace.'/variant.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start native contract variant.');
    }
    fclose($pipes[0]);
    proc_close($process);
    $issues = json_decode(file_get_contents($workspace.'/variant.json'), true)['issues'] ?? null;
    if (! is_array($issues)) {
        throw new RuntimeException('Variant report unavailable: '.$label);
    }
    foreach ($cases as $case => [$code, $positive]) {
        if (! $positive) {
            continue;
        }
        [$start, $end] = $ranges[$case];
        $errors = array_filter($issues, static function (array $issue) use ($start, $end): bool {
            $span = $issue['annotations'][0]['span'] ?? [];
            return $issue['level'] === 'Error' && ($span['file_id']['name'] ?? '') === 'cases.php'
                && ($span['start']['line'] ?? -1) >= $start && ($span['start']['line'] ?? -1) < $end;
        });
        $method = str_contains($code, '->fresh()') ? 'fresh' : 'refresh';
        if (($errors !== []) !== in_array($method, $blocked, true)) {
            throw new RuntimeException("Unexpected $label behavior for $case; workspace $workspace");
        }
    }
}
echo "Native contract: implementation, return documentation, and source location changes rejected\n";

require $package.'/vendor/autoload.php';
$cancel = new class implements \Mago\Sdk\CancellationTokenInterface {
    public bool $cancelled = false;
    public function isCancelled(): bool { return $this->cancelled; }
    public function throwIfCancelled(): void { if ($this->cancelled) { throw new RuntimeException('Scan cancelled.'); } }
    public function subscribe(Closure $callback): int { return 0; }
    public function unsubscribe(int $subscription): void {}
};
$version = \Mago\Sdk\PHPVersion::fromParts(8, 2);
$file = static function (string $path, string $contents) use ($version): \Mago\Sdk\Syntax\SourceFile {
    return new \Mago\Sdk\Syntax\SourceFile($version, $path, $contents, [],
        (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
};
$index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelRefreshCalls;
$snapshot = '<?php function read($value) { return $value->fresh(); }';
$start = strpos($snapshot, '$value->fresh');
$span = new \Mago\Sdk\Span($start, $start + strlen('$value->fresh()'));
$scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $cancel): void {
    $index->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($version, $cancel, $files, $first, $last));
};
$checks = 0;
$expect = static function (bool $known) use ($index, $span, &$checks): void {
    if (($index->call($span) !== null) !== $known) {
        throw new RuntimeException('Unexpected source inventory or caller identity state.');
    }
    $checks++;
};
$expect(false);
$scan([$file('/unsaved.php', $snapshot)], last: false);
$expect(false);
$scan([], first: false);
$expect(true);
$scan([$file('/first.php', $snapshot), $file('/second.php', $snapshot)]);
$expect(false);
$scan([$file('/first.php', $snapshot)], last: false);
$scan([$file('/second.php', $snapshot)], first: false);
$expect(false);
foreach (['$value->other', 'unrelated____', 'new Other____'] as $replacement) {
    $scan([$file('/first.php', $snapshot), $file('/unrelated.php', str_replace('$value->fresh', $replacement, $snapshot))]);
    $expect(false);
}
$scan([$file('/unsaved.php', $snapshot), $file('/broken.php', '<?php function broken( {')]);
$expect(false);
$scan([$file('/unsaved.php', $snapshot), $file('/large.php', '<?php /*'.str_repeat('x', 2_000_000).'*/')]);
$expect(false);
$scan([], last: false);
$inventory = new ReflectionProperty($index, 'calls');
$inventory->setValue($index, array_fill_keys(array_map(static fn (int $offset): string => '-'.$offset.':0', range(1, 100_000)), null));
$scan([$file('/first.php', $snapshot)], first: false);
$expect(false);
$scan([$file('/unsaved.php', $snapshot)]);
$expect(true);
$scan([$file('/unsaved.php', str_replace('->fresh()', '->other()', $snapshot))]);
$expect(false);
$scan([$file('/unsaved.php', $snapshot)]);
$expect(true);
$cancel->cancelled = true;
try {
    $scan([$file('/unsaved.php', $snapshot)], first: false);
    throw new LogicException('Cancellation was ignored.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Scan cancelled.') {
        throw $error;
    }
}
$expect(false);
$cancel->cancelled = false;
$scan([$file('/unsaved.php', $snapshot)]);
$expect(true);
echo "Source inventory: $checks batching, collision, bounds, cancellation, and snapshot checks verified\n";
