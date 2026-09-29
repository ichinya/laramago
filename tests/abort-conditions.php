<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago abort conditions '.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Foundation';
mkdir($framework, 0777, true);
$helpers = <<<'PHP'
    <?php
    function abort($code, $message = '', array $headers = []): never { throw new RuntimeException; }
    function abort_if($boolean, $code, $message = '', array $headers = []): void
    {
        if ($boolean) { abort($code, $message, $headers); }
    }
    function abort_unless($boolean, $code, $message = '', array $headers = []): void
    {
        if (! $boolean) { abort($code, $message, $headers); }
    }
    PHP;
$cases = [
    'unless instance' => 'function instance(?stdClass $value): stdClass { abort_unless($value instanceof stdClass, 403); return $value; }',
    'if null' => 'function nullable(?stdClass $value): stdClass { abort_if($value === null, 403); return $value; }',
    'compound condition' => 'function compound(mixed $value): string { abort_if($value === null || !is_string($value), 403); return $value; }',
    'named reversed arguments' => 'function named(?stdClass $value): stdClass { abort_unless(code: 403, boolean: $value instanceof stdClass); return $value; }',
    'wrong condition' => 'function wrong(?stdClass $value): stdClass { abort_if($value !== null, 403); return $value; }',
    'other variable' => 'function other(?stdClass $value, ?stdClass $other): stdClass { abort_if($other === null, 403); return $value; }',
    'missing code' => 'function missing(?stdClass $value): stdClass { abort_if($value === null); return $value; }',
    'unknown argument' => 'function unknown(?stdClass $value): void { abort_if($value === null, 403, unexpected: 1); }',
];
file_put_contents($workspace.'/cases.php', "<?php\n".implode("\n", $cases)."\n");
foreach (['enabled', 'disabled', 'changed-body', 'returning-abort', 'custom-helper'] as $mode) {
    $contents = match ($mode) {
        'changed-body' => str_replace('abort($code, $message, $headers);', 'return;', $helpers),
        'returning-abort' => str_replace(': never { throw new RuntimeException; }', ': void {}', $helpers),
        default => $helpers,
    };
    $helperPath = $mode === 'custom-helper' ? $workspace.'/helpers.php' : $framework.'/helpers.php';
    file_put_contents($helperPath, $contents);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => [$helperPath]],
        'extension-hosts' => $mode === 'disabled' ? new stdClass : [
            'laramago' => [
                'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => 1,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
        2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request|hook .* failed|parse error/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    $codes = [];
    foreach (json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'] as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'cases.php') {
                $codes[$annotation['span']['start']['line'] - 1][] = $issue['code'];
                break;
            }
        }
    }
    foreach (range(0, 3) as $line) {
        if ((($codes[$line] ?? []) === []) !== ($mode === 'enabled')) {
            throw new RuntimeException($mode.' wrong condition contract at '.$line.': '.json_encode($codes).' '.$workspace);
        }
    }
    foreach (range(4, 7) as $line) {
        if (($codes[$line] ?? []) === []) {
            throw new RuntimeException($mode.' lost negative case '.$line.': '.json_encode($codes).' '.$workspace);
        }
    }
    echo 'PASS: abort conditions '.$mode.' ('.count($cases).' cases)' . "\n";
}
