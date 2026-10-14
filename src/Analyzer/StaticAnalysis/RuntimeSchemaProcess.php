<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Runtime\RuntimeSchemaSnapshot;
use Throwable;

/** Bounded isolated collector execution, including native Windows timeout handling. */
final class RuntimeSchemaProcess
{
    private const TIMEOUT_SECONDS = 5.0;

    /** @return array<string, mixed> */
    public static function collect(array $snapshot, string $root): array
    {
        $command = self::command($snapshot, $root);
        // Windows anonymous pipe reads remain blocking despite set_blocking(false).
        // File descriptors let the parent enforce its deadline without waiting
        // for the child to produce bytes or close a pipe.
        $files = RuntimeSchemaOutput::open();
        $process = null;
        $pipes = [];
        $exit = null;
        $deadline = hrtime(as_number: true) + (int) (self::TIMEOUT_SECONDS * 1_000_000_000);
        try {
            $process = RuntimeSchemaFiles::attempt(static fn() => proc_open(
                $command,
                [
                    0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                    1 => ['file', $files->stdout, 'w'],
                    2 => ['file', $files->stderr, 'w'],
                ],
                $pipes,
                $root,
                env_vars: null,
                options: ['bypass_shell' => true],
            ));
            if (!is_resource($process)) {
                throw new RuntimeSchemaCollectionFailure('runtime-metadata-collector-failed');
            }
            do {
                $files->checkBounds();
                if (hrtime(as_number: true) > $deadline) {
                    throw new RuntimeSchemaCollectionFailure('runtime-metadata-collector-timeout');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                usleep(10_000);
            } while (true);
            $output = $files->read();
        } finally {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, signal: 9);
                }
                $closed = proc_close($process);
                $exit ??= $closed;
            }

            $files->close();
        }
        return self::result($output, $root, $exit);
    }

    private static function command(array $snapshot, string $root): array
    {
        $command = [
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=0',
            dirname(__DIR__, levels: 3) . '/bin/laramago-runtime',
            '--project-root',
            $root,
        ];
        foreach ($snapshot['plan']['models'] ?? [] as $model) {
            $command[] = '--model';
            $command[] = $model;
        }
        if (is_string($snapshot['plan']['autoload'] ?? null)) {
            $command[] = '--autoload';
            $command[] = $snapshot['plan']['autoload'];
        }

        return $command;
    }

    private static function result(string $output, string $root, ?int $exit): array
    {
        if (!in_array($exit, [0, 1], strict: true)) {
            throw new RuntimeSchemaCollectionFailure('runtime-metadata-collector-failed');
        }
        try {
            $result = RuntimeSchemaSnapshot::decode($output, $root);
        } catch (Throwable) {
            throw new RuntimeSchemaCollectionFailure('runtime-metadata-invalid-response');
        }
        if ($exit === 1 && $result['errors'] === []) {
            throw new RuntimeSchemaCollectionFailure('runtime-metadata-collector-failed');
        }

        return $result;
    }
}
