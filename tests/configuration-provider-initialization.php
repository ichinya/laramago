<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$control = proc_open([PHP_BINARY, $package.'/tests/fixtures/configuration-provider-initialization/worker.php', $package.'/vendor/autoload.php', $package, 'unused', 'control'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $controlPipes);
if (! is_resource($control)) {
    throw new RuntimeException('Cannot start coordination controls.');
}
fclose($controlPipes[0]);
if (proc_close($control) !== 0) {
    throw new RuntimeException('Configuration coordination controls failed.');
}
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago configuration initialization '.bin2hex(random_bytes(8));
mkdir($workspace.'/app', recursive: true);
mkdir($workspace.'/config');
mkdir($workspace.'/vendor/laravel/framework/src/Illuminate/Foundation', recursive: true);
file_put_contents($workspace.'/vendor/laravel/framework/src/Illuminate/Foundation/helpers.php', '<?php function config(?string $key = null, mixed $default = null): mixed { return $default; }');
file_put_contents($workspace.'/config/provider.php', '<?php return ["answer" => 42];');
for ($case = 0; $case < 24; ++$case) {
    file_put_contents($workspace.'/app/read'.$case.'.php', '<?php function configurationRead'.$case.'(): int { return config("provider.answer"); }');
}
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$config = ['php-version' => '8.5', 'source' => ['paths' => ['app'], 'includes' => ['vendor']]];
$run = static function (string $stage, ?int $workers) use ($package, $workspace, $command, $config): array {
    if ($workers !== null) {
        $config['extension-hosts'] = ['configuration' => [
            'command' => [PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'opcache.enable_cli=0', $package.'/tests/fixtures/configuration-provider-initialization/worker.php', $package.'/vendor/autoload.php', $workspace, $workspace.'/'.$stage.'-observer.jsonl'],
            'workers' => $workers,
            'request-timeout-ms' => 120000,
        ]];
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$stage.'.json', 'w'], 2 => ['file', $workspace.'/'.$stage.'.stderr', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start configuration initialization fixture.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$stage.'.stderr');
    if (! in_array($exit, [0, 1], true) || preg_match('/fallback|failed|fatal|timeout|timed out|invalid.*frame|PHP Warning/i', $stderr)) {
        throw new RuntimeException('Native initialization fixture failed; retained '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/'.$stage.'.json'), true, flags: JSON_THROW_ON_ERROR);

    return [$exit, $report['issues']];
};
[$nativeExit, $native] = $run('native', null);
if ($nativeExit !== 1 || count($native) !== 24) {
    throw new RuntimeException('Twenty-four genuine native return errors required; retained '.$workspace);
}
foreach ([1, 3] as $workers) {
    $stage = 'enabled-'.$workers;
    [$exit, $issues] = $run($stage, $workers);
    if ($exit !== 0 || $issues !== []) {
        throw new RuntimeException('Native config refinement failed; retained '.$workspace);
    }
    $records = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($workspace.'/'.$stage.'-observer.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $ready = [];
    $callbacks = 0;
    $fallbacks = 0;
    foreach ($records as $record) {
        if ($record['stage'] === 'ready') {
            if (isset($ready[$record['pid']])) {
                throw new RuntimeException('Worker prepared the write index twice.');
            }
            $ready[$record['pid']] = true;
            if ($record['origin'] === 'fallback') {
                ++$fallbacks;
            }
        } else {
            if (! isset($ready[$record['pid']]) || ! $record['refined']) {
                throw new RuntimeException('Refinement ran before successful preparation.');
            }
            ++$callbacks;
        }
    }
    if ($ready === [] || $callbacks < 24) {
        throw new RuntimeException('Vacuous lifecycle observation; retained '.$workspace);
    }
    if ($workers === 3 && ($fallbacks === 0 || count($ready) < 2)) {
        throw new RuntimeException('Three-worker run did not exercise a worker without the lifecycle hook.');
    }
}
echo 'PASS: native preparation precedes 24 concurrent refinements, stable index identity, one/three workers and unchanged 512M/120000ms limits; '.$workspace.PHP_EOL;
