<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Keeps every callback in one generation on the same explicit opt-in path. */
final class RuntimeSchemaSelection
{
    private bool $resolved = false;
    private ?string $selected = null;

    public function __construct(
        private readonly ?string $path,
    ) {}

    public function reset(): void
    {
        $this->resolved = false;
        $this->selected = null;
    }

    public function path(): ?string
    {
        if ($this->resolved) {
            return $this->selected;
        }
        $this->resolved = true;
        if ($this->path !== null) {
            return $this->selected = $this->path;
        }
        foreach ($_SERVER['argv'] ?? [] as $argument) {
            if (is_string($argument) && str_starts_with($argument, '--runtime-metadata=')) {
                return $this->selected = substr($argument, strlen('--runtime-metadata='));
            }
        }
        $environment = getenv('LARAMAGO_RUNTIME_METADATA');

        return $this->selected = is_string($environment) && $environment !== '' ? $environment : null;
    }
}
