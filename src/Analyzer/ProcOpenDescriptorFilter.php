<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext, IssueFilterDecision, IssueFilterHook};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\{Node, NodeFinder, NodeTraverser, ParserFactory};
use PhpParser\NodeVisitor\NameResolver;

/** Validate native literal redirect descriptors without replacing param-out pipes metadata. */
final class ProcOpenDescriptorFilter implements IssueFilterHook
{
    public function getCodes(): array { return ['possibly-invalid-argument']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if (! str_starts_with($context->issue->message, 'Possible argument type mismatch for argument #2 of `proc_open`: expected `array{')
            || strlen($context->contents) > 2_000_000) { return IssueFilterDecision::Keep; }
        $native = $context->codebase->getFunction('proc_open');
        if ($native === null || ! $native->flags->contains(MetadataFlags::BUILTIN)
            || $native->flags->contains(MetadataFlags::USER_DEFINED) || count($native->parameters) !== 6) { return IssueFilterDecision::Keep; }
        $primary = null;
        foreach ($context->issue->annotations as $annotation) {
            if ($annotation->kind !== AnnotationKind::Primary) { continue; }
            if ($primary !== null || $annotation->file !== null && $annotation->file !== '') { return IssueFilterDecision::Keep; }
            $primary = $annotation;
        }
        if ($primary === null) { return IssueFilterDecision::Keep; }
        try {
            $nodes = (new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? []);
        } catch (\PhpParser\Error) { return IssueFilterDecision::Keep; }
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
            if (! $call->name instanceof Node\Name || strtolower($call->name->toString()) !== 'proc_open' || $call->isFirstClassCallable()) { continue; }
            $namespaced = $call->name->getAttribute('namespacedName');
            if ($namespaced instanceof Node\Name && strcasecmp($namespaced->toString(), 'proc_open') !== 0
                && $context->codebase->getFunction($namespaced->toString()) !== null) { continue; }
            $argument = $call->args[1] ?? null;
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->name !== null || ! $argument->value instanceof Node\Expr\Array_
                || $argument->value->getStartFilePos() !== $primary->span->start || $argument->value->getEndFilePos() + 1 !== $primary->span->end) { continue; }
            return $this->validDescriptors($argument->value) ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
        }
        return IssueFilterDecision::Keep;
    }

    private function validDescriptors(Node\Expr\Array_ $array): bool
    {
        $redirect = false; $seen = [];
        foreach ($array->items as $item) {
            if ($item === null || $item->unpack || $item->byRef || ! $item->key instanceof Node\Scalar\Int_
                || ! in_array($item->key->value, [0, 1, 2], true) || isset($seen[$item->key->value])
                || ! $item->value instanceof Node\Expr\Array_) { return false; }
            $seen[$item->key->value] = true; $values = [];
            foreach ($item->value->items as $value) {
                if ($value === null || $value->unpack || $value->byRef || $value->key !== null) { return false; }
                $values[] = $value->value;
            }
            if (! ($values[0] ?? null) instanceof Node\Scalar\String_) { return false; }
            if ($values[0]->value === 'redirect') {
                if (count($values) !== 2 || ! $values[1] instanceof Node\Scalar\Int_ || ! in_array($values[1]->value, [0, 1, 2], true)) { return false; }
                $redirect = true;
            } elseif ($values[0]->value === 'pipe') {
                if (count($values) !== 2 || ! $values[1] instanceof Node\Scalar\String_ || ! in_array($values[1]->value, ['r', 'w'], true)) { return false; }
            } elseif ($values[0]->value === 'file') {
                if (count($values) !== 3 || ! $values[1] instanceof Node\Scalar\String_ || $values[1]->value === ''
                    || ! $values[2] instanceof Node\Scalar\String_ || ! in_array($values[2]->value, ['r', 'w'], true)) { return false; }
            } else { return false; }
        }
        return $redirect;
    }
}
