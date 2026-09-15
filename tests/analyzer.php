<?php

declare(strict_types=1);

// Exercise the real analyzer/worker protocol, including its native argument checks.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
if (! is_file($binary)) {
    throw new RuntimeException('Install Composer dependencies or set MAGO_BINARY.');
}
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = sys_get_temp_dir().'/laramago-analyzer-'.bin2hex(random_bytes(8));
mkdir($workspace);
$package = str_replace('\\', '/', dirname(__DIR__));
$cases = [
    'explicit query' => ["return TestModel::query()->where('name', 'Example');", 'Builder<TestModel>', []],
    'magic where' => ["return TestModel::where('name', 'Example');", 'Builder<TestModel>', []],
    'instance where' => ["return (new TestModel)->where('name', 'Example');", 'Builder<TestModel>', []],
    'case insensitive call' => ["return TestModel::WHERE('name', 'Example');", 'Builder<TestModel>', []],
    'orWhere' => ["return TestModel::orWhere('name', 'Example');", 'Builder<TestModel>', []],
    'whereNot' => ["return TestModel::whereNot('name', 'Example');", 'Builder<TestModel>', []],
    'orWhereNot' => ["return TestModel::orWhereNot('name', 'Example');", 'Builder<TestModel>', []],
    'array condition' => ["return TestModel::where(['name' => 'Example']);", 'Builder<TestModel>', []],
    'named arguments' => ["return TestModel::where(value: 'Example', column: 'name');", 'Builder<TestModel>', []],
    'chain model type' => ["return TestModel::where('name', 'Example')->first();", 'TestModel|null', []],
    'closure model type' => [
        "return TestModel::where(function (\$query): void { acceptModel(\$query->first()); });",
        'Builder<TestModel>',
        [],
    ],
    'misspelled method' => ["TestModel::wherre('name', 'Example');", 'void', ['non-documented-method']],
    'missing argument' => ['TestModel::where();', 'void', ['too-few-arguments']],
    'invalid argument' => ['TestModel::where(new stdClass);', 'void', ['less-specific-nested-argument-type']],
    'too many arguments' => ["TestModel::where('name', '=', 'Example', 'and', true);", 'void', ['too-many-arguments']],
    'invalid named argument' => ["TestModel::where(column: 'name', typo: true);", 'void', ['invalid-named-argument']],
    'explicit declaration preserved' => ["return DeclaredModel::where('Example');", 'int', []],
    'custom factory deferred' => ["CustomFactoryModel::where('Example');", 'void', ['non-documented-method']],
    'custom builder property deferred' => ["CustomPropertyModel::where('Example');", 'void', ['non-documented-method']],
    'custom builder attribute deferred' => [
        "CustomAttributeModel::where('Example');",
        'void',
        ['non-documented-method'],
    ],
    'unrelated class unchanged' => ["OtherModel::where('Example');", 'void', ['non-documented-method']],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
    final class TestModel extends Model {}
    final class DeclaredModel extends Model {
        public static function where(string $column): int { return strlen($column); }
    }
    final class CustomFactoryModel extends Model {
        /** @return Builder<static> */
        public function newEloquentBuilder($query) { return parent::newEloquentBuilder($query); }
    }
    /** @extends Builder<TestModel> */
    final class CustomBuilder extends Builder {}
    final class CustomPropertyModel extends Model { protected static string $builder = CustomBuilder::class; }
    #[UseEloquentBuilder(CustomBuilder::class)]
    final class CustomAttributeModel extends Model {}
    final class OtherModel { public static function __callStatic(string $method, array $arguments): mixed { return null; } }
    function acceptModel(?TestModel $model): void {}

    PHP;
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
copy($package.'/tests/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 1,
        ],
    ],
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
if ($exit !== 1) {
    throw new RuntimeException('Expected failing negative cases; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $actual[$line][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException(
            $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException(
        'Unexpected diagnostics outside the test cases: '.json_encode($actual).'; see '.$workspace,
    );
}
foreach (glob($workspace.'/*') ?: [] as $file) {
    unlink($file);
}
rmdir($workspace);
