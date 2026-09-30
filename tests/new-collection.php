<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$integrated = in_array('--integrated', $argv, true);
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago new collection '.bin2hex(random_bytes(8));
$framework = $workspace.'/laravel/framework/src/Illuminate/Database/Eloquent';
mkdir($framework.'/Attributes', 0o777, true);
file_put_contents($workspace.'/composer.json', '{"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Do not bootstrap the application.");');
file_put_contents($framework.'/Attributes/CollectedBy.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent\Attributes;
#[\Attribute(\Attribute::TARGET_CLASS)]
class CollectedBy { public function __construct(public string $collection) {} }
PHP);
file_put_contents($framework.'/Model.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
class Model {
    /** @use HasCollection<\Illuminate\Database\Eloquent\Collection<array-key, static & self>> */
    use HasCollection;
    /** @var class-string<Collection> */
    protected static string $collectionClass = Collection::class;
    public static function isAutomaticallyEagerLoadingRelationships(): bool { return false; }
}
PHP);
file_put_contents($framework.'/Collection.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
/** @template TKey of array-key = array-key
 * @template TModel of Model = Model */
class Collection {
    /** @param array<TKey, TModel> $models */ public function __construct(array $models = []) {}
    /** @return $this */ public function withRelationshipAutoloading() { return $this; }
    /** @return TModel|null */ public function first() { return null; }
}
PHP);
file_put_contents($workspace.'/models.php', <<<'PHP'
<?php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
class Record extends Model { public function label(): string { return ''; } }
class ChildRecord extends Record {}
class OtherRecord extends Model {}
/** @extends Collection<int, Record> */ class Records extends Collection {}
class PropertyCollectionRecord extends Record { protected static string $collectionClass = Records::class; }
#[CollectedBy(Records::class)] class AttributeCollectionRecord extends Record {}
class ExplicitCollectionRecord extends Record {
    public function newCollection(array $models = []): Records { throw new RuntimeException('Do not execute model code.'); }
}
class WrongFactoryRecord extends Record {
    public function newCollection(array $models = []): string { return 'custom'; }
}
class UnknownFactoryRecord extends Record {
    public function newCollection(array $models = []) { throw new RuntimeException('Do not execute model code.'); }
}
class ResolverRecord extends Record {
    public function resolveCollectionFromAttribute() { return Records::class; }
}
class CacheRecord extends Record {
    protected static array $resolvedCollectionClasses = [];
}
class SeededCacheRecord extends Record {
    protected static array $resolvedCollectionClasses = [self::class => Records::class];
}
/** @method Records newCollection(array $models = []) */ class DocumentedRecord extends Record {}
class MappedRecord extends Record {
    /** @use \Illuminate\Database\Eloquent\HasCollection<Records> */
    use \Illuminate\Database\Eloquent\HasCollection;
}
class ReusedRecord extends Record { use \Illuminate\Database\Eloquent\HasCollection; }
class InheritedMappedRecord extends MappedRecord {}
trait WrappedCollection {
    /** @use \Illuminate\Database\Eloquent\HasCollection<Records> */
    use \Illuminate\Database\Eloquent\HasCollection;
}
class WrappedRecord extends Record { use WrappedCollection; }
class Unrelated { public function newCollection(array $models = []): string { return ''; } }
PHP);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
use Ichinya\Laramago\Analyzer\EloquentNewCollectionProvider;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
final class NewCollectionPlugin implements Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('new-collection', 'New collection', 'Synthetic regression.'); }
    public function register(PluginRegistry $registry): void {
        $provider = new EloquentNewCollectionProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerMethodReturnTypeProvider($provider);
    }
}
$plugins = $argv[3] === 'integrated'
    ? [new Ichinya\Laramago\Analyzer\LaravelPlugin($argv[2])]
    : [new NewCollectionPlugin($argv[2])];
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(identifier: 'new-collection', name: 'New collection', version: '1', analyzerPlugins: $plugins)))->run();
PHP);
$cases = [
    'emptyDefault' => ['Collection<int, Record>', '(new Record)->newCollection()', true],
    'emptyLiteral' => ['Collection<int, Record>', '(new Record)->newCollection([])', true],
    'emptyLongSyntax' => ['Collection<int, Record>', '(new Record)->newCollection(array())', true],
    'emptyNamedArgument' => ['Collection<int, Record>', '(new Record)->newCollection(models: [])', true],
    'inheritedModel' => ['Collection<int, ChildRecord>', '(new ChildRecord)->newCollection()', true],
    'populatedListDefers' => ['Collection<int, Record>', '(new Record)->newCollection([new Record])', false],
    'populatedStringKeysDefer' => ['Collection<int, Record>', '(new Record)->newCollection(["named" => new Record])', false],
    'invalidElement' => ['Collection<int, Record>', '(new Record)->newCollection([123])', false],
    'invalidArray' => ['Collection<int, Record>', '(new Record)->newCollection("bad")', false],
    'invalidName' => ['Collection<int, Record>', '(new Record)->newCollection(unknown: [])', false],
    'extraArgument' => ['Collection<int, Record>', '(new Record)->newCollection([], [])', false],
    'unpackedArgument' => ['Collection<int, Record>', '(new Record)->newCollection(...[[]])', false],
    'staticCallDefers' => ['Collection<int, Record>', 'Record::newCollection()', false],
    'wrongRecordType' => ['Collection<int, OtherRecord>', '(new Record)->newCollection()', false],
    'wrongKeyType' => ['Collection<string, Record>', '(new Record)->newCollection()', false],
    'customProperty' => ['Records', '(new PropertyCollectionRecord)->newCollection()', true],
    'customAttribute' => ['Records', '(new AttributeCollectionRecord)->newCollection()', true],
    'customFactoryContract' => ['Records', '(new ExplicitCollectionRecord)->newCollection()', false],
    'wrongFactoryContract' => ['Collection<int, Record>', '(new WrongFactoryRecord)->newCollection()', false],
    'unknownFactoryDefers' => ['Collection<int, Record>', '(new UnknownFactoryRecord)->newCollection()', false],
    'resolverDefers' => ['Collection<int, Record>', '(new ResolverRecord)->newCollection()', false],
    'cacheOverrideDefers' => ['Collection<int, Record>', '(new CacheRecord)->newCollection()', false],
    'cacheSeedDefers' => ['Collection<int, Record>', '(new SeededCacheRecord)->newCollection()', false],
    'documentedMethodDefers' => ['Collection<int, Record>', '(new DocumentedRecord)->newCollection()', false],
    'mappedTraitDefers' => ['Records', '(new MappedRecord)->newCollection()', false],
    'reusedTraitDefers' => ['Collection<int, Record>', '(new ReusedRecord)->newCollection()', false],
    'inheritedMappingDefers' => ['Records', '(new InheritedMappedRecord)->newCollection()', false],
    'wrappedTraitDefers' => ['Records', '(new WrappedRecord)->newCollection()', false],
    'unrelatedDefers' => ['Collection<int, Record>', '(new Unrelated)->newCollection()', false],
];
$source = "<?php\nuse Illuminate\\Database\\Eloquent\\Collection;\n";
$lines = [];
foreach ($cases as $name => [$type, $expression]) {
    $source .= '/** @return '.$type.' */'."\n".'function '.$name.'() { return '.$expression.'; }'."\n";
    $lines[substr_count($source, "\n") - 1] = $name;
}
file_put_contents($workspace.'/case.php', $source);
$fixture = file_get_contents(__DIR__.'/fixtures/analysis/new-collection-trait.php.stub');
$modelFixture = file_get_contents($framework.'/Model.php');
$failed = false;
foreach (['enabled', 'changed-body', 'changed-resolver', 'changed-doc', 'changed-default', 'wrong-source',
    'changed-constraint', 'template-default', 'changed-model-mapping'] as $mode) {
    $trait = $fixture;
    if ($mode === 'changed-body') {
        $trait = str_replace('return $collection;', 'return new Collection([new Model]);', $trait);
    } elseif ($mode === 'changed-resolver') {
        $trait = str_replace('return null;', 'return Collection::class;', $trait);
    } elseif ($mode === 'changed-doc') {
        $trait = str_replace('@return TCollection', '@return Collection<array-key, Model>', $trait);
    } elseif ($mode === 'changed-default') {
        $trait = str_replace('array $models = []', 'array $models = ["custom" => new Model]', $trait);
    } elseif ($mode === 'changed-constraint') {
        $trait = str_replace('@template TCollection of \\Illuminate\\Database\\Eloquent\\Collection', '@template TCollection of \\Records', $trait);
    } elseif ($mode === 'template-default') {
        $trait = str_replace('@template TCollection of \\Illuminate\\Database\\Eloquent\\Collection', '@template TCollection of \\Illuminate\\Database\\Eloquent\\Collection = \\Records', $trait);
    }
    file_put_contents($framework.'/Model.php', $mode === 'changed-model-mapping'
        ? str_replace('HasCollection<\\Illuminate\\Database\\Eloquent\\Collection<array-key, static & self>>', 'HasCollection<\\Records>', $modelFixture)
        : $modelFixture);
    $traitPath = $mode === 'wrong-source' ? $workspace.'/custom-trait.php' : $framework.'/HasCollection.php';
    file_put_contents($traitPath, $trait);
    foreach ([false, true] as $enabled) {
        $reportName = $mode.($enabled ? '-enabled' : '-native');
        $configuration = [
            'extends' => $package.'/presets/laravel.toml',
            'source' => ['paths' => ['case.php'], 'includes' => ['models.php', $framework.'/Model.php', $framework.'/Collection.php', $framework.'/Attributes/CollectedBy.php', $traitPath]],
        ];
        if ($enabled) {
            $configuration['extension-hosts'] = ['collection' => [
                'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace, $integrated ? 'integrated' : 'isolated'],
                'workers' => 1,
            ]];
        }
        file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
        $process = proc_open([...$command, 'analyze', '--reporting-format=json'], [
            0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$reportName.'.json', 'w'], 2 => ['file', $workspace.'/'.$reportName.'.log', 'w'],
        ], $pipes, $workspace);
        fclose($pipes[0]);
        $exit = proc_close($process);
        $report = json_decode(file_get_contents($workspace.'/'.$reportName.'.json'), true, 512, JSON_THROW_ON_ERROR);
        $diagnostics = [];
        foreach ($report['issues'] ?? [] as $issue) {
            foreach ($issue['annotations'] ?? [] as $annotation) {
                if (($annotation['kind'] ?? '') !== 'Primary') { continue; }
                $name = ($annotation['span']['file_id']['name'] ?? '') === 'case.php'
                    ? ($lines[$annotation['span']['start']['line'] ?? -1] ?? 'unknown') : 'unknown';
                $diagnostics[$name][] = [$issue['level'], $issue['code'], $issue['message']];
            }
        }
        if ($exit > 1 || file_get_contents($workspace.'/'.$reportName.'.log') !== '' || isset($diagnostics['unknown'])) {
            throw new RuntimeException('Unexpected analyzer failure: '.$workspace.'/'.$reportName.'.json');
        }
        if (! $enabled) {
            $native = $diagnostics;
            foreach ($cases as $name => [, , $positive]) {
                if ($mode === 'enabled' && $positive && ($native[$name] ?? []) === []) {
                    throw new RuntimeException('Missing native diagnostic for '.$name.'; inspect '.$workspace);
                }
            }
            continue;
        }
        foreach ($cases as $name => [, , $positive]) {
            $actual = $diagnostics[$name] ?? [];
            $expected = $mode === 'enabled' && $positive ? [] : ($native[$name] ?? []);
            // Wrong element/key contracts remain errors, although refining a type can
            // change Mago's diagnostic wording or specificity.
            $retains = $mode === 'enabled' && in_array($name, ['wrongRecordType', 'wrongKeyType'], true);
            if (($retains && $actual === []) || (! $retains && $actual !== $expected)) {
                $failed = true;
                fwrite(STDERR, $mode.' '.$name.': '.json_encode($actual).'; expected '.json_encode($expected).PHP_EOL);
            }
        }
    }
    echo $mode.': checked '.count($cases).' contracts against native Mago'.PHP_EOL;
}
if ($failed) {
    fwrite(STDERR, 'Workspace: '.$workspace.PHP_EOL);
    exit(1);
}
echo 'Empty model collection inference passed.'.PHP_EOL;
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
