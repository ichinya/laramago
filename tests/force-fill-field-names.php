<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-force-fill-keys-'.bin2hex(random_bytes(8));
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
    class Record extends Model { protected $fillable = ['name']; }
    class Child extends Record {}
    class CatalogChild extends Record {}
    class NoCatalog extends Model { protected $fillable = ['name']; protected $casts = ['name' => 'string']; }
    class Incomplete extends Model {}
    class Malformed extends Model {}
    class DuplicateCatalog extends Model {}
    class CustomFill extends Record { public function fill(array $attributes) { return $this; } }
    class CustomForceFill extends Record { public function forceFill(array $attributes) { return $this; } }
    class CustomSetter extends Record { public function setAttribute($key, $value) {} }
    class CustomGuard extends Record { public static function unguarded(callable $callback) {} }
    class CustomFilter extends Record { protected function fillableFromArray(array $attributes) { return []; } }
    class CustomFillable extends Record { public function isFillable($key) { return false; } }
    class CustomDocumented extends Record {
        /** @param array{name: string} $attributes */
        public function forceFill(array $attributes) { return $this; }
    }
    /** @property-write string $virtual */
    class Documented extends Record {}
    class RealProperty extends Record { public string $virtual = ''; }
    class CustomConstructor extends Record { public function __construct(array $attributes = []) {} }
    /** @method static static forceFill(array $attributes) */
    class MethodDocumented extends Record {}
    class EmptyCatalog extends Model {}
    class Ambiguous extends Record {}
    PHP);
$catalogs = [];
foreach ([
    'Record',
    'CatalogChild',
    'CustomFill',
    'CustomForceFill',
    'CustomSetter',
    'CustomGuard',
    'CustomFilter',
    'CustomFillable',
    'CustomDocumented',
    'Documented',
    'RealProperty',
    'CustomConstructor',
    'MethodDocumented',
] as $class) {
    $catalogs['App\\'.$class] = ['complete' => true, 'fields' => ['name', 'payload', 'virtual_setter']];
}
$catalogs['App\\Incomplete'] = ['complete' => false, 'fields' => ['name']];
$catalogs['App\\Malformed'] = ['complete' => true, 'fields' => ['name', false]];
$catalogs['App\\DuplicateCatalog'] = ['complete' => true, 'fields' => ['name']];
$catalogs['app\\duplicatecatalog'] = ['complete' => true, 'fields' => ['name']];
$catalogs['App\\EmptyCatalog'] = ['complete' => true, 'fields' => []];
$catalogs['App\\Ambiguous'] = ['complete' => true, 'fields' => ['name', 'game']];
file_put_contents($workspace.'/composer.json', json_encode(
    ['extra' => ['laramago' => ['model-fields' => $catalogs]]],
    JSON_THROW_ON_ERROR,
));
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("No application execution.");');
$cases = [
    'function missing(Record $m): void { $m->forceFill(["nmae" => 1]); }',
    'function valid(Record $m): void { $m->forceFill(["name" => "ok"]); }',
    'function caseSensitive(Record $m): void { $m->forceFill(["Name" => "ok"]); }',
    'function exactChild(CatalogChild $m): void { $m->forceFill(["typo" => 1]); }',
    'function inherited(Child $m): void { $m->forceFill(["typo" => 1]); }',
    'function noCatalog(NoCatalog $m): void { $m->forceFill(["typo" => 1]); }',
    'function incomplete(Incomplete $m): void { $m->forceFill(["typo" => 1]); }',
    'function malformed(Malformed $m): void { $m->forceFill(["typo" => 1]); }',
    'function duplicateCatalog(DuplicateCatalog $m): void { $m->forceFill(["typo" => 1]); }',
    'function named(Record $m): void { $m->forceFill(attributes: ["typo" => 1]); }',
    'function fill(Record $m): void { $m->fill(["typo" => 1]); }',
    'function create(): void { Record::create(["typo" => 1]); }',
    'function custom(CustomFill $m): void { $m->forceFill(["typo" => 1]); }',
    'function customForce(CustomForceFill $m): void { $m->forceFill(["typo" => 1]); }',
    'function customSetter(CustomSetter $m): void { $m->forceFill(["typo" => 1]); }',
    'function customGuard(CustomGuard $m): void { $m->forceFill(["typo" => 1]); }',
    'function customFilter(CustomFilter $m): void { $m->forceFill(["typo" => 1]); }',
    'function customFillable(CustomFillable $m): void { $m->forceFill(["typo" => 1]); }',
    'function explicitProperty(Documented $m): void { $m->forceFill(["virtual" => "ok"]); }',
    'function realProperty(RealProperty $m): void { $m->forceFill(["virtual" => "ok"]); }',
    'function dynamic(Record $m, string $key): void { $m->forceFill(["typo" => 1, $key => 1]); }',
    'function duplicate(Record $m): void { $m->forceFill(["typo" => 1, "typo" => 2]); }',
    'function spread(Record $m): void { $m->forceFill(["typo" => 1, ...["name" => "ok"]]); }',
    'function reference(Record $m, string &$value): void { $m->forceFill(["typo" => &$value]); }',
    'function nested(Record $m): void { $m->forceFill(["payload->nested" => 1]); }',
    'function arrayVariable(Record $m): void { $attributes = ["typo" => 1]; $m->forceFill($attributes); }',
    'function wrongArgument(Record $m): void { $m->forceFill(123); }',
    'function missingMethod(Record $m): void { $m->missingMethod(); }',
    'function customDocs(CustomDocumented $m): void { $m->forceFill(["name" => [1]]); }',
    'function constructor(CustomConstructor $m): void { $m->forceFill(["typo" => 1]); }',
    'function callableValue(Record $m): void { $m->forceFill(["name" => fn () => 1]); }',
    'function emptyCatalog(EmptyCatalog $m): void { $m->forceFill(["typo" => 1]); }',
    'function docs(MethodDocumented $m): void { $m->forceFill(["typo" => 1]); }',
    'function callableReference(Record $m): void { $callable = $m->forceFill(...); $callable(["typo" => 1]); }',
    'function numeric(Record $m): void { $m->forceFill(["12" => 1]); }',
    'function multiple(Record $m): void { $m->forceFill(["first_typo" => 1, "second_typo" => 2]); }',
    'function nullable(?Record $m): void { $m?->forceFill(["typo" => 1]); }',
    'function catalogSetter(Record $m): void { $m->forceFill(["virtual_setter" => 1]); }',
    'function ambiguous(Ambiguous $m): void { $m->forceFill(["fame" => 1]); }',
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
    $help = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $a) {
            if ($a['kind'] === 'Primary') {
                $actual[$a['span']['start']['line'] - 2][] = $issue['code'];
                if (
                    $issue['code'] === 'ichinya/laramago/laramago-force-fill-missing-field'
                    && ($issue['help'] ?? null) !== null
                ) {
                    $help[$a['span']['start']['line'] - 2][] = $issue['help'];
                }
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
        21 => ['duplicate-array-key'],
        26 => ['invalid-argument'],
        27 => ['non-documented-method'],
        28 => ['possibly-invalid-argument'],
        32 => ['dynamic-static-method-call'],
        34 => ['possibly-invalid-argument'],
    ];
    if ($mode === 'changed-doc') {
        unset($expected[34]);
    }
    if ($mode === 'enabled') {
        foreach ([0, 2, 3, 9, 29, 31, 38] as $case) {
            $expected[$case] = ['ichinya/laramago/laramago-force-fill-missing-field'];
        }
        $expected[35] = array_fill(0, 2, 'ichinya/laramago/laramago-force-fill-missing-field');
    } elseif ($mode === 'disabled') {
        $expected[11] = ['non-documented-method'];
    }
    ksort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException($mode.': '.json_encode($actual).' workspace '.$workspace);
    }
    $expectedHelp = $mode === 'enabled'
        ? [0 => ['Did you mean "name"?'], 2 => ['Did you mean "name"?']]
        : [];
    if ($help !== $expectedHelp) {
        throw new RuntimeException($mode.' help: '.json_encode($help).' workspace '.$workspace);
    }
    echo 'PASS force fill keys '.$mode.' '.count($cases)." cases\n";
}
echo 'workspace '.$workspace."\n";
