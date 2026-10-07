<?php
declare(strict_types=1);
use Example\ProducerGuards\{BoundaryGuardPlugin,ControlledCache};
use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardProof as BoundaryGuardProof;
use Mago\Sdk\Analyzer\{IssueFilterContext,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\{Extension,Worker};
if (!defined('BOUNDARY_GUARD_CLASSES_ONLY')) { $package=$argv[1]; $root=$argv[2]; $mode=$argv[3]; $output=$argv[4]; require $package.'/vendor/autoload.php'; }

require __DIR__.'/ControlledCache.php';require __DIR__.'/observer-plugin.php';
final class BoundaryGuardDraftPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('example/boundary-guard-draft','Boundary guard draft','Current native observers and meaningful controls for a source-bound reporting policy.'); }
    public function register(PluginRegistry $registry): void
    {
        $mode=$this->mode; $output=$this->output;
        $control = str_starts_with($mode,'cache-') ? static function (IssueFilterContext $context,BoundaryGuardProof $proof,array $before) use ($mode,$output): void {
            if (!$before['remove']) { return; }
            if ($mode === 'cache-predicate-domain') {
                $symbol=$before['controlSymbol'] ?? null; if ($symbol===null) { throw new RuntimeException('No genuine predicate selected for this admitted warning.'); }
                $native=$context->codebase->getFunction($symbol['name']); if ($native===null || $native->parameters[0]->type===null || $native->parameters[0]->declaredType===null) { throw new RuntimeException('Genuine predicate parameter disappeared.'); }
                $parameters=$native->parameters; $parameters[0]=ControlledCache::copy($parameters[0],['type'=>ControlledCache::copy($parameters[0]->type,['type'=>Type::int()]),'declaredType'=>ControlledCache::copy($parameters[0]->declaredType,['type'=>Type::int()])]);
                $replacement=ControlledCache::copy($native,['parameters'=>$parameters]);
            } elseif ($mode === 'cache-producer-contract') {
                $selected=null;
                foreach ($before['nativeProducers'] as $producer) { if (isset($producer['native']['symbol'])) { $selected=$producer['native']['symbol']; break; } if (($producer['kind'] ?? null)==='request-pseudo') { $selected='Illuminate\\Http\\Request'; break; } }
                if ($selected===null) { return; } // argv has no invented SDK global metadata object.
                if ($selected==='Illuminate\\Http\\Request') {
                    $native=$context->codebase->getClass($selected); if ($native===null) { throw new RuntimeException('Genuine Request class disappeared.'); }
                    $replacement=ControlledCache::copy($native,['pseudoMethods'=>array_values(array_filter($native->pseudoMethods,static fn(string $name):bool=>strtolower($name)!=='validate'))]);
                    if ($replacement->pseudoMethods===$native->pseudoMethods) { throw new RuntimeException('Request pseudomethod control was a no-op.'); }
                } else {
                    $parts=explode('::',$selected); $native=count($parts)===2 ? $context->codebase->getDeclaringMethod($parts[0],$parts[1]) : $context->codebase->getFunction($selected);
                    if ($native===null || $native->returnType===null) { throw new RuntimeException('No genuine producer return to change.'); }
                    $changed=['returnType'=>ControlledCache::copy($native->returnType,['type'=>Type::int()])];
                    if($native->declaredReturnType!==null){$changed['declaredReturnType']=ControlledCache::copy($native->declaredReturnType,['type'=>Type::int()]);}
                    $replacement=ControlledCache::copy($native,$changed);
                }
                $symbol=['kind'=>'producer','name'=>$selected];
            } else { throw new RuntimeException('Unknown cache control.'); }
            $slots=ControlledCache::replace($context->codebase,$native,$replacement);
            try { $after=$proof->prove($context); } finally { ControlledCache::restore($context->codebase,$native,$slots); }
            if ($after['remove']) { throw new RuntimeException('Changed current native contract was still admitted.'); }
            $restored=$proof->prove($context);
            if (!$restored['remove'] || $restored!==$before) { throw new RuntimeException('The complete admitted proof was not restored.'); }
            file_put_contents($output,json_encode(['stage'=>'real-native-cache-control','role'=>$mode,'symbol'=>$symbol,'file'=>$context->file,
                'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],'genuinePositiveBefore'=>true,
                'slotsChanged'=>count($slots),'deferredAfter'=>true,'completeProofRestored'=>true],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        } : null;
        (new BoundaryGuardPlugin($this->root,$mode==='preserve',$mode!=='draft',$output,$control))->register($registry);
    }
}
if (!defined('BOUNDARY_GUARD_CLASSES_ONLY')) {
    (new Worker(new Extension(identifier:'example/boundary-guard-draft',name:'Boundary guard draft',version:'0.0.1',analyzerPlugins:[
        new \Ichinya\Laramago\Analyzer\LaravelPlugin($root),new \Ichinya\Laramago\Analyzer\ValidatedArrayContractPlugin($root),new BoundaryGuardDraftPlugin($root,$mode,$output),
    ])))->run();
}
