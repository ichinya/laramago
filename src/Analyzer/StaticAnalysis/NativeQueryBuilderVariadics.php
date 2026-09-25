<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;

/** Proves the installed query builder's positional func_get_args() contracts. */
final class NativeQueryBuilderVariadics
{
    private const BUILDER = 'Illuminate\Database\Query\Builder';
    private const SUFFIX = '/laravel/framework/src/illuminate/database/query/builder.php';

    private const BODIES = [
        'select' => <<<'PHP'
            public function select($columns = ['*'])
            {
                $this->columns = [];
                $this->bindings['select'] = [];
                $columns = is_array($columns) ? $columns : func_get_args();
                foreach ($columns as $as => $column) {
                    if (is_string($as) && $this->isQueryable($column)) {
                        $this->selectSub($column, $as);
                    } else {
                        $this->columns[] = $column;
                    }
                }
                return $this;
            }
            PHP,
        'distinct' => <<<'PHP'
            public function distinct()
            {
                $columns = func_get_args();
                if ($columns !== []) {
                    $this->distinct = is_array($columns[0]) || is_bool($columns[0]) ? $columns[0] : $columns;
                } else {
                    $this->distinct = true;
                }
                return $this;
            }
            PHP,
    ];

    public function __construct(private readonly string $root) {}

    public function proves(Codebase $codebase, string $name): bool
    {
        $expected = self::BODIES[$name] ?? '';
        $method = $codebase->getDeclaringMethod(self::BUILDER, $name);
        if ($method === null || strcasecmp($method->identifier->class ?? '', self::BUILDER) !== 0) {
            return false;
        }
        $path = $method->location->file;
        if ($path === null) {
            return false;
        }
        $path = $this->absolutePath($path);
        if (! str_ends_with(strtolower(str_replace('\\', replace: '/', subject: $path)), self::SUFFIX) || ! is_readable($path)) {
            return false;
        }
        $source = file_get_contents($path);
        if ($source === false) {
            return false;
        }
        $span = $method->location->span;
        if ($span->end > strlen($source) || $span->end === $span->start) {
            return false;
        }

        return $this->tokens(substr($source, $span->start, $span->length())) === $this->tokens($expected);
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('~^(?:[A-Za-z]:[/\\\\]|\\\\\\\\)~', $path)
            ? $path
            : $this->root.'/'.$path;
    }

    private function tokens(string $source): string
    {
        $parts = [];
        foreach (token_get_all('<?php '.$source) as $token) {
            if (! is_array($token)) {
                $parts[] = $token;
                continue;
            }
            if (! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], strict: true)) {
                $parts[] = $token[1];
            }
        }

        return implode('', $parts);
    }
}
