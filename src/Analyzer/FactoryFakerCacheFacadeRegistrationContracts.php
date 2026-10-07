<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, FunctionLikeKind, FunctionLikeMetadata, MetadataFlags, PropertyMetadata, TypeMetadata};
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\{KeyedArrayType, MixedTruthiness, MixedType, NamedObjectType, ScalarType, ScalarTypeKind,
    SimpleAtomicType, SimpleAtomicTypeKind, StringCasing, StringLiteralKind, StringType};
use Mago\Sdk\Analyzer\Type\FunctionLikeKind as IdentifierKind;
use Mago\Sdk\SourceLocation;
use PhpParser\{Node, NodeFinder};

/** The literal Cache accessor and physical Facade forwarding, not facade purity. */
final class FactoryFakerCacheFacadeRegistrationContracts
{
    public const CACHE = 'Illuminate\\Support\\Facades\\Cache';
    public const FACADE = 'Illuminate\\Support\\Facades\\Facade';
    private const APPLICATION = 'Illuminate\\Contracts\\Foundation\\Application';
    private const INITIALIZER = 'Illuminate\\Foundation\\Bootstrap\\RegisterFacades';
    private const FILES = [
        'vendor/laravel/framework/src/Illuminate/Support/Facades/Cache.php' => '54458e8228eb0a64aaa709a344c159215350fcf279786428ec76142bf06fd305',
        'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php' => '5d4eb0eee1be78b6c5dcf69e8907d5b85ecd4368716ff8a32f431848265c986b',
        'vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/RegisterFacades.php' => 'ad2e1ab13a04030de28930d239fcc77f484e7124e31ac4f33c452fa4db9c40b5',
    ];

    /** Scalar spans only; release all parsed files before the first SDK request. */
    public static function source(string $root): ?array
    {
        $result = ['classes' => [], 'methods' => [], 'properties' => [], 'sourceHashes' => [], 'initializerCalls' => []];
        foreach (self::FILES as $relative => $expected) {
            $path = self::path($relative, $root); $contents = $path === null ? false : @file_get_contents($path);
            if ($contents === false || hash('sha256', $contents) !== $expected) { return null; }
            try { $nodes = DefensiveBoundaryGuardSource::parse($contents); } catch (\Throwable) { return null; }
            $finder = new NodeFinder; $selected = null;
            $name = str_ends_with($relative, '/Cache.php') ? self::CACHE : (str_ends_with($relative, '/Facade.php') ? self::FACADE : self::INITIALIZER);
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                if (strcasecmp($class->namespacedName?->toString() ?? '', $name) === 0) {
                    if ($selected !== null) { return null; } $selected = $class;
                }
            }
            if ($selected === null || $selected->name === null || $selected->attrGroups !== [] || $selected->implements !== []
                || $selected->getTraitUses() !== []) { return null; }
            $result['sourceHashes'][$path] = $expected;
            if ($name === self::INITIALIZER) {
                $method = $selected->getMethod('bootstrap');
                if ($method === null || $method->stmts === null || count($method->stmts) !== 3) { return null; }
                foreach (array_slice($method->stmts, 0, 2) as $statement) {
                    $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
                    if (!$call instanceof Node\Expr\StaticCall || !$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier
                        || strcasecmp($call->class->toString(), self::FACADE) !== 0) { return null; }
                    $result['initializerCalls'][] = ['path' => $path, 'span' => self::span($call), 'name' => strtolower($call->name->name)];
                }
            } else {
                $result['classes'][$name] = ['name' => $name, 'path' => $path, 'span' => self::span($selected),
                    'nameSpan' => self::span($selected->name), 'abstract' => $selected->isAbstract(), 'final' => $selected->isFinal(),
                    'parent' => $selected->extends?->toString()];
                $methodNames = $name === self::CACHE ? ['getFacadeAccessor'] : ['getFacadeRoot', 'resolveFacadeInstance', '__callStatic'];
                foreach ($methodNames as $methodName) {
                    $method = $selected->getMethod($methodName); $recipe = $method === null ? null : self::methodSource($method, $name, $path);
                    if ($recipe === null) { return null; } $result['methods'][$name.'::'.$methodName] = $recipe;
                }
                if ($name === self::FACADE) {
                    foreach ($selected->getProperties() as $property) {
                        if (count($property->props) !== 1) { return null; } $item = $property->props[0]; $propertyName = '$'.$item->name->name;
                        if (!in_array($propertyName, ['$app', '$resolvedInstance', '$cached'], true) || !$property->isStatic()
                            || !$property->isProtected() || $property->type !== null || $property->attrGroups !== []) { return null; }
                        $doc = self::doc($property); if ($doc === null || array_keys($doc) !== ['var']) { return null; }
                        $expectedDoc = match ($propertyName) { '$app' => '\\'.self::APPLICATION.'|null', '$resolvedInstance' => 'array', '$cached' => 'bool' };
                        if ($doc['var']['text'] !== $expectedDoc || ($propertyName === '$cached' ? !($item->default instanceof Node\Expr\ConstFetch
                            && strtolower($item->default->name->toString()) === 'true') : $item->default !== null)) { return null; }
                        $result['properties'][$propertyName] = ['name' => $propertyName, 'path' => $path,
                            'nameSpan' => self::span($item->name), 'typeSpan' => $doc['var']['span'],
                            'defaultSpan' => $item->default === null ? null : self::span($item->default), 'domain' => $expectedDoc];
                    }
                    if (count($result['properties']) !== 3) { return null; }
                }
            }
            unset($nodes, $finder, $selected, $class, $method, $property, $item, $call, $statement);
            if (@hash_file('sha256', $path) !== $expected) { return null; }
        }
        return $result;
    }

    /** Every catalogue file is visited. Definitions are distinct from calls to them. */
    public static function observeSource(array $nodes, string $path, array $source): array
    {
        $result = ['classes' => [], 'operations' => []];
        $path = self::normalize($path); $hash = @hash_file('sha256', $path);
        $frameworkDefinition = isset($source['sourceHashes'][$path]) && $hash === $source['sourceHashes'][$path]
            && ($path === $source['classes'][self::CACHE]['path'] || $path === $source['classes'][self::FACADE]['path']);
        $visit = static function (Node $node, ?string $owner) use (&$visit, &$result, $path, $source, $frameworkDefinition): void {
            if ($node instanceof Node\Stmt\Class_) {
                $owner = $node->namespacedName?->toString();
                if ($owner !== null) { $result['classes'][] = ['name' => strtolower($owner), 'parent' => strtolower($node->extends?->toString() ?? '')]; }
            }
            if (!$frameworkDefinition && $node instanceof Node\Expr\StaticCall) {
                $name = $node->name instanceof Node\Identifier ? strtolower($node->name->name) : null;
                $selected = $name === null || in_array($name, ['swap', 'shouldreceive', 'expects', 'spy', 'partialmock', 'setfacadeapplication'], true);
                if ($selected) {
                    $allowed = false;
                    foreach ($source['initializerCalls'] as $call) {
                        if ($path === $call['path'] && @hash_file('sha256', $path) === ($source['sourceHashes'][$path] ?? null)
                            && self::span($node) === $call['span'] && $name === $call['name']) { $allowed = true; }
                    }
                    if (!$allowed) { $result['operations'][] = ['kind' => 'call', 'target' => self::target($node->class, $owner), 'path' => $path, 'span' => self::span($node)]; }
                }
            }
            // A selected shared field escaping to an unproved helper can be
            // changed through a reference or through its Application object.
            if (!$frameworkDefinition && $node instanceof Node\Expr\StaticPropertyFetch
                && (!$node->name instanceof Node\VarLikeIdentifier || in_array(strtolower($node->name->name), ['app', 'resolvedinstance', 'cached'], true))) {
                $result['operations'][] = ['kind' => 'shared-root-exposure', 'target' => self::target($node->class, $owner),
                    'path' => $path, 'span' => self::span($node)];
            }
            if (!$frameworkDefinition && ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef
                || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc
                || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec || $node instanceof Node\Stmt\Unset_)) {
                $targets = $node instanceof Node\Stmt\Unset_ ? $node->vars : [$node->var];
                if ($node instanceof Node\Expr\AssignRef) { $targets[] = $node->expr; }
                foreach ($targets as $target) {
                    while ($target instanceof Node\Expr\ArrayDimFetch) { $target = $target->var; }
                    if ($target instanceof Node\Expr\StaticPropertyFetch && (!$target->name instanceof Node\VarLikeIdentifier
                        || in_array(strtolower($target->name->name), ['app', 'resolvedinstance', 'cached'], true))) {
                        $result['operations'][] = ['kind' => 'shared-root-write', 'target' => self::target($target->class, $owner), 'path' => $path, 'span' => self::span($node)];
                    }
                }
            }
            foreach ($node->getSubNodeNames() as $key) {
                $value = $node->$key;
                foreach (is_array($value) ? $value : [$value] as $child) { if ($child instanceof Node) { $visit($child, $owner); } }
            }
        };
        foreach ($nodes as $node) { $visit($node, null); } $visit = null;
        return $result;
    }

    /** Requires the complete scalar catalogue, including files visited after the selected call. */
    public static function priority(array $catalogue): array
    {
        $parents = []; $operations = [];
        foreach ($catalogue as $facts) {
            foreach ($facts['classes'] as $class) {
                if (isset($parents[$class['name']]) && $parents[$class['name']] !== $class['parent']) {
                    return ['admitted' => false, 'stage' => 'ambiguous facade source hierarchy'];
                }
                $parents[$class['name']] = $class['parent'];
            }
            array_push($operations, ...$facts['operations']);
        }
        foreach ($operations as $operation) {
            $target = $operation['target']; $seen = [];
            if ($target !== null && str_starts_with($target, 'parent:')) { $target = $parents[substr($target, 7)] ?? null; }
            $unresolved = $target === null;
            while ($target !== null && $target !== '' && !isset($seen[$target])) {
                if (in_array($target, [strtolower(self::CACHE), strtolower(self::FACADE)], true)) {
                    return ['admitted' => false, 'stage' => 'source facade replacement precedes default container root', 'operation' => $operation];
                }
                $seen[$target] = true; $target = $parents[$target] ?? '';
            }
            if ($unresolved) { return ['admitted' => false, 'stage' => 'unresolved facade root operation', 'operation' => $operation]; }
        }
        return ['admitted' => true, 'stage' => 'no selected facade root replacement in complete source catalogue'];
    }

    /** The caller additionally proves cache bindings, core aliases and the concrete CacheManager APIs. */
    public static function current(IssueFilterContext $context, array $source, string $root): array
    {
        $result = ['admitted' => false, 'stage' => 'current physical Cache facade source', 'sourceHashes' => $source['sourceHashes'] ?? [],
            'selectedLookupBindings' => [], 'nativeTypesChanged' => false];
        foreach ($result['sourceHashes'] as $path => $hash) { if (@hash_file('sha256', $path) !== $hash) { return $result; } }
        if (count($source['classes'] ?? []) !== 2 || count($source['methods'] ?? []) !== 4 || count($source['properties'] ?? []) !== 3) { return $result; }
        foreach ($source['classes'] as $class) {
            $result['stage'] = 'current physical facade class '.$class['name'];
            $result['selectedLookupBindings'][] = ['api' => 'getClass', 'name' => $class['name']];
            $native = $context->codebase->getClass($class['name']);
            if ($native === null || $native->kind !== ClassLikeKind::Class_ || $native->hasIncompleteHierarchy()
                || strcasecmp($native->name, $class['name']) !== 0 || strcasecmp($native->originalName, $class['name']) !== 0
                || $native->flags->bits !== ($class['abstract'] ? 65 : 64) || $class['final']
                || strcasecmp($native->directParentClass ?? '', $class['parent'] ?? '') !== 0
                || array_map('strtolower', $native->parentClasses) !== ($class['parent'] === null ? [] : [strtolower($class['parent'])])
                || $native->directParentInterfaces !== [] || $native->parentInterfaces !== [] || $native->usedTraits !== []
                || $native->templates !== [] || $native->typeAliases !== [] || $native->mixins !== [] || $native->attributes !== []
                || !self::located($native->location, $class['path'], $class['span'], $root)
                || !self::located($native->nameLocation, $class['path'], $class['nameSpan'], $root)) { return $result; }
        }
        foreach ($source['methods'] as $method) {
            $result['stage'] = 'current physical facade method '.$method['class'].'::'.$method['name'];
            $owner = $method['class'];
            foreach (['getDeclaringMethod', 'getMethod'] as $api) { $result['selectedLookupBindings'][] = ['api' => $api, 'class' => $owner, 'name' => $method['name']]; }
            $declaring = $context->codebase->getDeclaringMethod($owner, $method['name']);
            $effective = $context->codebase->getMethod($owner, $method['name']);
            if ($declaring === null || $effective === null || $effective != $declaring || !self::methodCurrent($declaring, $method, $root)) { return $result; }
            if ($owner === self::FACADE) {
                foreach (['getDeclaringMethod', 'getMethod'] as $api) { $result['selectedLookupBindings'][] = ['api' => $api, 'class' => self::CACHE, 'name' => $method['name']]; }
                $inherited = $context->codebase->getDeclaringMethod(self::CACHE, $method['name']);
                $absent = $context->codebase->getMethod(self::CACHE, $method['name']);
                if ($inherited === null || $inherited != $declaring || $absent !== null) { return $result; }
            }
        }
        foreach ($source['properties'] as $property) {
            $result['stage'] = 'current shared facade root property '.$property['name'];
            foreach ([self::FACADE, self::CACHE] as $class) {
                foreach (['getDeclaringProperty', 'getProperty'] as $api) { $result['selectedLookupBindings'][] = ['api' => $api, 'class' => $class, 'name' => $property['name']]; }
            }
            $declaring = $context->codebase->getDeclaringProperty(self::FACADE, $property['name']);
            $effective = $context->codebase->getProperty(self::FACADE, $property['name']);
            $inherited = $context->codebase->getDeclaringProperty(self::CACHE, $property['name']);
            $absent = $context->codebase->getProperty(self::CACHE, $property['name']);
            if ($declaring === null || $effective === null || $inherited === null || $effective != $declaring || $inherited != $declaring
                || $absent !== null || !self::propertyCurrent($declaring, $property, $root)) { return $result; }
        }
        foreach ($result['sourceHashes'] as $path => $hash) { if (@hash_file('sha256', $path) !== $hash) { return $result; } }
        $result['stage'] = 'literal cache accessor and physical current facade forwarding'; $result['admitted'] = true;
        return $result;
    }

    private static function methodSource(Node\Stmt\ClassMethod $method, string $class, string $path): ?array
    {
        $parameters = match (strtolower($method->name->name)) { 'getfacadeaccessor', 'getfacaderoot' => [],
            'resolvefacadeinstance' => ['$name' => 'string'], '__callstatic' => ['$method' => 'string', '$args' => 'array'], default => null };
        $doc = self::doc($method);
        if ($parameters === null || $doc === null || $method->byRef || !$method->isStatic() || $method->isAbstract()
            || $method->returnType !== null || $method->attrGroups !== [] || $method->stmts === null || count($method->params) !== count($parameters)) { return null; }
        $return = $class === self::CACHE ? 'string' : 'mixed';
        $expected = ['return' => $return]; foreach ($parameters as $name => $type) { $expected['param:'.$name] = $type; }
        if (strtolower($method->name->name) === '__callstatic') { $expected['throws'] = '\\RuntimeException'; }
        if (count($doc) !== count($expected)) { return null; }
        foreach ($expected as $key => $value) { if (($doc[$key]['text'] ?? null) !== $value) { return null; } }
        $formals = []; $names = array_keys($parameters);
        foreach ($method->params as $index => $parameter) {
            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name) || '$'.$parameter->var->name !== $names[$index]
                || $parameter->byRef || $parameter->variadic || $parameter->default !== null || $parameter->type !== null
                || $parameter->attrGroups !== [] || $parameter->flags !== 0) { return null; }
            $formals[] = ['name' => $names[$index], 'domain' => $parameters[$names[$index]], 'span' => self::span($parameter),
                'nameSpan' => self::span($parameter->var), 'typeSpan' => $doc['param:'.$names[$index]]['span']];
        }
        return ['class' => $class, 'name' => $method->name->name, 'path' => $path, 'span' => self::span($method),
            'nameSpan' => self::span($method->name), 'visibility' => $method->isProtected() ? 'Protected' : 'Public',
            'formals' => $formals, 'returnDomain' => $return, 'returnSpan' => $doc['return']['span']];
    }

    private static function methodCurrent(FunctionLikeMetadata $native, array $source, string $root): bool
    {
        // Exact bits are the observed physical profiles, not invented names for reserved bits.
        $flags = strtolower($source['name']) === '__callstatic' ? 262208 : 64;
        if ($native->kind !== FunctionLikeKind::Method || $native->identifier->kind !== IdentifierKind::Method
            || strcasecmp($native->identifier->class ?? '', $source['class']) !== 0 || strcasecmp($native->identifier->name, $source['name']) !== 0
            || !$native->static || $native->abstract || $native->constructor || $native->final || !$native->hasDocblock
            || $native->visibility?->name !== $source['visibility'] || $native->flags->bits !== $flags
            || $native->templates !== [] || $native->whereConstraints !== [] || $native->attributes !== []
            || $native->assertions !== [] || $native->ifTrueAssertions !== [] || $native->ifFalseAssertions !== []
            || !self::located($native->location, $source['path'], $source['span'], $root)
            || !self::located($native->nameLocation, $source['path'], $source['nameSpan'], $root)
            || count($native->parameters) !== count($source['formals']) || $native->declaredReturnType !== null
            || !self::metadata($native->returnType, $source['path'], $source['returnSpan'], $root, true, false)
            || !self::domain($native->returnType->type, $source['returnDomain'], false)) { return false; }
        foreach ($source['formals'] as $index => $formal) {
            $parameter = $native->parameters[$index];
            if ($parameter->name !== $formal['name'] || $parameter->flags->bits !== 0 || $parameter->attributes !== []
                || $parameter->declaredType !== null || $parameter->defaultType !== null || $parameter->outType !== null || $parameter->closureThisType !== null
                || !self::located($parameter->location, $source['path'], $formal['span'], $root)
                || !self::located($parameter->nameLocation, $source['path'], $formal['nameSpan'], $root)
                || !self::metadata($parameter->type, $source['path'], $formal['typeSpan'], $root, true, false)
                || !self::domain($parameter->type->type, $formal['domain'], false)) { return false; }
        }
        return true;
    }

    private static function propertyCurrent(PropertyMetadata $native, array $source, string $root): bool
    {
        $cached = $source['name'] === '$cached';
        if ($native->name !== $source['name'] || $native->location !== null || $native->declaredType !== null || $native->writeType !== null
            || $native->attributes !== [] || $native->hooks !== [] || $native->readVisibility->name !== 'Protected' || $native->writeVisibility->name !== 'Protected'
            || $native->flags->bits !== (MetadataFlags::STATIC | ($cached ? MetadataFlags::HAS_DEFAULT : 0))
            || !self::located($native->nameLocation, $source['path'], $source['nameSpan'], $root)
            || !self::metadata($native->type, $source['path'], $source['typeSpan'], $root, true, false)
            || !($source['name'] === '$app' ? self::domain($native->type->type, $source['domain'], true)
                : self::primitivePropertyDomain($native->type->type, $source['domain']))) { return false; }
        if (!$cached) { return $native->defaultType === null; }
        return self::metadata($native->defaultType, $source['path'], $source['defaultSpan'], $root, false, true)
            && self::primitivePropertyDomain($native->defaultType->type, 'true');
    }

    /**
     * Only these current source-bound primitive fields have no class/template
     * dependency to resolve. POPULATED records codebase-population provenance;
     * both genuine snapshots retain the same array/bool domain. All semantic
     * flags and refinements are still checked, including each array component.
     */
    private static function primitivePropertyDomain(Type $type, string $domain): bool
    {
        if ($domain === 'bool' || $domain === 'true') { return self::domain($type, $domain, $type->flags->populated); }
        if ($domain !== 'array' || !self::flags($type, $type->flags->populated) || count($type->atomicTypes) !== 1) { return false; }
        $atomic = $type->atomicTypes[0];
        return $atomic instanceof KeyedArrayType && $atomic->knownItems === null && !$atomic->nonEmpty
            && $atomic->keyType !== null && $atomic->valueType !== null
            && self::domain($atomic->keyType, 'array-key', $atomic->keyType->flags->populated)
            && self::domain($atomic->valueType, 'mixed', $atomic->valueType->flags->populated);
    }

    private static function domain(Type $type, string $domain, bool $populated): bool
    {
        if (!self::flags($type, $populated)) { return false; }
        if ($domain === '\\'.self::APPLICATION.'|null') {
            if (count($type->atomicTypes) !== 2) { return false; } $object = false; $null = false;
            foreach ($type->atomicTypes as $atomic) {
                if ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null && !$null) { $null = true; }
                elseif ($atomic instanceof NamedObjectType && !$object && strcasecmp($atomic->name, self::APPLICATION) === 0
                    && !$atomic->static && !$atomic->isThis && !$atomic->remappedParameters
                    && in_array($atomic->parameters, [null, []], true) && in_array($atomic->variances, [null, []], true)
                    && in_array($atomic->intersections, [null, []], true)) { $object = true; }
                else { return false; }
            }
            return $object && $null;
        }
        if (count($type->atomicTypes) !== 1) { return false; } $atomic = $type->atomicTypes[0];
        return match ($domain) {
            'mixed' => $atomic instanceof MixedType && !$atomic->empty && !$atomic->nonNull && !$atomic->issetFromLoop && $atomic->truthiness === MixedTruthiness::Undetermined,
            'string' => $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::String && $atomic->refinement instanceof StringType
                && $atomic->refinement->literalKind === StringLiteralKind::General && $atomic->refinement->literalValue === null
                && !$atomic->refinement->callable && !$atomic->refinement->numeric && !$atomic->refinement->nonEmpty
                && !$atomic->refinement->truthy && $atomic->refinement->casing === StringCasing::Unspecified,
            'bool', 'true' => $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::Boolean && $atomic->refinement === ($domain === 'true' ? true : null),
            'array' => $atomic instanceof KeyedArrayType && $atomic->knownItems === null && !$atomic->nonEmpty
                && $atomic->keyType !== null && $atomic->valueType !== null && self::domain($atomic->keyType, 'array-key', $populated)
                && self::domain($atomic->valueType, 'mixed', $populated),
            'array-key' => $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::ArrayKey && $atomic->refinement === null,
            default => false,
        };
    }

    private static function doc(Node $node): ?array
    {
        $doc = $node->getDocComment(); if ($doc === null) { return null; } $result = [];
        preg_match_all('/@([a-zA-Z][a-zA-Z-]*)[ \t]+([^\r\n]*)/', $doc->getText(), $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $index => [$tag]) {
            $text = rtrim($matches[2][$index][0]); $start = $doc->getStartFilePos() + $matches[2][$index][1];
            if ($tag === 'param') {
                if (!preg_match('/^([^ \t]+)[ \t]+(\$[a-zA-Z_][a-zA-Z0-9_]*)$/D', $text, $parameter)) { return null; }
                $tag = 'param:'.$parameter[2]; $text = $parameter[1];
            }
            if (isset($result[$tag])) { return null; } $result[$tag] = ['text' => $text, 'span' => [$start, $start + strlen($text)]];
        }
        return $result;
    }
    private static function flags(Type $type, bool $populated): bool
    {
        $expected = ['hadTemplate', 'byReference', 'referenceFree', 'possiblyUndefinedFromTry', 'possiblyUndefined', 'ignoreNullableIssues',
            'ignoreFalsableIssues', 'fromTemplateDefault', 'populated', 'nullsafeNull', 'fromUnspecifiedTemplate'];
        $actual = get_object_vars($type->flags); if (array_keys($actual) !== $expected) { return false; }
        foreach ($actual as $key => $value) { if ($value !== ($key === 'populated' ? $populated : false)) { return false; } }
        return true;
    }
    private static function metadata(?TypeMetadata $type, string $path, ?array $span, string $root, bool $doc, bool $inferred): bool
    { return $type !== null && $type->fromDocblock === $doc && $type->inferred === $inferred && $span !== null && self::located($type->location, $path, $span, $root); }
    private static function target(Node $node, ?string $owner): ?string
    {
        if (!$node instanceof Node\Name) { return null; } $name = strtolower($node->toString());
        return in_array($name, ['self', 'static'], true) ? ($owner === null ? null : strtolower($owner))
            : ($name === 'parent' ? ($owner === null ? null : 'parent:'.strtolower($owner)) : $name);
    }
    private static function located(?SourceLocation $location, string $path, array $span, string $root): bool
    { return $location !== null && self::path($location->file, $root) === self::normalize($path) && [$location->span->start, $location->span->end] === $span; }
    private static function span(Node $node): array { return [$node->getStartFilePos(), $node->getEndFilePos() + 1]; }
    private static function normalize(string $path): string { $path = str_replace('\\', '/', $path); return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path; }
    private static function path(?string $path, string $root): ?string
    {
        if ($path === null) { return null; }
        $root = rtrim(self::normalize($root), '/'); $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, '/./') || str_contains($path, '/../') || str_starts_with($path, '../')) { return null; }
        $absolute = str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//D', $path) === 1;
        $path = self::normalize($absolute ? $path : $root.'/'.$path);
        return str_starts_with($path, $root.'/') ? $path : null;
    }
}
