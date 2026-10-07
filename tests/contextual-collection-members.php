<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$observe = in_array('--observe', $argv, true);
$probe = in_array('--probe', $argv, true);
$integrated = in_array('--integrated', $argv, true);
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago contextual members '.bin2hex(random_bytes(8));
$framework = $workspace.'/laravel/framework/src/Illuminate';
mkdir($framework.'/Database/Eloquent/Concerns', 0o777, true);
mkdir($framework.'/Database/Concerns', 0o777, true);
mkdir($framework.'/Collections', 0o777, true);
mkdir($framework.'/Contracts/Support', 0o777, true);
file_put_contents($framework.'/Contracts/Support/Arrayable.php', <<<'PHP'
<?php
namespace Illuminate\Contracts\Support;
/**
 * @template TKey of array-key
 * @template TValue
 */
interface Arrayable {
    /** @return array<TKey, TValue> */
    public function toArray();
}
PHP);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap must remain unused.");');
file_put_contents($workspace.'/composer.json', json_encode(['autoload' => ['files' => ['bootstrap.php']]], JSON_THROW_ON_ERROR));
$trait = file_get_contents(__DIR__.'/fixtures/analysis/chunk-callbacks-trait.php.stub');
$trait = substr($trait, 0, strrpos($trait, '}')).<<<'PHP'
    /**
     * @param array|string $columns
     * @return TValue
     */
    public function sole($columns = ['*']) {
        $result = $this->limit(2)->get($columns);
        $count = $result->count();
        if ($count === 0) { throw new \Illuminate\Database\RecordsNotFoundException; }
        if ($count > 1) { throw new \Illuminate\Database\MultipleRecordsFoundException($count); }
        return $result->first();
    }
}
PHP;
file_put_contents($framework.'/Database/Concerns/BuildsQueries.php', $trait);
$builder = file_get_contents(__DIR__.'/fixtures/analysis/chunk-callbacks-builder.php.stub');
$builder = str_replace('use \\Illuminate\\Database\\Concerns\\BuildsQueries;', 'use \\Illuminate\\Database\\Concerns\\BuildsQueries { \\Illuminate\\Database\\Concerns\\BuildsQueries::sole as baseSole; }', $builder);
$builder = substr($builder, 0, strrpos($builder, '}')).<<<'PHP'

    /**
     * @param array|string $columns
     * @return TModel
     */
    public function sole($columns = ['*']) {
        try { return $this->baseSole($columns); }
        catch (\Illuminate\Database\RecordsNotFoundException) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(get_class($this->model));
        }
    }

    /**
     * @template TModelNew of \Illuminate\Database\Eloquent\Model
     * @param TModelNew $model
     * @return static<TModelNew>
     */
    public function setModel(Model $model) {
        $this->model = $model;
        $this->query->from($model->getTable());
        return $this;
    }

    /** @return array<int, TModel> */
    public function getModels($columns = ['*']) { return $this->model->hydrate($this->query->get($columns)->all())->all(); }
    /** @return TModel */
    public function newModelInstance($attributes = []) {
        $attributes = array_merge($this->pendingAttributes, $attributes);
        return $this->model->newInstance($attributes)->setConnection($this->query->getConnection()->getName());
    }
    /** @return \Illuminate\Database\Eloquent\Collection<int, TModel> */
    public function hydrate(array $items) {
        $instance = $this->newModelInstance();
        return $instance->newCollection(array_map(function ($item) use ($items, $instance) {
            $model = $instance->newFromBuilder($item);
            if (count($items) > 1) { $model->preventsLazyLoading = Model::preventsLazyLoading(); }
            return $model;
        }, $items));
    }
    /** @return $this */
    public function where($column, $operator = null, $value = null, $boolean = 'and') {
        if ($column instanceof \Closure && is_null($operator)) {
            $column($query = $this->model->newQueryWithoutRelationships());
            $this->eagerLoad = array_merge($this->eagerLoad, $query->getEagerLoads());
            $this->withoutGlobalScopes($query->removedScopes());
            $this->query->addNestedWhereQuery($query->getQuery(), $boolean);
        } else { $this->query->where(...func_get_args()); }
        return $this;
    }
    /** @return $this */
    public function with($relations, $callback = null) {
        if ($callback instanceof \Closure) { $eagerLoad = $this->parseWithRelations([$relations => $callback]); }
        else { $eagerLoad = $this->parseWithRelations(is_string($relations) ? func_get_args() : $relations); }
        $this->eagerLoad = array_merge($this->eagerLoad, $eagerLoad);
        return $this;
    }
}
PHP;
file_put_contents($framework.'/Database/Eloquent/Builder.php', $builder);
// The guarded methods below preserve the installed Laravel 13.31 native bodies. No body is executed.
file_put_contents($framework.'/Database/Eloquent/Model.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
/** @implements \Illuminate\Contracts\Support\Arrayable<array-key, mixed> */
class Model implements \Illuminate\Contracts\Support\Arrayable {
    use HasCollection;
    use \Illuminate\Database\Eloquent\Concerns\HasGlobalScopes;
    protected static $builder = Builder::class;
    protected static $collectionClass = Collection::class;
    protected $with = [];
    protected $withCount = [];
    public function __construct(array $attributes = []) {}
    public function __call(string $method, array $arguments): mixed {}
    public static function __callStatic(string $method, array $arguments): mixed {}
    public function __get(string $key): mixed {}
    public function toArray(): array { return []; }
    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public static function query() { return (new static)->newQuery(); }
    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function newQuery() { return $this->registerGlobalScopes($this->newQueryWithoutScopes()); }
    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function newModelQuery() { return $this->newEloquentBuilder($this->newBaseQueryBuilder())->setModel($this); }
    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function newQueryWithoutScopes() { return $this->newModelQuery()->with($this->with)->withCount($this->withCount); }
    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function newQueryWithoutRelationships() { return $this->registerGlobalScopes($this->newModelQuery()); }
    /**
     * @param \Illuminate\Database\Eloquent\Builder<static> $builder
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function registerGlobalScopes($builder) {
        foreach ($this->getGlobalScopes() as $identifier => $scope) { $builder->withGlobalScope($identifier, $scope); }
        return $builder;
    }
    /** @return \Illuminate\Database\Eloquent\Builder<*> */
    public function newEloquentBuilder($query) {
        $builderClass = $this->resolveCustomBuilderClass();
        if ($builderClass && is_subclass_of($builderClass, Builder::class)) { return new $builderClass($query); }
        return new static::$builder($query);
    }
    protected function newBaseQueryBuilder() { return $this->getConnection()->query(); }
    protected function resolveCustomBuilderClass() { return static::resolveClassAttribute(\Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder::class, 'builderClass') ?? false; }
    /** @return static */
    public function newInstance($attributes = [], $exists = false) {
        $model = new static;
        $model->exists = $exists;
        $model->setConnection($this->getConnectionName());
        $model->setTable($this->getTable());
        $model->mergeCasts($this->casts);
        $model->fill((array) $attributes);
        return $model;
    }
    /** @return static */
    public function newFromBuilder($attributes = [], $connection = null) {
        $model = $this->newInstance([], true);
        $model->setRawAttributes((array) $attributes, true);
        $model->setConnection($connection ?? $this->getConnectionName());
        $model->fireModelEvent('retrieved', false);
        return $model;
    }
}
PHP);
file_put_contents($framework.'/Database/Eloquent/HasCollection.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
/** @template TCollection of Collection */
trait HasCollection {
    protected static array $resolvedCollectionClasses = [];
    /**
     * @param array<array-key, Model> $models
     * @return TCollection
     */
    public function newCollection(array $models = []) {
        static::$resolvedCollectionClasses[static::class] ??= ($this->resolveCollectionFromAttribute() ?? static::$collectionClass);
        $collection = new static::$resolvedCollectionClasses[static::class]($models);
        if (Model::isAutomaticallyEagerLoadingRelationships()) { $collection->withRelationshipAutoloading(); }
        return $collection;
    }
    public function resolveCollectionFromAttribute() { return null; }
}
PHP);
file_put_contents($framework.'/Database/Eloquent/Concerns/HasGlobalScopes.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent\Concerns;
trait HasGlobalScopes { public function getGlobalScopes(): array { return []; } }
PHP);
file_put_contents($framework.'/Collections/Enumerable.php', <<<'PHP'
<?php
namespace Illuminate\Support;
/**
 * @template TKey of array-key
 * @template TValue
 * @extends \IteratorAggregate<TKey, TValue>
 * @extends \Illuminate\Contracts\Support\Arrayable<TKey, TValue>
 */
interface Enumerable extends \IteratorAggregate, \Illuminate\Contracts\Support\Arrayable {
    /**
     * @template TZipValue
     * @param \Illuminate\Contracts\Support\Arrayable<array-key, TZipValue>|iterable<array-key, TZipValue> ...$items
     * @return static<int, static<int, TValue|TZipValue>>
     */
    public function zip($items);
}
PHP);
file_put_contents($framework.'/Collections/Collection.php', <<<'PHP'
<?php
namespace Illuminate\Support;
/**
 * @template TKey of array-key
 * @template TValue
 * @implements \Illuminate\Support\Enumerable<TKey, TValue>
 */
class Collection implements Enumerable {
    /** @var array<TKey, TValue> */
    protected $items = [];
    /** @param array<TKey, TValue> $items */
    public function __construct(array $items = []) { $this->items = $items; }
    /** @return \ArrayIterator<TKey, TValue> */
    public function getIterator(): \Traversable { return new \ArrayIterator($this->items); }
    /** @param TValue $item */
    public function push($item): static { $this->items[] = $item; return $this; }
    /** @return array<TKey, TValue> */
    public function toArray(): array { return $this->items; }
    /**
     * @template TZipValue
     * @param \Illuminate\Contracts\Support\Arrayable<array-key, TZipValue>|iterable<array-key, TZipValue> ...$items
     * @return static<int, static<int, TValue|TZipValue>>
     */
    public function zip($items) {
        $arrayableItems = array_map(fn ($items) => $this->getArrayableItems($items), func_get_args());
        $params = array_merge([fn () => $this->newInstance(func_get_args()), $this->items], $arrayableItems);
        return $this->newInstance(array_map(...$params));
    }
}
PHP);
file_put_contents($framework.'/Database/Eloquent/Collection.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
/**
 * @template TKey of array-key = array-key
 * @template TModel of Model = Model
 * @extends \Illuminate\Support\Collection<TKey, TModel>
 */
class Collection extends \Illuminate\Support\Collection {}
PHP);
file_put_contents($workspace.'/declarations.php', <<<'PHP'
<?php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
/**
 * @property-read string $amount
 * @property-read int $id
 * @property-read string|null $nullable
 * @property-read 'fixed value' $literal
 * @mixin Model
 */
class Row extends Model { public function recalculate(): void {} }
/** @property-read int $amount */
class OtherRow extends Model {}
class ShadowRow extends Row { private int $amount = 1; }
class IntegerRow extends Row { public int $amount = 1; }
class CustomQueryRow extends Row { public static function query(): \Illuminate\Database\Eloquent\Builder { return new \Illuminate\Database\Eloquent\Builder; } }
class CustomInstanceRow extends Row { public function newInstance($attributes = [], $exists = false): Row { return new Row; } }
class CustomCollectionRow extends Row { public function newCollection(array $models = []): Collection { return new Collection($models); } }
/** @property float $amount */
class CastRow extends Model { protected $casts = ['amount' => 'decimal:2']; }
/** @property-read float $amount */
class ExplicitReadCastRow extends Model { protected $casts = ['amount' => 'decimal:2']; }
function stringSink(string $value): void {}
function intSink(int $value): void {}
function nullableSink(?string $value): void {}
/** @param numeric-string|null $value */
function nullableNumericSink(?string $value): void {}
function floatSink(float $value): void {}
function arraySink(array $value): void {}
interface ReaderContract { public function helperMarker(): void; }
abstract class Reader implements ReaderContract {
    public function helperMarker(): void {}
    /** @param Collection<int, Row> $items */
    abstract protected function abstractLabels(Collection $items): array;
    /**
     * @param Collection<int, Row> $items
     * @return array<int, string>
     */
    protected function labels(Collection $items): array {
        $labels = [];
        foreach ($items as $row) { $labels[] = $row->amount; }
        return $labels;
    }
    /** @param Collection<int, Row> $items */
    protected function keyedLabels(Collection $items): array {
        $labels = [];
        foreach ($items as $row) { $labels[$row->amount] = true; }
        return $labels;
    }
    /** @param Collection<int, Row> $items */
    protected function nestedKeyedLabels(Collection $items): array {
        $labels = [];
        foreach ($items as $row) { $labels['group'][$row->amount] = true; }
        return $labels;
    }
    /** @param Collection<int, Row> $items */
    protected function keyedPairLabels(Collection $items): array {
        $labels = [];
        foreach ($items as $row) { $labels[$row->amount] = $row->amount; }
        return $labels;
    }
    /** @param Collection<int, Row> $items */
    protected function fieldWriter(Collection $items): void {
        foreach ($items as $row) { $row->amount = 'changed'; }
    }
    /** @param Collection<int, Row> $items */
    protected function indexedFieldWriter(Collection $items): void {
        foreach ($items as $row) { $row->amount[0] = 'x'; }
    }
    /** @param Collection<int, Row> $items */
    protected function destructuredFieldWriter(Collection $items): void {
        foreach ($items as $row) { [$row->amount] = ['changed']; }
    }
    /** @param Collection<int, Row> $items */
    protected function referencedFields(Collection $items): array {
        $references = [];
        foreach ($items as $row) { $references[] = [&$row->amount]; }
        return $references;
    }
    /** @param Collection<int, Row> $items */
    protected function iteratedFieldWriter(Collection $items): void {
        foreach ($items as $row) { foreach (['changed'] as $row->amount) {} }
    }
    /** @param Collection<int, Row> $items */
    protected function mutating(Collection $items): void { $items->push(new OtherRow); }
}
PHP);
$cases = [
    'bare native collection reads the documented member' => ['foreach ($items as $row) { stringSink($row->amount); }', true],
    'native integer declaration retains its type' => ['foreach ($items as $row) { intSink($row->id); }', true],
    'nullable read is preserved' => ['foreach ($items as $row) { nullableSink($row->nullable); }', true],
    'wrong scalar consumer is still invalid' => ['foreach ($items as $row) { intSink($row->amount); }', true],
    'unknown member is never invented' => ['foreach ($items as $row) { stringSink($row->typo); }', false],
    'unknown method is not contextualized' => ['foreach ($items as $row) { $row->missing(); }', false],
    'collection alias defers' => ['$alias = $items; foreach ($items as $row) { stringSink($row->amount); }', false],
    'collection mutation defers' => ['$items->push(new OtherRow); foreach ($items as $row) { stringSink($row->amount); }', false],
    'row assignment defers' => ['foreach ($items as $row) { $row = new OtherRow; stringSink($row->amount); }', false],
    'row reference iteration defers' => ['foreach ($items as &$row) { stringSink($row->amount); }', false],
    'collection capture defers' => ['$action = function () use ($items) {}; foreach ($items as $row) { stringSink($row->amount); }', false],
    'strong local annotation wins' => ['foreach ($items as $row) { /** @var OtherRow $row */ stringSink($row->amount); }', false],
    'phpstan item annotation wins' => ['foreach ($items as $row) { /** @phpstan-var OtherRow $row */ stringSink($row->amount); }', false],
    'psalm item annotation wins' => ['foreach ($items as $row) { /** @psalm-var OtherRow $row */ stringSink($row->amount); }', false],
    'typed read-only helper preserves input' => ['$this->labels($items); foreach ($items as $row) { stringSink($row->amount); }', true],
    'read-only helper reads a dictionary key' => ['$this->keyedLabels($items); foreach ($items as $row) { stringSink($row->amount); }', true],
    'read-only helper reads a nested dictionary key' => ['$this->nestedKeyedLabels($items); foreach ($items as $row) { stringSink($row->amount); }', true],
    'read-only helper reads both dictionary key and value' => ['$this->keyedPairLabels($items); foreach ($items as $row) { stringSink($row->amount); }', true],
    'helper field assignment defers' => ['$this->fieldWriter($items); foreach ($items as $row) { stringSink($row->amount); }', false],
    'helper indexed field assignment defers' => ['$this->indexedFieldWriter($items); foreach ($items as $row) { stringSink($row->amount); }', false],
    'helper destructured field assignment defers' => ['$this->destructuredFieldWriter($items); foreach ($items as $row) { stringSink($row->amount); }', false],
    'helper field reference escape defers' => ['$this->referencedFields($items); foreach ($items as $row) { stringSink($row->amount); }', false],
    'helper foreach field assignment defers' => ['$this->iteratedFieldWriter($items); foreach ($items as $row) { stringSink($row->amount); }', false],
    'typed mutable helper defers' => ['$this->mutating($items); foreach ($items as $row) { stringSink($row->amount); }', false],
    'abstract no-body helper never proves a read-only input' => ['$this->abstractLabels($items); foreach ($items as $row) { stringSink($row->amount); }', false],
    'write is not supplied a read type' => ['foreach ($items as $row) { $row->amount = 123; }', false],
    'isset never receives the contextual read' => ['foreach ($items as $row) { isset($row->amount); }', false],
    'empty never receives the contextual read' => ['foreach ($items as $row) { empty($row->amount); }', false],
    'destructuring row writes defer' => ['foreach ($items as $row) { [$row] = [new OtherRow]; stringSink($row->amount); }', false],
    'row reference capture defers' => ['foreach ($items as $row) { $action = function () use (&$row) {}; stringSink($row->amount); }', false],
    'row escape defers' => ['foreach ($items as $row) { unknown($row); stringSink($row->amount); }', false],
    'chained builder model replacement defers' => ['foreach ($items as $row) { stringSink($row->amount); }', false, 'Row', '$query->where("active", true)->setModel(new OtherRow);'],
    'explicit query generic documentation wins' => ['foreach ($items as $row) { stringSink($row->amount); }', false, 'Row', '/** @var \\Illuminate\\Database\\Eloquent\\Builder<OtherRow> $query */'],
    'phpstan query generic documentation wins' => ['foreach ($items as $row) { stringSink($row->amount); }', false, 'Row', '/** @phpstan-var \\Illuminate\\Database\\Eloquent\\Builder<OtherRow> $query */'],
    'psalm query generic documentation wins' => ['foreach ($items as $row) { stringSink($row->amount); }', false, 'Row', '/** @psalm-var \\Illuminate\\Database\\Eloquent\\Builder<OtherRow> $query */'],
    'referenced caller parameter is not fresh storage' => ['foreach ($items as $row) { stringSink($row->amount); }', false, 'Row', 'opaque();', '&$query'],
    'strong closure parameter documentation wins' => ['/**'."\n * @param Collection<int, OtherRow> \$items\n */\n".'function', false],
    'phpstan closure parameter documentation wins' => ['', false, 'Row', '', '', 'phpstan-'],
    'psalm closure parameter documentation wins' => ['', false, 'Row', '', '', 'psalm-'],
    'documented literal retains its spaces' => ['foreach ($items as $row) { stringSink($row->literal); }', true],
    'private physical shadow defers' => ['foreach ($items as $row) { stringSink($row->amount); }', false, 'ShadowRow'],
    'public integer shadow keeps its declaration' => ['foreach ($items as $row) { intSink($row->amount); }', true, 'IntegerRow'],
    'custom query return defers' => ['foreach ($items as $row) { stringSink($row->amount); }', false, 'CustomQueryRow'],
    'custom hydration instance defers' => ['foreach ($items as $row) { stringSink($row->amount); }', false, 'CustomInstanceRow'],
    'custom collection construction defers' => ['foreach ($items as $row) { stringSink($row->amount); }', false, 'CustomCollectionRow'],
    'known decimal cast refines a general item property' => ['foreach ($items as $row) { nullableNumericSink($row->amount); }', true, 'CastRow'],
    'cast item read retains wrong array consumer error' => ['foreach ($items as $row) { arraySink($row->amount); }', true, 'CastRow'],
    'explicit item read keeps priority over a cast' => ['foreach ($items as $row) { floatSink($row->amount); }', true, 'ExplicitReadCastRow'],
];
$source = "<?php\nuse Illuminate\\Database\\Eloquent\\Collection;\nabstract class Scenarios extends Reader {\n";
$ranges = [];
foreach ($cases as $name => $case) {
    [$body, $eligible] = $case;
    $model = $case[2] ?? 'Row';
    $start = strlen($source);
    $callback = str_contains($name, 'closure parameter documentation')
        ? '/**'."\n * @".($case[5] ?? '')."param Collection<int, OtherRow> \$items\n */\n".'function (Collection $items): void { foreach ($items as $row) { stringSink($row->amount); } }'
        : 'function (Collection $items): void { '.$body.' }';
    $source .= 'public function case'.count($ranges).'('.($case[4] ?? '').'): void { $query = '.$model.'::query(); '.($case[3] ?? '').' $query->chunkById(10, '.$callback.'); }'."\n";
    $ranges[] = [$name, $start, strlen($source), $eligible];
}
$source .= "}\n";
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
use Ichinya\Laramago\Analyzer\ContextualCollectionMemberProvider;
use Ichinya\Laramago\Analyzer\EloquentChunkCallbackProvider;
use Ichinya\Laramago\Analyzer\LaravelPlugin;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
final class ObservedMembers implements PropertyTypeProvider {
    private bool $checked = false;
    public function __construct(private readonly ContextualCollectionMemberProvider $inner, private readonly string $audit, private readonly bool $observe, private readonly bool $guards = false) {}
    public function getTargets(): array { return $this->inner->getTargets(); }
    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType {
        $access = $context->access;
        $result = $this->observe ? null : $this->inner->getPropertyType($context);
        if ($result !== null && $this->guards && ! $this->checked) { $this->checked = true; $this->check($context, $result); }
        $property = $context->codebase->getDeclaringMagicProperty('Row', '$'.$access->property);
        $chunk = $context->codebase->getMethod('Illuminate\Database\Eloquent\Builder', 'chunkById')
            ?? $context->codebase->getDeclaringMethod('Illuminate\Database\Eloquent\Builder', 'chunkById');
        $collection = $context->codebase->getClassLike('Illuminate\Database\Eloquent\Collection');
        $query = $context->codebase->getDeclaringMethod('Row', 'query');
        $proof = $this->inner->members->member($access->span, $access->property);
        $stages = [];
        $nativeDetails = [];
        if (! $this->observe && $proof !== null && $result === null) {
            $contract = (new ReflectionProperty($this->inner, 'contract'))->getValue($this->inner);
            foreach (['hierarchy' => [$context->codebase, $proof['model']], 'caller' => [$context->codebase, $proof],
                'nativeQuery' => [$context->codebase, $proof['model'], $proof], 'modelBinding' => [$context], 'collection' => [$context, $proof['model']],
                'input' => [$context, $proof], 'readonlyInputs' => [$context, $proof]] as $name => $arguments) {
                $stages[$name] = (new ReflectionMethod($contract, $name))->invoke($contract, ...$arguments);
            }
            foreach ((new ReflectionClass($contract))->getConstant('MODEL_BODIES') as $name => $body) {
                $nativeDetails[$name] = (new ReflectionMethod($contract, 'native'))->invoke($contract, $context->codebase, 'Row', $name, 'Illuminate\Database\Eloquent\Model', '/Database/Eloquent/Model.php', $body);
            }
            $root = (new ReflectionProperty($this->inner, 'root'))->getValue($this->inner);
            $reflection = new Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection($context->codebase, new Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($root));
            $nativeDetails['builderDefault'] = var_export($reflection->default('Row', 'builder'), true);
            $nativeDetails['collectionDefault'] = var_export($reflection->default('Row', 'collectionClass'), true);
            $nativeDetails['queryAtoms'] = var_export($query?->returnType?->type->atomicTypes, true);
            $boundQuery = $context->codebase->getMethod($proof['model'], 'query') ?? $context->codebase->getDeclaringMethod($proof['model'], 'query');
            $nativeDetails['queryContract'] = $boundQuery === null ? null : ['static' => $boundQuery->static,
                'fromDocblock' => $boundQuery->returnType?->fromDocblock, 'owner' => $boundQuery->identifier->class,
                'doc' => (new ReflectionMethod($contract, 'methodNode'))->invoke($contract, $boundQuery)?->getDocComment()?->getText(),
                'atoms' => var_export($boundQuery->returnType?->type->atomicTypes, true)];
            foreach ((new ReflectionClass($contract))->getConstant('BUILDER_BODIES') as $name => $body) {
                $nativeDetails['builderBodies'][$name] = (new ReflectionMethod($contract, 'native'))->invoke($contract, $context->codebase, 'Illuminate\\Database\\Eloquent\\Builder', $name, 'Illuminate\\Database\\Eloquent\\Builder', '/Database/Eloquent/Builder.php', $body);
            }
            $nativeDetails['getIterator'] = (new ReflectionMethod($contract, 'native'))->invoke($contract, $context->codebase, 'Illuminate\Database\Eloquent\Collection', 'getIterator', 'Illuminate\Support\Collection', '/Collections/Collection.php', 'return new \\ArrayIterator($this->items);');
            $nativeDetails['collectionHierarchy'] = (new ReflectionMethod($contract, 'hierarchy'))->invoke($contract, $context->codebase, 'Illuminate\Database\Eloquent\Collection');
            foreach (['Illuminate\Database\Eloquent\Collection', ...$context->codebase->getClassAncestors('Illuminate\Database\Eloquent\Collection')] as $ancestor) {
                $metadata = $context->codebase->getClassLike($ancestor);
                $nativeDetails['ancestors'][$ancestor] = $metadata === null ? null : ['kind' => $metadata->kind->name, 'flags' => $metadata->flags->bits,
                    'location' => $metadata->location, 'complete' => ! $metadata->hasIncompleteHierarchy(),
                    'source' => (new ReflectionMethod($contract, 'classNode'))->invoke($contract, $context->codebase, $metadata) !== null];
            }
        }
        file_put_contents($this->audit, json_encode([
            'start' => $access->span->start, 'end' => $access->span->end, 'property' => $access->property,
            'class' => $access->class, 'receiver' => (string) $access->receiverType, 'kind' => $access->kind->name,
            'type' => $result?->readType === null ? null : (string) $result->readType,
            'atoms' => $result?->readType === null ? null : var_export($result->readType->atomicTypes, true),
            'decimalEquality' => $result?->readType === null || strcasecmp($proof['model'] ?? '', 'CastRow') !== 0 ? null
                : $context->types->equals($result->readType, \Ichinya\Laramago\Analyzer\StaticAnalysis\AttributeTypes::nullable(
                    \Ichinya\Laramago\Analyzer\StaticAnalysis\AttributeTypes::cast('decimal:2', $context->codebase), true,
                )->readType),
            'decimalAcceptsGeneralString' => $result?->readType === null || strcasecmp($proof['model'] ?? '', 'CastRow') !== 0 ? null
                : $context->types->isContainedBy(\Mago\Sdk\Analyzer\Type::string(), $result->readType),
            'literalEquality' => $result?->readType === null || $access->property !== 'literal' ? null
                : $context->types->equals($result->readType, \Mago\Sdk\Analyzer\Type::literalString('fixed value')),
            'literalAcceptsGeneralString' => $result?->readType === null || $access->property !== 'literal' ? null
                : $context->types->isContainedBy(\Mago\Sdk\Analyzer\Type::string(), $result->readType),
            'literalAcceptsDifferentValue' => $result?->readType === null || $access->property !== 'literal' ? null
                : $context->types->canBeIdentical(\Mago\Sdk\Analyzer\Type::literalString('other value'), $result->readType),
            'proof' => $proof !== null, 'stages' => $stages,
            'nativeDetails' => $nativeDetails,
            'declaration' => $property === null ? null : ['type' => (string) ($property->type?->type ?? $property->declaredType?->type), 'location' => $property->type?->location ?? $property->declaredType?->location],
            'chunk' => $chunk === null ? null : ['owner' => $chunk->identifier->class, 'callback' => (string) $chunk->parameters[1]->type->type],
            'query' => $query === null ? null : (string) ($query->returnType?->type ?? $query->declaredReturnType?->type),
            'collection' => $collection === null ? null : ['kind' => $collection->kind->name, 'ancestors' => $context->codebase->getClassAncestors($collection->name),
                'templates' => array_map(static fn ($template): array => ['name' => $template->name, 'constraint' => (string) $template->constraint, 'default' => $template->default === null ? null : (string) $template->default, 'variance' => $template->variance->name], $collection->templates)],
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        return $result;
    }
    private function check(PropertyTypeProviderContext $context, PropertyType $result): void {
        $root = dirname($this->audit);
        $bytes = file_get_contents($root.'/cases.php');
        $file = static function (string $path, string $contents) use ($context): \Mago\Sdk\Syntax\SourceFile {
            return new \Mago\Sdk\Syntax\SourceFile($context->phpVersion, $path, $contents, [],
                (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
                (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
                (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
        };
        $host = $file('cases.php', $bytes);
        $probe = new ContextualCollectionMemberProvider($root);
        $scan = static fn (array $files, bool $first = true, bool $last = true) => $probe->members->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($context->phpVersion, $context->cancellation, $files, $first, $last));
        $checks = [];
        $expect = static function (string $label, bool $known, ?PropertyTypeProviderContext $variant = null) use ($probe, $context, $result, &$checks): void {
            $actual = $probe->getPropertyType($variant ?? $context);
            if (($actual === null ? null : (string) $actual->readType) !== ($known ? (string) $result->readType : null)
                || $actual?->writeType !== null) { throw new RuntimeException('Unexpected contextual member proof: '.$label); }
            $checks[] = $label;
        };
        $expect('missing scan', false);
        $scan([$host], last: false); $expect('incomplete scan', false);
        $scan([], first: false); $expect('complete scan', true);
        $scan([$host, $host]); $expect('duplicate path', false);
        $scan([$host], last: false); $scan([$host], first: false); $expect('duplicate across batches', false);
        $scan([$file('cases.php', $bytes.' ')]); $expect('stale analyzed caller', false);
        $scan([$host, $file('broken.php', '<?php function broken( {')]); $expect('parse failure', false);
        $scan([$host, $file('oversized.php', str_repeat(' ', 2_000_001))]); $expect('source byte budget', false);
        $large = '<?php '.str_repeat(' ', 1_999_994); $bulk = [$host];
        for ($index = 0; $index < 34; $index++) { $bulk[] = $file('budget'.$index.'.php', $large); }
        $scan($bulk); $expect('aggregate byte budget', false); unset($bulk, $large);
        foreach (['$other->amount', '$other?->amount', '$other->{$other}', '$xx->z'] as $syntax) {
            $offset = str_contains($syntax, 'amount') ? strpos($syntax, 'amount') : (str_contains($syntax, '{$other}') ? strpos($syntax, '$other', 1) : 0);
            $collision = '<?php '.str_repeat(' ', $context->access->span->start - 6 - $offset).$syntax.';';
            $scan([$host, $file('collision.php', $collision)]); $expect('foreign property span '.$syntax, false);
        }
        $scan([$host]); $expect('fresh generation', true);
        $probe->members->reset(); $scan([$host], first: false); $expect('missing first batch', false);
        $scan([$host]); $expect('first batch recovery', true);
        $cancel = new class implements \Mago\Sdk\CancellationTokenInterface {
            public function isCancelled(): bool { return true; }
            public function throwIfCancelled(): void { throw new RuntimeException('Invented scan cancellation.'); }
            public function subscribe(Closure $callback): int { return 0; }
            public function unsubscribe(int $subscription): void {}
        };
        try { $probe->members->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($context->phpVersion, $cancel, [$host], true, true)); }
        catch (RuntimeException $error) { if ($error->getMessage() !== 'Invented scan cancellation.') { throw $error; } }
        $expect('cancelled scan', false); $scan([], first: false); $expect('cancelled generation stays poisoned', false);
        $scan([$host]); $expect('cancellation recovery', true);
        $access = $context->access;
        $accessContext = static fn (string $class, string $property, $kind, $receiver, $span) => new PropertyTypeProviderContext($context->phpVersion, $context->codebase,
            new \Mago\Sdk\Analyzer\PropertyAccess($class, $property, $kind, $receiver, $span), $context->types, $context->cancellation);
        $expect('write access', false, $accessContext($access->class, $access->property, \Mago\Sdk\Analyzer\PropertyAccessKind::Write, $access->receiverType, $access->span));
        $expect('foreign class', false, $accessContext('OtherRow', $access->property, $access->kind, \Mago\Sdk\Analyzer\Type::namedObject('OtherRow'), $access->span));
        $expect('foreign property', false, $accessContext($access->class, 'typo', $access->kind, $access->receiverType, $access->span));
        $expect('foreign span', false, $accessContext($access->class, $access->property, $access->kind, $access->receiverType, new \Mago\Sdk\Span($access->span->start + 1, $access->span->end)));
        $atom = $access->receiverType->atomicTypes[0];
        foreach (['static' => true, 'isThis' => true, 'remappedParameters' => true, 'parameters' => [\Mago\Sdk\Analyzer\Type::string()],
            'intersections' => [\Mago\Sdk\Analyzer\Type::namedObject('Countable')->atomicTypes[0]]] as $name => $value) {
            $receiver = \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\NamedObjectType(...array_replace(get_object_vars($atom), [$name => $value])));
            $expect('receiver '.$name, false, $accessContext($access->class, $access->property, $access->kind, $receiver, $access->span));
        }
        $expect('union receiver', false, $accessContext($access->class, $access->property, $access->kind,
            \Mago\Sdk\Analyzer\Type::union($access->receiverType, \Mago\Sdk\Analyzer\Type::null()), $access->span));
        foreach (['possiblyUndefined', 'possiblyUndefinedFromTry', 'nullsafeNull'] as $flag) {
            $receiver = $access->receiverType->withFlags(new \Mago\Sdk\Analyzer\Type\TypeFlags(...array_replace(get_object_vars($access->receiverType->flags), [$flag => true])));
            $expect('receiver flag '.$flag, false, $accessContext($access->class, $access->property, $access->kind, $receiver, $access->span));
        }
        $cache = (new ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
        $snapshot = $cache->values;
        $property = $context->codebase->getDeclaringMagicProperty('Row', '$amount');
        $model = $context->codebase->getClassLike('Row');
        $query = $context->codebase->getDeclaringMethod('Row', 'query');
        $setter = $context->codebase->getMethod('Illuminate\\Database\\Eloquent\\Builder', 'setModel');
        $collection = $context->codebase->getClassLike('Illuminate\\Database\\Eloquent\\Collection');
        $interface = $context->codebase->getClassLike('Illuminate\\Contracts\\Support\\Arrayable');
        $abstract = $context->codebase->getMethod('Illuminate\\Contracts\\Support\\Arrayable', 'toArray');
        $alias = $context->codebase->getDeclaringMethod('Illuminate\\Database\\Eloquent\\Builder', 'baseSole');
        $zip = $context->codebase->getMethod('Illuminate\\Support\\Collection', 'zip');
        $enumerable = $context->codebase->getClassLike('Illuminate\\Support\\Enumerable');
        $interfaceZip = $context->codebase->getMethod('Illuminate\\Support\\Enumerable', 'zip');
        if ($abstract === null || ! $abstract->abstract || $alias === null || $zip === null
            || ! $zip->parameters[0]->flags->contains(\Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC)
            || $enumerable?->kind !== \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface || $interfaceZip === null || ! $interfaceZip->abstract
            || ! $interfaceZip->parameters[0]->flags->contains(\Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC)) {
            throw new RuntimeException('Faithful interface/alias/documented variadic representations were not observed.');
        }
        $flags = static fn ($original, int $add = 0) => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($original->flags->bits | $add);
        $type = static fn ($original, $value) => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($original->location, $value, $original->fromDocblock, $original->inferred);
        $variants = [
            'property wrong name' => [$property, ['name' => '$other']],
            'property private read' => [$property, ['readVisibility' => \Mago\Sdk\Analyzer\Type\Visibility::Private]],
            'property static' => [$property, ['flags' => $flags($property, \Mago\Sdk\Analyzer\Metadata\MetadataFlags::STATIC)]],
            'property write only' => [$property, ['flags' => $flags($property, \Mago\Sdk\Analyzer\Metadata\MetadataFlags::WRITEONLY)]],
            'property wrong authoritative type' => [$property, ['type' => $type($property->type, \Mago\Sdk\Analyzer\Type::int())]],
            'property mixed' => [$property, ['type' => $type($property->type, \Mago\Sdk\Analyzer\Type::mixed())]],
            'model incomplete hierarchy' => [$model, ['unresolvedHierarchyDependencies' => ['UnknownParent']]],
            'model wrong source kind' => [$model, ['kind' => \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Trait]],
            'model arbitrary mixin' => [$model, ['mixins' => [\Mago\Sdk\Analyzer\Type::namedObject('OtherRow')]]],
            'query static discrepancy' => [$query, ['static' => false]],
            'query reference return' => [$query, ['flags' => $flags($query, \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)]],
            'query wrong return' => [$query, ['returnType' => $type($query->returnType, \Mago\Sdk\Analyzer\Type::namedObject('Illuminate\\Database\\Eloquent\\Builder', \Mago\Sdk\Analyzer\Type::namedObject('OtherRow')))]],
            'query missing native doc contract' => [$query, ['returnType' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($query->returnType->location, $query->returnType->type, false, $query->returnType->inferred)]],
            'model setter wrong owner' => [$setter, ['identifier' => new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier(kind: $setter->identifier->kind, name: 'setModel', class: 'OtherRow')]],
            'model setter static discrepancy' => [$setter, ['static' => true]],
            'model setter private discrepancy' => [$setter, ['visibility' => \Mago\Sdk\Analyzer\Type\Visibility::Private]],
            'model setter missing native return contract' => [$setter, ['returnType' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($setter->returnType->location, $setter->returnType->type, false, $setter->returnType->inferred)]],
            'collection incomplete hierarchy' => [$collection, ['unresolvedHierarchyDependencies' => ['UnknownCollection']]],
            'collection missing templates' => [$collection, ['templates' => []]],
            'native interface wrong source kind' => [$interface, ['kind' => \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Class_]],
            'native interface missing implicit abstract flag' => [$abstract, ['abstract' => false]],
            'native alias different source name' => [$alias, ['identifier' => new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier(kind: $alias->identifier->kind, name: 'first', class: $alias->identifier->class)]],
            'native alias different source owner' => [$alias, ['identifier' => new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier(kind: $alias->identifier->kind, name: 'sole', class: 'OtherRow')]],
            'native alias abstract source' => [$alias, ['abstract' => true]],
            'native zip signature missing source documentation' => [$zip, ['parameters' => [new ($zip->parameters[0]::class)(...array_replace(get_object_vars($zip->parameters[0]),
                ['type' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($zip->parameters[0]->type->location, $zip->parameters[0]->type->type, false, $zip->parameters[0]->type->inferred)]))]]],
            'native zip loses documented variadic flag' => [$zip, ['parameters' => [new ($zip->parameters[0]::class)(...array_replace(get_object_vars($zip->parameters[0]),
                ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($zip->parameters[0]->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC)]))]]],
            'native enumerable wrong source kind' => [$enumerable, ['kind' => \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Class_]],
            'native enumerable zip loses implicit abstract flag' => [$interfaceZip, ['abstract' => false]],
            'native enumerable zip becomes static' => [$interfaceZip, ['static' => true]],
            'native enumerable zip wrong metadata kind' => [$interfaceZip, ['kind' => \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Closure]],
            'native enumerable zip wrong identifier kind' => [$interfaceZip, ['identifier' => new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier(kind: \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Closure, name: 'zip')]],
            'native enumerable zip wrong owner' => [$interfaceZip, ['identifier' => new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier(kind: $interfaceZip->identifier->kind, name: 'zip', class: 'OtherRow')]],
            'native enumerable zip missing documented type' => [$interfaceZip, ['parameters' => [new ($interfaceZip->parameters[0]::class)(...array_replace(get_object_vars($interfaceZip->parameters[0]),
                ['type' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($interfaceZip->parameters[0]->type->location, $interfaceZip->parameters[0]->type->type, false, $interfaceZip->parameters[0]->type->inferred)]))]]],
            'native enumerable zip loses documented variadic flag' => [$interfaceZip, ['parameters' => [new ($interfaceZip->parameters[0]::class)(...array_replace(get_object_vars($interfaceZip->parameters[0]),
                ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($interfaceZip->parameters[0]->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC)]))]]],
            'native enumerable zip references input' => [$interfaceZip, ['parameters' => [new ($interfaceZip->parameters[0]::class)(...array_replace(get_object_vars($interfaceZip->parameters[0]),
                ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($interfaceZip->parameters[0]->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)]))]]],
            'native enumerable zip type anchor outside documentation' => [$interfaceZip, ['parameters' => [new ($interfaceZip->parameters[0]::class)(...array_replace(get_object_vars($interfaceZip->parameters[0]),
                ['type' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($interfaceZip->location, $interfaceZip->parameters[0]->type->type, true, $interfaceZip->parameters[0]->type->inferred)]))]]],
            'native enumerable zip input is output storage' => [$interfaceZip, ['parameters' => [new ($interfaceZip->parameters[0]::class)(...array_replace(get_object_vars($interfaceZip->parameters[0]),
                ['outType' => $interfaceZip->parameters[0]->type]))]]],
        ];
        $snapshot = $cache->values;
        foreach ($variants as $label => [$original, $changes]) {
            $replacement = new ($original::class)(...array_replace(get_object_vars($original), $changes));
            $replaced = 0;
            foreach ($snapshot as $operation => $entries) { foreach ($entries as $key => $entry) {
                if ($entry === $original || $entry instanceof \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata && $original instanceof \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata && strcasecmp($entry->name, $original->name) === 0) {
                    $cache->values[$operation][$key] = $replacement; $replaced++;
                }
            } }
            try {
                if ($replaced === 0) { throw new RuntimeException('Metadata control did not replace a real native snapshot: '.$label); }
                $active = match (true) {
                    $original instanceof \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata => $context->codebase->getClassLike($original->name),
                    $original === $property => $context->codebase->getDeclaringMagicProperty('Row', '$amount'),
                    $original === $query => $context->codebase->getDeclaringMethod('Row', 'query'),
                    $original === $setter => $context->codebase->getMethod('Illuminate\\Database\\Eloquent\\Builder', 'setModel'),
                    $original === $abstract => $context->codebase->getMethod('Illuminate\\Contracts\\Support\\Arrayable', 'toArray'),
                    $original === $alias => $context->codebase->getDeclaringMethod('Illuminate\\Database\\Eloquent\\Builder', 'baseSole'),
                    $original === $zip => $context->codebase->getMethod('Illuminate\\Support\\Collection', 'zip'),
                    $original === $interfaceZip => $context->codebase->getMethod('Illuminate\\Support\\Enumerable', 'zip'),
                    default => null,
                };
                if ($active !== $replacement) { throw new RuntimeException('Metadata control did not alter its active native lookup: '.$label); }
                // SDK metadata is frozen per run; each mutation represents a different initial snapshot.
                (new ReflectionProperty($probe, 'contract'))->setValue($probe,
                    new \Ichinya\Laramago\Analyzer\StaticAnalysis\ContextualCollectionMemberContract($root, $probe->members));
                $expect($label, false);
            }
            finally { $cache->values = $snapshot; }
            (new ReflectionProperty($probe, 'contract'))->setValue($probe,
                new \Ichinya\Laramago\Analyzer\StaticAnalysis\ContextualCollectionMemberContract($root, $probe->members));
            $expect('metadata restoration '.$label, true);
        }
        $expect('metadata restored', true);
        foreach (['property spelling' => ['declarations.php', '@property-read string $amount', '@property-read float  $amount'],
            'property name' => ['declarations.php', '@property-read string $amount', '@property-read string $amounz'],
            'native event scalar' => ['laravel/framework/src/Illuminate/Database/Eloquent/Model.php', "'retrieved'", "'redirectd'"],
            'native model setter identity' => ['laravel/framework/src/Illuminate/Database/Eloquent/Builder.php', '$this->model = $model;', '$this->model = $other;'],
            'native iterator body' => ['laravel/framework/src/Illuminate/Collections/Collection.php', 'new \\ArrayIterator($this->items)', 'new \\ArrayIterator($this->other)'],
            'native class namespace' => ['laravel/framework/src/Illuminate/Database/Eloquent/Model.php', 'namespace Illuminate\\Database\\Eloquent;', 'namespace Illuminate\\Database\\Elsequen;']] as $label => [$path, $needle, $replacement]) {
            $original = file_get_contents($root.'/'.$path);
            if (! str_contains($original, $needle)) { throw new RuntimeException('Missing source control anchor: '.$label); }
            file_put_contents($root.'/'.$path, str_replace($needle, $replacement, $original));
            try { $expect('cached changed source '.$label, false); }
            finally { file_put_contents($root.'/'.$path, $original); }
            $expect('source restored '.$label, true);
            file_put_contents($root.'/'.$path, str_replace($needle, $replacement, $original));
            try {
                $fresh = new ContextualCollectionMemberProvider($root);
                $fresh->members->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($context->phpVersion, $context->cancellation, [$host], true, true));
                if ($fresh->getPropertyType($context) !== null) { throw new RuntimeException('Stale declaration before first resolution: '.$label); }
                $checks[] = 'changed source before first resolution '.$label;
            } finally { file_put_contents($root.'/'.$path, $original); }
        }
        foreach (['native alias unknown method' => ['laravel/framework/src/Illuminate/Database/Eloquent/Builder.php', 'BuildsQueries::sole as baseSole', 'BuildsQueries::solz as baseSole'],
            'native alias unknown name' => ['laravel/framework/src/Illuminate/Database/Eloquent/Builder.php', 'sole as baseSole', 'sole as baseZole'],
            'native alias guarded member collision' => ['laravel/framework/src/Illuminate/Database/Eloquent/Builder.php', 'sole as baseSole', 'sole as hydrate '],
            'native zip missing documented ellipsis' => ['laravel/framework/src/Illuminate/Collections/Collection.php', ' ...$items', '    $items'],
            'native zip different documented parameter' => ['laravel/framework/src/Illuminate/Collections/Collection.php', ' ...$items', ' ...$other'],
            'native zip current missing args-reader' => ['laravel/framework/src/Illuminate/Collections/Collection.php', 'func_get_args()', 'func_get_argz()'],
            'native enumerable missing documented ellipsis' => ['laravel/framework/src/Illuminate/Collections/Enumerable.php', ' ...$items', '    $items'],
            'native enumerable different documented parameter' => ['laravel/framework/src/Illuminate/Collections/Enumerable.php', ' ...$items', ' ...$other'],
            'native enumerable different type expression' => ['laravel/framework/src/Illuminate/Collections/Enumerable.php', 'iterable<array-key, TZipValue>', 'iterable<array-key, TZipOther>'],
            'native enumerable physical parameter disagrees' => ['laravel/framework/src/Illuminate/Collections/Enumerable.php', 'function zip($items)', 'function zip($other)'],
            'native zip counterpart body differs' => ['laravel/framework/src/Illuminate/Collections/Collection.php', 'array_map(...$params)', 'array_map(...$parazz)']] as $label => [$path, $needle, $replacement]) {
            $original = file_get_contents($root.'/'.$path);
            if (! str_contains($original, $needle)) { throw new RuntimeException('Missing faithful native source control: '.$label); }
            file_put_contents($root.'/'.$path, str_replace($needle, $replacement, $original));
            try {
                $fresh = new ContextualCollectionMemberProvider($root);
                $fresh->members->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($context->phpVersion, $context->cancellation, [$host], true, true));
                if ($fresh->getPropertyType($context) !== null) { throw new RuntimeException('Changed current source accepted: '.$label); }
                $checks[] = $label;
            } finally { file_put_contents($root.'/'.$path, $original); }
        }
        $native = $root.'/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php';
        $original = file_get_contents($native);
        file_put_contents($native, $original.' function array_map($callback, $items) { return [new \\OtherRow]; }');
        try {
            $fresh = new ContextualCollectionMemberProvider($root);
            $fresh->members->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($context->phpVersion, $context->cancellation, [$host], true, true));
            if ($fresh->getPropertyType($context) !== null) { throw new RuntimeException('Current same-file builtin shadow bypassed stale native metadata.'); }
            $checks[] = 'same-file builtin shadow before first resolution';
        } finally { file_put_contents($native, $original); }
        $setterBody = '$this->model = $model;' . "\n        " . '$this->query->from($model->getTable());' . "\n        " . 'return $this;';
        if (! str_contains($original, $setterBody)) { throw new RuntimeException('Missing full native setter source control.'); }
        $wrongBody = str_pad('$this->model = new \\OtherRow; return $this;', strlen($setterBody));
        file_put_contents($native, str_replace($setterBody, $wrongBody, $original));
        try {
            $fresh = new ContextualCollectionMemberProvider($root);
            $fresh->members->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($context->phpVersion, $context->cancellation, [$host], true, true));
            if ($fresh->getPropertyType($context) !== null) { throw new RuntimeException('Changed native setter selected another model before first resolution.'); }
            $checks[] = 'native setter replaces model before first resolution';
        } finally { file_put_contents($native, $original); }
        $probe->initialize(new \Mago\Sdk\Analyzer\InitializationContext($context->phpVersion, $context->cancellation));
        $expect('initialization reset', false);
        $scan([$host]); $expect('reinitialized complete scan', true);
        file_put_contents($root.'/guard-checks.json', json_encode($checks, JSON_THROW_ON_ERROR));
    }
}
final class MemberPlugin implements Plugin {
    public function __construct(private readonly string $root, private readonly bool $observe, private readonly bool $guards = false) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('members', 'Members', 'Invented contextual member fixture.'); }
    public function register(PluginRegistry $registry): void {
        $provider = new ContextualCollectionMemberProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->members);
        $registry->registerPropertyTypeProvider(new ObservedMembers($provider, $this->root.'/audit.jsonl', $this->observe, $this->guards));
        $chunks = new EloquentChunkCallbackProvider($this->root);
        $registry->registerInitializationHook($chunks);
        $registry->registerMethodReturnTypeProvider($chunks);
    }
}
$plugins = [new MemberPlugin($argv[2], $argv[3] === 'observe', $argv[3] === 'guards')];
if ($argv[3] === 'integrated') { $plugins[] = new LaravelPlugin($argv[2]); }
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(identifier: 'members', name: 'Members', version: '1', analyzerPlugins: $plugins)))->run();
PHP);
$config = [
    'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => [$framework, 'declarations.php']],
    'extension-hosts' => ['members' => ['command' => [PHP_BINARY, $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace, $observe ? 'observe' : ($integrated ? 'integrated' : 'standalone')], 'workers' => 1]],
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Workspace: '.$workspace."\n";
$run = static function (string $mode, int $workers = 1) use ($config, $workspace, $command, $package): array {
    $current = $config;
    $current['extension-hosts']['members']['workers'] = $workers;
    $current['extension-hosts']['members']['command'][4] = $mode;
    if (str_starts_with($mode, 'integrated')) {
        $current['extension-hosts']['members']['command'] = [PHP_BINARY, '-d', 'opcache.enable_cli=0', $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace];
    }
    file_put_contents($workspace.'/mago.json', json_encode($current, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]); $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request|Parse error|Fatal error/i', $stderr)) {
        throw new RuntimeException('Unexpected Mago exit '.$exit.'; inspect '.$workspace.'/'.$mode.'.log: '.$stderr);
    }
    return json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR);
};
$native = $observe || $probe ? null : $run('observe');
$report = $run($observe ? 'observe' : 'standalone');
$observations = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($workspace.'/audit.jsonl', FILE_IGNORE_NEW_LINES) ?: []);
if ($observe || in_array('--probe', $argv, true)) {
    foreach ($observations as $entry) { echo json_encode($entry, JSON_THROW_ON_ERROR)."\n"; }
    echo 'PASS: read-only native metadata observation; '.count($observations).' property requests.' ."\n";
    exit(0);
}
foreach ($ranges as [$name, $start, $end, $eligible]) {
    $provided = array_values(array_filter($observations, static fn (array $entry): bool => $entry['start'] >= $start && $entry['end'] <= $end && $entry['type'] !== null));
    if (($provided !== []) !== $eligible) { throw new RuntimeException($name.': contextual domain presence mismatch; inspect '.$workspace); }
    if ($eligible) {
        $expected = match ($name) {
            'native integer declaration retains its type', 'public integer shadow keeps its declaration' => 'int',
            'nullable read is preserved' => 'null|string',
            'documented literal retains its spaces' => 'string',
            'known decimal cast refines a general item property', 'cast item read retains wrong array consumer error' => 'string|null',
            'explicit item read keeps priority over a cast' => 'float',
            default => 'string',
        };
        foreach ($provided as $entry) { if ($entry['type'] !== $expected) { throw new RuntimeException($name.': wrong exact domain '.$entry['type'].'; inspect '.$workspace); } }
        if (in_array($name, ['known decimal cast refines a general item property', 'cast item read retains wrong array consumer error'], true)) {
            // SDK display omits numeric-string refinement; compare the actual native type.
            foreach ($provided as $entry) {
                if ($entry['decimalEquality'] !== true || $entry['decimalAcceptsGeneralString'] !== false) {
                    throw new RuntimeException('A decimal read lost its numeric-string refinement; inspect '.$workspace);
                }
            }
        }
        if ($name === 'documented literal retains its spaces') {
            foreach ($provided as $entry) {
                if ($entry['literalEquality'] !== true || $entry['literalAcceptsGeneralString'] !== false || $entry['literalAcceptsDifferentValue'] !== false) {
                    throw new RuntimeException('Literal read widened or lost its actual value; inspect '.$workspace);
                }
            }
        }
    }
    if (in_array($name, ['wrong scalar consumer is still invalid', 'cast item read retains wrong array consumer error'], true)) {
        $invalid = array_filter($report['issues'] ?? [], static function (array $issue) use ($start, $end): bool {
            foreach ($issue['annotations'] ?? [] as $annotation) {
                if ($annotation['kind'] === 'Primary' && $annotation['span']['start']['offset'] >= $start
                    && $annotation['span']['end']['offset'] <= $end && $issue['level'] === 'Error') { return true; }
            }
            return false;
        });
        if ($invalid === []) { throw new RuntimeException('A wrong scalar consumer was hidden; inspect '.$workspace); }
    }
    echo 'PASS: '.$name."\n";
}
$group = static function (array $report, int $start, int $end): array {
    $issues = [];
    foreach ($report['issues'] ?? [] as $issue) {
        foreach ($issue['annotations'] ?? [] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['start']['offset'] >= $start && $annotation['span']['end']['offset'] <= $end) {
                $issues[] = json_encode($issue, JSON_THROW_ON_ERROR); break;
            }
        }
    }
    sort($issues); return $issues;
};
$corrected = $retained = $nativeCovered = 0;
foreach ($ranges as [$name, $start, $end, $eligible]) {
    $before = $group($native, $start, $end); $after = $group($report, $start, $end);
    if (! $eligible) {
        if ($before !== $after) { throw new RuntimeException('A deferred exact native report changed: '.$name.'; inspect '.$workspace); }
        $retained += count($before);
        if ($before === []) { $nativeCovered++; }
    } else {
        $warnings = static fn (array $report): array => array_values(array_filter($report['issues'] ?? [], static function (array $issue) use ($start, $end): bool {
            if ($issue['code'] !== 'non-documented-property') { return false; }
            foreach ($issue['annotations'] ?? [] as $annotation) {
                if ($annotation['kind'] === 'Primary' && $annotation['span']['start']['offset'] >= $start && $annotation['span']['end']['offset'] <= $end) { return true; }
            }
            return false;
        }));
        if ($warnings($native) === [] || $warnings($report) !== []) { throw new RuntimeException('Missing non-vacuous native property correction: '.$name.'; inspect '.$workspace); }
        $corrected += count($warnings($native));
    }
}
echo 'PASS: '.count($ranges).' source cases, '.$corrected.' exact documented-member corrections, '.$retained.' retained negative reports, '.$nativeCovered." native-covered cases.\n";
$guards = $run('guards');
if ($group($guards, 0, strlen($source)) !== $group($report, 0, strlen($source))) { throw new RuntimeException('Genuine guard controls changed exact caller diagnostics; inspect '.$workspace); }
$checks = is_file($workspace.'/guard-checks.json') ? json_decode(file_get_contents($workspace.'/guard-checks.json'), true, flags: JSON_THROW_ON_ERROR) : [];
if (count($checks) < 45) { throw new RuntimeException('Missing genuine context and lifecycle controls; inspect '.$workspace); }
echo 'PASS: '.count($checks)." genuine context, metadata, source and lifecycle controls.\n";
if ($integrated) {
    $one = $run('integrated-one'); $three = $run('integrated-three', 3);
    if ($group($one, 0, strlen($source)) !== $group($three, 0, strlen($source)) || $group($one, 0, strlen($source)) !== $group($report, 0, strlen($source))) {
        throw new RuntimeException('Integrated one/three worker exact signatures differ; inspect '.$workspace);
    }
    echo "PASS: actual package worker, one and three integrated workers have exact caller signatures.\n";
}
