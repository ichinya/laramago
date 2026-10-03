<?php

declare(strict_types=1);

// Analyze declarations only; no application bootstrap or database is used.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago nonempty collection '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
// Native excerpts: Laravel v13.31.0, commit 7c75fbf93f91fa077d3df1c820cc14f4e59a9774.
// Copyright Taylor Otwell, MIT; full terms in fixtures/analysis/factory-result-LICENSE.md.
$native = '/vendor/laravel/framework/src/';
$fixtures = [
    'Collection' => 'Illuminate/Collections/Collection.php',
    'Arr' => 'Illuminate/Collections/Arr.php',
    'EnumeratesValues' => 'Illuminate/Collections/Traits/EnumeratesValues.php',
    'EloquentCollection' => 'Illuminate/Database/Eloquent/Collection.php',
];
$nativeSources = [
    'Collection' => <<<'NATIVE_Collection'
<?php
namespace Illuminate\Support;
/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @implements \ArrayAccess<TKey, TValue>
 * @implements \Illuminate\Support\Enumerable<TKey, TValue>
 */
class Collection {
use \Illuminate\Support\Traits\EnumeratesValues;
/**
 * The items contained in the collection.
 *
 * @var array<TKey, TValue>
 */
protected $items = [];
/**
 * Create a new collection.
 *
 * @param  \Illuminate\Contracts\Support\Arrayable<TKey, TValue>|iterable<TKey, TValue>|null  $items
 */
public function __construct($items = [])
{
    $this->items = $this->getArrayableItems($items);
}
/**
 * Get the first item from the collection passing the given truth test.
 *
 * @template TFirstDefault
 *
 * @param  (callable(TValue, TKey): bool)|null  $callback
 * @param  TFirstDefault|(\Closure(): TFirstDefault)  $default
 * @return TValue|TFirstDefault
 */
public function first(?callable $callback = null, $default = null)
{
    return \Illuminate\Support\Arr::first($this->items, $callback, $default);
}
/**
 * Determine if the collection is empty or not.
 *
 * @phpstan-assert-if-true null $this->first()
 * @phpstan-assert-if-true null $this->last()
 *
 * @phpstan-assert-if-false TValue $this->first()
 * @phpstan-assert-if-false TValue $this->last()
 *
 * @return bool
 */
public function isEmpty()
{
    return empty($this->items);
}
/**
 * Get the last item from the collection.
 *
 * @template TLastDefault
 *
 * @param  (callable(TValue, TKey): bool)|null  $callback
 * @param  TLastDefault|(\Closure(): TLastDefault)  $default
 * @return TValue|TLastDefault
 */
public function last(?callable $callback = null, $default = null)
{
    return \Illuminate\Support\Arr::last($this->items, $callback, $default);
}
}
NATIVE_Collection,
    'Arr' => <<<'NATIVE_Arr'
<?php
namespace Illuminate\Support;

class Arr {

/**
 * Return the first element in an iterable passing a given truth test.
 *
 * @template TKey
 * @template TValue
 * @template TFirstDefault
 *
 * @param  iterable<TKey, TValue>  $array
 * @param  (callable(TValue, TKey): bool)|null  $callback
 * @param  TFirstDefault|(\Closure(): TFirstDefault)  $default
 * @return TValue|TFirstDefault
 */
public static function first($array, ?callable $callback = null, $default = null)
{
    if (is_null($callback)) {
        if (empty($array)) {
            return value($default);
        }
        if (is_array($array)) {
            return array_first($array);
        }
        foreach ($array as $item) {
            return $item;
        }
        return value($default);
    }
    $array = static::from($array);
    $key = array_find_key($array, $callback);
    return $key !== null ? $array[$key] : value($default);
}
/**
 * Return the last element in an array passing a given truth test.
 *
 * @template TKey
 * @template TValue
 * @template TLastDefault
 *
 * @param  iterable<TKey, TValue>  $array
 * @param  (callable(TValue, TKey): bool)|null  $callback
 * @param  TLastDefault|(\Closure(): TLastDefault)  $default
 * @return TValue|TLastDefault
 */
public static function last($array, ?callable $callback = null, $default = null)
{
    if ($array === null) {
        return value($default);
    }
    $array = static::from($array);
    if (is_null($callback)) {
        return empty($array) ? value($default) : array_last($array);
    }
    return static::first(array_reverse($array, true), $callback, $default);
}
/**
 * Get the underlying array of items from the given argument.
 *
 * @template TKey of array-key = array-key
 * @template TValue = mixed
 *
 * @param  array<TKey, TValue>|Enumerable<TKey, TValue>|Arrayable<TKey, TValue>|WeakMap<object, TValue>|Traversable<TKey, TValue>|Jsonable|JsonSerializable|object  $items
 * @return ($items is WeakMap ? list<TValue> : array<TKey, TValue>)
 *
 * @throws \InvalidArgumentException
 */
public static function from($items)
{
    return match (true) {
        is_array($items) => $items,
        $items instanceof \Illuminate\Support\Enumerable => $items->all(),
        $items instanceof \Illuminate\Contracts\Support\Arrayable => $items->toArray(),
        $items instanceof \WeakMap => iterator_to_array($items, false),
        $items instanceof \Traversable => iterator_to_array($items),
        $items instanceof \Illuminate\Contracts\Support\Jsonable => json_decode($items->toJson(), true),
        $items instanceof \JsonSerializable => (array) $items->jsonSerialize(),
        is_object($items) => (array) $items,
        default => throw new \InvalidArgumentException('Items cannot be represented by a scalar value.'),
    };
}
}
NATIVE_Arr,
    'EnumeratesValues' => <<<'NATIVE_EnumeratesValues'
<?php
namespace Illuminate\Support\Traits;
/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @property-read HigherOrderCollectionProxy<'average', TValue, static> $average
 * @property-read HigherOrderCollectionProxy<'avg', TValue, static> $avg
 * @property-read HigherOrderCollectionProxy<'contains', TValue, static> $contains
 * @property-read HigherOrderCollectionProxy<'doesntContain', TValue, static> $doesntContain
 * @property-read HigherOrderCollectionProxy<'each', TValue, static> $each
 * @property-read HigherOrderCollectionProxy<'every', TValue, static> $every
 * @property-read HigherOrderCollectionProxy<'filter', TValue, static> $filter
 * @property-read HigherOrderCollectionProxy<'first', TValue, static> $first
 * @property-read HigherOrderCollectionProxy<'flatMap', TValue, static> $flatMap
 * @property-read HigherOrderCollectionProxy<'groupBy', TValue, static> $groupBy
 * @property-read HigherOrderCollectionProxy<'hasMany', TValue, static> $hasMany
 * @property-read HigherOrderCollectionProxy<'hasSole', TValue, static> $hasSole
 * @property-read HigherOrderCollectionProxy<'keyBy', TValue, static> $keyBy
 * @property-read HigherOrderCollectionProxy<'last', TValue, static> $last
 * @property-read HigherOrderCollectionProxy<'map', TValue, static> $map
 * @property-read HigherOrderCollectionProxy<'max', TValue, static> $max
 * @property-read HigherOrderCollectionProxy<'min', TValue, static> $min
 * @property-read HigherOrderCollectionProxy<'partition', TValue, static> $partition
 * @property-read HigherOrderCollectionProxy<'percentage', TValue, static> $percentage
 * @property-read HigherOrderCollectionProxy<'reject', TValue, static> $reject
 * @property-read HigherOrderCollectionProxy<'skipUntil', TValue, static> $skipUntil
 * @property-read HigherOrderCollectionProxy<'skipWhile', TValue, static> $skipWhile
 * @property-read HigherOrderCollectionProxy<'some', TValue, static> $some
 * @property-read HigherOrderCollectionProxy<'sortBy', TValue, static> $sortBy
 * @property-read HigherOrderCollectionProxy<'sortByDesc', TValue, static> $sortByDesc
 * @property-read HigherOrderCollectionProxy<'sum', TValue, static> $sum
 * @property-read HigherOrderCollectionProxy<'takeUntil', TValue, static> $takeUntil
 * @property-read HigherOrderCollectionProxy<'takeWhile', TValue, static> $takeWhile
 * @property-read HigherOrderCollectionProxy<'unique', TValue, static> $unique
 * @property-read HigherOrderCollectionProxy<'unless', TValue, static> $unless
 * @property-read HigherOrderCollectionProxy<'until', TValue, static> $until
 * @property-read HigherOrderCollectionProxy<'when', TValue, static> $when
 */
trait EnumeratesValues {

/**
 * Results array of items from Collection or Arrayable.
 *
 * @param  mixed  $items
 * @return array<TKey, TValue>
 */
protected function getArrayableItems($items)
{
    return is_null($items) || is_scalar($items) || $items instanceof \UnitEnum ? \Illuminate\Support\Arr::wrap($items) : \Illuminate\Support\Arr::from($items);
}
}
NATIVE_EnumeratesValues,
    'EloquentCollection' => <<<'NATIVE_EloquentCollection'
<?php
namespace Illuminate\Database\Eloquent;
/**
 * @template TKey of array-key
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends \Illuminate\Support\Collection<TKey, TModel>
 */
class Collection extends \Illuminate\Support\Collection {


}
NATIVE_EloquentCollection,
];
foreach ($fixtures as $label => $path) {
    if (! is_dir(dirname($workspace.$native.$path))) { mkdir(dirname($workspace.$native.$path), recursive: true); }
    file_put_contents($workspace.$native.$path, $nativeSources[$label]);
}
file_put_contents($workspace.'/runtime.php', <<<'PHP'
<?php
require $argv[1].'/Illuminate/Collections/Traits/EnumeratesValues.php';
require $argv[1].'/Illuminate/Collections/Arr.php';
require $argv[1].'/Illuminate/Collections/Collection.php';
class Record {}
function value($default) { return $default instanceof Closure ? $default() : $default; }
function publish(array $locals): void { $GLOBALS['escaped'] = $locals['records']; }
spl_autoload_register(static function (string $class): void {
    if (! in_array($class, ['LateCompactSink', 'LateDefinedSink'], true)) { return; }
    (new ReflectionProperty(Illuminate\Support\Collection::class, 'items'))->setValue($GLOBALS['escaped'], []);
    eval('class '.$class.' { public static function consume(Record $record): void {} }');
});
function compactEscape(): void {
    $records = new Illuminate\Support\Collection([new Record()]);
    publish(compact('records'));
    $records->isEmpty() ? null : LateCompactSink::consume($records->first());
}
function definedVarsEscape(): void {
    $records = new Illuminate\Support\Collection([new Record()]);
    publish(get_defined_vars());
    $records->isEmpty() ? null : LateDefinedSink::consume($records->first());
}
foreach (['compactEscape', 'definedVarsEscape'] as $scenario) {
    try { $scenario(); throw new RuntimeException('Hidden alias was not mutated: '.$scenario); }
    catch (TypeError $error) {
        if (! str_contains($error->getMessage(), 'Record') || ! $GLOBALS['escaped']->isEmpty()) { throw $error; }
        echo $scenario." raises TypeError after autoload clears native collection\n";
    }
}
PHP);
$runtime = proc_open([PHP_BINARY, $workspace.'/runtime.php', $workspace.$native], [
    0 => ['pipe', 'r'], 1 => ['file', $workspace.'/runtime.log', 'w'], 2 => ['file', $workspace.'/runtime-error.log', 'w'],
], $runtimePipes);
if (! is_resource($runtime)) { throw new RuntimeException('Cannot start synthetic runtime.'); }
fclose($runtimePipes[0]);
if (proc_close($runtime) !== 0 || substr_count(file_get_contents($workspace.'/runtime.log'), 'raises TypeError') !== 2) {
    throw new RuntimeException('Hidden alias runtime control failed; inspect '.$workspace);
}
echo "PASS: two hidden alias/autoload runtime mutation controls\n";
file_put_contents($workspace.'/types.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent { class Model {} }
namespace Illuminate\Support {
    /** @template TKey of array-key @template TValue */ interface Enumerable {}
    /** @template TKey of array-key @template TValue */ class LazyCollection extends Collection {}
}
namespace Illuminate\Contracts\Support {
    /** @template TKey of array-key @template TValue */ interface Arrayable {}
    interface Jsonable {}
}
namespace Fixtures {
    class Record extends \Illuminate\Database\Eloquent\Model {}
    /** @extends \Illuminate\Support\Collection<int, Record> */
    class CustomCollection extends \Illuminate\Support\Collection {
        /** @return Record|null */ public function first(?callable $callback = null, $default = null) { return null; }
    }
    function consume(Record $record): void {}
    function effect(): int { return 1; }
    function publish(array $locals): void {}
    /** @return \Illuminate\Support\Collection<int, Record> */
    function opaqueCollection() { return new CustomCollection([new Record()]); }
    /** @return \Illuminate\Database\Eloquent\Collection<int, Record> */
    function queryCollection() { throw new \RuntimeException(); }
    class LateSink { public static function consume(Record $record): void {} }
}
namespace { throw new \RuntimeException('Application fixtures must never execute.'); }
PHP);
$origin = '$records = new Collection([new Record()]); ';
$result = 'return $records->isEmpty() ? new Record() : $records->first();';
$cases = [
    'first' => [$origin.$result, true],
    'last' => [$origin.str_replace('first()', 'last()', $result), true],
    'multiline' => [$origin."return \$records->isEmpty()\n ? new Record()\n : \$records->first();", true],
    'first argument' => [$origin.'$records->isEmpty() ? null : consume($records->first());', true, 'void'],
    'named outer argument' => [$origin.'$records->isEmpty() ? null : consume(record: $records->first());', true, 'void'],
    'native Eloquent allocation' => [str_replace('new Collection(', 'new \\Illuminate\\Database\\Eloquent\\Collection(', $origin).$result, true],
    'named constructor items' => [str_replace('new Collection(', 'new Collection(items: ', $origin).$result, true],
    'unqualified alias' => [str_replace('new Collection(', 'new C(', $origin).$result, true],
    'nullable items' => ['$records = new Collection([$maybe]); '.$result, false, 'Record', '?Record $maybe'],
    'mixed items' => ['$records = new Collection([$value]); '.$result, false, 'Record', 'mixed $value'],
    'nullable receiver' => ['$records = $maybe; '.$result, false, 'Record', '?Collection $maybe'],
    'base typed receiver could be subclass' => [$result, false, 'Record', 'Collection $records', '/** @param Collection<int, Record> $records */'],
    'opaque result origin' => ['$records = opaqueCollection(); '.$result, false],
    'query result origin deferred' => ['$records = queryCollection(); '.$result, false],
    'custom collection' => [str_replace('new Collection(', 'new CustomCollection(', $origin).$result, false],
    'lazy collection' => [str_replace('new Collection(', 'new \\Illuminate\\Support\\LazyCollection(', $origin).$result, false],
    'unguarded first' => [$origin.'return $records->first();', false],
    'guarded then same typed unguarded' => [$origin.'$other = new Collection([new Record()]); $known = $records->isEmpty() ? new Record() : $records->first(); return $other->first();', false],
    'wrong guard receiver' => [$origin.'$other = new Collection([]); return $other->isEmpty() ? new Record() : $records->first();', false],
    'predicate can miss' => [$origin.str_replace('first()', 'first(static fn (Record $record): bool => false)', $result), false],
    'explicit default null' => [$origin.str_replace('first()', 'first(null, null)', $result), false],
    'named default null' => [$origin.str_replace('first()', 'first(default: null)', $result), false],
    'named callback null' => [$origin.str_replace('first()', 'first(callback: null)', $result), false],
    'unpacked arguments' => [$origin.str_replace('first()', 'first(...[])', $result), false],
    'getter nullsafe' => [$origin.str_replace('->first()', '?->first()', $result), false],
    'guard nullsafe' => [$origin.str_replace('->isEmpty()', '?->isEmpty()', $result), false],
    'inverted branch' => [$origin.'return $records->isEmpty() ? $records->first() : new Record();', false],
    'argument evaluated first' => [$origin.'$records->isEmpty() ? null : pair(effect(), $records->first());', false, 'void'],
    'effectful callee receiver' => [$origin.'$records->isEmpty() ? null : sink()->consume($records->first());', false, 'void'],
    'direct alias' => [$origin.'$alias = $records; '.$result, false],
    'reference alias' => [$origin.'$alias =& $records; '.$result, false],
    'compact hidden alias' => [$origin.'publish(compact("records")); $records->isEmpty() ? null : LateSink::consume($records->first());', false, 'void'],
    'defined vars hidden alias' => [$origin.'publish(get_defined_vars()); $records->isEmpty() ? null : LateSink::consume($records->first());', false, 'void'],
    'reserved header local' => [str_replace('$records', '$http_response_header', $origin.$result), false],
    'item reference' => ['$records = new Collection([&$record]); '.$result, false, 'Record', 'Record $record'],
    'item unpack' => ['$records = new Collection([...$items]); '.$result, false, 'Record', 'array $items'],
    'dynamic variable' => [$origin.'$$name = null; '.$result, false, 'Record', 'string $name'],
    'closure capture' => [$origin.'$callback = function () use ($records): void {}; '.$result, false],
    'closure-only branch' => [$origin.'return (fn () => $records->isEmpty() ? new Record() : $records->first())();', false],
];
$source = "<?php\nnamespace Fixtures;\nuse Illuminate\\Support\\Collection;\nuse Illuminate\\Support\\Collection as C;\n";
$source .= 'function pair(int $value, Record $record): void {} function sink(): LateSink { return new LateSink(); }'."\n";
$ranges = [];
foreach ($cases as $label => $case) {
    [$body, $accepted] = $case;
    $name = 'case'.count($ranges);
    $start = substr_count($source, "\n");
    $source .= ($case[4] ?? '').' function '.$name.'('.($case[3] ?? '').'): '.($case[2] ?? 'Record').' { '.$body.' }'."\n";
    $ranges[$label] = [$start, substr_count($source, "\n")];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('test/nonempty-collection', 'Nonempty collection', 'Native branch result contracts'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $provider = new \Ichinya\Laramago\Analyzer\NonEmptyCollectionResultProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerMethodReturnTypeProvider($provider);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/nonempty-collection', 'Nonempty collection', '1', analyzerPlugins: [$plugin])))->run();
PHP);
file_put_contents($workspace.'/guard-worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('test/nonempty-guards', 'Nonempty source guards', 'Scan and metadata controls'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $provider = new \Ichinya\Laramago\Analyzer\NonEmptyCollectionResultProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerMethodReturnTypeProvider(new class($provider, $this->root) implements \Mago\Sdk\Analyzer\MethodReturnTypeProvider {
            private bool $checked = false;
            public function __construct(private readonly \Ichinya\Laramago\Analyzer\NonEmptyCollectionResultProvider $provider, private readonly string $root) {}
            public function getTargets(): array { return $this->provider->getTargets(); }
            public function getReturnType(\Mago\Sdk\Analyzer\ReturnTypeProviderContext $context): ?\Mago\Sdk\Analyzer\Type {
                $type = $this->provider->getReturnType($context);
                if ($type === null || $this->checked) { return $type; }
                $this->checked = true;
                $probe = new \Ichinya\Laramago\Analyzer\NonEmptyCollectionResultProvider($this->root);
                $file = static function (string $path, string $contents) use ($context): \Mago\Sdk\Syntax\SourceFile {
                    return new \Mago\Sdk\Syntax\SourceFile($context->phpVersion, $path, $contents, [],
                        (new \ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
                        (new \ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
                        (new \ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
                };
                $scan = static function (array $files, bool $first = true, bool $last = true) use ($probe, $context): void {
                    $probe->calls->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($context->phpVersion, $context->cancellation, $files, $first, $last));
                };
                $checks = [];
                $expect = static function (string $label, bool $known) use ($probe, $context, $type, &$checks): void {
                    $result = $probe->getReturnType($context);
                    if (($result === null ? null : (string) $result) !== ($known ? (string) $type : null)) { throw new \RuntimeException('Unexpected collection proof state: '.$label); }
                    $checks[] = $label;
                };
                $contents = file_get_contents($this->root.'/cases.php');
                $host = $file($this->root.'/cases.php', $contents);
                $expect('absent scan', false);
                $scan([$host], last: false);
                $expect('incomplete scan', false);
                $scan([], first: false);
                $expect('complete scan', true);
                $scan([$host, $host]);
                $expect('duplicate host path', false);
                $prefix = '<?php function collision(): void { ';
                $start = $context->invocation->span->start;
                $end = $context->invocation->span->end;
                $collision = str_replace(['->first()', '->last()'], ['->other()', '->noop()'], substr($contents, $start, $end - $start));
                $scan([$host, $file($this->root.'/collision.php', $prefix.str_repeat(' ', $start - strlen($prefix)).$collision.'; }')]);
                $expect('same span unrelated call', false);
                $scan([$file($this->root.'/cases.php', $contents."\n// different analyzed caller bytes")]);
                $expect('caller overlay mismatch', false);
                $nativePath = $context->codebase->getClassLike('Illuminate\\Support\\Collection')->location->file;
                $nativeContents = file_get_contents($probe->calls->path($nativePath));
                $scan([$host, $file($nativePath, $nativeContents."\n// different analyzed native bytes")]);
                $expect('native overlay mismatch', false);
                $scan([$host, $file($this->root.'/broken.php', '<?php function broken( {')]);
                $expect('failed scan', false);
                $scan([$host]);
                $expect('first batch resets veto', true);
                $inventory = (new \ReflectionProperty($probe->calls, 'calls'))->getValue($probe->calls);
                $key = $context->invocation->span->start.':'.$context->invocation->span->end;
                foreach (['file' => '/other-source.php', 'name' => 'Fixtures\\missing'] as $field => $value) {
                    $changed = $inventory;
                    $changed[$key][$field] = $value;
                    (new \ReflectionProperty($probe->calls, 'calls'))->setValue($probe->calls, $changed);
                    $expect('foreign caller '.$field, false);
                }
                (new \ReflectionProperty($probe->calls, 'calls'))->setValue($probe->calls, $inventory);
                $candidate = $probe->calls->call($context->invocation->span);
                $caller = $context->codebase->getFunction($candidate['name']);
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                foreach (['name span', 'body span', 'reference return'] as $variant) {
                    $values = get_object_vars($caller);
                    if ($variant === 'name span') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation($caller->nameLocation->file, new \Mago\Sdk\Span($caller->nameLocation->span->start + 1, $caller->nameLocation->span->end)); }
                    if ($variant === 'body span') { $values['location'] = new \Mago\Sdk\SourceLocation($caller->location->file, new \Mago\Sdk\Span($caller->location->span->start, $caller->location->span->end - 1)); }
                    if ($variant === 'reference return') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($caller->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                    $changed = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                    $replaced = 0;
                    foreach ($snapshot as $operation => $entries) { foreach ($entries as $entryKey => $entry) {
                        if ($entry === $caller) { $cache->values[$operation][$entryKey] = $changed; $replaced++; }
                    } }
                    try {
                        if ($replaced === 0) { throw new \RuntimeException('No metadata snapshot replaced.'); }
                        $expect('stale caller '.$variant, false);
                    } finally { $cache->values = $snapshot; }
                }
                $expect('metadata snapshot restored', true);
                $probe->initialize(new \Mago\Sdk\Analyzer\InitializationContext($context->phpVersion, $context->cancellation));
                $expect('reinitialized without scan', false);
                $scan([$host]);
                $expect('reinitialized complete scan', true);
                file_put_contents($this->root.'/guard-checks.json', json_encode($checks, JSON_THROW_ON_ERROR));
                return $type;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/nonempty-guards', 'Nonempty source guards', '1', analyzerPlugins: [$plugin])))->run();
PHP);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$primary = static function (array $issue): ?array {
    foreach ($issue['annotations'] ?? [] as $annotation) { if (($annotation['kind'] ?? '') === 'Primary') { return $annotation; } }
    return null;
};
$run = static function (string $mode, string $version = '8.5', int $workers = 1) use ($workspace, $package, $command, $native, $fixtures, $primary): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => $version,
        'source' => ['paths' => ['cases.php'], 'includes' => ['types.php', ...array_map(static fn (string $path): string => ltrim($native.$path, '/'), array_values($fixtures))]],
        'extension-hosts' => $mode === 'native' ? new stdClass : ['test' => [
            'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', str_starts_with($mode, 'integrated') ? $package.'/bin/laramago-worker.php' : $workspace.($mode === 'guards' ? '/guard-worker.php' : '/worker.php'), $package.'/vendor/autoload.php', $workspace], 'workers' => $workers,
        ]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit !== 1 || preg_match('/failed|rejected request|parse error/i', $log)) { throw new RuntimeException('Mago '.$mode.' failed; inspect '.$workspace.'; '.$log); }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    return array_values(array_filter($issues, static fn (array $issue): bool => ($primary($issue)['span']['file_id']['name'] ?? '') === 'cases.php'));
};
$nativeIssues = $run('native');
$isolatedIssues = $run('isolated');
$normalize = static function (array $issues): array { $encoded = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues); sort($encoded); return $encoded; };
$within = static fn (array $issues, array $range): array => array_values(array_filter($issues, static fn (array $issue): bool =>
    ($primary($issue)['span']['start']['line'] ?? -1) >= $range[0] && ($primary($issue)['span']['start']['line'] ?? -1) < $range[1]));
foreach ($cases as $label => [$body, $accepted]) {
    $before = $within($nativeIssues, $ranges[$label]);
    $after = $within($isolatedIssues, $ranges[$label]);
    if ($accepted ? ($before === [] || $after !== []) : $normalize($before) !== $normalize($after)) {
        throw new RuntimeException('Unexpected contract result for '.$label.'; inspect '.$workspace.'; '.json_encode([$before, $after]));
    }
}
echo 'PASS: '.count($cases)." native/isolated collection branch contracts\n";
if ($normalize($run('guards')) !== $normalize($isolatedIssues)) { throw new RuntimeException('Proof guard controls changed native results.'); }
$checks = json_decode(file_get_contents($workspace.'/guard-checks.json'), true, flags: JSON_THROW_ON_ERROR);
if (count($checks) !== 17) { throw new RuntimeException('Missing provenance controls: '.count($checks)); }
echo 'PASS: '.count($checks)." scan, collision, source and metadata provenance controls\n";
if (in_array('--integrated', $argv, true)) {
    foreach ([1, 3] as $workers) {
        if ($normalize($run('integrated'.$workers, workers: $workers)) !== $normalize($isolatedIssues)) { throw new RuntimeException('Integrated collection result differs for '.$workers.' workers.'); }
    }
    echo "PASS: integrated one/three workers preserve unguarded getter diagnostics\n";
}
if ($normalize($run('php82', '8.2')) !== $normalize($run('native', '8.2'))) { throw new RuntimeException('PHP 8.2 did not defer.'); }
echo "PASS: PHP 8.2 preserves native diagnostics\n";
$mutations = [
    'isEmpty body' => ['Collection', 'return empty($this->items);', 'return false;'],
    'first body' => ['Collection', 'return \\Illuminate\\Support\\Arr::first($this->items, $callback, $default);', 'return null;'],
    'constructor body' => ['Collection', '$this->items = $this->getArrayableItems($items);', '$this->items = [null];'],
    'array conversion' => ['Arr', 'is_array($items) => $items', 'is_array($items) => [null]'],
    'conditional PHPDoc' => ['Collection', '@phpstan-assert-if-false TValue $this->first()', '@phpstan-assert-if-false null $this->first()'],
];
foreach ($mutations as $label => [$fixture, $before, $after]) {
    $path = $workspace.$native.$fixtures[$fixture];
    $original = file_get_contents($path);
    $changed = str_replace($before, $after, $original);
    if ($changed === $original) { throw new RuntimeException('Mutation did not change '.$label); }
    file_put_contents($path, $changed);
    $changedIssues = $run('mutated-'.str_replace(' ', '-', $label));
    $baselineIssues = $run('native');
    if ($label === 'first body') {
        // The unchanged last-item implementation has its own independent proof.
        $range = $ranges['last'];
        $outsideLast = static fn (array $issues): array => array_values(array_filter($issues, static fn (array $issue): bool =>
            ($primary($issue)['span']['start']['line'] ?? -1) < $range[0] || ($primary($issue)['span']['start']['line'] ?? -1) >= $range[1]));
        $changedIssues = $outsideLast($changedIssues);
        $baselineIssues = $outsideLast($baselineIssues);
    }
    if ($normalize($changedIssues) !== $normalize($baselineIssues)) { throw new RuntimeException('Changed native '.$label.' was accepted.'); }
    file_put_contents($path, $original);
}
echo 'PASS: '.count($mutations)." changed native source/PHPDoc contracts defer\n";
