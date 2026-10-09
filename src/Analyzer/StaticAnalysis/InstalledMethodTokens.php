<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\{Node, NodeFinder};

/** Bind a complete method declaration to the installed source. */
final class InstalledMethodTokens
{
    public function __construct(private readonly string $root) {}

    public function proves(Codebase $codebase, string $class, string $method, string $owner, string $suffix, string $declaration): bool
    {
        $native = $codebase->getDeclaringMethod($class, $method);
        if ($native === null || strcasecmp($native->identifier->class ?? '', $owner) !== 0) {
            return false;
        }
        $path = str_replace('\\', '/', $native->location->file ?? '');
        if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        if (! str_ends_with(strtolower($path), strtolower($suffix))) { return false; }
        $path = (new PhpSource($this->root))->path($path);
        $bytes = @file_get_contents($path);
        if ($bytes === false) { return false; }
        $span = $native->location->span;
        if ($span->start >= $span->end || $span->end > strlen($bytes)) { return false; }

        return self::tokens(substr($bytes, $span->start, $span->length())) === self::tokens($declaration);
    }

    private static function tokens(string $source): string
    {
        $parts = [];
        foreach (token_get_all('<?php '.$source) as $token) {
            if (! is_array($token)) { $parts[] = [0, $token]; }
            elseif (! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $parts[] = [$token[0], $token[1]]; }
        }
        return json_encode($parts, JSON_THROW_ON_ERROR);
    }

    /** Verify the physical import used by a selected static dependency. */
    public function references(Codebase $codebase, string $class, string $method, string $dependency, string $dependencyMethod): bool
    {
        $native = $codebase->getDeclaringMethod($class, $method);
        if ($native?->location->file === null) { return false; }
        $path = str_replace('\\', '/', $native->location->file);
        if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        $source = new PhpSource($this->root);
        $owner = (new NodeFinder)->findFirst($source->read($path) ?? [], static fn (Node $node): bool => $node instanceof Node\Stmt\Class_
            && strcasecmp($node->namespacedName?->toString() ?? '', $native->identifier->class ?? '') === 0);
        $node = $owner?->getMethod($method);
        $calls = (new NodeFinder)->findInstanceOf($node?->stmts ?? [], Node\Expr\StaticCall::class);
        return count($calls) === 1 && $calls[0]->class instanceof Node\Name && $calls[0]->name instanceof Node\Identifier
            && strcasecmp($calls[0]->class->toString(), $dependency) === 0 && strcasecmp($calls[0]->name->name, $dependencyMethod) === 0;
    }
}
