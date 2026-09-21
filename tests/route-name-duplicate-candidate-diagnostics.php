<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\RouteNameDuplicateCandidatesHook;

require dirname(__DIR__).'/vendor/autoload.php';

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago route location notes '.bin2hex(random_bytes(8));
mkdir($workspace.'/routes/first', 0777, true);
mkdir($workspace.'/routes/second', 0777, true);
$firstSource = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Route;

    Route::get('/first', 'FirstController')->name('shared');
    PHP;
$secondSource = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Route;

    Route::get('/second', 'SecondController')->name('shared');
    PHP;
file_put_contents($workspace.'/routes/first/web.php', $firstSource);
file_put_contents($workspace.'/routes/second/web.php', $secondSource);
file_put_contents($workspace.'/framework.php', <<<'PHP'
    <?php
    namespace Illuminate\Routing;
    final class Route
    {
        public function name(string $name): self { return $this; }
    }
    namespace Illuminate\Support\Facades;
    final class Route
    {
        public static function get(string $uri, mixed $action = null): \Illuminate\Routing\Route
        {
            return new \Illuminate\Routing\Route;
        }
    }
    PHP);

$policy = static function (bool $enabled) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'route-name-duplicate-candidates' => [
                    'diagnose' => $enabled,
                    'files' => ['routes/first/web.php', 'routes/second/web.php'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
};
$policy(true);

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$analyze = static function (array $paths) use ($command, $package, $workspace): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => [
            'paths' => $paths,
            'includes' => ['framework.php'],
        ],
        'extension-hosts' => [
            'laramago' => [
                'command' => [
                    PHP_BINARY,
                    '-d',
                    'opcache.enable_cli=0',
                    $package.'/bin/laramago-worker.php',
                    $package.'/vendor/autoload.php',
                    $workspace,
                ],
                'workers' => 2,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
    if (
        ! in_array($exit, [0, 1], true)
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            $log,
        )
    ) {
        throw new RuntimeException('Mago or the extension worker failed; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_filter(
        $report['issues'] ?? [],
        static fn (array $issue): bool => (
            ($issue['code'] ?? null) === 'ichinya/laramago/laramago-route-name-duplicate-candidate'
        ),
    ));
};

try {
    $issues = $analyze(['routes/first/web.php', 'routes/second/web.php']);
    if (count($issues) !== 1) {
        throw new RuntimeException('Expected one advisory duplicate-name note; inspect '.$workspace);
    }
    $issue = $issues[0];
    $annotations = [];
    foreach ($issue['annotations'] as $annotation) {
        $annotations[$annotation['kind']] = $annotation;
    }
    if (array_keys($annotations) !== ['Primary', 'Secondary']) {
        throw new RuntimeException('Expected one primary and one secondary annotation; inspect '.$workspace);
    }
    $file = static fn (array $annotation): ?string => (
        $annotation['span']['file_id']['name'] ?? $annotation['file'] ?? null
    );
    $literal = static function (array $annotation, string $source): string {
        $span = $annotation['span'];

        return substr($source, $span['start']['offset'], $span['end']['offset'] - $span['start']['offset']);
    };
    if (
        ! str_ends_with(str_replace('\\', '/', (string) $file($annotations['Primary'])), 'routes/second/web.php')
        || ! str_ends_with(str_replace('\\', '/', (string) $file($annotations['Secondary'])), 'routes/first/web.php')
        || $literal($annotations['Primary'], $secondSource) !== "'shared'"
        || $literal($annotations['Secondary'], $firstSource) !== "'shared'"
        || ($annotations['Primary']['message'] ?? null) !== 'Repeated selected declaration'
        || ($annotations['Secondary']['message'] ?? null) !== 'First selected declaration'
    ) {
        throw new RuntimeException(
            'Cross-file annotation locations mismatch: '.json_encode($annotations).'; inspect '.$workspace,
        );
    }
    if (
        strtolower((string) ($issue['level'] ?? '')) !== 'note'
        || ! str_contains($issue['message'] ?? '', 'source-only candidate does not prove an active route conflict')
    ) {
        throw new RuntimeException('The diagnostic must remain explicitly advisory; inspect '.$workspace);
    }
    echo "PASS: real Mago accepts cross-file primary and secondary route declaration locations\n";

    if ($analyze(['routes/second/web.php']) !== []) {
        throw new RuntimeException('An unindexed first declaration must defer the candidate; inspect '.$workspace);
    }
    echo "PASS: unindexed declaration defers the cross-file note\n";

    $method = new ReflectionMethod(RouteNameDuplicateCandidatesHook::class, 'indexedLocation');
    $path = str_replace('\\', '/', realpath($workspace.'/routes/first/web.php'));
    $key = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    $stale = $method->invoke(
        null,
        [
            'file' => $path,
            'start' => 0,
            'end' => 5,
            'contentHash' => hash('sha256', 'older source'),
        ],
        [
            $key => [
                'file' => $path,
                'contentHash' => hash('sha256', $firstSource),
                'byteLength' => strlen($firstSource),
            ],
        ],
    );
    if ($stale !== null) {
        throw new RuntimeException('A stale metadata hash must not produce a source location.');
    }
    echo "PASS: stale metadata defers before creating a source location\n";

    $policy(false);
    if ($analyze(['routes/first/web.php', 'routes/second/web.php']) !== []) {
        throw new RuntimeException('Disabled advisory policy emitted a note; inspect '.$workspace);
    }
    echo "PASS: route duplicate advisory requires explicit opt-in\n";
} finally {
    foreach ([
        'routes/first/web.php',
        'routes/second/web.php',
        'framework.php',
        'composer.json',
        'mago.json',
        'report.json',
        'stderr.log',
    ] as $file) {
        if (is_file($workspace.'/'.$file)) {
            unlink($workspace.'/'.$file);
        }
    }
    rmdir($workspace.'/routes/first');
    rmdir($workspace.'/routes/second');
    rmdir($workspace.'/routes');
    rmdir($workspace);
}
