<?php

declare(strict_types=1);

// Run against the real Mago executable; no Laravel application bootstrap needed.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
if (! is_file($binary)) {
    throw new RuntimeException('Install Composer dependencies or set MAGO_BINARY to the Mago executable.');
}
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = sys_get_temp_dir().'/laramago-preset-'.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/app');
mkdir($workspace.'/tests');
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => str_replace('\\', '/', realpath(__DIR__.'/../presets/laravel.toml')),
    'php-version' => '8.2',
    'source' => ['paths' => ['app', 'tests']],
], JSON_THROW_ON_ERROR));

/** @return array{int, list<array<string, mixed>>} */
function lintFixture(array $command, string $workspace): array
{
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'lint', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Mago.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    $report = trim($output) === '' ? [] : json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    return [$exit, $report['issues'] ?? []];
}

$cases = [
    // Both are framework metadata, not embedded secrets.
    [
        'Laravel metadata',
        'app/Fixture.php',
        '<?php class User { protected function casts(): array { return ["password" => "hashed"]; } }',
        0,
        'no-literal-password',
        'Warning',
    ],
    [
        'Laravel validation',
        'app/Fixture.php',
        '<?php function rules(): array { return ["token" => "required"]; }',
        0,
        'no-literal-password',
        'Warning',
    ],
    // Keep a real heuristic hit visible too: do not just disable the rule.
    [
        'Literal secret is visible',
        'app/Fixture.php',
        '<?php $password = "example-secret";',
        0,
        'no-literal-password',
        'Warning',
    ],
    [
        'Nested loop in finally',
        'app/Fixture.php',
        '<?php try { echo "start"; } finally { foreach ([1, 2] as $item) { if ($item === 1) { continue; } echo $item; } }',
        0,
        'no-unsafe-finally',
        'Warning',
    ],
    ['Production eval fails', 'app/Fixture.php', '<?php eval("echo 1;");', 1, 'no-eval', 'Error'],
    [
        'Test config round trip',
        'tests/Fixture.php',
        '<?php $config = eval("return ".var_export(["timezone" => "UTC"], true).";");',
        0,
        null,
        null,
    ],
    ['Invalid PHP fails', 'app/Fixture.php', '<?php function broken( {', 1, null, null],
];

try {
    foreach ($cases as [$label, $path, $source, $expectedExit, $code, $level]) {
        file_put_contents($workspace.'/'.$path, $source);
        [$exit, $issues] = lintFixture($command, $workspace);
        if ($exit !== $expectedExit) {
            throw new RuntimeException(
                $label.': unexpected exit '.$exit.' '.json_encode($issues).' '
                    .file_get_contents($workspace.'/stderr.log'),
            );
        }
        if (
            $code !== null
            && ! array_filter(
                $issues,
                static fn (array $issue): bool => ($issue['code'] ?? '') === $code && $issue['level'] === $level,
            )
        ) {
            throw new RuntimeException($label.': expected '.$code.' at '.$level);
        }
        if (
            $label === 'Test config round trip'
            && array_filter($issues, static fn (array $issue): bool => ($issue['code'] ?? '') === 'no-eval')
        ) {
            throw new RuntimeException('Test-only eval exemption did not apply.');
        }
        unlink($workspace.'/'.$path);
        echo 'PASS: '.$label."\n";
    }
} finally {
    foreach (['app/Fixture.php', 'tests/Fixture.php', 'mago.json', 'stderr.log'] as $path) {
        if (is_file($workspace.'/'.$path)) {
            unlink($workspace.'/'.$path);
        }
    }
    rmdir($workspace.'/app');
    rmdir($workspace.'/tests');
    rmdir($workspace);
}
