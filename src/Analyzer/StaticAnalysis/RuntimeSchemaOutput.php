<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Owns bounded collector output files, avoiding blocking Windows pipe reads. */
final class RuntimeSchemaOutput
{
    public const MAX_BYTES = 4_194_304;

    private function __construct(
        public readonly string $stdout,
        public readonly string $stderr,
    ) {}

    public static function open(): self
    {
        $stdout = RuntimeSchemaFiles::attempt(static fn() => tempnam(sys_get_temp_dir(), 'laramago-runtime-out-'));
        $stderr = RuntimeSchemaFiles::attempt(static fn() => tempnam(sys_get_temp_dir(), 'laramago-runtime-err-'));
        if (!is_string($stdout) || !is_string($stderr)) {
            RuntimeSchemaFiles::attempt(static fn() => unlink((string) $stdout));
            RuntimeSchemaFiles::attempt(static fn() => unlink((string) $stderr));
            throw new RuntimeSchemaCollectionFailure('runtime-metadata-collector-failed');
        }

        return new self($stdout, $stderr);
    }

    public function checkBounds(): void
    {
        foreach ([$this->stdout, $this->stderr] as $path) {
            clearstatcache(true, $path);
            $size = RuntimeSchemaFiles::attempt(static fn() => filesize($path));
            if (!is_int($size)) {
                throw new RuntimeSchemaCollectionFailure('runtime-metadata-collector-failed');
            }
            if ($size > self::MAX_BYTES) {
                throw new RuntimeSchemaCollectionFailure('runtime-metadata-collector-output-limit');
            }
        }
    }

    public function read(): string
    {
        $this->checkBounds();
        $output = RuntimeSchemaFiles::attempt(fn() => file_get_contents(
            $this->stdout,
            false,
            null,
            0,
            self::MAX_BYTES + 1,
        ));
        if (!is_string($output) || strlen($output) > self::MAX_BYTES) {
            throw new RuntimeSchemaCollectionFailure('runtime-metadata-collector-output-limit');
        }

        return $output;
    }

    public function close(): void
    {
        RuntimeSchemaFiles::attempt(fn() => unlink($this->stdout));
        RuntimeSchemaFiles::attempt(fn() => unlink($this->stderr));
    }
}
