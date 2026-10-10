<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$evidence = $package.'/var/warnings-20261009/native-round2/configuration-'.bin2hex(random_bytes(4));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
mkdir($evidence, 0777, true);
$modes = [
    'unchanged' => ['', ['invalid-return-statement'], []],
    'helper-leaf' => ["config(['example.driver' => 'array', 'example.hosts' => false]);", ['mixed-return-statement'], ['mixed-return-statement']],
    'helper-ancestor' => ["config(['example' => ['driver' => 'array']]);", ['mixed-return-statement'], ['mixed-return-statement']],
    'facade-alias-named' => ["use Illuminate\\Support\\Facades\\Config as Settings; Settings::set(value: false, key: 'example.hosts'); Settings::set('example.driver', 'array');", ['mixed-return-statement'], ['mixed-return-statement']],
    'facade-map' => ["\\Illuminate\\Support\\Facades\\Config::set(['example.driver' => 9, 'example.hosts' => false]);", ['mixed-return-statement'], ['mixed-return-statement']],
    'repository-direct' => ["config()->set('example.driver', 'array'); config()->set('example.hosts', false);", ['mixed-return-statement'], ['mixed-return-statement']],
    'descendant' => ["config(['example.driver.child' => false, 'example.hosts.0' => 8]);", ['mixed-return-statement'], ['mixed-return-statement']],
    'sibling' => ["config(['example.other' => 'array']);", ['invalid-return-statement'], []],
    'unknown-facade' => ["\\Illuminate\\Support\\Facades\\Config::set((string) random_int(1, 9), false);", ['mixed-return-statement'], ['mixed-return-statement']],
    'unpacked-map' => ["config([...unknownOverrides()]);", ['mixed-return-statement'], ['mixed-return-statement']],
    'escaped-repository' => ["\$repository = config(); \$repository->set('example.hosts', false);", ['mixed-return-statement'], ['mixed-return-statement']],
    'captured-writer' => ["\$writer = \\Illuminate\\Support\\Facades\\Config::set(...);", ['mixed-return-statement'], ['mixed-return-statement']],
    'unpacked-arguments' => ['config(...unknownOverrides());', ['mixed-return-statement'], ['mixed-return-statement']],
    'dynamic-suffix' => ["config(['example.'.unknownSuffix() => false]);", ['mixed-return-statement'], ['mixed-return-statement']],
    'string-read' => ["config('other.'.unknownSuffix()); config((string) unknownSuffix());", ['invalid-return-statement'], []],
    'app-repository' => ["app('config')->set('example.driver', 'array'); resolve('config')->set('example.hosts', false);", ['mixed-return-statement'], ['mixed-return-statement']],
];
foreach ($modes as $mode => [$writer, $driverCodes, $hostsCodes]) {
    $workspace = $evidence.'/'.$mode;
    $framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
    mkdir($framework.'/Foundation', 0777, true);
    mkdir($framework.'/Support/Facades', 0777, true);
    mkdir($framework.'/Config', 0777, true);
    mkdir($workspace.'/config');
    mkdir($workspace.'/tests');
    file_put_contents($workspace.'/composer.json', '{}');
    file_put_contents($workspace.'/config/example.php', "<?php return ['driver' => 'redis', 'hosts' => ['localhost'], 'stable' => 4];");
    file_put_contents($framework.'/Foundation/helpers.php', '<?php function config(mixed $key = null, mixed $default = null): mixed { throw new RuntimeException("Never execute helpers."); }');
    foreach (['Support/Facades/Facade' => 'configuration-facade-base', 'Support/Facades/Config' => 'configuration-facade', 'Config/Repository' => 'configuration-repository'] as $target => $fixture) {
        copy(__DIR__.'/fixtures/analysis/'.$fixture.'.php.stub', $framework.'/'.$target.'.php');
    }
    // The closure-only writer is deliberately not part of native source paths.
    file_put_contents($workspace.'/tests/runtime.php', '<?php '.$writer);
    $cases = [
        'driver unchecked' => ["return config('example.driver');", 'int', $driverCodes],
        'hosts unchecked' => ["return config('example.hosts');", 'array', $hostsCodes],
        'driver guard' => ["\$value = config('example.driver'); if (!is_string(\$value)) { throw new RuntimeException; } return \$value;", 'string', $driverCodes === ['mixed-return-statement'] ? [] : ['impossible-condition', 'redundant-type-comparison']],
        'hosts guard' => ["\$value = config('example.hosts'); if (!is_array(\$value)) { throw new RuntimeException; } return \$value;", 'array', $hostsCodes === ['mixed-return-statement'] ? [] : ['impossible-condition', 'redundant-type-comparison']],
        'facade unchecked' => ["return \\Illuminate\\Support\\Facades\\Config::get('example.driver');", 'int', $driverCodes],
        'stable mismatch' => ["return config('example.stable');", 'string', in_array($mode, ['helper-ancestor', 'unknown-facade', 'unpacked-map', 'escaped-repository', 'captured-writer', 'unpacked-arguments', 'dynamic-suffix'], true) ? ['mixed-return-statement'] : ['invalid-return-statement']],
        'ordinary mixed error' => ['return $foreign;', 'int', ['mixed-return-statement']],
    ];
    $source = "<?php\n";
    $lines = [];
    foreach ($cases as $name => [$body, $type, $expected]) {
        $source .= 'function scenario'.count($lines).'(mixed $foreign): '.$type.' { '.$body." }\n";
        $lines[substr_count($source, "\n")] = [$name, $expected];
    }
    file_put_contents($workspace.'/cases.php', $source);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['vendor/laravel/framework/src/Illuminate']],
        'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace], 'workers' => 1]],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start analyzer.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/stderr.log');
    if ($exit !== 1 || preg_match('/provider failed|rejected request|protocol error|panic|PHP Fatal|PHP Warning/i', $stderr)) {
        throw new RuntimeException('Operational failure: '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $actual = [];
    foreach ($issues as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn(array $a): bool => $a['kind'] === 'Primary'))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes); sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException($mode.' / '.$name.': expected '.json_encode($expected).', got '.json_encode($codes).'; '.$workspace);
        }
        unset($actual[$line]);
        echo 'PASS '.$mode.' / '.$name."\n";
    }
    if ($actual !== []) { throw new RuntimeException('Unexpected records: '.$workspace); }
}
echo 'Evidence: '.$evidence."\n";
