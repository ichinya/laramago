<?php
declare(strict_types=1);
namespace Example\LocalCacheFocusedTests;

use Mago\Sdk\Analyzer\{IssueFilterContext, Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, FunctionLikeKind, MetadataFlags};
use Mago\Sdk\Analyzer\Type\{CallableType, ConditionalType, FunctionLikeIdentifier,
    FunctionLikeKind as IdentifierKind, GenericParameterType, ScalarType,
    ScalarTypeKind, SimpleAtomicType, SimpleAtomicTypeKind, Variance, Visibility};
use Mago\Sdk\{SourceLocation, Span};

/** Added local-cache obligations only; the legacy 360-job catalogue is independent. */
final class LocalCacheFocusedControlPlan
{
    public const FAMILY_COUNT = 16;
    public const EXPECTED_LOCAL_MUTATIONS = 131;
    private const APP = 'Illuminate\\Foundation\\Application';
    private const CONTAINER = 'Illuminate\\Container\\Container';
    private const CONTRACT = 'Illuminate\\Contracts\\Container\\Container';
    private const CACHE = 'Illuminate\\Cache\\CacheManager';
    private const CONFIG_TRAIT = 'Illuminate\\Foundation\\Testing\\WithCachedConfig';
    private const ROUTES_TRAIT = 'Illuminate\\Foundation\\Testing\\WithCachedRoutes';
    private const LOAD_CONFIG = 'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration';
    private const ROUTE_PROVIDER = 'Illuminate\\Foundation\\Support\\Providers\\RouteServiceProvider';

    /** @return array<string,array{selected:array,bindings:array,change:callable,family:string}> */
    public static function append(IssueFilterContext $context, array $freshAdmittedProof, array $plans): array
    {
        $source = self::recipe($context, $freshAdmittedProof);
        $add = static function(string $family, string $label, array $selected, callable $change) use (&$plans): void {
            $key = 'local-cache/'.$family.'/'.$label;
            if (array_key_exists($key, $plans)) { throw new \RuntimeException('Duplicate focused mutation label.'); }
            $plans[$key] = ['selected' => $selected, 'bindings' => [$selected], 'change' => $change, 'family' => $family];
        };
        $method = static fn(string $class, string $name): array => ['kind' => 'method', 'class' => $class, 'name' => $name];
        $caller = $method($source['callerClass'], $source['callerMethod']);
        $callerNative = LocalCacheSelectedNativeAliases::select($context->codebase, $caller);
        self::need($callerNative->kind === FunctionLikeKind::Method && $callerNative->identifier->kind === IdentifierKind::Method
            && $callerNative->declaredReturnType !== null && $callerNative->returnType !== null,
            'The selected named local-cache caller must have both actual return domains.');
        $add('caller-kind', 'metadata', $caller, static fn(object $n): object => self::copy($n, ['kind' => FunctionLikeKind::Function_]));
        $add('caller-kind', 'identifier', $caller, static fn(object $n): object => self::copy($n,
            ['identifier' => new FunctionLikeIdentifier(IdentifierKind::Function_, $n->identifier->name)]));
        foreach (['declaredReturnType', 'returnType'] as $field) {
            $add('caller-return', $field.'-domain', $caller, static fn(object $n): object => self::at($n, [$field, 'type'],
                static fn(Type $t): Type => Type::namedObject('stdClass')->withFlags($t->flags)));
        }
        $add('caller-return', 'effective-doc-origin', $caller, static fn(object $n): object => self::at($n,
            ['returnType'], static fn(object $m): object => self::copy($m, ['fromDocblock' => !$m->fromDocblock])));
        $add('caller-return', 'effective-location', $caller, static fn(object $n): object => self::at($n,
            ['returnType'], static fn(object $m): object => self::copy($m, ['location' => self::shift($m->location)])));
        $add('caller-return', 'declared-inferred', $caller, static fn(object $n): object => self::at($n,
            ['declaredReturnType'], static fn(object $m): object => self::copy($m, ['inferred' => !$m->inferred])));
        $callerClass = ['kind' => 'class', 'name' => $source['callerClass']];
        $classNative = LocalCacheSelectedNativeAliases::select($context->codebase, $callerClass);
        $configTrait = LocalCacheSelectedNativeAliases::select($context->codebase, ['kind' => 'trait', 'name' => self::CONFIG_TRAIT]);
        self::need($classNative->usedTraits === [] && $configTrait->kind === ClassLikeKind::Trait,
            'The neutral caller must have its observed empty trait set.');
        $add('caller-trait-coherence', 'extra-selected-trait', $callerClass,
            static fn(object $n): object => self::copy($n, ['usedTraits' => [$configTrait->name]]));

        $generic = [$method(self::APP, 'make'), $method(self::APP, 'resolve'), $method(self::CONTAINER, 'make'),
            $method(self::CONTAINER, 'resolve'), $method(self::CONTRACT, 'make')];
        foreach ($generic as $binding) {
            $native = LocalCacheSelectedNativeAliases::select($context->codebase, $binding);
            self::need(count($native->templates) === 1 && $native->templates[0]->name === 'TClass'
                && !$native->templates[0]->readonly && $native->templates[0]->variance === Variance::Invariant
                && $native->templates[0]->default === null, 'An actual current TClass template is required.');
            $suffix = self::symbol($binding);
            $add('nominal-template-owner', $suffix, $binding, static fn(object $n): object => self::at($n,
                ['templates', 0, 'definingEntity'], static fn(object $o): object => self::copy($o, ['member' => 'extend'])));
            $add('nominal-template-constraint', $suffix, $binding, static fn(object $n): object => self::at($n,
                ['templates', 0], static fn(object $t): object => self::copy($t, ['constraint' => Type::string()])));
            foreach (['readonly' => true, 'variance' => Variance::Covariant, 'default' => Type::string()] as $field => $value) {
                $add('nominal-template-flags', $suffix.'/'.$field, $binding, static fn(object $n): object => self::at($n,
                    ['templates', 0], static fn(object $t): object => self::copy($t, [$field => $value])));
            }
            self::need($native->returnType !== null && count($native->returnType->type->atomicTypes) === 1
                && $native->returnType->type->atomicTypes[0] instanceof ConditionalType,
                'The selected make/resolve conditional return must be genuinely present.');
            $add('conditional-return-domain', $suffix.'/true-branch', $binding, static fn(object $n): object => self::at($n,
                ['returnType', 'type', 'atomicTypes', 0], static fn(object $a): object => self::copy($a,
                    ['then' => Type::namedObject('stdClass')->withFlags($a->then->flags)])));
            $add('conditional-return-domain', $suffix.'/target-constraint', $binding, static fn(object $n): object => self::at($n,
                ['returnType', 'type', 'atomicTypes', 0, 'target', 'atomicTypes', 0, 'refinement'],
                static fn(object $r): object => self::copy($r, ['constraint' => Type::string()->atomicTypes[0]])));
        }
        foreach ([$method(self::APP, 'make'), $method(self::APP, 'booting'), $method(self::CONTRACT, 'make')] as $binding) {
            $native = LocalCacheSelectedNativeAliases::select($context->codebase, $binding);
            self::need($native->parameters !== [], 'Receiving formal controls require actual parameters.');
            foreach ($native->parameters as $i => $parameter) {
                self::need($parameter->outType === null && !$parameter->flags->contains(MetadataFlags::BY_REFERENCE),
                    'Receiving formals must start with their observed by-value/no-out domain.');
                self::parameter($add, 'receiving-formal-effect', $binding, $i, 'reference',
                    static fn(object $p): object => self::copy($p, ['flags' => new MetadataFlags($p->flags->bits | MetadataFlags::BY_REFERENCE)]));
                self::parameter($add, 'receiving-formal-effect', $binding, $i, 'out',
                    static fn(object $p): object => self::copy($p, ['outType' => self::copy($p->type, ['type' => Type::mixed()])]));
            }
        }
        $interface = $method(self::CONTRACT, 'make');
        $native = LocalCacheSelectedNativeAliases::select($context->codebase, $interface);
        self::need(isset($native->parameters[1]) && $native->parameters[1]->type !== null
            && $native->parameters[1]->defaultType !== null, 'The physical interface array/default contract is required.');
        foreach (['domain', 'doc-origin', 'inferred', 'location', 'default-location'] as $label) {
            self::parameter($add, 'inherited-array-interface', $interface, 1, $label, static function(object $p) use ($label): object {
                if ($label === 'default-location') { return self::copy($p, ['defaultType' => self::copy($p->defaultType,
                    ['location' => self::shift($p->defaultType->location)])]); }
                $changes = match ($label) {
                    'domain' => ['type' => Type::string()->withFlags($p->type->type->flags)],
                    'doc-origin' => ['fromDocblock' => !$p->type->fromDocblock],
                    'inferred' => ['inferred' => !$p->type->inferred],
                    'location' => ['location' => self::shift($p->type->location)],
                };
                return self::copy($p, ['type' => self::copy($p->type, $changes)]);
            });
        }
        $extend = $method(self::CACHE, 'extend');
        $native = LocalCacheSelectedNativeAliases::select($context->codebase, $extend);
        self::need(count($native->parameters) === 2 && $native->parameters[1]->declaredType !== null
            && $native->parameters[1]->type !== null && $native->parameters[1]->closureThisType !== null,
            'The actual CacheManager extend callback and closure-this annotation are required.');
        foreach (['declared-domain', 'effective-domain', 'closure-this-absent', 'closure-this-domain', 'reference', 'out'] as $label) {
            self::parameter($add, 'cache-extend-callback', $extend, 1, $label, static fn(object $p): object => match ($label) {
                'declared-domain' => self::copy($p, ['declaredType' => self::copy($p->declaredType, ['type' => Type::string()])]),
                'effective-domain' => self::copy($p, ['type' => self::copy($p->type, ['type' => Type::string()])]),
                'closure-this-absent' => self::copy($p, ['closureThisType' => null]),
                'closure-this-domain' => self::copy($p, ['closureThisType' => self::copy($p->closureThisType, ['type' => Type::namedObject('stdClass')])]),
                'reference' => self::copy($p, ['flags' => new MetadataFlags($p->flags->bits | MetadataFlags::BY_REFERENCE)]),
                'out' => self::copy($p, ['outType' => self::copy($p->type, ['type' => Type::mixed()])]),
            });
        }
        $observedTemplate = LocalCacheSelectedNativeAliases::select($context->codebase, $generic[0])->templates[0];
        foreach ([self::CONFIG_TRAIT => 'markConfigCached', self::ROUTES_TRAIT => 'markRoutesCached'] as $trait => $marker) {
            $binding = ['kind' => 'trait', 'name' => $trait];
            $native = LocalCacheSelectedNativeAliases::select($context->codebase, $binding);
            self::need($native->kind === ClassLikeKind::Trait && $native->usedTraits === [] && $native->templates === []
                && $native->mixins === [] && $native->unresolvedHierarchyDependencies === [], 'A complete standard marker trait is required.');
            $other = $trait === self::CONFIG_TRAIT ? self::ROUTES_TRAIT : self::CONFIG_TRAIT;
            foreach (['kind', 'hierarchy', 'trait', 'template', 'name-location', 'builtin'] as $label) {
                $add('marker-trait-coherence', $trait.'/'.$label, $binding, static fn(object $n): object => self::copy($n, match ($label) {
                    'kind' => ['kind' => ClassLikeKind::Class_],
                    'hierarchy' => ['unresolvedHierarchyDependencies' => [$other]],
                    'trait' => ['usedTraits' => [$other]],
                    'template' => ['templates' => [$observedTemplate]],
                    'name-location' => ['nameLocation' => self::shift($n->nameLocation)],
                    'builtin' => ['flags' => new MetadataFlags($n->flags->bits | MetadataFlags::BUILTIN)],
                }));
            }
            $binding = $method($trait, $marker);
            $native = LocalCacheSelectedNativeAliases::select($context->codebase, $binding);
            self::need(count($native->parameters) === 1 && $native->parameters[0]->type !== null
                && $native->visibility === Visibility::Protected, 'The actual protected marker receiving domain is required.');
            foreach (['kind', 'owner', 'reference', 'static', 'visibility'] as $label) {
                $add('marker-method-owner-formal-flags', $trait.'/'.$label, $binding, static fn(object $n): object => self::copy($n, match ($label) {
                    'kind' => ['kind' => FunctionLikeKind::Function_],
                    'owner' => ['identifier' => self::copy($n->identifier, ['class' => self::CACHE])],
                    'reference' => ['flags' => new MetadataFlags($n->flags->bits | MetadataFlags::BY_REFERENCE)],
                    'static' => ['static' => !$n->static],
                    'visibility' => ['visibility' => Visibility::Public],
                }));
            }
            self::parameter($add, 'marker-method-owner-formal-flags', $binding, 0, 'name',
                static fn(object $p): object => self::copy($p, ['name' => '$other']));
            self::parameter($add, 'marker-method-owner-formal-flags', $binding, 0, 'domain',
                static fn(object $p): object => self::copy($p, ['type' => self::copy($p->type, ['type' => Type::namedObject('stdClass')])]));
        }
        self::callbackPlans($context, $add, $method(self::LOAD_CONFIG, 'alwaysUse'), true);
        self::callbackPlans($context, $add, $method(self::ROUTE_PROVIDER, 'loadCachedRoutesUsing'), false);
        $instance = $method(self::CONTAINER, 'instance');
        $native = LocalCacheSelectedNativeAliases::select($context->codebase, $instance);
        self::need(count($native->templates) === 1 && $native->templates[0]->name === 'TInstance'
            && count($native->parameters) === 2, 'The exact observed instance template is required.');
        foreach (['owner', 'constraint', 'readonly', 'variance', 'default'] as $label) {
            $add('instance-marker-template', $label, $instance, static function(object $n) use ($label): object {
                return self::at($n, ['templates', 0], static fn(object $t): object => self::copy($t, match ($label) {
                    'owner' => ['definingEntity' => self::copy($t->definingEntity, ['member' => 'extend'])],
                    'constraint' => ['constraint' => Type::string()],
                    'readonly' => ['readonly' => !$t->readonly],
                    'variance' => ['variance' => Variance::Covariant],
                    'default' => ['default' => Type::string()],
                }));
            });
        }
        foreach ($native->parameters as $i => $parameter) {
            self::parameter($add, 'instance-marker-template', $instance, $i, 'reference',
                static fn(object $p): object => self::copy($p, ['flags' => new MetadataFlags($p->flags->bits | MetadataFlags::BY_REFERENCE)]));
            self::parameter($add, 'instance-marker-template', $instance, $i, 'out',
                static fn(object $p): object => self::copy($p, ['outType' => self::copy($p->type, ['type' => Type::mixed()])]));
        }
        foreach (['class_uses_recursive', 'trait_uses_recursive'] as $name) {
            $binding = ['kind' => 'function', 'name' => $name];
            $native = LocalCacheSelectedNativeAliases::select($context->codebase, $binding);
            self::need($native->kind === FunctionLikeKind::Function_ && count($native->parameters) === 1
                && $native->parameters[0]->type !== null && $native->returnType !== null,
                'Both real recursive trait-index function declarations are required.');
            foreach (['kind', 'identifier', 'reference', 'builtin', 'location', 'return-domain'] as $label) {
                $add('trait-index-function-domain', $name.'/'.$label, $binding, static fn(object $n): object => match ($label) {
                    'kind' => self::copy($n, ['kind' => FunctionLikeKind::Method]),
                    'identifier' => self::copy($n, ['identifier' => new FunctionLikeIdentifier(IdentifierKind::Method, $n->identifier->name, self::CACHE)]),
                    'reference' => self::copy($n, ['flags' => new MetadataFlags($n->flags->bits | MetadataFlags::BY_REFERENCE)]),
                    'builtin' => self::copy($n, ['flags' => new MetadataFlags($n->flags->bits | MetadataFlags::BUILTIN)]),
                    'location' => self::copy($n, ['location' => self::shift($n->location)]),
                    'return-domain' => self::at($n, ['returnType'], static fn(object $m): object => self::copy($m, ['type' => Type::string()])),
                });
            }
            self::parameter($add, 'trait-index-function-domain', $binding, 0, 'domain',
                static fn(object $p): object => self::copy($p, ['type' => self::copy($p->type, ['type' => Type::string()])]));
        }
        $families = []; $localCount = 0;
        foreach ($plans as $label => $plan) {
            if (str_starts_with($label, 'local-cache/')) { $families[$plan['family']] = true; $localCount++; }
        }
        self::need(count($families) === self::FAMILY_COUNT && $localCount === self::EXPECTED_LOCAL_MUTATIONS,
            'Every focused native family and every observed-profile-derived local job must be present.');
        ksort($plans, SORT_STRING);
        return $plans;
    }

    private static function callbackPlans(IssueFilterContext $context, callable $add, array $binding, bool $typed): void
    {
        $native = LocalCacheSelectedNativeAliases::select($context->codebase, $binding);
        self::need(count($native->parameters) === 1 && $native->parameters[0]->type !== null
            && $native->parameters[0]->declaredType !== null, 'An actual callback receiving declaration is required.');
        $type = $native->parameters[0]->type->type; $callableIndex = null; $nullIndex = null;
        foreach ($type->atomicTypes as $i => $atomic) {
            if ($atomic instanceof CallableType) { self::need($callableIndex === null, 'Only one observed callback atom is supported.'); $callableIndex = $i; }
            elseif ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null) { $nullIndex = $i; }
            else { throw new \RuntimeException('The genuine callback union has an unexpected atom.'); }
        }
        self::need(count($type->atomicTypes) === 2 && $callableIndex !== null && $nullIndex !== null
            && $type->atomicTypes[$callableIndex]->signature !== null
            && count($type->atomicTypes[$callableIndex]->signature->parameters) === 1,
            'The exact genuine one-parameter callback|null representation is required.');
        $family = $typed ? 'typed-marker-storage-callback' : 'routes-marker-storage-callback';
        $prefix = ['parameters', 0, 'type', 'type', 'atomicTypes', $callableIndex, 'signature'];
        foreach (['type', 'byReference', 'variadic', 'hasDefault'] as $field) {
            $add($family, $field, $binding, static fn(object $n): object => self::at($n, [...$prefix, 'parameters', 0],
                static fn(object $p): object => self::copy($p, [$field => $field === 'type' ? Type::string() : !$p->$field])));
        }
        $add($family, 'return-domain', $binding, static fn(object $n): object => self::at($n, $prefix,
            static fn(object $s): object => self::copy($s, ['returnType' => Type::string()])));
        $add($family, 'null-branch-removed', $binding, static fn(object $n): object => self::at($n,
            ['parameters', 0, 'type', 'type'], static fn(Type $t): Type => Type::fromAtomics($t->atomicTypes[$callableIndex])->withFlags($t->flags)));
        self::parameter($add, $family, $binding, 0, 'effective-doc-origin', static fn(object $p): object => self::copy($p,
            ['type' => self::copy($p->type, ['fromDocblock' => !$p->type->fromDocblock])]));
        self::parameter($add, $family, $binding, 0, 'effective-location', static fn(object $p): object => self::copy($p,
            ['type' => self::copy($p->type, ['location' => self::shift($p->type->location)])]));
        self::parameter($add, $family, $binding, 0, 'declared-domain', static fn(object $p): object => self::copy($p,
            ['declaredType' => self::copy($p->declaredType, ['type' => Type::string()])]));
    }

    public static function recipe(IssueFilterContext $context, array $proof): array
    {
        $dependency = $proof['contract']['registrationReceivers']['selectedDependency'] ?? null;
        self::need(($proof['remove'] ?? false) === true && ($proof['nativeTypesChanged'] ?? null) === false
            && is_array($dependency) && ($dependency['kind'] ?? null) === 'local-cache-caller'
            && ($dependency['source']['recipe'] ?? null) === 'guarded-captured-application-local-cache-manager'
            && in_array($context->issue->code, ['array-to-string-conversion', 'mixed-argument'], true),
            'Focused controls require a fresh admitted genuine local-cache formatter context.');
        $source = $dependency['source'];
        self::need(is_string($source['callerClass'] ?? null) && $source['callerClass'] !== ''
            && is_string($source['callerMethod'] ?? null) && $source['callerMethod'] !== ''
            && is_string($source['path'] ?? null) && @hash_file('sha256', $source['path']) === ($source['hash'] ?? null),
            'The physical named caller must remain current.');
        return $source;
    }
    private static function parameter(callable $add, string $family, array $binding, int $index, string $label, callable $change): void
    {
        $add($family, self::symbol($binding).'/parameter-'.$index.'/'.$label, $binding,
            static fn(object $n): object => self::at($n, ['parameters', $index], $change));
    }
    private static function at(mixed $value, array $path, callable $change): mixed
    {
        if ($path === []) { return $change($value); }
        $key = array_shift($path);
        if (is_array($value)) {
            self::need(array_key_exists($key, $value), 'A genuinely observed nested array field is absent.');
            $value[$key] = self::at($value[$key], $path, $change); return $value;
        }
        self::need(is_object($value) && property_exists($value, (string)$key), 'A genuinely observed nested native field is absent.');
        return self::copy($value, [$key => self::at($value->$key, $path, $change)]);
    }
    private static function copy(object $native, array $changes): object { return LocalCacheSelectedNativeAliases::copy($native, $changes); }
    private static function symbol(array $binding): string { return ($binding['class'] ?? '').'::'.$binding['name']; }
    private static function shift(?SourceLocation $location): SourceLocation
    {
        self::need($location !== null && $location->span->end < 4294967295, 'A current finite source span is required.');
        return new SourceLocation($location->file, new Span($location->span->start + 1, $location->span->end + 1));
    }
    private static function need(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
}
