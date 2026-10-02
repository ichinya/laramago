<?php

declare(strict_types=1);

// The proof runs in Mago's metadata context; no application declaration is loaded.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago factory result '.bin2hex(random_bytes(8));
$native = '/vendor/laravel/framework/src/';
$fixtures = [
    'Factory' => 'Illuminate/Database/Eloquent/Factories/Factory.php',
    'HasFactory' => 'Illuminate/Database/Eloquent/Factories/HasFactory.php',
    'GuardsAttributes' => 'Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
    'helpers' => 'Illuminate/Support/helpers.php',
];
mkdir($workspace, recursive: true);
file_put_contents($workspace.'/composer.json', '{"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
foreach ($fixtures as $name => $path) {
    if (!is_dir(dirname($workspace.$native.$path))) { mkdir(dirname($workspace.$native.$path), recursive: true); }
    copy(__DIR__.'/fixtures/analysis/factory-result-'.$name.'.php.stub', $workspace.$native.$path);
}
$types = <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent {
    class Model { use \Illuminate\Database\Eloquent\Concerns\GuardsAttributes; }
    /** @template TKey of array-key @template TModel of Model */ class Collection {}
}
namespace Illuminate\Support {
    /** @template TKey of array-key @template TValue */ class Collection {}
}
namespace Illuminate\Support\Traits {
    trait Conditionable {}
    trait ForwardsCalls {}
    trait Macroable { public function __call(string $method, array $parameters): mixed {} }
}
namespace Illuminate\Database\Eloquent\Attributes {
    #[\Attribute(\Attribute::TARGET_CLASS)] class UseFactory { public function __construct(public string $factoryClass) {} }
}
namespace Illuminate\Database\Eloquent\Factories\Attributes {
    #[\Attribute(\Attribute::TARGET_CLASS)] class UseModel { public function __construct(public string $class) {} }
}
namespace App\Models {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    class Record extends Model { /** @use HasFactory<\Database\Factories\RecordFactory> */ use HasFactory; }
    class InheritedRecord extends Record {}
    class Convention extends Model { use HasFactory; }
    class Other extends Model { use HasFactory; protected static $factory = \Database\Factories\RecordFactory::class; }
    class Override extends Model { use HasFactory; protected static $factory = \Database\Factories\OverrideFactory::class; }
    class Mutated extends Model { use HasFactory; protected static $factory = \Database\Factories\MutatedFactory::class; }
    class Configured extends Model { use HasFactory; protected static $factory = \Database\Factories\ConfiguredFactory::class; }
    class Resolved extends Record { protected static function newFactory(): \Database\Factories\RecordFactory { throw new \RuntimeException(); } }
    class CustomFactory extends Record { public static function factory(): ?\Database\Factories\RecordFactory { return null; } }
    class Mismatched extends Model { /** @use HasFactory<\Database\Factories\RecordFactory> */ use HasFactory; }
    #[\Illuminate\Database\Eloquent\Attributes\UseFactory(\Database\Factories\RecordFactory::class)]
    class Attributed extends Model { use HasFactory; }
    class AttributedModel extends Model { use HasFactory; }
    class Misdocumented extends Model { use HasFactory; }
    class CustomNamespace extends Model { use HasFactory; }
    class ConfiguredResolver extends Model { use HasFactory; }
    final class FinalRecord extends Record { protected static $factory = \Database\Factories\RecordFactory::class; }
}
namespace Database\Factories {
    use Illuminate\Database\Eloquent\Factories\Factory;
    /** @extends Factory<\App\Models\Record> */
    class RecordFactory extends Factory { public function definition(): array { return []; } }
    class ConventionFactory extends Factory { protected $model = \App\Models\Convention::class; public function definition(): array { return []; } }
    class OverrideFactory extends RecordFactory { public function create($attributes = [], ?\Illuminate\Database\Eloquent\Model $parent = null): ?\App\Models\Record { return null; } }
    class MutatedFactory extends RecordFactory { public function mutate(): void { $this->count = 2; } }
    class ConfiguredFactory extends RecordFactory { public function configure(): static { return $this->count(3); } }
    #[\Illuminate\Database\Eloquent\Factories\Attributes\UseModel(\App\Models\Record::class)]
    class AttributedModelFactory extends RecordFactory {}
    class MisdocumentedFactory extends RecordFactory {}
    class CustomNamespaceFactory extends RecordFactory { protected static function appNamespace(): string { return 'Other\\'; } }
    class ConfiguredResolverFactory extends RecordFactory { protected static $modelNameResolvers = ['example']; }
}
namespace { throw new \RuntimeException('Application fixtures must never be executed.'); }
PHP;
file_put_contents($workspace.'/types.php', $types);
$cases = [
    'emptyCreate' => ['Record::factory()->create()', 'App\\Models\\Record'],
    'freshAttributes' => ['Record::factory()->create(["weight" => $amount, "label" => sprintf("row %d", $index + 1)])', 'App\\Models\\Record'],
    'namedAttributes' => ['Record::factory()->create(attributes: [])', 'App\\Models\\Record'],
    'inheritedDispatchMismatch' => ['InheritedRecord::factory()->create()', null],
    'conventionalResult' => ['Convention::factory()->create()', 'App\\Models\\Convention'],
    'mappedOtherResult' => ['Other::factory()->create()', 'App\\Models\\Record'],
    'countedFactory' => ['Record::factory(1)->create()', null],
    'nullCountArgument' => ['Record::factory(null)->create()', null],
    'factoryStateArgument' => ['Record::factory(state: [])->create()', null],
    'stateChain' => ['Record::factory()->state([])->create()', null],
    'makeResult' => ['Record::factory()->make()', null],
    'quietCreate' => ['Record::factory()->createQuietly()', null],
    'existingAttributes' => ['Record::factory()->create($attributes)', null],
    'callableAttributes' => ['Record::factory()->create(fn () => [])', null],
    'parentArgument' => ['Record::factory()->create([], null)', null],
    'wrongArgumentName' => ['Record::factory()->create(parent: [])', null],
    'spreadArgument' => ['Record::factory()->create(...[[]])', null],
    'spreadAttributes' => ['Record::factory()->create([...$attributes])', null],
    'referenceAttributes' => ['Record::factory()->create(["weight" => &$amount])', null],
    'nullableOverride' => ['Override::factory()->create()', null],
    'mutableFactory' => ['Mutated::factory()->create()', null],
    'configuredFactory' => ['Configured::factory()->create()', null],
    'customResolver' => ['Resolved::factory()->create()', null],
    'customFactoryMethod' => ['CustomFactory::factory()->create()', null],
    'dynamicModel' => ['$model::factory()->create()', null],
    'firstClassCallable' => ['Record::factory()->create(...)', null],
    'mismatchedDispatch' => ['\\App\\Models\\Mismatched::factory()->create()', null],
    'factoryAttribute' => ['\\App\\Models\\Attributed::factory()->create()', null],
    'modelAttribute' => ['\\App\\Models\\AttributedModel::factory()->create()', null],
    'modelDocMismatch' => ['\\App\\Models\\Misdocumented::factory()->create()', null],
    'customNamespace' => ['\\App\\Models\\CustomNamespace::factory()->create()', null],
    'configuredResolver' => ['\\App\\Models\\ConfiguredResolver::factory()->create()', null],
];
$source = "<?php\nnamespace Fixtures;\nuse App\\Models\\{Record, InheritedRecord, Convention, Other, Override, Mutated, Configured, Resolved, CustomFactory};\n";
foreach ($cases as $name => [$expression]) {
    $source .= 'function '.$name.'(array $attributes, float $amount, int $index, string $model): void { $created = '.$expression.'; }'."\n";
}
$source .= 'class OpenScope extends \\App\\Models\\Record { public function openSelf(): void { $created = self::factory()->create(); } }'."\n";
$source .= 'final class FinalScope extends \\App\\Models\\Record { protected static $factory = \\Database\\Factories\\RecordFactory::class; public function finalSelf(): void { $created = self::factory()->create(); } public function finalStatic(): void { $created = static::factory()->create(); } public function finalParent(): void { $created = parent::factory()->create(); } }'."\n";
$cases['openSelf'] = ['', null];
$cases['finalSelf'] = ['', 'App\\Models\\Record'];
$cases['finalStatic'] = ['', 'App\\Models\\Record'];
$cases['finalParent'] = ['', null];
$source .= 'function trigger(): \\Illuminate\\Database\\Eloquent\\Model { return null; }'."\n";
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('test/factory-result', 'Factory result contract', 'Independent source proof'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $contract = new \Ichinya\Laramago\Analyzer\StaticAnalysis\FactoryResultContract($this->root);
        $registry->registerCodebaseScanHook($contract);
        $registry->registerIssueFilterHook(new class($this->root, $contract) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            private bool $logged = false;
            public function __construct(private readonly string $root, private readonly \Ichinya\Laramago\Analyzer\StaticAnalysis\FactoryResultContract $contract) {}
            public function getCodes(): array { return ['invalid-return-statement', 'nullable-return-statement']; }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                if (!$this->logged) {
                    $this->logged = true;
                    $nodes = (new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root))->read('cases.php') ?? [];
                    $contract = $this->contract;
                    $results = [];
                    $firstProducer = null;
                    foreach ((new \PhpParser\NodeFinder)->findInstanceOf($nodes, \PhpParser\Node\Stmt\Function_::class) as $node) {
                        $expr = $node->stmts[0]->expr ?? null;
                        if (!$expr instanceof \PhpParser\Node\Expr\Assign) { continue; }
                        $type = $contract->resolve($context->codebase, $expr->expr);
                        $results[$node->name->name] = $type === null ? null : (string) $type;
                        if ($node->name->name === 'emptyCreate') { $firstProducer = $expr->expr; }
                    }
                    foreach ((new \PhpParser\NodeFinder)->findInstanceOf($nodes, \PhpParser\Node\Stmt\Class_::class) as $class) {
                        foreach ($class->getMethods() as $node) {
                            $expr = $node->stmts[0]->expr ?? null;
                            if (!$expr instanceof \PhpParser\Node\Expr\Assign) { continue; }
                            $type = $contract->resolve($context->codebase, $expr->expr, $class->namespacedName?->toString());
                            $results[$node->name->name] = $type === null ? null : (string) $type;
                        }
                    }
                    file_put_contents($this->root.'/proof.json', json_encode($results, JSON_THROW_ON_ERROR));
                    $source = new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root);
                    $reflection = new \Ichinya\Laramago\Analyzer\StaticAnalysis\FactoryReflection($context->codebase, $source);
                    file_put_contents($this->root.'/provider-core.json', json_encode($contract->standardCore($reflection, 'Database\\Factories\\RecordFactory'), JSON_THROW_ON_ERROR));
                    if (($results['emptyCreate'] ?? null) !== null) {
                        $snapshots = new \Ichinya\Laramago\Analyzer\StaticAnalysis\FactoryResultContract($this->root);
                        $file = static function (string $path, string $contents) use ($context): \Mago\Sdk\Syntax\SourceFile {
                            return new \Mago\Sdk\Syntax\SourceFile($context->phpVersion, $path, $contents, [],
                                (new \ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
                                (new \ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
                                (new \ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
                        };
                        $scan = static function (array $files, bool $first = true, bool $last = true) use ($snapshots, $context): void {
                            $snapshots->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($context->phpVersion, $context->cancellation, $files, $first, $last));
                        };
                        $checks = [];
                        $expect = static function (string $label, bool $known) use ($snapshots, $context, $firstProducer, &$checks): void {
                            $actual = $snapshots->resolve($context->codebase, $firstProducer);
                            if (($actual === null ? null : (string) $actual) !== ($known ? 'App\\Models\\Record' : null)) {
                                throw new \RuntimeException('Unexpected scan/source provenance state: '.$label);
                            }
                            $checks[] = $label;
                        };
                        $nativePath = $context->codebase->getClassLike('Illuminate\\Database\\Eloquent\\Factories\\Factory')->location->file;
                        $nativeContents = file_get_contents($nativePath);
                        $nativeFile = $file($nativePath, $nativeContents);
                        $expect('no scan', false);
                        $scan([$nativeFile], last: false);
                        $expect('incomplete scan', false);
                        $scan([], first: false);
                        $expect('complete matching native snapshot', true);
                        $scan([$nativeFile, $nativeFile]);
                        $expect('duplicate native path', false);
                        $scan([$file($nativePath, str_replace('$results = $this->make', '$results = $this->fake', $nativeContents))]);
                        $expect('in-memory native overlay', false);
                        $scan([$nativeFile]);
                        $expect('first batch resets native veto', true);
                        $hostPath = $context->codebase->getClassLike('Database\\Factories\\RecordFactory')->location->file;
                        $scan([$file($hostPath, str_replace('return [];', 'return [1];', file_get_contents($hostPath)))]);
                        $expect('in-memory factory overlay', false);
                        $scan([$file($this->root.'/configuration.php', '<?php \\Illuminate\\Database\\Eloquent\\Factories\\Factory::guessModelNamesUsing(static fn () => \\App\\Models\\Other::class);')]);
                        $expect('external mapping configuration', false);
                        $scan([$file($this->root.'/broken.php', '<?php function broken( {')]);
                        $expect('failed scan', false);
                        $scan([$nativeFile]);
                        $expect('first batch resets failed scan', true);
                        file_put_contents($this->root.'/scan-checks.json', json_encode($checks, JSON_THROW_ON_ERROR));
                    }
                }
                return \Mago\Sdk\Analyzer\IssueFilterDecision::Keep;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/factory-result', 'Factory result', '1', analyzerPlugins: [$plugin])))->run();
PHP);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$includes = ['types.php', ...array_map(static fn (string $path): string => 'vendor/laravel/framework/src/'.$path, array_values($fixtures))];
$run = static function (string $mode) use ($workspace, $package, $command, $includes): array {
    @unlink($workspace.'/proof.json');
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => $includes],
        'extension-hosts' => ['test' => ['command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace], 'workers' => 1]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
        || !is_file($workspace.'/proof.json')) {
        throw new RuntimeException('Factory proof worker failed for '.$mode.'; inspect '.$workspace.'; '.$log);
    }
    return json_decode(file_get_contents($workspace.'/proof.json'), true, flags: JSON_THROW_ON_ERROR);
};
$results = $run('standard');
foreach ($cases as $name => [$expression, $expected]) {
    if (!array_key_exists($name, $results) || $results[$name] !== $expected) {
        throw new RuntimeException($name.': expected '.var_export($expected, true).', got '.var_export($results[$name] ?? null, true).'; inspect '.$workspace);
    }
}
echo 'PASS: '.count($cases)." independent factory result contracts\n";
$scanChecks = json_decode(file_get_contents($workspace.'/scan-checks.json'), true, flags: JSON_THROW_ON_ERROR);
if (count($scanChecks) !== 10) { throw new RuntimeException('Incomplete source provenance controls.'); }
echo 'PASS: '.count($scanChecks)." scan lifecycle and analyzed source provenance controls\n";

$factoryPath = $workspace.$native.$fixtures['Factory'];
$factorySource = file_get_contents($factoryPath);
$mutations = [
    'changed create body' => str_replace('$results = $this->make($attributes, $parent);', '$results = null;', $factorySource),
    'changed create PHPDoc' => str_replace('@return \\Illuminate\\Database\\Eloquent\\Collection<int, TModel>|TModel', '@return \\Illuminate\\Database\\Eloquent\\Collection<int, TModel>|TModel|null', $factorySource),
    'changed constructor PHPDoc' => str_replace('@param  int|null  $count', '@param  int|null|float  $count', $factorySource),
    'changed attributes reference' => str_replace('function create($attributes = []', 'function create(&$attributes = []', $factorySource),
    'changed class template' => str_replace('@template TModel of \\Illuminate\\Database\\Eloquent\\Model', '@template TModel of object', $factorySource),
];
foreach ($mutations as $label => $changed) {
    if ($changed === $factorySource) { throw new RuntimeException('Mutation did not change '.$label); }
    file_put_contents($factoryPath, $changed);
    foreach ($run(str_replace(' ', '-', $label)) as $type) {
        if ($type !== null) { throw new RuntimeException('Changed native contract accepted: '.$label.'; inspect '.$workspace); }
    }
}
echo 'PASS: '.count($mutations)." native source and PHPDoc mutations defer\n";
file_put_contents($factoryPath, $factorySource);
foreach ([
    'HasFactory' => ['->count(is_numeric($count) ? $count : null)', '->count(2)'],
    'helpers' => ['return $value;', 'return null;'],
    'GuardsAttributes' => ['return $callback();', 'return null;'],
] as $fixture => [$before, $after]) {
    $path = $workspace.$native.$fixtures[$fixture];
    $original = file_get_contents($path);
    $changed = str_replace($before, $after, $original);
    if ($changed === $original) { throw new RuntimeException('Mutation did not change '.$fixture); }
    file_put_contents($path, $changed);
    foreach ($run('changed-'.$fixture) as $type) {
        if ($type !== null) { throw new RuntimeException('Changed native forwarding accepted: '.$fixture.'; inspect '.$workspace); }
    }
    file_put_contents($path, $original);
}
echo "PASS: 3 changed native forwarding bodies defer\n";

$customizations = [
    'definition resolver write' => 'public function definition(): array { static::$modelNameResolvers[static::class] = static fn () => \\App\\Models\\Other::class; return []; }',
    'definition resolver setter' => 'public function definition(): array { static::guessModelNamesUsing(static fn () => \\App\\Models\\Other::class); return []; }',
    'definition namespace write' => 'public function definition(): array { static::$namespace = "Other\\\\Factories\\\\"; return []; }',
    'definition model alias' => 'public function definition(): array { $self = $this; $self->model = \\App\\Models\\Other::class; return []; }',
    'constructor count alias' => 'public function definition(): array { return []; } protected function withFaker() { $self = $this; $self->count = 0; return parent::withFaker(); }',
    'constructor count reference' => 'public function definition(): array { return []; } protected function withFaker() { $alias =& $this->count; $alias = 0; return parent::withFaker(); }',
    'constructor count argument' => 'public function definition(): array { return []; } protected function withFaker() { settype($this->count, "integer"); return parent::withFaker(); }',
    'expanded attributes mapping' => 'public function definition(): array { return []; } protected function getExpandedAttributes(?\\Illuminate\\Database\\Eloquent\\Model $parent) { $self = $this; $self->model = \\App\\Models\\Other::class; return []; }',
];
foreach ($customizations as $label => $methods) {
    $changed = str_replace('public function definition(): array { return []; }', $methods, $types);
    if ($changed === $types) { throw new RuntimeException('Customization did not change '.$label); }
    file_put_contents($workspace.'/types.php', $changed);
    $proof = $run(str_replace(' ', '-', $label));
    if ($proof['emptyCreate'] !== null || $proof['freshAttributes'] !== null) {
        throw new RuntimeException('Mutable factory result accepted for '.$label.'; inspect '.$workspace);
    }
    if (json_decode(file_get_contents($workspace.'/provider-core.json'), true, flags: JSON_THROW_ON_ERROR) !== true) {
        throw new RuntimeException('Existing provider core contract unexpectedly changed for '.$label);
    }
}
file_put_contents($workspace.'/types.php', $types);
echo 'PASS: '.count($customizations)." custom mapping/count counterexamples defer with existing provider core preserved\n";

// Also audit native methods outside the original creation/count subset.
$changed = str_replace('return $this->expandAttributes($this->getRawAttributes($parent));', 'return [];', $factorySource);
if ($changed === $factorySource) { throw new RuntimeException('Expanded attributes native mutation did not change source.'); }
file_put_contents($factoryPath, $changed);
if ($run('changed-expanded-attributes')['emptyCreate'] !== null) { throw new RuntimeException('Changed native expansion was accepted.'); }
file_put_contents($factoryPath, $factorySource);
echo "PASS: complete native Factory source mutation defers\n";

// Cleanup only this test's resolved generated workspace.
$resolved = realpath($workspace);
$cleanup = static function (string $path) use (&$cleanup, $resolved): void {
    $real = realpath($path);
    if ($resolved === false || $real === false || ($real !== $resolved && !str_starts_with($real, $resolved.DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Refusing cleanup outside the generated workspace.');
    }
    if (is_dir($real)) {
        foreach (glob($real.'/*') ?: [] as $child) { $cleanup($child); }
        rmdir($real);
    } else { unlink($real); }
};
$cleanup($workspace);
