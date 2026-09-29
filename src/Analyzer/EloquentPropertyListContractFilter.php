<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\PropertyMetadata;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\SourceLocation;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Apply Larastan's list<string> contract to native Eloquent attribute name lists. */
final class EloquentPropertyListContractFilter implements IssueFilterHook, InitializationHook
{
    /** @var array<string, list<Node>> */
    private array $parsed = [];
    private ?PhpSource $source = null;

    public function __construct(private readonly string $root = '.') {}

    public function initialize(InitializationContext $context): void
    {
        $this->parsed = [];
        $this->source = null;
    }

    public function getCodes(): array
    {
        return ['incompatible-property-type'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== 'incompatible-property-type' || strlen($context->contents) > 1024 * 1024
            || ! preg_match('/^Property `([^`]+)::\$(fillable|hidden)` has an incompatible type declaration from docblock\.$/D', $context->issue->message, $match)) {
            return IssueFilterDecision::Keep;
        }
        $primary = null;
        $secondary = null;
        foreach ($context->issue->annotations as $annotation) {
            if ($annotation->kind === AnnotationKind::Primary) {
                if ($primary !== null || $annotation->file !== null && $annotation->file !== '') {
                    return IssueFilterDecision::Keep;
                }
                $primary = $annotation;
            } elseif ($annotation->kind === AnnotationKind::Secondary) {
                if ($secondary !== null) {
                    return IssueFilterDecision::Keep;
                }
                $secondary = $annotation;
            }
        }
        if ($primary === null || $secondary === null) {
            return IssueFilterDecision::Keep;
        }

        $class = $context->codebase->getClass($match[1]);
        $name = '$'.$match[2];
        $trait = 'Illuminate\\Database\\Eloquent\\Concerns\\'.($match[2] === 'fillable' ? 'GuardsAttributes' : 'HidesAttributes');
        if ($class === null || $class->kind !== ClassLikeKind::Class_ || $class->hasIncompleteHierarchy()
            || $class->directParentClass === null || strcasecmp($class->name, ModelReflection::MODEL) === 0
            || ! $context->types->isContainedBy(Type::namedObject($class->name), Type::namedObject(ModelReflection::MODEL))) {
            return IssueFilterDecision::Keep;
        }
        $child = $context->codebase->getProperty($class->name, $name);
        $parent = $context->codebase->getDeclaringProperty($class->directParentClass, $name);
        $native = $context->codebase->getProperty($trait, $name);
        $model = $context->codebase->getDeclaringProperty(ModelReflection::MODEL, $name);
        if (! self::plainProperty($child) || ! self::plainProperty($parent) || ! self::plainProperty($native)
            || $model === null || ! $child->type->fromDocblock || ! $parent->type->fromDocblock
            || ! $this->sameLocation($parent->type->location, $native->type->location)
            || ! $this->sameLocation($model->type?->location, $native->type->location)
            || $primary->span->start !== $child->type->location->span->start
            || $primary->span->end !== $child->type->location->span->end
            || ! $this->sameFile($child->type->location->file, $context->file)
            || ! $this->sameLocation(new SourceLocation($secondary->file, $secondary->span), $parent->type->location)) {
            return IssueFilterDecision::Keep;
        }
        $childType = $child->type->type;
        $parentType = $parent->type->type;
        $list = count($childType->atomicTypes) === 1 ? $childType->atomicTypes[0] : null;
        $array = count($parentType->atomicTypes) === 1 ? $parentType->atomicTypes[0] : null;
        $expectedKeys = $match[2] === 'fillable' ? Type::int() : Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey, null));
        if (! $list instanceof ListType || $list->nonEmpty || ($list->knownElements ?? []) !== [] || $list->knownCount !== null
            || ! $context->types->equals($list->elementType, Type::string())
            || ! $array instanceof KeyedArrayType || $array->nonEmpty || ($array->knownItems ?? []) !== []
            || $array->keyType === null || $array->valueType === null
            || ! $context->types->equals($array->keyType, $expectedKeys)
            || ! $context->types->equals($array->valueType, Type::string())) {
            return IssueFilterDecision::Keep;
        }
        $nodes = $this->nodes($context);
        $nativeClass = $context->codebase->getClassLike($trait);
        if ($nodes === null || $nativeClass === null || $nativeClass->kind !== ClassLikeKind::Trait
            || $nativeClass->location->file === null || $nativeClass->hasIncompleteHierarchy()) {
            return IssueFilterDecision::Keep;
        }
        $source = $this->source ??= new PhpSource($this->root);
        $nativeNodes = $source->read($nativeClass->location->file);
        return $nativeNodes !== null
            && $this->declares($nodes, $class->name, $name, $child->type->location)
            && $this->declares($nativeNodes, $trait, $name, $native->type->location, true)
            ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }

    private static function plainProperty(?PropertyMetadata $property): bool
    {
        if ($property === null || $property->type === null || ! $property->type->fromDocblock || $property->type->inferred
            || $property->declaredType !== null || $property->writeType !== null
            || $property->hooks !== [] || $property->readVisibility !== Visibility::Protected
            || $property->writeVisibility !== Visibility::Protected) {
            return false;
        }
        foreach ([MetadataFlags::STATIC, MetadataFlags::READONLY, MetadataFlags::VIRTUAL_PROPERTY,
            MetadataFlags::PROMOTED_PROPERTY, MetadataFlags::ASYMMETRIC_PROPERTY] as $flag) {
            if ($property->flags->contains($flag)) {
                return false;
            }
        }
        return true;
    }

    private function sameFile(?string $first, ?string $second): bool
    {
        if ($first === null || $second === null) {
            return false;
        }
        $source = $this->source ??= new PhpSource($this->root);
        $a = str_replace('\\', '/', $source->path($first));
        $b = str_replace('\\', '/', $source->path($second));
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    private function sameLocation(?SourceLocation $first, ?SourceLocation $second): bool
    {
        return $first !== null && $second !== null && $this->sameFile($first->file, $second->file)
            && $first->span->start === $second->span->start && $first->span->end === $second->span->end;
    }

    /** @return list<Node>|null */
    private function nodes(IssueFilterContext $context): ?array
    {
        $key = hash('sha256', $context->file."\0".$context->contents);
        if (! isset($this->parsed[$key])) {
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (Error) {
                return null;
            }
            if (count($this->parsed) >= 16) {
                unset($this->parsed[array_key_first($this->parsed)]);
            }
            $this->parsed[$key] = $nodes;
        }
        return $this->parsed[$key];
    }

    /** @param array<array-key, Node> $nodes */
    private function declares(array $nodes, string $owner, string $name, SourceLocation $type, bool $native = false): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $class) {
            if ($class->namespacedName === null || strcasecmp($class->namespacedName->toString(), $owner) !== 0) {
                continue;
            }
            foreach ($class->getProperties() as $property) {
                if ($property->type !== null || ! $property->isProtected() || $property->isStatic()
                    || $property->isReadonly() || count($property->props) !== 1 || $property->hooks !== []) {
                    continue;
                }
                $item = $property->props[0];
                $doc = $property->getDocComment();
                if ('$'.$item->name->toString() !== $name || $doc === null
                    || $type->span->start < $doc->getStartFilePos() || $type->span->end > $doc->getEndFilePos() + 1) {
                    continue;
                }
                if (! $native) {
                    return true;
                }
                // Verify the source declaration still agrees with the frozen metadata.
                $spelling = substr($doc->getText(), $type->span->start - $doc->getStartFilePos(), $type->span->end - $type->span->start);
                $spelling = preg_replace('/\s+/', '', $spelling);
                $expected = $name === '$fillable' ? ['array<int,string>'] : ['array<string>', 'array<array-key,string>'];
                return in_array($spelling, $expected, true) && $item->default instanceof Node\Expr\Array_ && $item->default->items === [];
            }
        }
        return false;
    }
}
