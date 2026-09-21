<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\RouteMetadataExport;
use Ichinya\Laramago\Metadata\SelectedSourceWatch;

require dirname(__DIR__).'/vendor/autoload.php';

$packageRoot = dirname(__DIR__);
$fixtureRoot = sys_get_temp_dir().'/laramago route watch '.bin2hex(random_bytes(6));
$routeDirectory = $fixtureRoot.'/routes';
$route = $routeDirectory.'/web.php';
$configDirectory = $fixtureRoot.'/config';
$config = $configDirectory.'/app.php';
$vendorDirectory = $fixtureRoot.'/vendor';
$eventsFile = $fixtureRoot.'/events.jsonl';
$stderrFile = $fixtureRoot.'/stderr.log';
$outside = dirname($fixtureRoot).'/laramago-route-watch-outside-'.bin2hex(random_bytes(4)).'.php';
$large = $routeDirectory.'/large.php';
$totalPaths = [];
$json = $fixtureRoot.'/selected.json';
$process = null;
$pipes = [];
$buffer = '';
$readOffset = 0;
$revision = 0;
$checks = 0;

$check = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($label);
    }
};
$routeSource = static fn (string $name): string => <<<PHP
    <?php
    use Illuminate\Support\Facades\Route;
    Route::get('/profile', 'ProfileController')->name('{$name}');
    throw new RuntimeException('Selected route source executed');
    PHP;
$read = static function () use ($eventsFile, &$buffer, &$readOffset): string {
    $contents = file_get_contents($eventsFile);
    if ($contents === false || strlen($contents) < $readOffset) {
        throw new RuntimeException('Cannot read route watch JSONL output.');
    }
    $chunk = substr($contents, $readOffset);
    $buffer .= $chunk;
    $readOffset = strlen($contents);

    return $chunk;
};
$next = static function (callable $accept, string $label) use (
    &$buffer,
    &$revision,
    &$process,
    $read,
    $stderrFile,
): array {
    $deadline = microtime(true) + 6;
    do {
        $read();
        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newline);
            $buffer = substr($buffer, $newline + 1);
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($event) || ($event['event'] ?? null) !== 'snapshot') {
                throw new RuntimeException('Unexpected route watch event: '.$line);
            }
            if (($event['revision'] ?? null) <= $revision) {
                throw new RuntimeException('Route watch revisions must increase.');
            }
            $revision = $event['revision'];
            if ($accept($event)) {
                return $event;
            }
        }
        $status = proc_get_status($process);
        if (! $status['running']) {
            throw new RuntimeException('Route watch process exited: '.file_get_contents($stderrFile));
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Timed out waiting for '.$label.': '.file_get_contents($stderrFile));
};

try {
    mkdir($routeDirectory, 0777, true);
    mkdir($configDirectory);
    mkdir($vendorDirectory);
    file_put_contents($route, $routeSource('first'));
    file_put_contents($config, "<?php return ['name' => 'one'];\n");
    file_put_contents($fixtureRoot.'/.env', 'SECRET=PRIVATE_ROUTE_WATCH_VALUE');
    file_put_contents(
        $vendorDirectory.'/autoload.php',
        '<?php throw new RuntimeException("Application autoload executed");',
    );
    file_put_contents($outside, "<?php return [];\n");

    $opaque = [
        'projectRoot' => 'opaque-root',
        'declarations' => [['name' => 'opaque-name', 'file' => 'opaque-file']],
        'errors' => [],
        'truncated' => false,
    ];
    $watch = new SelectedSourceWatch($fixtureRoot, ['routes/web.php'], ['php']);
    $initial = $watch->refresh(static fn (): array => $opaque);
    $check($initial['status'] === 'ready' && $initial['metadata'] === $opaque, 'Exporter metadata stays opaque.');
    $check(
        $watch->refresh(static function (): never {
            throw new RuntimeException('Unchanged ready source must not re-export.');
        }) === null,
        'Unchanged ready source emits no duplicate.',
    );
    file_put_contents($config, "<?php return ['name' => 'two'];\n");
    $check(
        $watch->refresh(static function (): never {
            throw new RuntimeException('Unselected configuration must not trigger route export.');
        }) === null,
        'Unselected configuration changes are ignored.',
    );

    file_put_contents($route, $routeSource('second'));
    $changedDuringExport = $watch->refresh(static function () use ($route, $routeSource): array {
        file_put_contents($route, $routeSource('third'));

        return ['errors' => [], 'truncated' => false];
    });
    $check(
        $changedDuringExport['status'] === 'error'
        && $changedDuringExport['metadata'] === null
        && in_array('source-changed-during-refresh', array_column($changedDuringExport['errors'], 'code'), true),
        'A before/after rescan rejects a racing export.',
    );
    $routeExporter = new RouteMetadataExport;
    $recovered = $watch->refresh(static fn (): array => $routeExporter->export($fixtureRoot, ['routes/web.php']));
    $check(
        $recovered['status'] === 'ready' && array_column($recovered['metadata']['declarations'], 'name') === ['third'],
        'A stable source recovers after a racing export.',
    );

    file_put_contents($route, '<?php broken(');
    $parseError = $watch->refresh(static fn (): array => $routeExporter->export($fixtureRoot, ['routes/web.php']));
    $check(
        $parseError['status'] === 'error'
        && $parseError['metadata'] === null
        && array_column($parseError['errors'], 'code') === ['parse-failure'],
        'Exporter parse errors invalidate previous metadata.',
    );
    $check(
        $watch->refresh(static fn (): array => $routeExporter->export($fixtureRoot, ['routes/web.php'])) === null,
        'A stable parse error emits no duplicate event.',
    );
    file_put_contents($route, $routeSource('restored'));
    $check(
        $watch->refresh(static fn (): array => $routeExporter->export($fixtureRoot, ['routes/web.php']))['status']
        === 'ready',
        'A repaired parse error publishes fresh metadata.',
    );

    file_put_contents($route, $routeSource('truncated'));
    $truncated = $watch->refresh(static fn (): array => ['errors' => [], 'truncated' => true]);
    $check(
        $truncated['status'] === 'error'
        && $truncated['metadata'] === null
        && array_column($truncated['errors'], 'code') === ['truncated-export'],
        'A truncated export invalidates previous metadata.',
    );
    $check(
        $watch->refresh(static fn (): array => ['errors' => [], 'truncated' => true]) === null,
        'A stable truncated export emits no duplicate event.',
    );

    $called = false;
    $invalidExtension = new SelectedSourceWatch($fixtureRoot, ['.env'], ['php']);
    $extensionEvent = $invalidExtension->refresh(static function () use (&$called): array {
        $called = true;

        return [];
    });
    $check(
        ! $called
        && $extensionEvent['status'] === 'error'
        && array_column($extensionEvent['errors'], 'code') === ['unsupported-source-format'],
        'Disallowed extensions are rejected before reading or exporting.',
    );
    $outsideWatch = new SelectedSourceWatch($fixtureRoot, ['../'.basename($outside)], ['php']);
    $outsideEvent = $outsideWatch->refresh(static function () use (&$called): array {
        $called = true;

        return [];
    });
    $check(
        ! $called
        && $outsideEvent['status'] === 'error'
        && array_column($outsideEvent['errors'], 'code') === ['invalid-source'],
        'Parent traversal is rejected before reading or exporting.',
    );
    file_put_contents($large, '<?php '.str_repeat(' ', 1024 * 1024));
    $largeEvent = (new SelectedSourceWatch($fixtureRoot, ['routes/large.php'], ['php']))->refresh(
        static function () use (&$called): array {
            $called = true;

            return [];
        },
    );
    $check(
        ! $called
        && $largeEvent['status'] === 'error'
        && array_column($largeEvent['errors'], 'code') === ['source-limit'],
        'Per-file reads are bounded to one MiB.',
    );
    $totalFiles = [];
    for ($index = 0; $index < 9; $index++) {
        $totalFiles[] = 'routes/total'.$index.'.php';
        $totalPaths[] = $routeDirectory.'/total'.$index.'.php';
        file_put_contents($totalPaths[$index], str_pad('<?php', 1024 * 1024));
    }
    $totalEvent = (new SelectedSourceWatch($fixtureRoot, $totalFiles, ['php']))->refresh(
        static function () use (&$called): array {
            $called = true;

            return [];
        },
    );
    $check(
        ! $called
        && $totalEvent['status'] === 'error'
        && array_column($totalEvent['errors'], 'code') === ['source-limit'],
        'Aggregate selected source reads are bounded to eight MiB.',
    );
    $fileLimit = (new SelectedSourceWatch($fixtureRoot, array_fill(0, 257, 'routes/web.php'), ['php']))->refresh(
        static function () use (&$called): array {
            $called = true;

            return [];
        },
    );
    $check(
        ! $called && $fileLimit['status'] === 'error' && $fileLimit['errors'][0]['code'] === 'too-many-sources',
        'Selected source scans are bounded to 256 files.',
    );
    file_put_contents($json, '{}');
    $jsonEvent = (new SelectedSourceWatch($fixtureRoot, ['selected.json'], ['php', 'json']))->refresh(
        static fn (): array => ['format' => 'json', 'errors' => [], 'truncated' => false],
    );
    $check(
        $jsonEvent['status'] === 'ready' && $jsonEvent['metadata']['format'] === 'json',
        'The caller controls an explicit reusable extension allowlist.',
    );

    file_put_contents($route, $routeSource('first'));
    $command = [
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        $packageRoot.'/bin/laramago-metadata',
        '--project-root',
        $fixtureRoot,
        '--kind',
        'routes',
        '--source',
        'routes/web.php',
        '--watch',
        '--interval-ms',
        '50',
    ];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $eventsFile, 'w'], 2 => ['file', $stderrFile, 'w']],
        $pipes,
        $packageRoot,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start route metadata watch CLI.');
    }
    fclose($pipes[0]);

    $cliInitial = $next(static fn (array $event): bool => $event['status'] === 'ready', 'initial route snapshot');
    $declaration = $cliInitial['metadata']['declarations'][0];
    $source = file_get_contents($route);
    $check(
        $cliInitial['metadata']['projectRoot'] === str_replace('\\', '/', realpath($fixtureRoot))
        && $declaration['name'] === 'first'
        && $declaration['file'] === str_replace('\\', '/', realpath($route))
        && $declaration['contentHash'] === hash('sha256', $source),
        'CLI preserves project, name, file, and exact-byte provenance.',
    );
    $check(
        ! str_contains(json_encode($cliInitial, JSON_THROW_ON_ERROR), 'PRIVATE_ROUTE_WATCH_VALUE'),
        'CLI does not expose environment values or execute application autoload.',
    );

    usleep(150_000);
    $check($read() === '' && $buffer === '', 'Idle route polls emit no duplicate JSONL.');
    file_put_contents($config, "<?php return ['name' => 'six'];\n");
    usleep(150_000);
    $check($read() === '' && $buffer === '', 'CLI ignores unselected configuration changes.');

    file_put_contents($route, $routeSource('other'));
    $modified = $next(
        static fn (array $event): bool => (
            $event['status'] === 'ready'
            && ($event['metadata']['declarations'][0]['name'] ?? null) === 'other'
        ),
        'same-size selected route edit',
    );
    $check($modified['sourceHash'] !== $cliInitial['sourceHash'], 'Exact source bytes change the watch hash.');

    unlink($route);
    $removed = $next(
        static fn (array $event): bool => $event['status'] === 'error'
        && in_array('unreadable-source', array_column($event['errors'], 'code'), true),
        'selected route removal',
    );
    $check($removed['metadata'] === null, 'Removed selected source cannot retain route metadata.');

    file_put_contents($route, '<?php broken(');
    $broken = $next(
        static fn (array $event): bool => $event['status'] === 'error'
        && in_array('parse-failure', array_column($event['errors'], 'code'), true),
        'selected route parse error',
    );
    $check($broken['metadata'] === null, 'CLI parse errors invalidate prior metadata.');
    usleep(150_000);
    $check($read() === '' && $buffer === '', 'Stable CLI error state emits no duplicate JSONL.');

    file_put_contents($route, $routeSource('recovered'));
    $cliRecovered = $next(
        static fn (array $event): bool => (
            $event['status'] === 'ready'
            && ($event['metadata']['declarations'][0]['name'] ?? null) === 'recovered'
        ),
        'selected route recovery',
    );
    $check($cliRecovered['metadata']['errors'] === [], 'Recovered CLI metadata is fresh and error-free.');
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    foreach ([
        $route,
        $large,
        ...$totalPaths,
        $config,
        $fixtureRoot.'/.env',
        $vendorDirectory.'/autoload.php',
        $eventsFile,
        $stderrFile,
        $json,
        $outside,
    ] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([$routeDirectory, $configDirectory, $vendorDirectory, $fixtureRoot] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

echo "Route metadata watch: {$checks} checks passed.\n";
