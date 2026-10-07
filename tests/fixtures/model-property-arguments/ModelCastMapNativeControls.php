<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\ModelPropertyArgumentFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext, IssueFilterDecision, IssueFilterHook, Type};
use Mago\Sdk\Analyzer\Type\{ArrayKey, ArrayKeyKind, KeyedArrayType};

/** Fixture-only mutations of genuine selected SDK storage; no guessed slots. */
final class ModelCastMapNativeControls implements IssueFilterHook
{
    private bool $checked = false;
    public function __construct(private readonly string $root, private readonly string $output) {}
    public function getCodes(): array { return ['mixed-argument']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($this->checked || basename($context->file) !== 'schema-cases.php'
            || [$context->issue->annotations[0]->span->start, $context->issue->annotations[0]->span->end] !== [1009, 1028]) { return IssueFilterDecision::Keep; }
        $this->checked = true; $checks = $mutations = [];
        $expect = function(string $name, bool $remove) use ($context, &$checks): ModelPropertyArgumentFilter {
            $filter = new ModelPropertyArgumentFilter($this->root); $decision = $filter->filterIssue($context);
            if (($decision === IssueFilterDecision::Remove) !== $remove || $decision === IssueFilterDecision::Keep && $filter->certificate !== []) {
                throw new RuntimeException('Actual cast-map control failed: '.$name);
            }
            $checks[$name] = true; return $filter;
        };
        $initial = $expect('genuine reordered cast map before every mutation', true); $model = $initial->certificate['class'];
        $copy = static function(object $value, array $changes): object { $class = $value::class; return new $class(...array_replace(get_object_vars($value), $changes)); };
        $cache = (new ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
        $mutate = function(string $name, callable $replace, bool $remove = false) use ($context, $model, $expect, $copy, $cache, &$mutations): void {
            $expect($name.' initial genuine admission', true);
            // Populate both real APIs before taking any slot snapshot.
            $aliases = array_values(array_filter([$context->codebase->getDeclaringProperty($model, '$casts'), $context->codebase->getProperty($model, '$casts')]));
            if ($aliases === []) { throw new RuntimeException('No genuine physical cast property was returned.'); }
            foreach ($aliases as $alias) { if ($alias::class !== $aliases[0]::class || $alias != $aliases[0]) { throw new RuntimeException('Physical cast aliases disagree before a mutation.'); } }
            $slots = [];
            foreach ($cache->values as $operation => $entries) { foreach ($entries as $key => $value) {
                foreach ($aliases as $alias) { if (is_object($value) && $value::class === $alias::class && $value == $alias) { $slots[$operation.'|'.$key] = [$operation, $key, $value]; break; } }
            } }
            if ($slots === []) { throw new RuntimeException('No equivalent genuine cast-property cache slot exists.'); }
            $roles = [];
            try {
                foreach ($slots as [$operation, $key, $value]) {
                    $changed = $replace($value);
                    if ($changed == $value) { throw new RuntimeException('A cast-property control was a no-op.'); }
                    $cache->values[$operation][$key] = $changed;
                    $roles[] = ['operation' => $operation, 'observedKey' => $key, 'genuineClass' => $value::class];
                }
                $expect($name, $remove);
            } finally { foreach ($slots as [$operation, $key, $value]) { $cache->values[$operation][$key] = $value; } }
            $expect($name.' restored genuine admission', true); $mutations[$name] = $roles;
        };
        $changedItems = static function(object $property, string $kind) use ($copy): object {
            $default = $property->defaultType; $type = $default?->type; $array = $type?->atomicTypes[0] ?? null;
            if (! $array instanceof KeyedArrayType || count($type->atomicTypes) !== 1 || count($array->knownItems ?? []) !== 2) { throw new RuntimeException('The genuine cast map must contain exactly two literal entries.'); }
            $items = $array->knownItems;
            if ($kind === 'reorder') { $items = array_reverse($items); }
            elseif ($kind === 'missing') { array_pop($items); }
            elseif ($kind === 'value') { $items[0] = $copy($items[0], ['type' => Type::literalString('other')]); }
            elseif ($kind === 'integer-key') { $items[0] = $copy($items[0], ['key' => new ArrayKey(ArrayKeyKind::Integer, 7)]); }
            else { throw new RuntimeException('Unknown fixture control.'); }
            $changedArray = $copy($array, ['knownItems' => $items]);
            return $copy($property, ['defaultType' => $copy($default, ['type' => Type::fromAtomic($changedArray, $type->flags)])]);
        };
        $mutate('native item order preserves the exact cast map', static fn(object $value): object => $changedItems($value, 'reorder'), true);
        foreach (['missing', 'value', 'integer-key'] as $kind) { $mutate('native cast map '.$kind, static fn(object $value): object => $changedItems($value, $kind)); }
        $mutate('native default documentation retains veto', static fn(object $value): object => $copy($value, ['defaultType' => $copy($value->defaultType, ['fromDocblock' => true])]));
        $mutate('native default physical span retains veto', static fn(object $value): object => $copy($value, ['defaultType' => $copy($value->defaultType,
            ['location' => $copy($value->defaultType->location, ['span' => $copy($value->defaultType->location->span, ['start' => $value->defaultType->location->span->start + 1])])])]));
        $file = $this->root.'/app/Record.php'; $before = file_get_contents($file);
        $anchor = "['zeta'=>'string','alpha'=>'integer']";
        if (substr_count($before, $anchor) !== 1) { throw new RuntimeException('One current source cast map is required.'); }
        foreach (['physical value change' => ["['zeta'=>'object','alpha'=>'integer']", false],
            'physical item order preserves the exact cast map' => ["['alpha'=>'integer','zeta'=>'string']", true]] as $name => [$literal, $remove]) {
            $expect($name.' initial genuine admission', true); $changed = str_replace($anchor, $literal, $before);
            if ($changed === $before || strlen($changed) !== strlen($before)) { throw new RuntimeException('The map source control must change bytes while preserving all spans.'); }
            try { file_put_contents($file, $changed); $expect($name, $remove); } finally { file_put_contents($file, $before); }
            $expect($name.' restored genuine admission', true);
            $mutations[$name] = ['beforeSha256' => hash('sha256', $before), 'changedSha256' => hash('sha256', $changed), 'restored' => true];
        }
        file_put_contents($this->output.'-cast-map-controls.json', json_encode(['genuinePositiveFirst' => true, 'allRestored' => true,
            'checks' => $checks, 'actualMutations' => $mutations, 'actualCacheVariants' => 6, 'physicalSourceVariants' => 2,
            'nativeIssueAlwaysKept' => true, 'fabricatedContext' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return IssueFilterDecision::Keep;
    }
}
