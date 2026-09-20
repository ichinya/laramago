<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$mode = $argv[1] ?? '';
$standalone = in_array($mode, ['standalone', 'standalone-changed'], true);
if ($mode !== '' && ! $standalone) {
    throw new RuntimeException('Unknown fixture mode.');
}
$workspace =
    str_replace('\\', '/', sys_get_temp_dir()).'/laramago-rule-parameter-diagnostics-'.bin2hex(random_bytes(8));
$source = __DIR__.'/fixtures/analysis';
$vendor = $standalone ? 'deps' : 'vendor';
$validation =
    $workspace
    .'/'
    .$vendor
    .'/'
    .(
        $standalone
            ? 'illuminate/validation'
            : 'laravel/framework/src/Illuminate/Validation'
    );
mkdir($validation.'/Concerns', 0777, true);
foreach (['Factory.php', 'Validator.php', 'ValidationRuleParser.php', 'Concerns/ValidatesAttributes.php'] as $file) {
    copy($source.'/native-validation-'.basename($file).'.stub', $validation.'/'.$file);
}
if ($mode === 'standalone-changed') {
    $factory = $validation.'/Factory.php';
    $contents = file_get_contents($factory);
    $before = '$validator->excludeUnvalidatedArrayKeys = $this->excludeUnvalidatedArrayKeys;';
    if (substr_count($contents, $before) !== 1) {
        throw new RuntimeException('Cannot construct changed standalone validation source.');
    }
    file_put_contents($factory, str_replace($before, '$validator->excludeUnvalidatedArrayKeys = false;', $contents));
}
file_put_contents($workspace.'/composer.json', json_encode([
    'config' => ['vendor-dir' => $vendor],
    'extra' => ['laramago' => ['validation-rule-parameters' => ['native' => true]]],
], JSON_THROW_ON_ERROR));
$cases = <<<'PHP'
    <?php
    namespace App;
    class CustomFactory { public function make(array $data, array $rules): void {} }
    function cases(\Illuminate\Validation\Factory $factory, \Illuminate\Validation\Validator $validator, CustomFactory $custom): void {
        $factory->make([], ['a' => 'required|min']);
        $factory->make([], ['b' => 'between:1|required_if:status']);
        $factory->make([], ['c' => 'in|size:2']);
        $factory->make([], ['d' => ['between:"1,2"', 'required_if:status,ready']]);
        $factory->make([], ['e' => 'regex:/a|b/|min']);
        $factory->validate([], ['g' => 'max']);
        $validator->sometimes('h', 'between:1', static fn (): bool => true);
        $factory->make([], ['pipeline' => 'between:1|unsupported rule:2,3']);
        $factory->make([], ['individual' => ['between:1|unsupported rule:2,3']]);
        $custom->make([], ['f' => 'min']);
    }
    PHP;
file_put_contents($workspace.'/cases.php', $cases);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            $vendor
                .'/'
                .($standalone ? 'illuminate/validation' : 'laravel/framework/src/Illuminate/Validation')
                .'/Factory.php',
            $vendor
                .'/'
                .($standalone ? 'illuminate/validation' : 'laravel/framework/src/Illuminate/Validation')
                .'/Validator.php',
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
$analyze = static function () use ($command, $workspace): array {
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

    return [$exit, json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)];
};
[$exit, $report] = $analyze();
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    if (($issue['code'] ?? null) !== 'ichinya/laramago/laramago-validation-rule-parameters') {
        continue;
    }
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $item): bool => $item['kind'] === 'Primary',
    ))[0];
    $actual[] = substr(
        $cases,
        $primary['span']['start']['offset'],
        $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
    );
}
sort($actual);
// PHP source uses single-quoted rule literals; the spans retain those quotes.
$expected = [
    "'between:1|required_if:status'",
    "'between:1|required_if:status'",
    "'between:\"1,2\"'",
    "'required|min'",
    "'max'",
    "'between:1'",
    "'between:1|unsupported rule:2,3'",
];
if ($mode === 'standalone-changed') {
    $expected = ["'between:1'"];
}
sort($expected);
if ($actual !== $expected || ! in_array($exit, [0, 1], true)) {
    throw new RuntimeException('Parameter diagnostics mismatch: '.json_encode($actual).'; inspect '.$workspace);
}
if (preg_match(
    '/External analyzer provider failed|extension worker .*rejected request/i',
    file_get_contents($workspace.'/stderr.log'),
)) {
    throw new RuntimeException('Worker failure; inspect '.$workspace);
}
echo "PASS: real Mago native parameter diagnostics, parser quoting, safe unknowns ($mode)\n";
file_put_contents($workspace.'/composer.json', '{}');
[$exit, $report] = $analyze();
foreach ($report['issues'] ?? [] as $issue) {
    if (($issue['code'] ?? null) === 'ichinya/laramago/laramago-validation-rule-parameters') {
        throw new RuntimeException('Opt-out emitted parameter diagnostics; inspect '.$workspace);
    }
}
if (! in_array($exit, [0, 1], true)) {
    throw new RuntimeException('Opt-out Mago process failed; inspect '.$workspace);
}
echo "PASS: explicit opt-in required\n";
