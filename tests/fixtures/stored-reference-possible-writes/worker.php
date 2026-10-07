<?php

declare(strict_types=1);

ini_set('display_errors', 'stderr');
$autoload = $argv[1] ?? '';
$root = $argv[2] ?? '';
$mode = $argv[3] ?? '';
$package = dirname($autoload,2);
if (realpath($autoload) !== realpath($package.'/vendor/autoload.php') || !is_dir($root) || !in_array($mode, ['observe', 'isolated', 'contexts', 'startup', 'full-before', 'full-after'], true)) {
    throw new RuntimeException('Only the public autoload and a prepared invented workspace are allowed.');
}
require $autoload;
require $root.'/sources-draft.php';
require $root.'/proofs-draft.php';
require $root.'/filter-draft.php';
function captureCompact(mixed $value): mixed {
    if ($value instanceof UnitEnum) { return ['enum' => $value::class, 'name' => $value->name]; }
    if ($value instanceof PhpParser\Node) { return ['kind' => $value->getType(), 'start' => $value->getStartFilePos(), 'end' => $value->getEndFilePos() + 1]; }
    if (is_object($value)) { $value = get_object_vars($value); }
    if (is_array($value)) { foreach ($value as $key => $item) { if ($key === 'contents') { unset($value[$key]); continue; } $value[$key] = captureCompact($item); } }
    return $value;
}
$plugin = new class($root, $mode) implements Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root, private readonly string $mode) {}
    public function getDefinition(): Mago\Sdk\Analyzer\PluginDefinition {
        return new Mago\Sdk\Analyzer\PluginDefinition('audit/stored-reference-captures', 'Stored reference captures', 'Native/source certification of an independently callable integer writer.');
    }
    public function register(Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $proofs = new Ichinya\Laramago\Analyzer\StaticAnalysis\StoredReferencePossibleWrites($this->root);
        $filter = new Ichinya\Laramago\Analyzer\StoredReferencePossibleWriteFilter($proofs);
        $registry->registerInitializationHook($proofs); $registry->registerCodebaseScanHook($proofs);
        $registry->registerIssueFilterHook(new class($proofs, $filter, $this->root, $this->mode) implements Mago\Sdk\Analyzer\IssueFilterHook {
            private bool $controlled = false;
            public function __construct(private readonly mixed $proofs, private readonly mixed $filter, private readonly string $root, private readonly string $mode) {}
            public function getCodes(): array { return $this->filter->getCodes(); }
            public function filterIssue(Mago\Sdk\Analyzer\IssueFilterContext $context): Mago\Sdk\Analyzer\IssueFilterDecision {
                if (in_array($this->mode,['isolated','full-after'],true)) { return $this->filter->filterIssue($context); }
                if ($this->mode === 'contexts') {
                    if (!$this->controlled && $context->issue->code === 'impossible-assignment' && $context->file === 'context.php') {
                        $this->controlled = true; $this->controls($context);
                    }
                    return Mago\Sdk\Analyzer\IssueFilterDecision::Keep;
                }
                $rows=[];
                foreach($this->proofs->proofs($context->file,$context->contents) as $proof) {
                    $annotation=$context->issue->annotations[0]??null;
                    if($annotation===null || $annotation->span->start<$proof['reader']->getStartFilePos() || $annotation->span->end>$proof['reader']->getEndFilePos()+1){continue;}
                    $domain=$this->proofs->domain($context->codebase,$context->types,$proof);
                    $rows[]=['owner'=>$proof['owner'],'scope'=>$proof['scope']->name->name,'sourceSha256'=>$proof['hash'],'domain'=>$domain===null?null:(string)$domain];
                }
                file_put_contents($this->root.'/native-stages.jsonl',json_encode(['file'=>$context->file,'code'=>$context->issue->code,'proofs'=>$rows],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
                return Mago\Sdk\Analyzer\IssueFilterDecision::Keep;
            }
            private function controls(Mago\Sdk\Analyzer\IssueFilterContext $context): void {
                $keep = Mago\Sdk\Analyzer\IssueFilterDecision::Keep; $remove = Mago\Sdk\Analyzer\IssueFilterDecision::Remove;
                if ($this->filter->filterIssue($context) !== $remove) { throw new RuntimeException('The genuine contextual assignment positive was not admitted.'); }
                $proof = $this->proofs->proofs($context->file, $context->contents)[0] ?? throw new RuntimeException('Missing actual source proof.');
                $cache = (new ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $variants = [];
                $store = $proof['configure']->params[0]->type->toString();
                foreach (['readerProperty', 'writerProperty'] as $key) {
                    $field = $context->codebase->getDeclaringProperty($store, '$'.$proof[$key]);
                    if ($field === null) { throw new RuntimeException('Missing actual physical callback field.'); }
                    foreach (['missing' => null, 'name' => ['name' => '$foreign'], 'hooks' => ['hooks' => ['get' => null]],
                        'private-read' => ['readVisibility' => Mago\Sdk\Analyzer\Type\Visibility::Private], 'private-write' => ['writeVisibility' => Mago\Sdk\Analyzer\Type\Visibility::Private],
                        'declared-missing' => ['declaredType' => null], 'documented-missing' => ['type' => null], 'default-missing' => ['defaultType' => null],
                        'write-direction' => ['writeType' => $field->type], 'name-location' => ['nameLocation' => null],
                        'readonly' => ['flags' => new Mago\Sdk\Analyzer\Metadata\MetadataFlags($field->flags->bits | Mago\Sdk\Analyzer\Metadata\MetadataFlags::READONLY)]] as $label => $changes) {
                        $variants[] = [$key.'-'.$label, $field, $changes];
                    }
                }
                $helper = $context->codebase->getDeclaringMethod($proof['owner'], $proof['helper']);
                $predicate = $context->codebase->getFunction('is_int');
                if ($helper === null || $predicate === null) { throw new RuntimeException('Missing actual helper or builtin predicate.'); }
                $variants[] = ['missing-helper', $helper, null];
                $variants[] = ['helper-visibility', $helper, ['visibility' => Mago\Sdk\Analyzer\Type\Visibility::Public]];
                $variants[] = ['missing-predicate', $predicate, null];
                $variants[] = ['predicate-shadowed', $predicate, ['flags' => new Mago\Sdk\Analyzer\Metadata\MetadataFlags($predicate->flags->bits | Mago\Sdk\Analyzer\Metadata\MetadataFlags::USER_DEFINED)]];
                $checks = [];
                foreach ($variants as [$label, $original, $changes]) {
                    $active=static function() use($context,$original,$store) {
                        return $original instanceof \Mago\Sdk\Analyzer\Metadata\PropertyMetadata
                            ?$context->codebase->getDeclaringProperty($store,'$'.substr($original->name,1))
                            :($original->identifier->class===null?$context->codebase->getFunction($original->identifier->name):$context->codebase->getDeclaringMethod($original->identifier->class,$original->identifier->name));
                    };
                    $replacement=$changes===null?null:new ($original::class)(...array_replace(get_object_vars($original),$changes));$slots=[];
                    foreach($cache->values as $operation=>$entries){foreach($entries as $key=>$value){if($value===$original||is_object($value)&&$value==$original){$slots[]=[$operation,$key,$value];}}}
                    if($slots===[]){throw new RuntimeException('No actual metadata control slot '.$label);}
                    foreach($slots as [$operation,$key]){$cache->values[$operation][$key]=$replacement;}
                    $count=count($slots);
                    try {
                        if($active()!==$replacement || $this->filter->filterIssue($context)!==$keep){throw new RuntimeException('Unsafe or vacuous current native control '.$label);}
                    } finally {foreach($slots as [$operation,$key,$value]){$cache->values[$operation][$key]=$value;}}
                    if($this->filter->filterIssue($context)!==$remove){throw new RuntimeException('Actual native positive did not restore '.$label);}
                    $checks[$label] = ['actualReplacements' => $count, 'kept' => true, 'restoredPositive' => true];
                }
                $issueChecks = [];
                foreach (['wrong-level' => ['level' => Mago\Sdk\Reporting\Level::Warning], 'wrong-note' => ['notes' => ['Different note.']],
                    'wrong-help' => ['help' => 'Different help.'], 'link' => ['link' => 'https://example.invalid'], 'wrong-code' => ['code' => 'invalid-argument'],
                    'annotation-missing' => ['annotations' => []]] as $label => $changes) {
                    $issue = new ($context->issue::class)(...array_replace(get_object_vars($context->issue), $changes));
                    $probe = new ($context::class)(...array_replace(get_object_vars($context), ['issue' => $issue]));
                    if ($this->filter->filterIssue($probe) !== $keep) { throw new RuntimeException('Unsafe exact-envelope control '.$label); }
                    $issueChecks[$label] = true;
                }
                $sourceChecks = [];
                $disk = $this->root.'/'.$context->file; $saved = file_get_contents($disk);
                try {
                    file_put_contents($disk, $saved."\n// Source changed after native analysis.\n");
                    if ($this->filter->filterIssue($context) !== $keep) { throw new RuntimeException('Stale source was admitted.'); }
                    $sourceChecks['stale-current-source'] = true;
                } finally { file_put_contents($disk, $saved); }
                if ($this->filter->filterIssue($context) !== $remove) { throw new RuntimeException('Current source positive did not restore.'); }
                $files = (new ReflectionProperty($this->proofs, 'files'))->getValue($this->proofs);
                foreach (['complete' => false, 'failed' => true, 'started' => false] as $field => $value) {
                    $reflection = new ReflectionProperty($this->proofs, $field); $previous = $reflection->getValue($this->proofs); $reflection->setValue($this->proofs, $value);
                    try { if ($this->filter->filterIssue($context) !== $keep) { throw new RuntimeException('Invalid source lifecycle was admitted: '.$field); } }
                    finally { $reflection->setValue($this->proofs, $previous); }
                    $sourceChecks['lifecycle-'.$field] = true;
                }
                if ($files === [] || $this->filter->filterIssue($context) !== $remove) { throw new RuntimeException('Nonempty actual source lifecycle positive did not restore.'); }
                file_put_contents($this->root.'/context-controls.json', json_encode(['positive' => true, 'nativeControls' => $checks, 'envelopeControls' => $issueChecks,
                    'sourceLifecycleControls' => $sourceChecks, 'restoredPositive' => true, 'overflowCompletionDomain' => 'int|float'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            }
        });
    }
};

if (in_array($mode,['full-before','full-after'],true)) { return; }
$worker = new Mago\Sdk\Worker(new Mago\Sdk\Extension(identifier: 'audit/stored-reference-captures', name: 'Stored reference captures', version: '1', analyzerPlugins: [$plugin]));
if ($mode === 'startup') { echo "Stored-reference worker constructed; no SDK transport or fixture body execution.\n"; exit(0); }
$worker->run();
