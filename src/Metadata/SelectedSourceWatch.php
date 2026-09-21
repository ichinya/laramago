<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use InvalidArgumentException;
use Throwable;

/** Polls an explicit bounded source selection; each event replaces the previous snapshot. */
final class SelectedSourceWatch
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;

    private readonly string $projectRoot;

    /** @var list<string> */
    private readonly array $files;

    /** @var array<string, true> */
    private readonly array $allowedExtensions;

    private ?string $lastSourceHash = null;
    private ?string $lastEventKey = null;
    private bool $lastReady = false;
    private int $revision = 0;

    /**
     * @param array<array-key, mixed> $files Project-relative source paths.
     * @param array<array-key, mixed> $allowedExtensions Lowercase extensions without a leading dot.
     */
    public function __construct(string $projectRoot, array $files, array $allowedExtensions)
    {
        clearstatcache(true);
        $resolvedRoot = realpath($projectRoot);
        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new InvalidArgumentException('Project root must be an existing directory.');
        }
        if (! array_is_list($files)) {
            throw new InvalidArgumentException('Selected watch sources must be a list.');
        }
        $selectedFiles = array_map(static function (mixed $file): string {
            if (! is_string($file)) {
                throw new InvalidArgumentException('Selected watch sources must be strings.');
            }

            return $file;
        }, $files);
        if ($allowedExtensions === [] || ! array_is_list($allowedExtensions)) {
            throw new InvalidArgumentException('Allowed source extensions must be a nonempty list.');
        }
        $extensionList = array_map(static function (mixed $extension): string {
            if (! is_string($extension)) {
                throw new InvalidArgumentException('Allowed source extensions must be lowercase names without dots.');
            }

            return $extension;
        }, $allowedExtensions);
        $extensions = [];
        foreach ($extensionList as $extension) {
            if (
                $extension === ''
                || $extension !== strtolower($extension)
                || ! preg_match('/^[a-z0-9]+$/D', $extension)
            ) {
                throw new InvalidArgumentException('Allowed source extensions must be lowercase names without dots.');
            }
            $extensions[$extension] = true;
        }

        $this->projectRoot = str_replace('\\', '/', $resolvedRoot);
        $this->files = $selectedFiles;
        $this->allowedExtensions = $extensions;
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
                    'message' => 'Selected sources changed during refresh; retrying on the next poll.',
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
        $prefix = rtrim($this->projectRoot, '/').'/';
        $files = $this->files;
        $errors = [];
        if (count($files) > self::MAX_FILES) {
            $errors[] = self::error('too-many-sources', null);
            $files = array_slice($files, 0, self::MAX_FILES);
        }

        $context = hash_init('sha256');
        $totalBytes = 0;
        $seen = [];
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', $file);
            hash_update($context, $relative."\0");
            if (
                $relative === ''
                || str_starts_with($relative, '/')
                || str_contains($relative, ':')
                || str_contains($relative, "\0")
                || in_array('..', explode('/', $relative), true)
            ) {
                hash_update($context, "invalid\0");
                $errors[] = self::error('invalid-source', $file);
                continue;
            }
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (! isset($this->allowedExtensions[$extension])) {
                hash_update($context, "unsupported-extension\0");
                $errors[] = self::error('unsupported-source-format', $file);
                continue;
            }

            $requestedPath = $prefix.$relative;
            clearstatcache(true, $requestedPath);
            $path = realpath($requestedPath);
            if ($path === false) {
                hash_update($context, "missing\0");
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $path = str_replace('\\', '/', $path);
            $contained = DIRECTORY_SEPARATOR === '\\'
                ? str_starts_with(strtolower($path), strtolower($prefix))
                : str_starts_with($path, $prefix);
            if (! $contained) {
                hash_update($context, "outside-root\0");
                $errors[] = self::error('invalid-source', $file);
                continue;
            }
            if (! isset($this->allowedExtensions[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
                hash_update($context, "unsupported-resolved-extension\0");
                $errors[] = self::error('unsupported-source-format', $file);
                continue;
            }
            if (! is_file($path)) {
                hash_update($context, "not-file\0");
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }

            $identity = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
            hash_update($context, $path."\0");
            if (isset($seen[$identity])) {
                hash_update($context, "duplicate\0");
                continue;
            }
            $seen[$identity] = true;
            $readLimit = min(self::MAX_FILE_BYTES, self::MAX_TOTAL_BYTES - $totalBytes) + 1;
            $contents = @file_get_contents($path, false, null, 0, $readLimit);
            if ($contents === false) {
                hash_update($context, "unreadable\0");
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $size = strlen($contents);
            $totalBytes += $size;
            if ($size > self::MAX_FILE_BYTES || $totalBytes > self::MAX_TOTAL_BYTES) {
                hash_update($context, "too-large\0".$size."\0".$contents."\0");
                $errors[] = self::error('source-limit', $file);
                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    break;
                }
                continue;
            }
            hash_update($context, $size."\0".$contents."\0");
        }

        return ['hash' => hash_final($context), 'errors' => $errors];
    }

    /** @return array{code: string, file: ?string, message: string} */
    private static function error(string $code, ?string $file): array
    {
        return [
            'code' => $code,
            'file' => $file,
            'message' => match ($code) {
                'too-many-sources' => 'Selected source watch file limit exceeded.',
                'source-limit' => 'Selected source watch byte limit exceeded.',
                'unsupported-source-format' => 'Selected source extension is not allowed.',
                'invalid-source' => 'Selected source must be a project-relative file contained in the project.',
                default => 'Unable to read selected watch source.',
            },
        ];
    }
}
