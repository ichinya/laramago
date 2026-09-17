<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago collection operations '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/framework.php', '<?php');
copy(__DIR__.'/fixtures/analysis/collection-operations.php.stub', $workspace.'/models.php');
file_put_contents(
    $workspace.'/bootstrap.php',
    '<?php throw new RuntimeException("Do not bootstrap the application.");',
);
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 3,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$cases = [
    'filter nullable strings' => ['return $nullable->filter();', 'Collection<string, string>', []],
    'filter explicit null' => ['return $nullable->filter(null);', 'Collection<string, string>', []],
    'filter named null' => ['return $nullable->filter(callback: null);', 'Collection<string, string>', []],
    'whole value not null' => ['return $nullable->whereNotNull();', 'Collection<string, string>', []],
    'named null key' => ['return $nullable->whereNotNull(key: null);', 'Collection<string, string>', []],
    'boolean truthiness' => ['return $booleans->filter();', 'Collection<int, true>', []],
    'not null keeps false' => ['return $booleans->whereNotNull();', 'Collection<int, bool>', []],
    'false removed' => ['return $falseOnly->filter();', 'Collection<int, never>', []],
    'false retained by not null' => ['return $falseOnly->whereNotNull();', 'Collection<int, false>', []],
    'scalar narrowing conservative' => ['return $scalars->filter();', "Collection<int, 0|'0'|array{}>", []],
    'mixed remains unknown' => ['return $unknown->filter();', 'Collection<int, mixed>', []],
    'callback preserves native' => [
        'return $nullable->filter(fn ($item) => true);',
        'Collection<string, string|null>',
        [],
    ],
    'key filtering preserves native' => [
        'return $nullable->whereNotNull("name");',
        'Collection<string, string|null>',
        [],
    ],
    'subclasses preserve native' => ['return $custom->filter();', 'CustomCollection', []],
    'eloquent collections preserve native' => [
        'return $eloquent->filter();',
        '\\Illuminate\\Database\\Eloquent\\Collection<int, object|null>',
        [],
    ],
    'wrong key type rejected' => [
        'return $nullable->filter();',
        'Collection<int, string>',
        ['invalid-return-statement'],
    ],
    'wrong value type rejected' => [
        'return $nullable->filter();',
        'Collection<string, int>',
        ['invalid-return-statement'],
    ],
    'not null does not remove false' => [
        'return $booleans->whereNotNull();',
        'Collection<int, true>',
        ['less-specific-return-statement'],
    ],
    'invalid callback' => ['$nullable->filter(42);', 'void', ['invalid-argument']],
    'unknown method' => ['$nullable->filtter();', 'void', ['non-existent-method']],
];
function check_collection_operations(array $cases, array $command, string $workspace): void
{
    $source = "<?php\nuse Illuminate\\Support\\Collection;\nuse Illuminate\\Support\\CustomCollection;\n";
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $doc =
            '/** @param Collection<string, string|null> $nullable @param Collection<int, bool|null> $booleans @param Collection<int, false|null> $falseOnly @param Collection<int, 0|\'0\'|array{}|null> $scalars @param Collection<int, mixed> $unknown @param \\Illuminate\\Database\\Eloquent\\Collection<int, object|null> $eloquent @return '
            .$return
            .' */'
            ."\n";
        $source .= str_replace([' @param', ' @return'], ["\n * @param", "\n * @return"], $doc);
        $source .=
            'function scenario'
            .count($lines)
            .'(Collection $nullable, Collection $booleans, Collection $falseOnly, Collection $scalars, Collection $unknown, CustomCollection $custom, \\Illuminate\\Database\\Eloquent\\Collection $eloquent) { '
            .$body
            .' }'
            ."\n";
        $lines[substr_count($source, "\n")] = [$name, $codes];
    }
    file_put_contents($workspace.'/cases.php', $source);
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/report.json', 'w'],
            2 => ['file', $workspace.'/stderr.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/stderr.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '
        .$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics outside query scenarios; inspect '.$workspace);
    }
}

check_collection_operations($cases, $command, $workspace);
$resolvedWorkspace = realpath($workspace);
foreach (glob($workspace.'/*') ?: [] as $file) {
    $resolvedFile = realpath($file);
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    unlink($resolvedFile);
}
rmdir($workspace);
