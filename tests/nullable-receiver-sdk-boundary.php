<?php

declare(strict_types=1);

// Keep the nullable-receiver error and prove where the subsequent mixed type originates.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago nullable receiver '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/framework.php', <<<'PHP'
    <?php
    class ProbeDate {
        public static function make(): ?static {}
        public function start(): static {}
    }
    PHP);
$source = <<<'PHP'
    <?php
    function unguarded(): ProbeDate { return ProbeDate::make()->start(); }
    function guarded(): ProbeDate { $date = ProbeDate::make(); if ($date === null) { throw new RuntimeException; } return $date->start(); }
    function known(ProbeDate $date): ProbeDate { return $date->start(); }
    function nullsafe(): ?ProbeDate { return ProbeDate::make()?->start(); }
    PHP;
file_put_contents($workspace.'/cases.php', $source);
copy(__DIR__.'/fixtures/analysis/nullable-receiver-sdk-worker.php.stub', $workspace.'/worker.php');
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach ([false, true] as $enabled) {
    $mode = $enabled ? 'provider' : 'native';
    $config = [
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php']],
        'extension-hosts' => $enabled
            ? [
                'probe' => [
                    'command' => [
                        PHP_BINARY,
                        $workspace.'/worker.php',
                        getenv('MAGO_SDK_AUTOLOAD') ?: $package.'/vendor/autoload.php',
                        $workspace.'/audit.jsonl',
                    ],
                    'workers' => 1,
                ],
            ] : new stdClass,
    ];
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
            2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Expected native diagnostics without worker failure; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $codes = [];
    foreach ($report['issues'] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        if ($primary['span']['start']['line'] !== 1) {
            throw new RuntimeException('Guarded, known and nullsafe receivers must remain precise; inspect '
            .$workspace);
        }
        $codes[] = $issue['code'];
    }
    sort($codes);
    if ($codes !== ['mixed-return-statement', 'possible-method-access-on-null']) {
        throw new RuntimeException('Nullable receiver behavior changed: re-evaluate the SDK boundary; inspect '
        .$workspace);
    }
    echo 'PASS: '.$mode.' nullable call stays mixed; guarded, known and nullsafe calls retain their types'."\n";
}
$records = array_map(
    static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
    file($workspace.'/audit.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
);
$unguarded = strpos($source, 'ProbeDate::make()->start()');
$matched = false;
foreach ($records as $record) {
    if ($record['start'] === $unguarded && $record['receiver'] === 'ProbeDate' && $record['return'] === 'ProbeDate') {
        $matched = true;
    }
}
if (! $matched) {
    throw new RuntimeException('The return provider must be called for the unguarded expression; inspect '.$workspace);
}
echo 'PASS: the provider supplies a concrete return type before the engine combines the nullable branch'."\n";
$resolvedWorkspace = realpath($workspace);
foreach (glob($workspace.'/*') ?: [] as $file) {
    $resolvedFile = realpath($file);
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    unlink($resolvedFile);
}
rmdir($workspace);
