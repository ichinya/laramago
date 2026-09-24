<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;

/** Proves the installed trait's full only() body without executing application code. */
final class NativeRequestOnly
{
    private const REQUEST = 'Illuminate\Http\Request';
    private const TRAIT = 'Illuminate\Support\Traits\InteractsWithData';
    private const SUFFIX = '/laravel/framework/src/illuminate/support/traits/interactswithdata.php';

    private const BODY = <<<'PHP'
        public function only($keys)
        {
            $results = [];
            $data = $this->all();
            $placeholder = new stdClass;
            foreach (is_array($keys) ? $keys : func_get_args() as $key) {
                $value = data_get($data, $key, $placeholder);
                if ($value !== $placeholder) {
                    Arr::set($results, $key, $value);
                }
            }
            return $results;
        }
        PHP;

    public function __construct(private readonly string $root) {}

    public function proves(Codebase $codebase): bool
    {
        $method = $codebase->getDeclaringMethod(self::REQUEST, 'only');
        if ($method === null || strcasecmp($method->identifier->class ?? '', self::TRAIT) !== 0) {
            return false;
        }
        $path = $method->location->file;
        if ($path === null) {
            return false;
        }
        $path = $this->absolutePath($path);
        if (! str_ends_with(strtolower(str_replace('\\', '/', $path)), self::SUFFIX) || ! is_readable($path)) {
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

        return $this->tokens(substr($source, $span->start, $span->length())) === $this->tokens(self::BODY);
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
            if (! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $parts[] = $token[1];
            }
        }

        return implode('', $parts);
    }
}
