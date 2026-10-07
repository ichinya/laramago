<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Tests\Support;

use Ichinya\Laramago\Analyzer\ArgvNormalizationAdvisoryIssueFilter;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ArgvNormalizationAdvisories;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Reporting\{Level,ReportedIssue};
use PhpParser\Node;

/** Controls begin with the unchanged genuine native context; no positive context/cache is synthesized. */
final class ArgvNormalizationControlObserver implements Plugin, IssueFilterHook
{
    private readonly ArgvNormalizationAdvisories $state;
    private readonly ArgvNormalizationAdvisoryIssueFilter $filter;
    public function __construct(private readonly string $output,private readonly bool $observeOnly=false)
    {
        $this->state=new ArgvNormalizationAdvisories();$this->filter=new ArgvNormalizationAdvisoryIssueFilter($this->state);
    }
    public function getDefinition():PluginDefinition{return new PluginDefinition('fixture/argv-controls','Argv normalization controls','Genuine source lifecycle, native predicate and whole issue envelope controls');}
    public function register(PluginRegistry $registry):void
    {
        $registry->registerInitializationHook($this->state);$registry->registerCodebaseScanHook($this->state);$registry->registerIssueFilterHook($this);
    }
    public function getCodes():array{return ['redundant-condition'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $facts=$this->state->facts($context);$decision=$this->observeOnly?IssueFilterDecision::Keep:$this->filter->filterIssue($context);
        $controls=!$this->observeOnly&&$facts['arrayAdvisoryEligible']?$this->controls($context,$facts):null;
        file_put_contents($this->output.'/issues.jsonl',json_encode(['facts'=>$facts,'actualNativeContext'=>true,'nativeTypesChanged'=>false,
            'nativeContextOrCacheMutated'=>false,'decision'=>$decision->name,'controls'=>$controls],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return$decision;
    }
    private function controls(IssueFilterContext $context,array $facts):array
    {
        $controls=\Closure::bind(function(IssueFilterContext $context,array $facts):array {
        $checks = 0;
        foreach (['started', 'complete', 'failed', 'files', 'sha256', 'proofs'] as $gate) {
            $clone = clone $this;
            if ($gate === 'started') { $clone->started = false; }
            elseif ($gate === 'complete') { $clone->complete = false; }
            elseif ($gate === 'failed') { $clone->failed = true; }
            elseif ($gate === 'files') { $clone->files = []; }
            elseif ($gate === 'sha256') { $clone->files[$context->file]['sha256'] = str_repeat('0', 64); }
            else { $clone->files[$context->file]['proofs'] = []; }
            if ($clone->facts($context)['arrayAdvisoryEligible']) { throw new \RuntimeException('Source lifecycle negative accepted: '.$gate); }
            $checks++;
        }
        foreach (['array-span', 'array-import', 'string-import'] as $gate) {
            $clone = clone $this;
            foreach ($clone->files[$context->file]['proofs'] as &$proof) {
                $target = $gate === 'string-import' ? 'stringCall' : 'arrayCall';
                $proof[$target] = clone $proof[$target];
                if ($gate === 'array-span') {
                    $proof[$target]->setAttribute('startFilePos', $proof[$target]->getStartFilePos() + 1);
                    $proof[$target]->setAttribute('endFilePos', $proof[$target]->getEndFilePos() + 1);
                } else { $proof[$target]->name = new Node\Name\FullyQualified('is_int'); }
            }
            unset($proof);
            if ($clone->facts($context)['arrayAdvisoryEligible']) { throw new \RuntimeException('Source span/import negative accepted: '.$gate); }
            $checks++;
        }
        $base = $context->issue;
        foreach (['level','code','message','notes','help','link','annotations','edits'] as $gate) {
            $values = ['level'=>$base->level,'code'=>$base->code,'message'=>$base->message,'notes'=>$base->notes,'help'=>$base->help,'link'=>$base->link,'annotations'=>$base->annotations,'edits'=>$base->edits];
            $values[$gate] = match ($gate) { 'level' => Level::Error, 'code' => 'independent-warning', 'message' => 'Independent message', 'notes' => array_reverse($base->notes), 'help' => null, 'link' => 'https://example.invalid/', 'annotations' => [], 'edits' => [null] };
            $negative = new ReportedIssue(...$values);
            if (self::envelope($negative)) { throw new \RuntimeException('Envelope negative accepted: '.$gate); }
            $checks++;
        }
        foreach (['is_array', 'is_string'] as $builtin) {
            foreach (['kind','identifierKind','identifierClass','identifierName','builtin','userDefined','parameterCount','parameterByReference','parameterVariadic','parameterDefault','parameterOut','parameterClosureThis','parameterTypeMixed','parameterPhysicalMixed','returnCompatible','physicalReturnBool','templates','globals','methodStatic'] as $gate) {
                $negative = $facts['predicateContracts'][$builtin];
                $negative[$gate] = match ($gate) { 'kind','identifierKind','identifierName' => 'Shadow', 'identifierClass' => 'ShadowClass', 'parameterCount' => 2, 'templates','globals' => ['unexpected'], 'parameterDefault','parameterOut','parameterClosureThis' => 'unknown', default => ! $negative[$gate] };
                if (self::contract($negative, $builtin)) { throw new \RuntimeException('Native-contract snapshot negative accepted: '.$gate); }
                $checks++;
            }
        }
        $conditionalChecks=0;$returnVariants=[];
        foreach(['is_array','is_string']as$builtin){
            $native=$context->codebase->getFunction($facts['predicateContracts'][$builtin]['resolvedName']);
            $type=$native?->returnType?->type;
            if($type===null){throw new \RuntimeException('Actual native return disappeared.');}
            if($context->types->equals($type,Type::bool())){$returnVariants[$builtin]='native-bool';continue;}
            $conditional=count($type->atomicTypes)===1?$type->atomicTypes[0]:null;
            if(!$conditional instanceof \Mago\Sdk\Analyzer\Type\ConditionalType){throw new \RuntimeException('Actual supported native return required.');}
            $branches=['thenTrue'=>$context->types->equals($conditional->then,Type::true()),
                'otherwiseFalse'=>$context->types->equals($conditional->otherwise,Type::false()),'nonnegated'=>!$conditional->negated];
            if(!self::conditionalBranches($branches)){throw new \RuntimeException('Genuine semantic branch comparisons required.');}
            $scalar=static fn(Type $branch):array=>array_map(static fn($atom):array=>['kind'=>$atom instanceof \Mago\Sdk\Analyzer\Type\ScalarType?$atom->kind->name:$atom::class,
                'refinement'=>$atom instanceof \Mago\Sdk\Analyzer\Type\ScalarType&&is_bool($atom->refinement)?$atom->refinement:null],$branch->atomicTypes);
            $returnVariants[$builtin]=['variant'=>'native-conditional','then'=>$scalar($conditional->then),'otherwise'=>$scalar($conditional->otherwise),'nativeComparisonGuards'=>$branches];
            foreach(array_keys($branches)as$gate){$negative=$branches;$negative[$gate]=false;
                if(self::conditionalBranches($negative)){throw new \RuntimeException('Conditional branch negative accepted: '.$gate);} $checks++;$conditionalChecks++;
            }
        }
        return ['actualEligibleNativeContext' => true, 'checks' => $checks, 'conditionalBranchChecks'=>$conditionalChecks, 'actualReturnVariants'=>$returnVariants, 'nativeContextOrCacheMutated' => false,
            'negativeOnlySnapshots' => true, 'positiveAuthority' => 'unchanged genuine native context'];
            },$this->state,ArgvNormalizationAdvisories::class);
        if($controls===null){throw new \RuntimeException('Native source controls could not bind the production certificate.');}
        return$controls($context,$facts);
    }
}
