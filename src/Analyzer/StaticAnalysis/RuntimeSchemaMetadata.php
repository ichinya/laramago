<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Owns one runtime snapshot generation; default mode performs no collection. */
final class RuntimeSchemaMetadata
{
    private ?RuntimeSchemaInput $input = null;
    private bool $prepared = false;
    private readonly RuntimeSchemaSelection $selection;
    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(
        private readonly string $root,
        ?string $path = null,
    ) {
        $this->selection = new RuntimeSchemaSelection($path);
    }

    public function enabled(): bool
    {
        return $this->selection->path() !== null;
    }

    public function reset(): void
    {
        $this->input = null;
        $this->prepared = false;
        $this->selection->reset();
        $this->errors = [];
    }

    public function prepare(): void
    {
        if ($this->prepared || !$this->enabled()) {
            return;
        }
        $this->prepared = true;
        try {
            $this->input = RuntimeSchemaInput::load((string) $this->selection->path(), $this->root);
        } catch (RuntimeSchemaCollectionFailure $failure) {
            $this->errors[$failure->diagnostic] = 'The requested runtime metadata snapshot is unavailable, invalid, incompatible, or belongs to another project.';

            return;
        }
        $snapshot = $this->input->snapshot;
        if ($snapshot['errors'] !== [] || $snapshot['truncated']) {
            $this->errors += RuntimeSchemaFailures::fromSnapshot($snapshot);

            return;
        }
        $this->revalidate();
    }

    public function current(): bool
    {
        $this->prepare();
        if (!$this->enabled()) {
            return true;
        }
        if ($this->input === null || $this->errors !== []) {
            return false;
        }
        if (!$this->input->current()) {
            $this->errors['runtime-metadata-source-changed'] = 'Runtime metadata inputs changed; collect a new snapshot before analysis.';

            return false;
        }

        return true;
    }

    /** Recollect before refinement and before final publication. */
    public function revalidate(): bool
    {
        if (!$this->current()) {
            return false;
        }
        if (!$this->enabled()) {
            return true;
        }
        $this->errors += RuntimeSchemaVerifier::check($this->input, $this->root);

        return $this->current();
    }

    public function model(string $class): ?array
    {
        return $this->current() ? $this->input?->model($class) : null;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
