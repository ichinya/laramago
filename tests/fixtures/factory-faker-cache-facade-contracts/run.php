<?php
declare(strict_types=1);
require __DIR__.'/NativeEventJoin.php';
$package=dirname(__DIR__,3);$fixture=__DIR__;$publicFixture=$package.'/tests/fixtures/factory-faker-contracts';$workspace=$package.'/var/compatibility-factory-faker-contracts-'.bin2hex(random_bytes(8));mkdir($workspace);$manifest=json_decode(file_get_contents($publicFixture.'/prepared-library/manifest.json'),true,flags:JSON_THROW_ON_ERROR);
foreach($manifest['files'] as $path=>$entry){$bytes=str_replace("\r\n","\n",file_get_contents($publicFixture.'/prepared-library/'.$path));if(hash('sha256',$bytes)!==$entry['sourceSha256']){throw new RuntimeException('Faker prepared primary source changed.');}if(!is_dir(dirname($workspace.'/'.$path))){mkdir(dirname($workspace.'/'.$path),recursive:true);}file_put_contents($workspace.'/'.$path,$bytes);}
$overridePath='vendor/example/faker-overrides/src/Registration.php';$overrideStub="<?php\n// Composer-declared autoload file without additional formatter or container registration.\n";mkdir(dirname($workspace.'/'.$overridePath),recursive:true);file_put_contents($workspace.'/'.$overridePath,$overrideStub);
$packages=[
    ['name'=>'fakerphp/faker','install-path'=>'../fakerphp/faker','autoload'=>['psr-4'=>['Faker\\'=>'src/Faker/']]],
    ['name'=>'example/faker-overrides','install-path'=>'../example/faker-overrides','autoload'=>['psr-4'=>['Example\\VendorFormatterOverride\\'=>'src/'],'files'=>['src/Registration.php']]],
    ['name'=>'laravel/framework','install-path'=>'../laravel/framework','autoload'=>['psr-4'=>['Illuminate\\'=>'src/Illuminate/'],'files'=>['src/Illuminate/Foundation/helpers.php']]],
];
$primaryPackages=$manifest['selectedSharedPackageAutoloadMetadata'];
foreach($primaryPackages as $name=>$metadata){
    if(in_array($name,['fakerphp/faker','laravel/framework'],true)){
        foreach($packages as &$entry){if($entry['name']===$name){$entry['autoload']['psr-4']=$metadata['autoloadPsr4'];}}unset($entry);continue;
    }
    $packages[]=['name'=>$name,'install-path'=>'../'.$name,'autoload'=>['psr-4'=>$metadata['autoloadPsr4']]];
}
$receiverPath='vendor/example/faker-overrides/src/ReceiverCatalogueProvider.php';
$receiverSource=file_get_contents($fixture.'/receiver-registration.php.stub');
file_put_contents($workspace.'/'.$receiverPath,$receiverSource);
$packages[1]['autoload']['files'][]='src/ReceiverCatalogueProvider.php';
$localPath='local-cache-registration.php';$localSource=file_get_contents($fixture.'/local-cache-registration.php.stub');file_put_contents($workspace.'/'.$localPath,$localSource);
$facadePath='facade-cache-registration.php';$facadeSource=file_get_contents($fixture.'/facade-cache-registration.php.stub');file_put_contents($workspace.'/'.$facadePath,$facadeSource);
$extras=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture.'/prepared-extra',FilesystemIterator::SKIP_DOTS)) as $entry){if(!$entry->isFile()){continue;}$relative=str_replace('\\','/',substr($entry->getPathname(),strlen($fixture.'/prepared-extra/')));$extras[]=$relative;if(!is_dir(dirname($workspace.'/'.$relative))){mkdir(dirname($workspace.'/'.$relative),recursive:true);}file_put_contents($workspace.'/'.$relative,str_replace("\r\n","\n",file_get_contents($entry->getPathname())));}
foreach($packages as &$entry){if($entry['name']==='laravel/framework'){$entry['autoload']['files'][]='src/Illuminate/Support/helpers.php';}}unset($entry);

$packages[1]['autoload']['psr-4']['Example\\FormatterRegistration\\']='src/';
$packages[1]['extra']=['laravel'=>['providers'=>['Example\\FormatterRegistration\\ReceiverCatalogueProvider']]];
$helperPath='vendor/laravel/framework/src/Illuminate/Foundation/helpers.php';if(!is_dir(dirname($workspace.'/'.$helperPath))){mkdir(dirname($workspace.'/'.$helperPath),recursive:true);}file_put_contents($workspace.'/'.$helperPath,str_replace("\r\n","\n",file_get_contents($publicFixture.'/prepared-library/'.$helperPath)));
foreach($packages as $entry){$path=$workspace.'/vendor/'.$entry['name'].'/composer.json';file_put_contents($path,json_encode(array_diff_key($entry,['install-path'=>true]),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));}
mkdir($workspace.'/vendor/composer');file_put_contents($workspace.'/vendor/composer/installed.json',json_encode(['packages'=>$packages],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));mkdir($workspace.'/bootstrap');$bootstrapProviders='<?php return [];';file_put_contents($workspace.'/bootstrap/providers.php',$bootstrapProviders);
$cases=(static fn(string $path):string=>require $path)($publicFixture.'/fixture-cases.php');$rootComposer='{}';file_put_contents($workspace.'/cases.php',$cases);file_put_contents($workspace.'/composer.json',$rootComposer);require $package.'/vendor/autoload.php';require $package.'/tests/fixtures/compatibility-bounded-worker-support.php';
$writeFixtureReceipt=static fn(string $name,array $value):int|false=>file_put_contents($workspace.'/'.$name,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$writeFixtureReceipt('fixture-workspace.json',['workspace'=>$workspace,'genuineContextsRequired'=>2,'requiredLevels'=>['Warning','Error'],'nativeCountsInitiallyUnknown'=>true,'legacyReceiverCataloguePreserved'=>true,'applicationExecuted'=>false]);
$proofs=\Ichinya\Laramago\Analyzer\FactoryFakerSource::compile(\Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource::parse($cases));
if(count($proofs)!==2){throw new RuntimeException('Two formatter source roles are required.');}
$worker=compatibilityProductionWorker($package,$workspace,$fixture,'LOCAL_CACHE_FIXTURE','LocalCacheFixturePlugin','FactoryFakerPlugin');
$config=['php-version'=>'8.5','source'=>['paths'=>['cases.php',$receiverPath,$localPath,$facadePath],
    'includes'=>array_values(array_unique([...array_keys($manifest['files']),...$extras,$overridePath,$helperPath]))]];
$run=static fn(string $stage,string $mode,int $workers=1):array=>compatibilityNativeGate($package,$workspace,$worker,$config,$stage,$mode,$workers);
$normalize=static function(array $issues):array{$rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows;};
$rows=static fn(string $stage):array=>array_map(static fn(string $s):array=>json_decode($s,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/'.$stage.'-observer.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$native=$run('native-profile-probe','observe');$seen=[];$profiles=[];$levels=[];$joined=[];
$references=json_decode(file_get_contents($fixture.'/native-profile-hashes.json'),true,flags:JSON_THROW_ON_ERROR);ksort($references);
foreach($rows('native-profile-probe') as $row){$site=implode(':',$row['span']);
    if(isset($seen[$site])||!isset($proofs[$site])||\Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($row['proof']['source'])!==\Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($proofs[$site])
        ||($row['alwaysKeep']??null)!==true||($row['contextContentsSha256']??null)!==hash('sha256',$cases)
        ||($row['proof']['nativeTypesChanged']??null)!==false||($row['proof']['globalGeneratorOverride']??null)!==false
        ||($row['proof']['formatterExecuted']??null)!==false){throw new RuntimeException('The genuine current source event differs.');}
    $file=str_replace('\\','/',$row['contextFile']);if($file!=='cases.php'&&strtolower($file)!==strtolower(str_replace('\\','/',$workspace.'/cases.php'))){throw new RuntimeException('A foreign context supplied the formatter event.');}
    $seen[$site]=true;$sdk=$row['wholeNativeEnvelope'];$levels[$site]=$sdk['level']['name']??null;$matches=[];
    foreach($native as $index=>$issue){$primary=array_values(array_filter($issue['annotations'],static fn(array $a):bool=>$a['kind']==='Primary'));
        if(count($primary)===1&&[$primary[0]['span']['start']['offset'],$primary[0]['span']['end']['offset']]===$row['span']
            &&$issue['code']===$sdk['code']&&$issue['message']===$sdk['message']&&$issue['level']===$sdk['level']['name']){$matches[$index]=$issue;}}
    if(count($matches)!==1){throw new RuntimeException('The selected event must join one genuine complete raw DTO.');}
    $index=array_key_first($matches);if(isset($joined[$index])){throw new RuntimeException('A duplicate native record supplied both roles.');}
    LocalCacheNativeEventJoin::sameEnvelope($sdk,$matches[$index],$workspace,$cases);$joined[$index]=hash('sha256',json_encode($matches[$index],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $perRow=array_map(static fn(array $profile):string=>$profile['sha256'],$row['independentFormatterProfiles']);ksort($perRow);
    if(count($perRow)!==18||$perRow!==$references){throw new RuntimeException('Each genuine context must independently bind all eighteen current profiles.');}

    foreach($row['independentFormatterProfiles'] as $symbol=>$profile){if(isset($profiles[$symbol])&&$profiles[$symbol]!==$profile['sha256']){throw new RuntimeException('Selected formatter profiles drifted.');}$profiles[$symbol]=$profile['sha256'];}
}
ksort($profiles);$levels=array_values($levels);sort($levels);
$entries=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/native-profile-probe-observer.jsonl.entries.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
if(count($entries)!==2){throw new RuntimeException('Exactly two genuine selected entry/completion events are required.');}
$entrySites=[];foreach($entries as $entry){$site=implode(':',$entry['span']);if(isset($entrySites[$site])||($entry['entry']??null)!==true||($entry['contextContentsSha256']??null)!==hash('sha256',$cases)||!isset($seen[$site])){throw new RuntimeException('A genuine unique entry does not join its completion.');}$entrySites[$site]=true;}
$entryKeys=array_keys($entrySites);$completionKeys=array_keys($seen);sort($entryKeys);sort($completionKeys);if($entryKeys!==$completionKeys){throw new RuntimeException('The two entry/completion site sets differ.');}
if(count($seen)!==2||count($profiles)!==18||$profiles!==$references||$levels!==['Error','Warning']){throw new RuntimeException('First genuine local receiver two-role/18-profile admission failed; retained '.$workspace);}
$decisions=array_map(static fn(array $row):bool=>$row['proof']['remove'],$rows('native-profile-probe'));
$bothRemove=count($decisions)===2&&!in_array(false,$decisions,true);
if(!$bothRemove){throw new RuntimeException('The first genuine Cache facade/local caller proof refused; retained '.$workspace);}
$result=['status'=>$bothRemove?'genuine-positive-preflight-passed':'genuine-native-discovery-passed','workspace'=>$workspace,'selectedContexts'=>2,'selectedLevels'=>$levels,'selectedNativeFormatterProfiles'=>18,
    'nativeIssueCount'=>count($native),'completeSelectedNativeDtoHashes'=>$joined,'completeNativeMultisetSha256'=>hash('sha256',json_encode($normalize($native),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'entryCompletionCount'=>2,'nativeRawSha256'=>hash_file('sha256',$workspace.'/native-profile-probe.json.raw'),'observerSha256'=>hash_file('sha256',$workspace.'/native-profile-probe-observer.jsonl'),'bothGenuineProofsRemove'=>$bothRemove,'cacheFacadeDiscoveryOnly'=>false,'controlsExecuted'=>false,'publicProductionAcceptance'=>false,'nativeTypesChanged'=>false];
if(in_array('--observe-only',$argv,true)){file_put_contents($workspace.'/fixture-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit(0);}
$nativeSet=$normalize($native);$selectedHashes=array_values($joined);$expected=[];
foreach($native as $issue){$digest=hash('sha256',json_encode($issue,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));if(in_array($digest,$selectedHashes,true)){continue;}$expected[]=$issue;}
if(count($native)-count($expected)!==2){throw new RuntimeException('The exact selected removal multiset differs.');}
$exact=static function(array $actual,array $wanted,string $name)use($normalize):void{if($normalize($actual)!==$normalize($wanted)){throw new RuntimeException('Complete native DTO multiset differs: '.$name);}};
$exact($run('native-keep-one','native'),$native,'Keep one');
$exact($run('candidate-one','draft'),$expected,'candidate one');
$exact($run('candidate-three','draft',3),$expected,'candidate three');
$exact($run('preserve-one','preserve'),$native,'preserve one');
$unions=[];$planByRole=[];$controlStages=[];
$readControls=static fn(string $stage):array=>array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/'.$stage.'-observer.jsonl.controls.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
for($part=0;$part<12;$part++){
    $stage=sprintf('cache-facade-%02d',$part);$exact($run($stage,$stage),$native,'control '.$stage);$controlStages[]=$stage;
    $controlRows=$readControls($stage);if(count($controlRows)!==2){throw new RuntimeException('Both genuine control completions are required.');}$seenRoles=[];
    foreach($controlRows as $row){$role=$row['sourceKind'];if(isset($seenRoles[$role])||($row['status']??null)!=='passed'||($row['restored']??null)!==true||($row['nativeIssueUnchanged']??null)!==true||($row['constructedContexts']??null)!==0){throw new RuntimeException('Control completion differs.');}$seenRoles[$role]=true;
        $labels=$row['plannedLabels'];if(count($labels)!==116||count(array_unique($labels))!==116||($row['plannedCount']??null)!==116||$row['selectedLabels']!==array_slice($labels,$part*10,10)){throw new RuntimeException('Control plan or exact partition differs.');}
        if(isset($planByRole[$role])&&$planByRole[$role]!==$labels){throw new RuntimeException('Per-role control plan drifted.');}$planByRole[$role]=$labels;
        if(array_keys($row['receipts'])!==$row['selectedLabels']){throw new RuntimeException('Each exact selected label needs a receipt.');}
        foreach($row['receipts'] as $label=>$receipt){if(isset($unions[$role][$label])){throw new RuntimeException('Control partitions overlap.');}
            foreach(['genuineBeforeRemove','changedKeep','restoredFreshRemove','completeProofRestored','wholeNativeIssueUnchanged'] as $flag){if(($receipt[$flag]??null)!==true){throw new RuntimeException('Meaningful control/restoration claim differs.');}}
            $unions[$role][$label]=$receipt;
        }
    }
}
if(count($unions)!==2){throw new RuntimeException('Both complete per-role control unions are required.');}
foreach($unions as $role=>$receipts){$labels=array_keys($receipts);sort($labels);if(count($receipts)!==116||$labels!==$planByRole[$role]){throw new RuntimeException('The exact 116-control per-role union differs.');}}
$sourceUnions=[];$sourcePlans=[];
for($part=0;$part<4;$part++){
    $stage=sprintf('source-facade-%02d',$part);$exact($run($stage,'cache-'.$stage),$native,'source '.$stage);$controlStages[]=$stage;
    $sourceRows=$readControls($stage);if(count($sourceRows)!==2){throw new RuntimeException('Both genuine source-control completions are required.');}$seenSourceRoles=[];
    foreach($sourceRows as $row){$role=$row['sourceKind'];if(isset($seenSourceRoles[$role])||($row['status']??null)!=='passed'||($row['restored']??null)!==true||($row['nativeIssueUnchanged']??null)!==true||($row['constructedContexts']??null)!==0){throw new RuntimeException('A unique restored source-control role is required.');}$seenSourceRoles[$role]=true;$labels=$row['plannedLabels'];if(count($labels)!==4||($row['plannedCount']??null)!==4||$row['selectedLabels']!==[$labels[$part]]){throw new RuntimeException('Source control partition differs.');}
        if(isset($sourcePlans[$role])&&$sourcePlans[$role]!==$labels){throw new RuntimeException('Source control plan drifted.');}$sourcePlans[$role]=$labels;
        foreach($row['receipts'] as $label=>$receipt){if(isset($sourceUnions[$role][$label])){throw new RuntimeException('Source partitions overlap.');}foreach(['genuineBeforeRemove','changedKeep','restoredFreshRemove','completeProofRestored','wholeNativeIssueUnchanged'] as $flag){if(($receipt[$flag]??null)!==true){throw new RuntimeException('Source restoration differs.');}}$sourceUnions[$role][$label]=$receipt;}
    }
}
if(count($sourceUnions)!==2){throw new RuntimeException('Both source control unions are required.');}
foreach($sourceUnions as $role=>$receipts){$labels=array_keys($receipts);sort($labels);if(count($receipts)!==4||$labels!==$sourcePlans[$role]){throw new RuntimeException('The exact source union differs.');}}
$caseDirectory=__DIR__.'/control-helpers';require_once $caseDirectory.'/FacadeSourceCaseCatalogue.php';
$negativeReceipts=[];
foreach(\Example\LocalCacheFocusedTests\FacadeSourceCaseCatalogue::cases() as $id=>$spec){
    file_put_contents($workspace.'/'.$facadePath,$spec['source']);
    try{$before=$run('negative-'.$id.'-native','native');$after=$run('negative-'.$id.'-draft','draft');$exact($after,$before,'negative '.$id);
        $targetMatches=0;foreach($before as $issue){if(in_array(hash('sha256',json_encode($issue,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),$selectedHashes,true)){$targetMatches++;}}
        if($targetMatches!==2){throw new RuntimeException('Each negative must keep both genuine original formatter DTOs.');}
        $negativeReceipts[$id]=['nativeCount'=>count($before),'draftCount'=>count($after),'genuineTargets'=>2,'wholeDTOsHeld'=>true];
    }finally{file_put_contents($workspace.'/'.$facadePath,$facadeSource);}
}
$exact($run('restored-candidate-three','draft',3),$expected,'restored candidate three');
$result+=['focusedControlGatePassed'=>true,'sdkMutationsPerRole'=>array_map('count',$unions),'sourceChangesPerRole'=>array_map('count',$sourceUnions),'sourceCaseCount'=>count($negativeReceipts),'sourceNegativeReceipts'=>$negativeReceipts,'controlStageCount'=>count($controlStages),'legacy360GateExecuted'=>false,'legacy26GateExecuted'=>false];
$result['status']='focused-facade-controls-passed';$result['controlsExecuted']=true;
file_put_contents($workspace.'/fixture-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";

