<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use RuntimeException;

/** Normalize the explicit selection before application code is loaded. */
final class CollectionPlan
{
    public readonly string $root;
    public readonly string $autoload;
    public readonly ?string $resolvedAutoload;
    public readonly array $models;

    public function __construct(string $root, ?string $autoload, array $models)
    {
        $this->root = RuntimeSchemaSnapshot::canonical($root) ?? throw new RuntimeException('Invalid project root.');
        if (!is_dir($this->root)) {
            throw new RuntimeException('Invalid project root.');
        }
        $models = array_values(array_unique($models));
        sort($models, SORT_STRING);
        if ($models === [] || count($models) > RuntimeSchemaSnapshot::MAX_MODELS) {
            throw new RuntimeException('Invalid runtime model selection.');
        }
        foreach ($models as $model) {
            if (
                !is_string($model)
                || strlen($model) > 256
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $model) !== 1
            ) {
                throw new RuntimeException('Invalid runtime model selection.');
            }
        }
        $this->models = $models;
        $autoload ??= CollectionPaths::defaultAutoload($this->root);
        if (!CollectionPaths::absolute($autoload)) {
            $autoload = $this->root . '/' . $autoload;
        }
        $this->autoload = str_replace('\\', '/', $autoload);
        $this->resolvedAutoload = RuntimeSchemaSnapshot::canonical($this->autoload);
    }
}
