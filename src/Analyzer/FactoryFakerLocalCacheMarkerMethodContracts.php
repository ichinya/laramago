<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeKind, FunctionLikeMetadata, MetadataFlags, TypeMetadata};
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\{CallableType, GenericParameterType, GenericParent, GenericParentKind, KeyedArrayType,
    MixedTruthiness, MixedType, NamedObjectType, ScalarType, ScalarTypeKind, SimpleAtomicType, SimpleAtomicTypeKind,
    StringCasing, StringLiteralKind, StringType, Variance};
use Mago\Sdk\Analyzer\Type\FunctionLikeKind as IdentifierKind;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;

/**
 * Five physical marker/effect signatures only. The importer owns their exact
 * current source/body certificates, conditional trait dispatch and root/key
 * priority. Container::instance may invoke rebound callbacks; this signature
 * is not a claim of global purity or a guarantee that a callback is executed.
 */
final class FactoryFakerLocalCacheMarkerMethodContracts
{
    private const APPLICATION = 'Illuminate\\Foundation\\Application';
    private const APPLICATION_CONTRACT = 'Illuminate\\Contracts\\Foundation\\Application';
    private const CONTAINER = 'Illuminate\\Container\\Container';

    /** A compact syntax recipe; this does not create a native declaration. */
    public static function source(Node\Stmt\ClassMethod $method, string $class, ?array $fileNodes = null): ?array
    {
        $spec = self::spec($class, $method->name->name); $doc = $method->getDocComment();
        if ($spec === null || $doc === null || $method->byRef || $method->attrGroups !== [] || $method->stmts === null
            || $method->isAbstract() || $method->isStatic() !== $spec['static']
            || ($method->isProtected() ? 'Protected' : ($method->isPrivate() ? 'Private' : 'Public')) !== $spec['visibility']
            || self::syntax($method->returnType) !== $spec['declaredReturn'] || count($method->params) !== count($spec['parameters'])) { return null; }
        $text = $doc->getText(); $tags = [];
        if (preg_match('/@(?:phpstan-|psalm-|param-out\b|param-closure-this\b|assert\b|template-(?:covariant|contravariant)\b)/i', $text)) { return null; }
        preg_match_all('/@([a-z][a-z-]*)[ \t]+([^\r\n]*)/i', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $index => [$name]) {
            $value = rtrim($matches[2][$index][0], " \t");
            $start = $doc->getStartFilePos() + $matches[2][$index][1];
            if ($name === 'param') {
                if (!preg_match('/^(.+?)[ \t]+(\$[a-zA-Z_][a-zA-Z0-9_]*)$/D', $value, $parameter)) { return null; }
                $key = 'param:'.$parameter[2]; $value = $parameter[1];
            } elseif (in_array($name, ['return', 'template'], true)) { $key = $name; }
            else { return null; }
            if (isset($tags[$key])) { return null; }
            $tags[$key] = ['text' => $value, 'span' => [$start, $start + strlen($value)]];
        }
        $expected = [];
        if ($spec['template'] !== null) { $expected['template'] = 'TInstance of mixed'; }
        $formals = [];
        foreach ($method->params as $index => $parameter) {
            $formal = $spec['parameters'][$index];
            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)
                || '$'.$parameter->var->name !== $formal['name'] || $parameter->byRef || $parameter->variadic
                || $parameter->default !== null || $parameter->flags !== 0 || $parameter->attrGroups !== []
                || self::syntax($parameter->type) !== $formal['declared']) { return null; }
            if ($formal['doc'] !== null) { $expected['param:'.$formal['name']] = $formal['doc']; }
            $formals[] = $formal + ['span' => self::span($parameter), 'nameSpan' => self::span($parameter->var),
                'declaredSpan' => $parameter->type === null ? null : self::span($parameter->type)];
        }
        if ($spec['returnDoc'] !== null) { $expected['return'] = $spec['returnDoc']; }
        if (count($tags) !== count($expected)) { return null; }
        foreach ($expected as $key => $value) {
            if (!isset($tags[$key]) || preg_replace('/[ \t]+/', ' ', $tags[$key]['text']) !== $value) { return null; }
        }
        $callbackApplication = null;
        if (strcasecmp($class, 'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration') === 0) {
            $callbackApplication = self::callbackApplicationImport($fileNodes);
            if ($callbackApplication === null) { return null; }
        }
        return $spec + ['class' => $class, 'name' => $method->name->name, 'span' => self::span($method),
            'nameSpan' => self::span($method->name), 'docSpan' => [$doc->getStartFilePos(), $doc->getEndFilePos() + 1],
            'docSha256' => hash('sha256', $text), 'tags' => $tags, 'formals' => $formals,
            'callbackApplication' => $callbackApplication,
            'declaredReturnSpan' => $method->returnType === null ? null : self::span($method->returnType)];
    }

    /** Evaluates a supplied genuine declaring-method snapshot, never a base-owner substitute. */
    public static function current(IssueFilterContext $context, FunctionLikeMetadata $native, array $source,
        string $absolutePath, string $projectRoot): array
    {
        $result = ['admitted' => false, 'stage' => 'current physical marker/effect signature',
            'nativeProfile' => DefensiveBoundaryGuardProof::compact($native), 'nativeTypesChanged' => false];
        $path = self::path($absolutePath, $projectRoot);
        if ($path === null || !isset($source['class'], $source['name'], $source['formals'], $source['tags'])
            || self::spec($source['class'], $source['name']) === null) { return $result; }
        $contents = @file_get_contents($path); $doc = $contents === false ? null : self::slice($contents, $source['docSpan']);
        if ($doc === null || hash('sha256', $doc) !== $source['docSha256']) { return $result; }
        if (strcasecmp($source['class'], 'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration') === 0) {
            $import = $source['callbackApplication'] ?? null;
            if (!is_array($import) || ($import['resolvedName'] ?? null) !== self::APPLICATION_CONTRACT
                || self::slice($contents, $import['namespaceSpan']) !== $import['namespaceText']
                || self::slice($contents, $import['nameSpan']) !== $import['resolvedName']
                || self::slice($contents, $import['useSpan']) !== $import['useText']) { return $result; }
            $result['callbackApplication'] = $import;
        }
        $hash = hash('sha256', $contents);
        $result['sourceHashes'] = [$path => $hash];
        if ($native->kind !== FunctionLikeKind::Method || $native->identifier->kind !== IdentifierKind::Method
            || strcasecmp($native->identifier->class ?? '', $source['class']) !== 0
            || strcasecmp($native->identifier->name, $source['name']) !== 0
            || $native->static !== $source['static'] || $native->abstract || $native->constructor
            || $native->visibility?->name !== $source['visibility'] || !$native->hasDocblock
            || $native->flags->contains(MetadataFlags::BUILTIN) || $native->flags->contains(MetadataFlags::MAGIC_METHOD)
            || $native->flags->contains(MetadataFlags::BY_REFERENCE) || $native->whereConstraints !== []
            || !self::located($native->location, $path, $source['span'], $projectRoot)
            || !self::located($native->nameLocation, $path, $source['nameSpan'], $projectRoot)
            || count($native->parameters) !== count($source['formals'])) { return $result; }
        $result['stage'] = 'current marker/effect generic declaration';
        if ($source['template'] === null) {
            if ($native->templates !== []) { return $result; }
        } else {
            if (count($native->templates) !== 1) { return $result; }
            $template = $native->templates[0];
            if ($template->name !== 'TInstance' || $template->default !== null || $template->readonly
                || $template->variance !== Variance::Invariant || !self::owner($template->definingEntity, $source)
                || !self::domain($template->constraint, 'mixed', $source)) { return $result; }
        }
        foreach ($source['formals'] as $index => $formal) {
            $parameter = $native->parameters[$index]; $result['stage'] = 'current marker/effect formal '.$formal['name'];
            if ($parameter->name !== $formal['name'] || $parameter->attributes !== [] || $parameter->outType !== null
                || $parameter->closureThisType !== null || $parameter->defaultType !== null
                || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
                || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
                || !self::located($parameter->location, $path, $formal['span'], $projectRoot)
                || !self::located($parameter->nameLocation, $path, $formal['nameSpan'], $projectRoot)) { return $result; }
            if ($formal['declared'] === null) {
                if ($parameter->declaredType !== null) { return $result; }
            } elseif (!self::metadata($parameter->declaredType, false, $path, $formal['declaredSpan'], $projectRoot)
                || !self::domain($parameter->declaredType->type, $formal['declared'], $source, true)) { return $result; }
            $fromDoc = $formal['doc'] !== null;
            $typeSpan = $fromDoc ? $source['tags']['param:'.$formal['name']]['span'] : $formal['declaredSpan'];
            if (!self::metadata($parameter->type, $fromDoc, $path, $typeSpan, $projectRoot)
                || !self::domain($parameter->type->type, $fromDoc ? $formal['doc'] : $formal['declared'], $source)) { return $result; }
        }
        $result['stage'] = 'current marker/effect return signature';
        if ($source['declaredReturn'] === null) {
            if ($native->declaredReturnType !== null) { return $result; }
        } elseif (!self::metadata($native->declaredReturnType, false, $path, $source['declaredReturnSpan'], $projectRoot)
            || !self::domain($native->declaredReturnType->type, $source['declaredReturn'], $source)) { return $result; }
        $docReturn = $source['returnDoc'] !== null;
        $span = $docReturn ? $source['tags']['return']['span'] : $source['declaredReturnSpan'];
        if (!self::metadata($native->returnType, $docReturn, $path, $span, $projectRoot)
            || !self::domain($native->returnType->type, $docReturn ? $source['returnDoc'] : $source['declaredReturn'], $source)
            || @hash_file('sha256', $path) !== $hash) { return $result; }
        $result['stage'] = 'current physical marker/effect signature admitted'; $result['admitted'] = true;
        return $result;
    }

    private static function spec(string $class, string $method): ?array
    {
        $key = strtolower($class.'::'.$method);
        $marker = in_array($key, ['illuminate\\foundation\\testing\\withcachedconfig::markconfigcached',
            'illuminate\\foundation\\testing\\withcachedroutes::markroutescached'], true);
        if ($marker) {
            return ['static' => false, 'visibility' => 'Protected', 'template' => null, 'declaredReturn' => 'void', 'returnDoc' => null,
                'parameters' => [['name' => '$app', 'declared' => self::APPLICATION, 'doc' => null]]];
        }
        return match ($key) {
            'illuminate\\foundation\\bootstrap\\loadconfiguration::alwaysuse' =>
                ['static' => true, 'visibility' => 'Public', 'template' => null, 'declaredReturn' => 'void', 'returnDoc' => 'void',
                    'parameters' => [['name' => '$alwaysUseConfig', 'declared' => '?Closure', 'doc' => '(Closure(Application): array<array-key, mixed>)|null']]],
            'illuminate\\foundation\\support\\providers\\routeserviceprovider::loadcachedroutesusing' =>
                ['static' => true, 'visibility' => 'Public', 'template' => null, 'declaredReturn' => null, 'returnDoc' => 'void',
                    'parameters' => [['name' => '$routesCallback', 'declared' => '?Closure', 'doc' => '\\Closure|null']]],
            'illuminate\\container\\container::instance' =>
                ['static' => false, 'visibility' => 'Public', 'template' => 'TInstance', 'declaredReturn' => null, 'returnDoc' => 'TInstance',
                    'parameters' => [['name' => '$abstract', 'declared' => null, 'doc' => 'string'],
                        ['name' => '$instance', 'declared' => null, 'doc' => 'TInstance']]],
            default => null,
        };
    }

    private static function domain(Type $type, ?string $name, array $source, bool $declared = false): bool
    {
        if ($name === '?Closure' || $name === '\\Closure|null' || $name === '(Closure(Application): array<array-key, mixed>)|null') {
            $typedClosure = $name === '(Closure(Application): array<array-key, mixed>)|null';
            if (!self::flags($type, $typedClosure) || count($type->atomicTypes) !== 2) { return false; }
            $null = false; $callable = false;
            foreach ($type->atomicTypes as $atomic) {
                if ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null && !$null) { $null = true; }
                elseif (!$callable && self::closure($atomic, $name === '(Closure(Application): array<array-key, mixed>)|null', $name === '?Closure', $source)) { $callable = true; }
                else { return false; }
            }
            return $null && $callable;
        }
        if (count($type->atomicTypes) !== 1) { return false; }
        $atomic = $type->atomicTypes[0];
        if ($name === self::APPLICATION || $name === self::APPLICATION_CONTRACT) {
            if ($name === self::APPLICATION_CONTRACT && (strcasecmp($source['class'], 'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration') !== 0
                || strcasecmp($source['name'], 'alwaysUse') !== 0)) { return false; }
            return self::flags($type, true) && $atomic instanceof NamedObjectType && strcasecmp($atomic->name, $name) === 0
                && !$atomic->static && !$atomic->isThis && !$atomic->remappedParameters
                && in_array($atomic->parameters, [null, []], true) && in_array($atomic->variances, [null, []], true)
                && in_array($atomic->intersections, [null, []], true);
        }
        if ($name === 'TInstance') {
            return self::flags($type, true) && $atomic instanceof GenericParameterType && $atomic->name === 'TInstance'
                && self::owner($atomic->definingEntity, $source) && in_array($atomic->intersections, [null, []], true)
                && self::domain($atomic->constraint, 'mixed', $source);
        }
        if (!self::flags($type, false)) { return false; }
        return match ($name) {
            'void' => $atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Void,
            'mixed' => $atomic instanceof MixedType && !$atomic->empty && !$atomic->nonNull && !$atomic->issetFromLoop
                && $atomic->truthiness === MixedTruthiness::Undetermined,
            'string' => $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::String && $atomic->refinement instanceof StringType
                && $atomic->refinement->literalKind === StringLiteralKind::General && $atomic->refinement->literalValue === null
                && !$atomic->refinement->callable && !$atomic->refinement->numeric && !$atomic->refinement->nonEmpty
                && !$atomic->refinement->truthy && $atomic->refinement->casing === StringCasing::Unspecified,
            'array<array-key, mixed>' => $atomic instanceof KeyedArrayType && $atomic->knownItems === null && !$atomic->nonEmpty
                && $atomic->keyType !== null && $atomic->valueType !== null && self::arrayKey($atomic->keyType)
                && self::domain($atomic->valueType, 'mixed', $source),
            default => false,
        };
    }
    private static function closure(object $atomic, bool $typed, bool $native, array $source): bool
    {
        if (!$atomic instanceof CallableType || $atomic->alias !== null || $atomic->signature === null) { return false; }
        $signature = $atomic->signature;
        if (!$signature->closure || $signature->pure || $signature->source !== null || $signature->constraints !== []
            || count($signature->parameters) !== 1 || $signature->returnType === null) { return false; }
        $parameter = $signature->parameters[0];
        return $parameter->name === null && !$parameter->byReference && $parameter->closureThisType === null
            && $parameter->variadic === !$typed && $parameter->hasDefault === $native && $parameter->type !== null
            && self::domain($parameter->type, $typed ? ($source['callbackApplication']['resolvedName'] ?? null) : 'mixed', $source)
            && self::domain($signature->returnType, $typed ? 'array<array-key, mixed>' : 'mixed', $source);
    }
    private static function callbackApplicationImport(?array $nodes): ?array
    {
        if ($nodes === null) { return null; }
        $namespaces = array_values(array_filter($nodes, static fn(Node $node): bool => $node instanceof Node\Stmt\Namespace_));
        if (count($namespaces) !== 1 || $namespaces[0]->name?->toString() !== 'Illuminate\\Foundation\\Bootstrap') { return null; }
        $namespace = $namespaces[0]; $selected = null;
        foreach ($namespace->stmts as $statement) {
            if ($statement instanceof Node\Stmt\GroupUse) { return null; }
            if (!$statement instanceof Node\Stmt\Use_ || $statement->type !== Node\Stmt\Use_::TYPE_NORMAL) { continue; }
            foreach ($statement->uses as $use) {
                if (strcasecmp($use->getAlias()->name, 'Application') !== 0) { continue; }
                if ($selected !== null || $use->name->toString() !== self::APPLICATION_CONTRACT
                    || $use->alias !== null || count($statement->uses) !== 1) { return null; }
                $selected = ['resolvedName' => $use->name->toString(), 'nameSpan' => self::span($use->name),
                    'namespaceSpan' => self::span($namespace->name), 'namespaceText' => $namespace->name->toString(),
                    'useSpan' => self::span($statement), 'useText' => 'use '.$use->name->toString().';'];
            }
        }
        return $selected;
    }
    private static function owner(GenericParent $owner, array $source): bool
    {
        return strcasecmp($source['class'], self::CONTAINER) === 0 && strcasecmp($source['name'], 'instance') === 0
            && $owner->kind === GenericParentKind::FunctionLike && strcasecmp($owner->name, self::CONTAINER) === 0
            && strcasecmp($owner->member ?? '', 'instance') === 0;
    }
    private static function arrayKey(Type $type): bool
    {
        return self::flags($type, false) && count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof ScalarType
            && $type->atomicTypes[0]->kind === ScalarTypeKind::ArrayKey && $type->atomicTypes[0]->refinement === null;
    }
    private static function flags(Type $type, bool $populated): bool
    {
        $expected = ['hadTemplate', 'byReference', 'referenceFree', 'possiblyUndefinedFromTry', 'possiblyUndefined',
            'ignoreNullableIssues', 'ignoreFalsableIssues', 'fromTemplateDefault', 'populated', 'nullsafeNull', 'fromUnspecifiedTemplate'];
        $actual = get_object_vars($type->flags); if (array_keys($actual) !== $expected) { return false; }
        foreach ($actual as $key => $value) { if ($value !== ($key === 'populated' ? $populated : false)) { return false; } }
        return true;
    }
    private static function metadata(?TypeMetadata $metadata, bool $doc, string $path, ?array $span, string $root): bool
    {
        return $metadata !== null && $span !== null && $metadata->fromDocblock === $doc && !$metadata->inferred
            && self::located($metadata->location, $path, $span, $root);
    }
    private static function located(?SourceLocation $location, string $path, array $span, string $root): bool
    {
        $file = $location === null ? null : self::path($location->file, $root);
        return $file !== null && self::samePath($file, $path) && [$location->span->start, $location->span->end] === $span;
    }
    private static function path(string $path, string $root): ?string
    {
        $path = str_replace('\\', '/', $path); $root = rtrim(str_replace('\\', '/', $root), '/');
        if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        if (!str_starts_with($path, '/') && !preg_match('~^[A-Za-z]:/~', $path)) { $path = $root.'/'.$path; }
        if (preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) { return null; }
        $fileKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $rootKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
        return str_starts_with($fileKey, $rootKey.'/') ? $path : null;
    }
    private static function samePath(string $a, string $b): bool { return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($a, $b) === 0 : $a === $b; }
    private static function syntax(?Node $node): ?string
    {
        if ($node === null) { return null; }
        if ($node instanceof Node\NullableType && $node->type instanceof Node\Name && strcasecmp($node->type->toString(), 'Closure') === 0) { return '?Closure'; }
        if ($node instanceof Node\Identifier && strtolower($node->name) === 'void') { return 'void'; }
        return $node instanceof Node\Name && strcasecmp($node->toString(), self::APPLICATION) === 0 ? self::APPLICATION : 'unsupported';
    }
    private static function slice(string $bytes, array $span): ?string
    {
        return count($span) === 2 && is_int($span[0]) && is_int($span[1]) && $span[0] >= 0 && $span[1] >= $span[0]
            && $span[1] <= strlen($bytes) ? substr($bytes, $span[0], $span[1] - $span[0]) : null;
    }
    private static function span(Node $node): array { return [$node->getStartFilePos(), $node->getEndFilePos() + 1]; }
}
