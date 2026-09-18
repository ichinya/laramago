<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-force-fill-'.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent';
mkdir($framework.'/Concerns', 0777, true);
$native = file_get_contents(__DIR__.'/fixtures/analysis/force-fill-native.php.stub');
$methods = substr($native, strpos($native, '{') + 1, strrpos($native, '}') - strpos($native, '{') - 1);
$base = file_get_contents(__DIR__.'/fixtures/analysis/framework.php.stub');
$base = preg_replace(
    '/class Model\s*\{/',
    "class Model {\nuse \\Illuminate\\Database\\Eloquent\\Concerns\\GuardsAttributes;\nuse \\Illuminate\\Database\\Eloquent\\Concerns\\HasAttributes;\n"
    .$methods,
    $base,
    1,
);
file_put_contents($framework.'/Model.php', $base);
copy(__DIR__.'/fixtures/analysis/force-fill-guards.php.stub', $framework.'/Concerns/GuardsAttributes.php');
copy(__DIR__.'/fixtures/analysis/force-fill-attributes.php.stub', $framework.'/Concerns/HasAttributes.php');
file_put_contents($workspace.'/models.php', <<<'PHP'
    <?php
    namespace App;
    use Illuminate\Database\Eloquent\Model;
    /**
     * @property-write \stdClass $payload
     * @property-write array<string, int> $entries
     * @property-read \stdClass $readonly
     * @property \stdClass $ordinary
     * @property-read string $different
     * @property-write \stdClass $different
     * @property-write int $number
     */
    class Record extends Model {}
    class Child extends Record {}
    /** @property-write array<string, int> $payload */
    class ChildContract extends Record {}
    class CustomFill extends Record { public function fill(array $attributes) { return $this; } }
    class CustomForceFill extends Record { public function forceFill(array $attributes) { return $this; } }
    class CustomSetter extends Record { public function setAttribute($key, $value) {} }
    class CustomGuard extends Record { public static function unguarded(callable $callback) {} }
    class CustomDocumented extends Record {
        /** @param array{payload: string} $attributes */
        public function forceFill(array $attributes) { return $this; }
    }
    /** @property-write \stdClass $payload */
    class RealProperty extends Model { public array $payload = []; }
    class CastOnly extends Model { protected $casts = ['payload' => 'array']; }
    PHP);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("No application execution.");');
$cases = [
    'function mismatch(Record $m): void { $m->forceFill(["payload" => [1]]); }',
    'function objectMismatch(Record $m): void { $m->forceFill(["entries" => new \\stdClass]); }',
    'function valid(Record $m): void { $m->forceFill(["payload" => new \\stdClass]); }',
    'function inherited(Child $m): void { $m->forceFill(["payload" => [1]]); }',
    'function override(ChildContract $m): void { $m->forceFill(["payload" => ["id" => 1]]); }',
    'function readOnly(Record $m): void { $m->forceFill(["readonly" => [1]]); }',
    'function ordinary(Record $m): void { $m->forceFill(["ordinary" => [1]]); }',
    'function independent(Record $m): void { $m->forceFill(["different" => [1]]); }',
    'function ignored(Record $m): void { $m->fill(["payload" => [1]]); }',
    'function create(): void { Record::create(["payload" => [1]]); }',
    'function cast(CastOnly $m): void { $m->forceFill(["payload" => new \\stdClass]); }',
    'function unknown(Record $m): void { $m->forceFill(["unknown" => [1]]); }',
    'function coercion(Record $m): void { $m->forceFill(["number" => "12"]); }',
    'function custom(CustomFill $m): void { $m->forceFill(["payload" => [1]]); }',
    'function customForce(CustomForceFill $m): void { $m->forceFill(["payload" => [1]]); }',
    'function customSetter(CustomSetter $m): void { $m->forceFill(["payload" => [1]]); }',
    'function customGuard(CustomGuard $m): void { $m->forceFill(["payload" => [1]]); }',
    'function doc(CustomDocumented $m): void { $m->forceFill(["payload" => "ok"]); }',
    'function invalidDoc(CustomDocumented $m): void { $m->forceFill(["payload" => [1]]); }',
    'function real(RealProperty $m): void { $m->forceFill(["payload" => [1]]); }',
    'function named(Record $m): void { $m->forceFill(attributes: ["payload" => [1]]); }',
    'function duplicate(Record $m): void { $m->forceFill(["payload" => [1], "payload" => new \\stdClass]); }',
    'function dynamic(Record $m, string $key): void { $m->forceFill([$key => [1]]); }',
    'function wrongArgument(Record $m): void { $m->forceFill(123); }',
    'function unknownMethod(Record $m): void { $m->missingMethod(); }',
    'function spread(Record $m): void { $m->forceFill([...["payload" => [1]]]); }',
    'function nested(Record $m): void { $m->forceFill(["payload->nested" => [1]]); }',
];
file_put_contents($workspace.'/cases.php', "<?php\nnamespace App;\n".implode("\n", $cases)."\n");
foreach (['enabled', 'disabled', 'changed-body', 'changed-doc', 'changed-signature'] as $mode) {
    file_put_contents($framework.'/Model.php', match ($mode) {
        'changed-body' => str_replace(
            'return static::unguarded(fn () => $this->fill($attributes));',
            'return $this;',
            $base,
        ),
        'changed-doc' => str_replace('array<string, mixed>', 'array<array-key, mixed>', $base),
        'changed-signature' => str_replace(
            'public function forceFill(array $attributes)',
            'public function forceFill(array $attributes, $ignored = null)',
            $base,
        ),
        default => $base,
    });
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['vendor', 'models.php']],
        'extension-hosts' => $mode !== 'disabled'
            ? [
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
            ] : new stdClass,
    ];
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open(
        [PHP_BINARY, $package.'/vendor/bin/mago', '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
            2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
        ],
        $pipes,
    );
    fclose($pipes[0]);
    $exit = proc_close($process);
    if (
        $exit > 1
        || preg_match('/provider failed|rejected request|parse error/i', file_get_contents($workspace.'/'.$mode.'.log'))
    ) {
        throw new RuntimeException('Analyzer failed: '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $actual = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $a) {
            if ($a['kind'] === 'Primary') {
                $actual[$a['span']['start']['line'] - 2][] = $issue['code'];
                break;
            }
        }
    }
    foreach ($actual as &$codes) {
        sort($codes);
    }
    unset($codes);
    ksort($actual);
    $expected = [
        18 => ['possibly-invalid-argument'],
        21 => ['duplicate-array-key'],
        23 => ['invalid-argument'],
        24 => ['non-documented-method'],
    ];
    if ($mode === 'enabled') {
        foreach ([0, 1, 3, 7, 20] as $case) {
            $expected[$case] = ['ichinya/laramago/laramago-force-fill-write-contract'];
        }
    } elseif ($mode === 'disabled') {
        $expected[9] = ['non-documented-method'];
    }
    ksort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException($mode.': '.json_encode($actual).' workspace '.$workspace);
    }
    echo 'PASS force fill '.$mode.' '.count($cases)." cases\n";
}
echo 'workspace '.$workspace."\n";
