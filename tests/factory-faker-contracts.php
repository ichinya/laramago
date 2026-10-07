<?php
declare(strict_types=1);
$package=dirname(__DIR__);$fixture=__DIR__.'/fixtures/factory-faker-contracts';$publicFixture=$fixture;$workspace=$package.'/var/compatibility-factory-faker-contracts-'.bin2hex(random_bytes(8));mkdir($workspace);$manifest=json_decode(file_get_contents($fixture.'/prepared-library/manifest.json'),true,flags:JSON_THROW_ON_ERROR);
foreach($manifest['files'] as $path=>$entry){$bytes=file_get_contents($fixture.'/prepared-library/'.$path);if(hash('sha256',$bytes)!==$entry['sourceSha256']){throw new RuntimeException('Faker prepared primary source changed.');}if(!is_dir(dirname($workspace.'/'.$path))){mkdir(dirname($workspace.'/'.$path),recursive:true);}file_put_contents($workspace.'/'.$path,$bytes);}
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
$packages[1]['autoload']['psr-4']['Example\\FormatterRegistration\\']='src/';
$packages[1]['extra']=['laravel'=>['providers'=>['Example\\FormatterRegistration\\ReceiverCatalogueProvider']]];
$helperPath='vendor/laravel/framework/src/Illuminate/Foundation/helpers.php';if(!is_dir(dirname($workspace.'/'.$helperPath))){mkdir(dirname($workspace.'/'.$helperPath),recursive:true);}file_put_contents($workspace.'/'.$helperPath,file_get_contents($fixture.'/prepared-library/'.$helperPath));
foreach($packages as $entry){$path=$workspace.'/vendor/'.$entry['name'].'/composer.json';file_put_contents($path,json_encode(array_diff_key($entry,['install-path'=>true]),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));}
mkdir($workspace.'/vendor/composer');file_put_contents($workspace.'/vendor/composer/installed.json',json_encode(['packages'=>$packages],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));mkdir($workspace.'/bootstrap');$bootstrapProviders='<?php return [];';file_put_contents($workspace.'/bootstrap/providers.php',$bootstrapProviders);
$cases=(static fn(string $path):string=>require $path)($publicFixture.'/fixture-cases.php');$rootComposer='{}';file_put_contents($workspace.'/cases.php',$cases);file_put_contents($workspace.'/composer.json',$rootComposer);require $package.'/vendor/autoload.php';require $package.'/tests/fixtures/compatibility-bounded-worker-support.php';
$writeFixtureReceipt=static fn(string $name,array $value):int|false=>file_put_contents($workspace.'/'.$name,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$writeFixtureReceipt('fixture-workspace.json',['workspace'=>$workspace,'genuineContextsRequired'=>2,'requiredLevels'=>['Warning','Error'],'selectedReceiverClasses'=>15,'selectedReceiverMethods'=>27,'applicationExecuted'=>false]);
$sourceCaseRoot=$workspace.'/pure-source-cases';mkdir($sourceCaseRoot);
$pureInclude=static function(string $path,string $sourceCaseRoot):void{require $path;};
foreach(['check-installed-sources.php','check-registered-source-precedence.php','check-static-provider-sources.php','check-provider-binding-maps.php','check-provider-source-dependencies.php','check-generator-alias-targets.php'] as $pureCheck){$pureInclude($publicFixture.'/'.$pureCheck,$sourceCaseRoot);}
(static function(string $fixture):void{$sourceCases=(static function(string $path):array{return require $path;})($fixture.'/source-cases.php');$positiveNames=['words-text','words-true-peer-left','elements-integers','elements-scalars','empty-array-retains-null','unrelated-first-class-callable'];if(count($sourceCases)!==25){throw new RuntimeException('The formatter source case count changed.');}foreach($sourceCases as $name=>$contents){$proofs=Ichinya\Laramago\Analyzer\FactoryFakerSource::compile(Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource::parse($contents));if(count($proofs)!==(in_array($name,$positiveNames,true)?1:0)){throw new RuntimeException('The public formatter source matrix changed: '.$name);}}})($publicFixture);
$proofs=Ichinya\Laramago\Analyzer\FactoryFakerSource::compile(Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource::parse($cases));$positives=[];foreach($proofs as $site=>$p){$positives[$site]=$p['kind'];}if(count($positives)!==2){throw new RuntimeException('Exactly two invented source positives required.');}
file_put_contents($workspace.'/source-case-manifest.json',json_encode(['formatterRoles'=>['default-faker-words-text','default-faker-literal-element'],'positiveSites'=>$positives,'formatterExecuted'=>false,'nativeTypeRewrite'=>false,'nativeProfilesInitiallyEmpty'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$worker=compatibilityProductionWorker($package,$workspace,$fixture,'FACTORY_FAKER_FIXTURE','ReceiverFixturePlugin','FactoryFakerPlugin');$config=['php-version'=>'8.5','source'=>['paths'=>['cases.php',$receiverPath],'includes'=>[...array_keys($manifest['files']),$overridePath,$helperPath]]];
$run=static fn(string $stage,string $mode,int $workers=1):array=>compatibilityNativeGate($package,$workspace,$worker,$config,$stage,$mode,$workers);
$normalize=static function(array $issues):array{$rows=array_map(static fn(array $i):string=>json_encode($i,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows;};$rows=static fn(string $stage):array=>array_map(static fn(string $s):array=>json_decode($s,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/'.$stage.'-observer.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$key=static function(array $i):string{foreach($i['annotations'] as $a){if($a['kind']==='Primary'){return $a['span']['start']['offset'].':'.$a['span']['end']['offset'].':'.$i['code'].':'.$i['message'];}}return '';};
$probe=$run('native-profile-probe','observe');$seen=[];$profiles=[];$selectedLevels=[];foreach($rows('native-profile-probe') as $row){if($row['stage']!=='factory-faker'||!$row['proof']['remove']){continue;}$site=implode(':',$row['span']);if(!isset($positives[$site])){throw new RuntimeException('A Faker near miss supplied default-domain profiles.');}$seen[$site]=true;$selectedLevels[$site]=$row['wholeNativeEnvelope']['level']['name']??null;foreach($row['proof']['observedNativeProfiles'] as $symbol=>$p){if(isset($profiles[$symbol])&&$profiles[$symbol]!==$p['sha256']){throw new RuntimeException('Selected Faker native metadata drift.');}$profiles[$symbol]=$p['sha256'];}}
if(count($seen)!==2||count($profiles)!==18){throw new RuntimeException('Two complete genuine default Faker source/native positives required; retained '.$workspace);}$selectedLevels=array_values($selectedLevels);sort($selectedLevels);if($selectedLevels!==['Error','Warning']){throw new RuntimeException('The two genuine native roles must retain one Error and one Warning.');}$references=json_decode(file_get_contents($publicFixture.'/native-profile-hashes.json'),true,flags:JSON_THROW_ON_ERROR);ksort($profiles);ksort($references);if($profiles!==$references){throw new RuntimeException('Current selected profiles differ from the closed genuine references.');}file_put_contents($workspace.'/observed-native-hashes.json',json_encode($profiles,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
if(in_array('--observe-only',$argv,true)){$writeFixtureReceipt('fixture-result.json',['status'=>'genuine-positive-preflight-passed','workspace'=>$workspace,'selectedContexts'=>2,'selectedLevels'=>$selectedLevels,'selectedNativeFormatterProfiles'=>18,'controlsExecuted'=>false,'publicProductionAcceptance'=>false]);echo "OBSERVED ONLY: $workspace\n";exit(0);}
$native=$run('native','observe');if($normalize($native)!==$normalize($probe)){throw new RuntimeException('AlwaysKeep or selected-native profile load changed whole DTOs.');}$admitted=[];
foreach($rows('native') as $row){if($row['stage']==='factory-faker'&&$row['alwaysKeep']&&$row['proof']['remove']){$site=implode(':',$row['span']);if(!isset($positives[$site])){throw new RuntimeException('Unsupported Faker producer admitted.');}$i=$row['wholeNativeEnvelope'];$admitted[$site.':'.$i['code'].':'.$i['message']]=true;}}
if(count($admitted)!==2){throw new RuntimeException('Two genuine source-bound formatter corrections required.');}$expected=array_values(array_filter($native,static fn(array $i):bool=>!isset($admitted[$key($i)])));$draft=$run('draft','draft');if($normalize($draft)!==$normalize($expected)){throw new RuntimeException('Complete residual Error/Warning DTO parity failed.');}
if(array_filter($draft,static fn(array $i):bool=>$i['level']==='Error')===[]){throw new RuntimeException('Missing independent preserved Error.');}
if($normalize($run('preserve','preserve'))!==$normalize($native)||$normalize($run('native-three-workers','observe',3))!==$normalize($native)||$normalize($run('draft-three-workers','draft',3))!==$normalize($draft)){throw new RuntimeException('Faker preserve/host-count parity failed.');}
$receiverControls=[];$receiverPlans=[];$receiverUnions=[];$receiverChecks=[];
$receiverKinds=['default-faker-words-text','default-faker-literal-element'];
$receiverBatchSize=10;$receiverPlannedMutations=360;$receiverBatchCount=intdiv($receiverPlannedMutations,$receiverBatchSize);
for($batch=0;$batch<$receiverBatchCount;$batch++){
    $receiverMode=sprintf('cache-receiver-batch-%02d',$batch);
    if($normalize($run($receiverMode,$receiverMode))!==$normalize($native)){throw new RuntimeException('Receiver native controls changed the complete AlwaysKeep DTO multiset.');}
    $completed=array_values(array_filter($rows($receiverMode),static fn(array $row):bool=>$row['stage']==='receiver-native-controls'
        &&$row['genuinePositiveBefore']&&$row['completeProofRestored']&&$row['receipt']['status']==='PASS'
        &&$row['receipt']['sourceSelectionCounts']['nativeClasses']===15&&$row['receipt']['sourceSelectionCounts']['methods']===27));
    if(count($completed)!==2){throw new RuntimeException('Both genuine formatter roles require the complete bounded receiver batch and restored admission.');}
    $seenKinds=[];
    foreach($completed as $row){
        $kind=$row['kind'];$receipt=$row['receipt'];
        if(!in_array($kind,$receiverKinds,true)||isset($seenKinds[$kind])||$receipt['batchIndex']!==$batch
            ||$receipt['batchSize']!==$receiverBatchSize||$receipt['batchCount']!==$receiverBatchCount
            ||$receipt['plannedMutationCount']!==$receiverPlannedMutations||count($receipt['plannedMutations'])!==$receiverPlannedMutations
            ||$receipt['mode']!==$receiverMode||$receipt['genuinePositive']!==true||$receipt['restored']!==true){
            throw new RuntimeException('A receiver batch must bind each original genuine role exactly once and retain its complete plan.');
        }
        $seenKinds[$kind]=true;$plan=$receipt['plannedMutations'];$sorted=$plan;ksort($sorted,SORT_STRING);
        if($plan!==$sorted||$receipt['plannedMutationPlanSha256']!==hash('sha256',json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))){
            throw new RuntimeException('The deterministic complete receiver mutation plan changed.');
        }
        if(!isset($receiverPlans[$kind])){$receiverPlans[$kind]=$plan;}elseif($plan!==$receiverPlans[$kind]){throw new RuntimeException('The receiver mutation plan changed between native batches of the same genuine role.');}
        $expectedLabels=array_slice(array_keys($plan),$batch*$receiverBatchSize,$receiverBatchSize);
        if($receipt['batchLabels']!==$expectedLabels||array_keys($receipt['cacheMutations'])!==$expectedLabels){
            throw new RuntimeException('The real receiver mutations differ from their disjoint selected batch.');
        }
        foreach($receipt['cacheMutations'] as $label=>$mutation){
            if(isset($receiverUnions[$kind][$label])||$mutation['selectedBinding']!==$plan[$label]['selectedBinding']
                ||$mutation['queriedBindings']!==$plan[$label]['queriedBindings']||$mutation['genuineRemoveBefore']!==true
                ||$mutation['changedKeep']!==true||$mutation['restoredRemove']!==true||$mutation['meaningfulChange']!==true
                ||$mutation['cacheValuesAndRelationsRestored']!==true||$mutation['slotsChanged']<=0
                ||count($mutation['actualSlots'])!==$mutation['slotsChanged']){
                throw new RuntimeException('A promised receiver mutation was missing, duplicated, a no-op or did not restore genuine admission.');
            }
            $receiverUnions[$kind][$label]=$mutation;
        }
        foreach($receipt['allChecks'] as $label=>$passed){if($passed!==true){throw new RuntimeException('A receiver batch assertion did not pass.');}$receiverChecks[$kind][$label]=true;}
        $receiverControls[$receiverMode][$kind]=$receipt;
    }
}
foreach($receiverKinds as $kind){
    if(!isset($receiverUnions[$kind],$receiverPlans[$kind])||array_keys($receiverUnions[$kind])!==array_keys($receiverPlans[$kind])
        ||count($receiverUnions[$kind])!==$receiverPlannedMutations){throw new RuntimeException('The complete unchanged receiver mutation plan was not executed exactly once for both genuine roles.');}
}
file_put_contents($workspace.'/receiver-batch-union.json',json_encode(['status'=>'PASS','batchSize'=>$receiverBatchSize,
    'batchCount'=>$receiverBatchCount,'plannedMutationCountPerRole'=>$receiverPlannedMutations,'completePlansByRole'=>$receiverPlans,
    'actualMutationsByRole'=>$receiverUnions,'allChecksByRole'=>$receiverChecks,'disjointCompletePlanHeld'=>true,
    'cacheRestorationsHeld'=>true,'requestTimeoutMs'=>120000],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
if($normalize($run('receiver-cache-restored','draft'))!==$normalize($draft)){throw new RuntimeException('Receiver controls did not restore the complete residual DTO multiset.');}
$receiverNegatives=(static fn(string $path):array=>require $path)($fixture.'/registered-source-negatives.php');
foreach($receiverNegatives as $label=>$source){
    // The declared autoload file remains selected independently of CodebaseScan input coverage.
    file_put_contents($workspace.'/'.$overridePath,$source);
    $changedNative=$run($label.'-receiver-native','observe');$changedDraft=$run($label.'-receiver-draft','draft');
    if($normalize($changedNative)!==$normalize($changedDraft)){throw new RuntimeException('A registered receiver source priority negative hid a native DTO: '.$label);}
    $refusals=array_filter($rows($label.'-receiver-native'),static fn(array $row):bool=>$row['stage']==='factory-faker'
        &&isset($row['proof']['source'])&&!$row['proof']['remove']);
    if(count($refusals)!==2){throw new RuntimeException('Both selected positive roles must reach a genuine source-priority refusal: '.$label);}
    file_put_contents($workspace.'/'.$overridePath,$overrideStub);
    if($normalize($run($label.'-receiver-restored','draft'))!==$normalize($draft)){throw new RuntimeException('A registered receiver source priority restoration drifted: '.$label);}
}
foreach(['cache-pseudo-return','cache-factory-field','cache-caller-reference','cache-caller-traits','cache-parent-traits'] as $mode){if($normalize($run($mode,$mode))!==$normalize($native)){throw new RuntimeException('Faker cache mutation changed whole DTOs.');}$controls=array_filter($rows($mode),static fn(array $r):bool=>$r['stage']==='real-native-cache-control'&&$r['genuinePositiveBefore']&&$r['slotsChanged']>0&&$r['deferredAfter']&&$r['completeProofRestored']);if(count($controls)!==2){throw new RuntimeException('Both Faker positives need genuine changed cache refusal/restoration.');}}
$vendorOverrides=[
    'vendor-provider-registration'=>['hazard'=>'provider-registration','source'=>'<?php namespace Example\VendorFormatterOverride;function register(\Faker\Generator $generator):void{$generator->addProvider(new \stdClass);}'],
    'vendor-generator-binding'=>['hazard'=>'generator-binding','source'=>'<?php namespace Example\VendorFormatterOverride;class Registry{public function bind(string $name,\Closure $creator):void{}}function register(Registry $registry):void{$registry->bind(\Faker\Generator::class,fn()=>new \Faker\Generator);}'],
    'vendor-generator-alias'=>['hazard'=>'generator-binding','source'=>'<?php namespace Example\VendorFormatterOverride;class Registry{public function alias(string $abstract,string $alias):void{}}function register(Registry $registry):void{$registry->alias(\stdClass::class,\Faker\Generator::class);}'],
];
foreach($vendorOverrides as $stage=>$override){
    file_put_contents($workspace.'/'.$overridePath,$override['source']);$changedNative=$run($stage.'-native','observe');$changedDraft=$run($stage.'-draft','draft');
    if($normalize($changedNative)!==$normalize($changedDraft)){throw new RuntimeException('Declared vendor formatter or Generator override bypassed priority.');}
    $refusals=array_filter($rows($stage.'-native'),static fn(array $row):bool=>$row['stage']==='factory-faker'&&isset($row['proof']['source'])&&!$row['proof']['remove']&&in_array($override['hazard'],$row['proof']['contract']['catalog']['hazards']??[],true));
    if(count($refusals)!==2){throw new RuntimeException('Both genuine selected positives need the meaningful vendor source override refusal.');}
    file_put_contents($workspace.'/'.$overridePath,$overrideStub);if($normalize($run($stage.'-restored','draft'))!==$normalize($draft)){throw new RuntimeException('Vendor source override restoration changed whole residual DTOs.');}
}
$providerClass='Example\\VendorFormatterOverride\\RegisteredProvider';$providerPath=$workspace.'/vendor/example/faker-overrides/src/RegisteredProvider.php';
file_put_contents($providerPath,'<?php namespace Example\\VendorFormatterOverride;class RegisteredProvider extends \\Illuminate\\Support\\ServiceProvider{public function register():void{$this->app->make(\\Faker\\Generator::class)->addProvider(new \\stdClass);}}');
$providerPackages=$packages;$providerPackages[1]['extra']=['laravel'=>['providers'=>[$providerClass]]];
file_put_contents($workspace.'/vendor/example/faker-overrides/composer.json',json_encode(array_diff_key($providerPackages[1],['install-path'=>true]),JSON_THROW_ON_ERROR));file_put_contents($workspace.'/vendor/composer/installed.json',json_encode(['packages'=>$providerPackages],JSON_THROW_ON_ERROR));
if($normalize($run('discovered-provider-native','observe'))!==$normalize($run('discovered-provider-draft','draft'))){throw new RuntimeException('Composer-discovered provider registration bypassed priority.');}
foreach(['discovered-provider-native','discovered-provider-draft'] as $stage){$refusals=array_filter($rows($stage),static fn(array $row):bool=>$row['stage']==='factory-faker'&&isset($row['proof']['source'])&&!$row['proof']['remove']&&in_array('provider-registration',$row['proof']['contract']['installedSources']['hazards']??[],true));if(count($refusals)!==2){throw new RuntimeException('The recognized physical discovered provider must refuse both genuine selected domains.');}}
file_put_contents($workspace.'/vendor/example/faker-overrides/composer.json',json_encode(array_diff_key($packages[1],['install-path'=>true]),JSON_THROW_ON_ERROR));file_put_contents($workspace.'/vendor/composer/installed.json',json_encode(['packages'=>$packages],JSON_THROW_ON_ERROR));
if($normalize($run('discovered-provider-restored','draft'))!==$normalize($draft)){throw new RuntimeException('Discovered provider declaration restoration drift.');}
// The physical provider remains on disk but is no longer registered: mere source presence is not a runtime registration claim.
$unknownPackages=$packages;$unknownPackages[1]['extra']=['laravel'=>['providers'=>['Example\\VendorFormatterOverride\\MissingProvider']]];
file_put_contents($workspace.'/vendor/example/faker-overrides/composer.json',json_encode(array_diff_key($unknownPackages[1],['install-path'=>true]),JSON_THROW_ON_ERROR));file_put_contents($workspace.'/vendor/composer/installed.json',json_encode(['packages'=>$unknownPackages],JSON_THROW_ON_ERROR));
if($normalize($run('unknown-provider-native','observe'))!==$normalize($run('unknown-provider-draft','draft'))){throw new RuntimeException('Unknown selected provider catalogue bypassed priority.');}
$unknownRefusals=array_filter($rows('unknown-provider-native'),static fn(array $row):bool=>$row['stage']==='factory-faker'&&isset($row['proof']['source'])&&!$row['proof']['remove']&&isset($row['proof']['contract']['installedSources'])&&!$row['proof']['contract']['installedSources']['complete']);if(count($unknownRefusals)!==2){throw new RuntimeException('Both genuine selected domains must reach the unknown registered source catalogue refusal.');}
file_put_contents($workspace.'/vendor/example/faker-overrides/composer.json',json_encode(array_diff_key($packages[1],['install-path'=>true]),JSON_THROW_ON_ERROR));file_put_contents($workspace.'/vendor/composer/installed.json',json_encode(['packages'=>$packages],JSON_THROW_ON_ERROR));
if($normalize($run('unknown-provider-restored','draft'))!==$normalize($draft)){throw new RuntimeException('Unknown provider catalogue restoration drift.');}
file_put_contents($workspace.'/bootstrap/providers.php','<?php return [\\'.$providerClass.'::class];');
if($normalize($run('bootstrap-provider-native','observe'))!==$normalize($run('bootstrap-provider-draft','draft'))){throw new RuntimeException('Static bootstrap provider registration bypassed independent source priority.');}
$bootstrapRefusals=array_filter($rows('bootstrap-provider-native'),static fn(array $row):bool=>$row['stage']==='factory-faker'&&isset($row['proof']['source'])&&!$row['proof']['remove']&&in_array('provider-registration',$row['proof']['contract']['installedSources']['hazards']??[],true));if(count($bootstrapRefusals)!==2){throw new RuntimeException('Both genuine selected domains must refuse the static registered provider.');}
file_put_contents($workspace.'/bootstrap/providers.php',$bootstrapProviders);if($normalize($run('bootstrap-provider-restored','draft'))!==$normalize($draft)){throw new RuntimeException('Static provider source restoration drift.');}
$providerSource=file_get_contents($providerPath);$bindingParentPath=$workspace.'/vendor/example/faker-overrides/src/BindingParent.php';
file_put_contents($bindingParentPath,'<?php namespace Example\\VendorFormatterOverride;class BindingParent extends \\Illuminate\\Support\\ServiceProvider{public $singletons=[\\Faker\\Generator::class=>\\stdClass::class];}');
file_put_contents($providerPath,'<?php namespace Example\\VendorFormatterOverride;class RegisteredProvider extends BindingParent{}');file_put_contents($workspace.'/bootstrap/providers.php','<?php return [\\'.$providerClass.'::class];');
if($normalize($run('provider-property-native','observe'))!==$normalize($run('provider-property-draft','draft'))){throw new RuntimeException('A declared inherited provider binding map bypassed source priority.');}
$propertyRefusals=array_filter($rows('provider-property-native'),static fn(array $row):bool=>$row['stage']==='factory-faker'&&isset($row['proof']['source'])&&!$row['proof']['remove']&&in_array('generator-binding',$row['proof']['contract']['installedSources']['hazards']??[],true));if(count($propertyRefusals)!==2){throw new RuntimeException('Both genuine selected domains must defer to the independently resolved provider parent binding map.');}
file_put_contents($providerPath,$providerSource);file_put_contents($workspace.'/bootstrap/providers.php',$bootstrapProviders);if($normalize($run('provider-property-restored','draft'))!==$normalize($draft)){throw new RuntimeException('Inherited provider binding-map source restoration drift.');}
$helperSource=file_get_contents($workspace.'/'.$helperPath);$changedHelper=str_replace("\\Faker\\Generator::class.':'.\$locale","\\Faker\\Generator::class.':changed:'.\$locale",$helperSource,$changes);if($changes!==1){throw new RuntimeException('Standard locale helper target mutation was a no-op.');}file_put_contents($workspace.'/'.$helperPath,$changedHelper);
if($normalize($run('changed-helper-native','observe'))!==$normalize($run('changed-helper-draft','draft'))){throw new RuntimeException('Changed selected standard helper registration bypassed source precedence.');}
$helperRefusals=array_filter($rows('changed-helper-native'),static fn(array $row):bool=>$row['stage']==='factory-faker'&&isset($row['proof']['source'])&&!$row['proof']['remove']&&in_array('unknown-selected-binding-target',$row['proof']['contract']['installedSources']['hazards']??[],true));if(count($helperRefusals)!==2){throw new RuntimeException('Both genuine selected domains must refuse the changed literal locale helper source target.');}
file_put_contents($workspace.'/'.$helperPath,$helperSource);if($normalize($run('changed-helper-restored','draft'))!==$normalize($draft)){throw new RuntimeException('Standard helper source restoration drift.');}
file_put_contents($workspace.'/composer.json',json_encode(['extra'=>['laramago'=>['binding-files'=>'unknown']]],JSON_THROW_ON_ERROR));if($normalize($run('unknown-binding-native','observe'))!==$normalize($run('unknown-binding-draft','draft'))){throw new RuntimeException('Unknown configured bindings bypassed priority.');}file_put_contents($workspace.'/composer.json',$rootComposer);
$changed=str_replace('$this->faker->words(2,true)', '$this->faker->words(2,false)',$cases,$changes);if($changes!==2){throw new RuntimeException('Faker text flag mutation was a no-op.');}file_put_contents($workspace.'/cases.php',$changed);$mutNative=$run('source-flag-native','observe');$mutDraft=$run('source-flag-draft','draft');$target=array_values(array_filter($rows('source-flag-native'),static fn(array $r):bool=>$r['stage']==='factory-faker'&&$r['proof']['remove']));if(count($target)!==1||$target[0]['proof']['source']['kind']!=='default-faker-literal-element'){throw new RuntimeException('Source flag mutation must retain the independent selected element correction only.');}$site=implode(':',$target[0]['span']);$i=$target[0]['wholeNativeEnvelope'];$removed=$site.':'.$i['code'].':'.$i['message'];if($normalize(array_values(array_filter($mutNative,static fn(array $i):bool=>$key($i)!==$removed)))!==$normalize($mutDraft)){throw new RuntimeException('Unsupported words flag was incorrectly hidden.');}
file_put_contents($workspace.'/cases.php',$cases);if($normalize($run('source-restored','draft'))!==$normalize($draft)){throw new RuntimeException('Faker source restoration drift.');}
$gateResult=['status'=>'passed','formatterRoles'=>['default-faker-words-text','default-faker-literal-element'],'nativeCount'=>count($native),'draftCount'=>count($draft),'selectedCorrections'=>2,'nativeTypesChanged'=>false,'globalGeneratorOverride'=>false,'wholeResidualDTOsPreserved'=>true,'sourcePriorityNegativeCount'=>count($receiverNegatives),'receiverCacheControls'=>$receiverControls,'workspace'=>$workspace];file_put_contents($workspace.'/gate-result.json',json_encode($gateResult,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));$writeFixtureReceipt('fixture-result.json',$gateResult);echo "PASS: source-bound default Faker formatter domains; retained $workspace\n";
