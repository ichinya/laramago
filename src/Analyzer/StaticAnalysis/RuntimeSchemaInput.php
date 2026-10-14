<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Runtime\RuntimeSchemaSnapshot;
use Throwable;

/** An immutable decoded snapshot and the digest of the exact bytes decoded. */
final class RuntimeSchemaInput
{
    private readonly array $models;

    private function __construct(
        public readonly array $snapshot,
        private readonly string $path,
        private readonly string $digest,
    ) {
        $this->models = array_change_key_case($snapshot['models'], CASE_LOWER);
    }

    public static function load(string $path, string $root): self
    {
        if (preg_match('~\A(?:[A-Za-z]:[/\\\\]|/|\\\\\\\\)~', $path) !== 1) {
            throw new RuntimeSchemaCollectionFailure('runtime-metadata-unavailable');
        }
        $contents = self::read($path);
        if (!is_string($contents)) {
            throw new RuntimeSchemaCollectionFailure('runtime-metadata-unavailable');
        }
        try {
            $snapshot = RuntimeSchemaSnapshot::decode($contents, $root);
        } catch (Throwable) {
            throw new RuntimeSchemaCollectionFailure('runtime-metadata-invalid');
        }

        return new self($snapshot, $path, hash('sha256', $contents));
    }

    public function current(): bool
    {
        $contents = self::read($this->path);

        return (
            is_string($contents)
            && hash('sha256', $contents) === $this->digest
            && RuntimeSchemaSnapshot::currentInputs($this->snapshot)
        );
    }

    public function model(string $class): ?array
    {
        return $this->models[strtolower(ltrim($class, characters: '\\'))] ?? null;
    }

    private static function read(string $path): ?string
    {
        $contents = RuntimeSchemaFiles::attempt(static fn() => file_get_contents(
            $path,
            use_include_path: false,
            context: null,
            offset: 0,
            length: RuntimeSchemaSnapshot::MAX_BYTES + 1,
        ));

        return is_string($contents) ? $contents : null;
    }
}
