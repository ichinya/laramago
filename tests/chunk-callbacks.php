<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$integrated = in_array('--integrated', $argv, true);
$modes = array_values(array_filter(array_slice($argv, 1), static fn (string $argument): bool => $argument !== '--integrated'));
$mode = $modes[0] ?? 'native';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago chunk callbacks '.bin2hex(random_bytes(8));
$illuminate = $workspace.'/laravel/framework/src/Illuminate';
$framework = $illuminate.'/Database';
mkdir($framework.'/Concerns', 0o777, true);
mkdir($framework.'/Eloquent', 0o777, true);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap test projects.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$trait = file_get_contents(__DIR__.'/fixtures/analysis/chunk-callbacks-trait.php.stub');
if ($mode === 'changed-body') {
    $trait = str_replace('$callback($results, $page)', '$callback("custom", $page)', $trait);
}
if ($mode === 'changed-doc') {
    $trait = str_replace('Collection<int, TValue>', 'Collection<int, string>', $trait);
}
file_put_contents($framework.'/Concerns/BuildsQueries.php', $trait);
file_put_contents($framework.'/Eloquent/Builder.php', file_get_contents(__DIR__.'/fixtures/analysis/chunk-callbacks-builder.php.stub'));
file_put_contents($framework.'/Eloquent/Model.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
class Model {
    protected static string $builder = Builder::class;
    /** @return Builder<static> */
    public static function query() {}
    /** @return Builder<static> */
    public function newQuery() {}
    /** @return Builder<static> */
    public function newModelQuery() {}
    /** @return Builder<static> */
    public function newQueryWithoutScopes() {}
    /** @return Builder<static> */
    public function newQueryWithoutRelationships() {}
    public function __call(string $method, array $arguments): mixed {}
    public static function __callStatic(string $method, array $arguments): mixed {}
    /** @return Builder<static> */
    public function newEloquentBuilder($query) {}
    /** @return Collection<int, static> */
    public function newCollection(array $models = []) { return new Collection($models); }
}
PHP);
file_put_contents($illuminate.'/collections.php', <<<'PHP'
<?php
namespace Illuminate\Support;
/**
 * @template TKey of array-key
 * @template TValue
 * @implements \IteratorAggregate<TKey, TValue>
 */
class Collection implements \IteratorAggregate {
    /** @param array<TKey, TValue> $items */
    public function __construct(array $items = []) {}
    /** @return \Traversable<TKey, TValue> */
    public function getIterator(): \Traversable { yield from []; }
}
namespace Illuminate\Database\Eloquent;
/**
 * @template TKey of array-key = array-key
 * @template TModel of Model = Model
 * @extends \Illuminate\Support\Collection<TKey, TModel>
 */
class Collection extends \Illuminate\Support\Collection {}
namespace Illuminate\Database\Query;
class Builder {
    /** @use \Illuminate\Database\Concerns\BuildsQueries<\stdClass> */
    use \Illuminate\Database\Concerns\BuildsQueries;
}
PHP);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
use Ichinya\Laramago\Analyzer\EloquentChunkCallbackProvider;
use Ichinya\Laramago\Analyzer\LaravelPlugin;
use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
final class ObservedChunks implements CallableSignatureOverride, MethodReturnTypeProvider, InitializationHook {
    private readonly EloquentChunkCallbackProvider $inner;
    public function __construct(string $root, private readonly string $audit) { $this->inner = new EloquentChunkCallbackProvider($root); }
    public function initialize(InitializationContext $context): void { $this->inner->initialize($context); }
    public function getTargets(): array { return $this->inner->getTargets(); }
    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature {
        $signature = $this->inner->getCallableSignature($context);
        file_put_contents($this->audit, json_encode([
            'start' => $context->invocation->span->start,
            'signature' => $signature === null ? null : (string) $signature->parameters[1]->type,
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        return $signature;
    }
    public function getReturnType(ReturnTypeProviderContext $context): ?Type { return $this->inner->getReturnType($context); }
}
final class ChunkPlugin implements Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('chunks', 'Chunks', 'Test-only chunk contracts.'); }
    public function register(PluginRegistry $registry): void {
        $provider = new ObservedChunks($this->root, $this->root.'/audit.jsonl');
        $registry->registerMethodReturnTypeProvider($provider);
        $registry->registerInitializationHook($provider);
    }
}
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(
    identifier: 'chunks', name: 'Chunks', version: '1',
    analyzerPlugins: [$argv[3] === 'integrated' ? new LaravelPlugin($argv[2]) : new ChunkPlugin($argv[2])],
)))->run();
PHP);
$cases = in_array($mode, ['changed-body', 'changed-doc'], true) ? [
    'changed implementation defers' => ['$builder->chunkById(10, function (Collection $items) { foreach ($items as $item) { $item->label(); } });', ['invalid-argument', 'non-documented-method'], false],
] : [
    'typed collection callback is accepted' => ['$builder->chunkById(10, function (Collection $items) {});', [], true],
    'explicit collection generics keep native body analysis' => ['$builder->chunkById(10, function (Collection $items) { acceptItems($items); foreach ($items as $item) { acceptString($item->label()); } });', ['less-specific-argument', 'mixed-argument', 'non-documented-method'], true],
    'chunk narrows inferred collection elements' => ['$builder->chunk(10, function ($items, $page) { acceptItems($items); acceptInt($page); foreach ($items as $item) { acceptString($item->label()); } });', [], true],
    'chunkByIdDesc narrows collection' => ['$builder->chunkByIdDesc(10, fn ($items) => acceptItems($items));', [], true],
    'orderedChunkById narrows collection' => ['$builder->orderedChunkById(10, fn ($items) => acceptItems($items));', [], true],
    'named callback narrows collection' => ['$builder->chunkById(callback: fn ($items) => acceptItems($items), count: 10);', [], true],
    'static model forwards chunk' => ['Record::chunkById(10, fn ($items) => acceptItems($items));', [], true],
    'custom collection type is retained' => ['$customCollection->chunkById(10, fn ($items) => $items->special());', [], true],
    'wrong callback parameter remains invalid' => ['$builder->chunkById(10, fn (string $items) => acceptString($items));', ['invalid-argument'], true],
    'wrong count remains invalid' => ['$builder->chunkById("wrong", fn ($items) => acceptItems($items));', ['invalid-argument'], true],
    'wrong page type remains invalid' => ['$builder->chunkById(10, function ($items, string $page) {});', ['invalid-argument'], true],
    'unknown item method remains invalid' => ['$builder->chunkById(10, function ($items) { foreach ($items as $item) { $item->missing(); } });', ['non-documented-method'], true],
    'query builder keeps support collection' => ['$query->chunkById(10, function (Collection $items) {});', ['invalid-argument'], false],
    'custom builder declaration wins' => ['$override->chunkById(10, fn (string $items) => acceptString($items));', [], false],
    'custom model builder defers' => ['$customBuilderModel->chunkById(10, function (Collection $items) {});', ['invalid-argument'], false],
    'model method override wins' => ['OverriddenRecord::chunkById(10, fn (string $items) => acceptString($items));', [], false],
    'unknown custom collection defers' => ['$unknownCollection->chunkById(10, function (Collection $items) {});', ['invalid-argument'], false],
    'explicit model PHPDoc method wins' => ['DocumentedRecord::chunkById(10, fn (string $items) => acceptString($items));', [], false],
    'wrong named argument remains invalid' => ['$builder->chunkById(count: 10, callback: fn ($items) => acceptItems($items), typo: "id");', ['invalid-named-argument'], true],
];
$source = <<<'PHP'
<?php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
class Record extends Model { public function label(): string { return 'label'; } }
class CustomBuilderModel extends Record { public function newEloquentBuilder($query): Builder { return new Builder; } }
/** @extends Collection<int, CustomCollectionRecord> */
class RecordCollection extends Collection { public function special(): void {} }
class CustomCollectionRecord extends Record {
    /** @return RecordCollection */
    public function newCollection(array $models = []): RecordCollection { return new RecordCollection($models); }
}
class UnknownCollectionRecord extends Record {
    /** @return mixed */
    public function newCollection(array $models = []): mixed { return $models; }
}
class OverriddenRecord extends Record {
    /** @param callable(string): void $callback */
    public static function chunkById(int $count, callable $callback): bool { return true; }
}
/** @method static bool chunkById(int $count, callable(string): void $callback) */
class DocumentedRecord extends Record {}
/** @extends Builder<Record> */
class CustomBuilder extends Builder {
    /** @param callable(string): void $callback */
    public function chunkById($count, callable $callback, $column = null, $alias = null): bool { return true; }
}
/** @param Collection<int, Record> $items */
function acceptItems(Collection $items): void {}
function acceptString(string $value): void {}
function acceptInt(int $value): void {}
PHP;
file_put_contents($illuminate.'/test-declarations.php', $source);
$source = <<<'PHP'
<?php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
PHP;
$lines = [];
foreach ($cases as $name => [$body, $expected, $bind]) {
    $source .= "/**\n * @param Builder<Record> \$builder\n * @param Builder<CustomCollectionRecord> \$customCollection\n * @param Builder<UnknownCollectionRecord> \$unknownCollection\n * @param Builder<CustomBuilderModel> \$customBuilderModel\n */\n";
    $source .= 'function scenario'.count($lines).'(Builder $builder, Builder $customCollection, Builder $unknownCollection, Builder $customBuilderModel, CustomBuilder $override, Illuminate\\Database\\Query\\Builder $query): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $expected, $bind];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => [$illuminate]],
    'extension-hosts' => ['chunks' => [
        'command' => [PHP_BINARY, $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace, $integrated ? 'integrated' : 'standalone'],
        'workers' => 1,
    ]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Unexpected Mago result; inspect '.$workspace.' (exit '.$exit.'): '.$log);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
    $actual[$primary['span']['file_id']['name']][$primary['span']['start']['line'] + 1][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual['cases.php'][$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException($name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace);
    }
    unset($actual['cases.php'][$line]);
    echo 'PASS: '.$name."\n";
}
if (array_filter($actual)) {
    throw new RuntimeException('Unexpected diagnostics outside scenarios: '.json_encode($actual).'; inspect '.$workspace);
}
if (! $integrated) {
    $observed = [];
    foreach (file($workspace.'/audit.jsonl', FILE_IGNORE_NEW_LINES) ?: [] as $record) {
        $entry = json_decode($record, true, flags: JSON_THROW_ON_ERROR);
        $line = substr_count(substr($source, 0, $entry['start']), "\n") + 1;
        $observed[$line][] = $entry['signature'];
    }
    foreach ($lines as $line => [$name, , $bind]) {
        $bound = array_filter($observed[$line] ?? [], static fn (?string $signature): bool => $signature !== null);
        if ($bind !== ($bound !== [])) {
            throw new RuntimeException('Unexpected signature binding for '.$name.': '.json_encode($observed[$line] ?? []).'; inspect '.$workspace);
        }
    }
}
$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($items as $item) {
    $path = $item->getPathname();
    if (! str_starts_with($path, $resolved.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $item->isDir() ? rmdir($path) : unlink($path);
}
rmdir($resolved);
