<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\MixedTruthiness;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\Variance;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** PHPStan's benevolent array-key and unrestricted mixed collection parameters. */
final class CollectionReturnCompatibilityFilter implements IssueFilterHook, InitializationHook
{
    private const COLLECTION = 'Illuminate\\Support\\Collection';
    private const ENUMERABLE = 'Illuminate\\Support\\Enumerable';

    private ?PhpSource $source = null;

    public function __construct(private readonly string $root) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
    }

    public function getCodes(): array
    {
        return ['incompatible-return-type'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== 'incompatible-return-type'
            || strlen($context->contents) > 1024 * 1024 || strlen($context->issue->message) > 16384
            || preg_match('/^Return type `([^`]+)` of `([^`]+)::([^`]+)\(\)` is incompatible with parent return type `([^`]+)` of `([^`]+)::([^`]+)\(\)`$/D', $context->issue->message, $match) !== 1
            || $match[3] !== $match[6]) {
            return IssueFilterDecision::Keep;
        }
        $childClass = $context->codebase->getClassLike($match[2]);
        $parentClass = $context->codebase->getClassLike($match[5]);
        $child = $context->codebase->getMethod($match[2], $match[3]);
        $parent = $context->codebase->getMethod($match[5], $match[6]);
        if ($childClass === null || $parentClass === null || $child === null || $parent === null
            || $childClass->hasIncompleteHierarchy() || $parentClass->hasIncompleteHierarchy()
            || $child->templates !== [] || $parent->templates !== []
            || strcasecmp($child->identifier->class ?? '', $match[2]) !== 0
            || strcasecmp($parent->identifier->class ?? '', $match[5]) !== 0
            || ! $child->returnType?->fromDocblock || ! $parent->returnType?->fromDocblock
            || $child->nameLocation === null || ! $this->sameFile($child->nameLocation->file, $context->file)) {
            return IssueFilterDecision::Keep;
        }
        $primary = null;
        $parentSpan = false;
        foreach ($context->issue->annotations as $annotation) {
            if ($annotation->kind === AnnotationKind::Secondary
                && $annotation->message === 'Parent method `'.$match[5].'::'.$match[6].'()` return type defined here'
                && $parentClass->nameLocation !== null
                && $annotation->span->start === $parentClass->nameLocation->span->start
                && $annotation->span->end === $parentClass->nameLocation->span->end
                && $this->sameFile($annotation->file ?? $context->file, $parentClass->nameLocation->file ?? '')) {
                $parentSpan = true;
            }
            if ($annotation->kind !== AnnotationKind::Primary) {
                continue;
            }
            if ($primary !== null || $annotation->file !== null
                || $annotation->span->start !== $child->nameLocation->span->start
                || $annotation->span->end !== $child->nameLocation->span->end) {
                return IssueFilterDecision::Keep;
            }
            $primary = $annotation;
        }
        $found = self::object($child->returnType->type, self::COLLECTION, true);
        $expected = self::object($parent->returnType->type, self::ENUMERABLE, true);
        if ($primary === null || ! $parentSpan || $found === null || $expected === null
            || self::object($child->declaredReturnType?->type, self::COLLECTION, false) === null
            || self::object($parent->declaredReturnType?->type, self::ENUMERABLE, false) === null) {
            return IssueFilterDecision::Keep;
        }
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? [];
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        } catch (Error) {
            return IssueFilterDecision::Keep;
        }
        $declaration = self::declaration($nodes, $match[2]);
        $method = $declaration?->getMethod($match[3]);
        if (! $declaration instanceof Node\Stmt\Class_ || $method === null
            || $method->name->getStartFilePos() !== $primary->span->start
            || $method->name->getEndFilePos() + 1 !== $primary->span->end
            || ! $this->directParent($declaration, $match[5])) {
            return IssueFilterDecision::Keep;
        }
        $parameters = [];
        foreach ($expected->parameters as $parameter) {
            $resolved = $this->parameter($parameter, $parentClass, $declaration);
            if ($resolved === null || ! self::plainMixed($resolved) && ! CollectionItemProperty::concrete($resolved)) {
                return IssueFilterDecision::Keep;
            }
            $parameters[] = $resolved;
        }
        $enumerable = $context->codebase->getInterface(self::ENUMERABLE);
        $collection = $context->codebase->getClass(self::COLLECTION);
        if ($enumerable === null || $collection === null || ! $this->mapping($collection, $enumerable)) {
            return IssueFilterDecision::Keep;
        }
        $relaxed = false;
        foreach ($parameters as $index => $target) {
            $input = $found->parameters[$index];
            if ($index === 0 && self::arrayKey($target)) {
                if (! $this->concreteKey($input, $context)) {
                    return IssueFilterDecision::Keep;
                }
                $relaxed = true;
                continue;
            }
            if (self::plainMixed($target)) {
                $relaxed = true;
                continue;
            }
            if (self::plainMixed($input)) {
                return IssueFilterDecision::Keep;
            }
            $variance = $enumerable->templates[$index]->variance;
            $compatible = $variance === Variance::Covariant
                ? $context->types->isContainedBy($input, $target)
                : $context->types->equals($input, $target);
            if (! $compatible) {
                return IssueFilterDecision::Keep;
            }
        }

        return $relaxed ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }

    private static function object(?Type $type, string $name, bool $generic): ?NamedObjectType
    {
        if ($type === null || count($type->atomicTypes) !== 1) {
            return null;
        }
        $atom = $type->atomicTypes[0];
        return $atom instanceof NamedObjectType && strcasecmp($atom->name, $name) === 0
            && ! $atom->static && ! $atom->isThis && ($atom->intersections ?? []) === []
            && ($atom->variances ?? []) === [] && ! $atom->remappedParameters
            && ($generic ? count($atom->parameters ?? []) === 2 : ($atom->parameters ?? []) === [])
            ? $atom : null;
    }

    /** Resolve only an unbound direct parent's explicitly declared template defaults. */
    private function parameter(Type $type, ClassLikeMetadata $parent, Node\Stmt\Class_ $child): ?Type
    {
        foreach ($type->atomicTypes as $atom) {
            if (! $atom instanceof GenericParameterType) {
                continue;
            }
            if (count($type->atomicTypes) !== 1 || ($atom->intersections ?? []) !== []
                || $atom->definingEntity->kind !== GenericParentKind::ClassLike
                || strcasecmp($atom->definingEntity->name, $parent->name) !== 0
                || preg_match('/@(?:phpstan-|psalm-|mago-)?(?:implements|extends)\b/', $child->getDocComment()?->getText() ?? '')) {
                return null;
            }
            foreach ($parent->templates as $template) {
                if ($template->name === $atom->name && $template->default !== null) {
                    foreach ($template->default->atomicTypes as $default) {
                        if ($default instanceof GenericParameterType) {
                            return null;
                        }
                    }
                    return $template->default;
                }
            }
            return null;
        }
        return $type;
    }

    /** Prove the installed Collection maps its two templates to Enumerable unchanged. */
    private function mapping(ClassLikeMetadata $collection, ClassLikeMetadata $enumerable): bool
    {
        if (count($collection->templates) !== 2 || count($enumerable->templates) !== 2
            || $collection->hasIncompleteHierarchy() || $enumerable->hasIncompleteHierarchy()
            || $collection->directParentClass !== null || $collection->location->file === null
            || $enumerable->location->file === null) {
            return false;
        }
        foreach ([$collection, $enumerable] as $metadata) {
            $name = $metadata === $collection ? 'Collection' : 'Enumerable';
            $path = str_replace('\\', '/', $metadata->location->file);
            if (! str_ends_with($path, '/laravel/framework/src/Illuminate/Collections/'.$name.'.php')
                && ! str_ends_with($path, '/illuminate/collections/'.$name.'.php')) {
                return false;
            }
            if ($metadata->templates[0]->name !== 'TKey' || $metadata->templates[1]->name !== 'TValue'
                || ! self::arrayKey($metadata->templates[0]->constraint)
                || ! self::plainMixed($metadata->templates[1]->constraint)
                || $metadata->templates[0]->variance !== Variance::Invariant
                || ! in_array($metadata->templates[1]->variance, [Variance::Invariant, Variance::Covariant], true)) {
                return false;
            }
        }
        $source = $this->source ??= new PhpSource($this->root);
        $file = str_replace('\\', '/', $collection->location->file);
        if (PHP_OS_FAMILY === 'Windows' && preg_match('~^//\?/[a-z]:/~i', $file)) {
            $file = substr($file, 4);
        }
        $declaration = self::declaration($source->read($file) ?? [], self::COLLECTION);
        if (! $declaration instanceof Node\Stmt\Class_ || $declaration->extends !== null
            || ! $this->directParent($declaration, self::ENUMERABLE)) {
            return false;
        }
        $doc = $declaration->getDocComment()?->getText() ?? '';
        preg_match_all('/@(?:phpstan-|psalm-|mago-)?implements\s+([^\r\n*]+)/', $doc, $matches);
        $mapping = [];
        foreach ($matches[1] as $annotation) {
            if (str_contains($annotation, 'Enumerable')) {
                $mapping[] = preg_replace('/\s+/', '', $annotation);
            }
        }
        return $mapping === ['\\Illuminate\\Support\\Enumerable<TKey,TValue>'];
    }

    private static function arrayKey(Type $type): bool
    {
        $atom = $type->atomicTypes[0] ?? null;
        return count($type->atomicTypes) === 1 && $atom instanceof ScalarType
            && $atom->kind === ScalarTypeKind::ArrayKey && $atom->refinement === null;
    }

    private static function plainMixed(Type $type): bool
    {
        $atom = $type->atomicTypes[0] ?? null;
        return count($type->atomicTypes) === 1 && $atom instanceof MixedType
            && ! $atom->nonNull && ! $atom->empty && ! $atom->issetFromLoop
            && $atom->truthiness === MixedTruthiness::Undetermined;
    }

    private function concreteKey(Type $type, IssueFilterContext $context): bool
    {
        foreach ($type->atomicTypes as $atom) {
            if (! $atom instanceof ScalarType || ! in_array($atom->kind, [
                ScalarTypeKind::Integer, ScalarTypeKind::String, ScalarTypeKind::ArrayKey,
            ], true)) {
                return false;
            }
        }
        return $type->atomicTypes !== []
            && $context->types->isContainedBy($type, Type::union(Type::int(), Type::string()));
    }

    /** @param array<array-key, Node> $nodes */
    private static function declaration(array $nodes, string $name): ?Node\Stmt\ClassLike
    {
        $node = (new NodeFinder)->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassLike
            && isset($node->namespacedName) && strcasecmp($node->namespacedName->toString(), $name) === 0);
        return $node instanceof Node\Stmt\ClassLike ? $node : null;
    }

    private function directParent(Node\Stmt\Class_ $class, string $parent): bool
    {
        foreach ([...$class->implements, ...($class->extends === null ? [] : [$class->extends])] as $name) {
            if (strcasecmp($name->toString(), $parent) === 0) {
                return true;
            }
        }
        return false;
    }

    private function sameFile(?string $left, string $right): bool
    {
        return $left !== null && strcasecmp(str_replace('\\', '/', $left), str_replace('\\', '/', $right)) === 0;
    }
}
