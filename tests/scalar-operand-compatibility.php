<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago scalar operands '.bin2hex(random_bytes(8));
mkdir($workspace);
$cases = [
    'nullable concatenation' => 'function nullableString(?string $value): string { return "key=".$value; }',
    'false concatenation' => 'function falseString(string|false $value): string { return "key=".$value; }',
    'boolean concatenation' => 'function booleanString(bool $value): string { return "key=".$value; }',
    'nullable arithmetic' => 'function nullableArithmetic(?int $value): int { return $value + 1; }',
    'false arithmetic' => 'function falseArithmetic(int|false $value): int { return $value + 1; }',
    'mixed condition' => 'function condition(mixed $value, mixed $other): bool { return $value || $other; }',
    'mixed comparison' => 'function comparison(mixed $value, mixed $other): bool { return $value != $other; }',
    'mixed spaceship' => 'function spaceship(mixed $value, mixed $other): int { return $value <=> $other; }',
    'mixed arithmetic' => 'function arithmetic(mixed $value): int { return $value + 1; }',
    'mixed concatenation' => 'function concatenation(mixed $value): string { return "key=".$value; }',
    'object concatenation' => 'function objectString(object $value): string { return "key=".$value; }',
    'nullable nonnumeric' => 'function nonnumeric(?string $value): int { return $value + 1; }',
    'object arithmetic' => 'function objectArithmetic(object $value): int { return $value + 1; }',
    'invalid nested argument' => 'function nestedArgument(mixed $value): bool { return strlen($value) > 0; }',
    'array string' => '/** @param array<string, mixed> $value */ function arrayString(array $value): string { return "key=".$value; }',
    'wrong return' => 'function wrongReturn(mixed $value, mixed $other): string { return $value <=> $other; }',
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
    foreach ([8 => 'mixed-operand', 9 => 'mixed-operand', 10 => 'invalid-operand', 11 => 'invalid-operand', 12 => 'invalid-operand', 13 => 'mixed-argument', 14 => 'array-to-string-conversion', 15 => 'invalid-return-statement'] as $line => $code) {
        if (! in_array($code, $codes[$line] ?? [], true)) {
            throw new RuntimeException($mode.' lost '.$code.': '.json_encode($codes).' '.$workspace);
        }
    }
    foreach (range(0, 7) as $line) {
        $advisories = array_intersect(['possibly-null-operand', 'possibly-false-operand', 'mixed-operand', 'invalid-operand'], $codes[$line] ?? []);
        if (($advisories === []) !== ($mode === 'enabled')) {
            throw new RuntimeException($mode.' wrong scalar operand policy: '.json_encode($codes).' '.$workspace);
        }
    }
    echo 'PASS: scalar operand compatibility '.$mode.' ('.count($cases).' cases)' . "\n";
}
