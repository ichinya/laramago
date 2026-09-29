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
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Recheck numeric return atoms as the concrete union accepted by is_numeric(). */
final class NumericReturnCompatibilityFilter implements IssueFilterHook, InitializationHook
{
    /** @var array<string, array<string, true>> */
    private array $returns = [];

    public function initialize(InitializationContext $context): void
    {
        $this->returns = [];
    }

    public function getCodes(): array
    {
        return ['invalid-return-statement'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== 'invalid-return-statement'
            || strlen($context->contents) > 1024 * 1024 || strlen($context->issue->message) > 16384
            || preg_match('/^Invalid return type for function `([^`]+)`: expected `([^`]+)`, but found `([^`]+)`\.$/D', $context->issue->message, $match) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $expected = DiagnosticArrayTypes::parse($match[2]);
        $found = DiagnosticArrayTypes::parse($match[3]);
        if ($expected === null || $found === null) {
            return IssueFilterDecision::Keep;
        }
        $changed = false;
        $expanded = $this->expand($found, $changed);
        if (! $changed) {
            return IssueFilterDecision::Keep;
        }
        if (str_contains($match[1], '::')) {
            [$class, $method] = explode('::', $match[1], 2);
            $metadata = $context->codebase->getMethod($class, $method)
                ?? $context->codebase->getDeclaringMethod($class, $method);
        } else {
            $metadata = $context->codebase->getFunction($match[1]);
        }
        $declared = $metadata?->returnType?->type;
        if ($declared === null || ! DiagnosticArrayTypes::same($expected, $declared, $context->types)
            || ! $context->types->isContainedBy($expanded, $declared)) {
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
        if (! isset($this->returns[$key])) {
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? [];
            } catch (Error) {
                return IssueFilterDecision::Keep;
            }
            $spans = [];
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Return_::class) as $node) {
                if ($node->expr !== null) {
                    $spans[$node->expr->getStartFilePos().':'.($node->expr->getEndFilePos() + 1)] = true;
                }
            }
            if (count($this->returns) >= 16) {
                unset($this->returns[array_key_first($this->returns)]);
            }
            $this->returns[$key] = $spans;
        }

        return isset($this->returns[$key][$primary->span->start.':'.$primary->span->end])
            ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }

    private function expand(Type $type, bool &$changed): Type
    {
        $atoms = [];
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof ScalarType && $atom->kind === ScalarTypeKind::Numeric) {
                $changed = true;
                $numericString = DiagnosticArrayTypes::parse('numeric-string');
                array_push($atoms, ...Type::int()->atomicTypes, ...Type::float()->atomicTypes, ...$numericString->atomicTypes);
            } elseif ($atom instanceof KeyedArrayType) {
                $items = $atom->knownItems === null ? null : [];
                foreach ($atom->knownItems ?? [] as $item) {
                    $items[] = new ArrayItem($item->key, $item->optional, $this->expand($item->type, $changed));
                }
                $atoms[] = new KeyedArrayType(
                    $items,
                    $atom->keyType === null ? null : $this->expand($atom->keyType, $changed),
                    $atom->valueType === null ? null : $this->expand($atom->valueType, $changed),
                    $atom->nonEmpty,
                );
            } elseif ($atom instanceof ListType) {
                $items = $atom->knownElements === null ? null : [];
                foreach ($atom->knownElements ?? [] as $item) {
                    $items[] = new ListElement($item->index, $item->optional, $this->expand($item->type, $changed));
                }
                $atoms[] = new ListType($this->expand($atom->elementType, $changed), $items, $atom->knownCount, $atom->nonEmpty);
            } else {
                $atoms[] = $atom;
            }
        }

        return Type::fromAtomics(...$atoms)->withFlags($type->flags);
    }
}
