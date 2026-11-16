<?php
declare(strict_types=1);
namespace Example\ModelFreshnessTests;

use Ichinya\Laramago\Analyzer\RefreshedModelPropertyIssueFilter;
use Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof;
use Ichinya\Laramago\Analyzer\StaticAnalysis\{ModelAttributeReadDomains,PhpSource,RefreshedModelProperties};
use Mago\Sdk\Analyzer\{AfterFileAnalysisContext,AfterFileAnalysisHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook};

/** This observer uses one unchanged genuine issue context and returns Keep. */
final class NativeFreshnessControls implements IssueFilterHook,AfterFileAnalysisHook
{
    private bool $checked=false;
    public function __construct(private readonly RefreshedModelPropertyIssueFilter $filter,private readonly string $root,private readonly string $targetFile='proof.php') {}
    public function getCodes():array { return $this->filter->getCodes(); }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        if($this->checked||!in_array(self::key($context->file),[self::key($this->targetFile),self::key($this->root.'/'.$this->targetFile)],true)) { return IssueFilterDecision::Keep; }
        $this->checked=true;
        $this->check($context,fn():bool=>$this->filter->filterIssue($context)===IssueFilterDecision::Remove,$context->issue);
        return IssueFilterDecision::Keep;
    }
    public function getRequirements():array { return []; }
    public function afterFileAnalysis(AfterFileAnalysisContext $context):void
    {
        if($this->checked||!in_array(self::key($context->analysis->file),[self::key($this->targetFile),self::key($this->root.'/'.$this->targetFile)],true)){return;}
        $source=$context->analysis->getSourceFile();$index=$this->filter->provenance;
        $proofs=array_values(array_filter($index->proofs($source->path,$source->contents),fn(array $proof):bool=>$index->valid($context->codebase,$context->types,$proof)));
        if(count($proofs)!==1){throw new \RuntimeException('One genuine source/native refresh contract is required after file analysis.');}
        $origin=(new \ReflectionMethod($index,'originClass'))->invoke($index,$context->codebase,$proofs[0]);
        if(!is_string($origin)||$origin===''){throw new \RuntimeException('A genuine current native model origin is required.');}
        $this->checked=true;
        $this->check($context,fn():bool=>$index->valid($context->codebase,$context->types,$proofs[0]),null,$origin);
    }
    private function check(IssueFilterContext|AfterFileAnalysisContext $context,\Closure $admitted,?\Mago\Sdk\Reporting\ReportedIssue $issue,string $modelClass='Fixture\\Row'):void
    {
        $checks=[];
        $check=static function(string $label,bool $condition)use(&$checks):void {
            $checks[$label]=$condition;if(!$condition) { throw new \RuntimeException('Model source freshness control failed: '.$label); }
        };
        try {
            $check('genuine current refresh positive',$admitted());
            $index=$this->filter->provenance;
            $domains=(new \ReflectionProperty($index,'domains'))->getValue($index);
            $check('real retained model domain reader',$domains instanceof ModelAttributeReadDomains);
            $reader=(new \ReflectionProperty($domains,'source'))->getValue($domains);
            $check('current two-entry source reader',$reader instanceof PhpSource&&(new \ReflectionClass($reader))->getConstant('INSTANCE_CACHE_ENTRIES')===2);
            $model=$context->codebase->getClass($modelClass);
            $check('selected native model location',$model!==null&&strcasecmp($model->name,$modelClass)===0&&$model->location->file!==null);
            $disk=$reader->path(RefreshedModelProperties::path($model->location->file));
            $original=file_get_contents($disk);$originalHash=hash('sha256',$original);
            $snapshots=(new \ReflectionProperty($domains,'snapshots'))->getValue($domains);
            $check('selected dependency has an earlier physical snapshot',($snapshots[$disk]??null)===$originalHash);
            $scanned=(new \ReflectionProperty($domains,'analyzedHashes'))->getValue($domains);
            foreach(array_keys($scanned) as $file) {
                if(self::key($reader->path(RefreshedModelProperties::path($file)))===self::key($disk)) {
                    throw new \RuntimeException('The selected native dependency is scanned; this control must exercise the unscanned path.');
                }
            }
            $check('selected known dependency is outside the analyzed source scan',true);
            $bindings=[];
            foreach(['Illuminate\\Database\\Eloquent\\Model','Illuminate\\Database\\Eloquent\\Concerns\\HasAttributes'] as $name) {
                $native=$context->codebase->getClassLike($name);
                if($native===null||strcasecmp($native->name,$name)!==0||$native->location->file===null) {
                    throw new \RuntimeException('An actual selected native declaration is unavailable for eviction.');
                }
                $path=$reader->path(RefreshedModelProperties::path($native->location->file));
                if(self::key($path)===self::key($disk)||isset($bindings[self::key($path)])||$reader->read($native->location->file)===null) {
                    throw new \RuntimeException('The actual source eviction declarations are not two distinct readable native files.');
                }
                $bindings[self::key($path)]=['name'=>$native->name,'file'=>$native->location->file,'sourceSha256'=>hash_file('sha256',$path)];
            }
            $cache=(new \ReflectionProperty($reader,'files'))->getValue($reader);
            $check('actual two native reads evicted the selected AST',count($cache)===2&&!in_array(self::key($disk),array_map(self::key(...),array_keys($cache)),true));
            // Keep one real context; never replace its issue, contents, or native metadata.
            $check('same genuine positive before the source mutation',$admitted());
            // The positive may read the selected file again. Force the same observed eviction once more.
            foreach($bindings as $binding) { if($reader->read($binding['file'])===null) { throw new \RuntimeException('The actual eviction read failed.'); } }
            $cache=(new \ReflectionProperty($reader,'files'))->getValue($reader);
            $check('selected AST absent immediately before mutation',!in_array(self::key($disk),array_map(self::key(...),array_keys($cache)),true));
            file_put_contents($this->root.'/freshness-initial.json',json_encode([
                'completeGenuineSdkIssue'=>$issue===null?null:DeprecatedMethodCompatibilityProof::canonical($issue),
                'actualSdkContextClass'=>$context::class,'genuineIssuePositive'=>$issue!==null,'genuineSourceNativePositive'=>true,
                'selectedFile'=>$model->location->file,'selectedSourceSha256'=>$originalHash,'unscanned'=>true,
                'actualEvictionBindings'=>array_values($bindings),'actualReaderCachePaths'=>array_keys($cache),
            ],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
            $changed=$original."\n// Changed an already certified unscanned model dependency.\n";
            $check('meaningful physical byte mutation',$changed!==$original&&hash('sha256',$changed)!==$originalHash);
            try {
                if(file_put_contents($disk,$changed)!==strlen($changed)||hash_file('sha256',$disk)!==hash('sha256',$changed)) {
                    throw new \RuntimeException('The actual selected dependency bytes were not changed.');
                }
                $check('changed known unscanned dependency is rejected',!$admitted());
            } finally {
                if(file_put_contents($disk,$original)!==strlen($original)||hash_file('sha256',$disk)!==$originalHash) {
                    throw new \RuntimeException('The selected model dependency was not fully restored.');
                }
            }
            $check('fresh genuine positive after exact restoration',$admitted());
            $snapshots=(new \ReflectionProperty($domains,'snapshots'))->getValue($domains);
            $check('original selected source certificate remains retained',($snapshots[$disk]??null)===$originalHash);
            file_put_contents($this->root.'/freshness-controls.json',json_encode([
                'status'=>'PASS','genuinePositive'=>true,'genuineIssuePositive'=>$issue!==null,'genuineSourceNativePositive'=>true,'sameGenuineContext'=>true,'checks'=>$checks,
                'sourceMutations'=>1,'restored'=>true,'sdkCacheMutations'=>0,'fabricatedPositiveContexts'=>false,
            ],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        } catch(\Throwable $error) {
            file_put_contents($this->root.'/freshness-first-failure.json',json_encode([
                'status'=>'FAILED','message'=>$error->getMessage(),'completedChecks'=>$checks,
            ],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));throw $error;
        }
    }
    private static function key(string $path):string
    {
        $path=str_replace('\\','/',$path);return PHP_OS_FAMILY==='Windows'?strtolower($path):$path;
    }
}
