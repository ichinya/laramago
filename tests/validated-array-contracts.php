<?php
declare(strict_types=1);

// Analyze independently invented sources without including fixture or application bodies.
$option=static function(string $name)use($argv):?string { foreach($argv as $argument) { if(str_starts_with($argument,$name.'=')) { return substr($argument,strlen($name)+1); } }return null; };
$package=str_replace('\\','/',$option('--package-root')??dirname(__DIR__));
$workspace=str_replace('\\','/',$option('--workspace')??sys_get_temp_dir().'/laramago validated arrays '.bin2hex(random_bytes(8)));
$prepareOnly=in_array('--prepare-only',$argv,true);
$data=__DIR__.'/fixtures/analysis/validated-array-contracts';
require $package.'/vendor/autoload.php';
if(file_exists($workspace)) { throw new RuntimeException('Preserve existing fixture workspace; choose a fresh path.'); }
mkdir($workspace,0o777,true);
$write=static function(string $path,string $bytes):void { if(!is_dir(dirname($path))) { mkdir(dirname($path),0o777,true); }file_put_contents($path,$bytes); };
$catalogue=json_decode(file_get_contents($data.'/cases.json'),true,flags:JSON_THROW_ON_ERROR);
if(count($catalogue['sources'])!==6||count($catalogue['positiveContracts'])!==3) { throw new RuntimeException('Expected six source files and three positive contracts.'); }
$parser=(new PhpParser\ParserFactory)->createForNewestSupportedVersion();$held=[];
foreach($catalogue['sources'] as $file=>$source) {
    $bytes=file_get_contents($data.'/'.$file.'.stub');
    if(hash('sha256',$bytes)!==$source['sourceSha256']) { throw new RuntimeException('Portable fixture source hash mismatch.'); }
    $nodes=$parser->parse($bytes)??[];$functions=[];
    foreach((new PhpParser\NodeFinder)->findInstanceOf($nodes,PhpParser\Node\Stmt\Function_::class) as $function) { $functions[$function->name->name]=[$function->getStartFilePos(),$function->getEndFilePos()+1]; }
    if($functions!==$source['functionSpans']) { throw new RuntimeException('Portable source declaration spans differ.'); }
    $write($workspace.'/'.$file,$bytes);$held[$workspace.'/'.$file]=$source['sourceSha256'];
}
$write($workspace.'/bootstrap.php','<?php file_put_contents(__DIR__."/bootstrap-executed","unexpected"); throw new RuntimeException("Fixture bootstrap must remain unused.");');
$write($workspace.'/dependency tree/autoload.php','<?php file_put_contents(__DIR__."/autoload-executed","unexpected"); throw new RuntimeException("Fixture autoload must remain unused.");');
$write($workspace.'/composer.json',json_encode(['config'=>['vendor-dir'=>'dependency tree'],'autoload'=>['files'=>['bootstrap.php']]],JSON_THROW_ON_ERROR));
foreach(['bootstrap.php','dependency tree/autoload.php','composer.json'] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
$write($workspace.'/prepared.json',json_encode(['sourceOnly'=>true,'fixtureBodiesExecuted'=>false,'applicationBodiesExecuted'=>false,'analyzerStarts'=>0,'sources'=>$catalogue['sources'],'held'=>$held],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if($prepareOnly) { echo "Prepared six source files; no analyzer or fixture body executed.\n";exit; }

$registry=file_get_contents($package.'/bin/laramago-worker.php');
$registration='        new \\Ichinya\\Laramago\\Analyzer\\ValidatedArrayContractPlugin($projectRoot),';
if(substr_count($registry,$registration)!==1) { throw new RuntimeException('Expected one production array contract plugin registration.'); }
// Baseline keeps the genuine initialization/native callback provider, replacing only its issue decision with Keep.
$before=str_replace($registration,'        new \\Ichinya\\Laramago\\Tests\\ValidatedArrayTestPlugin($projectRoot, \'full-before\'),',$registry);
$write($workspace.'/full-before-worker.php',$before);
$after=$registry;
$write($workspace.'/full-after-worker.php',$after);
$worker=str_replace('\\','/',__DIR__.'/fixtures/analysis/validated-array-contract-worker.php');
$binary=$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');
if(!is_file($binary)) { $binary=$package.'/vendor/bin/mago'; }
$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];
$inventory=static function()use($package):array {
    $process=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot read public source inventory.'); }
    $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$errors!=='') { throw new RuntimeException('Public inventory capture failed.'); }
    $hashes=[];foreach(array_unique(array_filter(explode("\0",$output))) as $file) { $hashes[$file]=hash_file('sha256',$package.'/'.$file); }ksort($hashes);return $hashes;
};
$publicBefore=$inventory();
$assertSources=static function()use($held,$inventory,$publicBefore):void {
    foreach($held as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Fixture source changed during the native gate.'); } }
    if($inventory()!==$publicBefore) { throw new RuntimeException('Public source/inventory changed during the native gate.'); }
};
$reports=[];$runReceipts=[];
foreach(['native','observe','compatibility','compatibility-three','without-provider','without-provider-three','guards-native','guards','guards-three','full-before','full-after','full-after-three'] as $mode) {
    $workerMode=match($mode) { 'compatibility-three'=>'compatibility','without-provider-three'=>'without-provider','guards-three'=>'guards','guards-native'=>'native','full-after-three'=>'full-after',default=>$mode };
    $focus=in_array($mode,['guards-native','guards','guards-three'],true);
    $arguments=[PHP_BINARY,'-d','opcache.enable_cli=0',$worker,$package.'/vendor/autoload.php',$workspace,$workerMode,$package];
    if($mode==='full-before') { $arguments[]=$workspace.'/full-before-worker.php'; }
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','threads'=>1,
        'source'=>['paths'=>$focus?['fixture-cases.php','top-positive.php']:array_keys($catalogue['sources'])],
        'extension-hosts'=>['test'=>['command'=>$arguments,'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];
    $configPath=$workspace.'/'.$mode.'.config.json';$write($configPath,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $raw=$workspace.'/'.$mode.'.json';$stderrFile=$workspace.'/'.$mode.'.stderr.log';
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configPath,'analyze','--reporting-format=json'],[1=>['file',$raw,'w'],2=>['file',$stderrFile,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot start genuine native test.'); }
    $exit=proc_close($process);$stderr=file_get_contents($stderrFile);
    $receipt=['mode'=>$mode,'closed'=>true,'exitCode'=>$exit,'rawSha256'=>hash_file('sha256',$raw),'stderrSha256'=>hash_file('sha256',$stderrFile),'threads'=>1,'hostWorkers'=>str_ends_with($mode,'three')?3:1,'requestTimeoutMs'=>120000];
    $runReceipts[$mode]=$receipt;$write($workspace.'/'.$mode.'.closed-receipt.json',json_encode($receipt,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|falling back|hook[^\r\n]*failed|fatal|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i',$stderr)) { throw new RuntimeException('Operational native gate failure: '.$mode); }
    $report=json_decode(ltrim(file_get_contents($raw),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
    if(!isset($report['issues'])||!is_array($report['issues'])) { throw new RuntimeException('Missing genuine native report.'); }
    $reports[$mode]=$report['issues'];$assertSources();
}
$signature=static function(array $issues):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows; };
$primary=static function(array $issue):array { foreach($issue['annotations'] as $annotation) { if($annotation['kind']==='Primary') { return $annotation; } }throw new RuntimeException('Missing actual Primary.'); };
$file=static fn(array $issue):string=>$primary($issue)['span']['file_id']['name'];
$span=static fn(array $issue):array=>[$primary($issue)['span']['start']['offset'],$primary($issue)['span']['end']['offset']];
if($signature($reports['native'])!==$signature($reports['observe'])) { throw new RuntimeException('Always-Keep observer changed complete native records.'); }
$removed=[];
foreach($catalogue['positiveContracts'] as $name=>$contract) {
    $selected=array_values(array_filter($reports['native'],static fn(array $issue):bool=>$file($issue)===$contract['file']&&$issue['level']==='Error'
        &&($issue['code']==='mixed-array-access'&&in_array($span($issue),$contract['mixedSpans'],true)
            ||$issue['code']==='less-specific-nested-return-statement'&&$span($issue)===$contract['returnSpan'])));
    if(count($selected)!==$contract['expectedNativeRemovals']) { throw new RuntimeException('Native positive is vacuous or changed: '.$name); }
    $removed=[...$removed,...$selected];
}
$excluded=$signature($removed);$expected=array_values(array_filter($reports['native'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$excluded,true)));
if(count($removed)!==15||$signature($expected)!==$signature($reports['compatibility'])||$signature($reports['compatibility'])!==$signature($reports['compatibility-three'])) { throw new RuntimeException('Isolated exact-fifteen-removal or one/three-worker gate failed.'); }
foreach(['without-provider','without-provider-three'] as $mode) { if($signature($expected)!==$signature($reports[$mode])) { throw new RuntimeException('Provider-free genuine issue gate failed: '.$mode); } }
foreach($catalogue['negativeFunctions'] as $name) {
    $scope=$catalogue['sources']['fixture-cases.php']['functionSpans'][$name];
    $selected=array_values(array_filter($reports['native'],static fn(array $issue):bool=>$file($issue)==='fixture-cases.php'&&$issue['level']==='Error'&&$span($issue)[0]>=$scope[0]&&$span($issue)[1]<=$scope[1]));
    if($selected===[]) { throw new RuntimeException('Native source regression is vacuous: '.$name); }
}
foreach($catalogue['negativeFiles'] as $name) {
    if(array_filter($reports['native'],static fn(array $issue):bool=>$file($issue)===$name&&$issue['level']==='Error')===[]) { throw new RuntimeException('Native script regression is vacuous: '.$name); }
}
if($signature($reports['guards-native'])!==$signature($reports['guards'])||$signature($reports['guards-native'])!==$signature($reports['guards-three'])) { throw new RuntimeException('Native one/three-worker controls changed real complete records.'); }
$controlCount=0;
foreach($catalogue['expectedControls'] as $family=>$count) {
    $controlReceiptFiles=glob($workspace.'/native-controls-'.$family.'-*.json');
    if($controlReceiptFiles===[]) { throw new RuntimeException('Missing genuine native control receipts: '.$family); }
    foreach($controlReceiptFiles as $controlReceiptFile) {
        $receipt=json_decode(file_get_contents($controlReceiptFile),true,flags:JSON_THROW_ON_ERROR);
        $expectedCount=$count-($family==='script'?0:7);
        if(count($receipt['checks'])!==$expectedCount||in_array(false,$receipt['checks'],true)) { throw new RuntimeException('Missing genuine native controls: '.$family); }
        if($family!=='script'&&(($receipt['lexicalProof']['providerCacheUsed']??true)!==false||($receipt['lexicalProof']['nativeClosureIdentityClaimed']??true)!==false)) { throw new RuntimeException('Missing lexical authority receipt.'); }
        $controlCount+=count($receipt['checks']);
    }
}
$callbacks=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/observe-callbacks.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
if($callbacks===[]) { throw new RuntimeException('No genuine native callback certificate was observed.'); }
foreach($callbacks as $callback) {
    $certificate=$callback['certificate'];$matches=array_keys(array_filter($catalogue['sources'],static fn(array $source):bool=>$source['sourceSha256']===$certificate['sourceHash']));
    if(count($matches)!==1||$callback['providerResult']!==null||$callback['nativeCallback']===[]||$certificate['nativeIntegerReturn']!==true) { throw new RuntimeException('Genuine callback/source/null-provider certificate missing.'); }
    $sourceBytes=file_get_contents($workspace.'/'.$matches[0]);$range=$certificate['callbackSpan'];
    if(substr($sourceBytes,$range[0],$range[1]-$range[0])!==$callback['nativeInvocation']['arguments'][1]['expression']) { throw new RuntimeException('Native callback expression/source span differs.'); }
}
// Full before receipts certify the exact subset left by other real providers. Full after is the unwrapped production registry.
$events=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/full-before-issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$fullRemoved=[];
foreach($events as $event) {
    if($event['candidateDecision']!=='Remove') { continue; }
    $sdk=$event['completeSdkIssue'];$matches=[];
    foreach($reports['full-before'] as $issue) {
        if($file($issue)!==$event['file']||$issue['level']!==$sdk['level']||$issue['code']!==$sdk['code']||$issue['message']!==$sdk['message']
            ||($issue['notes']??[])!==$sdk['notes']||($issue['help']??null)!==$sdk['help']||($issue['link']??null)!==$sdk['link']
            ||($issue['edits']??[])!==$sdk['edits']||count($issue['annotations'])!==count($sdk['annotations'])) { continue; }
        $bound=true;
        foreach($issue['annotations'] as $index=>$annotation) {
            $actual=$sdk['annotations'][$index];
            if($annotation['kind']!==$actual['kind']||($annotation['message']??null)!==$actual['message']
                ||[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']]!==[$actual['span']['start'],$actual['span']['end']]) { $bound=false;break; }
        }
        if($bound) { $matches[]=$issue; }
    }
    if(count($matches)!==1||hash_file('sha256',$workspace.'/'.$event['file'])!==$event['sourceSha256']) { throw new RuntimeException('Full-registry candidate lacks one complete current native record.'); }
    $fullRemoved[]=json_encode($matches[0],JSON_THROW_ON_ERROR);
}
if(count(array_unique($fullRemoved))!==count($fullRemoved)) { throw new RuntimeException('Duplicate full-registry native removal.'); }
$fullExpected=array_values(array_filter($reports['full-before'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$fullRemoved,true)));
if($signature($fullExpected)!==$signature($reports['full-after'])||$signature($reports['full-after'])!==$signature($reports['full-after-three'])) { throw new RuntimeException('Full-registry unrelated-record or one/three-worker gate failed.'); }
foreach(['bootstrap-executed','autoload-executed'] as $trap) { if(file_exists($workspace.'/'.$trap)) { throw new RuntimeException('A fixture/bootstrap body executed.'); } }
$assertSources();
$write($workspace.'/accepted-result.json',json_encode(['cases'=>6,'positiveContracts'=>3,'isolatedNativeErrorRemovals'=>15,'fullRegistryNativeRemovals'=>count($fullRemoved),
    'negativeSourceObligations'=>18,'genuineControls'=>$controlCount,'genuineCallbacksObservedWithoutAuthority'=>count($callbacks),'nativeTypesReplaced'=>false,'oneThreeWorkersMatch'=>true,'withoutProviderOneThreeWorkersMatch'=>true,
    'allOtherCompleteRecordsUnchanged'=>true,'fixtureBodiesExecuted'=>false,'applicationBodiesExecuted'=>false,'publicSourcesUnchanged'=>true,'closedReceipts'=>$runReceipts],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS validated array contracts: 15 isolated native Errors, '.count($fullRemoved).' certified full-registry Errors, '.$controlCount." genuine controls.\n";
