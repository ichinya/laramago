<?php

declare(strict_types=1);

// Analyze native Laravel declarations with the real Mago worker; do not execute application PHP.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago rule names '.bin2hex(random_bytes(8));
// Laravel framework 7c75fbf; see fixtures/analysis/native-validation-LICENSE.md.
$frameworkSource = __DIR__.'/fixtures/analysis';
mkdir($workspace);
$validation = $workspace.'/vendor/laravel/framework/src/Illuminate/Validation';
mkdir($validation.'/Concerns', 0777, true);
foreach (['Factory.php', 'Validator.php', 'ValidationRuleParser.php', 'Concerns/ValidatesAttributes.php'] as $file) {
    copy($frameworkSource.'/native-validation-'.basename($file).'.stub', $validation.'/'.$file);
}

$mode = $argv[1] ?? 'complete';
if (! in_array($mode, ['complete', 'incomplete', 'malformed', 'changed-body', 'changed-default', 'variadic'], true)) {
    throw new RuntimeException('Unknown mode.');
}
$factoryPath = $validation.'/Factory.php';
if ($mode === 'changed-body') {
    $before = '$validator->excludeUnvalidatedArrayKeys = $this->excludeUnvalidatedArrayKeys;';
    $after = '$validator->excludeUnvalidatedArrayKeys = false;';
} elseif ($mode === 'changed-default') {
    $before = 'public function make(array $data, array $rules, array $messages = [], array $attributes = [])';
    $after = 'public function make(array $data, array $rules, array $messages = ["changed"], array $attributes = [])';
} elseif ($mode === 'variadic') {
    $before = 'public function make(array $data, array $rules, array $messages = [], array $attributes = [])';
    $after = 'public function make(array $data, array $rules, ...$messages)';
}
if (isset($before, $after)) {
    $original = file_get_contents($factoryPath);
    if (substr_count($original, $before) !== 1) {
        throw new RuntimeException('Cannot construct modified native method fixture.');
    }
    file_put_contents($factoryPath, str_replace($before, $after, $original));
}
$assertion = match ($mode) {
    'complete', 'changed-body', 'changed-default', 'variadic' => [
        'complete' => true,
        'names' => ['required', 'string', 'company_code'],
    ],
    'incomplete' => ['complete' => false, 'names' => ['required', 'string', 'company_code']],
    'malformed' => ['complete' => true, 'names' => ['required', 42]],
};
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['validation-rule-names' => $assertion]],
], JSON_THROW_ON_ERROR));

$cases = [
    'builtin rules' => ['$factory->make([], ["name" => "required|string"]);', []],
    'known custom rule' => ['$factory->make([], ["code" => "company_code"]);', []],
    'missing pipe rule' => ['$factory->make([], ["name" => "required|requird"]);', ['required|requird']],
    'missing array rule' => ['$factory->make([], ["name" => ["string", "strng"]]);', ['strng']],
    'named rules argument' => ['$factory->validate(data: [], rules: ["name" => "strng"]);', ['strng']],
    'validator setRules' => ['$validator->setRules(["name" => "strng"]);', ['strng']],
    'validator addRules' => ['$validator->addRules(["name" => "strng"]);', ['strng']],
    'validator sometimes' => [
        '$validator->sometimes("name", "strng", static fn (): bool => true);',
        ['strng'],
    ],
    'dynamic rule' => ['$factory->make([], ["name" => $rule]);', []],
    'dynamic array item' => ['$factory->make([], ["name" => ["required", $rule]]);', []],
    'shadowed field' => ['$factory->make([], ["name" => "strng", "name" => "string"]);', []],
    'dynamic field key' => ['$factory->make([], ["name" => "strng", $rule => "string"]);', []],
    'unpacked field' => ['$factory->make([], ["name" => "strng", ...$rule]);', []],
    'unpacked rule item' => ['$factory->make([], ["name" => ["strng", ...$rule]]);', []],
    'rule object' => ['$factory->make([], ["name" => [new CustomRule]]);', []],
    'unsupported spelling' => ['$factory->make([], ["name" => "some rule"]);', []],
    'custom receiver' => ['$custom->make([], ["name" => "strng"]);', []],
];
if (in_array($mode, ['changed-body', 'changed-default', 'variadic'], true)) {
    $cases = array_filter(
        $cases,
        static fn (string $label): bool => ! str_starts_with($label, 'validator '),
        ARRAY_FILTER_USE_KEY,
    );
}
$source = "<?php\nnamespace App;\n";
$source .= "class CustomRule {}\n";
$source .= "class CustomFactory { public function make(array \$data, array \$rules): void {} }\n";
$expected = [];
foreach ($cases as $label => [$body, $missing]) {
    $line = substr_count($source, "\n") + 1;
    $source .=
        'function case'
        .count($expected)
        .'(\\Illuminate\\Validation\\Factory $factory, \\Illuminate\\Validation\\Validator $validator, CustomFactory $custom, mixed $rule): void { '
        .$body
        ." }\n";
    $expected[$line] = [$label, $mode === 'complete' ? $missing : []];
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
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
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
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
$native = [];
foreach ($report['issues'] ?? [] as $issue) {
    if ($issue['code'] !== 'ichinya/laramago/laramago-unknown-validation-rule') {
        $native[] = $issue['code'];
        continue;
    }
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $offset = $primary['span']['start']['offset'];
    $length = $primary['span']['end']['offset'] - $offset;
    $actual[$line][] = substr($source, $offset, $length);
}
foreach ($expected as $line => [$label, $missing]) {
    $want = array_map(static fn (string $name): string => '"'.$name.'"', $missing);
    sort($want);
    $got = $actual[$line] ?? [];
    sort($got);
    if ($got !== $want) {
        throw new RuntimeException(
            $mode.' '.$label.': expected '.json_encode($want).', got '.json_encode($got).'; inspect '.$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$mode.' '.$label."\n";
}
if (
    $actual !== []
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/stderr.log'),
    )
) {
    throw new RuntimeException('Unexpected rule diagnostics or worker failure; inspect '.$workspace);
}
sort($native);
if (
    ! in_array($exit, [0, 1], true)
    || $native !== [
        'duplicate-array-key',
        'invalid-array-element',
        'invalid-array-element',
        'invalid-array-element-key',
    ]
) {
    throw new RuntimeException(
        'Unexpected native issues or exit '.$exit.': '.json_encode($native).'; inspect '.$workspace,
    );
}
