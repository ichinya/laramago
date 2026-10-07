<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\{ModelReflection, NativeModelAttributeRefresh, RefreshedModelProperties};
use Mago\Sdk\Analyzer\{IssueFilterContext, Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, MetadataFlags};
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Bind each helper owner field to its physical PHP declaration and genuine native property. */
final class RepeatedRefreshOwnerFields
{
    public array $dependencies = [];
    private array $files = [];

    public function __construct(private readonly string $root = '.') {}

    public function resolve(IssueFilterContext $context, array $proof): ?array
    {
        $origin = $proof['origin']->expr;
        if (! $origin instanceof Node\Expr\MethodCall || ! $origin->var instanceof Node\Expr\Variable || $origin->var->name !== 'this'
            || ! $origin->name instanceof Node\Identifier) { return null; }
        $helper = $proof['callerClass']->getMethod($origin->name->name);
        $method = $context->codebase->getMethod($proof['owner'], $origin->name->name);
        $caller = $context->codebase->getMethod($proof['owner'], $proof['scope']->name->name);
        if ($helper?->stmts === null || ! $helper->returnType instanceof Node\Name || $method?->returnType === null || $caller === null
            || $caller->returnType !== null && $context->types->equals($caller->returnType->type, Type::never())) { return null; }
        $model = $helper->returnType->toString();
        $ownerMetadata = $context->codebase->getClass($proof['owner']);
        $modelMetadata = $context->codebase->getClass($model);
        if ($ownerMetadata === null || $ownerMetadata->kind !== ClassLikeKind::Class_ || $ownerMetadata->hasIncompleteHierarchy()
            || strcasecmp($ownerMetadata->name, $proof['owner']) !== 0 || $modelMetadata === null || $modelMetadata->kind !== ClassLikeKind::Class_
            || strcasecmp($modelMetadata->name, $model) !== 0 || $modelMetadata->hasIncompleteHierarchy()) { return null; }
        $this->dependencies = ['caller' => $caller, 'helper' => $method, 'callerClass' => $ownerMetadata, 'selectedModel' => $modelMetadata];
        $this->files[$proof['diskFile']] = $proof['hash'];
        $fields = [];
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($helper->stmts, Node\Expr\PropertyFetch::class) as $fetch) {
            if (! $fetch->name instanceof Node\Identifier || $fetch->name->name === 'exists') { return null; }
            $base = $fetch->var;
            $nested = null;
            if ($base instanceof Node\Expr\PropertyFetch) { $nested = $fetch->name->name; $fetch = $base; $base = $fetch->var; }
            if (! $base instanceof Node\Expr\Variable || $base->name !== 'this' || ! $fetch->name instanceof Node\Identifier) { return null; }
            $field = $fetch->name->name;
            $physical = null;
            foreach ($proof['callerClass']->getProperties() as $declaration) {
                foreach ($declaration->props as $entry) { if ($entry->name->name === $field) { $physical = [$declaration, $entry]; } }
            }
            if ($physical === null) { return null; }
            [$declaration, $entry] = $physical;
            if (! $declaration->type instanceof Node\Name || $declaration->isStatic() || $entry->default !== null || $declaration->hooks !== []) { return null; }
            $class = $declaration->type->toString();
            $metadata = $context->codebase->getProperty($proof['owner'], '$'.$field);
            $declaring = $context->codebase->getDeclaringProperty($proof['owner'], '$'.$field);
            $visibility = $declaration->isPrivate() ? Visibility::Private : ($declaration->isProtected() ? Visibility::Protected : Visibility::Public);
            if ($metadata === null || $declaring === null || $metadata != $declaring || $metadata->name !== '$'.$field || $metadata->hooks !== []
                || $metadata->readVisibility !== $visibility || $metadata->writeVisibility !== ($declaration->isReadonly() ? Visibility::Protected : $visibility)
                || $metadata->flags->contains(MetadataFlags::STATIC) || $metadata->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)
                || $metadata->flags->contains(MetadataFlags::WRITEONLY) || $metadata->flags->contains(MetadataFlags::PROMOTED_PROPERTY)
                || $metadata->flags->contains(MetadataFlags::HAS_DEFAULT) || $metadata->flags->contains(MetadataFlags::READONLY) !== $declaration->isReadonly()
                || $metadata->declaredType === null || $metadata->type === null || $metadata->declaredType->fromDocblock || $metadata->type->fromDocblock
                || ! self::located($metadata->nameLocation, $entry->name, $proof['file'])
                || ! self::located($metadata->declaredType->location, $declaration->type, $proof['file'])
                || ! self::located($metadata->type->location, $declaration->type, $proof['file'])
                || ! $context->types->equals($metadata->declaredType->type, Type::namedObject($class))
                || ! $context->types->equals($metadata->type->type, Type::namedObject($class))
                || $metadata->writeType !== null && ! $context->types->equals($metadata->writeType->type, $metadata->type->type)) { return null; }
            $other = $context->codebase->getClass($class);
            if ($other === null || $other->kind !== ClassLikeKind::Class_ || $other->hasIncompleteHierarchy() || strcasecmp($other->name, $class) !== 0
                || strcasecmp($class, $model) === 0 || in_array(strtolower($model), array_map('strtolower', $other->parentClasses), true)
                || in_array(strtolower($class), array_map('strtolower', $modelMetadata->parentClasses), true)) { return null; }
            $this->dependencies['ownerField:'.$field] = $metadata;
            $this->dependencies['ownerClass:'.$field] = $other;
            $fields[$field] = ($fields[$field] ?? []) + ['class' => $class, 'propertyNameSpan' => RefreshedModelProperties::key($entry->name),
                'declaredTypeSpan' => RefreshedModelProperties::key($declaration->type)];
            if ($nested !== null) {
                // A model's id read still uses the existing audited native primitive read chain.
                if (! in_array(strtolower(ModelReflection::MODEL), array_map('strtolower', $other->parentClasses), true)
                    || $context->codebase->getMethod($class, 'get'.ModelReflection::studly($nested).'Attribute') !== null
                    || $context->codebase->getMethod($class, lcfirst(ModelReflection::studly($nested))) !== null
                    || ! (new NativeModelAttributeRefresh($this->root))->reads($context->codebase, $class, [$nested])) { return null; }
                $fields[$field]['nestedRead'] = $nested;
            }
        }
        if ($fields === []) { return null; }
        $assertion = $context->codebase->getMethod('Testo\\Assert', 'same');
        $refresh = $context->codebase->getMethod($model, 'refresh') ?? $context->codebase->getDeclaringMethod($model, 'refresh');
        if ($assertion === null || $refresh === null) { return null; }
        $this->dependencies['assertion'] = $assertion;
        $this->dependencies['refresh'] = $refresh;

        return $fields;
    }

    public function current(): bool
    {
        foreach ($this->files as $file => $hash) { if (@hash_file('sha256', $file) !== $hash) { return false; } }

        return true;
    }

    private static function located(?SourceLocation $location, Node $syntax, string $file): bool
    {
        return $location !== null && RefreshedModelProperties::path($location->file ?? '') === RefreshedModelProperties::path($file)
            && $location->span->start === $syntax->getStartFilePos() && $location->span->end === $syntax->getEndFilePos() + 1;
    }
}
