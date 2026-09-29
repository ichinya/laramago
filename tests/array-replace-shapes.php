<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago array replace '.bin2hex(random_bytes(8));
mkdir($workspace);
$cases = [
    'replaced field' => 'function replaced(): string { $record = ["id" => 1, "name" => "before"]; return array_replace($record, ["name" => "after"])["name"]; }',
    'retained field' => 'function retained(): int { return array_replace(["id" => 1, "name" => "before"], ["name" => "after"])["id"]; }',
    'last replacement wins' => 'function last(): bool { return array_replace(["item" => 1], ["item" => "middle"], ["item" => true])["item"]; }',
    'nullable replacement' => '/** @return array{id: null, name: string} */ function nullable(): array { return array_replace(["id" => 1, "name" => "example"], ["id" => null]); }',
    'wrong replacement value' => 'function wrong(): int { return array_replace(["id" => 1], ["id" => "wrong"])["id"]; }',
    'dynamic replacement' => '/** @param array<string, mixed> $input */ function dynamic(array $input): int { return array_replace(["id" => 1], $input)["id"]; }',
    'optional replacement' => '/** @param array{id?: string} $input */ function optional(array $input): int { return array_replace(["id" => 1], $input)["id"]; }',
    'invalid argument' => 'function invalid(): array { return array_replace(["id" => 1], 3); }',
    'unpacked replacement' => '/** @param list<array<string, mixed>> $input */ function unpacked(array $input): int { return array_replace(["id" => 1], ...$input)["id"]; }',
    'nested replacement is shallow' => 'function shallow(): int { return array_replace(["item" => ["id" => 1]], ["item" => ["id" => "wrong"]])["item"]["id"]; }',
    'missing arguments' => 'function missing(): array { return array_replace(); }',
];
file_put_contents($workspace.'/cases.php', "<?php\n".implode("\n", $cases)."\n");
$runs = [];
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
    $codes = [];
    foreach (json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'] as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'cases.php') {
                $codes[$annotation['span']['start']['line'] - 1][] = $issue['code'];
                break;
            }
        }
    }
    $runs[$mode] = $codes;
}
foreach (range(0, 3) as $line) {
    if (($runs['enabled'][$line] ?? []) !== []) {
        throw new RuntimeException('Shape was lost at '.$line.': '.json_encode($runs).' '.$workspace);
    }
}
foreach ([4 => 'invalid-return-statement', 7 => 'invalid-argument', 9 => 'invalid-return-statement', 10 => 'too-few-arguments'] as $line => $code) {
    if (! in_array($code, $runs['enabled'][$line] ?? [], true)) {
        throw new RuntimeException('Lost '.$code.' at '.$line.': '.json_encode($runs).' '.$workspace);
    }
}
foreach ([5, 6, 8] as $line) {
    if (($runs['enabled'][$line] ?? []) !== ($runs['disabled'][$line] ?? []) || ($runs['enabled'][$line] ?? []) === []) {
        throw new RuntimeException('Unknown shape must defer at '.$line.': '.json_encode($runs).' '.$workspace);
    }
}
if (($runs['disabled'][0] ?? []) === [] || ($runs['disabled'][3] ?? []) === []) {
    throw new RuntimeException('Missing native regression witness: '.$workspace);
}
echo 'PASS: array_replace shapes ('.count($cases).' cases, native and extended)' . "\n";
