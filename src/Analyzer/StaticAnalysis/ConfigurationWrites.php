<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Possible source writes invalidate reads; write values are never inferred. */
final class ConfigurationWrites
{
    /** @var array<string, true> */
    private array $keys = [];
    private bool $unknown = false;

    public function __construct(private readonly PhpSource $source, Codebase $codebase)
    {
        $paths = [];
        // Include scripts and closure-only test/bootstrap files without symbol metadata.
        foreach (glob($source->path('*.php')) ?: [] as $path) {
            $paths[$path] = true;
        }
        foreach (['app', 'bootstrap', 'routes', 'tests'] as $directory) {
            $path = $source->path($directory);
            if (! is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $paths[$file->getPathname()] = true;
                }
            }
        }
        foreach ($codebase->getClassLikeNames() as $name) {
            $path = $codebase->getClassLike($name)?->location->file;
            if ($path !== null && $this->applicationPath($path)) {
                $paths[$path] = true;
            }
        }
        foreach ($codebase->getFunctionNames() as $name) {
            $path = $codebase->getFunction($name)?->location->file;
            if ($path !== null && $this->applicationPath($path)) {
                $paths[$path] = true;
            }
        }
        foreach (array_keys($paths) as $path) {
            $nodes = $source->read($path);
            if ($nodes === null) {
                $this->unknown = true;
                continue;
            }
            $calls = (new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall);
            $directReceivers = [];
            foreach ($calls as $call) {
                if ($call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\FuncCall) {
                    $directReceivers[spl_object_id($call->var)] = true;
                }
            }
            foreach ($calls as $call) {
                if (! $call instanceof Node\Expr\FuncCall && ! $call instanceof Node\Expr\StaticCall && ! $call instanceof Node\Expr\MethodCall) {
                    continue;
                }
                if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name
                    && strcasecmp($call->name->toString(), 'config') === 0 && $call->args === []
                    && ! isset($directReceivers[spl_object_id($call)])) {
                    // The mutable singleton escapes; later calls through aliases or
                    // callbacks cannot be certified by a return-type SDK hook.
                    $this->unknown = true;
                }
                if ($call instanceof Node\Expr\FuncCall && self::repositoryCall($call)
                    && ! isset($directReceivers[spl_object_id($call)])) {
                    $this->unknown = true;
                }
                $this->call($call);
            }
        }
    }

    private function applicationPath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $this->source->root), '/').'/';

        return str_starts_with($path, $root) && ! str_starts_with($path, $root.'vendor/');
    }

    public function affects(string $key): bool
    {
        if ($this->unknown) {
            return true;
        }
        foreach ($this->keys as $written => $_) {
            if ($key === $written || str_starts_with($key, $written.'.') || str_starts_with($written, $key.'.')) {
                return true;
            }
        }

        return false;
    }

    private function call(Node\Expr\FuncCall|Node\Expr\StaticCall|Node\Expr\MethodCall $call): void
    {
        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && strcasecmp($call->name->toString(), 'config') === 0) {
            if ($call->isFirstClassCallable()) {
                $this->unknown = true;
                return;
            }
            $key = PhpSource::argument($call->args, 0, 'key');
            if ($key instanceof Node\Expr\Array_) {
                $this->write($key);
            } elseif ($key !== null && ! self::stringExpression($key) && count($call->args) === 1) {
                // A dynamic single argument may be the helper's array writer overload.
                $this->unknown = true;
            } elseif ($call->args !== [] && $key === null) {
                $this->unknown = true;
            }
            return;
        }
        $repository = $call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\FuncCall
            && self::repositoryCall($call->var);
        $facade = $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name
            && strcasecmp($call->class->toString(), 'Illuminate\\Support\\Facades\\Config') === 0;
        if ((! $repository && ! $facade) || ! $call->name instanceof Node\Identifier
            || ! in_array(strtolower($call->name->toString()), ['set', 'push', 'prepend', 'offsetset', 'offsetunset'], true)) {
            return;
        }
        if ($call->isFirstClassCallable()) {
            $this->unknown = true;
            return;
        }
        $this->write(PhpSource::argument($call->args, 0, 'key'));
    }

    private function write(?Node\Expr $key): void
    {
        if ($key instanceof Node\Scalar\String_ && $key->value !== '') {
            $this->keys[$key->value] = true;
            return;
        }
        if ($key instanceof Node\Expr\Array_) {
            foreach ($key->items as $item) {
                if ($item->unpack || $item->key === null) {
                    $this->unknown = true;
                } else {
                    $this->write($item->key);
                }
            }
            return;
        }
        // A dynamic suffix can only affect the literal namespace prefix. Never
        // infer the write value or use a partial segment as an exact key.
        if ($key instanceof Node\Expr\BinaryOp\Concat) {
            $prefix = self::prefix($key);
            $separator = strrpos($prefix, '.');
            if ($separator !== false && $separator > 0) {
                $this->keys[substr($prefix, 0, $separator)] = true;
                return;
            }
        }
        $this->unknown = true;
    }

    private static function stringExpression(Node\Expr $node): bool
    {
        return $node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\InterpolatedString
            || $node instanceof Node\Expr\Cast\String_ || $node instanceof Node\Expr\BinaryOp\Concat;
    }

    private static function repositoryCall(Node\Expr\FuncCall $call): bool
    {
        if (! $call->name instanceof Node\Name || $call->isFirstClassCallable()) {
            return false;
        }
        $name = strtolower($call->name->toString());
        if ($name === 'config') {
            return $call->args === [];
        }
        $abstract = PhpSource::argument($call->args, 0, 'abstract');

        return in_array($name, ['app', 'resolve'], true) && $abstract instanceof Node\Scalar\String_ && $abstract->value === 'config';
    }

    private static function prefix(Node\Expr $node): string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $left = self::prefix($node->left);
            return $node->left instanceof Node\Scalar\String_ && $node->right instanceof Node\Scalar\String_
                ? $left.$node->right->value : $left;
        }

        return '';
    }
}
