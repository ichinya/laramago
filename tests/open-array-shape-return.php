<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace(DIRECTORY_SEPARATOR, '/', realpath(dirname(__DIR__)));
$workspace = str_replace(DIRECTORY_SEPARATOR, '/', sys_get_temp_dir()).'/laramago open return '.bin2hex(random_bytes(8));
mkdir($workspace, 0777, true);

$cases = [
    'required integer present with unknown extra keys' => [
        '/** @param array<string, mixed> $input @return array{a: int} */',
        "function openCorrect(array \$input): array\n{\n    \$input['a'] = 1;\n    return \$input;\n}",
        false,
    ],
    'all required fields present' => [
        '/** @param array<string, mixed> $input @return array{a: int, b: string} */',
        "function openTwoKeys(array \$input): array\n{\n    \$input['a'] = 1;\n    \$input['b'] = 'ok';\n    return \$input;\n}",
        false,
    ],
    'nested array field has the declared value type' => [
        "/** @param array<string, mixed> \$input\n * @param array<string, int> \$connections\n * @return array{a: array<string, int>} */",
        "function openNested(array \$input, array \$connections): array\n{\n    \$input['a'] = \$connections;\n    return \$input;\n}",
        false,
    ],
    'method return' => [
        '',
        "class OpenMethod\n{\n    /** @param array<string, mixed> \$input\n     * @return array{a: int} */\n    public function build(array \$input): array\n    {\n        \$input['a'] = 1;\n        return \$input;\n    }\n}",
        false,
    ],
    'wrong field type' => [
        '/** @param array<string, mixed> $input @return array{a: int} */',
        "function openWrongValue(array \$input): array\n{\n    \$input['a'] = 'wrong';\n    return \$input;\n}",
        true,
    ],
    'missing field' => [
        '/** @param array<string, mixed> $input @return array{a: int} */',
        "function openMissing(array \$input): array\n{\n    return \$input;\n}",
        true,
    ],
    'optional field' => [
        '/** @param array<string, mixed> $input @return array{a: int} */',
        "function openOptional(array \$input): array\n{\n    if (random_int(0, 1)) {\n        \$input['a'] = 1;\n    }\n    return \$input;\n}",
        true,
    ],
    'nested field mismatch' => [
        '/** @param array<string, mixed> $input @return array{a: array<string, int>} */',
        "function openWrongNested(array \$input): array\n{\n    \$input['a'] = ['x' => 'wrong'];\n    return \$input;\n}",
        true,
    ],
    'closed wrong value' => [
        '/** @return array{a: int} */',
        "function closedWrongValue(): array\n{\n    return ['a' => 'wrong'];\n}",
        true,
    ],
];

$source = "<?php\n";
$expected = [];
foreach ($cases as $name => [$doc, $code, $invalid]) {
    $doc = str_replace(' @return ', "\n * @return ", $doc);
    $caseStart = substr_count($source, "\n") + substr_count($doc, "\n") + 2;
    $returnOffset = strrpos($code, 'return ');
    if ($returnOffset === false) {
        throw new RuntimeException('Missing return in '.$name);
    }
    $expected[$caseStart + substr_count(substr($code, 0, $returnOffset), "\n")] = [$name, $invalid];
    $source .= $doc."\n".$code."\n";
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php']],
    'extension-hosts' => ['laramago' => ['command' => [
        PHP_BINARY,
        $package.'/bin/laramago-worker.php',
        $package.'/vendor/autoload.php',
        $workspace,
    ], 'workers' => 2]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
$log = file_get_contents($workspace.'/stderr.log');
if (! in_array($exit, [0, 1], true) || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Unexpected Mago failure; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    if ($issue['code'] !== 'invalid-return-statement') {
        continue;
    }
    $primary = array_values(array_filter($issue['annotations'], static fn (array $a): bool => $a['kind'] === 'Primary'))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue;
}
foreach ($expected as $line => [$name, $invalid]) {
    $count = count($actual[$line] ?? []);
    if ($count !== (int) $invalid) {
        throw new RuntimeException($name.': expected '.(int) $invalid.' invalid return issues, got '.$count.'; inspect '.$workspace);
    }
    unset($actual[$line]);
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected invalid return issues; inspect '.$workspace);
}

echo 'Open array shape return compatibility: '.count($cases).' cases passed.'.PHP_EOL;
