<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\FrameworkClassAliases;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;

/**
 * Removes the false `non-existent-method` on global-namespace calls to Laravel's
 * class aliases.
 *
 * Laravel registers `Str`, `Arr` and friends as global class aliases at boot, so a
 * call like `\Str::random()` resolves to `Illuminate\Support\Str` at runtime. Mago
 * analyzes statically and reports the alias name as an undeclared class; Larastan
 * resolves it through the bootstrapped autoloader. The filter removes the diagnostic
 * only when every proof link holds: the receiver is an undeclared global class name,
 * the literal boot alias table maps it to a class that declares the method, and the
 * call site matches. The expression itself stays `mixed` because the analyzer cannot
 * resolve the receiver.
 */
final class ClassAliasFilter implements IssueFilterHook, InitializationHook
{
    private const FILTER_CODE = 'non-existent-method';

    private ?FrameworkClassAliases $frameworkAliases = null;
    /** @var array<string, string>|null Lowercased alias name => target class. */
    private ?array $aliases = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->frameworkAliases = null;
        $this->aliases = null;
    }

    public function getCodes(): array
    {
        return [self::FILTER_CODE];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== self::FILTER_CODE) {
            return IssueFilterDecision::Keep;
        }
        if (preg_match('/^Method `([^`]+)` does not exist on type `([^`]+)`\.$/D', $context->issue->message, $match) !== 1) {
            return IssueFilterDecision::Keep;
        }
        [$method, $type] = [$match[1], $match[2]];
        if (
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $type) !== 1
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method) !== 1
        ) {
            return IssueFilterDecision::Keep;
        }
        $aliases = $this->aliasMap();
        $target = $aliases !== null ? ($aliases[strtolower($type)] ?? null) : null;
        $codebase = $context->codebase;
        if (
            $target === null
            || $codebase->classLikeExists($type)
            || $codebase->getDeclaringMethod($target, $method) === null
        ) {
            return IssueFilterDecision::Keep;
        }

        return $this->spanMentions($context->contents, $context->issue->annotations, $method)
            ? IssueFilterDecision::Remove
            : IssueFilterDecision::Keep;
    }

    /** The proven alias map, or null when the boot chain is not fully literal. */
    private function aliasMap(): ?array
    {
        if ($this->aliases === null) {
            $this->aliases = ($this->frameworkAliases ??= new FrameworkClassAliases($this->root))->aliases();
        }

        return $this->aliases === [] ? null : $this->aliases;
    }

    /**
     * Cheap sanity check that the annotated call site really mentions the method.
     *
     * @param list<\Mago\Sdk\Reporting\Annotation> $annotations
     */
    private function spanMentions(string $contents, array $annotations, string $method): bool
    {
        foreach ($annotations as $annotation) {
            if ($annotation->kind !== AnnotationKind::Primary) {
                continue;
            }
            if ($annotation->file !== null && $annotation->file !== '') {
                return false;
            }
            $start = $annotation->span->start;
            $end = $annotation->span->end;
            $length = strlen($contents);
            if ($start > $length || $end > $length) {
                return false;
            }
            $window = substr($contents, max(0, $start - 64), min($length, $end + 64) - max(0, $start - 64));

            // Native messages lowercase the method name, so match the word without case.
            return preg_match('/\b'.preg_quote($method, '/').'\b/iD', $window) === 1;
        }

        return false;
    }
}
