<?php

declare(strict_types=1);

// This is a development benchmark; it never modifies the application's config.
ini_set('memory_limit', '768M');
$options = getopt('', ['project:', 'output:']);
$project = realpath($options['project'] ?? '');
if ($project === false || ! isset($options['project']) || ! is_file($project.'/composer.json')) {
    fwrite(STDERR, "Usage: php scripts/compare.php --project=C:/projects/laravel-app [--output=directory]\n");
    exit(2);
}
$output = $options['output'] ?? dirname(__DIR__).'/var/comparisons/'.basename($project).'/'.gmdate('Ymd-His');
if (! is_dir($output) && ! mkdir($output, 0777, true) && ! is_dir($output)) {
    throw new RuntimeException('Cannot create output directory.');
}
$output = realpath($output);
$composer = json_decode(file_get_contents($project.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$vendor = $composer['config']['vendor-dir'] ?? 'vendor';
$vendor = preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $vendor) ? $vendor : $project.'/'.$vendor;
$phpstan = $vendor.'/bin/phpstan';
$mago = $vendor.'/bin/mago';
foreach ([$phpstan, $mago] as $binary) {
    if (! is_file($binary)) {
        throw new RuntimeException('Install project dependencies first: '.$binary);
    }
}
$config = null;
foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $name) {
    if (is_file($project.'/'.$name)) {
        $config = str_replace('\\', '/', $project.'/'.$name);
        break;
    }
}
if ($config === null) {
    throw new RuntimeException('No PHPStan project config found.');
}

/** @return array{exit: int, seconds: float, json: array} */
function runReport(array $command, string $project, string $output, string $name): array
{
    echo 'Running '.$name."...\n";
    $start = microtime(true);
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['file', $output.'/'.$name.'.json', 'w'],
            2 => ['file', $output.'/'.$name.'.log', 'w'],
        ],
        $pipes,
        $project,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start '.$name);
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($output.'/'.$name.'.log');
    if (
        str_starts_with($name, 'mago-')
        && preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
    ) {
        throw new RuntimeException($name.' used native fallback after an extension failure: inspect its log.');
    }
    $body = file_get_contents($output.'/'.$name.'.json');
    $json = trim($body) === '' ? [] : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    if (! in_array($exit, [0, 1], true)) {
        throw new RuntimeException($name.' failed: see '.$output.'/'.$name.'.log');
    }
    if (str_starts_with($name, 'larastan-') && (! isset($json['totals']) || ($json['totals']['errors'] ?? 0) > 0)) {
        throw new RuntimeException($name.' produced an incomplete report: inspect its JSON and log.');
    }
    if (str_starts_with($name, 'mago-') && $exit !== 0 && ! isset($json['issues'])) {
        throw new RuntimeException($name.' failed without a diagnostic report.');
    }

    return ['exit' => $exit, 'seconds' => round(microtime(true) - $start, 2), 'json' => $json];
}

function relativeFile(string $file, string $project): string
{
    $file = str_replace('\\', '/', $file);
    if (str_starts_with($file, '//?/')) {
        $file = substr($file, 4);
    }
    $root = rtrim(str_replace('\\', '/', $project), '/').'/';

    return strncasecmp($file, $root, strlen($root)) === 0 ? substr($file, strlen($root)) : $file;
}

/** @return list<array{file: string, line: int, code: string, level: string, message: string}> */
function normalize(array $report, string $project, bool $isMago): array
{
    $rows = [];
    if ($isMago) {
        foreach ($report['issues'] ?? [] as $issue) {
            $primary = null;
            foreach ($issue['annotations'] ?? [] as $annotation) {
                if (($annotation['kind'] ?? '') === 'Primary') {
                    $primary = $annotation;
                    break;
                }
            }
            $primary ??= $issue['annotations'][0] ?? [];
            $rows[] = [
                'file' => relativeFile($primary['span']['file_id']['name'] ?? '<global>', $project),
                'line' => isset($primary['span']['start']['line']) ? $primary['span']['start']['line'] + 1 : 0,
                'code' => $issue['code'] ?? '<none>',
                'level' => $issue['level'],
                'message' => $issue['message'],
            ];
        }
    } else {
        foreach ($report['files'] ?? [] as $file => $data) {
            foreach ($data['messages'] as $message) {
                $rows[] = [
                    'file' => relativeFile($file, $project),
                    'line' => $message['line'] ?? 0,
                    'code' => $message['identifier'] ?? '<none>',
                    'level' => 'Error',
                    'message' => $message['message'],
                ];
            }
        }
    }
    usort(
        $rows,
        static fn (array $a, array $b): int => (
            [$a['file'], $a['line'], $a['code'], $a['message']] <=> [$b['file'], $b['line'], $b['code'], $b['message']]
        ),
    );

    return $rows;
}

function frequencies(array $rows, string $key): array
{
    $counts = array_count_values(array_column($rows, $key));
    arsort($counts);

    return $counts;
}

function saveJson(string $file, mixed $data): void
{
    file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            ."\n",
    );
}

$parameters = runReport(
    [PHP_BINARY, $phpstan, 'dump-parameters', '-c', $config, '--json'],
    $project,
    $output,
    'parameters',
)['json'];
$paths = $parameters['paths'] ?? [];
if ($paths === []) {
    throw new RuntimeException('PHPStan configured no analysis paths.');
}
$unfiltered = $output.'/unfiltered.neon';
file_put_contents(
    $unfiltered,
    "includes:\n    - ".json_encode($config, JSON_UNESCAPED_SLASHES)."\nparameters:\n    ignoreErrors!: []\n",
);
$rawParameters = runReport(
    [PHP_BINARY, $phpstan, 'dump-parameters', '-c', $unfiltered, '--json'],
    $project,
    $output,
    'parameters-unfiltered',
)['json'];
if (($rawParameters['ignoreErrors'] ?? null) !== []) {
    throw new RuntimeException('PHPStan ignores were not cleared.');
}
$base = [PHP_BINARY, $phpstan, 'analyse', '--memory-limit=2G', '--error-format=json', '--no-progress'];
$runs = [
    'larastan-configured' => runReport([...$base, '-c', $config], $project, $output, 'larastan-configured'),
    'larastan-unfiltered' => runReport([...$base, '-c', $unfiltered], $project, $output, 'larastan-unfiltered'),
    'larastan-max' => runReport([...$base, '-c', $unfiltered, '--level=max'], $project, $output, 'larastan-max'),
    'mago-matched' => runReport(
        [PHP_BINARY, $mago, 'analyze', ...$paths, '--reporting-format=json'],
        $project,
        $output,
        'mago-matched',
    ),
];
$summary = [
    'project' => $project,
    'generatedAt' => gmdate('c'),
    'phpVersion' => PHP_VERSION,
    'phpstanLevel' => $parameters['level'],
    'paths' => $paths,
    'phpstanIgnoreErrors' => $parameters['ignoreErrors'] ?? [],
    'phpstanExcludePaths' => $parameters['excludePaths'] ?? [],
    'limits' => 'Same source roots; tool-specific exclusions and severity/type systems remain different. Location overlap is not proof of semantic equivalence.',
    'runs' => [],
];
$lock = json_decode(file_get_contents($project.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
    if (in_array(
        $package['name'],
        ['carthage-software/mago', 'larastan/larastan', 'phpstan/phpstan', 'laravel/framework'],
        true,
    )) {
        $summary['versions'][$package['name']] = $package['version'];
    }
}
$normalized = [];
foreach ($runs as $name => $run) {
    $rows = normalize($run['json'], $project, str_starts_with($name, 'mago-'));
    $normalized[$name] = $rows;
    $summary['runs'][$name] = [
        'exit' => $run['exit'],
        'seconds' => $run['seconds'],
        'total' => count($rows),
        'levels' => frequencies($rows, 'level'),
        'codes' => frequencies($rows, 'code'),
    ];
    saveJson($output.'/'.$name.'-normalized.json', $rows);
}
$larastanLocations = [];
foreach ($normalized['larastan-max'] as $row) {
    $larastanLocations[$row['file'].':'.$row['line']] = true;
}
$candidates = array_values(array_filter(
    $normalized['mago-matched'],
    static fn (array $row): bool => ! isset($larastanLocations[$row['file'].':'.$row['line']]),
));
saveJson($output.'/mago-only-location-candidates.json', $candidates);
saveJson($output.'/summary.json', $summary);
$markdown = "# Larastan and Mago comparison\n\nGenerated: ".$summary['generatedAt'].".\n\n";
$markdown .= "| Run | Diagnostics | Exit |\n| --- | ---: | ---: |\n";
foreach ($summary['runs'] as $name => $run) {
    $markdown .= '| '.$name.' | '.$run['total'].' | '.$run['exit']." |\n";
}
$markdown .=
    "\nSource paths: `"
    .implode('`, `', array_map(static fn (string $p): string => relativeFile($p, $project), $paths))
    ."`.\n\n";
$markdown .= "`larastan-unfiltered` keeps the project level and clears ignoreErrors; `larastan-max` also selects the maximum level. Mago uses its own configuration and native strictness.\n\n";
$markdown .=
    'Mago candidates on lines without Larastan max diagnostics: '
    .count($candidates)
    .". These are candidates for investigation, not confirmed false positives. Source annotations and tool-specific exclusions remain in effect.\n\n";
$markdown .= "## Most frequent Mago codes\n\n| Code | Count |\n| --- | ---: |\n";
foreach (array_slice($summary['runs']['mago-matched']['codes'], 0, 20, true) as $code => $count) {
    $markdown .= '| '.$code.' | '.$count." |\n";
}
file_put_contents($output.'/comparison.md', $markdown);

$fixture = dirname(__DIR__).'/tests/fixtures/analysis/eloquent.php';
$corpus = [];
foreach ([
    'larastan-corpus' => [...$base, '-c', $unfiltered, '--level=max', $fixture],
    'mago-corpus' => [PHP_BINARY, $mago, 'analyze', $fixture, '--reporting-format=json'],
] as $name => $command) {
    $run = runReport($command, $project, $output, $name);
    $corpus[$name] = normalize($run['json'], $project, str_starts_with($name, 'mago-'));
    saveJson($output.'/'.$name.'-normalized.json', $corpus[$name]);
}
$markdown .= "\n## Eloquent comparison fixture\n\n";
$markdown .= "| Scenario | Larastan max | Mago |\n| --- | --- | --- |\n";
$labels = [
    'return ComparisonModel::query()->where(' => 'Explicit query()->where()',
    'return ComparisonModel::where(' => 'Magic where()',
    'ComparisonModel::wherre(' => 'Misspelled wherre()',
    'ComparisonModel::where();' => 'Missing argument',
    'ComparisonModel::where(new stdClass' => 'Invalid argument type',
];
foreach (file($fixture) as $offset => $sourceLine) {
    foreach ($labels as $needle => $label) {
        if (! str_contains($sourceLine, $needle)) {
            continue;
        }
        $cells = [];
        foreach ($corpus as $rows) {
            $codes = array_column(
                array_filter($rows, static fn (array $row): bool => $row['line'] === ($offset + 1)),
                'code',
            );
            $cells[] = $codes === [] ? 'No diagnostics' : implode(', ', $codes);
        }
        $markdown .= '| '.$label.' | '.implode(' | ', $cells)." |\n";
    }
}
$markdown .= "\nThe first two scenarios are valid; the last three are intentionally invalid. Missing argument count/type checks are a separate gap, even when a generic unknown-method diagnostic is present.\n";
file_put_contents($output.'/comparison.md', $markdown);
echo 'Comparison saved to '.$output."/comparison.md\n";
