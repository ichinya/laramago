<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Tests\Support;

use Ichinya\Laramago\Analyzer\WeakNumericFloatCompatibilityFilter;

use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\FloatType;
use Mago\Sdk\Analyzer\Type\FloatTypeKind;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Annotation;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\ReportedIssue;
use Mago\Sdk\Reporting\TextEdit;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;

/** Genuine native context controls. Never creates a positive Codebase/Type comparator. */
final class WeakNumericFloatControlObserver implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('fixture/weak-float-observer', 'Weak float controls', 'Current native source, callable and exact issue controls'); }
    public function register(PluginRegistry $registry): void
    {
        $root = $this->root;
        $registry->registerIssueFilterHook(new class($root) implements IssueFilterHook {
            private array $checked = [];
            private WeakNumericFloatCompatibilityFilter $filter;
            public function __construct(private readonly string $root) { $this->filter = new WeakNumericFloatCompatibilityFilter($root); }
            public function getCodes(): array { return ['invalid-argument', 'invalid-return-statement']; }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $result = $this->filter->filterIssue($context);
                $mode = $context->issue->code;
                if (basename($context->file) !== 'focus.php' || isset($this->checked[$mode])) { return $result; }
                // Preserve the real snapshot before a native positive can fail.
                $argument = $mode === 'invalid-argument';
                $callee = $argument ? $context->codebase->getMethod('FloatFocus\InvoiceTotals', 'accept') : $context->codebase->getMethod('FloatFocus\InvoiceTotals', 'returnAmount');
                $caller = $argument ? $context->codebase->getMethod('FloatFocus\InvoiceTotals', 'run') : $callee;
                $declaring = $context->codebase->getDeclaringMethod('FloatFocus\InvoiceTotals', $argument ? 'accept' : 'returnAmount');
                $owner = $context->codebase->getClass('FloatFocus\InvoiceTotals');
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                file_put_contents($this->root.'/native-'.$mode.'-metadata.txt', var_export(['callee' => $callee, 'caller' => $caller,
                    'declaring' => $declaring, 'owner' => $owner, 'issue' => $context->issue, 'cache' => $cache->values], true));
                $checks = [];
                $expect = function (string $label, IssueFilterContext $input, bool $remove) use (&$checks, $mode): void {
                    $actual = $this->filter->filterIssue($input) === IssueFilterDecision::Remove;
                    if ($actual !== $remove) { throw new \RuntimeException('Weak float control failed: '.$mode.' / '.$label); }
                    $checks[$label] = true;
                    file_put_contents($this->root.'/native-'.$mode.'-controls-progress.json', json_encode($checks, JSON_THROW_ON_ERROR));
                };
                $expect('genuine native positive before mutations', $context, true);
                if ($callee === null || $caller === null || $argument && ($owner === null || $declaring === null)) { throw new \RuntimeException('Missing native contract metadata.'); }
                $this->checked[$mode] = true;
                $issue = $context->issue;
                $alter = static fn (array $changes): ReportedIssue => new ReportedIssue(...array_replace(get_object_vars($issue), $changes));
                $with = static fn (ReportedIssue $report, ?string $bytes = null): IssueFilterContext => new IssueFilterContext(
                    $context->phpVersion, $context->codebase, $context->types, $context->cancellation, $context->file, $bytes ?? $context->contents, $report);
                $primary = $issue->annotations[0];
                $annotation = static fn (array $changes): Annotation => new Annotation(...array_replace(get_object_vars($primary), $changes));
                $envelopes = [
                    'Warning severity' => ['level' => Level::Warning], 'foreign code' => ['code' => 'invalid-property-assignment-value'],
                    'foreign callable message' => ['message' => str_replace('FloatFocus\\', 'Foreign\\', $issue->message)],
                    'missing note' => ['notes' => []], 'extra note' => ['notes' => [...$issue->notes, 'Unrelated note.']],
                    'changed help' => ['help' => null], 'foreign link' => ['link' => 'https://example.invalid'],
                    'suggested edit' => ['edits' => [TextEdit::delete($primary->span)]],
                    'no annotation' => ['annotations' => []],
                    'primary kind' => ['annotations' => [$annotation(['kind' => AnnotationKind::Secondary]), ...array_slice($issue->annotations, 1)]],
                    'foreign primary file' => ['annotations' => [$annotation(['file' => 'foreign.php']), ...array_slice($issue->annotations, 1)]],
                    'primary nearby span' => ['annotations' => [$annotation(['span' => new Span($primary->span->start + 1, $primary->span->end)]), ...array_slice($issue->annotations, 1)]],
                    'different primary type' => ['annotations' => [$annotation(['message' => 'This has type `string`']), ...array_slice($issue->annotations, 1)]],
                ];
                if ($argument) {
                    $secondary = $issue->annotations[1];
                    $other = static fn (array $changes): Annotation => new Annotation(...array_replace(get_object_vars($secondary), $changes));
                    $envelopes += ['missing secondary' => ['annotations' => [$primary]],
                        'reversed annotations' => ['annotations' => [$secondary, $primary]],
                        'foreign secondary file' => ['annotations' => [$primary, $other(['file' => 'foreign.php'])]],
                        'secondary nearby span' => ['annotations' => [$primary, $other(['span' => new Span($secondary->span->start + 1, $secondary->span->end)])]],
                        'unknown parameter position' => ['message' => str_replace('argument #1', 'argument #99', $issue->message)]];
                } else { $envelopes['extra annotation'] = ['annotations' => [$primary, $primary]]; }
                foreach ($envelopes as $label => $changes) { $expect($label, $with($alter($changes)), false); }
                foreach (['string', 'string(text)', 'numeric', 'numeric-string|null', 'numeric-string|int', 'numeric-string|mixed', 'numeric-string|array', 'numeric-string|false'] as $domain) {
                    $found = $argument ? 'numeric-string' : 'float(1.5)|numeric-string';
                    $report = $alter(['message' => str_replace('`'.$found.'`', '`'.$domain.'`', $issue->message),
                        'notes' => array_map(static fn ($note) => str_replace('`'.$found.'`', '`'.$domain.'`', $note), $issue->notes),
                        'annotations' => [$annotation(['message' => 'This has type `'.$domain.'`']), ...array_slice($issue->annotations, 1)]]);
                    $expect('outside numeric float domain: '.$domain, $with($report), false);
                }
                foreach (['same-path altered current bytes' => $context->contents.' ', 'malformed current bytes' => '<?php function broken( {',
                    'oversized current bytes' => $context->contents.str_repeat(' ', 1024 * 1024)] as $label => $bytes) { $expect($label, $with($issue, $bytes), false); }
                $diskPath = $this->filter->source->path($context->file);
                $diskBytes = file_get_contents($diskPath);
                try { file_put_contents($diskPath, $diskBytes.' '); $expect('same-path disk changed after native snapshot', $context, false); }
                finally { file_put_contents($diskPath, $diskBytes); }
                $snapshot = $cache->values;
                $copy = static function (object $dto, array $changes): object { $class = $dto::class; return new $class(...array_replace(get_object_vars($dto), $changes)); };
                $shift = static fn (SourceLocation $location): SourceLocation => new SourceLocation($location->file, new Span($location->span->start + 1, $location->span->end));
                $mutations = [
                    'missing native callee' => [$callee, null],
                    'native callee foreign location' => [$callee, $copy($callee, ['location' => new SourceLocation('foreign.php', $callee->location->span)])],
                    'native callee nearby span' => [$callee, $copy($callee, ['location' => $shift($callee->location)])],
                    'native callee nearby name span' => [$callee, $copy($callee, ['nameLocation' => $shift($callee->nameLocation)])],
                    'native callee magic' => [$callee, $copy($callee, ['flags' => new MetadataFlags($callee->flags->bits | MetadataFlags::MAGIC_METHOD)])],
                    'native callee builtin' => [$callee, $copy($callee, ['flags' => new MetadataFlags($callee->flags->bits | MetadataFlags::BUILTIN)])],
                    'native callee reference return' => [$callee, $copy($callee, ['flags' => new MetadataFlags($callee->flags->bits | MetadataFlags::BY_REFERENCE)])],
                    'native callee docblock state' => [$callee, $copy($callee, ['hasDocblock' => ! $callee->hasDocblock])],
                ];
                if ($argument) {
                    $parameter = $callee->parameters[0];
                    foreach (['declaredType', 'type'] as $field) {
                        $type = $parameter->$field;
                        foreach (['docblock' => ['fromDocblock' => true], 'inferred' => ['inferred' => true],
                            'string' => ['type' => Type::string()], 'narrow float' => ['type' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Float, new FloatType(FloatTypeKind::Literal, 1.0)))],
                            'nearby type span' => ['location' => $shift($type->location)]] as $label => $changes) {
                            $parameters = $callee->parameters; $parameters[0] = $copy($parameter, [$field => $copy($type, $changes)]);
                            $mutations['native parameter '.$field.' '.$label] = [$callee, $copy($callee, ['parameters' => $parameters])];
                        }
                    }
                    foreach (['name' => '$foreign', 'nameLocation' => $shift($parameter->nameLocation),
                        'location' => $shift($parameter->location),
                        'flags' => new MetadataFlags($parameter->flags->bits | MetadataFlags::BY_REFERENCE), 'outType' => $parameter->type,
                        'defaultType' => $parameter->type] as $field => $replacement) {
                        $parameters = $callee->parameters; $parameters[0] = $copy($parameter, [$field => $replacement]);
                        $mutations['native parameter '.$field] = [$callee, $copy($callee, ['parameters' => $parameters])];
                    }
                    foreach (['variadic' => MetadataFlags::VARIADIC, 'invented default' => MetadataFlags::HAS_DEFAULT] as $label => $flag) {
                        $parameters = $callee->parameters; $parameters[0] = $copy($parameter, ['flags' => new MetadataFlags($parameter->flags->bits | $flag)]);
                        $mutations['native parameter '.$label] = [$callee, $copy($callee, ['parameters' => $parameters])];
                    }
                    $mutations += ['missing native caller' => [$caller, null],
                        'native caller nearby scope' => [$caller, $copy($caller, ['location' => $shift($caller->location)])],
                        'native method public dispatch' => [$callee, $copy($callee, ['visibility' => Visibility::Public])],
                        'missing native owner' => [$owner, null],
                        'native owner interface kind' => [$owner, $copy($owner, ['kind' => ClassLikeKind::Interface])],
                        'native owner incomplete hierarchy' => [$owner, $copy($owner, ['unresolvedHierarchyDependencies' => ['UnknownParent']])],
                        'native owner nearby scope' => [$owner, $copy($owner, ['location' => $shift($owner->location)])]];
                } else {
                    foreach (['declaredReturnType', 'returnType'] as $field) {
                        $type = $callee->$field;
                        foreach (['missing' => null, 'docblock' => $copy($type, ['fromDocblock' => true]),
                            'inferred' => $copy($type, ['inferred' => true]), 'string' => $copy($type, ['type' => Type::string()]),
                            'narrow float' => $copy($type, ['type' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Float, new FloatType(FloatTypeKind::Literal, 1.0)))]), 'nearby type span' => $copy($type, ['location' => $shift($type->location)])] as $label => $replacement) {
                            $mutations['native '.$field.' '.$label] = [$callee, $copy($callee, [$field => $replacement])];
                        }
                    }
                }
                if ($owner === null || count($owner->mixins) !== 1 || count($owner->mixins[0]->atomicTypes) !== 1
                    || ! $owner->mixins[0]->atomicTypes[0] instanceof \Mago\Sdk\Analyzer\Type\NamedObjectType) {
                    throw new \RuntimeException('Missing genuine simple source-written mixin positive.');
                }
                $mixin = $owner->mixins[0];
                $atom = $mixin->atomicTypes[0];
                $mixinClass = $context->codebase->getClassLike($atom->name);
                if ($mixinClass === null) { throw new \RuntimeException('Missing genuine mixin target class.'); }
                foreach ([
                    'missing source-written native mixin' => [],
                    'additional source-absent native mixin' => [$mixin, Type::namedObject('FloatFixtures\\VirtualFloatStorage')],
                    'wrong native mixin name' => [Type::namedObject('FloatFixtures\\VirtualFloatStorage')],
                    'duplicate native mixins' => [$mixin, $mixin],
                    'nullable native mixin union' => [Type::union($mixin, Type::null())],
                    'native scalar mixin' => [Type::string()],
                ] as $label => $replacements) {
                    $mutations[$label] = [$owner, $copy($owner, ['mixins' => $replacements])];
                }
                foreach (['static' => true, 'isThis' => true, 'remappedParameters' => true,
                    'parameters' => [Type::int()], 'intersections' => [Type::string()->atomicTypes[0]]] as $field => $replacement) {
                    $mutations['native mixin atom '.$field] = [$owner, $copy($owner, ['mixins' => [Type::fromAtomic($copy($atom, [$field => $replacement]))]])];
                }
                $mutations['missing native mixin target class'] = [$mixinClass, null];
                $mutations['incomplete native mixin target hierarchy'] = [$mixinClass, $copy($mixinClass, ['unresolvedHierarchyDependencies' => ['UnknownMixinParent']])];
                foreach ($mutations as $label => [$original, $replacement]) {
                    $replaced = 0;
                    foreach ($snapshot as $operation => $entries) { foreach ($entries as $key => $entry) { if ($entry === $original) { $cache->values[$operation][$key] = $replacement; $replaced++; } } }
                    try { if ($replaced === 0) { throw new \RuntimeException('Vacuous native cache mutation: '.$label); } $expect($label, $context, false); }
                    finally { $cache->values = $snapshot; }
                }
                $sourceTag = '@mixin \\FloatFixtures\\DecimalInput';
                if (substr_count($context->contents, $sourceTag) !== 1) { throw new \RuntimeException('Missing single genuine source mixin tag.'); }
                foreach (['source omits existing native mixin' => '', 'source changes native mixin name' => '@mixin \\Foreign\\Unknown',
                    'source changes plain mixin to dialect tag' => '@psalm-mixin Foreign', 'source changes plain mixin to nullable type' => '@mixin ?Contract'] as $label => $replacement) {
                    $replacement = str_pad($replacement, strlen($sourceTag), ' ');
                    if (strlen($replacement) !== strlen($sourceTag)) { throw new \RuntimeException('Source guard mutation changes native header offsets.'); }
                    $mutant = str_replace($sourceTag, $replacement, $context->contents);
                    try { file_put_contents($diskPath, $mutant); $expect($label, $with($issue, $mutant), false); }
                    finally { file_put_contents($diskPath, $diskBytes); }
                }
                $expect('native cache restored', $context, true);
                $cancelled = new class implements \Mago\Sdk\CancellationTokenInterface {
                    public function isCancelled(): bool { return true; }
                    public function throwIfCancelled(): void { throw new \Mago\Sdk\Exception\CancelledException; }
                    public function subscribe(\Closure $callback): int { return 0; }
                    public function unsubscribe(int $subscription): void {}
                };
                $expect('cancelled current context', new IssueFilterContext($context->phpVersion, $context->codebase, $context->types,
                    $cancelled, $context->file, $context->contents, $issue), false);
                $this->filter->initialize(new InitializationContext($context->phpVersion, $context->cancellation));
                $expect('initialization resets source cache', $context, true);
                file_put_contents($this->root.'/native-'.$mode.'-controls.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                return $result;
            }
        });
    }
}
