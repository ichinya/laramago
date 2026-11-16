<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Invocation names may be normalized by the engine; Macroable keys are case-sensitive. */
final class MacroInvocationNames implements CodebaseScanHook
{
    /** @var array<string, array{string, string, string}|null> */
    private array $calls = [];
    /** @var array<string, array<string, array{string, array<string,string>}|null>> */
    private array $spellings = [];
    /** @var array<string, true> */
    private array $dynamic = [];
    private bool $complete = false;
    private bool $failed = false;

    public function __construct(private readonly string $root) {}

    public function getTargets(): array { return ['**']; }

    public function reset(): void
    {
        $this->calls = [];
        $this->spellings = $this->dynamic = [];
        $this->complete = $this->failed = false;
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) { $this->reset(); }
        $this->complete = false;
        foreach ($context->files as $file) {
            $context->cancellation->throwIfCancelled();
            if ($this->failed) { break; }
            try {
                if (strlen($file->contents) > 2_000_000) { $this->failed = true; break; }
                $names = CodebaseScanSyntax::query($file->contents, 'macro-invocation-names-v2', static function (array $nodes) use ($file): array {
                    $names = [];
                    foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\CallLike::class) as $call) {
                        $key = $call->getStartFilePos().':'.($call->getEndFilePos() + 1);
                        $name = ($call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\MethodCall
                            || $call instanceof Node\Expr\NullsafeMethodCall) && $call->name instanceof Node\Identifier
                            ? $call->name->toString() : null;
                        $arguments = null;
                        if ($call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) {
                            $arguments = [];
                            foreach ($call->args as $argument) {
                                $arguments[] = $argument instanceof Node\Arg
                                    ? [$argument->name?->toString(), $argument->unpack, false, substr($file->contents, $argument->value->getStartFilePos(), $argument->value->getEndFilePos() - $argument->value->getStartFilePos() + 1)]
                                    : [null, false, true, '...'];
                            }
                        }
                        $names[$key] = array_key_exists($key, $names) ? null : [$name, $arguments];
                    }
                    return $names;
                });
            } catch (\PhpParser\Error) { $this->failed = true; break; }
            $path = str_replace('\\', '/', $file->path);
            if (preg_match('~^//\?/[A-Za-z]:/~', $path) === 1) { $path = substr($path, 4); }
            if (! str_starts_with($path, '/') && preg_match('~^[A-Za-z]:/~', $path) !== 1) {
                $path = $this->root.'/'.$path;
            }
            $hash = hash('sha256', $file->contents);
            // Invocation spans have no file identity. Reserve every call span,
            // including unrelated calls, and reject collisions across files.
            foreach ($names as $key => $entry) {
                [$name, $arguments] = $entry ?? [null, null];
                $this->calls[$key] = $name === null || array_key_exists($key, $this->calls)
                    ? null : [$name, $path, $hash];
                if ($arguments !== null) {
                    $argumentsKey = serialize($arguments);
                    if ($name === null) {
                        $this->dynamic[$argumentsKey] = true;
                    } else {
                        $lower = strtolower($name);
                        $known = $this->spellings[$lower][$argumentsKey] ?? null;
                        if (! array_key_exists($argumentsKey, $this->spellings[$lower] ?? [])) {
                            $this->spellings[$lower][$argumentsKey] = [$name, [$path => $hash]];
                        } elseif ($known !== null && $known[0] === $name) {
                            $this->spellings[$lower][$argumentsKey][1][$path] = $hash;
                        } else {
                            $this->spellings[$lower][$argumentsKey] = null;
                        }
                    }
                }
                if (count($this->calls) > 100_000) { $this->failed = true; break; }
            }
        }
        if ($this->failed) { $this->calls = []; }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    public function name(Span $span): ?string
    {
        $candidate = $this->complete ? ($this->calls[$span->start.':'.$span->end] ?? null) : null;
        return $candidate !== null && @hash_file('sha256', $candidate[1]) === $candidate[2] ? $candidate[0] : null;
    }

    /** Mago 1.59 sends zero signature spans. Use only unanimous source spellings
     * for the exact argument texts; dynamic or differently cased calls defer.
     */
    public function signatureName(Invocation $call): ?string
    {
        if (! $this->complete) { return null; }
        $arguments = array_map(static fn ($argument): array =>
            [$argument->name, $argument->unpacked, $argument->placeholder, $argument->expression], $call->arguments);
        $key = serialize($arguments);
        if (isset($this->dynamic[$key])) { return null; }
        $candidate = $this->spellings[strtolower($call->name)][$key] ?? null;
        if ($candidate === null) { return null; }
        foreach ($candidate[1] as $path => $hash) {
            if (@hash_file('sha256', $path) !== $hash) { return null; }
        }
        return $candidate[0];
    }
}
