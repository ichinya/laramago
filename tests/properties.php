<?php

declare(strict_types=1);

// All application PHP is analyzed as data, including when only one file is a host.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago properties '.bin2hex(random_bytes(8));
mkdir($workspace.'/database/migrations', 0777, true);
mkdir($workspace.'/extra migrations', 0777, true);
mkdir($workspace.'/bootstrap', 0777, true);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/properties.php.stub', $workspace.'/models.php');
copy(__DIR__.'/fixtures/analysis/migration.php.stub', $workspace.'/database/migrations/001_create.php');
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Laravel must not boot.");');
file_put_contents($workspace.'/database/migrations/002_change.php', <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\Schema;
    return new class extends Migration {
        public function up(): void {
            Schema::table('people', function ($table) {
                $table->renameColumn('previous_name', 'renamed');
                $table->dropColumn('removed', 'removed_together');
                $table->dropColumn(columns: ['removed_by_name']);
                $table->decimal('changed', 12, 2)->nullable()->change();
            });
            Schema::rename('old_records', 'changed_records');
        }
        public function down(): void { Schema::drop('people'); }
    };
    PHP);
file_put_contents($workspace.'/extra migrations/003_extra.php', <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\Schema;
    return new class extends Migration {
        public function up(): void { Schema::create('extra_records', function ($table) { $table->string('value'); }); }
    };
    PHP);
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['migration-paths' => ['extra migrations']]],
], JSON_THROW_ON_ERROR));
$cases = [
    'primary key' => ['return $model->id;', 'int', []],
    'schema string' => ['return $model->name;', 'string', []],
    'nullable column' => ['return $model->nickname;', '?string', []],
    'nullable false' => ['return $model->required;', 'string', []],
    'renamed column' => ['return $model->renamed;', 'string', []],
    'changed column' => ['return $model->changed;', 'int|float|numeric-string|null', []],
    'boolean cast' => ['return $model->active;', 'bool', []],
    'decimal cast' => ['return $model->amount;', 'string', []],
    'decimal arithmetic' => ['return $model->amount * 2;', 'int|float', []],
    'uncast boolean is driver dependent' => ['return $model->uncast_flag;', "bool|0|1|'0'|'1'", []],
    'array cast' => ['return $model->settings;', 'array<array-key, mixed>|null', []],
    'enum cast' => ['return $model->status;', 'Example\Status', []],
    'immutable date' => ['return $model->published_at;', 'Carbon\CarbonImmutable|null', []],
    'method casts' => ['return $model->score;', 'int', []],
    'automatic timestamp' => ['return $model->created_at;', 'Carbon\CarbonInterface|null', []],
    'remember token' => ['return $model->remember_token;', '?string', []],
    'morph columns' => ['return $model->attachment_id;', '?string', []],
    'foreign key' => ['return $model->manager_id;', '?int', []],
    'legacy accessor' => ['return $model->display_name;', 'string', []],
    'legacy mutator' => ['$model->alias = "Example";', 'void', []],
    'modern accessor' => ['return $model->label;', 'string', []],
    'modern mutator' => ['$model->label = 42;', 'void', []],
    'declared property wins' => ['return $model->declared;', 'string', []],
    'documented property wins' => ['return $model->documented;', 'string', []],
    'singular relation' => ['return $model->manager;', 'Example\Person|null', []],
    'relation with default' => ['return $model->profile;', 'Example\Person', []],
    'many relation' => ['return $model->members;', 'Illuminate\Database\Eloquent\Collection<int, Example\Person>', []],
    'documented relation' => ['return $model->documentedManager;', 'Example\Person|null', []],
    'inherited table' => ['return (new Example\InheritedRecord)->name;', 'string', []],
    'inherited casts' => ['return (new Example\InheritedPerson)->published_at;', 'Carbon\CarbonImmutable|null', []],
    'inherited accessor' => ['return (new Example\InheritedPerson)->display_name;', 'string', []],
    'inherited self relation' => [
        '(new Example\InheritedSelfPerson)->manager?->childOnly();',
        'void',
        ['non-documented-method'],
    ],
    'late static relation' => [
        'return (new Example\InheritedSelfPerson)->peer;',
        'Example\InheritedSelfPerson|null',
        [],
    ],
    'trait casts' => ['return (new Example\RatedRecord)->rating;', '?int', []],
    'custom key' => ['return (new Example\CustomKey)->code;', 'string', []],
    'renamed table' => ['return (new Example\ChangedRecord)->value;', 'string', []],
    'additional migration path' => ['return (new Example\ExtraRecord)->value;', 'string', []],
    'cast without schema' => ['return (new Example\UnknownRecord)->enabled;', '?bool', []],
    'valid enum writes' => ['$model->status = Example\Status::Published; $model->status = "draft";', 'void', []],
    'valid decimal write' => ['$model->amount = 12.5;', 'void', []],
    'unknown property' => ['$model->nmae;', 'void', ['non-documented-property']],
    'removed property' => ['$model->removed;', 'void', ['non-documented-property']],
    'variadic column drop' => ['$model->removed_together;', 'void', ['non-documented-property']],
    'named column drop' => ['$model->removed_by_name;', 'void', ['non-documented-property']],
    'old column name' => ['$model->previous_name;', 'void', ['non-documented-property']],
    'fillable is not a type' => ['(new Example\UnknownRecord)->imaginary;', 'void', ['non-documented-property']],
    'unsupported cast' => ['(new Example\UnknownRecord)->mystery;', 'void', ['non-documented-property']],
    'dynamic migration' => ['(new Example\ConditionalRecord)->value;', 'void', ['non-documented-property']],
    'dynamic casts' => ['(new Example\DynamicRecord)->name;', 'void', ['non-documented-property']],
    'other connection' => ['(new Example\OtherConnection)->name;', 'void', ['non-documented-property']],
    'invalid column write' => ['$model->name = new stdClass;', 'void', ['invalid-property-assignment-value']],
    'invalid modern write' => ['$model->label = new stdClass;', 'void', ['invalid-property-assignment-value']],
    'invalid enum write' => ['$model->status = new stdClass;', 'void', ['invalid-property-assignment-value']],
    'nullable remains visible' => ['Example\acceptString($model->nickname);', 'void', ['possibly-null-argument']],
    'custom magic dispatcher' => ['(new Example\CustomDispatcher)->name;', 'void', ['non-documented-property']],
    'untyped accessor stays unknown' => [
        'return (new Example\UntypedAccessor)->name;',
        'string',
        ['mixed-return-statement', 'non-documented-property'],
    ],
    'collection property stays invalid' => ['$model->members->id;', 'void', ['non-existent-property']],
];
$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    if ($codes === ['non-documented-property'] || $codes === ['non-existent-property']) {
        $codes[] = 'unused-statement';
    }
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'(Example\Person $model) { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 3,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$run = static function () use ($command, $workspace): array {
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
    if (preg_match('/provider failed|rejected request/i', file_get_contents($workspace.'/stderr.log'))) {
        throw new RuntimeException('Extension fallback invalidates the test; inspect '.$workspace);
    }
    if ($exit !== 1) {
        throw new RuntimeException('Expected negative test failures; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);

    return $report['issues'] ?? [];
};
$actual = [];
foreach ($run() as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
$failures = [];
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        $failures[] = $name.': expected '.json_encode($expected).', got '.json_encode($codes);
    } else {
        echo 'PASS: '.$name."\n";
    }
    unset($actual[$line]);
}
if ($failures !== [] || $actual !== []) {
    throw new RuntimeException(
        implode("\n", $failures).' Unexpected locations: '.json_encode($actual).'; see '.$workspace,
    );
}
// Many host files force simultaneous requests for initially uncached model metadata.
mkdir($workspace.'/parallel');
for ($index = 0; $index < 12; $index++) {
    file_put_contents(
        $workspace.'/parallel/case'.$index.'.php',
        '<?php function parallel'.$index.'(Example\\Person $person): string { return $person->name; }',
    );
}
$config['source']['paths'][] = 'parallel';
$config['extension-hosts']['laramago']['workers'] = 1;
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
foreach ($run() as $issue) {
    $name = $issue['annotations'][0]['span']['file_id']['name'];
    if (str_starts_with($name, 'parallel/')) {
        throw new RuntimeException('Concurrent metadata requests lost a property type; inspect '.$workspace);
    }
}
echo "PASS: concurrent requests share only complete metadata\n";
$config['extension-hosts']['laramago']['workers'] = 3;
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
foreach (['model-executed', 'database/migrations/migration-executed', '.env'] as $file) {
    if (file_exists($workspace.'/'.$file)) {
        throw new RuntimeException('Application code executed: '.$file);
    }
}
echo "PASS: analysis without application execution or environment\n";

// A subsequent process must observe new migration bytes, not a stale persistent index.
file_put_contents($workspace.'/database/migrations/004_change.php', <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\Schema;
    return new class extends Migration {
        public function up(): void { Schema::table('people', function ($table) { $table->integer('name')->change(); }); }
    };
    PHP);
$issues = $run();
if (
    count(array_filter(
        $issues,
        static fn (array $issue): bool => str_contains($issue['message'], 'return')
        && ($issue['annotations'][0]['span']['start']['line'] + 1) === 5,
    )) === 0
) {
    throw new RuntimeException('Changed migration was not reflected in analysis; inspect '.$workspace);
}
echo "PASS: migration changes are visible on the next run\n";
file_put_contents($workspace.'/database/migrations/005_broken.php', '<?php invalid syntax !');
$warnings = array_values(array_filter(
    $run(),
    static fn (array $issue): bool => $issue['code'] === 'ichinya/laramago/metadata-unavailable',
));
if (count($warnings) !== 1 || ! str_contains(implode(' ', $warnings[0]['notes']), '005_broken.php')) {
    throw new RuntimeException('Expected one visible parse warning; inspect '.$workspace);
}
echo "PASS: unreadable metadata produces one warning across workers\n";
unlink($workspace.'/database/migrations/005_broken.php');
file_put_contents($workspace.'/database/migrations/005_indirect.php', <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    return new class extends Migration { public function up(): void { $this->loadSchemaDynamically(); } };
    PHP);
$issues = $run();
if (
    count(array_filter(
        $issues,
        static fn (array $issue): bool => $issue['code'] === 'non-documented-property'
        && ($issue['annotations'][0]['span']['start']['line'] + 1) === 5,
    )) !== 1
) {
    throw new RuntimeException('Indirect migration execution must leave the schema uncertain; inspect '.$workspace);
}
echo "PASS: indirect schema changes invalidate uncertain metadata\n";

// No migration directory is a supported state; casts and accessor contracts survive.
file_put_contents($workspace.'/composer.json', '{}');
rename($workspace.'/database/migrations', $workspace.'/database/archived-migrations');
$issues = $run();
if (
    array_filter($issues, static fn (array $issue): bool => $issue['code'] === 'ichinya/laramago/metadata-unavailable')
    !== []
) {
    throw new RuntimeException('Missing default migrations must not be a setup failure; inspect '.$workspace);
}
echo "PASS: missing migrations keep declared model information available\n";

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
$resolvedWorkspace = realpath($workspace);
if ($resolvedWorkspace === false) {
    throw new RuntimeException('Cannot resolve the test workspace for cleanup.');
}
foreach ($files as $file) {
    $resolvedFile = $file->getRealPath();
    if (
        $resolvedFile === false
        || strncasecmp($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR, strlen($resolvedWorkspace) + 1) !== 0
    ) {
        throw new RuntimeException('Refusing to clean a path outside the test workspace.');
    }
    if ($file->isDir()) {
        rmdir($file->getPathname());
    } else {
        unlink($file->getPathname());
    }
}
rmdir($workspace);
