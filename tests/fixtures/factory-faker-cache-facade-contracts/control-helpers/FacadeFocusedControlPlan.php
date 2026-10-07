<?php
declare(strict_types=1);
namespace Example\LocalCacheFocusedTests;

use Mago\Sdk\Analyzer\{IssueFilterContext, Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, FunctionLikeKind, MetadataFlags};
use Mago\Sdk\Analyzer\Type\{FunctionLikeKind as IdentifierKind, Visibility};
use Mago\Sdk\{SourceLocation, Span};

/** Added facade obligations; this does not replace local131 or receiver360. */
final class FacadeFocusedControlPlan
{
    public const EXPECTED_MUTATIONS = 116;
    public const EXPECTED_FAMILIES = 7;
    private const CACHE = 'Illuminate\\Support\\Facades\\Cache';
    private const FACADE = 'Illuminate\\Support\\Facades\\Facade';
    private const CONFIG_TRAIT = 'Illuminate\\Foundation\\Testing\\WithCachedConfig';

    /** Same descriptor shape as the genuine selected-alias control helper. */
    public static function append(IssueFilterContext $context, array $freshProof, array $plans): array
    {
        self::recipe($context, $freshProof);
        $add = static function(string $family, string $suffix, array $binding, array $aliases, callable $change) use (&$plans): void {
            $label = 'cache-facade/'.$family.'/'.$suffix;
            self::need(!array_key_exists($label, $plans), 'Duplicate facade mutation label.');
            $plans[$label] = ['selected' => $binding, 'bindings' => $aliases, 'change' => $change, 'family' => $family];
        };
        // This is an actual existing trait DTO used only in negative metadata.
        $trait = LocalCacheSelectedNativeAliases::select($context->codebase,
            ['kind' => 'trait', 'name' => self::CONFIG_TRAIT]);
        self::need($trait->kind === ClassLikeKind::Trait, 'The negative trait must be a genuine standard trait.');
        foreach ([self::CACHE, self::FACADE] as $class) {
            $binding = ['kind' => 'class', 'name' => $class];
            $native = LocalCacheSelectedNativeAliases::select($context->codebase, $binding);
            self::need($native->kind === ClassLikeKind::Class_ && $native->usedTraits === []
                && $native->unresolvedHierarchyDependencies === [] && $native->templates === [],
                'A current complete nongeneric facade class must start with its real empty trait set.');
            self::need($class === self::CACHE
                ? strcasecmp($native->directParentClass ?? '', self::FACADE) === 0
                    && array_map('strtolower', $native->parentClasses) === [strtolower(self::FACADE)]
                : $native->directParentClass === null && $native->parentClasses === [],
                'The current physical Cache-to-Facade hierarchy must be observed.');
            foreach (['kind', 'parent', 'parent-list', 'traits', 'incomplete', 'builtin'] as $field) {
                $add('class-graph', $class.'/'.$field, $binding, [$binding],
                    static fn(object $n): object => self::copy($n, match ($field) {
                        'kind' => ['kind' => ClassLikeKind::Trait],
                        'parent' => ['directParentClass' => $class === self::CACHE ? null : self::CACHE],
                        'parent-list' => ['parentClasses' => $class === self::CACHE ? [] : [strtolower(self::CACHE)]],
                        'traits' => ['usedTraits' => [$trait->name]],
                        'incomplete' => ['unresolvedHierarchyDependencies' => [self::CONFIG_TRAIT]],
                        'builtin' => ['flags' => new MetadataFlags($n->flags->bits | MetadataFlags::BUILTIN)],
                    }));
            }
        }
        $methods = [self::CACHE => ['getFacadeAccessor'],
            self::FACADE => ['getFacadeRoot', 'resolveFacadeInstance', '__callStatic']];
        foreach ($methods as $owner => $names) {
            foreach ($names as $name) {
                $binding = ['kind' => 'method', 'class' => $owner, 'name' => $name];
                $aliases = [$binding];
                if ($owner === self::FACADE) { $aliases[] = ['kind' => 'method', 'class' => self::CACHE, 'name' => $name]; }
                $native = LocalCacheSelectedNativeAliases::select($context->codebase, $binding);
                self::prime($context, $native, $aliases, $owner === self::FACADE);
                self::need($native->kind === FunctionLikeKind::Method && $native->identifier->kind === IdentifierKind::Method
                    && strcasecmp($native->identifier->class ?? '', $owner) === 0 && $native->static
                    && $native->declaredReturnType === null && $native->returnType !== null
                    && $native->returnType->fromDocblock && !$native->returnType->inferred,
                    'Each facade method must have its actual physical owning method and documented return.');
                $symbol = $owner.'::'.$name;
                foreach (['kind', 'owner', 'static', 'reference', 'visibility', 'location'] as $field) {
                    $add('method-owner', $symbol.'/'.$field, $binding, $aliases,
                        static fn(object $n): object => self::copy($n, match ($field) {
                            'kind' => ['kind' => FunctionLikeKind::Function_],
                            'owner' => ['identifier' => self::copy($n->identifier,
                                ['class' => $owner === self::CACHE ? self::FACADE : self::CACHE])],
                            'static' => ['static' => false],
                            'reference' => ['flags' => new MetadataFlags($n->flags->bits | MetadataFlags::BY_REFERENCE)],
                            'visibility' => ['visibility' => $n->visibility === Visibility::Protected ? Visibility::Public : Visibility::Protected],
                            'location' => ['location' => self::shift($n->location)],
                        }));
                }
                foreach (['domain', 'doc', 'inferred', 'location'] as $field) {
                    $add('method-return', $symbol.'/'.$field, $binding, $aliases,
                        static fn(object $n): object => self::at($n, ['returnType'],
                            static fn(object $m): object => self::copy($m, match ($field) {
                                'domain' => ['type' => Type::bool()->withFlags($m->type->flags)],
                                'doc' => ['fromDocblock' => false],
                                'inferred' => ['inferred' => true],
                                'location' => ['location' => self::shift($m->location)],
                            })));
                }
                foreach ($native->parameters as $index => $parameter) {
                    self::need($parameter->flags->bits === 0 && $parameter->declaredType === null
                        && $parameter->defaultType === null && $parameter->outType === null
                        && $parameter->closureThisType === null && $parameter->type !== null
                        && $parameter->type->fromDocblock && !$parameter->type->inferred,
                        'A real by-value, nondefault documented facade formal is required.');
                    foreach (['name', 'reference', 'out', 'declared', 'domain', 'doc', 'location', 'default'] as $field) {
                        $add('method-formal', $symbol.'/parameter-'.$index.'/'.$field, $binding, $aliases,
                            static fn(object $n): object => self::at($n, ['parameters', $index],
                                static fn(object $p): object => self::copy($p, match ($field) {
                                    'name' => ['name' => '$other'],
                                    'reference' => ['flags' => new MetadataFlags($p->flags->bits | MetadataFlags::BY_REFERENCE)],
                                    'out' => ['outType' => $p->type],
                                    'declared' => ['declaredType' => $p->type],
                                    'domain' => ['type' => self::copy($p->type, ['type' => Type::bool()->withFlags($p->type->type->flags)])],
                                    'doc' => ['type' => self::copy($p->type, ['fromDocblock' => false])],
                                    'location' => ['type' => self::copy($p->type, ['location' => self::shift($p->type->location)])],
                                    'default' => ['defaultType' => $p->type],
                                })));
                    }
                }
            }
        }
        foreach (['$app', '$resolvedInstance', '$cached'] as $name) {
            $binding = ['kind' => 'property', 'class' => self::FACADE, 'name' => $name];
            $aliases = [$binding, ['kind' => 'property', 'class' => self::CACHE, 'name' => $name]];
            $native = LocalCacheSelectedNativeAliases::select($context->codebase, $binding);
            self::prime($context, $native, $aliases, true);
            self::need($native->name === $name && $native->location === null && $native->declaredType === null
                && $native->writeType === null && $native->type !== null && $native->type->fromDocblock
                && !$native->type->inferred && $native->flags->contains(MetadataFlags::STATIC)
                && $native->readVisibility === Visibility::Protected && $native->writeVisibility === Visibility::Protected,
                'The observed untyped static Facade property requires real name/doc locations; null declaration location stays null.');
            foreach (['name', 'name-location', 'location', 'static', 'declared', 'write-domain', 'read-visibility', 'write-visibility'] as $field) {
                $add('property-owner', $name.'/'.$field, $binding, $aliases,
                    static fn(object $n): object => self::copy($n, match ($field) {
                        'name' => ['name' => '$other'],
                        'name-location' => ['nameLocation' => self::shift($n->nameLocation)],
                        'location' => ['location' => $n->nameLocation],
                        'static' => ['flags' => new MetadataFlags($n->flags->bits ^ MetadataFlags::STATIC)],
                        'declared' => ['declaredType' => $n->type],
                        'write-domain' => ['writeType' => $n->type],
                        'read-visibility' => ['readVisibility' => Visibility::Public],
                        'write-visibility' => ['writeVisibility' => Visibility::Public],
                    }));
            }
            foreach (['domain', 'doc', 'inferred', 'location'] as $field) {
                $add('property-doc', $name.'/'.$field, $binding, $aliases,
                    static fn(object $n): object => self::at($n, ['type'],
                        static fn(object $m): object => self::copy($m, match ($field) {
                            'domain' => ['type' => Type::string()->withFlags($m->type->flags)],
                            'doc' => ['fromDocblock' => false],
                            'inferred' => ['inferred' => true],
                            'location' => ['location' => self::shift($m->location)],
                        })));
            }
            if ($name === '$cached') {
                self::need($native->defaultType !== null && !$native->defaultType->fromDocblock && $native->defaultType->inferred,
                    'The actual inferred true facade caching default must be present.');
                foreach (['domain', 'doc', 'inferred', 'location'] as $field) {
                    $add('property-default', $name.'/'.$field, $binding, $aliases,
                        static fn(object $n): object => self::at($n, ['defaultType'],
                            static fn(object $m): object => self::copy($m, match ($field) {
                                'domain' => ['type' => Type::false()->withFlags($m->type->flags)],
                                'doc' => ['fromDocblock' => true],
                                'inferred' => ['inferred' => false],
                                'location' => ['location' => self::shift($m->location)],
                            })));
                }
            }
        }
        $families = []; $count = 0;
        foreach ($plans as $label => $job) { if (str_starts_with($label, 'cache-facade/')) { $families[$job['family']] = true; $count++; } }
        self::need($count === self::EXPECTED_MUTATIONS && count($families) === self::EXPECTED_FAMILIES,
            'The complete observed-profile-derived facade catalogue differs.');
        ksort($plans, SORT_STRING);
        return $plans;
    }

    /** Requires the real current facade certificate published by this fresh proof. */
    public static function recipe(IssueFilterContext $context, array $freshProof): array
    {
        $certificate = $freshProof['contract']['registrationReceivers']['cacheFacadeContract'] ?? null;
        self::need(($freshProof['remove'] ?? null) === true && ($freshProof['nativeTypesChanged'] ?? null) === false
            && is_array($certificate) && ($certificate['kind'] ?? null) === 'cache-facade-driver-extension'
            && ($certificate['admitted'] ?? null) === true && ($certificate['nativeTypesChanged'] ?? null) === false
            && in_array($context->issue->code, ['array-to-string-conversion', 'mixed-argument'], true),
            'Facade controls require a fresh genuine formatter Remove with the selected admitted facade certificate.');
        self::need(count($certificate['sourceHashes'] ?? []) === 3 && count($certificate['selectedLookupBindings'] ?? []) === 28,
            'All three current physical files and twenty-eight actual facade lookups must be certified.');
        foreach ($certificate['sourceHashes'] as $path => $hash) {
            self::need(is_string($path) && is_string($hash) && @hash_file('sha256', $path) === $hash,
                'The physically certified facade source must remain current.');
        }
        return $certificate;
    }

    private static function prime(IssueFilterContext $context, object $native, array $bindings, bool $inherited): void
    {
        foreach ($bindings as $index => $binding) {
            $aliases = LocalCacheSelectedNativeAliases::aliases($context->codebase, $binding);
            if ($binding['kind'] === 'method') { [$effective, $declaring] = $aliases; }
            else { [$declaring, $effective] = $aliases; }
            self::need(is_object($declaring) && $declaring::class === $native::class && $declaring == $native,
                'Every inherited declaration must equal its genuinely observed physical owner.');
            self::need($index > 0 && $inherited ? $effective === null
                : is_object($effective) && $effective::class === $native::class && $effective == $native,
                'Observed null child effective lookups must stay null; owning lookups must stay populated.');
        }
    }
    private static function at(mixed $value, array $path, callable $change): mixed
    {
        if ($path === []) { return $change($value); } $key = array_shift($path);
        if (is_array($value)) { self::need(array_key_exists($key, $value), 'An observed nested array field is absent.'); $value[$key] = self::at($value[$key], $path, $change); return $value; }
        self::need(is_object($value) && property_exists($value, (string)$key), 'An observed native field is absent.');
        return self::copy($value, [$key => self::at($value->$key, $path, $change)]);
    }
    private static function copy(object $native, array $changes): object { return LocalCacheSelectedNativeAliases::copy($native, $changes); }
    private static function shift(?SourceLocation $location): SourceLocation
    { self::need($location !== null && $location->span->end < 4294967295, 'An actual finite selected span is required.'); return new SourceLocation($location->file, new Span($location->span->start + 1, $location->span->end + 1)); }
    private static function need(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
}
