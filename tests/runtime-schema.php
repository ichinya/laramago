<?php

declare(strict_types=1);

require __DIR__ . '/fixtures/runtime-schema/support.php';
// Each control chooses runtime opt-in explicitly, independently of the caller.
putenv('LARAMAGO_RUNTIME_METADATA');
$autoload = getenv('LARAMAGO_RUNTIME_TEST_AUTOLOAD');
runtimeAssert(
    is_string($autoload) && is_file($autoload),
    'Set LARAMAGO_RUNTIME_TEST_AUTOLOAD to an isolated laravel/framework test installation. This integration gate requires real Laravel and PDO SQLite; missing dependencies are not a pass.',
);
runtimeAssert(extension_loaded('pdo_sqlite'), 'Runtime integration gate requires PDO SQLite.');
$package = str_replace('\\', '/', dirname(__DIR__));
$root = str_replace('\\', '/', sys_get_temp_dir()) . '/laramago runtime schema ' . bin2hex(random_bytes(8));
runtimeProject($root, $autoload);
$models = ['Record', 'BetaRecord', 'PrefixedRecord', 'SplitRecord', 'StrictRecord'];
$digests = [];
foreach (glob($root . '/database/*.sqlite') as $database) {
    $digests[$database] = hash_file('sha256', $database);
}
[$exit, $snapshot, $json] = runtimeCollect($package, $root, $models);
runtimeAssert(
    $exit === 0 && $snapshot['errors'] === [] && !$snapshot['truncated'],
    'Healthy collector failed: ' . $json . '; ' . $root,
);
runtimeAssert(
    $snapshot['schemaVersion'] === 1
    && $snapshot['scope'] === ['kind' => 'runtime-schema', 'evidence' => 'evaluated-schema'],
    'Missing versioned runtime evidence.',
);
$record = $snapshot['models']['SyntheticRuntime\\Record'];
runtimeAssert(
    $record['connection'] === 'alpha' && $record['table'] === 'records' && $record['casts']['score'] === 'integer',
    'Runtime model identity or casts lost.',
);
runtimeAssert(
    $record['columns']['score']['nullable'] === true && $record['columns']['score']['strict'] === false,
    'SQLite nullable/affinity evidence invented.',
);
$beta = $snapshot['models']['SyntheticRuntime\\BetaRecord'];
runtimeAssert(
    $beta['connection'] === 'beta' && isset($beta['columns']['beta_only']) && !isset($beta['columns']['alpha_only']),
    'Same-named table used the wrong connection.',
);
runtimeAssert(
    isset($snapshot['models']['SyntheticRuntime\\PrefixedRecord']['columns']['write_only']),
    'Prefix was missing or added twice.',
);
runtimeAssert(
    isset($snapshot['models']['SyntheticRuntime\\SplitRecord']['columns']['write_only'])
    && !isset($snapshot['models']['SyntheticRuntime\\SplitRecord']['columns']['replica_only']),
    'Collector used read replica instead of write schema.',
);
runtimeAssert(
    $snapshot['models']['SyntheticRuntime\\StrictRecord']['columns']['amount']['strict'] === true,
    'Strict SQLite evidence lost.',
);
runtimeAssert(
    !str_contains($json, 'secret-canary-do-not-export') && !str_contains($json, 'synthetic-only-no-real-secret'),
    'Collector exported credentials or config values.',
);
foreach ($digests as $database => $digest) {
    runtimeAssert(hash_file('sha256', $database) === $digest, 'Collector modified database: ' . $database);
}
echo
    'PASS: real Laravel SQLite collection, nullable/strict evidence, connections, prefix, write schema, privacy, database unchanged'
        . PHP_EOL
;
$failedSnapshots = [];
foreach ([
    'MissingRecord' => 'runtime-table-missing',
    'UnavailableRecord' => 'runtime-schema-failed',
    'UnsupportedRecord' => 'runtime-unsupported-driver',
    'BootQueryRecord' => 'runtime-model-failed',
] as $model => $code) {
    [$failedExit, $failed, $failedJson] = runtimeCollect($package, $root, [$model], $model);
    runtimeAssert(
        $failedExit !== 0 && in_array($code, array_column($failed['errors'], 'code'), true) && $failed['models'] === [],
        'Expected explicit failure for ' . $model . ': ' . $failedJson,
    );
    runtimeAssert(!str_contains($failedJson, 'secret-canary-do-not-export'), 'Failure exported credentials.');
    $failedSnapshots[$model] = [$failedJson, 'ichinya/laramago/' . str_replace('runtime-', 'runtime-metadata-', $code)];
    echo 'PASS: collector ' . $model . PHP_EOL;
}
$bootstrap = file_get_contents($root . '/bootstrap/app.php');
file_put_contents($root . '/bootstrap/app.php', '<?php throw new RuntimeException("secret-canary-do-not-export");');
[$failedExit, $failed, $failedJson] = runtimeCollect($package, $root, ['Record'], 'bootstrap-failure');
runtimeAssert(
    $failedExit !== 0
    && in_array('runtime-bootstrap-failed', array_column($failed['errors'], 'code'), true)
    && !str_contains($failedJson, 'secret-canary-do-not-export'),
    'Bootstrap failure is not explicit/private.',
);
$failedSnapshots['bootstrap'] = [$failedJson, 'ichinya/laramago/runtime-metadata-bootstrap-failed'];
file_put_contents($root . '/bootstrap/app.php', $bootstrap);
echo 'PASS: bootstrap failure privacy' . PHP_EOL;
file_put_contents($root . '/bootstrap/app.php', '<?php echo "secret-canary-do-not-export"; ' . substr($bootstrap, 6));
[$failedExit, $failed, $failedJson] = runtimeCollect($package, $root, ['Record'], 'bootstrap-output');
runtimeAssert(
    $failedExit !== 0
    && in_array('runtime-output-rejected', array_column($failed['errors'], 'code'), true)
    && !str_contains($failedJson, 'secret-canary-do-not-export'),
    'Application output escaped the metadata envelope.',
);
file_put_contents($root . '/bootstrap/app.php', $bootstrap);
echo 'PASS: application output rejection' . PHP_EOL;
unlink($root . '/bootstrap/cache/packages.php');
[$uncachedExit, $uncached, $uncachedJson] = runtimeCollect($package, $root, ['Record'], 'uncached-packages');
runtimeAssert(
    $uncachedExit === 0 && !file_exists($root . '/bootstrap/cache/packages.php'),
    'Collector generated an application package cache: ' . $uncachedJson,
);
echo 'PASS: uncached application package manifest does not write' . PHP_EOL;
file_put_contents(
    $root . '/app/ArgumentRecord.php',
    '<?php namespace SyntheticRuntime; class ArgumentRecord extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = "records"; protected $casts = ["label" => NullStringCast::class . ":secret-canary-do-not-export"]; }',
);
[$argumentExit, $argument, $argumentJson] = runtimeCollect($package, $root, ['ArgumentRecord'], 'cast-arguments');
runtimeAssert(
    $argumentExit === 0
    && $argument['models']['SyntheticRuntime\\ArgumentRecord']['casts']['label'] === 'SyntheticRuntime\\NullStringCast'
    && !str_contains($argumentJson, 'secret-canary-do-not-export'),
    'Custom cast arguments were exported.',
);
echo 'PASS: custom cast arguments do not export' . PHP_EOL;
file_put_contents(
    $root . '/app/GeneratedRecord.php',
    '<?php namespace SyntheticRuntime; class GeneratedRecord extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = "generated_records"; }',
);
$generatedDatabase = new PDO('sqlite:' . $root . '/database/alpha.sqlite');
$generatedDatabase->exec(
    'CREATE TABLE generated_records (base INTEGER, derived INTEGER GENERATED ALWAYS AS (base + 1))',
);
[$generatedExit, $generated, $generatedJson] = runtimeCollect(
    $package,
    $root,
    ['GeneratedRecord'],
    'generated-columns',
);
runtimeAssert(
    $generatedExit !== 0 && in_array('runtime-unsupported-column', array_column($generated['errors'], 'code'), true),
    'Generated writable column contract was invented: ' . $generatedJson,
);
echo 'PASS: generated columns rejected explicitly' . PHP_EOL;

copy($package . '/tests/fixtures/analysis/framework.php.stub', $root . '/framework.php');
file_put_contents(
    $root . '/cast-contract.php',
    '<?php namespace Illuminate\\Contracts\\Database\\Eloquent; interface CastsAttributes { public function get(\\Illuminate\\Database\\Eloquent\\Model $model, string $key, mixed $value, array $attributes); public function set(\\Illuminate\\Database\\Eloquent\\Model $model, string $key, mixed $value, array $attributes); }',
);
$binary = getenv('MAGO_BINARY') ?: $package . '/vendor/bin/mago';
$sdk = getenv('MAGO_SDK_AUTOLOAD') ?: $package . '/vendor/autoload.php';
$native = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];

/** Native Mago is the result oracle; no mocked SDK/type inference. */
$analyze = static function (
    string $name,
    string $body,
    string $return,
    ?string $snapshotPath,
    array $required,
    int $workers = 1,
    string $mutation = '',
) use ($root, $package, $native, $sdk): array {
    file_put_contents(
        $root . '/cases.php',
        '<?php function scenario(\\SyntheticRuntime\\Record $record, \\SyntheticRuntime\\StrictRecord $strict, \\SyntheticRuntime\\BetaRecord $beta): '
        . $return
        . ' { '
        . $body
        . ' }',
    );
    $paths = ['cases.php'];
    if ($workers === 3) {
        if (!is_dir($root . '/parts')) {
            mkdir($root . '/parts', 0777, true);
        }
        for ($part = 0; $part < 12; ++$part) {
            file_put_contents(
                $root . '/parts/file' . $part . '.php',
                '<?php function part'
                . $part
                . '(\\SyntheticRuntime\\StrictRecord $record): ?int { return $record->amount; }',
            );
        }
        $paths[] = 'parts';
    }
    $workerCommand = [
        PHP_BINARY,
        $package . '/tests/fixtures/runtime-schema/worker.php',
        $sdk,
        $root,
        $snapshotPath ?? '-',
        $mutation,
        $package,
    ];
    if (str_starts_with($mutation, 'public-')) {
        $publicAutoload = $root . '/public-sdk.php';
        file_put_contents(
            $publicAutoload,
            '<?php require '
            . var_export($package . '/vendor/autoload.php', true)
            . '; require_once '
            . var_export($sdk, true)
            . ';',
        );
        $workerCommand = [PHP_BINARY, $package . '/bin/laramago-worker.php', $publicAutoload, $root];
        if ($mutation === 'public-flag') {
            $workerCommand[] = '--runtime-metadata=' . $snapshotPath;
        }
    }
    file_put_contents($root . '/mago.json', json_encode([
        'extends' => $package . '/presets/laravel.toml',
        'php-version' => '8.2',
        'threads' => 1,
        'source' => ['paths' => $paths, 'includes' => ['framework.php', 'cast-contract.php', 'app']],
        'extension-hosts' => [
            'runtime' => [
                'command' => $workerCommand,
                'workers' => $workers,
                'request-timeout-ms' => 120000,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $priorSelection = getenv('LARAMAGO_RUNTIME_METADATA');
    if ($mutation === 'public-env') {
        putenv('LARAMAGO_RUNTIME_METADATA=' . $snapshotPath);
    }
    try {
        [$exit, $output, $stderr] = runtimeProcess(
            [...$native, '--workspace', $root, 'analyze', '--reporting-format=json'],
            $root,
            $name,
        );
    } finally {
        if ($mutation === 'public-env') {
            putenv(
                $priorSelection === false
                    ? 'LARAMAGO_RUNTIME_METADATA'
                    : 'LARAMAGO_RUNTIME_METADATA=' . $priorSelection,
            );
        }
    }
    runtimeAssert(
        in_array($exit, [0, 1], true),
        'Native runtime control operational failure: ' . $name . '; ' . $stderr . '; ' . $root,
    );
    runtimeAssert(
        !preg_match('/rejected request|protocol error|worker[^\r\n]*failed|PHP Fatal|PHP Warning/i', $stderr),
        'Native worker fallback: ' . $name . '; ' . $stderr,
    );
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $codes = array_column($report['issues'] ?? [], 'code');
    if ($required === []) {
        runtimeAssert(
            $exit === 0 && $codes === [],
            'Expected native success ' . $name . '; ' . json_encode($codes) . '; ' . $root,
        );
    } else {
        runtimeAssert($exit === 1, 'Runtime failure left native success: ' . $name);
        foreach ($required as $code) {
            runtimeAssert(
                in_array($code, $codes, true),
                'Missing native diagnostic ' . $code . ': ' . $name . '; ' . json_encode($codes) . '; ' . $root,
            );
        }
    }
    echo 'PASS: native ' . $name . PHP_EOL;
    return $codes;
};

// Default mode must not execute bootstrap even though collector is installed.
unlink($root . '/bootstrap/entered');
$analyze('default-deferred', 'return $strict->amount;', '?int', null, ['non-documented-property']);
runtimeAssert(!file_exists($root . '/bootstrap/entered'), 'Default analysis executed application bootstrap.');
[$lintExit, $lintOutput, $lintStderr] = runtimeProcess(
    [...$native, '--workspace', $root, 'lint', '--reporting-format=json'],
    $root,
    'default-lint',
);
runtimeAssert(
    $lintExit === 0 && !file_exists($root . '/bootstrap/entered'),
    'Default lint executed application bootstrap or failed: ' . $lintStderr,
);
echo 'PASS: default native lint does not bootstrap' . PHP_EOL;

[$exit, $snapshot, $json] = runtimeCollect($package, $root, $models, 'fresh');
$path = $root . '/snapshot.json';
file_put_contents($path, $json);
foreach ($failedSnapshots as $name => [$failedJson, $code]) {
    $failedPath = $root . '/failed-' . $name . '.json';
    file_put_contents($failedPath, $failedJson);
    $analyze(
        'collector-failure-' . $name,
        'return $strict->amount;',
        '?int',
        $failedPath,
        [$code, 'non-documented-property'],
    );
}
$analyze('actual-schema-only', 'return $strict->amount;', '?int', $path, []);
$analyze('production-flag', 'return $strict->amount;', '?int', $path, [], 1, 'public-flag');
$analyze('production-env-three-workers', 'return $strict->amount;', '?int', $path, [], 3, 'public-env');
$analyze('nullable-read', 'return $record->score;', '?int', $path, []);
$analyze('connection-specific-read', 'return $beta->score;', '?string', $path, []);
$analyze('connection-specific-wrong-read', 'return $beta->score;', '?int', $path, ['invalid-return-statement']);
$analyze(
    'ordinary-sqlite-affinity-deferred',
    'return $record->alpha_only;',
    '?string',
    $path,
    ['non-documented-property'],
);
$analyze('nonnull-read-rejected', 'return $record->score;', 'int', $path, ['invalid-return-statement']);
$analyze('cast-write', '$record->score = 42;', 'void', $path, []);
$analyze('cast-null-write', '$record->score = null;', 'void', $path, []);
$analyze('cast-string-write', '$record->score = "42";', 'void', $path, []);
$analyze('cast-bad-write', '$record->score = [];', 'void', $path, ['invalid-property-assignment-value']);
$analyze('nonnull-column-null-write', '$record->hard = null;', 'void', $path, ['invalid-property-assignment-value']);
$analyze(
    'nonnull-column-read-unhydrated',
    'return (new \\SyntheticRuntime\\Record)->hard;',
    'int',
    $path,
    ['invalid-return-statement'],
);
$analyze('custom-cast-null-read', 'return $record->label;', 'string', $path, []);
$analyze('custom-cast-null-write', '$record->label = null;', 'void', $path, []);
$analyze('accessor-null-read', 'return $record->accessed;', 'string', $path, []);
$analyze('accessor-null-write', '$record->accessed = null;', 'void', $path, []);
$analyze('native-priority', 'return $record->native;', 'string', $path, []);
$analyze('documented-priority', 'return $record->documented;', 'string', $path, []);
$analyze('unknown-not-hidden', 'return $record->unknown;', 'int', $path, ['non-documented-property']);
$analyze('three-workers', 'return $strict->amount;', '?int', $path, [], 3);
file_put_contents($root . '/bootstrap/hang.flag', 'synthetic');
$started = hrtime(true);
$analyze(
    'genuine-collector-timeout',
    'return $strict->amount;',
    '?int',
    $path,
    ['ichinya/laramago/runtime-metadata-collector-timeout'],
);
runtimeAssert(((hrtime(true) - $started) / 1e9) < 10.0, 'Native analysis did not bound the genuine bootstrap hang.');
unlink($root . '/bootstrap/hang.flag');

foreach ([
    'version' => static function (array &$s): void {
        $s['schemaVersion'] = 999;
    },
    'truncated' => static function (array &$s): void {
        $s['truncated'] = true;
    },
    'error' => static function (array &$s): void {
        $s['errors'][] = [
            'code' => 'runtime-schema-failed',
            'file' => null,
            'message' => 'Synthetic unavailable schema.',
        ];
    },
] as $mode => $mutate) {
    $bad = $snapshot;
    $mutate($bad);
    file_put_contents($path, json_encode($bad, JSON_THROW_ON_ERROR));
    $analyze('envelope-' . $mode, 'return $strict->amount;', '?int', $path, [
        $mode === 'version'
            ? 'ichinya/laramago/runtime-metadata-invalid'
            : (
                $mode === 'error'
                    ? 'ichinya/laramago/runtime-metadata-schema-failed'
                    : 'ichinya/laramago/runtime-metadata-unavailable'
            ),
        'non-documented-property',
    ]);
}
file_put_contents($path, '{');
$analyze(
    'malformed-json',
    'return $strict->amount;',
    '?int',
    $path,
    ['ichinya/laramago/runtime-metadata-invalid', 'non-documented-property'],
);
file_put_contents($path, $json);
file_put_contents($root . '/config/new.php', '<?php return [];');
$analyze(
    'source-revision',
    'return $strict->amount;',
    '?int',
    $path,
    ['ichinya/laramago/runtime-metadata-source-changed', 'non-documented-property'],
);
$analyze(
    'production-env-stale',
    'return $strict->amount;',
    '?int',
    $path,
    ['ichinya/laramago/runtime-metadata-source-changed', 'non-documented-property'],
    1,
    'public-env',
);
unlink($root . '/config/new.php');
$pdo = new PDO('sqlite:' . $root . '/database/alpha.sqlite');
$pdo->exec('ALTER TABLE strict_records ADD COLUMN changed TEXT');
$analyze(
    'schema-revision',
    'return $strict->amount;',
    '?int',
    $path,
    ['ichinya/laramago/runtime-metadata-schema-changed', 'non-documented-property'],
);
[$exit, $snapshot, $json] = runtimeCollect($package, $root, $models, 'after-ddl');
file_put_contents($path, $json);
$analyze(
    'late-schema-revision',
    'return $strict->amount;',
    '?int',
    $path,
    ['ichinya/laramago/runtime-metadata-schema-changed'],
    1,
    'late-schema',
);
[$exit, $snapshot, $json] = runtimeCollect($package, $root, $models, 'after-late-ddl');
file_put_contents($path, $json);
$analyze(
    'late-source-three-workers',
    'return $strict->amount;',
    '?int',
    $path,
    ['ichinya/laramago/runtime-metadata-source-changed'],
    3,
    'late-source',
);
echo 'Runtime evidence directory: ' . $root . PHP_EOL;
