<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Mutable collection record; errors are redacted, structured, and deduplicated. */
final class CollectionState
{
    public array $snapshot;
    public array $sets;

    public function __construct(
        public readonly CollectionPlan $plan,
    ) {
        $this->snapshot = [
            'schemaVersion' => 1,
            'projectRoot' => $plan->root,
            'scope' => ['kind' => 'runtime-schema', 'evidence' => 'evaluated-schema'],
            'plan' => ['models' => $plan->models, 'autoload' => $plan->resolvedAutoload ?? $plan->autoload],
            'models' => [],
            'sources' => [],
            'sourceSets' => [],
            'errors' => [],
            'truncated' => false,
        ];
        $this->sets = [
            ['directory' => $plan->root . '/config', 'pattern' => '*.php'],
            ['directory' => $plan->root, 'pattern' => '.env*'],
            ['directory' => $plan->root . '/bootstrap', 'pattern' => '*.php'],
            ['directory' => $plan->root . '/bootstrap/cache', 'pattern' => '*.php'],
            ['paths' => [$plan->root . '/composer.json', $plan->root . '/composer.lock', $plan->autoload]],
        ];
    }

    public function error(string $code, string $message): void
    {
        $error = ['code' => $code, 'file' => null, 'message' => $message];
        if (!in_array($error, $this->snapshot['errors'], true)) {
            $this->snapshot['errors'][] = $error;
        }
    }
}
