<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Account for PHP's implicit string conversion at native string parameters in weak files.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class WeakStringableArgumentFilter implements IssueFilterHook, InitializationHook
{
    /** @var array<string, array{strict: bool, calls: array<string, list<array{kind: string, target: string, index: int, name: ?string}>>}> */
    private array $cache = [];

    public function initialize(InitializationContext $context): void
    {
        $this->cache = [];
    }

    public function getCodes(): array
    {
        return ['invalid-argument'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== 'invalid-argument' || strlen($context->contents) > 1024 * 1024
            || preg_match('~^Invalid argument type for argument #([1-9][0-9]*) of `([^`]+)`: expected `string`, but found `([A-Za-z_\\\\][A-Za-z_0-9\\\\]*)`\.$~', $context->issue->message, $matches) !== 1) {
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

        $key = hash('sha256', $context->file."\0".$context->contents);
        if (! isset($this->cache[$key])) {
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? [];
            } catch (Error) {
                return IssueFilterDecision::Keep;
            }
            $strict = false;
            $calls = [];
            $finder = new NodeFinder;
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Declare_::class) as $declare) {
                foreach ($declare->declares as $item) {
                    if (strtolower($item->key->toString()) === 'strict_types'
                        && $item->value instanceof Node\Scalar\Int_ && $item->value->value === 1) {
                        $strict = true;
                    }
                }
            }
            if (! $strict) {
                foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall
                    || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall
                    || $node instanceof Node\Expr\NullsafeMethodCall) as $call) {
                    if ($call instanceof Node\Expr\FuncCall) {
                        $target = $call->name instanceof Node\Name ? $call->name->toString() : null;
                        $kind = 'function';
                    } else {
                        $target = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
                        $kind = 'method';
                    }
                    if ($target === null) {
                        continue;
                    }
                    foreach ($call->args as $index => $arg) {
                        if (! $arg instanceof Node\Arg || $arg->unpack) {
                            continue;
                        }
                        $span = $arg->value->getStartFilePos().':'.($arg->value->getEndFilePos() + 1);
                        $calls[$span][] = ['kind' => $kind, 'target' => $target, 'index' => $index, 'name' => $arg->name?->toString()];
                    }
                }
            }
            if (count($this->cache) >= 16) {
                unset($this->cache[array_key_first($this->cache)]);
            }
            $this->cache[$key] = ['strict' => $strict, 'calls' => $calls];
        }
        $parsed = $this->cache[$key];
        if ($parsed['strict']) {
            return IssueFilterDecision::Keep;
        }

        $reportedPosition = (int) $matches[1] - 1;
        $target = $matches[2];
        $class = $matches[3];
        $methodSeparator = strrpos($target, '::');
        if ($methodSeparator === false) {
            $signature = $context->codebase->getFunction($target);
            $shortName = substr($target, strrpos($target, '\\') === false ? 0 : strrpos($target, '\\') + 1);
        } else {
            $owner = substr($target, 0, $methodSeparator);
            $shortName = substr($target, $methodSeparator + 2);
            $signature = $context->codebase->getDeclaringMethod($owner, $shortName);
        }
        if ($signature === null) {
            return IssueFilterDecision::Keep;
        }

        $metadata = $context->codebase->getClass($class);
        $stringMethod = $context->codebase->getDeclaringMethod($class, '__toString');
        if ($metadata === null || $metadata->kind !== ClassLikeKind::Class_
            || $metadata->flags->contains(MetadataFlags::ABSTRACT) || $metadata->hasIncompleteHierarchy()
            || $stringMethod === null || $stringMethod->visibility !== Visibility::Public
            || $stringMethod->static || $stringMethod->parameters !== []
            || ($stringMethod->declaredReturnType !== null && $stringMethod->declaredReturnType->type->__toString() !== 'string')
            || $stringMethod->returnType?->type->__toString() !== 'string') {
            return IssueFilterDecision::Keep;
        }

        foreach ($parsed['calls'][$primary->span->start.':'.$primary->span->end] ?? [] as $call) {
            if (($methodSeparator === false) !== ($call['kind'] === 'function')) {
                continue;
            }
            if (strcasecmp($call['target'], $shortName) !== 0 && strcasecmp($call['target'], $target) !== 0) {
                continue;
            }
            // Mago numbers source arguments, including reordered named arguments.
            if ($call['index'] !== $reportedPosition) {
                continue;
            }
            if ($call['name'] !== null) {
                $parameter = null;
                foreach ($signature->parameters as $candidate) {
                    if (ltrim($candidate->name, '$') === $call['name']) {
                        $parameter = $candidate;
                        break;
                    }
                }
            } else {
                $parameter = $signature->parameters[$call['index']] ?? null;
            }
            if ($parameter?->declaredType?->type->__toString() !== 'string'
                || $parameter->type?->type->__toString() !== 'string') {
                continue;
            }

            return IssueFilterDecision::Remove;
        }

        return IssueFilterDecision::Keep;
    }
}
