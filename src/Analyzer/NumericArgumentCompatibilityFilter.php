<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Numeric values are integers, floats or numeric strings, including after is_numeric(). */
final class NumericArgumentCompatibilityFilter implements IssueFilterHook, InitializationHook
{
    /** @var array<string, array<string, list<array{method: bool, target: string, index: int, name: ?string}>>> */
    private array $calls = [];

    public function initialize(InitializationContext $context): void
    {
        $this->calls = [];
    }

    public function getCodes(): array
    {
        return ['possibly-invalid-argument'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== 'possibly-invalid-argument'
            || strlen($context->contents) > 1024 * 1024
            || preg_match('/^Possible argument type mismatch for argument #([1-9][0-9]*) of `([^`]+)`: expected `((?:int|float|string|numeric-string)(?:\|(?:int|float|string|numeric-string))*)`, but possibly received `numeric`\.$/D', $context->issue->message, $match) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $members = explode('|', $match[3]);
        if (! in_array('int', $members, true) || ! in_array('float', $members, true)
            || ! in_array('string', $members, true) && ! in_array('numeric-string', $members, true)) {
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
        $target = $match[2];
        $method = str_contains($target, '::');
        if ($method) {
            [$owner, $name] = explode('::', $target, 2);
            $metadata = $context->codebase->getMethod($owner, $name)
                ?? $context->codebase->getDeclaringMethod($owner, $name);
        } else {
            $metadata = $context->codebase->getFunction($target);
            $name = substr($target, strrpos($target, '\\') === false ? 0 : strrpos($target, '\\') + 1);
        }
        if ($metadata === null) {
            return IssueFilterDecision::Keep;
        }
        $key = hash('sha256', $context->file."\0".$context->contents);
        if (! isset($this->calls[$key])) {
            $calls = $this->parseCalls($context->contents);
            if ($calls === null) {
                return IssueFilterDecision::Keep;
            }
            if (count($this->calls) >= 16) {
                unset($this->calls[array_key_first($this->calls)]);
            }
            $this->calls[$key] = $calls;
        }
        $numericString = Type::fromAtomics(new ScalarType(ScalarTypeKind::String, new StringType(
            StringLiteralKind::General, null, true, false, false, false, StringCasing::Unspecified,
        )));
        foreach ($this->calls[$key][$primary->span->start.':'.$primary->span->end] ?? [] as $call) {
            if ($call['method'] !== $method || $call['index'] !== (int) $match[1] - 1
                || strcasecmp($call['target'], $name) !== 0 && strcasecmp($call['target'], $target) !== 0) {
                continue;
            }
            $parameter = null;
            if ($call['name'] === null) {
                $parameter = $metadata->parameters[$call['index']] ?? null;
            } else {
                foreach ($metadata->parameters as $candidate) {
                    if (ltrim($candidate->name, '$') === $call['name']) {
                        $parameter = $candidate;
                        break;
                    }
                }
            }
            $type = $parameter?->type?->type;
            if ($type === null || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC)) {
                continue;
            }
            // Check the actual callable contract, not just the diagnostic's printed union.
            foreach ($type->atomicTypes as $atom) {
                if (! $atom instanceof ScalarType || ! in_array($atom->kind, [
                    ScalarTypeKind::Integer, ScalarTypeKind::Float, ScalarTypeKind::String,
                ], true)) {
                    continue 2;
                }
            }
            if ($context->types->isContainedBy(Type::int(), $type)
                && $context->types->isContainedBy(Type::float(), $type)
                && $context->types->isContainedBy($numericString, $type)) {
                return IssueFilterDecision::Remove;
            }
        }

        return IssueFilterDecision::Keep;
    }

    /** @return array<string, list<array{method: bool, target: string, index: int, name: ?string}>>|null */
    private function parseCalls(string $contents): ?array
    {
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
        } catch (Error) {
            return null;
        }
        $calls = [];
        foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall
            || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\NullsafeMethodCall) as $call) {
            if (! $call->name instanceof Node\Name && ! $call->name instanceof Node\Identifier) {
                continue;
            }
            foreach ($call->args as $index => $argument) {
                if (! $argument instanceof Node\Arg || $argument->unpack) {
                    continue;
                }
                $span = $argument->value->getStartFilePos().':'.($argument->value->getEndFilePos() + 1);
                $calls[$span][] = [
                    'method' => ! $call instanceof Node\Expr\FuncCall,
                    'target' => $call->name->toString(),
                    'index' => $index,
                    'name' => $argument->name?->toString(),
                ];
            }
        }

        return $calls;
    }
}
