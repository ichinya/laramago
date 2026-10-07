<?php
declare(strict_types=1);
// Root-owned native gate. Fixtures remain source material and are never included.
$option=static function(string $name)use($argv):?string { foreach($argv as $argument) { if(str_starts_with($argument,$name.'=')) { return substr($argument,strlen($name)+1); } }return null; };
$package=str_replace('\\','/',$option('--package-root')??dirname(__DIR__));
$candidate=str_replace('\\','/',$option('--candidate-root')??$package);
$workspace=str_replace('\\','/',$option('--workspace')??sys_get_temp_dir().'/laramago renamed parameters '.bin2hex(random_bytes(8)));
$prepareOnly=in_array('--prepare-only',$argv,true);$data=__DIR__.'/fixtures/analysis';
require $package.'/vendor/autoload.php';
if(file_exists($workspace)) { throw new RuntimeException('Preserve existing gate evidence; choose a fresh fixture workspace.'); }mkdir($workspace,0o777,true);
$write=static function(string $file,string $bytes):void { if(!is_dir(dirname($file))) { mkdir(dirname($file),0o777,true); }file_put_contents($file,$bytes); };
$catalogue=json_decode(file_get_contents($data.'/renamed-parameter-cases.json'),true,flags:JSON_THROW_ON_ERROR);
$bytes=file_get_contents($data.'/renamed-parameter-cases.php.stub');
if(hash('sha256',$bytes)!==$catalogue['sourceSha256']) { throw new RuntimeException('Invented fixture source hash changed.'); }
// Parsing validates source only. No fixture declarations are loaded into PHP.
(new PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($bytes);
$write($workspace.'/cases.php',$bytes);
$write($workspace.'/bootstrap.php','<?php file_put_contents(__DIR__."/bootstrap-executed","unexpected"); throw new RuntimeException("Fixture bootstrap must remain unused.");');
$write($workspace.'/dependency tree/autoload.php','<?php file_put_contents(__DIR__."/autoload-executed","unexpected"); throw new RuntimeException("Fixture autoload must remain unused.");');
$write($workspace.'/composer.json',json_encode(['config'=>['vendor-dir'=>'dependency tree'],'autoload'=>['files'=>['bootstrap.php']]],JSON_THROW_ON_ERROR));
$held=[];foreach(['cases.php','bootstrap.php','dependency tree/autoload.php','composer.json'] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
$write($workspace.'/prepared.json',json_encode(['sourceOnly'=>true,'analyzerStarts'=>0,'fixtureBodiesExecuted'=>false,'applicationBodiesExecuted'=>false,'held'=>$held],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if($prepareOnly) { echo "Prepared invented fixture sources; no analyzer or fixture body executed.\n";exit; }
$registry=file_get_contents($package.'/bin/laramago-worker.php');$marker="    analyzerPlugins: [";
$registration='        new \\Ichinya\\Laramago\\Analyzer\\RenamedParameterAdvisoryFilter($projectRoot),';
if(substr_count($registry,$marker)!==1||substr_count($registry,$registration)>1) { throw new RuntimeException('Current production registry does not have one bounded insertion point.'); }
$without=str_replace($registration,'',$registry);
$before=str_replace($marker,$marker."\n".'        new \\Ichinya\\Laramago\\Tests\\RenamedParameterTestPlugin($projectRoot, \'full-before\'),',$without);
// An integrated after gate uses the exact production registry. An ignored candidate gate adds only the real candidate.
$after=str_contains($registry,$registration)?$registry:str_replace($marker,$marker."\n".$registration,$registry);
$write($workspace.'/full-before-worker.php',$before);$write($workspace.'/full-after-worker.php',$after);
$worker=str_replace('\\','/',__DIR__.'/fixtures/analysis/renamed-parameter-worker.php');
$binary=$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');if(!is_file($binary)) { $binary=$package.'/vendor/bin/mago'; }
$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];
$inventory=static function()use($package):array {
    $process=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot capture public source inventory.'); }
    $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$errors!=='') { throw new RuntimeException('Public source inventory capture failed.'); }
    $hashes=[];foreach(array_unique(array_filter(explode("\0",$output))) as $file) { $hashes[$file]=hash_file('sha256',$package.'/'.$file); }ksort($hashes);return $hashes;
};
$publicBefore=$inventory();$candidateBefore=[];
foreach([$candidate.'/src/Analyzer/RenamedParameterAdvisoryFilter.php',__FILE__,$worker,$data.'/renamed-parameter-controls.php',$data.'/renamed-parameter-cases.json'] as $path) { $candidateBefore[$path]=hash_file('sha256',$path); }
$assertSources=static function()use($held,$candidateBefore,$inventory,$publicBefore):void {
    foreach($held+$candidateBefore as $path=>$hash) { if(hash_file('sha256',$path)!==$hash) { throw new RuntimeException('Source changed during the native gate.'); } }
    if($inventory()!==$publicBefore) { throw new RuntimeException('Public inventory/source changed during the native gate.'); }
};
$reports=$receipts=[];
foreach(['native','observe','compatibility','compatibility-three','guards-native','guards','full-before','full-after','full-after-three'] as $mode) {
    $workerMode=match($mode) { 'compatibility-three'=>'compatibility','guards-native'=>'native','full-after-three'=>'full-after',default=>$mode };
    $arguments=[PHP_BINARY,'-d','opcache.enable_cli=0',$worker,$package.'/vendor/autoload.php',$workspace,$workerMode,$package,$candidate];
    if(str_starts_with($mode,'full-')) { $arguments[]=$workspace.'/'.($mode==='full-before'?'full-before':'full-after').'-worker.php'; }
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','threads'=>1,'source'=>['paths'=>['cases.php']],
        'extension-hosts'=>['test'=>['command'=>$arguments,'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];
    $configPath=$workspace.'/'.$mode.'.config.json';$write($configPath,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $raw=$workspace.'/'.$mode.'.json';$stderrFile=$workspace.'/'.$mode.'.stderr.log';
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configPath,'analyze','--reporting-format=json'],[1=>['file',$raw,'w'],2=>['file',$stderrFile,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot start the root-owned genuine native gate.'); }
    $exit=proc_close($process);$stderr=file_get_contents($stderrFile);
    $receipt=['mode'=>$mode,'closed'=>true,'exitCode'=>$exit,'rawSha256'=>hash_file('sha256',$raw),'stderrSha256'=>hash_file('sha256',$stderrFile),'engineThreads'=>1,'hostWorkers'=>str_ends_with($mode,'three')?3:1,'requestTimeoutMs'=>120000];
    $receipts[$mode]=$receipt;$write($workspace.'/'.$mode.'.closed-receipt.json',json_encode($receipt,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|falling back|hook[^\r\n]*failed|fatal|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i',$stderr)) { throw new RuntimeException('Operational native failure; preserved raw receipt: '.$mode); }
    $report=json_decode(ltrim(file_get_contents($raw),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
    if(!isset($report['issues'])||!is_array($report['issues'])) { throw new RuntimeException('Missing genuine whole native report.'); }
    $reports[$mode]=$report['issues'];$assertSources();
}
$signature=static function(array $issues):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows; };
$primary=static function(array $issue):array { foreach($issue['annotations'] as $annotation) { if($annotation['kind']==='Primary') { return $annotation; } }throw new RuntimeException('Missing actual Primary.'); };
$issueFile=static fn(array $issue):string=>$primary($issue)['span']['file_id']['name'];
$span=static fn(array $issue):array=>[$primary($issue)['span']['start']['offset'],$primary($issue)['span']['end']['offset']];
if($signature($reports['native'])!==$signature($reports['observe'])) { throw new RuntimeException('Always-Keep observation changed a complete native record.'); }
$removed=[];
foreach($catalogue['positiveClasses'] as $name=>$method) {
    $selected=array_values(array_filter($reports['native'],static fn(array $issue):bool=>$issueFile($issue)==='cases.php'&&$issue['level']==='Warning'&&$issue['code']==='incompatible-parameter-name'&&$span($issue)===$catalogue['classes'][$name]['methods'][$method]['nameSpan']));
    if(count($selected)!==1) { throw new RuntimeException('Native positive is vacuous or changed: '.$name); }$removed[]=$selected[0];
}
$removedSignatures=$signature($removed);$expected=array_values(array_filter($reports['native'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$removedSignatures,true)));
if(count($removed)!==4||$signature($expected)!==$signature($reports['compatibility'])||$signature($reports['compatibility'])!==$signature($reports['compatibility-three'])) { throw new RuntimeException('Exact four-warning removal / unrelated records / one-three worker gate failed.'); }
foreach(['mandatoryNativeErrorClasses'=>'classes','mandatoryNativeErrorFunctions'=>'functions'] as $obligation=>$ranges) {
    foreach($catalogue[$obligation] as $name) {
        $range=$ranges==='classes'?$catalogue[$ranges][$name]['span']:$catalogue[$ranges][$name];
        if(array_filter($reports['native'],static fn(array $issue):bool=>$issueFile($issue)==='cases.php'&&$issue['level']==='Error'&&$span($issue)[0]>=$range[0]&&$span($issue)[1]<=$range[1])===[]) { throw new RuntimeException('Native Error source control is vacuous: '.$name); }
    }
}
if(array_filter($reports['native'],static fn(array $issue):bool=>$issue['level']==='Warning'&&$issue['code']==='incompatible-parameter-name'&&$span($issue)===$catalogue['classes']['NamedCallChild']['methods']['handle']['nameSpan'])===[]) { throw new RuntimeException('Unsafe named-call advisory control is vacuous.'); }
if($signature($reports['guards-native'])!==$signature($reports['guards'])) { throw new RuntimeException('Native cache/envelope controls changed whole original records.'); }
$controls=json_decode(file_get_contents($workspace.'/native-controls.json'),true,flags:JSON_THROW_ON_ERROR);
if(count($controls['checks'])!==$catalogue['expectedGenuineControls']||in_array(false,$controls['checks'],true)) { throw new RuntimeException('Missing genuine native envelope/cache/source controls.'); }
$events=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/full-before-issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$fullRemoved=[];$normalize=static fn(string $path):string=>strtolower(str_replace('\\','/',$path));
foreach($events as $event) {
    if($event['candidateDecision']!=='Remove') { continue; }$sdk=$event['completeSdkIssue'];$matches=[];
    if($event['actualCertificate']===null||$event['actualCertificate']['sourceAndNativeDeclarationsBound']!==true) { throw new RuntimeException('Full native candidate is uncertified.'); }
    foreach($reports['full-before'] as $issue) {
        if($issueFile($issue)!==$event['file']||$issue['level']!==$sdk['level']||$issue['code']!==$sdk['code']||$issue['message']!==$sdk['message']||($issue['notes']??[])!==$sdk['notes']||($issue['help']??null)!==$sdk['help']||($issue['link']??null)!==$sdk['link']||($issue['edits']??[])!==$sdk['edits']||count($issue['annotations'])!==count($sdk['annotations'])) { continue; }
        $bound=true;
        foreach($issue['annotations'] as $index=>$annotation) {
            $actual=$sdk['annotations'][$index];$actualFile=$actual['file']??$event['file'];
            if($annotation['kind']!==$actual['kind']||($annotation['message']??null)!==$actual['message']||[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']]!==[$actual['span']['start'],$actual['span']['end']]||$normalize($annotation['span']['file_id']['name'])!==$normalize($actualFile)) { $bound=false;break; }
        }
        if($bound) { $matches[]=$issue; }
    }
    if(count($matches)!==1||hash_file('sha256',$workspace.'/'.$event['file'])!==$event['sourceSha256']) { throw new RuntimeException('Candidate is not bound to one whole current native record.'); }$fullRemoved[]=json_encode($matches[0],JSON_THROW_ON_ERROR);
}
if(count(array_unique($fullRemoved))!==count($fullRemoved)) { throw new RuntimeException('Duplicate full native candidate.'); }
$fullExpected=array_values(array_filter($reports['full-before'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$fullRemoved,true)));
if($signature($fullExpected)!==$signature($reports['full-after'])||$signature($reports['full-after'])!==$signature($reports['full-after-three'])) { throw new RuntimeException('Full registry changed unrelated whole records or differs at three workers.'); }
foreach(['bootstrap-executed','autoload-executed'] as $trap) { if(file_exists($workspace.'/'.$trap)) { throw new RuntimeException('A fixture bootstrap/body executed.'); } }$assertSources();
$write($workspace.'/accepted-result.json',json_encode(['isolatedWarningsRemoved'=>4,'genuineControls'=>count($controls['checks']),'nativeErrorSourceObligations'=>8,'namedCallAdvisoryKept'=>true,'fullNativeWarningsRemoved'=>count($fullRemoved),'oneThreeWorkersMatch'=>true,'everyErrorAndOtherWholeIssueUnchanged'=>true,'nativeDTOsReplaced'=>false,'fixtureBodiesExecuted'=>false,'closedReceipts'=>$receipts],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS renamed parameter advisories: four isolated Warnings, '.count($fullRemoved)." certified full-registry Warnings, 56 genuine controls.\n";
