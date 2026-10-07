<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago aggregate projections '.bin2hex(random_bytes(8));
$mode = $argv[1] ?? 'normal';
$framework = $workspace.'/laravel/framework/src/Illuminate/Database';
mkdir($framework.'/Eloquent', 0777, true);
mkdir($framework.'/Query', 0777, true);
mkdir($workspace.'/database/migrations', 0777, true);
file_put_contents($workspace.'/composer.json', '{}');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never execute the application.");');
copy(__DIR__.'/fixtures/analysis/query-projections-migration.php.stub', $workspace.'/database/migrations/001_create.php');
file_put_contents($framework.'/Query/Builder.php', <<<'PHP'
<?php
namespace Illuminate\Database\Query;
class Expression { public function __construct(string $value) {} }
class Builder {
    /** @return $this */
    public function selectRaw($expression, array $bindings = []) {
        $this->addSelect(new Expression($expression));
        if ($bindings) { $this->addBinding($bindings, 'select'); }
        return $this;
    }
    /** @return $this */ public function select($columns = ['*']) {}
    /** @return $this */ public function groupBy(...$groups) {}
    /** @return $this */ public function join($table, $first, $operator, $second) {}
}
PHP);
file_put_contents($framework.'/Eloquent/Builder.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
/** @template TModel of Model
 * @mixin \Illuminate\Database\Query\Builder */
class Builder {
    /** @return $this */ public function where($column, $operator = null, $value = null, $boolean = 'and') {}
    /** @return Collection<int, TModel> */
    public function get($columns = ['*']) {
        $builder = $this->applyScopes();
        if (count($models = $builder->getModels($columns)) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }
        return $this->applyAfterQueryCallbacks($builder->getModel()->newCollection($models));
    }
    public function __call(string $method, array $parameters): mixed {}
}
PHP);
file_put_contents($framework.'/Eloquent/Model.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
class Model {
    protected static string $builder = Builder::class;
    protected $table;
    protected $casts = [];
    protected $primaryKey = 'id';
    protected $keyType = 'int';
    public $incrementing = true;
    public $timestamps = true;
    /** @var string|null */ public const CREATED_AT = 'created_at';
    /** @var string|null */ public const UPDATED_AT = 'updated_at';
    /** @return Builder<static> */ public static function query() {}
    /** @return Builder<static> */ public function newQuery() {}
    /** @return Builder<static> */ public function newModelQuery() {}
    /** @return Builder<static> */ public function newQueryWithoutScopes() {}
    /** @return Builder<static> */ public function newQueryWithoutRelationships() {}
    /** @return Builder<static> */ public function newEloquentBuilder($query) {}
    public static function updating($callback) { static::registerModelEvent('updating', $callback); }
    public function __get(string $key): mixed {}
    public function __call(string $method, array $parameters): mixed {}
    public static function __callStatic(string $method, array $parameters): mixed {}
}
/** @template TKey of array-key
 * @template TModel of Model */
class Collection {
    /** @return static<array-key, TModel> */ public function keyBy(string $key) {}
    /** @return TModel|null */ public function first() {}
    /** @return TModel|null */ public function get(int|string $key) {}
}
PHP);
file_put_contents($workspace.'/models.php', <<<'PHP'
<?php
use Illuminate\Database\Eloquent\Model;
class ProjectionRecord extends Model {}
interface ProjectionContract {}
class ContractProjectionRecord extends Model implements ProjectionContract { protected $table = 'projection_records'; }
/** @extends \Illuminate\Database\Eloquent\Builder<CustomBuilderProjectionRecord> */
class CustomProjectionBuilder extends \Illuminate\Database\Eloquent\Builder {}
class CustomBuilderProjectionRecord extends Model { protected $table = 'projection_records'; protected static string $builder = CustomProjectionBuilder::class; }
class HydrationProjectionRecord extends Model { protected $table = 'projection_records'; public function newFromBuilder($attributes = [], $connection = null): static { return new static; } }
class NativeScopeProjectionRecord extends Model { protected $table = 'projection_records'; public function scopeSelectRaw($query, string $expression): mixed { return $query; } }
class EventProjectionRecord extends Model { protected $table = 'projection_records'; protected static function booted(): void { static::updating(function (self $model): void { throw new \RuntimeException('Never execute write events.'); }); } }
class RetrievedProjectionRecord extends Model { protected $table = 'projection_records'; protected static function booted(): void { static::retrieved(function (self $model): void {}); } }
class CastProjectionRecord extends Model { protected $table = 'projection_records'; protected $casts = ['total' => 'bool']; }
class ScopedProjectionRecord extends Model { protected $table = 'projection_records'; protected static function booted(): void { static::addGlobalScope("custom", fn ($query) => $query); } }
class AccessorProjectionRecord extends Model { protected $table = 'projection_records'; public function getTotalAttribute(): string { return 'custom'; } }
PHP);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
use Ichinya\Laramago\Analyzer\EloquentAggregateProjectionProvider;
use Ichinya\Laramago\Analyzer\EloquentQueryProvider;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
final class AggregateProjectionPlugin implements Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('aggregate-projection', 'Aggregate projection', 'Synthetic regression.'); }
    public function register(PluginRegistry $registry): void {
        $provider = new EloquentAggregateProjectionProvider($this->root);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerInitializationHook($provider);
        $registry->registerMethodReturnTypeProvider($provider);
        $registry->registerMethodReturnTypeProvider(new EloquentQueryProvider);
        $registry->registerMethodReturnTypeProvider(new Ichinya\Laramago\Analyzer\EloquentWhereProvider);
    }
}
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(identifier: 'aggregate-projection', name: 'Aggregate projection', version: '1', analyzerPlugins: $argv[3] === 'integrated' ? [new Ichinya\Laramago\Analyzer\AggregateProjectionPlugin($argv[2]), new Ichinya\Laramago\Analyzer\LaravelPlugin($argv[2])] : [new AggregateProjectionPlugin($argv[2])])))->run();
PHP);
$cases = [
    'custom builder defers' => ['CustomBuilderProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'custom hydration defers' => ['HydrationProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'custom selectRaw scope defers' => ['NativeScopeProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'write event registration preserves projection' => ['return EventProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'int|float|numeric-string|null', []],
    'retrieval listener defers' => ['RetrievedProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'count retains numeric driver types' => ['return ProjectionRecord::query()->selectRaw("COUNT(*) AS count")->get()->first()?->count;', 'int|numeric-string|null', []],
    'interface ancestor retains numeric projection' => ['return ContractProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'int|float|numeric-string|null', []],
    'interface ancestor preserves wrong return error' => ['return ContractProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', '?int', ['invalid-return-statement']],
    'alias typo remains unknown' => ['ProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->totla;', 'void', ['non-documented-property']],
    'dynamic SQL defers' => ['$sql = "SUM(amount) AS total"; ProjectionRecord::query()->selectRaw($sql)->get()->first()?->total;', 'void', ['non-documented-property']],
    'join defers' => ['ProjectionRecord::query()->join("other", "id", "=", "other.id")->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'unsupported aggregate defers' => ['ProjectionRecord::query()->selectRaw("MAX(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'duplicate aggregate aliases defer' => ['ProjectionRecord::query()->selectRaw("COUNT(*) AS total, SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'sum retains nullable driver numeric types' => ['return ProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'int|float|numeric-string|null', []],
    'aggregate aliases survive keyBy' => ['return ProjectionRecord::where("id", 1)->selectRaw("name, COUNT(*) AS count, SUM(amount) AS total")->groupBy("name")->get()->keyBy("name")->get("first")?->total;', 'int|float|numeric-string|null', []],
    'sum is not an integer contract' => ['return ProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', '?int', ['invalid-return-statement']],
    'projection reset defers' => ['ProjectionRecord::query()->selectRaw("SUM(amount) AS total")->select("id")->get()->first()?->total;', 'void', ['non-documented-property']],
    'builder variable defers' => ['$query = ProjectionRecord::query()->selectRaw("SUM(amount) AS total"); $query->get()->first()?->total;', 'void', ['non-documented-property']],
    'unknown numeric source defers' => ['ProjectionRecord::query()->selectRaw("SUM(missing) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'non numeric schema source defers' => ['ProjectionRecord::query()->selectRaw("SUM(name) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'bindings defer' => ['ProjectionRecord::query()->selectRaw("SUM(amount) AS total", [1])->get()->first()?->total;', 'void', ['non-documented-property']],
    'custom cast defers' => ['CastProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'model boot scopes defer' => ['ScopedProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'custom accessor defers' => ['AccessorProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'unprojected model remains unknown' => ['ProjectionRecord::query()->get()->first()?->total;', 'void', ['non-documented-property']],
];
// Each override can replace, transform, or redirect a hydrated aggregate alias.
foreach ([
    'transformModelValue', 'getAttributeFromArray', 'getGlobalScopes',
    'resolveGlobalScopeAttributes', 'addGlobalScopes', 'addGlobalScope',
    'hasAttribute', 'hasGetMutator', 'hasAttributeMutator', 'hasAttributeGetMutator',
    'mergeAttributeFromCachedCasts', 'getAttributes', 'mergeAttributesFromCachedCasts',
    'mergeAttributesFromClassCasts', 'mergeAttributesFromAttributeCasts', 'syncOriginal',
    'fireModelEvent', 'setConnection',
] as $hook) {
    $class = ucfirst($hook).'ProjectionRecord';
    file_put_contents($workspace.'/models.php', "\n".'class '.$class.' extends Model { protected $table = "projection_records"; public function '.$hook.'(...$arguments): mixed { return "not numeric"; } }', FILE_APPEND);
    $cases['custom '.$hook.' hook defers'] = [$class.'::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']];
}
if ($mode === 'integrated') {
    $cases['custom cast defers'] = ['return CastProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', '?bool', []];
    $cases['custom accessor defers'] = ['return AccessorProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', '?string', []];
}
if ($mode === 'changed-body' || $mode === 'collision') {
    $cases = [
    'custom builder defers' => ['CustomBuilderProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'custom hydration defers' => ['HydrationProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],
    'custom selectRaw scope defers' => ['NativeScopeProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']],'uncertain projection stays unknown' => ['ProjectionRecord::query()->selectRaw("SUM(amount) AS total")->get()->first()?->total;', 'void', ['non-documented-property']]];
}
if ($mode === 'changed-body') {
    $file = $framework.'/Query/Builder.php';
    file_put_contents($file, str_replace("$"."this->addSelect(new Expression($"."expression));", "$"."this->addSelect(new Expression('custom'));", file_get_contents($file)));
}
$source = '<?php'."\n";
$lines = [];
foreach ($cases as $name => [$body, $return, $expected]) {
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'(): '.($return === 'void' ? 'void' : 'mixed').' { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $expected];
}
file_put_contents($workspace.'/cases.php', $source);
if ($mode === 'collision') {
    file_put_contents($workspace.'/other.php', str_replace(['scenario', 'SUM(amount)'], ['negative', 'MAX(amount)'], $source));
}

file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
    'source' => ['paths' => $mode === 'collision' ? ['cases.php', 'other.php'] : ['cases.php'], 'includes' => [$framework, 'models.php']],
    'extension-hosts' => ['aggregate-projection' => ['command' => $mode === 'integrated'
        ? [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]
        : [PHP_BINARY, $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace, $mode], 'workers' => 2]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']], $pipes);
if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
fclose($pipes[0]);
$exit = proc_close($process);
$stderr = file_get_contents($workspace.'/stderr.log');
if (! in_array($exit, [0, 1], true) || preg_match('/External analyzer provider failed|extension worker .*rejected request|(?:PHP )?Warning:|fatal error|orchestrator error|pars(?:e|ing) errors?/i', $stderr)) { throw new RuntimeException('Unexpected analyzer result: '.$workspace.' '.$stderr); }
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    if ($issue['level'] === 'Help' || $issue['level'] === 'Note') { continue; }
    foreach ($issue['annotations'] as $annotation) {
        if ($annotation['kind'] === 'Primary') {
            $file = $annotation['span']['file_id']['name'];
            if ($mode === 'collision' && str_ends_with($file, 'other.php')) {
                if ($issue['code'] !== 'non-documented-property') { throw new RuntimeException('Unexpected collision control diagnostic: '.$issue['code']); }
                continue;
            }
            $actual[$annotation['span']['start']['line'] + 1][] = $issue['code']; break;
        }
    }
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes); sort($expected);
    if ($codes !== $expected) { throw new RuntimeException($name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace); }
    unset($actual[$line]); echo 'PASS: '.$name."\n";
}
if ($actual !== []) { throw new RuntimeException('Unexpected diagnostics outside scenarios; inspect '.$workspace); }
$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
    $path = $item->getPathname();
    if (! str_starts_with($path, $resolved.DIRECTORY_SEPARATOR)) { throw new RuntimeException('Refusing cleanup outside the test workspace.'); }
    $item->isDir() ? rmdir($path) : unlink($path);
}
rmdir($resolved);
