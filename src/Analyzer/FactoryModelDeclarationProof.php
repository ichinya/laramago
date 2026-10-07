<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ClassLikeStringKind;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\Variance;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/** Current source/native declaration agreement, not a lifetime immutability theorem. */
final class FactoryModelDeclarationProof
{
    public array $stages = [];
    public function __construct(private readonly string $root, private readonly string $factory, private readonly string $model) {}
    public function current(IssueFilterContext $context, string $class, string $expectedModel): bool
    {
        $this->stages = ['class' => $class, 'expectedModel' => $expectedModel];
        $child = $context->codebase->getClass($class); $parent = $context->codebase->getClass($this->factory);
        $field = $context->codebase->getProperty($class, '$model'); $declaring = $context->codebase->getDeclaringProperty($class, '$model');
        $parentField = $context->codebase->getProperty($this->factory, '$model'); $parentDeclaring = $context->codebase->getDeclaringProperty($this->factory, '$model');
        $childFile = $this->read($context->file, $context->contents); $parentFile = $parent === null ? null : $this->read($parent->location->file);
        $childNode = $childFile === null ? null : $this->classNode($childFile['nodes'], $class); $parentNode = $parentFile === null ? null : $this->classNode($parentFile['nodes'], $this->factory);
        $guards = ['currentSource' => $childFile !== null && $parentFile !== null,
            'ordinaryCurrentChild' => $this->ordinaryClass($child, $childNode, $childFile) && ! $childNode->isAbstract(),
            'ordinaryCurrentParent' => $this->ordinaryClass($parent, $parentNode, $parentFile),
            'ownDirectFactoryParent' => $child !== null && strcasecmp($child->directParentClass ?? '', $this->factory) === 0
                && $childNode?->extends !== null && strcasecmp($childNode->extends->toString(), $this->factory) === 0,
            'noChildGenericAliasMixin' => $child !== null && $child->templates === [] && $child->mixins === [] && $child->typeAliases === [],
            'physicalOwnField' => $this->physical($field) && $field == $declaring,
            'physicalParentField' => $this->physical($parentField) && $parentField == $parentDeclaring,
            'nativeGeneralStringDoc' => $field?->type !== null && $field->type->fromDocblock && ! $field->type->inferred && $context->types->equals($field->type->type, Type::string())];
        $this->stages['initial'] = $guards; if (in_array(false, $guards, true)) { return false; }
        [$property, $item] = $this->field($childNode); [$parentProperty, $parentItem] = $this->field($parentNode);
        $generic = $childNode->getAttribute('laramagoFactoryDeclarationExtends');
        if ($property === null || $item === null || $parentProperty === null || $parentItem === null
            || $generic !== [$this->factory, $expectedModel] || $property->type !== null || ! $property->isProtected() || $property->isStatic()
            || $parentProperty->type !== null || ! $parentProperty->isProtected() || $parentProperty->isStatic() || $parentItem->default !== null
            || ! $this->located($field->nameLocation, $item->name, $childFile) || ! $this->located($parentField->nameLocation, $parentItem->name, $parentFile)
            || ! $this->docType($field->type->location, $property, $childFile, 'string')
            || ! $this->annotationMatches($context->issue->annotations[0], $field->type->location, $childFile)
            || ! $item->default instanceof Node\Expr\ClassConstFetch || ! $item->default->class instanceof Node\Name
            || ! $item->default->name instanceof Node\Identifier || strtolower($item->default->name->name) !== 'class'
            || $item->default->class->toString() !== $expectedModel || $field->defaultType === null || $field->defaultType->fromDocblock || ! $field->defaultType->inferred
            || ! $this->literalDefault($field->defaultType->type, $expectedModel) || ! $this->located($field->defaultType->location, $item->default, $childFile)) {
            $this->stages['currentOwnDocDefaultGeneric'] = false; return false;
        }
        $this->stages['currentOwnDocDefaultGeneric'] = true;
        $template = $parent->templates[0] ?? null; $refinement = $parentField->type?->type->atomicTypes[0] ?? null;
        $string = $refinement instanceof ScalarType ? $refinement->refinement : null;
        $constraint = $template?->constraint->atomicTypes[0] ?? null;
        if (count($parent->templates) !== 1 || $template?->name !== 'TModel' || $template->definingEntity->kind !== GenericParentKind::ClassLike
            || strcasecmp($template->definingEntity->name, $this->factory) !== 0 || $template->default !== null || $template->variance !== Variance::Invariant
            || count($template->constraint->atomicTypes) !== 1 || ! $this->plainObject($constraint, $this->model) || $parentNode->getAttribute('laramagoFactoryDeclarationTemplate') !== $this->model
            || $parentField->type === null || ! $parentField->type->fromDocblock || $parentField->type->inferred || count($parentField->type->type->atomicTypes) !== 1
            || ! $refinement instanceof ScalarType || $refinement->kind !== ScalarTypeKind::ClassLikeString || ! $string instanceof ClassLikeStringType
            || $string->variant !== ClassLikeStringVariant::Generic || $string->kind !== ClassLikeStringKind::Class_ || $string->literal !== null || $string->parameterName !== 'TModel'
            || $string->definingEntity?->kind !== GenericParentKind::ClassLike || strcasecmp($string->definingEntity?->name ?? '', $this->factory) !== 0
            || ! $this->plainObject($string->constraint, $this->model) || $parentField->defaultType !== null
            || ! $this->docType($parentField->type->location, $parentProperty, $parentFile, 'class-string<TModel>')
            || ! $this->annotationMatches($context->issue->annotations[1], $parentField->type->location, $parentFile)) {
            $this->stages['parentCurrentNativeGenericDoc'] = false; return false;
        }
        $this->stages['parentCurrentNativeGenericDoc'] = true;
        foreach ([$this->model, $expectedModel] as $modelClass) {
            $metadata = $context->codebase->getClass($modelClass); $file = $metadata === null ? null : $this->read($metadata->location->file);
            $node = $file === null ? null : $this->classNode($file['nodes'], $modelClass);
            if (! $this->ordinaryClass($metadata, $node, $file)
                || $modelClass === $expectedModel && ($node->isAbstract() || ! in_array(strtolower($this->model), array_map('strtolower', [$modelClass, ...$context->codebase->getClassAncestors($modelClass)]), true))) {
                $this->stages['currentModelRole'] = false; return false;
            }
        }
        $this->stages['currentModelRole'] = true;
        $known = [$class, ...$context->codebase->getClassDescendants($class)];
        if (count($known) > 128) { return false; }
        foreach (array_unique($known) as $descendant) {
            $metadata = $context->codebase->getClass($descendant); $file = $metadata === null ? null : $this->read($metadata->location->file);
            $node = $file === null ? null : $this->classNode($file['nodes'], $descendant);
            $effective = $context->codebase->getProperty($descendant, '$model');
            if (! $this->ordinaryClass($metadata, $node, $file) || $effective != $field
                || $context->codebase->getDeclaringProperty($descendant, '$model') != $declaring
                || ! $this->noOwnModelChanges($context, $node, $metadata->usedTraits, [])) {
                $this->stages['knownWriteShadowAliasRef'] = ['class' => $descendant, 'safe' => false]; return false;
            }
        }
        $this->stages['knownWriteShadowAliasRef'] = ['safe' => true, 'scope' => 'Native merged known classes only; no runtime lifetime promise'];
        return true;
    }
    private function physical(?object $field): bool
    {
        if ($field === null || $field->name !== '$model' || $field->location !== null || $field->declaredType !== null || $field->writeType !== null
            || $field->readVisibility !== Visibility::Protected || $field->writeVisibility !== Visibility::Protected || $field->hooks !== []
            || $field->type?->type->flags->byReference || $field->type?->type->flags->possiblyUndefined) { return false; }
        foreach ([MetadataFlags::STATIC, MetadataFlags::VIRTUAL_PROPERTY, MetadataFlags::READONLY, MetadataFlags::ASYMMETRIC_PROPERTY, MetadataFlags::BY_REFERENCE, MetadataFlags::WRITEONLY] as $flag) { if ($field->flags->contains($flag)) { return false; } }
        return true;
    }
    private function ordinaryClass(?object $metadata, ?Node\Stmt\Class_ $node, ?array $file): bool
    {
        return $metadata !== null && $metadata->kind === ClassLikeKind::Class_ && ! $metadata->hasIncompleteHierarchy() && $node !== null && $file !== null
            && strcasecmp($metadata->name, $node->namespacedName?->toString() ?? '') === 0 && $this->located($metadata->location, $node, $file)
            && $this->located($metadata->nameLocation, $node->name, $file) && $metadata->flags->contains(MetadataFlags::FINAL) === $node->isFinal()
            && ! $metadata->flags->contains(MetadataFlags::BUILTIN)
            && ! $metadata->flags->contains(MetadataFlags::READONLY) && ! $node->isReadonly()
            && $metadata->flags->contains(MetadataFlags::ABSTRACT) === $node->isAbstract()
            && strcasecmp($metadata->directParentClass ?? '', $node->extends?->toString() ?? '') === 0;
    }
    private function noOwnModelChanges(IssueFilterContext $context, Node\Stmt\ClassLike $node, array $nativeTraits, array $visited): bool
    {
        $finder = new NodeFinder;
        $owner = $node->namespacedName?->toString();
        $metadata = $owner === null ? null : $context->codebase->getClassLike($owner);
        $closure = $metadata === null ? null : $this->mergedTraitClosure($context, $node, $metadata, []);
        $expectedNative = array_values(array_unique(array_map('strtolower', $nativeTraits))); sort($expectedNative);
        if ($closure === null || $closure !== $expectedNative) { return false; }
        // A source-owned trait must not introduce a competing storage declaration.
        if ($node instanceof Node\Stmt\Trait_) {
            foreach ($node->getProperties() as $property) { foreach ($property->props as $item) { if ($item->name->name === 'model') { return false; } } }
        }
        if ($finder->findFirst($node->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\AssignRef
            || $node instanceof Node\Stmt\ClassMethod && $node->byRef || $node instanceof Node\Param && $node->byRef || $node instanceof Node\Arg && $node->byRef
            || $node instanceof Node\Expr\Variable && ! is_string($node->name) || $node instanceof Node\Expr\Eval_
            || $node instanceof Node\Expr\PropertyFetch && ! $node->name instanceof Node\Identifier
            || $node instanceof Node\Expr\MethodCall && ! $node->name instanceof Node\Identifier
            || $node instanceof Node\Expr\Assign && $node->expr instanceof Node\Expr\Variable && $node->expr->name === 'this') !== null) { return false; }
        foreach ($finder->find($node->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp
            || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec) as $write) {
            if ($write->var instanceof Node\Expr\PropertyFetch && $write->var->name instanceof Node\Identifier && strtolower($write->var->name->name) === 'model') { return false; }
        }
        foreach ($finder->findInstanceOf($node->stmts, Node\Stmt\Unset_::class) as $unset) {
            foreach ($unset->vars as $var) { if ($var instanceof Node\Expr\PropertyFetch && $var->name instanceof Node\Identifier && strtolower($var->name->name) === 'model') { return false; } }
        }
        $traits = [];
        foreach ($node->stmts as $member) { if ($member instanceof Node\Stmt\TraitUse) {
            if ($member->adaptations !== []) { return false; }
            foreach ($member->traits as $trait) { $traits[] = strtolower($trait->toString()); }
        } }
        sort($traits);
        if (count($traits) > 32) { return false; }
        foreach ($traits as $name) {
            if (in_array($name, $visited, true) || count($visited) > 32) { return false; }
            $metadata = $context->codebase->getClassLike($name); $file = $metadata === null ? null : $this->read($metadata->location->file);
            $matches = $file === null ? [] : $finder->find($file['nodes'], static fn (Node $node): bool => $node instanceof Node\Stmt\Trait_ && strcasecmp($node->namespacedName?->toString() ?? '', $name) === 0);
            if ($metadata?->kind !== ClassLikeKind::Trait || count($matches) !== 1 || ! $this->located($metadata->location, $matches[0], $file)
                || ! $this->noOwnModelChanges($context, $matches[0], $metadata->usedTraits, [...$visited, $name])) { return false; }
        }
        return true;
    }

    /** Source/native merged trait declaration agreement; this does not certify inherited bodies free of effects. */
    private function mergedTraitClosure(IssueFilterContext $context, Node\Stmt\ClassLike $node, object $metadata, array $visited): ?array
    {
        $name = strtolower($node->namespacedName?->toString() ?? '');
        if ($name === '' || in_array($name, $visited, true) || count($visited) >= 64 || $metadata->hasIncompleteHierarchy()
            || strtolower($metadata->name) !== $name || $metadata->flags->contains(MetadataFlags::BUILTIN)) { return null; }
        $file = $this->read($metadata->location->file);
        if ($file === null || ! $this->located($metadata->location, $node, $file) || ! $this->located($metadata->nameLocation, $node->name, $file)) { return null; }
        if ($node instanceof Node\Stmt\Class_ ? ! $this->ordinaryClass($metadata, $node, $file)
            : ! $node instanceof Node\Stmt\Trait_ || $metadata->kind !== ClassLikeKind::Trait || $metadata->directParentClass !== null) { return null; }
        $visited[] = $name; $merged = [];
        if ($node instanceof Node\Stmt\Class_ && $node->extends !== null) {
            $parent = $context->codebase->getClass($node->extends->toString());
            $parentFile = $parent === null ? null : $this->read($parent->location->file);
            $parentNode = $parentFile === null ? null : $this->classNode($parentFile['nodes'], $node->extends->toString());
            $inherited = $parentNode === null ? null : $this->mergedTraitClosure($context, $parentNode, $parent, $visited);
            if ($inherited === null) { return null; } $merged = $inherited;
        }
        foreach ($node->stmts as $member) {
            if (! $member instanceof Node\Stmt\TraitUse) { continue; }
            foreach ($member->traits as $trait) {
                $traitName = strtolower($trait->toString()); $traitMetadata = $context->codebase->getTrait($traitName);
                $traitFile = $traitMetadata === null ? null : $this->read($traitMetadata->location->file);
                $matches = $traitFile === null ? [] : (new NodeFinder)->find($traitFile['nodes'], static fn (Node $node): bool => $node instanceof Node\Stmt\Trait_
                    && strtolower($node->namespacedName?->toString() ?? '') === $traitName);
                $nested = count($matches) !== 1 ? null : $this->mergedTraitClosure($context, $matches[0], $traitMetadata, $visited);
                if ($nested === null) { return null; } $merged = [...$merged, $traitName, ...$nested];
            }
        }
        $merged = array_values(array_unique($merged)); sort($merged);
        $native = array_values(array_unique(array_map('strtolower', $metadata->usedTraits))); sort($native);
        return $merged === $native && count($merged) <= 64 ? $merged : null;
    }
    private function plainObject(mixed $atom, string $name): bool
    {
        return $atom instanceof NamedObjectType && strcasecmp($atom->name, $name) === 0 && ($atom->parameters ?? []) === [] && ($atom->variances ?? []) === []
            && ($atom->intersections ?? []) === [] && ! $atom->static && ! $atom->isThis && ! $atom->remappedParameters;
    }
    private function literalDefault(Type $type, string $expected): bool
    {
        if (count($type->atomicTypes) !== 1 || $type->flags->byReference || $type->flags->possiblyUndefined) { return false; }
        $atom = $type->atomicTypes[0]; $value = $atom instanceof ScalarType ? $atom->refinement : null;
        return $atom instanceof ScalarType && $atom->kind === ScalarTypeKind::ClassLikeString && $value instanceof ClassLikeStringType
            && $value->variant === ClassLikeStringVariant::Literal && $value->kind === null && $value->literal === $expected
            && $value->parameterName === null && $value->definingEntity === null && $value->constraint === null;
    }
    private function field(Node\Stmt\Class_ $class): array
    {
        $matches = [];
        foreach ($class->getProperties() as $property) { foreach ($property->props as $item) { if ($item->name->name === 'model') { $matches[] = [$property, $item]; } } }
        return count($matches) === 1 && count($matches[0][0]->props) === 1 ? $matches[0] : [null, null];
    }
    private function docType(?SourceLocation $location, Node\Stmt\Property $property, array $file, string $expected): bool
    {
        $doc = $property->getDocComment();
        return $doc !== null && $location !== null && $location->file !== null && $this->path($location->file) === $file['path']
            && $location->span->start >= $doc->getStartFilePos() && $location->span->end <= $doc->getEndFilePos() + 1
            && substr($file['contents'], $location->span->start, $location->span->length()) === $expected
            && preg_match_all('/@(?:phpstan-|psalm-)?var\b/', $doc->getText()) === 1;
    }
    private function annotationMatches(object $annotation, SourceLocation $location, array $file): bool
    {
        return [$annotation->span->start, $annotation->span->end] === [$location->span->start, $location->span->end]
            && ($annotation->file === null || $this->path($annotation->file) === $file['path']);
    }
    private function read(?string $name, ?string $analyzed = null): ?array
    {
        if ($name === null) { return null; } $path = $this->path($name); $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) > 1024 * 1024 || $analyzed !== null && $analyzed !== $contents) { return null; }
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? []; $resolver = new NameResolver;
            $docs = new class($resolver) extends NodeVisitorAbstract {
                public function __construct(private readonly NameResolver $resolver) {}
                public function enterNode(Node $node): ?Node
                {
                    if (! $node instanceof Node\Stmt\Class_) { return null; }
                    $doc = $node->getDocComment()?->getText() ?? '';
                    $identifier = '[\\\\a-zA-Z_][\\\\a-zA-Z0-9_]*';
                    $resolve = function (string $name): ?string {
                        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) { return null; }
                        $syntax = str_starts_with($name, '\\') ? new Node\Name\FullyQualified(substr($name, 1)) : new Node\Name($name);
                        return $this->resolver->getNameContext()->getResolvedClassName($syntax)->toString();
                    };
                    if (preg_match_all('/@(?:phpstan-|psalm-)?extends\b/', $doc) === 1 && preg_match('/@extends\s+('.$identifier.')<('.$identifier.')>\s*(?:\*\/|\r?\n)/', $doc, $match) === 1) {
                        $node->setAttribute('laramagoFactoryDeclarationExtends', [$resolve($match[1]), $resolve($match[2])]);
                    }
                    if (preg_match_all('/@(?:phpstan-|psalm-)?template(?:-covariant|-contravariant)?\b/', $doc) === 1
                        && preg_match('/@template\s+TModel\s+(?:of|as)\s+('.$identifier.')\s*(?:\*\/|\r?\n)/', $doc, $match) === 1) {
                        $node->setAttribute('laramagoFactoryDeclarationTemplate', $resolve($match[1]));
                    }
                    return null;
                }
            };
            $nodes = (new NodeTraverser($resolver, $docs))->traverse($nodes);
        } catch (\PhpParser\Error) { return null; }
        return ['path' => $path, 'contents' => $contents, 'hash' => hash('sha256', $contents), 'nodes' => $nodes];
    }
    private function classNode(array $nodes, string $name): ?Node\Stmt\Class_
    {
        $nodes = (new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\Class_ && strcasecmp($node->namespacedName?->toString() ?? '', $name) === 0);
        return count($nodes) === 1 ? $nodes[0] : null;
    }
    private function located(?SourceLocation $location, Node $node, array $file): bool
    {
        return $location !== null && $location->file !== null && $this->path($location->file) === $file['path']
            && [$location->span->start, $location->span->end] === [$node->getStartFilePos(), $node->getEndFilePos() + 1]
            && $file['hash'] === hash_file('sha256', $file['path']);
    }
    public function path(string $path): string
    {
        $path = str_replace('\\', '/', $path); if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        if (! str_starts_with($path, '/') && preg_match('~^[A-Za-z]:/~', $path) !== 1) { $path = $this->root.'/'.$path; }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
