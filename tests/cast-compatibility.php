<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago casts '.bin2hex(random_bytes(8));
mkdir($workspace);
$cases = [
    'raw array conversion' => 'function raw(mixed $value): array { return (array) $value; }',
    'null becomes empty array' => 'function emptyArray(): array { return (array) null; }',
    'object becomes array' => 'function objectArray(object $value): array { return (array) $value; }',
    'redundant int cast' => 'function integer(int $value): int { return (int) $value; }',
    'redundant array cast' => 'function arrayValue(array $value): array { return (array) $value; }',
    'invalid string cast' => 'function invalidString(object $value): string { return (string) $value; }',
    'array to string' => 'function arrayString(array $value): string { return (string) $value; }',
    'wrong return' => 'function wrongReturn(mixed $value): int { return (array) $value; }',
    'unsafe array item' => 'function unsafeItem(mixed $value): string { return ((array) $value)["name"]; }',
    'nearby invalid cast' => 'function adjacent(object $value): string { $array = (array) $value; return (string) $value; }',
    'nested invalid cast' => 'function nested(object $value): array { return (array) (string) $value; }',
    'mixed boolean cast' => 'function boolean(mixed $value): bool { return (bool) $value; }',
    'string to float' => 'function floating(string $value): float { return (float) $value; }',
    'string to integer' => 'function integerString(string $value): int { return (int) $value; }',
    'invalid object to float' => 'function objectFloating(object $value): float { return (float) $value; }',
    'nested boolean invalid cast' => 'function nestedBoolean(object $value): bool { return (bool) (string) $value; }',
    'mixed arithmetic' => 'function mixedArithmetic(mixed $value): int { return $value + 1; }',
];
file_put_contents($workspace.'/cases.php', "<?php\n".implode("\n", $cases)."\n");
foreach (['disabled', 'enabled'] as $mode) {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php']],
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
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $codes = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'cases.php') {
                $codes[$annotation['span']['start']['line'] - 1][] = $issue['code'];
                break;
            }
        }
    }
    foreach ([5 => 'invalid-type-cast', 6 => 'array-to-string-conversion', 7 => 'invalid-return-statement', 8 => 'mixed-return-statement', 9 => 'invalid-type-cast', 10 => 'invalid-type-cast', 14 => 'invalid-type-cast', 15 => 'invalid-type-cast', 16 => 'mixed-operand'] as $line => $code) {
        if (! in_array($code, $codes[$line] ?? [], true)) {
            throw new RuntimeException($mode.' lost '.$code.': '.json_encode($codes).' '.$workspace);
        }
    }
    foreach ([0, 1, 2, 4, 11, 12] as $line) {
        $advisories = array_intersect(['invalid-type-cast', 'redundant-cast', 'mixed-operand'], $codes[$line] ?? []);
        if (($advisories === []) !== ($mode === 'enabled')) {
            throw new RuntimeException($mode.' wrong safe cast policy: '.json_encode($codes).' '.$workspace);
        }
    }
    echo 'PASS: cast compatibility '.$mode.' ('.count($cases).' cases)' . "\n";
}
