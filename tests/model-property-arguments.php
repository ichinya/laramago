<?php
declare(strict_types=1);
ini_set('display_errors','stderr');ini_set('log_errors','1');
if(PHP_SAPI!=='cli'||ini_get('memory_limit')!=='512M'){throw new RuntimeException('Run the native model-property argument matrix at 512M.');}
$package=dirname(__DIR__);require $package.'/vendor/autoload.php';require $package.'/tests/fixtures/compatibility-bounded-worker-support.php';require __DIR__.'/fixtures/model-property-arguments/closed-record-identity.php';
$ordinaryChecks=require __DIR__.'/fixtures/model-property-arguments/source-grammar-checks.php';$schemaChecks=require __DIR__.'/fixtures/model-property-arguments/source-schema-checks.php';$controlSourceChecks=require __DIR__.'/fixtures/model-property-arguments/source-control-checks.php';
$workspace=sys_get_temp_dir().'/laramago primitive schema matrix '.bin2hex(random_bytes(8));mkdir($workspace);$recipe=__DIR__.'/fixtures/model-property-arguments/data';
$parentError=$workspace.'/parent-'.getmypid().'.error.log';ini_set('error_log',$parentError);$parentReserve=str_repeat(' ',65536);
register_shutdown_function(static function()use(&$parentReserve,$parentError):void{$parentReserve='';$error=error_get_last();
    if($error!==null&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true)){
        file_put_contents($parentError,json_encode(['fatal'=>true,'pid'=>getmypid(),'type'=>$error['type'],'message'=>substr($error['message'],0,4096),
            'file'=>basename($error['file']),'line'=>$error['line']],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    }
});
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($recipe,FilesystemIterator::SKIP_DOTS)) as $file){if(!$file->isFile()){continue;}
    $relative=substr($file->getPathname(),strlen($recipe)+1);if(!is_dir(dirname($workspace.'/'.$relative))){mkdir(dirname($workspace.'/'.$relative),recursive:true);}
    // The checked-in catalogue and literal mutation recipes use LF byte spans.
    // Stage that exact fixture form before Mago scans it; production bytes stay raw.
    if(strtolower($file->getExtension())==='php'){file_put_contents($workspace.'/'.$relative,str_replace("\r\n","\n",file_get_contents($file->getPathname())));}
    else{copy($file->getPathname(),$workspace.'/'.$relative);}
}
$publicInputs=[];foreach(['bin/laramago-worker.php','tests/fixtures/compatibility-bounded-worker-support.php','vendor/autoload.php','presets/laravel.toml','tests/model-property-arguments.php'] as $relative){$publicInputs[$package.'/'.$relative]=hash_file('sha256',$package.'/'.$relative);}
// Hold the actual current runtime and portable fixture bytes without retaining ASTs.
foreach([$package.'/src',__DIR__.'/fixtures/model-property-arguments'] as $directory){
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)) as $input){
        if($input->isFile()){$publicInputs[$input->getPathname()]=hash_file('sha256',$input->getPathname());}
    }
}
$primarySdk=['Analyzer/Metadata/FunctionLikeMetadata.php','Analyzer/Metadata/ParameterMetadata.php','Analyzer/Metadata/ClassConstantMetadata.php',
    'Analyzer/Metadata/ClassLikeMetadata.php','Analyzer/Codebase.php','Reporting/Level.php','Reporting/AnnotationKind.php','Analyzer/IssueFilterContext.php',
    'Analyzer/Metadata/PropertyMetadata.php','Analyzer/Metadata/TypeMetadata.php','Analyzer/Metadata/MetadataFlags.php','Analyzer/Metadata/FunctionLikeKind.php','Analyzer/Type.php','SourceLocation.php'];
foreach($primarySdk as $relative){$path=$package.'/vendor/carthage-software/mago/composer/src/Sdk/'.$relative;$publicInputs[$path]=hash_file('sha256',$path);}
$generation=static function()use($publicInputs):void{foreach($publicInputs as $path=>$hash){if($hash===false||hash_file('sha256',$path)!==$hash){throw new RuntimeException('Current worker/support generation changed.');}}};
file_put_contents($workspace.'/runner-start.json',json_encode(['discoveryOnly'=>false,'acceptancePending'=>true,'portableTestSha256'=>hash_file('sha256',__FILE__),
    'currentWorkerInputs'=>$publicInputs,'applicationBodiesExecuted'=>false,'fabricatedPositiveDTOs'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$full=compatibilityProductionWorker($package,$workspace,__DIR__.'/fixtures/model-property-arguments','MODEL_PROPERTY_ARGUMENT_CLASSES_ONLY','ModelPropertyArgumentNativePlugin','ModelPropertyArgumentPlugin');
$isolated=__DIR__.'/fixtures/model-property-arguments/isolated-worker.php';$config=['php-version'=>'8.5','source'=>['paths'=>['cases.php','schema-cases.php'],'includes'=>['app','vendor']]];
$catalogue=json_decode(file_get_contents(__DIR__.'/fixtures/model-property-arguments/case-catalogue.json'),true,flags:JSON_THROW_ON_ERROR);
$bag=static function(array $issues):array{$rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows;};
$primary=static function(array $issue):?array{foreach($issue['annotations'] as $annotation){if($annotation['kind']==='Primary'){return $annotation;}}return null;};
$targets=static function(array $issues)use($primary,$catalogue):array{
    $selected=[];foreach($catalogue['requiredGenuineTargets'] as $name=>$target){$matches=array_values(array_filter($issues,static function(array $issue)use($primary,$target):bool{
        $span=$primary($issue);return $span!==null&&$issue['level']==='Error'&&$issue['code']==='mixed-argument'&&$span['span']['file_id']['name']===$target['file']
            &&[$span['span']['start']['offset'],$span['span']['end']['offset']]===$target['span'];
    }));if(count($matches)!==1){throw new RuntimeException('One genuine target Error before controls required: '.$name);}$selected[$name]=$matches[0];}return $selected;
};
$subtract=static function(array $issues,array $selected)use($bag):array{$keys=$bag($selected);return array_values(array_filter($issues,static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$keys,true)));};
$stages=[['isolated-native',$isolated,'native',1],['isolated-observe',$isolated,'observe',1],['isolated-after',$isolated,'draft',1],['isolated-after-three',$isolated,'draft',3],
    ['cache-native',$full,'cache-native',1],['cache-controls',$full,'cache-schema',1],['full-before',$full,'native',1],['full-after',$full,'draft',1],['full-after-three',$full,'draft',3]];
$reports=[];$closed=[];$selected=[];$observations=[];
foreach($stages as [$stage,$worker,$mode,$workers]){$generation();$reports[$stage]=compatibilityNativeGate($package,$workspace,$worker,$config,$stage,$mode,$workers);$generation();
    $receipt=json_decode(file_get_contents($workspace.'/'.$stage.'-closed-receipt.json'),true,flags:JSON_THROW_ON_ERROR);$closed[$stage]=$receipt;
    if(!$receipt['closed']||!in_array($receipt['exit'],[0,1],true)||$receipt['workers']!==$workers
        ||$receipt['threads']!==(str_starts_with($mode,'cache-')?1:'default')
        ||$receipt['rawSha256']!==hash_file('sha256',$workspace.'/'.$stage.'.json.raw')
        ||$receipt['stderrSha256']!==hash_file('sha256',$workspace.'/'.$stage.'.stderr.raw')){throw new RuntimeException('Actual closed native stage receipt is not exact.');}
    $closed[$stage]+=['receiptSha256'=>hash_file('sha256',$workspace.'/'.$stage.'-closed-receipt.json'),
        'configSha256'=>hash_file('sha256',$workspace.'/'.$stage.'-config.json'),'observerSha256'=>hash_file('sha256',$workspace.'/'.$stage.'-observer.jsonl')];
    if($stage==='isolated-native'){$selected=$targets($reports[$stage]);}
    if($stage==='isolated-observe'){
        if($bag($reports[$stage])!==$bag($reports['isolated-native'])){throw new RuntimeException('AlwaysKeep changed a complete isolated native record.');}
        $rows=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/'.$stage.'-observer.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
        foreach($rows as $row){$joins=array_values(array_filter($reports['isolated-native'],static fn(array $issue):bool=>modelDiscoveryWholeJoin($row,$issue,$workspace)));
            if(count($joins)!==1||!$row['alwaysKeep']||$row['admissionAuthority']||($row['candidateDecision']==='Keep'&&$row['certificate']!==[])){throw new RuntimeException('Completed source/native event identity or nesting-safe Keep state failed.');}
        }
        foreach($selected as $name=>$raw){$matches=array_values(array_filter($rows,static fn(array $row):bool=>$row['candidateDecision']==='Remove'&&modelDiscoveryWholeJoin($row,$raw,$workspace)));
            if(count($matches)!==1||($matches[0]['certificate']['span']??null)!==$catalogue['requiredGenuineTargets'][$name]['span']){throw new RuntimeException('Genuine selected native proof refused before controls: '.$name);}$observations[$name]=$matches[0];
        }
        $admitted=array_values(array_filter($rows,static fn(array $row):bool=>$row['candidateDecision']==='Remove'));
        if(count($admitted)!==count($selected)){throw new RuntimeException('Only exact selected genuine positives may be admitted.');}
    }
    if($stage==='isolated-after-three'&&($bag($reports['isolated-after'])!==$bag($reports[$stage])||$bag($subtract($reports['isolated-native'],$selected))!==$bag($reports[$stage]))){throw new RuntimeException('Isolated one/three complete residuals differ.');}
    if($stage==='cache-native'){$targets($reports[$stage]);}
    if($stage==='cache-controls'){
        if($bag($reports[$stage])!==$bag($reports['cache-native'])){throw new RuntimeException('Actual controls changed a complete native record.');}
        $controls=json_decode(file_get_contents($workspace.'/'.$stage.'-observer.jsonl-native-controls.json'),true,flags:JSON_THROW_ON_ERROR);
        $castControls=json_decode(file_get_contents($workspace.'/'.$stage.'-observer.jsonl-cast-map-controls.json'),true,flags:JSON_THROW_ON_ERROR);
        if(!$castControls['genuinePositiveFirst']||!$castControls['allRestored']||!$castControls['nativeIssueAlwaysKept']||in_array(false,$castControls['checks'],true)
            ||$castControls['actualCacheVariants']!==6||$castControls['physicalSourceVariants']!==2){throw new RuntimeException('Exact cast-map source/cache controls incomplete.');}
        if(!$controls['genuinePositiveControls']||!$controls['allRestored']||!$controls['nativeIssueAlwaysKept']||in_array(false,$controls['checks'],true)
            ||$controls['actualDeclaringDispatchRoles']!==13||$controls['actualConstantRoles']!==2||$controls['physicalSourceControls']!==13
            ||count($controls['checks'])<163){throw new RuntimeException('Genuine positive cache/source refusal/restoration controls incomplete.');}
    }
    if($stage==='full-before'){$fullSelected=$targets($reports[$stage]);}
    if($stage==='full-after-three'&&($bag($reports['full-after'])!==$bag($reports[$stage])||$bag($subtract($reports['full-before'],$fullSelected))!==$bag($reports[$stage]))){throw new RuntimeException('Complete production registry one/three residuals differ.');}
}
foreach(['isolated-native','isolated-after','isolated-after-three','full-before','full-after','full-after-three'] as $stage){foreach($catalogue['requiredEarlierArgumentErrors'] as $span){
    $matches=array_filter($reports[$stage],static function(array $issue)use($primary,$span):bool{$annotation=$primary($issue);return $annotation!==null&&$issue['level']==='Error'&&$issue['code']==='invalid-argument'
        &&$annotation['span']['file_id']['name']==='cases.php'&&[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']]===$span;});
    if(count($matches)!==1){throw new RuntimeException('Independent earlier Error must remain genuine and unchanged.');}
}}
$caseRows=[];
foreach(['cases.php'=>$catalogue['legacyCases'],'schema-cases.php'=>$catalogue['schemaCases']] as $file=>$names){
    $nodes=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse(file_get_contents($workspace.'/'.$file));
    $methods=(new \PhpParser\NodeFinder)->findInstanceOf($nodes,\PhpParser\Node\Stmt\ClassMethod::class);
    foreach($names as $name){$scope=array_values(array_filter($methods,static fn($method):bool=>$method->name->name===$name));
        if(count($scope)!==1){throw new RuntimeException('Every source case must remain physically unique.');}$scope=$scope[0];
        $issues=array_values(array_filter($reports['isolated-native'],static function(array $issue)use($primary,$file,$scope):bool{
            $annotation=$primary($issue);return $annotation!==null&&$annotation['span']['file_id']['name']===$file
                &&$annotation['span']['start']['offset']>=$scope->getStartFilePos()&&$annotation['span']['end']['offset']<=$scope->getEndFilePos()+1;
        }));
        $caseRows[$file.'::'.$name]=['completeNativeRecords'=>count($issues),'nonemptyNegativeDiagnostic'=>!isset($selected[$name])&&$issues!==[],
            'nativeCoveredWithoutDiagnostic'=>$issues===[],'wholeResidualRecordsHeld'=>true];
    }
}
if(count($caseRows)!==23){throw new RuntimeException('Twenty-three physical source cases required.');}
foreach($closed as $stage=>$receipt){foreach(['.json.raw'=>'rawSha256','.stderr.raw'=>'stderrSha256','-closed-receipt.json'=>'receiptSha256','-config.json'=>'configSha256','-observer.jsonl'=>'observerSha256'] as $suffix=>$key){
    if(hash_file('sha256',$workspace.'/'.$stage.$suffix)!==$receipt[$key]){throw new RuntimeException('A completed raw report or observation changed after its native stage.');}
}}
$generation();$result=['focusedNativeMatrixPassed'=>true,'sourceCases'=>$caseRows,'genuineIsolatedErrors'=>count($selected),'genuineFullErrors'=>count($fullSelected),
    'allResidualWholeIssuesHeld'=>true,'oneThreeDefaultEngineParity'=>true,'cacheSourceControlChecks'=>count($controls['checks']),'nativeControls'=>$controls,'castMapControls'=>$castControls,
    'closedStages'=>$closed,'ordinarySourceChecks'=>$ordinaryChecks,'schemaSourceChecks'=>$schemaChecks,'controlSourceChecks'=>$controlSourceChecks,'observedGenuineTargets'=>$observations,
    'publicAcceptance'=>false,'originalConsumerRemovalClaimed'=>false,'applicationBodiesExecuted'=>false,'fabricatedPositiveDTOs'=>false,'workspace'=>$workspace];
file_put_contents($workspace.'/matrix-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo json_encode(['focusedMatrixPassed'=>true,'workspace'=>$workspace,'nativeJobs'=>count($stages),'resultSha256'=>hash_file('sha256',$workspace.'/matrix-result.json')],JSON_THROW_ON_ERROR),"\n";

