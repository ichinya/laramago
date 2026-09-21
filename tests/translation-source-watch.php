<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\JsonTranslationMetadataExport;
use Ichinya\Laramago\Metadata\SelectedSourceWatch;
use Ichinya\Laramago\Metadata\TranslationMetadataExport;

require dirname(__DIR__).'/vendor/autoload.php';

$packageRoot = dirname(__DIR__);
$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-translation-watch-'.bin2hex(random_bytes(6));
$lang = $fixture.'/lang';
$vendor = $fixture.'/vendor';
$phpFile = $lang.'/messages.php';
$jsonFile = $lang.'/en.json';
$checks = 0;
$links = [];

$check = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($label);
    }
};

$run = static function (array $command) use ($packageRoot): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $packageRoot);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start metadata CLI validation process.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [$exit, $stdout, $stderr];
};

$startWatch = static function (string $kind, string $source, string $suffix) use ($fixture, $packageRoot): array {
    $eventsFile = $fixture.'/events-'.$suffix.'.jsonl';
    $stderrFile = $fixture.'/stderr-'.$suffix.'.log';
    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            $packageRoot.'/bin/laramago-metadata',
            '--project-root',
            $fixture,
            '--kind',
            $kind,
            '--source',
            $source,
            '--watch',
            '--interval-ms',
            '50',
        ],
        [0 => ['pipe', 'r'], 1 => ['file', $eventsFile, 'w'], 2 => ['file', $stderrFile, 'w']],
        $pipes,
        $packageRoot,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start translation metadata watch process.');
    }
    fclose($pipes[0]);

    return [$process, $eventsFile, $stderrFile];
};

$stopWatch = static function (&$process): void {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    $process = null;
};

$watchScenario = static function (
    string $kind,
    string $relative,
    string $path,
    string $initialSource,
    string $modifiedSource,
    string $brokenSource,
    string $recoveredSource,
) use ($check, $fixture, $startWatch, $stopWatch): void {
    $suffix = str_replace('-', '_', $kind);
    [$process, $eventsFile, $stderrFile] = $startWatch($kind, $relative, $suffix);
    $buffer = '';
    $offset = 0;
    $revision = 0;
    $read = static function () use ($eventsFile, &$buffer, &$offset): string {
        $contents = file_get_contents($eventsFile);
        if ($contents === false || strlen($contents) < $offset) {
            throw new RuntimeException('Cannot read translation watch output.');
        }
        $chunk = substr($contents, $offset);
        $buffer .= $chunk;
        $offset = strlen($contents);

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
                    throw new RuntimeException('Unexpected translation watch event: '.$line);
                }
                if (($event['revision'] ?? null) <= $revision) {
                    throw new RuntimeException('Translation watch revisions must increase.');
                }
                $revision = $event['revision'];
                if ($accept($event)) {
                    return $event;
                }
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                throw new RuntimeException('Translation watch exited: '.file_get_contents($stderrFile));
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Timed out waiting for '.$label.': '.file_get_contents($stderrFile));
    };

    try {
        file_put_contents($path, $initialSource);
        clearstatcache(true, $path);
        $initialMtime = filemtime($path);
        if ($initialMtime === false) {
            throw new RuntimeException('Cannot read selected translation source mtime.');
        }
        $initial = $next(
            static fn (array $event): bool => (
                $event['status'] === 'ready'
                && ($event['metadata']['declarations'][0]['name'] ?? null) === 'first'
            ),
            $kind.' initial snapshot',
        );
        $check(
            $initial['metadata']['projectRoot'] === str_replace('\\', '/', realpath($fixture))
            && $initial['metadata']['errors'] === [],
            $kind.' emits a complete initial replacement snapshot.',
        );
        usleep(180_000);
        $check($read() === '' && $buffer === '', $kind.' idle polls emit no duplicate events.');

        file_put_contents($path, $modifiedSource);
        touch($path, $initialMtime);
        clearstatcache(true, $path);
        $check(filemtime($path) === $initialMtime, $kind.' fixture preserves mtime across the edit.');
        $modified = $next(
            static fn (array $event): bool => (
                $event['status'] === 'ready'
                && ($event['metadata']['declarations'][0]['name'] ?? null) === 'other'
            ),
            $kind.' same-mtime edit',
        );
        $check(
            $modified['sourceHash'] !== $initial['sourceHash']
            && $modified['metadata']['declarations'][0]['contentHash']
                !== $initial['metadata']['declarations'][0]['contentHash'],
            $kind.' detects exact-byte changes even when mtime is unchanged.',
        );

        unlink($path);
        $deleted = $next(
            static fn (array $event): bool => $event['status'] === 'error'
            && in_array('unreadable-source', array_column($event['errors'], 'code'), true),
            $kind.' deletion',
        );
        $check($deleted['metadata'] === null, $kind.' deletion clears stale metadata.');

        file_put_contents($path, $brokenSource);
        $broken = $next(
            static fn (array $event): bool => $event['status'] === 'error'
            && in_array('parse-failure', array_column($event['errors'], 'code'), true),
            $kind.' parse failure',
        );
        $check($broken['metadata'] === null, $kind.' parse failure clears stale metadata.');
        usleep(180_000);
        $check($read() === '' && $buffer === '', $kind.' stable parse error emits no duplicate event.');

        file_put_contents($path, $recoveredSource);
        $recovered = $next(
            static fn (array $event): bool => (
                $event['status'] === 'ready'
                && ($event['metadata']['declarations'][0]['name'] ?? null) === 'fresh'
            ),
            $kind.' recovery',
        );
        $check($recovered['metadata']['errors'] === [], $kind.' recovery publishes fresh metadata.');
    } finally {
        $stopWatch($process);
    }
};

$initialError = static function (string $kind, string $source, string $suffix) use (
    $check,
    $startWatch,
    $stopWatch,
): void {
    [$process, $eventsFile, $stderrFile] = $startWatch($kind, $source, $suffix);
    try {
        $deadline = microtime(true) + 8;
        do {
            $contents = file_get_contents($eventsFile);
            if ($contents !== false && str_contains($contents, "\n")) {
                $event = json_decode(strtok($contents, "\n"), true, 512, JSON_THROW_ON_ERROR);
                $check(
                    $event['status'] === 'error'
                    && $event['metadata'] === null
                    && array_column($event['errors'], 'code') === ['unsupported-source-format'],
                    $kind.' CLI enforces its source extension before export.',
                );

                return;
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                throw new RuntimeException('Extension watch exited: '.file_get_contents($stderrFile));
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Timed out waiting for extension watch error.');
    } finally {
        $stopWatch($process);
    }
};

try {
    mkdir($lang, 0777, true);
    mkdir($vendor);
    file_put_contents($fixture.'/.env', 'TRANSLATION_WATCH_SECRET=ACTUAL_ENV_VALUE');
    file_put_contents(
        $vendor.'/autoload.php',
        '<?php file_put_contents(__DIR__."/autoload-executed", "yes"); throw new RuntimeException("autoloaded");',
    );
    file_put_contents(
        $fixture.'/composer.json',
        '{"autoload":{"files":["vendor/autoload.php"]}}',
    );

    $phpInitial = "<?php return ['first' => env('TRANSLATION_WATCH_SECRET')];\n";
    $phpModified = "<?php return ['other' => env('TRANSLATION_WATCH_SECRET')];\n";
    $phpRecovered = "<?php return ['fresh' => env('TRANSLATION_WATCH_SECRET')];\n";
    $jsonInitial = "{\"first\":\"PRIVATE_JSON_VALUE\"}\n";
    $jsonModified = "{\"other\":\"PRIVATE_JSON_VALUE\"}\n";
    $jsonRecovered = "{\"fresh\":\"PRIVATE_JSON_VALUE\"}\n";

    $watchScenario(
        'translations',
        'lang/messages.php',
        $phpFile,
        $phpInitial,
        $phpModified,
        "<?php return ['broken' => ;\n",
        $phpRecovered,
    );
    $watchScenario(
        'translations-json',
        'lang/en.json',
        $jsonFile,
        $jsonInitial,
        $jsonModified,
        "{\"broken\": }\n",
        $jsonRecovered,
    );

    $check(! is_file($vendor.'/autoload-executed'), 'Translation watches do not load the application autoloader.');
    foreach (glob($fixture.'/events-*.jsonl') ?: [] as $eventFile) {
        $events = file_get_contents($eventFile);
        $check(
            $events !== false
            && ! str_contains($events, 'ACTUAL_ENV_VALUE')
            && ! str_contains($events, 'PRIVATE_JSON_VALUE'),
            'Translation watch output excludes translation values and actual environment values.',
        );
    }

    file_put_contents($phpFile, $phpRecovered);
    file_put_contents($jsonFile, $jsonRecovered);
    $initialError('translations', 'lang/en.json', 'php_rejects_json');
    $initialError('translations-json', 'lang/messages.php', 'json_rejects_php');

    foreach (['translation-placeholders', 'environment-references', 'environment-duplicates'] as $kind) {
        [$exit, $stdout, $stderr] = $run([
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            $packageRoot.'/bin/laramago-metadata',
            '--project-root',
            $fixture,
            '--kind',
            $kind,
            '--source',
            $kind === 'translation-placeholders' ? 'lang/messages.php' : '.env',
            '--watch',
        ]);
        $check(
            $exit === 2
            && $stdout === ''
            && str_contains($stderr, '--watch supports configuration, route, and translation source metadata only.'),
            $kind.' remains excluded from watch mode.',
        );
    }

    $phpWatcherCalled = false;
    $phpWrong = (new SelectedSourceWatch($fixture, ['lang/en.json'], ['php']))->refresh(
        static function () use (&$phpWatcherCalled): array {
            $phpWatcherCalled = true;

            return [];
        },
    );
    $jsonWatcherCalled = false;
    $jsonWrong = (new SelectedSourceWatch($fixture, ['lang/messages.php'], ['json']))->refresh(
        static function () use (&$jsonWatcherCalled): array {
            $jsonWatcherCalled = true;

            return [];
        },
    );
    $check(
        ! $phpWatcherCalled && $phpWrong['errors'][0]['code'] === 'unsupported-source-format',
        'PHP translation watch rejects JSON before invoking the exporter.',
    );
    $check(
        ! $jsonWatcherCalled && $jsonWrong['errors'][0]['code'] === 'unsupported-source-format',
        'JSON translation watch rejects PHP before invoking the exporter.',
    );

    $firstTarget = $lang.'/first.php';
    $secondTarget = $lang.'/second.php';
    $currentLink = $lang.'/current.php';
    file_put_contents($firstTarget, "<?php return ['same' => 'value'];\n");
    file_put_contents($secondTarget, "<?php return ['same' => 'value'];\n");
    if (@symlink($firstTarget, $currentLink)) {
        $links[] = $currentLink;
        $exporter = new TranslationMetadataExport;
        $retargetWatch = new SelectedSourceWatch($fixture, ['lang/current.php'], ['php']);
        $before = $retargetWatch->refresh(
            static fn (): array => $exporter->export($fixture, ['lang/current.php']),
        );
        unlink($currentLink);
        array_pop($links);
        if (! @symlink($secondTarget, $currentLink)) {
            throw new RuntimeException('Cannot retarget supported source symlink.');
        }
        $links[] = $currentLink;
        $after = $retargetWatch->refresh(
            static fn (): array => $exporter->export($fixture, ['lang/current.php']),
        );
        $check(
            $after !== null
            && $before['sourceHash'] !== $after['sourceHash']
            && $before['metadata']['declarations'][0]['file'] !== $after['metadata']['declarations'][0]['file'],
            'Same-content symlink retarget refreshes canonical file provenance.',
        );

        $phpAlias = $lang.'/json-target.php';
        $jsonAlias = $lang.'/php-target.json';
        if (@symlink($jsonFile, $phpAlias) && @symlink($phpFile, $jsonAlias)) {
            $links[] = $phpAlias;
            $links[] = $jsonAlias;
            $called = false;
            $phpAliasEvent = (new SelectedSourceWatch($fixture, ['lang/json-target.php'], ['php']))->refresh(
                static function () use (&$called): array {
                    $called = true;

                    return [];
                },
            );
            $jsonAliasEvent = (new SelectedSourceWatch($fixture, ['lang/php-target.json'], ['json']))->refresh(
                static function () use (&$called): array {
                    $called = true;

                    return [];
                },
            );
            $jsonExporterEvent = (new JsonTranslationMetadataExport)->export($fixture, ['lang/php-target.json']);
            $check(
                ! $called
                && $phpAliasEvent['errors'][0]['code'] === 'unsupported-source-format'
                && $jsonAliasEvent['errors'][0]['code'] === 'unsupported-source-format',
                'Resolved source extensions are rejected before watch reads or exports.',
            );
            $check(
                $jsonExporterEvent['errors'][0]['code'] === 'unsupported-source-format',
                'JSON exporter rejects a selected alias resolving to a non-JSON file.',
            );
        }
    }
} finally {
    foreach ($links as $link) {
        if (is_link($link)) {
            unlink($link);
        }
    }
    foreach (glob($lang.'/*') ?: [] as $file) {
        if (is_file($file) || is_link($file)) {
            unlink($file);
        }
    }
    foreach (glob($vendor.'/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (glob($fixture.'/events-*.jsonl') ?: [] as $file) {
        unlink($file);
    }
    foreach (glob($fixture.'/stderr-*.log') ?: [] as $file) {
        unlink($file);
    }
    foreach ([$fixture.'/.env', $fixture.'/composer.json'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($lang)) {
        rmdir($lang);
    }
    if (is_dir($vendor)) {
        rmdir($vendor);
    }
    if (is_dir($fixture)) {
        rmdir($fixture);
    }
}

echo "Translation source watch: {$checks} checks passed.\n";
