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
$run = static function (bool $disabled) use ($workspace, $package, $command): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.5',
        'source' => ['paths' => ['cases.php']],
        'extension-hosts' => $disabled ? new stdClass : [
            'laramago' => [
                'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => 1,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/report.json', 'w'],
        2 => ['file', $workspace.'/stderr.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Mago.');
    }
    fclose($pipes[0]);
    proc_close($process);
    $stderr = file_get_contents($workspace.'/stderr.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)) {
        throw new RuntimeException('Provider failure: '.$stderr);
    }
    return json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
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
$native = $summarize($run(true));
$adapted = $summarize($run(false));
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
