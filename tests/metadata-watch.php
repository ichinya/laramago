<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$fixtureRoot = sys_get_temp_dir().'/laramago metadata watch '.bin2hex(random_bytes(6));
$config = $fixtureRoot.'/config';
$app = $config.'/app.php';
$extra = $config.'/extra.php';
$composer = $fixtureRoot.'/composer.json';
$eventsFile = $fixtureRoot.'/events.jsonl';
$stderrFile = $fixtureRoot.'/stderr.log';
$process = null;
$pipes = [];
$buffer = '';
$readOffset = 0;
$revision = 0;

$assert = static function (bool $condition, string $label): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    echo 'PASS: '.$label."\n";
};

$read = static function () use ($eventsFile, &$buffer, &$readOffset): string {
    $contents = file_get_contents($eventsFile);
    if ($contents === false || strlen($contents) < $readOffset) {
        throw new RuntimeException('Cannot read watch JSONL output.');
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
    $deadline = microtime(true) + 8;
    do {
        $read();
        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newline);
            $buffer = substr($buffer, $newline + 1);
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($event) || ($event['event'] ?? null) !== 'snapshot') {
                throw new RuntimeException('Unexpected watch event: '.$line);
            }
            if (($event['revision'] ?? null) <= $revision) {
                throw new RuntimeException('Watch revisions must increase.');
            }
            $revision = $event['revision'];
            if ($accept($event)) {
                echo 'PASS: '.$label."\n";

                return $event;
            }
        }
        $status = proc_get_status($process);
        if (! $status['running']) {
            throw new RuntimeException('Watch process exited: '.file_get_contents($stderrFile));
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Timed out waiting for '.$label.': '.file_get_contents($stderrFile));
};

try {
    mkdir($fixtureRoot);
    mkdir($config);
    file_put_contents($composer, '{"name":"example/watch-fixture"}');
    file_put_contents($app, "<?php return ['name' => 'one'];\n");

    $command = [
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        $packageRoot.'/bin/laramago-metadata',
        '--project-root',
        $fixtureRoot,
        '--config-key',
        'app.name',
        '--watch',
        '--interval-ms',
        '75',
    ];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $eventsFile, 'w'], 2 => ['file', $stderrFile, 'w']],
        $pipes,
        $packageRoot,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start metadata watch CLI.');
    }
    fclose($pipes[0]);

    $initial = $next(
        static fn (array $event): bool => $event['status'] === 'ready',
        'initial snapshot in a path with spaces',
    );
    $assert($initial['metadata']['requests'][0]['confidence'] === 'known-positive', 'initial request is known');

    usleep(250_000);
    $assert($read() === '' && $buffer === '', 'idle polls do not repeat JSONL');

    file_put_contents($app, "<?php return ['name' => 'two'];\n");
    $modified = $next(
        static fn (array $event): bool => (
            $event['status'] === 'ready'
            && $event['sourceHash'] !== $initial['sourceHash']
        ),
        'same-size content edit is observed',
    );
    $assert(
        $modified['metadata']['requests'][0]['declaration']['contentHash']
        !== $initial['metadata']['requests'][0]['declaration']['contentHash'],
        'provenance hash refreshes',
    );

    file_put_contents($extra, "<?php return ['enabled' => true];\n");
    $added = $next(
        static fn (array $event): bool => (
            $event['status'] === 'ready'
            && $event['sourceHash'] !== $modified['sourceHash']
        ),
        'added configuration file is observed',
    );

    unlink($extra);
    $deleted = $next(
        static fn (array $event): bool => $event['status'] === 'ready' && $event['sourceHash'] !== $added['sourceHash'],
        'deleted configuration file is observed',
    );

    unlink($app);
    $removed = $next(
        static fn (array $event): bool => (
            $event['status'] === 'ready'
            && $event['sourceHash'] !== $deleted['sourceHash']
        ),
        'selected configuration file removal is observed',
    );
    $assert(
        $removed['metadata']['requests'][0]['declaration'] === null,
        'removed source does not retain its declaration',
    );

    mkdir($app);
    $fileAsDirectory = $next(
        static fn (array $event): bool => $event['status'] === 'error'
        && in_array('unreadable-source', array_column($event['errors'], 'code'), true),
        'source path replaced with a directory invalidates snapshot',
    );
    $assert($fileAsDirectory['metadata'] === null, 'unreadable source cannot retain metadata');

    rmdir($app);
    file_put_contents($app, "<?php return ['name' => 'restored'];\n");
    $next(
        static fn (array $event): bool => $event['status'] === 'ready',
        'source path recovers after directory replacement',
    );

    unlink($app);
    rmdir($config);
    file_put_contents($config, 'not a directory');
    $directoryAsFile = $next(
        static fn (array $event): bool => $event['status'] === 'error'
        && in_array('unreadable-config-directory', array_column($event['errors'], 'code'), true),
        'configuration directory replaced with a file invalidates snapshot',
    );
    $assert($directoryAsFile['metadata'] === null, 'invalid configuration root cannot retain metadata');

    unlink($config);
    mkdir($config);
    file_put_contents($app, "<?php return ['name' => 'restored'];\n");
    $next(static fn (array $event): bool => $event['status'] === 'ready', 'configuration directory recovers');

    file_put_contents($app, '<?php return [broken');
    $invalid = $next(
        static fn (array $event): bool => $event['status'] === 'error',
        'parse failure invalidates snapshot',
    );
    $assert(
        $invalid['metadata'] === null && in_array('parse-failure', array_column($invalid['errors'], 'code'), true),
        'error contains no stale metadata',
    );

    file_put_contents($app, "<?php return ['name' => 'recovered'];\n");
    $recovered = $next(
        static fn (array $event): bool => $event['status'] === 'ready',
        'syntax recovery replaces error',
    );
    $assert($recovered['metadata']['requests'][0]['confidence'] === 'known-positive', 'recovered declaration is fresh');

    file_put_contents($composer, '{"name":"example/watch-fixture","type":"project"}');
    $next(
        static fn (array $event): bool => (
            $event['status'] === 'ready'
            && $event['sourceHash'] !== $recovered['sourceHash']
        ),
        'Composer metadata change is observed',
    );
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
    clearstatcache(true);
    if (is_dir($app)) {
        rmdir($app);
    }
    foreach ([$extra, $app, $composer, $eventsFile, $stderrFile] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_file($config)) {
        unlink($config);
    }
    if (is_dir($config)) {
        rmdir($config);
    }
    if (is_dir($fixtureRoot)) {
        rmdir($fixtureRoot);
    }
}
