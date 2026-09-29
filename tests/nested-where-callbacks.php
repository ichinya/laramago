<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago nested where '.bin2hex(random_bytes(8));
$illuminate = $workspace.'/laravel/framework/src/Illuminate';
$framework = $illuminate.'/Database';
mkdir($framework.'/Query', 0777, true);
mkdir($framework.'/Eloquent/Relations', 0777, true);
mkdir($illuminate.'/Contracts/Database/Eloquent', 0777, true);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap test projects.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
file_put_contents($framework.'/Query/Builder.php', <<<'PHP'
<?php
namespace Illuminate\Database\Query;
class Builder {
    public function where($column, $operator = null, $value = null, $boolean = 'and'): static { return $this; }
}
PHP);
file_put_contents($illuminate.'/Contracts/Database/Eloquent/Builder.php', <<<'PHP'
<?php
namespace Illuminate\Contracts\Database\Eloquent;
/** @mixin \Illuminate\Database\Eloquent\Builder */
interface Builder {}
PHP);
file_put_contents($framework.'/Eloquent/Builder.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
/**
 * @template TModel of Model = Model
 */
class Builder {
    /**
     * @param (\Closure(static): mixed)|string|array $column
     * @param mixed $operator
     * @param mixed $value
     * @param string $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and') { return $this; }
    /**
     * @param (\Closure(static): mixed)|string|array $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function orWhere($column, $operator = null, $value = null) { return $this; }
    /** @return $this */
    public function whereNull($column) { return $this; }
    public function __call(string $method, array $arguments): mixed { return null; }
}
PHP);
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
}
PHP);
file_put_contents($framework.'/Eloquent/Relations/HasMany.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent\Relations;
/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 * @template TResult
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
abstract class Relation implements \Illuminate\Contracts\Database\Eloquent\Builder {
    public function __call(string $method, array $parameters): mixed {
        return (new \Illuminate\Database\Eloquent\Builder)->{$method}(...$parameters);
    }
    public function getQuery(): \Illuminate\Database\Eloquent\Builder { return new \Illuminate\Database\Eloquent\Builder; }
}
/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 * @template TResult
 * @extends Relation<TRelatedModel, TDeclaringModel, TResult>
 */
class HasOneOrMany extends Relation {}
/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 * @extends HasOneOrMany<TRelatedModel, TDeclaringModel, array>
 */
class HasMany extends HasOneOrMany {}
PHP);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
use Ichinya\Laramago\Analyzer\EloquentNestedWhereCallbackProvider;
use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
final class ObservedNestedWhereProvider implements CallableSignatureOverride, MethodReturnTypeProvider {
    private readonly EloquentNestedWhereCallbackProvider $inner;
    public function __construct(private readonly string $audit) { $this->inner = new EloquentNestedWhereCallbackProvider; }
    public function getTargets(): array { return $this->inner->getTargets(); }
    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature {
        $signature = $this->inner->getCallableSignature($context);
        $callback = null;
        foreach ($signature?->parameters[0]->type?->atomicTypes ?? [] as $atom) {
            if ($atom instanceof CallableType) {
                $callback = (string) ($atom->signature?->parameters[0]->type);
            }
        }
        file_put_contents($this->audit, json_encode([
            'start' => $context->invocation->span->start,
            'callback' => $callback,
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        return $signature;
    }
    public function getReturnType(ReturnTypeProviderContext $context): ?Type { return null; }
}
final class NestedWherePlugin implements Plugin {
    public function __construct(private readonly string $audit) {}
    public function getDefinition(): PluginDefinition {
        return new PluginDefinition('nested-where-test', 'Nested where test', 'Test-only callback contract.');
    }
    public function register(PluginRegistry $registry): void {
        $registry->registerMethodReturnTypeProvider(new ObservedNestedWhereProvider($this->audit));
    }
}
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(
    identifier: 'nested-where-test',
    name: 'Nested where test',
    version: '1',
    analyzerPlugins: [new NestedWherePlugin($argv[2])],
)))->run();
PHP);
$cases = [
    'nested where types Eloquent builder' => [
        '$relation->where(function ($query) { $query->whereNull("end_date")->orWhere("end_date", ">=", "today"); });',
        [],
    ],
    'nested orWhere types Eloquent builder' => [
        '$relation->orWhere(fn ($query) => $query->whereNull("end_date"));',
        [],
    ],
    'named column callback' => [
        '$relation->where(column: fn ($query) => $query->whereNull("end_date"));',
        [],
    ],
    'unknown builder method remains invalid' => [
        '$relation->where(fn ($query) => $query->doesNotExist());',
        ['non-documented-method'],
    ],
    'custom relation method retains declaration' => [
        '$custom->where(function ($query) { $query->whereNull("end_date"); });',
        ['mixed-method-access'],
    ],
    'custom model builder defers' => [
        '$customModel->where(function ($query) { $query->whereNull("end_date"); });',
        [],
    ],
    'documented relation method defers' => [
        '$documented->where(function ($query) { $query->whereNull("end_date"); });',
        ['mixed-method-access'],
    ],
];
$source = <<<'PHP'
<?php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Record extends Model {}
class CustomModel extends Model {
    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function newEloquentBuilder($query) { return $this->newQuery(); }
}
/** @extends HasMany<Record, Record> */
class CustomHasMany extends HasMany {
    public function where($column): string { return 'custom'; }
}
/**
 * @extends HasMany<Record, Record>
 * @method string where(\Closure $column)
 */
class DocumentedHasMany extends HasMany {}
PHP;
$lines = [];
foreach ($cases as $name => [$body, $expected]) {
    $source .= '/** @param HasMany<Record, Record> $relation'.("\n").' * @param HasMany<CustomModel, Record> $customModel'.("\n").' */'.("\n");
    $source .= 'function scenario'.count($lines).'(HasMany $relation, CustomHasMany $custom, HasMany $customModel, DocumentedHasMany $documented): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $expected];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => [$illuminate]],
    'extension-hosts' => ['nested-where-test' => [
        'command' => [PHP_BINARY, $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace.'/signature-audit.jsonl'],
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
    throw new RuntimeException('Unexpected diagnostics outside scenarios; inspect '.$workspace);
}
$observed = [];
foreach (file($workspace.'/signature-audit.jsonl', FILE_IGNORE_NEW_LINES) ?: [] as $record) {
    $entry = json_decode($record, true, flags: JSON_THROW_ON_ERROR);
    $line = substr_count(substr($source, 0, $entry['start']), "\n") + 1;
    $observed[$line][] = $entry['callback'];
}
foreach ($lines as $line => [$name]) {
    $callbacks = $observed[$line] ?? [];
    $shouldBind = ! in_array($name, [
        'custom relation method retains declaration',
        'custom model builder defers',
        'documented relation method defers',
    ], true);
    if ($shouldBind && ! in_array('Illuminate\\Database\\Eloquent\\Builder<Record>', $callbacks, true)
        || ! $shouldBind && array_filter($callbacks, static fn (?string $callback): bool => $callback !== null) !== []) {
        throw new RuntimeException('Unexpected callback binding for '.$name.': '.json_encode($callbacks).'; inspect '.$workspace);
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
