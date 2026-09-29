<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Match PHPStan's default reporting of narrower PHPDoc parameters, preserving native contracts. */
final class PhpDocParameterCompatibilityFilter implements IssueFilterHook, InitializationHook
{
    /** @var array<string, list<Node\Stmt\Class_>> */
    private array $classes = [];

    public function initialize(InitializationContext $context): void
    {
        $this->classes = [];
    }

    public function getCodes(): array
    {
        return ['incompatible-parameter-type'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== 'incompatible-parameter-type'
            || strlen($context->contents) > 1024 * 1024 || strlen($context->issue->message) > 16384
            || preg_match('/^Parameter `(\$[A-Za-z_][A-Za-z0-9_]*)` of `([^`]+)::([^`:]+)\(\)` expects type `([^`]+)` but parent `([^`]+)::([^`:]+)\(\)` expects type `([^`]+)`$/D', $context->issue->message, $match) !== 1
            || strcasecmp($match[3], $match[6]) !== 0 || str_starts_with($match[3], '__')) {
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
        $childClass = $context->codebase->getClass($match[2]);
        $parentClass = $context->codebase->getClassLike($match[5]);
        $childMethod = $context->codebase->getMethod($match[2], $match[3]);
        $parentMethod = $context->codebase->getMethod($match[5], $match[6]);
        if ($childClass === null || $parentClass === null || $childMethod === null || $parentMethod === null
            || $childClass->kind !== ClassLikeKind::Class_
            || ! in_array($parentClass->kind, [ClassLikeKind::Class_, ClassLikeKind::Interface], true)
            || $childClass->hasIncompleteHierarchy() || $parentClass->hasIncompleteHierarchy()
            || ! in_array(strtolower($parentClass->name), array_map(strtolower(...), [
                ...$childClass->parentClasses, ...$childClass->parentInterfaces,
            ]), true)
            || strcasecmp($childMethod->identifier->class ?? '', $childClass->name) !== 0
            || strcasecmp($parentMethod->identifier->class ?? '', $parentClass->name) !== 0
            || $childMethod->templates !== [] || $parentMethod->templates !== []
            || $childMethod->static !== $parentMethod->static
            || $childMethod->visibility !== $parentMethod->visibility) {
            return IssueFilterDecision::Keep;
        }
        $source = $this->parameterSource($context, $match[2], $match[3], $match[1], $primary->span->start, $primary->span->end);
        if ($source === null) {
            return IssueFilterDecision::Keep;
        }
        [$classNode, $index] = $source;
        $child = $childMethod->parameters[$index] ?? null;
        $parent = $parentMethod->parameters[$index] ?? null;
        if ($child === null || $parent === null || $child->name !== $match[1] || $child->name !== $parent->name
            || ! $child->type?->fromDocblock || $child->type->inferred
            || $child->outType !== null || $parent->outType !== null
            || $child->flags->contains(MetadataFlags::BY_REFERENCE) || $parent->flags->contains(MetadataFlags::BY_REFERENCE)
            || $child->flags->contains(MetadataFlags::VARIADIC) || $parent->flags->contains(MetadataFlags::VARIADIC)
            || $childMethod->nameLocation?->span->start !== $primary->span->start
            || $childMethod->nameLocation?->span->end !== $primary->span->end) {
            return IssueFilterDecision::Keep;
        }
        $nativeChild = $child->declaredType?->type ?? Type::mixed();
        $nativeParent = $parent->declaredType?->type ?? Type::mixed();
        if (! $context->types->isContainedBy($nativeParent, $nativeChild)) {
            return IssueFilterDecision::Keep;
        }
        $childDoc = DiagnosticArrayTypes::resolveAliases($child->type->type, $context->codebase);
        $parentDoc = $parent->type?->type ?? $nativeParent;
        $generic = $parentDoc->atomicTypes[0] ?? null;
        if ($generic instanceof GenericParameterType) {
            if (count($parentDoc->atomicTypes) !== 1
                || ! $this->usesDefaultMixed($context, $generic, $parentClass, $childClass, $classNode)) {
                return IssueFilterDecision::Keep;
            }
            $parentDoc = Type::mixed();
        }
        $parentDoc = DiagnosticArrayTypes::resolveAliases($parentDoc, $context->codebase);
        $reportedChild = DiagnosticArrayTypes::parse($match[4]);
        $reportedParent = DiagnosticArrayTypes::parse($match[7]);
        if ($childDoc === null || $parentDoc === null || $reportedChild === null || $reportedParent === null
            || self::containsNever($childDoc)
            || ! DiagnosticArrayTypes::same($reportedChild, $childDoc, $context->types)
            || ! DiagnosticArrayTypes::same($reportedParent, $parentDoc, $context->types)
            || ! $context->types->isContainedBy($childDoc, $nativeChild)
            || ! $context->types->isContainedBy($childDoc, $parentDoc)) {
            return IssueFilterDecision::Keep;
        }

        return IssueFilterDecision::Remove;
    }

    private static function containsNever(Type $type, int $depth = 0): bool
    {
        if ($depth > 12) {
            return true;
        }
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Never) {
                return true;
            }
            $children = [];
            if ($atom instanceof ListType) {
                $children[] = $atom->elementType;
                foreach ($atom->knownElements ?? [] as $element) {
                    $children[] = $element->type;
                }
            } elseif ($atom instanceof KeyedArrayType) {
                foreach ([$atom->keyType, $atom->valueType] as $fallback) {
                    if ($fallback !== null) {
                        $children[] = $fallback;
                    }
                }
                foreach ($atom->knownItems ?? [] as $item) {
                    $children[] = $item->type;
                }
            }
            foreach ($children as $child) {
                if (self::containsNever($child, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function usesDefaultMixed(
        IssueFilterContext $context,
        GenericParameterType $generic,
        ClassLikeMetadata $parent,
        ClassLikeMetadata $child,
        Node\Stmt\Class_ $node,
    ): bool {
        if ($generic->definingEntity->kind !== GenericParentKind::ClassLike
            || strcasecmp($generic->definingEntity->name, $parent->name) !== 0
            || ($generic->intersections ?? []) !== []
            || ! $context->types->equals($generic->constraint, Type::mixed())
            || ! in_array(strtolower($parent->name), array_map(strtolower(...), [
                ...$child->directParentInterfaces, $child->directParentClass ?? '',
            ]), true)
            || preg_match('/@(?:phpstan-|psalm-|mago-)?(?:implements|extends)\b/i', $node->getDocComment()?->getText() ?? '')) {
            return false;
        }
        foreach ($parent->templates as $template) {
            if ($template->name === $generic->name) {
                return $template->default !== null
                    && $context->types->equals($template->constraint, Type::mixed())
                    && $context->types->equals($template->default, Type::mixed());
            }
        }

        return false;
    }

    /** @return array{Node\Stmt\Class_, int}|null */
    private function parameterSource(IssueFilterContext $context, string $class, string $method, string $parameter, int $start, int $end): ?array
    {
        $key = hash('sha256', $context->file."\0".$context->contents);
        if (! isset($this->classes[$key])) {
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (Error) {
                return null;
            }
            if (count($this->classes) >= 16) {
                unset($this->classes[array_key_first($this->classes)]);
            }
            $this->classes[$key] = (new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class);
        }
        foreach ($this->classes[$key] as $node) {
            if ($node->name === null || $node->namespacedName === null || strcasecmp($node->namespacedName->toString(), $class) !== 0) {
                continue;
            }
            foreach ($node->getMethods() as $methodNode) {
                if (strcasecmp($methodNode->name->toString(), $method) !== 0
                    || $methodNode->name->getStartFilePos() !== $start || $methodNode->name->getEndFilePos() + 1 !== $end) {
                    continue;
                }
                foreach ($methodNode->params as $index => $param) {
                    if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                        && '$'.$param->var->name === $parameter) {
                        return [$node, $index];
                    }
                }
            }
        }

        return null;
    }
}
