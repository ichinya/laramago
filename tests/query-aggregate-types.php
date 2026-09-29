<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$changedBody = in_array('--changed-body', $argv, true);
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago aggregate types '.bin2hex(random_bytes(8));
$framework = $workspace.'/laravel/framework/src/Illuminate';
mkdir($framework.'/Database/Query', 0777, true);
mkdir($framework.'/Database/Eloquent/Relations', 0777, true);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap test projects.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
file_put_contents($framework.'/Database/Query/Builder.php', <<<'PHP'
<?php
namespace Illuminate\Contracts\Database\Query;
interface Expression {}
namespace Illuminate\Database\Query;
class Builder {
    /**
     * @param \Illuminate\Contracts\Database\Query\Expression|string $column
     * @return mixed
     */
    public function sum($column) {
        $result = $this->aggregate(__FUNCTION__, [$column]);
        return $result ?: 0;
    }
    /**
     * @param \Illuminate\Contracts\Database\Query\Expression|string $column
     * @return mixed
     */
    public function avg($column) {
        return $this->aggregate(__FUNCTION__, [$column]);
    }
    /**
     * @param \Illuminate\Contracts\Database\Query\Expression|string $column
     * @return mixed
     */
    public function average($column) {
        return $this->avg($column);
    }
    /** @return mixed */
    public function aggregate($function, $columns = ['*']) { return null; }
}
PHP);
if ($changedBody) {
    $builderFile = $framework.'/Database/Query/Builder.php';
    file_put_contents($builderFile, str_replace('return $result ?: 0;', 'return "custom";', file_get_contents($builderFile)));
}
file_put_contents($framework.'/Database/Eloquent/Builder.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
/**
 * @template TModel of Model = Model
 * @mixin \Illuminate\Database\Query\Builder
 */
class Builder {
    public function __call(string $method, array $parameters): mixed {
        return (new \Illuminate\Database\Query\Builder)->{$method}(...$parameters);
    }
    public function getQuery(): \Illuminate\Database\Query\Builder { return new \Illuminate\Database\Query\Builder; }
    public function toBase(): \Illuminate\Database\Query\Builder { return $this->getQuery(); }
}
PHP);
file_put_contents($framework.'/Database/Eloquent/Model.php', <<<'PHP'
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
    public function __call(string $method, array $parameters): mixed {}
    public static function __callStatic(string $method, array $parameters): mixed {}
    /** @return Builder<static> */
    public function newEloquentBuilder($query) {}
}
PHP);
file_put_contents($framework.'/Database/Eloquent/Relations/HasMany.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent\Relations;
/** @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @mixin \Illuminate\Database\Eloquent\Builder<TRelatedModel>
 */
class Relation {
    public function __call(string $method, array $parameters): mixed {
        return (new \Illuminate\Database\Eloquent\Builder)->{$method}(...$parameters);
    }
    public function getQuery(): \Illuminate\Database\Eloquent\Builder { return new \Illuminate\Database\Eloquent\Builder; }
}
/** @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @extends Relation<TRelatedModel>
 * @mixin \Illuminate\Database\Eloquent\Builder<TRelatedModel>
 */
class HasMany extends Relation {}
PHP);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
use Ichinya\Laramago\Analyzer\EloquentQueryProvider;
use Ichinya\Laramago\Analyzer\EloquentRelationProvider;
use Ichinya\Laramago\Analyzer\QueryAggregateProvider;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
final class AggregateTypesPlugin implements Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition {
        return new PluginDefinition('aggregate-types', 'Aggregate types', 'Test-only aggregate contract.');
    }
    public function register(PluginRegistry $registry): void {
        $aggregate = new QueryAggregateProvider($this->root);
        $registry->registerMethodReturnTypeProvider($aggregate);
        $registry->registerInitializationHook($aggregate);
        $queries = new EloquentQueryProvider;
        $registry->registerMethodReturnTypeProvider($queries);
        $relations = new EloquentRelationProvider;
        $registry->registerMethodReturnTypeProvider($relations);
    }
}
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(
    identifier: 'aggregate-types',
    name: 'Aggregate types',
    version: '1',
    analyzerPlugins: [new AggregateTypesPlugin($argv[2])],
)))->run();
PHP);

$cases = $changedBody ? [
    'changed installed sum body defers' => ['return $query->sum("total");', 'int|float|numeric-string', ['mixed-return-statement']],
] : [
    'direct sum accepts numeric union' => ['return $query->sum("total");', 'int|float|numeric-string', []],
    'direct avg is nullable' => ['return $query->avg("total");', 'int|float|numeric-string|null', []],
    'direct average is nullable' => ['return $query->average("total");', 'int|float|numeric-string|null', []],
    'sum is not necessarily int' => ['return $query->sum("total");', 'int', ['invalid-return-statement']],
    'sum is not necessarily string' => ['return $query->sum("total");', 'string', ['invalid-return-statement']],
    'avg is not necessarily non-null' => ['return $query->avg("total");', 'int|float|numeric-string', ['invalid-return-statement', 'nullable-return-statement']],
    'model sum forwards' => ['return Record::sum("total");', 'int|float|numeric-string', []],
    'model avg forwards' => ['return Record::avg("total");', 'int|float|numeric-string|null', []],
    'eloquent sum forwards' => ['return $builder->sum("total");', 'int|float|numeric-string', []],
    'eloquent average forwards' => ['return $builder->average("total");', 'int|float|numeric-string|null', []],
    'relation sum forwards' => ['return $relation->sum("total");', 'int|float|numeric-string', []],
    'relation avg forwards' => ['return $relation->avg("total");', 'int|float|numeric-string|null', []],
    'named column accepted' => ['return $query->sum(column: "total");', 'int|float|numeric-string', []],
    'missing column stays invalid' => ['$query->sum();', 'void', ['too-few-arguments']],
    'extra column stays invalid' => ['$query->sum("a", "b");', 'void', ['too-many-arguments']],
    'invalid named column stays invalid' => ['$query->sum(typo: "a");', 'void', ['invalid-named-argument']],
    'unpacked arguments use native analysis' => ['return $query->sum(...["total"]);', 'mixed', []],
    'custom query override wins' => ['return $custom->sum("total");', 'string', []],
    'custom query aggregate defers' => ['return $aggregate->sum("total");', 'mixed', []],
    'custom eloquent override wins' => ['return $customBuilder->sum("total");', 'string', []],
    'custom model override wins' => ['return CustomRecord::sum("total");', 'string', []],
    'model scope defers to scope analysis' => ['return ScopedRecord::sum("total");', 'string', ['mixed-return-statement', 'non-documented-method']],
];
$source = <<<'PHP'
<?php
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Record extends Model {}
class CustomRecord extends Model { public static function sum(string $column): string { return 'custom'; } }
class ScopedRecord extends Model { public function scopeSum($query, string $column): string { return 'scoped'; } }
class CustomQueryBuilder extends QueryBuilder { public function sum($column): string { return 'custom'; } }
class CustomAggregateBuilder extends QueryBuilder { public function aggregate($function, $columns = ['*']): mixed { return 1; } }
class CustomEloquentBuilder extends EloquentBuilder { public function sum($column): string { return 'custom'; } }
PHP;
$lines = [];
foreach ($cases as $name => [$body, $returnType, $expected]) {
    $index = count($lines);
    $source .= '/**' . "\n".' * @param HasMany<Record> $relation'."\n".' * @return '.$returnType."\n".' */'."\n";
    $source .= 'function scenario'.$index.'(QueryBuilder $query, EloquentBuilder $builder, HasMany $relation, CustomQueryBuilder $custom, CustomAggregateBuilder $aggregate, CustomEloquentBuilder $customBuilder): '.($returnType === 'void' ? 'void' : 'mixed').' { '.$body.' }' . "\n";
    $lines[substr_count($source, "\n")] = [$name, $expected];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => [$framework]],
    'extension-hosts' => ['aggregate-types' => [
        'command' => [PHP_BINARY, $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace],
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
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException($name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace);
    }
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics outside scenarios; inspect '.$workspace);
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
