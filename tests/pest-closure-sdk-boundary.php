<?php

declare(strict_types=1);

// This fixture executes Mago only; neither Pest nor analyzed PHP is executed.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-pest-sdk-'.bin2hex(random_bytes(8));
mkdir($workspace);
$assert = static function (bool $condition, string $message) use ($workspace): void {
    if (! $condition) {
        throw new RuntimeException($message.'; inspect '.$workspace);
    }
    echo 'PASS: '.$message."\n";
};
$run = static function (array $paths, bool $probe) use ($package, $workspace, $assert): array {
    $config = [
        'php-version' => '8.2',
        'source' => ['paths' => $paths, 'includes' => ['framework.php']],
    ];
    if ($probe) {
        $config['extension-hosts'] = [
            'probe' => [
                'command' => [
                    PHP_BINARY,
                    '-d',
                    'opcache.enable_cli=0',
                    $package.'/tests/fixtures/analysis/pest-closure-sdk-worker.php.stub',
                    getenv('MAGO_SDK_AUTOLOAD') ?: $package.'/vendor/autoload.php',
                    $workspace.'/audit.jsonl',
                ],
                'workers' => 1,
            ],
        ];
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
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
    $assert(
        in_array($exit, [0, 1], true)
        && ! preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($workspace.'/stderr.log'),
        ),
        'Mago completed without provider failure',
    );

    return json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
file_put_contents($workspace.'/framework.php', <<<'PHP'
    <?php
    function it(string $description, Closure $test): void {}
    class AlphaCase { public function alpha(): void {} }
    class BetaCase { public function beta(): void {} }
    /** @param-closure-this AlphaCase $test */
    function alpha_it(string $description, Closure $test): void {}
    /** @param-closure-this BetaCase $test */
    function beta_it(string $description, Closure $test): void {}
    PHP);
$identical = "<?php\nit('same', function (): void { \$this->shared(); });\n";
foreach (['AlphaTest.php', 'BetaTest.php'] as $file) {
    file_put_contents($workspace.'/'.$file, $identical);
}
$issues = $run(['AlphaTest.php', 'BetaTest.php'], true);
$codes = array_count_values(array_column($issues, 'code'));
$assert(
    ($codes['undefined-variable'] ?? 0) === 2 && ($codes['mixed-method-access'] ?? 0) === 2,
    'Both identical files retain native untyped-this diagnostics',
);
$records = array_map(static fn (string $line): array => json_decode(
    $line,
    true,
    flags: JSON_THROW_ON_ERROR,
), array_values(array_filter(explode("\n", file_get_contents($workspace.'/audit.jsonl')))));
$assert(
    count($records) === 2 && $records[0] === $records[1],
    'Pre-argument provider cannot distinguish identical source in separate files',
);
$assert(
    $records[0]['contextFields'] === ['phpVersion', 'codebase', 'invocation', 'types', 'cancellation']
    && $records[0]['invocationFields'] === ['name', 'declaringClass', 'kind', 'receiverType', 'span', 'arguments'],
    'SDK field changes require re-evaluating the source-identity boundary',
);
$assert($records[0]['arguments'][1]['type'] === null, 'Closure context is requested before argument typing');
file_put_contents($workspace.'/TypedTest.php', <<<'PHP'
    <?php
    alpha_it('alpha', function (): void { $this->alpha(); });
    beta_it('beta', function (): void { $this->beta(); });
    PHP);
$assert($run(['TypedTest.php'], false) === [], 'Explicit wrappers independently type two closure contexts');
file_put_contents($workspace.'/TypedTest.php', <<<'PHP'
    <?php
    alpha_it('wrong', function (): void { $this->beta(); });
    beta_it('wrong', function (): void { $this->alpha(); });
    alpha_it('static', static function (): void { $this->alpha(); });
    PHP);
$issues = $run(['TypedTest.php'], false);
$codes = array_count_values(array_column($issues, 'code'));
$assert(($codes['non-existent-method'] ?? 0) === 2, 'Explicit context does not leak methods between test cases');
$assert(
    str_contains(json_encode($issues, JSON_THROW_ON_ERROR), 'Cannot use `$this` in a static closure'),
    'Static closures retain native this restrictions',
);

foreach ([
    'framework.php',
    'AlphaTest.php',
    'BetaTest.php',
    'TypedTest.php',
    'mago.json',
    'report.json',
    'stderr.log',
    'audit.jsonl',
] as $file) {
    unlink($workspace.'/'.$file);
}
rmdir($workspace);
