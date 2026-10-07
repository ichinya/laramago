<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\{ModelReflection, PhpSource};
use Mago\Sdk\Analyzer\{IssueFilterContext, Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;
/** Current native caller and physical callback storage; no invocation-state authority. */
final class CapturedThisStorageContract
{
    public static function prove(IssueFilterContext $context, string $root, Node\Stmt\ClassMethod $scope, object $caller, Node\Expr\MethodCall $registration): array
    {
        $receipt = ['admitted' => false, 'authority' => 'current issue metadata and current physical declarations', 'usesInvocationHookState' => false, 'stage' => 'source-registration-receiver'];
        if (!$registration->var instanceof Node\Expr\Variable || !is_string($registration->var->name) || !$registration->name instanceof Node\Identifier || strcasecmp($registration->name->name, 'booting') !== 0) {
            return $receipt;
        }
        $formal = null;
        $index = null;
        foreach ($scope->params as $i => $parameter) {
            if ($parameter->var instanceof Node\Expr\Variable && $parameter->var->name === $registration->var->name) {
                $formal = $parameter;
                $index = $i;
                break;
            }
        }
        $finder = new \PhpParser\NodeFinder();
        if ($formal === null) {
            $binding = CapturedThisLocalBinding::source($scope, $registration);
            if ($binding === null) { return $receipt; }
            $local = CapturedThisLocalBinding::native($context, $scope, $caller, $binding);
            $receipt['localBinding'] = $local;
            if (!$local['admitted']) { return $receipt; }
            foreach ($binding['uses'] as $use) {
                if ($use['kind'] !== 'append-only-callback-storage') { continue; }
                $prior = self::prove($context, $root, $scope, $caller, $use['call']);
                if (!$prior['admitted']) { $receipt['priorStorage'] = $prior; return $receipt; }
            }
            $receiver = Type::namedObject($binding['class'])->atomicTypes[0];
        } else {
        if (!$formal->type instanceof Node\Name\FullyQualified || $formal->byRef || $formal->variadic || $formal->default !== null) {
            return $receipt;
        }
        $native = $caller->parameters[$index] ?? null;
        $expected = Type::namedObject($formal->type->toString());
        $receipt['stage'] = 'current-native-caller-formal';
        $receipt['callerFormal'] = $native === null ? null : ['name' => $native->name, 'flags' => $native->flags->bits, 'declaredType' => (string) $native->declaredType?->type, 'effectiveType' => (string) $native->type?->type, 'span' => [$native->location->span->start, $native->location->span->end], 'sourceSpan' => [$formal->getStartFilePos(), $formal->getEndFilePos() + 1]];
        if ($native === null || $native->name !== '$' . $formal->var->name || $native->declaredType === null || $native->type === null || $native->declaredType->fromDocblock || $native->declaredType->inferred || $native->outType !== null || $native->closureThisType !== null || $native->flags->contains(MetadataFlags::BY_REFERENCE) || $native->flags->contains(MetadataFlags::VARIADIC) || !$context->types->equals($native->declaredType->type, $expected) || !$context->types->equals($native->type->type, $expected) || [$native->location->span->start, $native->location->span->end] !== [$formal->getStartFilePos(), $formal->getEndFilePos() + 1]) {
            return $receipt;
        }
        $receiver = count($native->type->type->atomicTypes) === 1 ? $native->type->type->atomicTypes[0] : null;
        if (!$receiver instanceof NamedObjectType || $receiver->static || $receiver->isThis || ($receiver->parameters ?? []) !== [] || ($receiver->intersections ?? []) !== []) {
            return $receipt;
        }
        // Any other source mention before registration may change the caller binding.
        $finder = new \PhpParser\NodeFinder();
        if ($finder->findFirst($scope->stmts ?? [], static fn(Node $node): bool => $node instanceof Node\Expr\Variable && $node->name === $formal->var->name && $node->getStartFilePos() < $registration->getStartFilePos()) !== null) {
            return $receipt;
        }
        }
        $method = $context->codebase->getDeclaringMethod($receiver->name, 'booting');
        $source = new PhpSource($root);
        $node = $method === null ? null : (new ModelReflection($context->codebase, $source))->methodNode($method);
        $owner = $method === null ? null : $context->codebase->getClass($method->identifier->class);
        $receipt['stage'] = 'current-native-callback-method';
        $receipt['callbackMethod'] = $method === null ? null : ['owner' => $method->identifier->class, 'name' => $method->originalName, 'flags' => $method->flags->bits, 'file' => $method->location->file, 'span' => [$method->location->span->start, $method->location->span->end], 'parameterNames' => array_column($method->parameters, 'name')];
        if ($method === null || $node === null || $owner === null || $owner->hasIncompleteHierarchy() || $owner->templates !== [] || $owner->mixins !== [] || $method->static || $method->abstract || $method->flags->contains(MetadataFlags::BY_REFERENCE) || $node->byRef || count($method->parameters) !== 1 || count($node->params) !== 1 || count($node->stmts ?? []) !== 1 || $node->params[0]->byRef || $node->params[0]->variadic || $method->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE) || $method->parameters[0]->outType !== null || $method->parameters[0]->closureThisType !== null || preg_match('/@(?:param-closure-this|phpstan-param-closure-this|psalm-param-closure-this)\b/', $node->getDocComment()?->getText() ?? '') === 1) {
            return $receipt;
        }
        $statement = $node->stmts[0];
        $assignment = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
        $field = $assignment instanceof Node\Expr\Assign && $assignment->var instanceof Node\Expr\ArrayDimFetch && $assignment->var->dim === null ? $assignment->var->var : null;
        if (!$field instanceof Node\Expr\PropertyFetch || !$field->var instanceof Node\Expr\Variable || $field->var->name !== 'this' || !$field->name instanceof Node\Identifier || !$assignment->expr instanceof Node\Expr\Variable || $assignment->expr->name !== $node->params[0]->var->name) {
            return $receipt;
        }
        $property = $context->codebase->getDeclaringProperty($owner->name, '$' . $field->name->name);
        $receipt['stage'] = 'current-native-physical-callback-field';
        $receipt['callbackField'] = $property === null ? null : ['name' => $property->name, 'flags' => $property->flags->bits, 'file' => $property->location?->file, 'hooks' => array_keys($property->hooks)];
        if ($property === null || $property->hooks !== [] || $property->flags->contains(MetadataFlags::VIRTUAL_PROPERTY) || $property->flags->contains(MetadataFlags::BY_REFERENCE) || $property->flags->contains(MetadataFlags::STATIC)) {
            return $receipt;
        }
        $declarations = $source->read($owner->location->file);
        $physicalOwner = $declarations === null ? null : $finder->findFirst($declarations, static fn(Node $node): bool => $node instanceof Node\Stmt\Class_ && isset($node->namespacedName) && strcasecmp($node->namespacedName->toString(), $owner->name) === 0 && $node->getStartFilePos() === $owner->location->span->start && $node->getEndFilePos() + 1 === $owner->location->span->end);
        $physicalFields = [];
        foreach ($physicalOwner?->getProperties() ?? [] as $declaration) {
            foreach ($declaration->props as $item) {
                if ($item->name->name === $field->name->name) {
                    $physicalFields[] = [$declaration, $item];
                }
            }
        }
        if (count($physicalFields) !== 1 || $physicalFields[0][0]->isStatic() || $physicalFields[0][0]->attrGroups !== [] || ($physicalFields[0][0]->hooks ?? []) !== [] || $property->name !== '$' . $physicalFields[0][1]->name->name) {
            return $receipt;
        }
        $receipt['callbackField']['physicalDeclarationSpan'] = [$physicalFields[0][0]->getStartFilePos(), $physicalFields[0][0]->getEndFilePos() + 1];
        $receipt['callbackField']['nativeLocationOptionalBecausePhysicalOwnerAndFieldAreProven'] = $property->location === null;
        $disk = $source->path($method->location->file);
        $hash = @hash_file('sha256', $disk);
        if ($hash === false) {
            return $receipt;
        }
        $receipt['declarationSha256'] = $hash;
        $receipt['admitted'] = true;
        $receipt['stage'] = 'physical-callback-storage-admitted';
        return $receipt;
    }
}
