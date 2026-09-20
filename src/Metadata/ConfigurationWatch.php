<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use InvalidArgumentException;
use Throwable;

/** Polls only conventional configuration sources; each event replaces the previous snapshot. */
final class ConfigurationWatch
{
    private const MAX_FILES = 512;
    private const MAX_FILE_BYTES = 4 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 32 * 1024 * 1024;

    private ?string $lastSourceHash = null;
    private ?string $lastEventKey = null;
    private bool $lastReady = false;
    private int $revision = 0;

    public function __construct(
        private readonly string $projectRoot,
    ) {
        if (! is_dir($projectRoot)) {
            throw new InvalidArgumentException('Project root must be an existing directory.');
        }
    }

    /**
     * Perform one poll. A null result means the published snapshot has not changed.
     *
     * @param callable(): ?array<string, mixed> $export
     * @return array{event: string, revision: int, sourceHash: string, status: string, metadata: ?array, errors: array}|null
     */
    public function refresh(callable $export): ?array
    {
        $before = $this->scan();
        if ($before['errors'] === [] && $this->lastReady && $before['hash'] === $this->lastSourceHash) {
            return null;
        }

        $metadata = null;
        $errors = $before['errors'];
        if ($errors === []) {
            try {
                $metadata = $export();
                if ($metadata === null) {
                    throw new \UnexpectedValueException('Exporter returned no metadata.');
                }
                $errors = is_array($metadata['errors'] ?? null) ? $metadata['errors'] : [];
                if (($metadata['truncated'] ?? false) === true) {
                    $errors[] = [
                        'code' => 'truncated-export',
                        'file' => null,
                        'message' => 'Static metadata export was truncated.',
                    ];
                }
            } catch (Throwable) {
                $errors = [[
                    'code' => 'export-failure',
                    'file' => null,
                    'message' => 'Static metadata export failed.',
                ]];
            }

            $after = $this->scan();
            if ($after['hash'] !== $before['hash'] || $after['errors'] !== []) {
                $errors = $after['errors'];
                $errors[] = [
                    'code' => 'source-changed-during-refresh',
                    'file' => null,
                    'message' => 'Configuration sources changed during refresh; retrying on the next poll.',
                ];
                $before = $after;
            }
        }

        $ready = $errors === [];
        $this->lastReady = $ready;
        $this->lastSourceHash = $before['hash'];
        $eventKey = $before['hash'].'|'.($ready ? 'ready' : 'error').'|'.hash('sha256', serialize($errors));
        if ($eventKey === $this->lastEventKey) {
            return null;
        }
        $this->lastEventKey = $eventKey;

        return [
            'event' => 'snapshot',
            'revision' => ++$this->revision,
            'sourceHash' => $before['hash'],
            'status' => $ready ? 'ready' : 'error',
            'metadata' => $ready ? $metadata : null,
            'errors' => $errors,
        ];
    }

    /**
     * @param callable(): ?array<string, mixed> $export
     * @param callable(array<string, mixed>): void $emit
     */
    public function run(callable $export, callable $emit, int $intervalMilliseconds = 500): void
    {
        if ($intervalMilliseconds < 50 || $intervalMilliseconds > 60_000) {
            throw new InvalidArgumentException('Watch interval must be between 50 and 60000 milliseconds.');
        }

        while (true) {
            $event = $this->refresh($export);
            if ($event !== null) {
                $emit($event);
            }
            usleep($intervalMilliseconds * 1000);
        }
    }

    /** @return array{hash: string, errors: list<array{code: string, file: ?string, message: string}>} */
    private function scan(): array
    {
        clearstatcache(true);
        $root = rtrim($this->projectRoot, '/\\');
        $config = $root.'/config';
        $files = ['composer.json', 'composer.lock'];
        $errors = [];
        if (file_exists($config) && ! is_dir($config)) {
            $errors[] = $this->error('unreadable-config-directory', $config, 'Configuration path is not a directory.');
        } elseif (is_dir($config)) {
            $entries = @scandir($config);
            if ($entries === false) {
                $errors[] = $this->error(
                    'unreadable-config-directory',
                    $config,
                    'Cannot list configuration directory.',
                );
            } else {
                foreach ($entries as $entry) {
                    if (str_ends_with($entry, '.php')) {
                        $files[] = 'config/'.$entry;
                    }
                }
            }
        }

        sort($files, SORT_STRING);
        if (count($files) > (self::MAX_FILES + 2)) {
            $errors[] = $this->error('too-many-sources', $config, 'Configuration watch source limit exceeded.');
            $files = array_slice($files, 0, self::MAX_FILES + 2);
        }

        $context = hash_init('sha256');
        $totalBytes = 0;
        foreach ($files as $relative) {
            $path = $root.'/'.$relative;
            hash_update($context, $relative."\0");
            if (! file_exists($path) && ! is_link($path)) {
                hash_update($context, "missing\0");
                continue;
            }
            if (! is_file($path)) {
                hash_update($context, "not-file\0");
                $errors[] = $this->error('unreadable-source', $path, 'Watch source is not a readable file.');
                continue;
            }
            $contents = @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1);
            if ($contents === false) {
                hash_update($context, "unreadable\0");
                $errors[] = $this->error('unreadable-source', $path, 'Cannot read watch source.');
                continue;
            }
            $size = strlen($contents);
            if ($size > self::MAX_FILE_BYTES || ($totalBytes += $size) > self::MAX_TOTAL_BYTES) {
                hash_update($context, "too-large\0".$size."\0");
                $errors[] = $this->error('source-limit', $path, 'Configuration watch source byte limit exceeded.');
                continue;
            }
            hash_update($context, $size."\0".$contents."\0");
        }

        return ['hash' => hash_final($context), 'errors' => $errors];
    }

    /** @return array{code: string, file: ?string, message: string} */
    private function error(string $code, ?string $file, string $message): array
    {
        return ['code' => $code, 'file' => $file, 'message' => $message];
    }
}
