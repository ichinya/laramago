<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Source-only consumers: no compilation, application execution, or Mago issue publication. */
final class BladeSourceDiagnostics
{
    /**
     * Check literal @livewire and <livewire:name> references in the original buffer.
     * Explicit registration completeness is only one part of the effective name
     * universe. The caller separately asserts complete conventional names, native
     * version-specific syntax, and no custom resolvers or runtime mutations.
     *
     * @param list<string>|null $conventionalNames Names from a separately verified resolver snapshot.
     */
    public function livewire(
        BladeSourceDocument $document,
        LivewireComponentCatalog $registrations,
        ?array $conventionalNames,
        bool $conventionalComplete,
        bool $effectiveResolverComplete,
        bool $nativeReferenceSemantics,
    ): ?BladeSourceCheckResult {
        if (! $nativeReferenceSemantics) {
            return null;
        }
        $scan = (new BladeLivewireReferenceParser)->parse($document->source);
        if ($scan === null) {
            return null;
        }
        $conventional = [];
        if ($conventionalNames !== null) {
            foreach ($conventionalNames as $name) {
                if ($name === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $name) !== 1) {
                    return null;
                }
                $conventional[$name] = true;
            }
        }
        $absenceProven =
            $registrations->isComplete()
            && $conventionalNames !== null
            && $conventionalComplete
            && $effectiveResolverComplete;
        $diagnostics = [];
        foreach ($scan->references as $reference) {
            if (
                str_contains($reference->name, '\\')
                || strpbrk($reference->name, '.:-') === false
                || isset($conventional[$reference->name])
                || $registrations->contains($reference->name) !== false
                || ! $absenceProven
            ) {
                continue;
            }
            $diagnostics[] = $document->diagnostic(
                'blade-missing-livewire-component',
                'The complete effective Livewire component resolver does not contain "'.$reference->name.'".',
                $reference->start,
                $reference->end,
            );
        }

        return new BladeSourceCheckResult($diagnostics, $scan->complete);
    }

    /**
     * The caller asserts native reference directive/helper semantics, including no
     * overriding custom directives, helpers, compiler extensions or precompilers.
     * Only required view lookups are checked. Class-capable @component calls,
     * conditional/optional views and translations without proven locale are skipped.
     */
    public function references(
        BladeSourceDocument $document,
        ReferenceCatalogs $catalogs,
        bool $nativeReferenceSemantics,
    ): ?BladeSourceCheckResult {
        if (! $nativeReferenceSemantics) {
            return null;
        }
        $scan = (new BladeReferenceParser)->parse($document->source);
        if ($scan === null) {
            return null;
        }
        $diagnostics = [];
        foreach ($scan->references as $reference) {
            if (
                $reference->kind !== 'view'
                || $reference->requirement !== 'required'
                || $reference->origin === '@component'
            ) {
                continue;
            }
            if ($catalogs->missingView($reference->name)) {
                $diagnostics[] = $document->diagnostic(
                    'blade-missing-'.$reference->kind,
                    'The explicit catalog does not contain '.$reference->kind.' "'.$reference->name.'".',
                    $reference->start,
                    $reference->end,
                );
            }
        }

        return new BladeSourceCheckResult($diagnostics, $scan->complete);
    }

    public function directives(
        BladeSourceDocument $document,
        BladeDirectiveReferenceChecker $checker,
    ): ?BladeSourceCheckResult {
        if (! $checker->isComplete()) {
            return null;
        }
        $references = $checker->check($document->source);
        if ($references === null) {
            return null;
        }
        $diagnostics = [];
        foreach ($references as $reference) {
            if ($reference->status === 'missing') {
                $diagnostics[] = $document->diagnostic(
                    'blade-missing-directive',
                    'The explicit directive catalog does not contain @'.$reference->name.'.',
                    $reference->start,
                    $reference->end,
                );
            }
        }

        return new BladeSourceCheckResult($diagnostics, true);
    }

    /**
     * Check one caller-selected opening tag in the original buffer. The caller
     * must establish that it is active Blade markup, outside comments/verbatim/PHP.
     * This does not discover tags in a document. Required names are an explicit
     * application contract; constructor candidates alone never imply requiredness.
     *
     * @param list<BladeClassComponent>|null $classes Same snapshot passed to the resolver.
     * @param list<scalar|array|null> $requiredNames
     */
    public function requiredClassProps(
        BladeSourceDocument $document,
        int $start,
        int $end,
        BladeComponentTagResolver $resolver,
        ?array $classes,
        array $requiredNames,
        bool $activeOpeningTag,
    ): ?BladeSourceCheckResult {
        if (
            ! $activeOpeningTag
            || $classes === null
            || $start < 0
            || $end <= $start
            || $end > strlen($document->source)
        ) {
            return null;
        }
        $opening = (new BladeComponentAttributeParser)->parse(substr($document->source, $start, $end - $start));
        if ($opening === null || ! $opening->complete) {
            return null;
        }
        $target = $resolver->resolve($opening->tag);
        if ($target === null || $target->kind === 'anonymous') {
            return null;
        }
        $matching = array_values(array_filter(
            $classes,
            static fn (BladeClassComponent $class): bool => (
                $class->class === $target->name
                && $class->path === $target->path
            ),
        ));
        if (count($matching) !== 1) {
            return null;
        }
        $missing = (new BladeClassRequiredProps)->missingExplicit(
            $matching[0],
            $requiredNames,
            $opening->attributeNames(),
            true,
        );
        if ($missing === null) {
            return null;
        }
        $diagnostics = [];
        foreach ($missing as $name) {
            $diagnostics[] = $document->diagnostic(
                'blade-missing-required-prop',
                'Component "'.$target->name.'" requires the explicitly declared prop "'.$name.'".',
                $start,
                $end,
            );
        }

        return new BladeSourceCheckResult($diagnostics, true);
    }
}
