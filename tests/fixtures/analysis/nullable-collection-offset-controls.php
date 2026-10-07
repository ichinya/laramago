<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\GenericParent;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\Variance;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Annotation;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\ReportedIssue;
use Mago\Sdk\Reporting\TextEdit;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;

/** Single-file actual native cache controls; no simulated positive Codebase or comparator. */
final class NullableCollectionOffsetNativeControls implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('fixture/nullable-index-native-controls', 'Nullable index controls', 'Real native DTO, current source and exact issue mutations'); }
    public function register(PluginRegistry $registry): void
    {
        $registry->registerIssueFilterHook(new class($this->root) implements IssueFilterHook {
            private bool $checked = false;
            private NullableCollectionOffsetIssueFilter $filter;
            public function __construct(private readonly string $root) { $this->filter = new NullableCollectionOffsetIssueFilter($root, 'Illuminate\\Support\\Collection'); }
            public function getCodes(): array { return ['invalid-array-index']; }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $result = $this->filter->filterIssue($context);
                if ($this->checked || basename($context->file) !== 'focus.php') { return $result; }
                $receiver = 'NullableOffsetFixture\\StableCollection'; $support = 'Illuminate\\Support\\Collection';
                $class = $context->codebase->getClass($receiver); $owner = $context->codebase->getClass($support);
                $directGet = $context->codebase->getMethod($receiver, 'offsetGet');
                $directExists = $context->codebase->getMethod($receiver, 'offsetExists');
                $directField = $context->codebase->getProperty($receiver, '$items');
                $get = $context->codebase->getDeclaringMethod($receiver, 'offsetGet');
                $exists = $context->codebase->getDeclaringMethod($receiver, 'offsetExists');
                $set = $context->codebase->getDeclaringMethod($receiver, 'offsetSet');
                $directSet = $context->codebase->getMethod($receiver, 'offsetSet');
                $borrow = $context->codebase->getDeclaringMethod('NullableOffsetFixture\\LocalAccumulator', 'reduce');
                $field = $context->codebase->getDeclaringProperty($receiver, '$items');
                $declaredField = $context->codebase->getDeclaringProperty($receiver, '$items');
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                file_put_contents($this->root.'/native-controls-source-stages.json', json_encode(self::value($this->filter->contract->stages), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                file_put_contents($this->root.'/native-controls-metadata.txt', var_export(['issue' => $context->issue,
                    'receiver' => $class, 'owner' => $owner, 'directGet' => $directGet, 'directExists' => $directExists, 'directField' => $directField,
                    'get' => $get, 'exists' => $exists, 'set' => $set, 'directSet' => $directSet, 'localBorrowBridge' => $borrow, 'field' => $field,
                    'declaringField' => $declaredField], true));
                $checks = [];
                $expect = function (string $label, IssueFilterContext $input, bool $remove) use (&$checks): void {
                    $actual = $this->filter->filterIssue($input) === IssueFilterDecision::Remove;
                    if ($actual !== $remove) {
                        file_put_contents($this->root.'/native-controls-first-failure.json', json_encode(['label' => $label, 'expectedRemove' => $remove,
                            'actualRemove' => $actual, 'stages' => self::value($this->filter->contract->stages), 'passed' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                        throw new \RuntimeException('Nullable native index control failed: '.$label);
                    }
                    $checks[$label] = true;
                    file_put_contents($this->root.'/native-controls-progress.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                };
                $expect('genuine native eligibility before mutations', $context, true);
                if ($class === null || $owner === null || $get === null || $exists === null || $set === null || $borrow === null || $field === null || $field->type === null || $field->defaultType === null) {
                    throw new \RuntimeException('Missing real native collection declarations.');
                }
                $this->checked = true;
                $issue = $context->issue; $primary = $issue->annotations[0];
                $alter = static fn (array $changes): ReportedIssue => new ReportedIssue(...array_replace(get_object_vars($issue), $changes));
                $annotation = static fn (array $changes): Annotation => new Annotation(...array_replace(get_object_vars($primary), $changes));
                $with = static fn (ReportedIssue $report, ?string $bytes = null, ?string $file = null): IssueFilterContext => new IssueFilterContext(
                    $context->phpVersion, $context->codebase, $context->types, $context->cancellation, $file ?? $context->file, $bytes ?? $context->contents, $report);
                $envelopes = [
                    'Warning severity' => ['level' => Level::Warning], 'foreign issue code' => ['code' => 'possibly-null-array-index'],
                    'unknown receiver metadata' => ['message' => str_replace('NullableOffsetFixture\\StableCollection', 'Unknown\\Collection', $issue->message)],
                    'missing note' => ['notes' => []], 'additional note' => ['notes' => [...$issue->notes, 'Another note.']],
                    'stronger native key contract' => ['notes' => [str_replace('`array-key`.', '`int`.', $issue->notes[0])], 'help' => 'Ensure the index expression evaluates to `int`.'],
                    'unknown help' => ['help' => null], 'foreign link' => ['link' => 'https://example.invalid'],
                    'suggested edit' => ['edits' => [TextEdit::delete($primary->span)]], 'missing Primary' => ['annotations' => []],
                    'additional annotation' => ['annotations' => [$primary, $primary]], 'Secondary annotation' => ['annotations' => [$annotation(['kind' => AnnotationKind::Secondary])]],
                    'foreign Primary file' => ['annotations' => [$annotation(['file' => 'foreign.php'])]],
                    'nearby Primary span' => ['annotations' => [$annotation(['span' => new Span($primary->span->start + 1, $primary->span->end)])]],
                    'empty Primary span' => ['annotations' => [$annotation(['span' => new Span($primary->span->start, $primary->span->start)])]],
                    'mismatching Primary native type' => ['annotations' => [$annotation(['message' => 'Type `string` cannot be used as an index here.'])]],
                ];
                foreach ($envelopes as $label => $changes) { $expect($label, $with($alter($changes)), false); }
                foreach (['string', 'int', 'mixed', 'stdClass|null', 'array|null', 'float|null', 'bool|null', 'resource|null', 'null|string(chosen)', 'array-key|int|null', 'null|null'] as $domain) {
                    $expect('rejected native key domain '.$domain, $with($alter(['message' => str_replace('`int|null`', '`'.$domain.'`', $issue->message),
                        'annotations' => [$annotation(['message' => 'Type `'.$domain.'` cannot be used as an index here.'])]])), false);
                }
                foreach (['changed caller source bytes' => $context->contents.' ', 'malformed caller source' => '<?php function broken( {',
                    'oversized caller source' => $context->contents.str_repeat(' ', 1024 * 1024)] as $label => $bytes) { $expect($label, $with($issue, $bytes), false); }
                $expect('foreign current caller path', $with($issue, file: 'foreign.php'), false);
                $copy = static function (object $dto, array $changes): object { $class = $dto::class; return new $class(...array_replace(get_object_vars($dto), $changes)); };
                $shift = static fn (SourceLocation $location): SourceLocation => new SourceLocation($location->file, new Span($location->span->start + 1, $location->span->end));
                $values = $cache->values; $relations = $cache->relations;
                $mutations = [];
                foreach (['missing' => null, 'private' => $copy($set, ['visibility' => Visibility::Private]),
                    'static' => $copy($set, ['static' => true]), 'reference return' => $copy($set, ['flags' => new MetadataFlags($set->flags->bits | MetadataFlags::BY_REFERENCE)]),
                    'foreign declaring owner' => $copy($set, ['identifier' => $copy($set->identifier, ['class' => 'Unknown\\Collection'])]),
                    'nearby source name' => $copy($set, ['nameLocation' => $shift($set->nameLocation)]),
                    'declared return missing' => $copy($set, ['declaredReturnType' => null]),
                    'effective return missing' => $copy($set, ['returnType' => null]),
                    'native return not void' => $copy($set, ['declaredReturnType' => $copy($set->declaredReturnType, ['type' => Type::int()])])] as $change => $replacement) {
                    $mutations['native offsetSet '.$change] = [$set, $replacement];
                }
                foreach ([0, 1] as $position) {
                    $formal = $set->parameters[$position];
                    foreach (['missing doc type' => ['type' => null], 'parameter-out' => ['outType' => $formal->type],
                        'reference' => ['flags' => new MetadataFlags($formal->flags->bits | MetadataFlags::BY_REFERENCE)],
                        'variadic' => ['flags' => new MetadataFlags($formal->flags->bits | MetadataFlags::VARIADIC)],
                        'nearby name span' => ['nameLocation' => $shift($formal->nameLocation)],
                        'doc provenance lost' => ['type' => $copy($formal->type, ['fromDocblock' => false])],
                        'inferred input' => ['type' => $copy($formal->type, ['inferred' => true])],
                        'different domain' => ['type' => $copy($formal->type, ['type' => Type::int()])],
                        'nearby doc span' => ['type' => $copy($formal->type, ['location' => $shift($formal->type->location)])]] as $change => $changes) {
                        $formals = $set->parameters; $formals[$position] = $copy($formal, $changes);
                        $mutations['native offsetSet formal '.$position.' '.$change] = [$set, $copy($set, ['parameters' => $formals])];
                    }
                }
                foreach (['missing' => null, 'foreign owner' => $copy($borrow, ['identifier' => $copy($borrow->identifier, ['class' => 'Unknown\\Trait'])]),
                    'reference return' => $copy($borrow, ['flags' => new MetadataFlags($borrow->flags->bits | MetadataFlags::BY_REFERENCE)]),
                    'nearby source name' => $copy($borrow, ['nameLocation' => $shift($borrow->nameLocation)])] as $change => $replacement) {
                    $mutations['native local accumulator bridge '.$change] = [$borrow, $replacement];
                }
                foreach ([0, 1] as $position) {
                    $formal = $borrow->parameters[$position];
                    foreach (['reference' => ['flags' => new MetadataFlags($formal->flags->bits | MetadataFlags::BY_REFERENCE)],
                        'parameter-out' => ['outType' => $formal->type ?? $formal->declaredType],
                        'foreign name' => ['name' => '$unknown'], 'nearby name span' => ['nameLocation' => $shift($formal->nameLocation)]] as $change => $changes) {
                        // A missing optional native type is not manufactured into an out-type control.
                        if ($change === 'parameter-out' && $changes['outType'] === null) { continue; }
                        $formals = $borrow->parameters; $formals[$position] = $copy($formal, $changes);
                        $mutations['native local bridge formal '.$position.' '.$change] = [$borrow, $copy($borrow, ['parameters' => $formals])];
                    }
                }
                foreach (['NullableOffsetFixture\\BranchStyle', 'NullableOffsetFixture\\LeafStyle'] as $traitName) {
                    $trait = $context->codebase->getTrait($traitName);
                    if ($trait === null) { throw new \RuntimeException('Missing genuine inherited nested trait metadata.'); }
                    foreach (['missing' => null, 'wrong kind' => $copy($trait, ['kind' => ClassLikeKind::Class_]),
                        'incomplete' => $copy($trait, ['unresolvedHierarchyDependencies' => ['UnknownTrait']]),
                        'nearby source scope' => $copy($trait, ['location' => $shift($trait->location)]),
                        'nearby source name' => $copy($trait, ['nameLocation' => $shift($trait->nameLocation)]),
                        'unexpected merged trait closure' => $copy($trait, ['usedTraits' => ['UnknownTrait']])] as $change => $replacement) {
                        $mutations['native inherited trait '.$traitName.' '.$change] = [$trait, $replacement];
                    }
                }
                $mutations['native inherited receiver lost merged traits'] = [$class, $copy($class, ['usedTraits' => []])];
                $mutations['native storage owner lost source traits'] = [$owner, $copy($owner, ['usedTraits' => []])];
                $values = $cache->values; $relations = $cache->relations;
                foreach (['native inherited reader own-name shadow' => ['methods' => ['offsetget']],
                    'native inherited exists own-name shadow' => ['methods' => ['offsetExists']],
                    'native inherited pseudo reader shadow' => ['pseudoMethods' => ['offsetget']],
                    'native inherited static pseudo reader shadow' => ['staticPseudoMethods' => ['offsetget']],
                    'native inherited physical storage shadow' => ['properties' => ['$items']],
                    'native inherited magic storage shadow' => ['magicProperties' => ['$items']]] as $label => $changes) {
                    $mutations[$label] = [$class, $copy($class, $changes)];
                }
                foreach (['receiver' => $class, 'storage owner' => $owner] as $label => $metadata) {
                    foreach (['missing' => null, 'foreign name' => $copy($metadata, ['name' => 'Unknown\\Collection']),
                        'interface' => $copy($metadata, ['kind' => ClassLikeKind::Interface]), 'incomplete' => $copy($metadata, ['unresolvedHierarchyDependencies' => ['UnknownParent']]),
                        'source nearby class span' => $copy($metadata, ['location' => $shift($metadata->location)]),
                        'source nearby name span' => $copy($metadata, ['nameLocation' => $shift($metadata->nameLocation)]),
                        'source missing traits' => $copy($metadata, ['usedTraits' => ['Unknown\\StorageTrait']]),
                        'native injected mixin' => $copy($metadata, ['mixins' => [Type::namedObject('stdClass')]]),
                        'native builtin class' => $copy($metadata, ['flags' => new MetadataFlags($metadata->flags->bits | MetadataFlags::BUILTIN)])] as $change => $replacement) {
                        $mutations['native '.$label.' '.$change] = [$metadata, $replacement];
                    }
                }
                $templates = $owner->templates;
                $template = $templates[0];
                foreach (['missing owner templates' => [], 'wrong native key constraint' => [$copy($template, ['constraint' => Type::int()]), $templates[1]],
                    'wrong native key variance' => [$copy($template, ['variance' => Variance::Contravariant]), $templates[1]],
                    'wrong native value name' => [$templates[0], $copy($templates[1], ['name' => 'TOther'])]] as $label => $replacements) {
                    $mutations[$label] = [$owner, $copy($owner, ['templates' => $replacements])];
                }
                foreach (['offsetGet' => $get, 'offsetExists' => $exists] as $label => $method) {
                    foreach (['missing' => null, 'foreign name' => $copy($method, ['name' => 'unknown']),
                        'wrong kind' => $copy($method, ['kind' => \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Function_]),
                        'private' => $copy($method, ['visibility' => Visibility::Private]), 'static' => $copy($method, ['static' => true]),
                        'abstract' => $copy($method, ['abstract' => true]), 'constructor' => $copy($method, ['constructor' => true]),
                        'doc state' => $copy($method, ['hasDocblock' => ! $method->hasDocblock]),
                        'nearby scope' => $copy($method, ['location' => $shift($method->location)]),
                        'nearby name' => $copy($method, ['nameLocation' => $shift($method->nameLocation)])] as $change => $replacement) {
                        $mutations['native '.$label.' '.$change] = [$method, $replacement];
                    }
                    foreach (['builtin' => MetadataFlags::BUILTIN, 'magic' => MetadataFlags::MAGIC_METHOD, 'reference return' => MetadataFlags::BY_REFERENCE] as $change => $flag) {
                        $mutations['native '.$label.' '.$change] = [$method, $copy($method, ['flags' => new MetadataFlags($method->flags->bits | $flag)])];
                    }
                    $parameter = $method->parameters[0];
                    foreach (['foreign name' => ['name' => '$unknown'], 'native PHP type' => ['declaredType' => $parameter->type],
                        'out type' => ['outType' => $parameter->type], 'default type' => ['defaultType' => $parameter->type],
                        'nearby span' => ['location' => $shift($parameter->location)], 'nearby name span' => ['nameLocation' => $shift($parameter->nameLocation)],
                        'reference' => ['flags' => new MetadataFlags($parameter->flags->bits | MetadataFlags::BY_REFERENCE)],
                        'variadic' => ['flags' => new MetadataFlags($parameter->flags->bits | MetadataFlags::VARIADIC)]] as $change => $changes) {
                        $parameters = $method->parameters; $parameters[0] = $copy($parameter, $changes);
                        $mutations['native '.$label.' parameter '.$change] = [$method, $copy($method, ['parameters' => $parameters])];
                    }
                    foreach (['not docblock' => ['fromDocblock' => false], 'inferred' => ['inferred' => true], 'stronger int' => ['type' => Type::int()],
                        'nearby doc type span' => ['location' => $shift($parameter->type->location)]] as $change => $changes) {
                        $parameters = $method->parameters; $parameters[0] = $copy($parameter, ['type' => $copy($parameter->type, $changes)]);
                        $mutations['native '.$label.' parameter doc '.$change] = [$method, $copy($method, ['parameters' => $parameters])];
                    }
                    foreach (['declaredReturnType', 'returnType'] as $fieldName) {
                        $type = $method->$fieldName;
                        foreach (['missing' => null, 'wrong scalar' => $copy($type, ['type' => Type::string()]), 'inferred' => $copy($type, ['inferred' => true]),
                            'nearby source type span' => $copy($type, ['location' => $shift($type->location)])] as $change => $replacement) {
                            $mutations['native '.$label.' '.$fieldName.' '.$change] = [$method, $copy($method, [$fieldName => $replacement])];
                        }
                    }
                }
                foreach (['missing' => null, 'foreign name' => $copy($field, ['name' => '$other']),
                    'public read' => $copy($field, ['readVisibility' => Visibility::Public]), 'public write' => $copy($field, ['writeVisibility' => Visibility::Public]),
                    'native PHP type' => $copy($field, ['declaredType' => $field->type]), 'strong write type' => $copy($field, ['writeType' => $field->type]),
                    'native typed location' => $copy($field, ['location' => $field->nameLocation]),
                    'nearby native name' => $copy($field, ['nameLocation' => $shift($field->nameLocation)]),
                    'missing native array doc' => $copy($field, ['type' => null]), 'native storage object' => $copy($field, ['type' => $copy($field->type, ['type' => Type::namedObject('NullableOffsetFixture\\ForeignOffsets')])]),
                    'native storage not doc' => $copy($field, ['type' => $copy($field->type, ['fromDocblock' => false])]),
                    'native storage inferred' => $copy($field, ['type' => $copy($field->type, ['inferred' => true])]),
                    'nearby native array doc' => $copy($field, ['type' => $copy($field->type, ['location' => $shift($field->type->location)])]),
                    'missing native default' => $copy($field, ['defaultType' => null]),
                    'native object default' => $copy($field, ['defaultType' => $copy($field->defaultType, ['type' => Type::namedObject('stdClass')])]),
                    'native default doc provenance' => $copy($field, ['defaultType' => $copy($field->defaultType, ['fromDocblock' => true])]),
                    'native default not expression inferred' => $copy($field, ['defaultType' => $copy($field->defaultType, ['inferred' => false])]),
                    'nearby native default source' => $copy($field, ['defaultType' => $copy($field->defaultType, ['location' => $shift($field->defaultType->location)])]) ] as $label => $replacement) {
                    $mutations['native field '.$label] = [$field, $replacement];
                }
                foreach (['virtual' => MetadataFlags::VIRTUAL_PROPERTY, 'static' => MetadataFlags::STATIC, 'readonly' => MetadataFlags::READONLY,
                    'asymmetric' => MetadataFlags::ASYMMETRIC_PROPERTY, 'writeonly' => MetadataFlags::WRITEONLY, 'reference' => MetadataFlags::BY_REFERENCE] as $label => $flag) {
                    $mutations['native field '.$label] = [$field, $copy($field, ['flags' => new MetadataFlags($field->flags->bits | $flag)])];
                }
                $array = $field->type->type->atomicTypes[0];
                $mutations['native array stronger key'] = [$field, $copy($field, ['type' => $copy($field->type, ['type' => Type::fromAtomic(new KeyedArrayType(null, Type::int(), $array->valueType, false))])])];
                $mutations['native array stronger value'] = [$field, $copy($field, ['type' => $copy($field->type, ['type' => Type::fromAtomic(new KeyedArrayType(null, $array->keyType, Type::string(), false))])])];
                $mutations['native array nonempty stronger declaration'] = [$field, $copy($field, ['type' => $copy($field->type, ['type' => Type::fromAtomic($copy($array, ['nonEmpty' => true]))])])];
                $generic = $array->keyType->atomicTypes[0];
                $mutations['native array key wrong generic owner'] = [$field, $copy($field, ['type' => $copy($field->type, ['type' => Type::fromAtomic($copy($array,
                    ['keyType' => Type::fromAtomic($copy($generic, ['definingEntity' => new GenericParent(GenericParentKind::ClassLike, 'Unknown\\Collection')]))]))])])];
                foreach ($mutations as $label => [$original, $replacement]) {
                    $replaced = 0;
                    foreach ($values as $operation => $entries) { foreach ($entries as $key => $entry) { if ($entry === $original || is_object($entry) && $entry == $original) { $cache->values[$operation][$key] = $replacement; $replaced++; } } }
                    try { if ($replaced === 0) { throw new \RuntimeException('Vacuous native cache control: '.$label); } $expect($label, $context, false); }
                    finally { $cache->values = $values; $cache->relations = $relations; }
                }
                // These are actual cached null direct slots observed on this inherited receiver.
                // Injecting an inconsistent direct declaration must never become a fallback.
                foreach (['reader' => [\Mago\Sdk\Internal\Analyzer\Protocol::GET_METHODS, 'offsetget', $get],
                    'exists' => [\Mago\Sdk\Internal\Analyzer\Protocol::GET_METHODS, 'offsetexists', $exists],
                    'setter' => [\Mago\Sdk\Internal\Analyzer\Protocol::GET_METHODS, 'offsetset', $set],
                    'storage' => [\Mago\Sdk\Internal\Analyzer\Protocol::GET_PROPERTIES, '$items', $field]] as $label => [$operation, $member, $declaration]) {
                    $key = strtolower($receiver)."\0".$member;
                    $bucket = $operation << 4;
                    if (! array_key_exists($key, $values[$bucket] ?? []) || $values[$bucket][$key] !== null) {
                        throw new \RuntimeException('Genuine inherited null direct slot missing: '.$label);
                    }
                    try {
                        $cache->values[$bucket][$key] = $copy($declaration, ['name' => 'unknown']);
                        $expect('inconsistent current inherited direct slot '.$label, $context, false);
                    } finally { $cache->values = $values; $cache->relations = $relations; }
                }
                $relation = \Mago\Sdk\Internal\Analyzer\Protocol::ALL_DESCENDANTS; $key = strtolower($receiver);
                if (! array_key_exists($key, $relations[$relation] ?? [])) { throw new \RuntimeException('Native descendant query was not cached.'); }
                try { $cache->relations[$relation][$key] = ['Unknown\\Descendant']; $expect('unknown known descendant must defer', $context, false); }
                finally { $cache->relations = $relations; }
                $callerPath = $this->filter->contract->path($context->file); $callerBytes = file_get_contents($callerPath);
                try { file_put_contents($callerPath, $callerBytes.' '); $expect('caller disk changed after frozen snapshot', $context, false); }
                finally { file_put_contents($callerPath, $callerBytes); }
                $ownerPath = $this->filter->contract->path($owner->location->file); $ownerBytes = file_get_contents($ownerPath);
                $sourceMutations = ['current native array doc changed' => ['/** @var array<TKey, TValue> */', '/** @var array<TKey, mixed>  */'],
                    'current getter uses other physical field' => ['return $this->items[$offset];', 'return $this->other[$offset];'],
                    'current empty-array default changed' => ['protected $items = [];', 'protected $items = 42;'],
                    'current setter null test changed' => ['if (is_null($offset))', 'if (is_bool($offset))'],
                    'current local borrow not static' => ['reduce(static function', 'reduce(       function'],
                    'current local borrow key changed' => ['$reduce[0] += $resolved;', '$reduce[9] += $resolved;'],
                    'current bridge reads a different local' => ['$result = $initial;', '$result = $unknown;']];
                foreach ($sourceMutations as $label => [$find, $replacement]) {
                    if (substr_count($ownerBytes, $find) !== 1 || strlen($find) !== strlen($replacement)) { throw new \RuntimeException('Source mutation would be vacuous or shift native spans: '.$label); }
                    try { file_put_contents($ownerPath, str_replace($find, $replacement, $ownerBytes)); $expect($label, $context, false); }
                    finally { file_put_contents($ownerPath, $ownerBytes); }
                }
                $pattern = '/@template TValue(?=\s*\*\s*@implements)/';
                if (preg_match_all($pattern, $ownerBytes, $found, PREG_OFFSET_CAPTURE) !== 1) { throw new \RuntimeException('Missing unique owner generic source tag.'); }
                $mutant = substr_replace($ownerBytes, '@template TOther', $found[0][0][1], strlen($found[0][0][0]));
                try { file_put_contents($ownerPath, $mutant); $expect('current native generic owner changed', $context, false); }
                finally { file_put_contents($ownerPath, $ownerBytes); }
                $cancelled = new class implements \Mago\Sdk\CancellationTokenInterface {
                    public function isCancelled(): bool { return true; }
                    public function throwIfCancelled(): void { throw new \Mago\Sdk\Exception\CancelledException; }
                    public function subscribe(\Closure $callback): int { return 0; }
                    public function unsubscribe(int $subscription): void {}
                };
                $expect('cancelled native context', new IssueFilterContext($context->phpVersion, $context->codebase, $context->types,
                    $cancelled, $context->file, $context->contents, $issue), false);
                $expect('genuine native source/cache restored', $context, true);
                file_put_contents($this->root.'/native-controls.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                return $result;
            }
            private static function value(mixed $value):mixed {
                if($value instanceof \UnitEnum) { return $value->name; }
                if(is_object($value)) { return self::value(get_object_vars($value)); }
                return is_array($value)?array_map(self::value(...),$value):$value;
            }
        });
    }
}
