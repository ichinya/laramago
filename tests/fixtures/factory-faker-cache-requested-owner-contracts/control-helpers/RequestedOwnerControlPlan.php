<?php
declare(strict_types=1);
namespace Example\LocalCacheRequestedOwnerTests;

use Example\LocalCacheFocusedTests\LocalCacheFocusedControlPlan;
use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, ClassLikeMetadata, MetadataFlags};
use PhpParser\Node;

/** Two independent new controls; this fixture does not invoke the unchanged 131-job builder. */
final class RequestedOwnerControlPlan
{
    public const MUTATION_COUNT = 2;
    private const PARENT = 'Example\\LocalCacheRegistration\\EmptyCacheParent';
    private const OTHER_PARENT = 'Example\\LocalCacheRegistration\\AlternateCacheParent';
    private const TRAIT = 'Example\\LocalCacheRegistration\\EmptyCacheTrait';
    private const OTHER_TRAIT = 'Example\\LocalCacheRegistration\\AlternateCacheTrait';

    /** Each change callback returns the independently obtained genuine DTO, never a constructor-built copy. */
    public static function append(IssueFilterContext $context, array $freshAdmittedProof, array $plans): array
    {
        $source = LocalCacheFocusedControlPlan::recipe($context, $freshAdmittedProof);
        $graph = $freshAdmittedProof['contract']['registrationReceivers']['selectedCallerTraitContract'] ?? null;
        if (!is_array($graph) || ($graph['admitted'] ?? null) !== true || ($graph['nativeTypesChanged'] ?? null) !== false
            || !is_array($graph['nativeBindings'] ?? null) || !is_array($graph['sourceHashes'] ?? null)) {
            throw new \RuntimeException('Wrong-owner controls need the actual admitted current caller trait graph.');
        }
        foreach ($graph['sourceHashes'] as $path => $hash) {
            if (!is_string($path) || @hash_file('sha256', $path) !== $hash) { throw new \RuntimeException('The admitted ancestry source changed.'); }
        }
        $caller = RequestedClassLikeAliases::select($context->codebase,
            ['kind' => 'classlike', 'classLikeKind' => 'Class_', 'name' => $source['callerClass']]);
        if (strcasecmp($caller->directParentClass ?? '', self::PARENT) !== 0
            || !self::names($caller->usedTraits, [self::TRAIT])
            || !self::names($graph['expectedParentClasses'] ?? [], [self::PARENT])
            || !self::names($graph['expectedUsedTraits'] ?? [], [self::TRAIT])) {
            throw new \RuntimeException('The new genuine fixture must physically request the empty parent and trait.');
        }
        foreach ([['requested-parent-owner', self::PARENT, self::OTHER_PARENT, 'Class_'],
            ['requested-trait-owner', self::TRAIT, self::OTHER_TRAIT, 'Trait']] as [$family, $requested, $other, $kind]) {
            $selected = ['kind' => 'classlike', 'classLikeKind' => $kind, 'name' => $requested];
            $counterpart = ['kind' => 'classlike', 'classLikeKind' => $kind, 'name' => $other];
            $native = RequestedClassLikeAliases::select($context->codebase, $selected);
            $replacement = RequestedClassLikeAliases::select($context->codebase, $counterpart);
            $node = $graph['nativeBindings'][strtolower($requested)] ?? null;
            if (!is_array($node) || RequestedClassLikeAliases::hash($node) !== RequestedClassLikeAliases::hash(self::frame($native))) {
                throw new \RuntimeException('The selected parent/trait is absent from the actual admitted graph.');
            }
            $physical = [self::emptyPhysical($native, $source['path']), self::emptyPhysical($replacement, $source['path'])];
            $label = 'local-cache-requested-owner/'.$family.'/different-genuine-'.$kind;
            if (array_key_exists($label, $plans)) { throw new \RuntimeException('Duplicate wrong-owner control label.'); }
            $nativeHash = RequestedClassLikeAliases::hash($native);
            $plans[$label] = ['selected' => $selected, 'bindings' => [$selected], 'counterpart' => $counterpart,
                'family' => $family, 'executionClass' => RequestedOwnerExecution::class,
                'physicalDeclarations' => $physical,
                'change' => static function(object $current) use ($nativeHash, $replacement): object {
                    if (RequestedClassLikeAliases::hash($current) !== $nativeHash) { throw new \RuntimeException('Selected declaration changed after honest plan priming.'); }
                    return $replacement;
                }];
        }
        ksort($plans, SORT_STRING);
        return $plans;
    }

    private static function emptyPhysical(ClassLikeMetadata $native, string $selectedSourcePath): array
    {
        $source = @file_get_contents($selectedSourcePath);
        if ($source === false) { throw new \RuntimeException('The neutral ancestry fixture is absent.'); }
        $path = self::locatedPath($native->location->file, $selectedSourcePath);
        if ($path === null || !self::samePath($path, $selectedSourcePath)) { throw new \RuntimeException('Empty counterpart must be in the freshly copied selected fixture source.'); }
        $nodes = Source::parse($source); $pending = $nodes; $matches = [];
        while ($pending !== []) {
            $node = array_shift($pending);
            if ($node instanceof Node\Stmt\Namespace_) { array_push($pending, ...$node->stmts); continue; }
            if (($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) && $node->namespacedName !== null
                && strcasecmp($node->namespacedName->toString(), $native->name) === 0) { $matches[] = $node; }
        }
        if (count($matches) !== 1) { throw new \RuntimeException('Exactly one physical counterpart declaration is required.'); }
        $node = $matches[0];
        $kind = $node instanceof Node\Stmt\Class_ ? ClassLikeKind::Class_ : ClassLikeKind::Trait;
        if ($node->name === null || $node->stmts !== [] || $node->getDocComment() !== null || $node->attrGroups !== []
            || $node instanceof Node\Stmt\Class_ && ($node->extends !== null || $node->implements !== [] || $node->isAnonymous()
                || $node->isFinal() || $node->isAbstract() || $node->isReadonly())
            || $native->kind !== $kind || $native->hasIncompleteHierarchy() || $native->directParentClass !== null
            || $native->directParentInterfaces !== [] || $native->parentInterfaces !== [] || $native->parentClasses !== []
            || $native->usedTraits !== [] || $native->requiredExtends !== [] || $native->requiredImplements !== []
            || $native->templates !== [] || $native->mixins !== [] || $native->typeAliases !== []
            || $native->methods !== [] || $native->pseudoMethods !== [] || $native->staticPseudoMethods !== []
            || $native->properties !== [] || $native->magicProperties !== [] || $native->constants !== [] || $native->enumCases !== []
            || $native->flags->contains(MetadataFlags::BUILTIN) || $native->flags->contains(MetadataFlags::FINAL)
            || $native->flags->contains(MetadataFlags::ABSTRACT) || $native->flags->contains(MetadataFlags::READONLY)
            || [$native->location->span->start, $native->location->span->end] !== Source::span($node)
            || $native->nameLocation === null || !self::samePath(self::locatedPath($native->nameLocation->file, $selectedSourcePath) ?? '', $selectedSourcePath)
            || [$native->nameLocation->span->start, $native->nameLocation->span->end] !== Source::span($node->name)) {
            throw new \RuntimeException('Counterpart must be a genuinely source-bound same-kind empty declaration.');
        }
        return ['name' => $native->name, 'originalName' => $native->originalName, 'kind' => $native->kind->name,
            'sourcePath' => $selectedSourcePath, 'sourceSha256' => hash('sha256', $source),
            'sourceSpan' => Source::span($node), 'nativeWholeDtoSha256' => RequestedClassLikeAliases::hash($native)];
    }

    private static function frame(ClassLikeMetadata $native): array
    {
        return ['name' => $native->name, 'originalName' => $native->originalName, 'kind' => $native->kind->name,
            'location' => $native->location, 'nameLocation' => $native->nameLocation, 'directParentClass' => $native->directParentClass,
            'directParentInterfaces' => $native->directParentInterfaces, 'usedTraits' => $native->usedTraits, 'parentClasses' => $native->parentClasses];
    }

    private static function names(array $actual, array $expected): bool
    {
        foreach ($actual as $name) { if (!is_string($name) || $name === '') { return false; } }
        $actual = array_map('strtolower', $actual); $expected = array_map('strtolower', $expected);
        sort($actual); sort($expected); return $actual === $expected;
    }

    private static function locatedPath(?string $file, string $selectedSourcePath): ?string
    {
        if ($file === null || $file === '') { return null; }
        $path = str_replace('\\', '/', $file);
        if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        if (preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) { return null; }
        if (!preg_match('~^(?:[A-Za-z]:/|/)~', $path)) { $path = str_replace('\\', '/', dirname($selectedSourcePath)).'/'.$path; }
        return $path;
    }

    private static function samePath(string $left, string $right): bool
    {
        $left = str_replace('\\', '/', $left); $right = str_replace('\\', '/', $right);
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
    }
}
