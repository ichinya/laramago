<?php
declare(strict_types=1);
// Analyze invented declarations only. Do not include a fixture or application body.
$argument=static function(string $key)use($argv):?string{foreach($argv as $value){if(str_starts_with($value,$key.'=')){return substr($value,strlen($key)+1);}}return null;};
$package=str_replace('\\','/',$argument('--package-root')??dirname(__DIR__));
$workspace=str_replace('\\','/',$argument('--workspace')??sys_get_temp_dir().'/laramago guarded local model discovery '.bin2hex(random_bytes(8)));
$data=__DIR__.'/data/guarded-local-model-tuples';
$fixture=$data.'/fixtures';
$controls=str_replace('\\','/',$argument('--controls-root')??$data);
require $package.'/vendor/autoload.php';
require $data.'/closed-record-identity.php';
if(file_exists($workspace)){throw new RuntimeException('A fresh workspace is required; preserve previous evidence.');}
mkdir($workspace,0777,true);
$write=static function(string $path,string $contents):void{if(!is_dir(dirname($path))){mkdir(dirname($path),0777,true);}if(file_put_contents($path,$contents)===false){throw new RuntimeException('Cannot preserve discovery input.');}};
$catalogue=json_decode(file_get_contents($fixture.'/cases.json'),true,flags:JSON_THROW_ON_ERROR);
$held=[];$scopes=[];$positiveSites=[];$sourcePaths=[];
$parser=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion();$finder=new \PhpParser\NodeFinder;
$fixtureBytes=static function(string $bytes,string $hash):string {
    $lf=str_replace("\r\n","\n",$bytes);$recipes=[$bytes,$lf,str_replace("\n","\r\n",$lf)];$offset=0;
    // Captured declaration PHPDoc used CRLF within otherwise LF fixture recipes.
    foreach(token_get_all($lf) as $token){
        $text=is_array($token)?$token[1]:$token;
        if(is_array($token)&&$token[0]===T_DOC_COMMENT&&str_contains($text,"\n")){
            $recipes[]=substr_replace($lf,str_replace("\n","\r\n",$text),$offset,strlen($text));break;
        }
        $offset+=strlen($text);
    }
    foreach($recipes as $recipe){if(hash('sha256',$recipe)===$hash){return $recipe;}}
    throw new RuntimeException('Frozen invented source changed.');
};
foreach($catalogue['sources'] as $file=>$hash){
    $input=$fixture.'/source/'.$file.'.stub';$contents=$fixtureBytes(file_get_contents($input),$hash);
    if(hash('sha256',$contents)!==$hash){throw new RuntimeException('Frozen invented source changed.');}
    $write($workspace.'/'.$file,$contents);$held[$input]=hash_file('sha256',$input);$held[$workspace.'/'.$file]=$hash;$sourcePaths[]=$file;
    $nodes=$parser->parse($contents)??[];
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Stmt\Class_::class) as $class){foreach($class->getMethods() as $owner){
        $name=$file.'::'.$class->name->name.'::'.$owner->name->name;
        $scopes[$file][$class->name->name.'::'.$owner->name->name]=[$owner->getStartFilePos(),$owner->getEndFilePos()+1];
        if(!in_array($name,$catalogue['positiveOwners'],true)){continue;}
        $calls=$finder->find($owner->stmts??[],static fn(\PhpParser\Node $node):bool=>$node instanceof \PhpParser\Node\Expr\MethodCall
            &&$node->name instanceof \PhpParser\Node\Identifier&&$node->name->name==='acceptInvoice');
        if(count($calls)!==1||!isset($calls[0]->args[0])||!$calls[0]->args[0]->value instanceof \PhpParser\Node\Expr\Variable){throw new RuntimeException('One exact positive receiving source site is required.');}
        $argument=$calls[0]->args[0]->value;$positiveSites[$name]=[$argument->getStartFilePos(),$argument->getEndFilePos()+1];
    }}
    unset($nodes,$class,$owner,$calls,$argument);
}
$write($workspace.'/bootstrap/app.php','<?php file_put_contents(dirname(__DIR__)."/bootstrap-executed","unexpected"); throw new RuntimeException("Fixture bootstrap must never execute.");');
$write($workspace.'/dependency tree/autoload.php','<?php file_put_contents(dirname(__DIR__)."/autoload-executed","unexpected"); throw new RuntimeException("Fixture autoload must never execute.");');
$write($workspace.'/composer.json',json_encode(['config'=>['vendor-dir'=>'dependency tree'],'autoload'=>['files'=>['bootstrap/app.php']]],JSON_THROW_ON_ERROR));
foreach(['bootstrap/app.php','dependency tree/autoload.php','composer.json'] as $file){$held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file);}
$runtime=['StandardEloquentFirstDeclarationContracts','LiteralGuardedTupleModelProjection','GuardedLocalQueryModelContract',
    'GuardedLocalModelTupleArgumentFilter','GuardedLocalModelTuplePlugin'];
$registry=file_get_contents($package.'/bin/laramago-worker.php');
$anchor='        new LaravelPlugin($projectRoot),';
$registration='        new \Ichinya\Laramago\Analyzer\GuardedLocalModelTuplePlugin($projectRoot),';
$newline=str_contains($registry,"\r\n")?"\r\n":"\n";
$registrationCount=substr_count($registry,$registration);
if(substr_count($registry,$anchor)!==1||$registrationCount>1
    ||substr_count($registry,'GuardedLocalModelTuplePlugin')!==$registrationCount){
    throw new RuntimeException('Current registry registration is not unique.');
}
if($registrationCount===1){
    if(!str_contains($registry,$anchor.$newline.$registration)){
        throw new RuntimeException('The selected public registration must follow Laravel.');
    }
    $after=$registry;
    $before=str_replace($newline.$registration,'',$registry);
}else{
    $before=$registry;
    $after=str_replace($anchor,$anchor.$newline.$registration,$registry);
}
if(str_replace($newline.$registration,'',$after)!==$before
    ||($registrationCount===1&&$after!==$registry)||($registrationCount===0&&$before!==$registry)){
    throw new RuntimeException('Full registry inverse identity failed.');
}
$write($workspace.'/full-before-worker.php',$before);$write($workspace.'/full-after-worker.php',$after);
$write($workspace.'/registry-inverse.json',json_encode(['registrationOnlyDifference'=>true,'publicWorkerSha256'=>hash('sha256',$registry),
    'beforeSha256'=>hash('sha256',$before),'afterSha256'=>hash('sha256',$after),'candidateAfterLaravel'=>true,'publicPluginAlreadyRegistered'=>$registrationCount===1,'registeredFullAfterIsActualPublicWorker'=>$registrationCount===1],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
foreach(['full-before-worker.php','full-after-worker.php','registry-inverse.json'] as $name){$held[$workspace.'/'.$name]=hash_file('sha256',$workspace.'/'.$name);}
foreach($runtime as $class){$path=$package.'/src/Analyzer/'.$class.'.php';
    if(!is_file($path)){throw new RuntimeException('A selected public runtime input is absent: '.$path);}$held[$path]=hash_file('sha256',$path);}
foreach(['GuardedStringCastContracts','SourceArgumentDeclarationContracts','EloquentModelDispatch','EloquentCollectionType','PossibleCallbackTupleContracts'] as $class){
    $path=$package.'/src/Analyzer/'.$class.'.php';$held[$path]=hash_file('sha256',$path);
}
foreach([__FILE__,$data.'/LocalModelObservation.php',$data.'/RouteDeclarationObservation.php',$data.'/matrix-worker.php',
    $controls.'/NativeHarness.php',$controls.'/SourceControls.php',$data.'/closed-record-identity.php',
    $fixture.'/cases.json',$package.'/vendor/autoload.php',$package.'/bin/laramago-worker.php',$package.'/presets/laravel.toml'] as $path){
    if(!is_file($path)){throw new RuntimeException('The frozen matrix input is absent: '.$path);}$held[$path]=hash_file('sha256',$path);}
foreach(['IssueFilterContext.php','Codebase.php','Type.php','Metadata/ClassLikeMetadata.php','Metadata/FunctionLikeMetadata.php','Metadata/PropertyMetadata.php','Metadata/TemplateMetadata.php','Type/NamedObjectType.php','Type/GenericParameterType.php','Type/GenericParent.php','Type/TypeFlags.php'] as $file){
    $path=$package.'/vendor/carthage-software/mago/composer/src/Sdk/Analyzer/'.$file;
    if(!is_file($path)){throw new RuntimeException('Installed SDK primary source is absent.');}$held[$path]=hash_file('sha256',$path);
}
$metadataCodec=$package.'/vendor/carthage-software/mago/composer/src/Sdk/Internal/Analyzer/MetadataCodec.php';
if(!is_file($metadataCodec)){throw new RuntimeException('Installed template metadata decoder is absent.');}
$held[$metadataCodec]=hash_file('sha256',$metadataCodec);
$typeCodec=$package.'/vendor/carthage-software/mago/composer/src/Sdk/Internal/Analyzer/TypeCodec.php';
if(!is_file($typeCodec)){throw new RuntimeException('Installed named object wire decoder is absent.');}$held[$typeCodec]=hash_file('sha256',$typeCodec);
$write($workspace.'/prepared.json',json_encode(['sourceOnly'=>true,'analyzerJobsStarted'=>0,'stages'=>['native','observe','isolated','isolated-three','controls','full-before','full-before-three','full-after','full-after-three'],
    'nativeCandidateEvaluation'=>true,'returnTypeProvidersRegistered'=>0,'fakeProviderContexts'=>0,'issuesAlwaysKept'=>true,
    'sourcePaths'=>$sourcePaths,'heldHashes'=>$held,'publicAcceptance'=>false,'originalConsumerRemovalClaimed'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if(in_array('--prepare-only',$argv,true)){echo 'Prepared guarded local model matrix; no native job started.'.PHP_EOL;exit;}
$inventory=static function()use($package):array{
    $process=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)){throw new RuntimeException('Cannot capture current package inventory.');}
    $bytes=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$error!==''){throw new RuntimeException('Current package inventory failed.');}
    $hashes=[];foreach(array_unique(array_filter(explode("\0",$bytes))) as $file){$hashes[$file]=hash_file('sha256',$package.'/'.$file);}ksort($hashes);return $hashes;
};
$publicBefore=$inventory();$evidence=[];
$assertHeld=static function()use($held,$inventory,$publicBefore,$workspace,&$evidence):void{
    if($inventory()!==$publicBefore){throw new RuntimeException('Current public bytes or inventory changed.');}
    foreach($held as $path=>$hash){if(hash_file('sha256',$path)!==$hash){throw new RuntimeException('Held discovery input changed.');}}
    foreach($evidence as $path=>$hash){if(hash_file('sha256',$path)!==$hash){throw new RuntimeException('Closed raw discovery evidence changed.');}}
    foreach(['bootstrap-executed','autoload-executed','.env'] as $trap){if(file_exists($workspace.'/'.$trap)){throw new RuntimeException('A forbidden fixture/application side effect appeared.');}}
};
$identity=static function(array $issues):array{$values=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($values);return $values;};
$primary=static function(array $issue):array{$values=array_values(array_filter($issue['annotations'],static fn(array $annotation):bool=>$annotation['kind']==='Primary'));if(count($values)!==1){throw new RuntimeException('A selected genuine issue needs exactly one Primary annotation.');}return $values[0];};
$relativeSource=static function(array $annotation)use($workspace,$sourcePaths):string{
    $actual=modelDiscoveryAbsolute($annotation['span']['file_id']['path']??$annotation['span']['file_id']['name'],$workspace);
    foreach($sourcePaths as $file){if($actual===modelDiscoveryAbsolute($file,$workspace)){return $file;}}
    return '<foreign-source>';
};
$selected=static function(array $issues)use($positiveSites,$primary,$relativeSource,$catalogue):array{
    $result=[];$owners=[];
    foreach($issues as $issue){
        if($issue['level']!=='Error'||!in_array($issue['code'],['mixed-argument'],true)){continue;}
        $annotation=$primary($issue);$file=$relativeSource($annotation);
        foreach($positiveSites as $name=>$span){
            if(str_starts_with($name,$file.'::')&&[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']]===$span){$result[]=$issue;$owners[]=$name;}
        }
    }
    sort($owners);$expected=$catalogue['positiveOwners'];sort($expected);
    if($owners!==$expected){throw new RuntimeException('The two genuine captured-tuple mixed-argument witnesses are absent or ambiguous.');}
    return $result;
};
$binary=$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');if(!is_file($binary)){$binary=$package.'/vendor/bin/mago';}
$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];$reports=[];$closed=[];
$runStage=static function(string $mode)use($workspace,$package,$controls,$data,$sourcePaths,$command,$write,$selected,$assertHeld,&$closed,&$evidence,&$reports):void{
    $output=$workspace.'/'.$mode;mkdir($output,0777,true);
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','source'=>['paths'=>$sourcePaths],
        'extension-hosts'=>['guarded-local-model-discovery'=>['command'=>[PHP_BINARY,'-d','memory_limit=512M','-d','opcache.enable_cli=0',$data.'/matrix-worker.php',$package.'/vendor/autoload.php',$workspace,$mode,$output,$package,$controls],
            'workers'=>in_array($mode,['observe','isolated-three','full-before-three','full-after-three'],true)?3:1,'request-timeout-ms'=>120000]]];
    if($mode==='controls'){$config['threads']=1;}
    $configuration=$workspace.'/'.$mode.'.config.json';$raw=$workspace.'/'.$mode.'.json';$error=$workspace.'/'.$mode.'.stderr.log';
    $write($configuration,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configuration,'analyze','--reporting-format=json'],[1=>['file',$raw,'w'],2=>['file',$error,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)){throw new RuntimeException('Cannot start the serialized genuine observation.');}
    $exit=proc_close($process);copy($raw,$raw.'.raw');copy($error,$error.'.raw');
    $closed[$mode]=['closed'=>true,'exitCode'=>$exit,'rawPath'=>$raw.'.raw','stderrPath'=>$error.'.raw',
        'rawSha256'=>hash_file('sha256',$raw.'.raw'),'stderrSha256'=>hash_file('sha256',$error.'.raw'),'configSha256'=>hash_file('sha256',$configuration),
        'hostWorkers'=>in_array($mode,['observe','isolated-three','full-before-three','full-after-three'],true)?3:1,'engineThreads'=>$mode==='controls'?1:'default','workerMemory'=>'512M','timeoutMs'=>120000];
    $write($workspace.'/'.$mode.'.closed.json',json_encode($closed[$mode],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    foreach([$raw,$raw.'.raw',$error,$error.'.raw',$configuration,$workspace.'/'.$mode.'.closed.json'] as $path){$evidence[$path]=hash_file('sha256',$path);}
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|falling back|hook[^\r\n]*failed|fatal|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning|PHP Notice/i',file_get_contents($error))){throw new RuntimeException('Operational native discovery failure: '.$mode);}
    $report=json_decode(ltrim(file_get_contents($raw),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
    if(!isset($report['issues'])||!is_array($report['issues'])){throw new RuntimeException('The complete raw native issue report is absent.');}
    $reports[$mode]=$report['issues'];if(in_array($mode,['native','observe'],true)){$selected($reports[$mode]);}$assertHeld();
};
foreach(['native','observe'] as $mode){$runStage($mode);}
if($identity($reports['native'])!==$identity($reports['observe'])){throw new RuntimeException('AlwaysKeep changed complete native records.');}
$positiveIssues=$selected($reports['native']);
$entryPath=$workspace.'/observe/entries.jsonl';$observationPath=$workspace.'/observe/observations.jsonl';
if(!is_file($entryPath)||!is_file($observationPath)){throw new RuntimeException('Actual local-model IssueFilterContext observations are absent.');}
$readEvents=static fn(string $path):array=>array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$entries=$readEvents($entryPath);$events=$readEvents($observationPath);$joins=[];
foreach($positiveIssues as $issue){
    $entered=array_values(array_filter($entries,static fn(array $event):bool=>modelDiscoveryWholeJoin($event,$issue,$workspace)));
    $finished=array_values(array_filter($events,static fn(array $event):bool=>modelDiscoveryWholeJoin($event,$issue,$workspace)));
    if(count($entered)!==1||count($finished)!==1){throw new RuntimeException('A selected native record does not have one complete entry and completed SDK join.');}
    $entry=$entered[0];$event=$finished[0];
    if(($event['alwaysKeep']??false)!==true||($event['genuineIssueFilterContext']??false)!==true||($event['entryBeforeCandidateQueries']??false)!==true
        ||($event['returnTypeProvidersRegistered']??null)!==0||($event['fakeProviderContextUsed']??null)!==false
        ||$entry['sequence']!==$event['sequence']||$entry['pid']!==$event['pid']
        ||hash_file('sha256',modelDiscoveryAbsolute($event['file'],$workspace))!==$event['sourceSha256']){
        throw new RuntimeException('A genuine entry/current source identity was not preserved.');
    }
    // Keep is useful ABI discovery, never reported as native acceptance.
    $joins[]=['rawIssue'=>$issue,'sdkObservation'=>$event,'candidateAdmitted'=>($event['candidateDecision']??null)==='Remove'];
}
// This successor exists to prove the observed Collection parent route on the
// same two real captured-tuple issues before any broader mutation matrix.
$parentTraits=['illuminate\\support\\traits\\conditionable','illuminate\\support\\traits\\enumeratesvalues',
    'illuminate\\support\\traits\\macroable','illuminate\\support\\traits\\transformstoresourcecollection'];sort($parentTraits);
$ownTraits=['illuminate\\database\\eloquent\\relations\\concerns\\interactswithdictionary'];
$allTraits=[...$ownTraits,...$parentTraits];sort($allTraits);
foreach($joins as $join){
    $event=$join['sdkObservation'];
    if(!$join['candidateAdmitted']){
        $failed=array_values(array_filter($event['stages'],static fn(array $stage):bool=>!$stage['passed']));
        throw new RuntimeException('A genuine captured-tuple positive was refused: '.($failed[0]['stage']??'no completed source certificate'));
    }
    $models=$event['certificate']['modelCertificates']??[];
    if($models===[]){throw new RuntimeException('The positive has no current local-model certificate.');}
    foreach($models as $model){
        $graphs=$model['declaration']['currentFirstTraitSources']??[];$collection=$graphs['collection']??null;$support=$graphs['support']??null;
        if($collection===null||$support===null||$support['traits']!==$parentTraits||$support['parent']!==null
            ||$collection['ownTraits']!==$ownTraits||$collection['traits']!==$allTraits
            ||strcasecmp($collection['parent']['className']??'','Illuminate\\Support\\Collection')!==0
            ||$collection['parent']['file']!==$support['file']||$collection['parent']['hash']!==$support['hash']
            ||$collection['parent']['traits']!==$parentTraits){
            throw new RuntimeException('The genuine positive did not prove the exact own and inherited Collection trait graph.');
        }
    }
}
if(count($entries)!==count($events)){throw new RuntimeException('An actual context entered without a completed AlwaysKeep event.');}
foreach($entries as $entry){
    $finished=array_values(array_filter($events,static fn(array $event):bool=>$event['pid']===$entry['pid']&&$event['sequence']===$entry['sequence']));
    if(count($finished)!==1||$finished[0]['genuineIssue']!==$entry['genuineIssue']||$finished[0]['file']!==$entry['file']
        ||$finished[0]['sourceSha256']!==$entry['sourceSha256']||!$finished[0]['alwaysKeep']){
        throw new RuntimeException('A genuine entered context lacks its exact completed event.');
    }
}
$negativeCoverage=[];
foreach($catalogue['negativeOwners'] as $owner){
    $owned=[];foreach($reports['native'] as $issue){
        if($issue['level']!=='Error'){continue;}$annotation=$primary($issue);$file=$relativeSource($annotation);
        foreach($scopes[$file]??[] as $name=>$span){
            if($file.'::'.$name===$owner&&$annotation['span']['start']['offset']>=$span[0]&&$annotation['span']['end']['offset']<=$span[1]){$owned[]=$issue;}
        }
    }
    $negativeCoverage[$owner]=['genuineErrorCount'=>count($owned),'genuineWholeRecords'=>$owned,'nativeCovered'=>count($owned)===0];
}
$nativeCovered=[];
foreach($catalogue['nativeCoveredOwners'] as $owner){
    $owned=[];foreach($reports['native'] as $issue){
        if($issue['level']!=='Error'){continue;}$annotation=$primary($issue);$file=$relativeSource($annotation);
        foreach($scopes[$file]??[] as $name=>$span){
            if($file.'::'.$name===$owner&&$annotation['span']['start']['offset']>=$span[0]&&$annotation['span']['end']['offset']<=$span[1]){$owned[]=$issue;}
        }
    }
    if($owned!==[]){throw new RuntimeException('An ordinary first() native-covered control regressed.');}
    $nativeCovered[$owner]=['nativeCovered'=>true,'inventedErrorDemanded'=>false];
}
$evidence[$entryPath]=hash_file('sha256',$entryPath);$evidence[$observationPath]=hash_file('sha256',$observationPath);$assertHeld();
$write($workspace.'/positive-observation.json',json_encode(['discoveryOnly'=>true,'twoClosedNativeJobs'=>true,
    'genuinePositiveErrors'=>count($positiveIssues),'wholeNativeRecordsUnchanged'=>true,'rawSdkCompleteJoins'=>$joins,
    'negativeCoverage'=>$negativeCoverage,'nativeCoveredOrdinaryFirst'=>$nativeCovered,
    'entryCount'=>count($entries),'completedObservationCount'=>count($events),
    'genuinePositiveCandidatesRemove'=>count($joins),'observedCollectionParentTraitRouteProved'=>true,
    'observerRawHashes'=>['entries'=>hash_file('sha256',$entryPath),'observations'=>hash_file('sha256',$observationPath)],
    'closedReceipts'=>$closed,'publicInventoryHeld'=>true,'sourceInputsHeld'=>true,'fixtureBodiesExecuted'=>false,
    'returnTypeProvidersRegistered'=>0,'fakeProviderContexts'=>0,'nativeCandidateAccepted'=>false,
    'originalConsumerRemovalClaimed'=>false,'publicAcceptance'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'Two genuine candidates Remove in completed AlwaysKeep observations; continuing the full matrix.'.PHP_EOL;
$without=static function(array $before,array $removed)use($identity):array{
    $counts=[];foreach($removed as $issue){$key=json_encode($issue,JSON_THROW_ON_ERROR);$counts[$key]=($counts[$key]??0)+1;}
    $remaining=[];foreach($before as $issue){$key=json_encode($issue,JSON_THROW_ON_ERROR);if(($counts[$key]??0)>0){$counts[$key]--;continue;}$remaining[]=$issue;}
    if(array_sum($counts)!==0){throw new RuntimeException('A requested exact complete record removal is absent.');}return $identity($remaining);
};
foreach($catalogue['requiredNegativeOwners'] as $owner){
    if(($negativeCoverage[$owner]['genuineErrorCount']??0)<1){throw new RuntimeException('A promised meaningful negative has no actual Error: '.$owner);}
}
foreach($catalogue['nativeCoveredNegativeOwners'] as $owner){
    if(($negativeCoverage[$owner]['genuineErrorCount']??null)!==0){throw new RuntimeException('An honestly native-covered source refusal changed: '.$owner);}
}
$expectedIsolated=$without($reports['native'],$positiveIssues);
foreach(['isolated','isolated-three','controls','full-before','full-before-three','full-after','full-after-three'] as $mode){
    $runStage($mode);
    if(in_array($mode,['isolated','isolated-three'],true)&&$identity($reports[$mode])!==$expectedIsolated){
        throw new RuntimeException('Isolated exact removals or unrelated complete records changed: '.$mode);
    }
    if($mode==='controls'){
        if($identity($reports[$mode])!==$identity($reports['native'])){throw new RuntimeException('AlwaysKeep controls changed complete native records.');}
        $controlsPath=$workspace.'/controls/controls.json';
        if(!is_file($controlsPath)){throw new RuntimeException('Completed genuine cache/source controls receipt is absent.');}
        $controlReceipt=json_decode(file_get_contents($controlsPath),true,flags:JSON_THROW_ON_ERROR);
        if(($controlReceipt['status']??null)!=='PASS'||($controlReceipt['restored']??false)!==true||($controlReceipt['genuinePositive']??false)!==true
            ||count($controlReceipt['allChecks']??[])<1||in_array(false,$controlReceipt['allChecks'],true)
            ||count($controlReceipt['cacheMutations']??[])<1||count($controlReceipt['sourceMutations']??[])<1){
            throw new RuntimeException('Genuine mutation controls were incomplete or not restored.');
        }
        $evidence[$controlsPath]=hash_file('sha256',$controlsPath);$assertHeld();
    }
    if($mode==='full-before-three'&&$identity($reports[$mode])!==$identity($reports['full-before'])){
        throw new RuntimeException('Current full before registries differ by host worker count.');
    }
    if($mode==='full-before'){
        $fullPositive=[];
        foreach($reports[$mode] as $issue){
            if($issue['level']!=='Error'||$issue['code']!=='mixed-argument'){continue;}
            $annotation=$primary($issue);$file=$relativeSource($annotation);
            foreach($positiveSites as $name=>$span){
                if(str_starts_with($name,$file.'::')&&[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']]===$span){$fullPositive[]=$issue;}
            }
        }
        if($fullPositive===[]){throw new RuntimeException('Current full registry has no nonvacuous selected target Error.');}
        $expectedFull=$without($reports[$mode],$fullPositive);
    }
    if(in_array($mode,['full-after','full-after-three'],true)&&$identity($reports[$mode])!==$expectedFull){
        throw new RuntimeException('Full registry changed unrelated whole records or exact selected removals: '.$mode);
    }
}
if(count($closed)!==9){throw new RuntimeException('The nine genuine matrix stages were not all closed.');}
$assertHeld();
$write($workspace.'/accepted-result.json',json_encode(['focusedNativeFixtureAccepted'=>true,'originalConsumerRemovalClaimed'=>false,
    'closedStages'=>9,'sourceCaseCount'=>count($catalogue['positiveOwners'])+count($catalogue['negativeOwners'])+count($catalogue['nativeCoveredOwners']),
    'genuineIsolatedRemovedErrors'=>count($positiveIssues),'genuineFullRemovedErrors'=>count($fullPositive),
    'allUnrelatedCompleteRecordsHeld'=>true,'hostWorkersOneAndThreeEqual'=>true,'mainEngineThreads'=>'default','controlEngineThreads'=>1,
    'workerMemory'=>'512M','requestTimeoutMs'=>120000,'genuinePositiveJoins'=>$joins,'negativeCoverage'=>$negativeCoverage,
    'nativeCoveredOrdinaryFirst'=>$nativeCovered,'controlReceipt'=>$controlReceipt,'controlReceiptSha256'=>hash_file('sha256',$controlsPath),
    'closedReceipts'=>$closed,'closedEvidenceHashes'=>$evidence,'inputHashes'=>$held,'publicInventorySha256'=>hash('sha256',json_encode($publicBefore,JSON_THROW_ON_ERROR)),
    'fixtureBodiesExecuted'=>false,'fakePositiveContexts'=>0,'returnTypeProvidersRegistered'=>0,'afterFileAuthority'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS guarded local model matrix: exact genuine removals, all residual records, restored SDK/source controls, full registries at one and three host workers.'.PHP_EOL;
