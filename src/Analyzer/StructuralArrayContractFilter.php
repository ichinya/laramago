<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Preserve required array fields while allowing additional record keys. */
final class StructuralArrayContractFilter implements IssueFilterHook, InitializationHook
{
    public function initialize(InitializationContext $context): void
    {
        // Source-only facts live in the existing bounded syntax query cache.
    }

    public function getCodes(): array
    {
        return ['possibly-invalid-argument', 'invalid-argument', 'invalid-property-assignment-value'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if (! in_array($context->issue->code, $this->getCodes(), true)
            || strlen($context->contents) > 1024 * 1024 || strlen($context->issue->message) > 32768) {
            return IssueFilterDecision::Keep;
        }
        $primary = null;
        foreach ($context->issue->annotations as $annotation) {
            if ($annotation->kind !== AnnotationKind::Primary) {
                continue;
            }
            if ($primary !== null || $annotation->file !== null && $annotation->file !== '') {
                return IssueFilterDecision::Keep;
            }
            $primary = $annotation;
        }
        if ($primary === null) {
            return IssueFilterDecision::Keep;
        }
        $nodes = $this->nodes($context);
        if ($nodes === null) {
            return IssueFilterDecision::Keep;
        }
        if ($context->issue->code === 'invalid-property-assignment-value') {
            if (! preg_match('/^Invalid type for property `\$([A-Za-z_][A-Za-z0-9_]*)`: expected `([^`]+)`, but got `([^`]+)`\.$/sD', $context->issue->message, $match)) {
                return IssueFilterDecision::Keep;
            }
            $declared = $this->propertyType($context, $nodes, $match[1], $primary->span->start, $primary->span->end);
            $expected = DiagnosticArrayTypes::parse($match[2]);
            $actual = DiagnosticArrayTypes::parse($match[3]);
        } else {
            $pattern = $context->issue->code === 'possibly-invalid-argument'
                ? '/^Possible argument type mismatch for argument #([1-9][0-9]*) of `([^`]+)`: expected `([^`]+)`, but possibly received `([^`]+)`\.$/sD'
                : '/^Invalid argument type for argument #([1-9][0-9]*) of `([^`]+)`: expected `([^`]+)`, but found `([^`]+)`\.$/sD';
            if (! preg_match($pattern, $context->issue->message, $match)) {
                return IssueFilterDecision::Keep;
            }
            $declared = $this->parameterType($context, $nodes, $match[2], (int) $match[1] - 1, $primary->span->start, $primary->span->end);
            $expected = DiagnosticArrayTypes::parse($match[3]);
            $actual = DiagnosticArrayTypes::parse($match[4]);
        }
        $declared = $declared === null ? null : DiagnosticArrayTypes::resolveAliases($declared, $context->codebase);
        $relaxed = false;
        return $declared !== null && $expected !== null && $actual !== null
            && DiagnosticArrayTypes::same($expected, $declared, $context->types)
            && DiagnosticArrayTypes::contains($actual, $declared, $context->types, $relaxed) && $relaxed
            ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }

    /** @return array{calls: list<array>, assignments: list<array>}|null */
    private function nodes(IssueFilterContext $context): ?array
    {
        try {
            return StaticAnalysis\CodebaseScanSyntax::query($context->contents, self::class.'::source-sites:v1', self::sourceSites(...));
        } catch (Error) {
            return null;
        }
    }

    /** Keep traversal order and spans; native signatures remain live at the call site. */
    private static function sourceSites(array $nodes): array
    {
        $calls = [];
        foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall
            || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\NullsafeMethodCall) as $call) {
            $method = ! $call instanceof Node\Expr\FuncCall;
            if ($method ? ! $call->name instanceof Node\Identifier : ! $call->name instanceof Node\Name) {
                continue;
            }
            $arguments = []; $unpacked = false;
            foreach ($call->args as $key => $argument) {
                $arguments[$key] = $argument instanceof Node\Arg
                    ? ['unpack' => $argument->unpack, 'name' => $argument->name?->toString(),
                        'start' => $argument->value->getStartFilePos(), 'end' => $argument->value->getEndFilePos() + 1]
                    : null;
                $unpacked = $unpacked || $argument instanceof Node\Arg && $argument->unpack;
            }
            $calls[] = ['method' => $method, 'name' => $call->name->toString(),
                'resolved' => $method ? null : $call->name->getAttribute('namespacedName')?->toString(),
                'firstClass' => $call->isFirstClassCallable(), 'unpacked' => $unpacked, 'args' => $arguments];
        }
        $assignments = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
            if ($class->name === null || $class->namespacedName === null) { continue; }
            foreach ($class->getMethods() as $method) {
                if ($method->isStatic()) { continue; }
                foreach (self::assignments($method->stmts ?? []) as $assignment) {
                    $property = $assignment->var;
                    if (! $property instanceof Node\Expr\PropertyFetch || ! $property->var instanceof Node\Expr\Variable
                        || $property->var->name !== 'this' || ! $property->name instanceof Node\Identifier) { continue; }
                    $assignments[] = ['owner' => $class->namespacedName->toString(), 'name' => $property->name->toString(),
                        'start' => $assignment->expr->getStartFilePos(), 'end' => $assignment->expr->getEndFilePos() + 1];
                }
            }
        }
        return ['calls' => $calls, 'assignments' => $assignments];
    }

    private function parameterType(IssueFilterContext $context, array $nodes, string $target, int $position, int $start, int $end): ?Type
    {
        if (str_contains($target, '::')) {
            [$owner, $name] = explode('::', $target, 2);
            $signature = $context->codebase->getDeclaringMethod($owner, $name);
            $method = true;
        } else {
            $name = $target;
            $signature = $context->codebase->getFunction($target);
            $method = false;
        }
        if ($signature === null) { return null; }
        foreach ($nodes['calls'] as $call) {
            if ($method) {
                if (! $call['method'] || strcasecmp($call['name'], $name) !== 0) { continue; }
            } elseif ($call['method'] || strcasecmp($call['name'], $name) !== 0
                && ($call['resolved'] === null || strcasecmp($call['resolved'], $name) !== 0)) {
                continue;
            }
            if ($call['firstClass']) { continue; }
            $arg = $call['args'][$position] ?? null;
            if ($arg === null || $arg['unpack'] || $arg['start'] !== $start || $arg['end'] !== $end || $call['unpacked']) {
                continue;
            }
            if ($arg['name'] === null) {
                $parameter = $signature->parameters[$position] ?? null;
            } else {
                $parameter = null;
                foreach ($signature->parameters as $candidate) {
                    if (ltrim($candidate->name, '$') === $arg['name']) { $parameter = $candidate; break; }
                }
            }
            return $parameter?->type?->type;
        }
        return null;
    }

    private function propertyType(IssueFilterContext $context, array $nodes, string $name, int $start, int $end): ?Type
    {
        foreach ($nodes['assignments'] as $assignment) {
            if ($assignment['name'] !== $name || $assignment['start'] !== $start || $assignment['end'] !== $end) { continue; }
            $metadata = $context->codebase->getDeclaringProperty($assignment['owner'], '$'.$name);
            if ($metadata === null || $metadata->hooks !== []) { return null; }
            return ($metadata->writeType ?? $metadata->type)?->type;
        }
        return null;
    }

    /** @param array<array-key, mixed> $nodes
     * @return \Generator<Node\Expr\Assign>
     */
    private static function assignments(array $nodes): \Generator
    {
        foreach ($nodes as $node) {
            if (! $node instanceof Node || $node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                continue;
            }
            if ($node instanceof Node\Expr\Assign) {
                yield $node;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->$name;
                yield from self::assignments(is_array($value) ? $value : [$value]);
            }
        }
    }
}
