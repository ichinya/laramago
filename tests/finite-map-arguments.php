<?php

declare(strict_types=1);

/** Genuine native reports certify the precision boundary; no fixture PHP body is executed. */
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago finite maps '.bin2hex(random_bytes(8));
mkdir($workspace.'/packages/contracts', 0777, true);
file_put_contents($workspace.'/composer.json', json_encode(['config' => ['vendor-dir' => 'packages'], 'autoload' => ['files' => ['bootstrap.php']]], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap must not execute.");');
$contracts = <<<'PHP'
<?php
namespace MapFixture;
final readonly class LabelPath {}
final readonly class LabelEnvelope {
    /**
     * @param non-empty-string $label
     * Reserved selected argument contract for current source validation.
     */
    public function __construct(public string $label, public LabelPath|array $options = [], public string $description = '', public iterable $features = []) {}
}
final readonly class NarrowEnvelope {
    /** @param 'Alpha' $label */
    public function __construct(public string $label) {}
}
final readonly class ReferenceEnvelope {
    /** @param non-empty-string $label */
    public function __construct(string &$label) {}
}
final class PrivateEnvelope {
    /** @param non-empty-string $label */
    private function __construct(string $label) {}
}
function unrelated(): string { return 'description'; }
PHP;
file_put_contents($workspace.'/packages/contracts/declarations.php', $contracts);
$cases = [
    'explicit arrow positional' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ["Alpha", "Beta"]);', true],
    'explicit arrow named constructor' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope(label: $label), ["Alpha", "Beta"]);', true],
    'explicit single-return closure' => ['\\array_map(static function(string $label): LabelEnvelope { return new LabelEnvelope($label); }, ["Alpha", "Beta"]);', true],
    'native named map inputs' => ['\\array_map(callback: static fn(string $label): LabelEnvelope => new LabelEnvelope($label), array: ["Alpha", "Beta"]);', true],
    'later literal configuration' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope(label: $label, options: ["enabled" => true], description: "plain"), ["Alpha", "Beta"]);', true],
    'later unrelated source call' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope(label: $label, description: unrelated()), ["Alpha", "Beta"]);', true],
    'later formal assignment follows selected value' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope(label: $label, description: $label = ""), ["Alpha", "Beta"]);', true],
    'zero string is nonempty' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ["0", "Beta"]);', true],
    'duplicates and unicode are finite' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ["Alpha", "Alpha", "\u{03b1}"]);', true],
    'inferred mapper already native' => ['\\array_map(static fn($label): LabelEnvelope => new LabelEnvelope($label), ["Alpha", "Beta"]);', null],
    'empty member retained' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ["Alpha", ""]);', false],
    'empty literal list retained' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), []);', false],
    'unknown list retained' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), $values);', false],
    'computed element retained' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), [unrelated()]);', false],
    'integer element retained' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ["Alpha", 0]);', false],
    'keyed literal array retained' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), [5 => "Alpha", "Beta"]);', false],
    'unpacked array retained' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ["Alpha", ...$values]);', false],
    'multiple arrays retained' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ["Alpha"], ["Beta"]);', false],
    'assigned mapper retained' => ['$mapper = static fn(string $label): LabelEnvelope => new LabelEnvelope($label); \\array_map($mapper, ["Alpha", "Beta"]);', false],
    'captured closure retained' => ['\\array_map(static function(string $label) use ($values): LabelEnvelope { return new LabelEnvelope($label); }, ["Alpha", "Beta"]);', false],
    'mutable callback body retained' => ['\\array_map(static function(string $label): LabelEnvelope { $label = ""; return new LabelEnvelope($label); }, ["Alpha", "Beta"]);', false],
    'stronger callback documentation retained' => ['\\array_map(/** @param "Alpha" $label */ static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ["Alpha", "Beta"]);', false],
    'stronger wrapper return retained' => ['/** @return never */ \\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ["Alpha", "Beta"]);', false],
    'referenced constructor formal retained' => ['\\array_map(static fn(string $label): ReferenceEnvelope => new ReferenceEnvelope($label), ["Alpha", "Beta"]);', false],
    'private constructor retained' => ['\\array_map(static fn(string $label): PrivateEnvelope => new PrivateEnvelope($label), ["Alpha", "Beta"]);', false],
    'narrow constructor contract retained' => ['\\array_map(static fn(string $label): NarrowEnvelope => new NarrowEnvelope($label), ["Alpha", "Beta"]);', false],
    'selected argument after mutation retained' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope(description: $label = "", label: $label), ["Alpha", "Beta"]);', false],
    'wrong selected formal retained' => ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope(other: $label), ["Alpha", "Beta"]);', false],
    'dynamic consumer retained' => ['$consumer = LabelEnvelope::class; \\array_map(static fn(string $label) => new $consumer($label), ["Alpha", "Beta"]);', false],
    'nonstatic mapper retained' => ['\\array_map(fn(string $label): LabelEnvelope => new LabelEnvelope($label), ["Alpha", "Beta"]);', false],
    'referenced mapper formal retained' => ['\\array_map(static fn(string &$label): LabelEnvelope => new LabelEnvelope($label), ["Alpha", "Beta"]);', false],
];
$labels = array_map(static fn(int $number): string => '"Label'.$number.'"',range(1,64));
$cases['finite input at budget'] = ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ['.implode(',',$labels).']);',true];
$cases['input beyond budget retained'] = ['\\array_map(static fn(string $label): LabelEnvelope => new LabelEnvelope($label), ['.implode(',',[...$labels,'"Overflow"']).']);',false];
$source = "<?php\nnamespace MapFixture;\n";
$ranges = [];
foreach ($cases as $name => [$body, $expected]) {
    $start = strlen($source);
    $source .= '/** @param list<string> $values */ function case'.count($ranges).'(array $values): void { '.$body." }\n";
    $ranges[$name] = [$start, strlen($source), $expected];
}
$shadowStart = strlen($source);
$source .= "namespace ShadowFixture; function array_map(callable \$callback, array \$array): array { return []; }\n"
    .'function shadow(): void { array_map(static fn(string $label): \MapFixture\LabelEnvelope => new \MapFixture\LabelEnvelope($label), ["Alpha", "Beta"]); }'."\n";
$ranges['native function shadow retained'] = [$shadowStart,strlen($source),false];
file_put_contents($workspace.'/cases.php', $source);

$worker = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
use Ichinya\Laramago\Analyzer\FiniteMapArgumentIssueFilter;
use Ichinya\Laramago\Analyzer\StaticAnalysis\FiniteMapArgumentProofs;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\Type;
final class MapTestPlugin implements Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): Mago\Sdk\Analyzer\PluginDefinition { return new Mago\Sdk\Analyzer\PluginDefinition('map-test', 'Map test', 'Source-certified native map boundary checks.'); }
    public function register(Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $index = new FiniteMapArgumentProofs($this->root);
        $filter = new FiniteMapArgumentIssueFilter($index);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $registry->registerAfterFileAnalysisHook(new class($index, $this->root) implements Mago\Sdk\Analyzer\AfterFileAnalysisHook {
            public function __construct(private $index, private $root) {}
            public function getRequirements(): array { return []; }
            public function afterFileAnalysis(Mago\Sdk\Analyzer\AfterFileAnalysisContext $context): void {
                file_put_contents($this->root.'/order.log', 'after:'.$context->analysis->file."\n", FILE_APPEND);
                $source = $context->analysis->getSourceFile();
                $count = 0;
                foreach ($this->index->proofs($source->path, $source->contents) as $proof) {
                    if ($this->index->certificate($context->codebase, $context->types, $proof, $context->analysis->file) !== null) {
                        $count++;
                        $type = $context->analysis->getExpressionType(new Mago\Sdk\Span($proof['callback']->getStartFilePos(), $proof['callback']->getEndFilePos()+1));
                        $signature = $type?->atomicTypes[0]->signature ?? null;
                        if ($signature?->source === null || !$signature->source->equals(FiniteMapArgumentProofs::callbackIdentifier($proof,$context->analysis->file))) { throw new RuntimeException('Optional lookup differs from the real final closure identifier.'); }
                        $input = $context->analysis->getExpressionType(new Mago\Sdk\Span($proof['array']->getStartFilePos(), $proof['array']->getEndFilePos()+1));
                        $atom = $input?->atomicTypes[0] ?? null;
                        if (!$atom instanceof Mago\Sdk\Analyzer\Type\ListType || $atom->knownCount !== count($proof['values']) || count($atom->knownElements??[]) !== count($proof['values'])) { throw new RuntimeException('Native input is not the complete source literal list.'); }
                        foreach ($atom->knownElements as $position=>$element) {
                            if ($element->optional || $element->index !== $position || !$context->types->equals($element->type,Type::literalString($proof['values'][$position]))) { throw new RuntimeException('Source input differs from the real final literal input domain.'); }
                        }
                    }
                    elseif (!is_file($this->root.'/first-deferred.log')) {
                        $native = new ReflectionMethod($this->index, 'nativeMap');
                        $type = $context->analysis->getExpressionType(new Mago\Sdk\Span($proof['callback']->getStartFilePos(), $proof['callback']->getEndFilePos()+1));
                        $input = $context->analysis->getExpressionType(new Mago\Sdk\Span($proof['array']->getStartFilePos(), $proof['array']->getEndFilePos()+1));
                        $signature = $type?->atomicTypes[0]->signature ?? null;
                        $bound = $signature?->source === null ? null : $context->codebase->getFunctionLike($signature->source);
                        $callback = new ReflectionMethod($this->index, 'callback');
                        file_put_contents($this->root.'/first-deferred.log', var_export([
                            'span' => [$proof['callback']->getStartFilePos(),$proof['callback']->getEndFilePos()+1],
                            'native' => $native->invoke(null,$context->codebase,$context->types),
                            'callback' => $bound !== null && $callback->invoke($this->index,$bound,$proof,$context->types),
                            'input' => $input, 'bound' => $bound,
                        ],true));
                    }
                }
                file_put_contents($this->root.'/certificates.log', $count."\n", FILE_APPEND);
            }
        });
        $registry->registerIssueFilterHook(new class($filter, $this->root) implements Mago\Sdk\Analyzer\IssueFilterHook {
            private bool $checked = false;
            public function __construct(private $filter, private $root) {}
            public function getCodes(): array { return ['possibly-invalid-argument']; }
            public function filterIssue(Mago\Sdk\Analyzer\IssueFilterContext $context): IssueFilterDecision {
                file_put_contents($this->root.'/order.log', 'filter:'.$context->file."\n", FILE_APPEND);
                $decision = $this->filter->filterIssue($context);
                if ($decision !== IssueFilterDecision::Remove || $this->checked) { return $decision; }
                $this->checked = true;
                $checks = 0;
                $issue = $context->issue;
                $check = function ($changed) use ($context, &$checks): void {
                    $input = new Mago\Sdk\Analyzer\IssueFilterContext($context->phpVersion, $context->codebase, $context->types, $context->cancellation, $context->file, $context->contents, $changed);
                    if ($this->filter->filterIssue($input) !== IssueFilterDecision::Keep) { throw new RuntimeException('Changed diagnostic envelope was accepted.'); }
                    $checks++;
                };
                foreach (['code', 'level', 'message', 'notes', 'help', 'link', 'primary', 'secondary', 'foreign', 'span', 'contents'] as $variant) {
                    if ($variant === 'contents') {
                        $input = new Mago\Sdk\Analyzer\IssueFilterContext($context->phpVersion, $context->codebase, $context->types, $context->cancellation, $context->file, $context->contents.' ', $issue);
                        if ($this->filter->filterIssue($input) !== IssueFilterDecision::Keep) { throw new RuntimeException('Changed analyzed source was accepted.'); }
                        $checks++; continue;
                    }
                    $v = get_object_vars($issue);
                    if ($variant === 'code') { $v['code'] = 'invalid-argument'; }
                    if ($variant === 'level') { $v['level'] = Mago\Sdk\Reporting\Level::Warning; }
                    if ($variant === 'message') { $v['message'] .= ' changed'; }
                    if ($variant === 'notes') { $v['notes'] = ['Changed note.']; }
                    if ($variant === 'help') { $v['help'] = 'Changed help.'; }
                    if ($variant === 'link') { $v['link'] = 'https://example.test/changed'; }
                    if (in_array($variant, ['primary','secondary','foreign','span'], true)) {
                        $position = $variant === 'secondary' ? 1 : 0;
                        $a = get_object_vars($v['annotations'][$position]);
                        if ($variant === 'foreign') { $a['file'] = 'foreign.php'; }
                        elseif ($variant === 'span') { $a['span'] = new Mago\Sdk\Span($a['span']->start+1, $a['span']->end); }
                        else { $a['message'] = 'Changed annotation.'; }
                        $v['annotations'][$position] = new Mago\Sdk\Reporting\Annotation(...$a);
                    }
                    $check(new Mago\Sdk\Reporting\ReportedIssue(...$v));
                }
                $cache = (new ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $replace = static function ($old, $new) use ($cache): void {
                    foreach ($cache->values as $operation => $entries) { foreach ($entries as $key => $entry) { if ($entry === $old) { $cache->values[$operation][$key] = $new; } } }
                };
                $prove = function () use ($context) {
                    foreach ($this->filter->provenance->proofs($context->file, $context->contents) as $proof) {
                        if ($proof['argument']->value->getStartFilePos() === $context->issue->annotations[0]->span->start) { return $this->filter->provenance->certificate($context->codebase, $context->types, $proof, $context->file); }
                    }
                    return null;
                };
                $metadata = $context->codebase->getFunction('array_map');
                foreach (['name','kind','builtin','reference','declared','effective','parameter-name','parameter-reference','parameter-type'] as $variant) {
                    $snapshot = $cache->values;
                    $v = get_object_vars($metadata);
                    if ($variant === 'name') { $v['originalName'] = 'other'; }
                    if ($variant === 'kind') { $v['kind'] = Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Closure; }
                    if ($variant === 'builtin') { $v['flags'] = new Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits & ~Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                    if ($variant === 'reference') { $v['flags'] = new Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                    if ($variant === 'declared') { $v['declaredReturnType'] = new Mago\Sdk\Analyzer\Metadata\TypeMetadata($metadata->location, Type::never(), false, false); }
                    if ($variant === 'effective') { $v['returnType'] = new Mago\Sdk\Analyzer\Metadata\TypeMetadata($metadata->location, Type::never(), false, false); }
                    if (str_starts_with($variant, 'parameter-')) {
                        $p = get_object_vars($v['parameters'][0]);
                        if ($variant === 'parameter-name') { $p['name'] = '$other'; }
                        if ($variant === 'parameter-reference') { $p['flags'] = new Mago\Sdk\Analyzer\Metadata\MetadataFlags($p['flags']->bits | Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                        if ($variant === 'parameter-type') { $p['type'] = new Mago\Sdk\Analyzer\Metadata\TypeMetadata($metadata->location, Type::never(), false, false); }
                        $v['parameters'][0] = new Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$p);
                    }
                    $replace($metadata, new Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$v));
                    if ($prove() !== null) { throw new RuntimeException('Changed native map metadata accepted: '.$variant); }
                    $checks++; $cache->values = $snapshot;
                }
                $baseProof = null;
                foreach ($this->filter->provenance->proofs($context->file,$context->contents) as $proof) {
                    if ($proof['argument']->value->getStartFilePos() === $issue->annotations[0]->span->start) { $baseProof = $proof; break; }
                }
                if ($baseProof === null) { throw new RuntimeException('Missing genuine selected source proof.'); }
                $callback = $context->codebase->getFunctionLike(FiniteMapArgumentProofs::callbackIdentifier($baseProof,$context->file));
                $constructor = $context->codebase->getDeclaringMethod($baseProof['class'],'__construct');
                foreach (['callback'=>$callback,'constructor'=>$constructor] as $role=>$method) {
                    foreach (['name','kind','file','span','static','reference','builtin','classification','parameter-name','parameter-file','parameter-span','parameter-reference','parameter-type','parameter-declared','parameter-doc','return'] as $variant) {
                        $snapshot = $cache->values; $v = get_object_vars($method);
                        if ($variant==='name') { $v['originalName']='other'; }
                        if ($variant==='kind') { $v['kind']=Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Function_; }
                        if ($variant==='file') { $v['location']=new Mago\Sdk\SourceLocation('foreign.php',$method->location->span); }
                        if ($variant==='span') { $v['location']=new Mago\Sdk\SourceLocation($method->location->file,new Mago\Sdk\Span($method->location->span->start+1,$method->location->span->end)); }
                        if ($variant==='static') { $v['static']=!$method->static; }
                        if ($variant==='reference') { $v['flags']=new Mago\Sdk\Analyzer\Metadata\MetadataFlags($method->flags->bits|Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                        if ($variant==='builtin') { $v['flags']=new Mago\Sdk\Analyzer\Metadata\MetadataFlags($method->flags->bits|Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                        if ($variant==='classification') { $v['flags']=new Mago\Sdk\Analyzer\Metadata\MetadataFlags($method->flags->bits & ~(Mago\Sdk\Analyzer\Metadata\MetadataFlags::USER_DEFINED | 64)); }
                        if ($variant==='return') { $v['returnType']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata($method->location,Type::never(),false,false); }
                        if (str_starts_with($variant,'parameter-')) {
                            $p=get_object_vars($method->parameters[0]);
                            if ($variant==='parameter-name') { $p['name']='$different'; }
                            if ($variant==='parameter-file') { $p['location']=new Mago\Sdk\SourceLocation('foreign.php',$p['location']->span); }
                            if ($variant==='parameter-span') { $p['nameLocation']=new Mago\Sdk\SourceLocation($p['nameLocation']->file,new Mago\Sdk\Span($p['nameLocation']->span->start+1,$p['nameLocation']->span->end)); }
                            if ($variant==='parameter-reference') { $p['flags']=new Mago\Sdk\Analyzer\Metadata\MetadataFlags($p['flags']->bits|Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                            if ($variant==='parameter-type') { $p['type']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata($p['type']->location,Type::never(),$p['type']->fromDocblock,false); }
                            if ($variant==='parameter-declared') { $p['declaredType']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata($p['declaredType']->location,Type::never(),false,false); }
                            if ($variant==='parameter-doc') { $p['type']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata($p['type']->location,$p['type']->type,!$p['type']->fromDocblock,false); }
                            $v['parameters'][0]=new Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$p);
                        }
                        $replace($method,new Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$v));
                        if ($prove() !== null) { throw new RuntimeException('Changed source contract accepted: '.$role.' '.$variant); }
                        $checks++; $cache->values=$snapshot;
                    }
                }
                foreach(['union-type','union-doc','iterable-key','iterable-default','iterable-default-doc','iterable-default-file','iterable-default-span'] as $variant) {
                    $snapshot=$cache->values;
                    $v=get_object_vars($constructor);
                    $position=str_starts_with($variant,'union-')?1:3;
                    $parameter=$constructor->parameters[$position];
                    $p=get_object_vars($parameter);
                    if($variant==='union-type') { $p['declaredType']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->declaredType->location,Type::never(),false,false); }
                    if($variant==='union-doc') { $p['declaredType']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->declaredType->location,$parameter->declaredType->type,true,false); }
                    if($variant==='iterable-key') {
                        $type=Type::fromAtomic(new Mago\Sdk\Analyzer\Type\IterableType(Type::fromAtomic(new Mago\Sdk\Analyzer\Type\ScalarType(Mago\Sdk\Analyzer\Type\ScalarTypeKind::ArrayKey)),Type::mixed(),null));
                        $p['declaredType']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->declaredType->location,$type,false,false);
                    }
                    if($variant==='iterable-default') { $p['defaultType']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->defaultType->location,Type::list(Type::string()),false,false); }
                    if($variant==='iterable-default-doc') { $p['defaultType']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->defaultType->location,$parameter->defaultType->type,true,false); }
                    if($variant==='iterable-default-file') { $p['defaultType']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata(new Mago\Sdk\SourceLocation('foreign.php',$parameter->defaultType->location->span),$parameter->defaultType->type,false,false); }
                    if($variant==='iterable-default-span') { $p['defaultType']=new Mago\Sdk\Analyzer\Metadata\TypeMetadata(new Mago\Sdk\SourceLocation($parameter->defaultType->location->file,new Mago\Sdk\Span($parameter->defaultType->location->span->start,$parameter->defaultType->location->span->end+1)),$parameter->defaultType->type,false,false); }
                    $v['parameters'][$position]=new Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$p);
                    $replace($constructor,new Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$v));
                    if($prove()!==null) { throw new RuntimeException('Changed unrelated constructor declaration accepted: '.$variant); }
                    $checks++;$cache->values=$snapshot;
                }
                $class=$context->codebase->getClass($baseProof['class']);
                foreach (['kind','file','span','final','readonly','abstract','incomplete','builtin','classification'] as $variant) {
                    $snapshot=$cache->values; $v=get_object_vars($class);
                    if ($variant==='kind') { $v['kind']=Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface; }
                    if ($variant==='file') { $v['location']=new Mago\Sdk\SourceLocation('foreign.php',$class->location->span); }
                    if ($variant==='span') { $v['nameLocation']=new Mago\Sdk\SourceLocation($class->nameLocation->file,new Mago\Sdk\Span($class->nameLocation->span->start+1,$class->nameLocation->span->end)); }
                    if ($variant==='final') { $v['flags']=new Mago\Sdk\Analyzer\Metadata\MetadataFlags($class->flags->bits & ~Mago\Sdk\Analyzer\Metadata\MetadataFlags::FINAL); }
                    if ($variant==='readonly') { $v['flags']=new Mago\Sdk\Analyzer\Metadata\MetadataFlags($class->flags->bits ^ Mago\Sdk\Analyzer\Metadata\MetadataFlags::READONLY); }
                    if ($variant==='abstract') { $v['flags']=new Mago\Sdk\Analyzer\Metadata\MetadataFlags($class->flags->bits | Mago\Sdk\Analyzer\Metadata\MetadataFlags::ABSTRACT); }
                    if ($variant==='incomplete') { $v['unresolvedHierarchyDependencies']=['Unresolved']; }
                    if ($variant==='builtin') { $v['flags']=new Mago\Sdk\Analyzer\Metadata\MetadataFlags($class->flags->bits|Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                    if ($variant==='classification') { $v['flags']=new Mago\Sdk\Analyzer\Metadata\MetadataFlags($class->flags->bits & ~(Mago\Sdk\Analyzer\Metadata\MetadataFlags::USER_DEFINED | 64)); }
                    $replace($class,new Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$v));
                    if ($prove()!==null) { throw new RuntimeException('Changed class contract accepted: '.$variant); }
                    $checks++; $cache->values=$snapshot;
                }
                foreach ([$this->root.'/cases.php',$this->root.'/packages/contracts/declarations.php'] as $file) {
                    $original=file_get_contents($file);
                    try {
                        file_put_contents($file,str_replace($file===$this->root.'/cases.php'?'"Alpha"':'non-empty-string',$file===$this->root.'/cases.php'?'"Omega"':'truthy-string   ',$original));
                        if ($prove()!==null) { throw new RuntimeException('Changed current source accepted: '.$file); }
                        $checks++;
                    } finally { file_put_contents($file,$original); }
                }
                $file=$this->root.'/packages/contracts/declarations.php';
                $original=file_get_contents($file);
                $reserved='Reserved selected argument contract for current source validation.';
                foreach(['param','phpstan-param','psalm-param'] as $tag) {
                    $changed=str_pad('@'.$tag.' "Other" | "Else" $label',strlen($reserved));
                    if(strlen($changed)!==strlen($reserved) || substr_count($original,$reserved)!==1) { throw new RuntimeException('Invalid same-length documentation mutation.'); }
                    try {
                        file_put_contents($file,str_replace($reserved,$changed,$original));
                        if($prove()!==null) { throw new RuntimeException('Changed selected-formal spaced documentation accepted: '.$tag); }
                        $checks++;
                    } finally { file_put_contents($file,$original); }
                }
                file_put_contents($this->root.'/controls.log', $checks."\n", FILE_APPEND);
                return $decision;
            }
        });
    }
}
$mode = $argv[3];
$plugins = $mode === 'integrated' ? [new Ichinya\Laramago\Analyzer\FiniteMapArgumentPlugin($argv[2])] : [new MapTestPlugin($argv[2])];
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(identifier: 'finite-map-test', name: 'Finite map test', version: '1', analyzerPlugins: $plugins)))->run();
PHP;
file_put_contents($workspace.'/worker.php', $worker);
$command = [PHP_BINARY, $package.'/vendor/bin/mago'];
$run = static function (string $name, string $mode, int $workers = 1, bool $single = false, bool $analyzedConsumer = false) use ($workspace, $package, $command): array {
    $config = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2', 'source' => ['paths' => ['cases.php'], 'includes' => ['packages/contracts']]];
    if ($analyzedConsumer) { $config['source'] = ['paths'=>['cases.php','packages/contracts/declarations.php']]; }
    if ($mode !== 'native') { $config['extension-hosts'] = ['maps' => ['command' => $mode==='integrated'
        ? [PHP_BINARY,'-d','opcache.enable_cli=0',$package.'/bin/laramago-worker.php',$package.'/vendor/autoload.php',$workspace]
        : [PHP_BINARY,$workspace.'/worker.php',$package.'/vendor/autoload.php',$workspace,$mode], 'workers' => $workers]]; }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json', ...($single ? ['cases.php'] : [])],
        [0 => ['pipe','r'], 1 => ['file',$workspace.'/'.$name.'.json','w'], 2 => ['file',$workspace.'/'.$name.'.stderr','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]); $exit = proc_close($process); $log = file_get_contents($workspace.'/'.$name.'.stderr');
    if (!in_array($exit,[0,1],true) || preg_match('/External analyzer provider failed|rejected request|Fatal error|parsing error/i',$log)) { throw new RuntimeException('Unexpected Mago result '.$name.'; inspect '.$workspace.': '.$log); }
    return json_decode(file_get_contents($workspace.'/'.$name.'.json'),true,flags:JSON_THROW_ON_ERROR)['issues'];
};
require $package.'/vendor/autoload.php';
$scanChecks=(static function (string $root,string $contents): int {
    $index=new Ichinya\Laramago\Analyzer\StaticAnalysis\FiniteMapArgumentProofs($root);
    $version=Mago\Sdk\PHPVersion::fromParts(8,2);
    $cancel=new class implements Mago\Sdk\CancellationTokenInterface {
        public bool $cancelled=false;
        public function isCancelled():bool{return $this->cancelled;}
        public function throwIfCancelled():void{if($this->cancelled){throw new RuntimeException('Scan cancelled.');}}
        public function subscribe(Closure $callback):int{return 0;}
        public function unsubscribe(int $subscription):void{}
    };
    $file=static fn(string $path,string $bytes):Mago\Sdk\Syntax\SourceFile=>new Mago\Sdk\Syntax\SourceFile($version,$path,$bytes,[],
        (new ReflectionClass(Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(),null);
    $host=$file('cases.php',$contents);
    $scan=static function(array $files,bool $first=true,bool $last=true)use($index,$version,$cancel):void{
        $index->scan(new Mago\Sdk\Analyzer\CodebaseScanContext($version,$cancel,$files,$first,$last));
    };
    $checks=0;
    $expect=static function(string $label,bool $known)use($index,$contents,&$checks):void{
        if(($index->proofs('cases.php',$contents)!==[])!==$known){throw new RuntimeException('Wrong map scan state: '.$label);} $checks++;
    };
    $expect('unscanned',false);
    $scan([$host],first:false);$expect('missing first batch',false);
    $scan([$host]);$expect('complete',true);
    $scan([],first:false);$expect('completed generation cannot be republished',false);
    $scan([$host]);$expect('new first batch recovers completed generation',true);
    if($index->proofs('foreign.php',$contents)!==[]||$index->proofs('cases.php',$contents.' ')!==[]){throw new RuntimeException('Foreign analyzed map source accepted.');}$checks+=2;
    $scan([$host],last:false);$expect('incomplete',false);
    $scan([],first:false);$expect('completed batches',true);
    $scan([]);$expect('new empty generation',false);
    $scan([$host,$host]);$expect('duplicate normalized path',false);
    $scan([$host,$file('./cases.php',$contents)]);$expect('equivalent duplicate path',false);
    $ticks=$file('cases.php',str_replace('<?php','<?php declare(ticks=1);',$contents));
    $scan([$ticks,$host]);$expect('unsupported duplicate first',false);
    $scan([$host,$ticks]);$expect('unsupported duplicate last',false);
    $scan([$ticks],last:false);$scan([$host],first:false);$expect('unsupported duplicate across batches',false);
    $duplicate=$file('duplicate.php','<?php final class Collision {}');
    $scan([$host,$duplicate,$file('second.php','<?php final class Collision {}')]);$expect('duplicate source symbol',false);
    $scan([$host,$file('broken.php','<?php function broken( {')]);$expect('parse failure',false);
    $scan([$host,$file('oversized.php',str_repeat(' ',2_000_001))]);$expect('file byte budget',false);
    $many=[$host];$padding='<?php '.str_repeat(' ',1_999_980);
    for($number=0;$number<34;$number++){$many[]=$file('padding'.$number.'.php',$padding);}
    $scan($many);$expect('aggregate byte budget',false);unset($many,$padding);
    $scan([$host]);$expect('recovery',true);
    $scan([$host],last:false);$cancel->cancelled=true;
    try{$scan([$file('middle.php','<?php')],first:false,last:false);throw new RuntimeException('Cancelled scan completed.');}
    catch(RuntimeException $error){if($error->getMessage()!=='Scan cancelled.'){throw $error;}}
    $cancel->cancelled=false;$scan([],first:false);$expect('cancelled generation cannot be republished',false);
    $scan([$host]);$expect('cancelled generation recovery',true);
    $index->initialize(new Mago\Sdk\Analyzer\InitializationContext($version,$cancel));$expect('initialization clears',false);
    return $checks;
})($workspace,$source);
$native = $run('native','native');
$proven = $run('proven','standalone');
if (in_array('--probe',$argv,true)) { echo 'Finite map ordering probe workspace: '.$workspace."\n"; exit(0); }
$group = static function (array $issues, int $start, int $end): array {
    return array_values(array_filter($issues, static fn(array $issue): bool => ($issue['annotations'][0]['span']['file_id']['name'] ?? '') === 'cases.php'
        && $issue['annotations'][0]['span']['start']['offset'] >= $start && $issue['annotations'][0]['span']['start']['offset'] < $end));
};
$corrected = $retained = 0;
foreach ($ranges as $name => [$start,$end,$expected]) {
    $before = $group($native,$start,$end); $after = $group($proven,$start,$end);
    $targets = array_values(array_filter($before,static fn(array $issue):bool => $issue['code']==='possibly-invalid-argument'
        && str_contains($issue['message'],'LabelEnvelope::__construct') && str_contains($issue['message'],'non-empty-string')));
    if ($expected === true) {
        if (count($targets) !== 1 || $after !== array_values(array_filter($before,static fn(array $issue):bool => $issue['code']!=='possibly-invalid-argument'
            || !str_contains($issue['message'],'LabelEnvelope::__construct') || !str_contains($issue['message'],'non-empty-string')))) { throw new RuntimeException('Missing exact correction: '.$name.'; inspect '.$workspace); }
        $corrected++;
    } else {
        if ($before !== $after || $expected === false && $before === []) { throw new RuntimeException('Negative changed or vacuous: '.$name.'; inspect '.$workspace); }
        $retained += count($before);
    }
}
$controls = array_sum(array_map('intval',file($workspace.'/controls.log',FILE_IGNORE_NEW_LINES)));
if ($controls !== 73) { throw new RuntimeException('Missing exact metadata/diagnostic controls: '.$controls.'; '.$workspace); }
$order = file($workspace.'/order.log',FILE_IGNORE_NEW_LINES);
$firstAfter = array_search('after:cases.php',$order,true); $firstFilter = array_search('filter:cases.php',$order,true);
if ($firstAfter === false || $firstFilter === false || $firstAfter < $firstFilter) { throw new RuntimeException('Unexpected native file-hook/filter ordering; '.$workspace); }
$variants=[['single','standalone',1,true]];
if(in_array('--integrated',$argv,true)){$variants=[...$variants,['integrated-one','integrated',1,false],['integrated-three','integrated',3,false]];}
foreach ($variants as [$name,$mode,$workers,$single]) {
    if ($run($name,$mode,$workers,$single) !== $proven) { throw new RuntimeException('Worker or single-file mismatch: '.$name.'; '.$workspace); }
}
$analyzedNative=$run('analyzed-native','native',analyzedConsumer:true);
$analyzedProven=$run('analyzed-proven','standalone',analyzedConsumer:true);
$classificationComparable=static function(array $reports,string $expected):array {
    foreach($reports as &$report) {
        foreach($report['annotations'] as &$annotation) {
            if(($annotation['span']['file_id']['name']??'')==='packages/contracts/declarations.php') {
                if(($annotation['span']['file_id']['file_type']??null)!==$expected) {
                    throw new RuntimeException('Unexpected consumer source classification.');
                }
                unset($annotation['span']['file_id']['file_type']);
            }
        }
        unset($annotation);
    }
    unset($report);
    return $reports;
};
foreach ($ranges as $name=>[$start,$end]) {
    if ($classificationComparable($group($analyzedNative,$start,$end),'Host')!==$classificationComparable($group($native,$start,$end),'Vendored')
        || $classificationComparable($group($analyzedProven,$start,$end),'Host')!==$classificationComparable($group($proven,$start,$end),'Vendored')) {
        throw new RuntimeException('Analyzed and included consumer declarations disagree: '.$name.'; '.$workspace);
    }
}
echo 'Finite map checks passed: '.count($ranges).' source cases, '.$corrected.' exact corrections, '.$retained.' retained reports, '.$controls.' native and diagnostic controls, '.$scanChecks.' scan controls, independently observed final callback identities/input domains, single-file'.(in_array('--integrated',$argv,true)?' and one/three integrated workers':'').'.'."\n";
