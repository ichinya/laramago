<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago json decode '.bin2hex(random_bytes(8));
mkdir($workspace, 0777, true);
$cases = [
    'throw-named-flags' => '$a = json_decode($input, true, flags: JSON_THROW_ON_ERROR);',
    'throw-positional-depth' => '$b = json_decode($input, true, 16, JSON_THROW_ON_ERROR);',
    'named-assoc' => '$c = json_decode($input, assoc: true);',
    'assoc-true' => '$d = json_decode($input, true);',
    'array-narrowing' => 'if (is_array($d)) { $e = $d["k"]; }',
    'object-impossible' => 'if (is_object($d)) { $f = $d; }',
    'no-assoc' => '$g = json_decode($input);',
    'assoc-false' => '$h = json_decode($input, false);',
    'named-assoc-false' => '$i = json_decode($input, assoc: false);',
    'dynamic-assoc' => '$j = json_decode($input, $assoc);',
    'truthy-int-assoc' => '$k = json_decode($input, 1);',
    'null-assoc' => '$l = json_decode($input, null);',
    'wrapper-deferral' => '$m = decode_json($input);',
];
$prefix = <<<'PHP'
    <?php
    $input = (string) ($_GET['payload'] ?? '');
    $assoc = (bool) ($_GET['assoc'] ?? false);
    function decode_json(string $payload): mixed { return json_decode($payload, true); }
    PHP;
$source = $prefix."\n".implode("\n", array_values($cases))."\n";
file_put_contents($workspace.'/cases.php', $source);
$lines = [];
$line = substr_count($prefix, "\n") + 2;
foreach ($cases as $name => $_) {
    $lines[$name] = $line++;
}

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach (['json' => false, 'json-ordinary' => true, 'ordinary' => null] as $mode => $ordinary) {
    $jsonRegistration = $ordinary === null ? '' : '$registry->registerFunctionReturnTypeProvider(new Ichinya\\Laramago\\Analyzer\\JsonDecodeProvider);';
    $plugins = $ordinary !== false ? ', new Ichinya\\Laramago\\Analyzer\\OrdinaryMixedAssignmentPlugin' : '';
    file_put_contents($workspace.'/'.$mode.'-worker.php', '<?php require '.var_export($package.'/vendor/autoload.php', true).';'
        .'$json = new class implements Mago\\Sdk\\Analyzer\\Plugin {'
        .'public function getDefinition(): Mago\\Sdk\\Analyzer\\PluginDefinition { return new Mago\\Sdk\\Analyzer\\PluginDefinition("json-control", "JSON control", "Isolated provider and policy controls."); }'
        .'public function register(Mago\\Sdk\\Analyzer\\PluginRegistry $registry): void { '.$jsonRegistration.' }};'
        .'(new Mago\\Sdk\\Worker(new Mago\\Sdk\\Extension(identifier:"json-control", name:"JSON control", version:"1", analyzerPlugins:[$json'.$plugins.'])))->run();');
}
$run = static function (string $label, ?string $worker = null, int $workers = 1) use ($workspace, $package, $command): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.5',
        'source' => ['paths' => ['cases.php']],
        'extension-hosts' => $worker === null ? new stdClass : [
            'laramago' => [
                'command' => [PHP_BINARY, $worker, $package.'/vendor/autoload.php', $workspace],
                'workers' => $workers,
                'request-timeout-ms' => 120000,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/'.$label.'.json', 'w'],
        2 => ['file', $workspace.'/'.$label.'.stderr', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    file_put_contents($workspace.'/'.$label.'.process.json', json_encode(['exitCode' => $exit, 'childClosed' => true], JSON_THROW_ON_ERROR));
    $stderr = file_get_contents($workspace.'/'.$label.'.stderr');
    if (! in_array($exit, [0, 1], true) || preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|invalid[^\r\n]*frame|hook[^\r\n]*failed|fatal|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i', $stderr)) {
        throw new RuntimeException('Provider failure: '.$stderr);
    }
    return json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$summarize = static function (array $issues): array {
    $result = [];
    foreach ($issues as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
        if ($primary['span']['file_id']['name'] === 'cases.php') {
            $result[$primary['span']['start']['line'] + 1][] = $issue['code'];
        }
    }
    foreach ($result as &$codes) {
        sort($codes);
    }
    return $result;
};
$nativeIssues = $run('native');
$isolatedIssues = $run('isolated', $workspace.'/json-worker.php');
$ordinaryIssues = $run('ordinary', $workspace.'/ordinary-worker.php');
$combinedIssues = $run('combined', $workspace.'/json-ordinary-worker.php');
$native = $summarize($nativeIssues);
$adapted = $summarize($isolatedIssues);
foreach ($lines as $name => $line) {
    $actual = $adapted[$line] ?? [];
    $baseline = $native[$line] ?? [];
    if (in_array($name, ['throw-named-flags', 'throw-positional-depth', 'named-assoc', 'assoc-true'], true)) {
        if (! in_array('mixed-assignment', $baseline, true) || in_array('mixed-assignment', $actual, true) || array_diff($actual, $baseline) !== []) {
            throw new RuntimeException($name.' must lose its mixed-assignment without new diagnostics; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif ($name === 'array-narrowing') {
        if (array_diff($actual, $baseline) !== []) {
            throw new RuntimeException('Array narrowing on the assoc=true union must not introduce new diagnostics; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif ($name === 'object-impossible') {
        $introduced = array_values(array_diff($actual, $baseline));
        sort($introduced);
        if ($introduced !== ['impossible-assignment', 'impossible-condition', 'impossible-type-comparison']) {
            throw new RuntimeException('is_object on the assoc=true union must become a definitely-impossible condition; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif ($actual !== $baseline) {
        throw new RuntimeException($name.' must retain native diagnostics; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
    }
    echo 'PASS: '.$name."\n";
}

$signatures = static function (array $issues): array {
    $values = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues);
    sort($values);
    return $values;
};
$errors = static fn (array $issues): array => array_values(array_filter($issues, static fn (array $issue): bool => $issue['level'] === 'Error'));
if ($signatures($errors($nativeIssues)) !== $signatures($errors($ordinaryIssues))
    || $signatures($errors($isolatedIssues)) !== $signatures($errors($combinedIssues))) {
    throw new RuntimeException('Ordinary assignment policy changed native Errors; inspect '.$workspace);
}
foreach ([1, 3] as $workers) {
    $integrated = $run('integrated'.$workers, $package.'/bin/laramago-worker.php', $workers);
    if ($signatures($integrated) !== $signatures($combinedIssues)) {
        throw new RuntimeException('Integrated JSON diagnostics differ from the independently measured provider and Ordinary policy; inspect '.$workspace);
    }
}
echo "PASS: isolated JSON deferrals, independent Ordinary policy, and complete one/three-worker integrated records\n";

$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($resolved);
