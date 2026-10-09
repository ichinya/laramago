<?php
declare(strict_types=1);
$package=dirname(__DIR__);$fixture=__DIR__.'/fixtures/installed-package-container-bindings';$workspace=$package.'/var/compatibility-installed-package-container-bindings-'.bin2hex(random_bytes(8));mkdir($workspace);
foreach(require $fixture.'/sources.php' as $path=>$bytes){if(!is_dir(dirname($workspace.'/'.$path))){mkdir(dirname($workspace.'/'.$path),recursive:true);}file_put_contents($workspace.'/'.$path,str_replace("\r\n","\n",$bytes));}
require $package.'/tests/fixtures/compatibility-bounded-worker-support.php';$config=require $fixture.'/config.php';
$registrar=file_get_contents($package.'/src/Analyzer/LaravelPlugin.php');$registrar=str_replace('final class LaravelPlugin','final class FixtureLaravelContainerRegistrar',$registrar,$classChanges);
$registrar=str_replace('$containerHelpers = new ContainerHelperProvider($this->projectRoot);','$containerHelpers = new \FortifyControlObserver(new \Example\Fortify\ContainerHelperProvider($this->projectRoot),$GLOBALS[\'fortifyFixtureMode\'],$GLOBALS[\'fortifyFixtureOutput\'],$this->projectRoot);',$registrar,$helperChanges);
if($classChanges!==1||$helperChanges!==1){throw new RuntimeException('Current Laravel helper registration changed.');}$registrarPath=$workspace.'/current-laravel-registrar.php';file_put_contents($registrarPath,$registrar);
// Preserve every existing helper path while removing only the newly installed-package fallback.
$baseline=file_get_contents($package.'/src/Analyzer/ContainerHelperProvider.php');
$baseline=str_replace('final class ContainerHelperProvider','final class FixtureBaselineContainerHelperProvider',$baseline,$baselineClassChanges);
$baseline=str_replace(' ?? ($this->installedPackages ??= new InstalledPackageContainerBindings($this->root))->concrete($context->codebase, $abstract)','',$baseline,$baselineFallbackChanges);
if($baselineClassChanges!==1||$baselineFallbackChanges!==1){throw new RuntimeException('Current installed-package baseline anchor changed.');}
$baselinePath=$workspace.'/current-helper-without-installed-package-fallback.php';file_put_contents($baselinePath,$baseline);
$worker=compatibilityProductionWorker($package,$workspace,$fixture,'FORTIFY_CONTROL_CLASSES_ONLY','FortifyControlPlugin','UnregisteredFortifyCandidate');$bytes=file_get_contents($worker);
$bytes=str_replace('$argv[1]=$draftPackage.\'/vendor/autoload.php\';','$argv[1]=$draftPackage.\'/vendor/autoload.php\';$GLOBALS[\'fortifyFixtureMode\']=$draftMode;$GLOBALS[\'fortifyFixtureOutput\']=$draftOutput;require '.var_export($registrarPath,true).';require '.var_export($baselinePath,true).';',$bytes,$prefixChanges);
$bytes=str_replace('new LaravelPlugin($projectRoot)','new \Ichinya\Laramago\Analyzer\FixtureLaravelContainerRegistrar($projectRoot)',$bytes,$pluginChanges);if($prefixChanges!==1||$pluginChanges!==1){throw new RuntimeException('Current full registry helper wrapper anchor changed.');}file_put_contents($worker,$bytes);
$run=static fn(string $stage,string $mode,int $workers=1):array=>compatibilityNativeGate($package,$workspace,$worker,$config,$stage,$mode,$workers);
$key=static function(array $issue):string{return json_encode($issue,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);};
$multiset=static function(array $issues) use($key):array{$rows=array_map($key,$issues);sort($rows);return $rows;};
$lineRows=static function(array $issues,int $line):array{return array_values(array_filter($issues,static function(array $issue) use($line):bool{foreach($issue['annotations'] as $annotation){if($annotation['kind']==='Primary' && str_replace('\\','/',$annotation['span']['file_id']['name'])==='cases.php' && $annotation['span']['start']['line']+1===$line){return true;}}return false;}));};
$native=$run('always-keep-1','observe');$draft=$run('draft-1','draft');$expected=$native;$removed=[];
foreach([2,3,4,5,6] as $line){$found=array_values(array_filter($lineRows($native,$line),static fn(array $issue):bool=>$issue['code']==='too-many-arguments'));
    if(count($found)!==1){throw new RuntimeException('Missing genuine native package-binding diagnostic at line '.$line);}$removed[]=$found[0];}
$removeKeys=array_map($key,$removed);$expected=array_values(array_filter($expected,static fn(array $issue):bool=>!in_array($key($issue),$removeKeys,true)));
$newErrors=array_values(array_filter($lineRows($draft,4),static fn(array $issue):bool=>$issue['code']==='invalid-argument'));
if(count($newErrors)!==1 || $newErrors[0]['level']!=='Error'){throw new RuntimeException('Concrete bad argument did not produce the intended native Error.');}$expected[]=$newErrors[0];
$concreteArity=array_values(array_filter($lineRows($draft,5),static fn(array $issue):bool=>$issue['code']==='too-many-arguments'));
if(count($concreteArity)!==1 || $concreteArity[0]['level']!=='Error' || $concreteArity[0]['message']!=='Too many arguments provided for method `Laravel\\Fortify\\TwoFactorAuthenticationProvider::generateSecretKey`.'
    || $concreteArity[0]['notes']!==['Expected 1 argument(s), but received 2.'] || $concreteArity[0]['help']!=='Remove the extra argument(s).'
    || count($concreteArity[0]['annotations'])!==2 || $concreteArity[0]['annotations'][0]['span']['start']['offset']!==604 || $concreteArity[0]['annotations'][0]['span']['end']['offset']!==606){throw new RuntimeException('Concrete native arity Error did not identify the actual extra argument.');}$expected[]=$concreteArity[0];
if($multiset($expected)!==$multiset($draft)){throw new RuntimeException('Complete full-registry residual DTOs differ.');}
if($multiset($run('always-keep-3','observe',3))!==$multiset($native) || $multiset($run('draft-3','draft',3))!==$multiset($draft)){throw new RuntimeException('One/three worker parity failed.');}
foreach(['cache-helper-reference','cache-package-class-abstract'] as $control){if($multiset($run($control,$control))!==$multiset($native)){throw new RuntimeException('AlwaysKeep cache control changed native DTOs.');}
    $receipts=array_map(static fn(string $bytes):array=>json_decode($bytes,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/'.$control.'-observer.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
    $controls=array_values(array_filter($receipts,static fn(array $row):bool=>$row['stage']==='real-native-cache-control'&&$row['genuinePositiveBefore']&&$row['slotsChanged']>0&&$row['deferredAfter']&&$row['restoredProof']));
    $helpers=array_values(array_unique(array_column($controls,'helper')));sort($helpers);if($helpers!==['app','resolve']){throw new RuntimeException('Cache control lacked genuine admitted helper roles.');}
}
$composer=$workspace.'/composer.json';$installed=$workspace.'/vendor/composer/installed.json';$provider=$workspace.'/vendor/laravel/fortify/src/FortifyServiceProvider.php';$framework=$workspace.'/vendor/laravel/framework/src/Illuminate/Foundation/PackageManifest.php';$concrete=$workspace.'/vendor/laravel/fortify/src/TwoFactorAuthenticationProvider.php';
$original=[];foreach([$composer,$installed,$provider,$framework,$concrete] as $path){$original[$path]=file_get_contents($path);}
$changes=[
    'discovery-disabled'=>[$composer,static fn(string $bytes):string=>json_encode(['extra'=>['laravel'=>['dont-discover'=>['laravel/fortify']]]],JSON_THROW_ON_ERROR)],
    'discovery-disabled-all'=>[$composer,static fn(string $bytes):string=>json_encode(['extra'=>['laravel'=>['dont-discover'=>['*']]]],JSON_THROW_ON_ERROR)],
    'unknown-binding-catalog'=>[$composer,static fn(string $bytes):string=>json_encode(['extra'=>['laramago'=>['binding-files'=>['bootstrap/missing.php']]]],JSON_THROW_ON_ERROR)],
    'missing-installation'=>[$installed,static fn(string $bytes):string=>'{"packages":[]}'],
    'provider-not-discovered'=>[$installed,static fn(string $bytes):string=>str_replace('Laravel\\\\Fortify\\\\FortifyServiceProvider','FixtureContracts\\\\UnregisteredProvider',$bytes)],
    'changed-package-binding'=>[$provider,static fn(string $bytes):string=>str_replace('new \\Laravel\\Fortify\\TwoFactorAuthenticationProvider(','new \\FixtureContracts\\WrongProvider(',$bytes)],
    'changed-framework-discovery'=>[$framework,static fn(string $bytes):string=>str_replace("return \$this->config('providers');",'return [];',$bytes)],
    'abstract-concrete'=>[$concrete,static fn(string $bytes):string=>str_replace('class TwoFactorAuthenticationProvider','abstract class TwoFactorAuthenticationProvider',$bytes)],
    'incomplete-concrete'=>[$concrete,static fn(string $bytes):string=>str_replace('class TwoFactorAuthenticationProvider','class TwoFactorAuthenticationProvider extends \\FixtureContracts\\AbsentBase',$bytes)],
];
foreach($changes as $name=>[$path,$mutate]){$changed=$mutate($original[$path]);if($changed===$original[$path]){throw new RuntimeException('No-op source control '.$name);}file_put_contents($path,$changed);
    try{$controlNative=$run($name.'-always-keep','observe');$controlDraft=$run($name.'-draft','draft');if($multiset($controlNative)!==$multiset($controlDraft)){throw new RuntimeException('Source control did not preserve native DTOs: '.$name);}}
    finally{file_put_contents($path,$original[$path]);}
    if($multiset($run($name.'-restored','draft'))!==$multiset($draft)){throw new RuntimeException('Source control restoration failed: '.$name);}
}
$bindings=$workspace.'/bootstrap/bindings.php';if(!is_dir(dirname($bindings))){mkdir(dirname($bindings),recursive:true);}$hadBinding=is_file($bindings);$oldBinding=$hadBinding?file_get_contents($bindings):null;
file_put_contents($bindings,'<?php \\app()->singleton(\\Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider::class, static fn (): \\FixtureContracts\\ReplacementProvider => new \\FixtureContracts\\ReplacementProvider());');
file_put_contents($composer,json_encode(['extra'=>['laramago'=>['binding-files'=>['bootstrap/bindings.php']]]],JSON_THROW_ON_ERROR));
try{$overrideNative=$run('configured-override-always-keep','observe');$overrideDraft=$run('configured-override-draft','draft');if($multiset($overrideNative)!==$multiset($overrideDraft)){throw new RuntimeException('Configured binding priority changed native diagnostics.');}$overrideRows=array_map(static fn(string $row):array=>json_decode($row,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/configured-override-always-keep-observer.jsonl',FILE_SKIP_EMPTY_LINES|FILE_IGNORE_NEW_LINES));
$overrideHelpers=[];foreach($overrideRows as $row){if($row['stage']==='genuine-helper'&&($row['proof']['configured']??false)&&$row['candidate']==='fixturecontracts\replacementprovider'&&$row['baseline']===$row['candidate']){$overrideHelpers[]=strtolower(ltrim($row['helper'],chr(92)));}}$overrideHelpers=array_values(array_unique($overrideHelpers));sort($overrideHelpers);if($overrideHelpers!==['app','resolve']){throw new RuntimeException('Configured priority lacked genuine baseline helper roles.');}}
finally{file_put_contents($composer,$original[$composer]);file_put_contents($bindings,$hadBinding?$oldBinding:"<?php\n");}
if($multiset($run('configured-override-restored','draft'))!==$multiset($draft)){throw new RuntimeException('Configured override restoration failed.');}
file_put_contents($workspace.'/gate-result.json',json_encode(['closed'=>true,'inventedFixtureOnly'=>true,'allCurrentProductionPluginsRetained'=>true,'completeResidualDtoParity'=>true,'workers'=>[1,3],'replacedContractArityErrors'=>count($removed),'newNativeBadArgumentErrors'=>count($newErrors),'preservedConcreteArityErrors'=>count($concreteArity),'sourceControls'=>array_keys($changes),'nativeCacheControls'=>2,'configuredBindingPriority'=>true,'privateAcceptance'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'PASS: full registry 1/3, concrete argument/return Errors, discovery/source/override/native cache controls; retained '.$workspace."\n";
