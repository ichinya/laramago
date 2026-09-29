<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago model keys '.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent';
mkdir($framework, 0777, true);
mkdir($workspace.'/database/migrations', 0777, true);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$model = <<<'PHP'
    <?php
    namespace Illuminate\Database\Eloquent;
    class Model {
        protected $table;
        protected $casts = [];
        protected $primaryKey = 'id';
        protected $keyType = 'int';
        public $incrementing = true;
        public $timestamps = true;
        public function __get(string $key): mixed { return null; }
        public function __set(string $key, mixed $value): void {}
        /** @return mixed */
        public function getAttribute($key) { return null; }
        public function getKeyName(): string { return $this->primaryKey; }
        /** @return mixed */
        public function getKey() { return $this->getAttribute($this->getKeyName()); }
    }
    PHP;
file_put_contents($framework.'/Model.php', $model);
file_put_contents($workspace.'/models.php', <<<'PHP'
    <?php
    namespace App;
    class Record extends \Illuminate\Database\Eloquent\Model {}
    class StringKey extends \Illuminate\Database\Eloquent\Model {
        protected $primaryKey = 'code';
        protected $keyType = 'string';
        public $incrementing = false;
    }
    class Unknown extends \Illuminate\Database\Eloquent\Model { public $incrementing = false; }
    class Custom extends Record { public function getKey(): string { return 'custom'; } }
    class CustomAttribute extends Record { public function getAttribute($key): mixed { return new \stdClass; } }
    /** @property int $id */
    class Documented extends Record {}
    PHP);
file_put_contents($workspace.'/database/migrations/001_create.php', <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Database\Schema\Blueprint;
    throw new RuntimeException('Migration body executed.');
    return new class extends \Illuminate\Database\Migrations\Migration {
        public function up(): void {
            Schema::create('records', function (Blueprint $table): void { $table->id(); });
            Schema::create('string_keys', function (Blueprint $table): void { $table->string('code')->primary(); });
        }
    };
    PHP);
$cases = [
    'known integer key' => 'function known(Record $model): int|string|null { return $model->getKey(); }',
    'unsaved key remains nullable' => 'function nullable(Record $model): int|string { return $model->getKey(); }',
    'non-key return rejected' => 'function invalid(Record $model): \stdClass { return $model->getKey(); }',
    'known string key' => 'function stringKey(StringKey $model): int|string|null { return $model->getKey(); }',
    'unknown schema deferred' => 'function unknown(Unknown $model): string { return $model->getKey(); }',
    'custom key method wins' => 'function custom(Custom $model): string { return $model->getKey(); }',
    'custom attribute reader deferred' => 'function customAttribute(CustomAttribute $model): string { return $model->getKey(); }',
    'documented key property wins' => 'function documented(Documented $model): string { return $model->getKey(); }',
    'native argument error retained' => 'function argument(Record $model): void { $model->getKey("extra"); }',
];
file_put_contents($workspace.'/cases.php', "<?php\nnamespace App;\n".implode("\n", $cases)."\n");
foreach (['enabled', 'disabled', 'changed-body'] as $mode) {
    file_put_contents($framework.'/Model.php', $mode === 'changed-body'
        ? str_replace('return $this->getAttribute($this->getKeyName());', 'return null;', $model)
        : $model);
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['vendor', 'models.php']],
        'extension-hosts' => $mode === 'disabled' ? new stdClass : [
            'laramago' => [
                'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => 1,
            ],
        ],
    ];
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open(
        [PHP_BINARY, $package.'/vendor/bin/mago', '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    if ($exit > 1 || preg_match('/provider failed|rejected request|parse error/i', file_get_contents($workspace.'/'.$mode.'.log'))) {
        throw new RuntimeException('Analyzer failed in '.$mode.' mode: '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $actual = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary') {
                $actual[$annotation['span']['start']['line'] - 2][] = $issue['code'];
                break;
            }
        }
    }
    foreach ($actual as &$codes) {
        sort($codes);
    }
    unset($codes);
    ksort($actual);
    $expected = $mode === 'enabled'
        ? [
            1 => ['invalid-return-statement', 'nullable-return-statement'],
            2 => ['invalid-return-statement', 'nullable-return-statement'],
            4 => ['mixed-return-statement'],
            6 => ['mixed-return-statement'],
            7 => ['mixed-return-statement'],
            8 => ['too-many-arguments'],
        ]
        : [
            0 => ['mixed-return-statement'],
            1 => ['mixed-return-statement'],
            2 => ['mixed-return-statement'],
            3 => ['mixed-return-statement'],
            4 => ['mixed-return-statement'],
            6 => ['mixed-return-statement'],
            7 => ['mixed-return-statement'],
            8 => ['too-many-arguments'],
        ];
    if ($actual !== $expected) {
        throw new RuntimeException($mode.': '.json_encode($actual).' workspace '.$workspace);
    }
    if (is_file($workspace.'/migration-executed') || is_file($workspace.'/bootstrap-executed')) {
        throw new RuntimeException('Application source was executed.');
    }
    echo 'PASS: model key '.$mode.' ('.count($cases).' cases)' . "\n";
}
