<?php

declare(strict_types=1);

// Exercise the native validation entry point through the real Mago worker.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago validation database '.bin2hex(random_bytes(8));
mkdir($workspace);
$validation = $workspace.'/vendor/laravel/framework/src/Illuminate/Validation';
mkdir($validation.'/Concerns', recursive: true);
foreach ([
    'Factory.php',
    'Validator.php',
    'Rule.php',
    'ValidationRuleParser.php',
    'Concerns/ValidatesAttributes.php',
] as $file) {
    copy(__DIR__.'/fixtures/analysis/native-validation-'.basename($file).'.stub', $validation.'/'.$file);
}

$mode = $argv[1] ?? 'complete';
if (! in_array(
    $mode,
    [
        'complete',
        'incomplete',
        'malformed-table',
        'malformed-column',
        'numeric-table',
        'changed-query-column',
        'unasserted-native',
    ],
    true,
)) {
    throw new RuntimeException('Unknown mode.');
}
$tables = [
    'users' => ['complete' => $mode !== 'incomplete', 'columns' => ['email', 'id']],
];
if ($mode === 'malformed-table') {
    $tables['broken'] = ['complete' => true, 'columns' => [null]];
}
if ($mode === 'malformed-column') {
    $tables['users']['columns'][] = null;
}
if ($mode === 'numeric-table') {
    $tables['42'] = ['complete' => true, 'columns' => ['id']];
}
if ($mode === 'changed-query-column') {
    $path = $validation.'/Concerns/ValidatesAttributes.php';
    $contents = file_get_contents($path);
    $before = "return isset(\$parameters[1]) && \$parameters[1] !== 'NULL'";
    if (substr_count($contents, $before) !== 1) {
        throw new RuntimeException('Cannot modify native query-column fixture.');
    }
    file_put_contents($path, str_replace($before, 'return false', $contents));
}
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'validation-database' => [
                'native-rule-semantics' => $mode !== 'unasserted-native',
                'connections' => [
                    'default' => ['complete' => true, 'tables' => $tables],
                    'archive' => [
                        'complete' => true,
                        'tables' => [
                            'entries' => ['complete' => true, 'columns' => ['code']],
                        ],
                    ],
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
$cases = [
    'present string rule' => ['["email" => "required|exists:users,email"]', []],
    'missing table' => ['["email" => "exists:missing,email"]', ['laramago-unknown-validation-table']],
    'missing explicit column' => ['["email" => "unique:users,emali"]', ['laramago-unknown-validation-column']],
    'inferred column' => ['["emali" => "exists:users"]', ['laramago-unknown-validation-column']],
    'null placeholder' => ['["emali" => "unique:users,NULL"]', ['laramago-unknown-validation-column']],
    'ignore column' => ['["email" => "unique:users,email,3,identifer"]', ['laramago-unknown-validation-column']],
    'null ignored id' => ['["email" => "unique:users,email,NULL,identifer"]', []],
    'named connection' => ['["code" => "exists:archive.entries,code"]', []],
    'table case differs' => ['["email" => "exists:Users,email"]', []],
    'column case differs' => ['["email" => "exists:users,Email"]', []],
    'unknown connection' => ['["code" => "exists:unknown.entries,code"]', []],
    'dynamic field' => ['[$field => "exists:missing,email"]', []],
    'numeric field key' => ['["0" => "exists:missing,id"]', ['laramago-unknown-validation-table']],
    'object table' => ['["email" => [\\Illuminate\\Validation\\Rule::exists("missing", "email")]]', []],
    'object column' => ['["email" => [\\Illuminate\\Validation\\Rule::unique("users", "emali")]]', []],
    'object ignore column' => [
        '["email" => [\\Illuminate\\Validation\\Rule::unique("users", "email")->ignore(3, "identifer")]]',
        [],
    ],
    'object falsy ignore' => [
        '["email" => [\\Illuminate\\Validation\\Rule::unique("users", "email")->ignore(0, "identifer")]]',
        [],
    ],
    'dynamic table' => ['["email" => [\\Illuminate\\Validation\\Rule::exists($table, "email")]]', []],
    'model class table' => ['["email" => "exists:App\\\\UserRecord,emali"]', []],
    'unresolved class table' => [
        '["email" => [\\Illuminate\\Validation\\Rule::exists(MissingModel::class, "emali")]]',
        [],
    ],
];
$eloquent = $workspace.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent';
mkdir($eloquent, recursive: true);
file_put_contents(
    $eloquent.'/Model.php',
    '<?php namespace Illuminate\\Database\\Eloquent; class Model { protected $table = null; protected $connection = null; }',
);
$source = "<?php\nnamespace App;\nclass UserRecord extends \\Illuminate\\Database\\Eloquent\\Model { protected \$table = 'users'; }\n";
$expected = [];
foreach ($cases as $label => [$rules, $codes]) {
    $line = substr_count($source, "\n") + 1;
    $source .=
        'function case'
        .count($expected)
        .'(\\Illuminate\\Validation\\Factory $factory, string $field, string $table): void { '
        .'$factory->make([], '
        .$rules
        ."); }\n";
    if ($mode === 'incomplete') {
        $codes = array_values(array_filter(
            $codes,
            static fn (string $code): bool => $code === 'laramago-unknown-validation-table',
        ));
    } elseif (in_array($mode, ['malformed-table', 'numeric-table'], true)) {
        $codes = array_values(array_filter(
            $codes,
            static fn (string $code): bool => $code !== 'laramago-unknown-validation-table',
        ));
    } elseif ($mode === 'malformed-column') {
        $codes = [];
    } elseif (in_array($mode, ['changed-query-column', 'unasserted-native'], true)) {
        $codes = [];
    }
    $expected[$line] = [$label, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Validation/Factory.php',
            'vendor/laravel/framework/src/Illuminate/Validation/Validator.php',
            'vendor/laravel/framework/src/Illuminate/Validation/Rule.php',
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php',
        ],
    ],
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
            'workers' => 2,
        ],
    ],
], JSON_THROW_ON_ERROR));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
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
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    if (! str_starts_with($issue['code'], 'ichinya/laramago/laramago-unknown-validation-')) {
        continue;
    }
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $actual[$line][] = substr($issue['code'], strlen('ichinya/laramago/'));
}
foreach ($expected as $line => [$label, $codes]) {
    sort($codes);
    $got = $actual[$line] ?? [];
    sort($got);
    if ($got !== $codes) {
        throw new RuntimeException(
            $mode.' '.$label.': expected '.json_encode($codes).', got '.json_encode($got).'; inspect '.$workspace,
        );
    }
    unset($actual[$line]);
}
if (
    $actual !== []
    || ! in_array($exit, [0, 1], true)
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/stderr.log'),
    )
) {
    throw new RuntimeException('Unexpected worker result; inspect '.$workspace);
}
echo 'PASS: '.$mode.' validation database references ('.count($cases)." cases)\n";
