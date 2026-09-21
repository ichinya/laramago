<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago search attributes '.bin2hex(random_bytes(8));
mkdir($workspace);

$framework = file_get_contents(__DIR__.'/fixtures/analysis/framework.php.stub');
$support = <<<'PHP'

    class UniqueConstraintViolationException extends \Exception {}

    function value(mixed $value): mixed
    {
        return $value instanceof \Closure ? $value() : $value;
    }

    function tap(mixed $value, ?callable $callback = null): mixed
    {
        if ($callback !== null) {
            $callback($value);
        }

        return $value;
    }

    PHP;
$framework = str_replace(
    "namespace Illuminate\\Database\\Eloquent;\n\nclass Model",
    "namespace Illuminate\\Database\\Eloquent;\n".$support."\nclass Model",
    $framework,
);
$framework = str_replace(
    '    public $timestamps = true;',
    <<<'PHP'
        public $timestamps = true;
        public bool $wasRecentlyCreated = false;

        /** @return $this */
        public function fill(array $attributes) {}
        public function save(): bool {}
        PHP,
    $framework,
);
$framework = str_replace(
    "class Builder\n{",
    <<<'PHP'
        class Builder
        {
            /** @return TModel */
            public function newModelInstance(array $attributes = []) {}
            /** @return mixed */
            public function withSavepointIfNeeded(\Closure $scope) {}
            /** @return $this */
            public function useWritePdo() {}
            /** @return TModel|null */
            public function first() {}
        PHP,
    $framework,
);
// Audited Laravel 7c75fbf bodies; see fixtures/analysis/search-attributes-LICENSE.md.
$nativeMethods = [
    '    public function firstOrNew(array $attributes = [], \Closure|array $values = []) {}' => <<<'PHP'
        public function firstOrNew(array $attributes = [], \Closure|array $values = [])
        {
            if (! is_null($instance = $this->where($attributes)->first())) {
                return $instance;
            }

            return $this->newModelInstance(array_merge($attributes, value($values)));
        }
        PHP,
    '    public function firstOrCreate(array $attributes = [], \Closure|array $values = []) {}' => <<<'PHP'
        public function firstOrCreate(array $attributes = [], \Closure|array $values = [])
        {
            if (! is_null($instance = (clone $this)->where($attributes)->first())) {
                return $instance;
            }

            return $this->createOrFirst($attributes, $values);
        }
        PHP,
    '    public function createOrFirst(array $attributes = [], \Closure|array $values = []) {}' => <<<'PHP'
        public function createOrFirst(array $attributes = [], \Closure|array $values = [])
        {
            try {
                return $this->withSavepointIfNeeded(fn () => $this->create(array_merge($attributes, value($values))));
            } catch (UniqueConstraintViolationException $e) {
                return $this->useWritePdo()->where($attributes)->first() ?? throw $e;
            }
        }
        PHP,
    '    public function updateOrCreate(array $attributes, \Closure|array $values = []) {}' => <<<'PHP'
        public function updateOrCreate(array $attributes, \Closure|array $values = [])
        {
            return tap($this->firstOrCreate($attributes, $values), function ($instance) use ($values) {
                if (! $instance->wasRecentlyCreated) {
                    $instance->fill(value($values))->save();
                }
            });
        }
        PHP,
];
foreach ($nativeMethods as $declaration => $body) {
    if (! str_contains($framework, $declaration)) {
        throw new RuntimeException('The native search method fixture drifted: '.$declaration);
    }
    $framework = str_replace($declaration, $body, $framework);
}
file_put_contents($workspace.'/framework.php', $framework);
file_put_contents($workspace.'/models.php', <<<'PHP'
    <?php

    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Model;

    class SearchRecord extends Model {}
    class SearchChildRecord extends SearchRecord {}
    class IncompleteSearchRecord extends Model {}
    class UnassertedSearchRecord extends Model {}
    class ScopedSearchRecord extends Model
    {
        /** @return Builder<static> */
        public function scopeActive(Builder $query): Builder { return $query; }
    }
    class CustomQuerySearchRecord extends Model
    {
        /** @return Builder<static> */
        public static function query(): Builder { throw new RuntimeException('Queries must not run.'); }
    }
    /** @template TModel of Model @extends Builder<TModel> */
    class CustomSearchBuilder extends Builder
    {
        public function firstOrCreate(array $attributes = [], Closure|array $values = []): string { return 'custom'; }
    }
    class CustomBuilderSearchRecord extends Model
    {
        protected static string $builder = CustomSearchBuilder::class;
    }
    class CustomMethodSearchRecord extends Model
    {
        public static function firstOrCreate(array $attributes = [], Closure|array $values = []): string
        {
            return 'custom';
        }
    }
    /** @method static string firstOrCreate(array $attributes = [], Closure|array $values = []) */
    class DocumentedSearchRecord extends Model {}
    PHP);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application must not boot.");');

$contracts = [
    'SearchRecord' => [
        'complete' => true,
        'native-column-semantics' => true,
        'columns' => ['id', 'label', 'search_records.id'],
    ],
    'ScopedSearchRecord' => [
        'complete' => true,
        'native-column-semantics' => true,
        'columns' => ['id'],
    ],
    'CustomQuerySearchRecord' => [
        'complete' => true,
        'native-column-semantics' => true,
        'columns' => ['id'],
    ],
    'CustomBuilderSearchRecord' => [
        'complete' => true,
        'native-column-semantics' => true,
        'columns' => ['id'],
    ],
    'IncompleteSearchRecord' => [
        'complete' => false,
        'native-column-semantics' => true,
        'columns' => ['id'],
    ],
    'UnassertedSearchRecord' => [
        'complete' => true,
        'columns' => ['id'],
    ],
];
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
    'extra' => ['laramago' => ['query-sources' => $contracts]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 1,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$missing = ['ichinya/laramago/laramago-query-source-missing-column'];
$cases = [
    'firstOrCreate missing search key' => [
        'SearchRecord::query()->firstOrCreate(["missing" => 1]);',
        $missing,
    ],
    'updateOrCreate missing search key' => [
        'SearchRecord::query()->updateOrCreate(["missing" => 1]);',
        $missing,
    ],
    'firstOrNew missing search key' => ['SearchRecord::query()->firstOrNew(["missing" => 1]);', $missing],
    'createOrFirst creation-first path defers' => [
        'SearchRecord::query()->createOrFirst(["missing" => 1]);',
        [],
    ],
    'valid search keys' => [
        'SearchRecord::query()->firstOrCreate(["id" => 1, "search_records.id" => 1]);',
        [],
    ],
    'case sensitive key' => ['SearchRecord::query()->firstOrCreate(["ID" => 1]);', $missing],
    'two missing search keys' => [
        'SearchRecord::query()->firstOrCreate(["missing_a" => 1, "missing_b" => 2]);',
        [$missing[0], $missing[0]],
    ],
    'creation values are not query columns' => [
        'SearchRecord::query()->firstOrCreate(["id" => 1], ["write_only" => 2]);',
        [],
    ],
    'update values are not query columns' => [
        'SearchRecord::query()->updateOrCreate(["id" => 1], ["write_only" => 2]);',
        [],
    ],
    'closure values are not query columns' => [
        'SearchRecord::query()->firstOrCreate(["id" => 1], fn (): array => ["write_only" => 2]);',
        [],
    ],
    'named reordered attributes' => [
        'SearchRecord::query()->updateOrCreate(values: ["write_only" => 2], attributes: ["missing" => 1]);',
        $missing,
    ],
    'named values with default attributes' => [
        'SearchRecord::query()->firstOrCreate(values: ["write_only" => 2]);',
        [],
    ],
    'dynamic attributes defer' => [
        '$attributes = ["missing" => 1]; SearchRecord::query()->firstOrCreate($attributes);',
        [],
    ],
    'dynamic key defers whole array' => [
        '$key = "id"; SearchRecord::query()->firstOrCreate([$key => 1, "missing" => 2]);',
        [],
    ],
    'unpacked attributes defer' => [
        '$attributes = ["missing" => 1]; SearchRecord::query()->firstOrCreate([...$attributes]);',
        [],
    ],
    'list attributes defer' => ['SearchRecord::query()->firstOrCreate(["missing"]);', []],
    'integer-like string key defers nested tuple' => [
        'SearchRecord::query()->firstOrCreate(["0" => ["id", 1], "missing" => 2]);',
        [],
    ],
    'numeric string key defers nested tuple' => [
        'SearchRecord::query()->firstOrCreate(["1e2" => ["id", 1], "missing" => 2]);',
        [],
    ],
    'duplicate attributes defer' => [
        'SearchRecord::query()->firstOrCreate(["missing" => 1, "missing" => 2]);',
        ['duplicate-array-key'],
    ],
    'saved builder defers' => [
        '$query = SearchRecord::query(); $query->firstOrCreate(["missing" => 1]);',
        [],
    ],
    'prior predicate defers' => [
        'SearchRecord::query()->where("id", 1)->firstOrCreate(["missing" => 1]);',
        [],
    ],
    'local scope defers' => [
        'ScopedSearchRecord::query()->active()->firstOrCreate(["missing" => 1]);',
        [],
    ],
    'custom query factory defers' => [
        'CustomQuerySearchRecord::query()->firstOrCreate(["missing" => 1]);',
        [],
    ],
    'custom builder method defers' => [
        'CustomBuilderSearchRecord::query()->firstOrCreate(["missing" => 1]);',
        [],
    ],
    'direct model forwarding defers' => ['SearchRecord::firstOrCreate(["missing" => 1]);', []],
    'custom model method defers' => ['CustomMethodSearchRecord::firstOrCreate(["missing" => 1]);', []],
    'documented model method defers' => ['DocumentedSearchRecord::firstOrCreate(["missing" => 1]);', []],
    'contract is exact model only' => [
        'SearchChildRecord::query()->firstOrCreate(["missing" => 1]);',
        [],
    ],
    'incomplete source defers' => [
        'IncompleteSearchRecord::query()->firstOrCreate(["missing" => 1]);',
        [],
    ],
    'unasserted native semantics defer' => [
        'UnassertedSearchRecord::query()->firstOrCreate(["missing" => 1]);',
        [],
    ],
    'findOrNew has id and projection arguments' => [
        'SearchRecord::query()->findOrNew(1, ["missing"]);',
        [],
    ],
    'first class terminal remains callable' => [
        '$callable = SearchRecord::query()->firstOrCreate(...);',
        [],
    ],
    'invalid values retain native signature' => [
        'SearchRecord::query()->firstOrCreate([], "wrong");',
        ['possibly-invalid-argument'],
    ],
    'required update attributes retain native signature' => [
        'SearchRecord::query()->updateOrCreate();',
        ['too-few-arguments'],
    ],
    'invalid named argument retains native signature' => [
        'SearchRecord::query()->firstOrCreate(unknown: []);',
        ['invalid-named-argument'],
    ],
];

$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$body, $codes]) {
    $source .= 'function scenario'.count($lines).'(): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);

$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/report.json', 'w'],
        2 => ['file', $workspace.'/stderr.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
if (
    ! in_array($exit, [0, 1], true)
    || preg_match('/provider failed|rejected request/i', file_get_contents($workspace.'/stderr.log'))
) {
    throw new RuntimeException('Expected analyzer diagnostics without extension fallback; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
$failures = [];
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    unset($actual[$line]);
    if ($codes !== $expected) {
        $failures[] = $name.': expected '.json_encode($expected).', got '.json_encode($codes);
        continue;
    }
    echo 'PASS: '.$name."\n";
}
if ($failures !== [] || $actual !== []) {
    throw new RuntimeException(
        implode("\n", $failures).'; extra diagnostics: '.json_encode($actual).'; inspect '.$workspace,
    );
}

if (is_file($workspace.'/.env')) {
    throw new RuntimeException('The offline fixture must not have an environment file.');
}
$resolvedWorkspace = realpath($workspace);
foreach (glob($workspace.'/*') ?: [] as $file) {
    $resolvedFile = realpath($file);
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    unlink($resolvedFile);
}
rmdir($workspace);
