<?php
declare(strict_types=1);
// Root owns the serialized native lease. Fixture declarations and application autoload are never executed.
$option=static function(string $name)use($argv):?string { foreach($argv as $argument) { if(str_starts_with($argument,$name.'=')) { return substr($argument,strlen($name)+1); } }return null; };
$package=str_replace('\\','/',$option('--package-root')??dirname(__DIR__));$candidate=str_replace('\\','/',$option('--candidate-root')??$package);
$workspace=str_replace('\\','/',$option('--workspace')??sys_get_temp_dir().'/laramago null flow '.bin2hex(random_bytes(8)));
$run=true;$observeOnly=in_array('--observe-only',$argv,true);$data=__DIR__.'/fixtures/analysis';
require $package.'/vendor/autoload.php';
if(file_exists($workspace)) { throw new RuntimeException('Preserve prior evidence; choose a fresh workspace.'); }mkdir($workspace,0o777,true);
$write=static function(string $file,string $bytes):void { if(!is_dir(dirname($file))) { mkdir(dirname($file),0o777,true); }file_put_contents($file,$bytes); };
$catalogue=json_decode(file_get_contents($data.'/nullable-flow-cases.json'),true,flags:JSON_THROW_ON_ERROR);$bytes=str_replace("\r\n","\n",file_get_contents($data.'/nullable-flow-cases.php.stub'));
if(hash('sha256',$bytes)!==$catalogue['sourceSha256']) { throw new RuntimeException('Invented source hash changed.'); }(new PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($bytes);
$write($workspace.'/cases.php',$bytes);$write($workspace.'/cases.json',json_encode($catalogue,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
$write($workspace.'/bootstrap.php','<?php file_put_contents(__DIR__."/bootstrap-executed","unexpected");throw new RuntimeException("Fixture bootstrap must remain unused.");');
$write($workspace.'/dependency tree/autoload.php','<?php file_put_contents(dirname(__DIR__)."/autoload-executed","unexpected");throw new RuntimeException("Fixture autoload must remain unused.");');
$write($workspace.'/composer.json',json_encode(['config'=>['vendor-dir'=>'dependency tree'],'autoload'=>['files'=>['bootstrap.php']]],JSON_THROW_ON_ERROR));
$held=[];foreach(['cases.php','cases.json','bootstrap.php','dependency tree/autoload.php','composer.json'] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
$write($workspace.'/prepared.json',json_encode(['sourceOnly'=>true,'analyzerStarts'=>0,'fixtureBodiesExecuted'=>false,'held'=>$held],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if(!$run) { echo "Prepared source-only null-flow fixtures; no analyzer or fixture body executed.\n";exit; }
$registry=file_get_contents($package.'/bin/laramago-worker.php');$marker='    analyzerPlugins: [';$registration='        new \\Ichinya\\Laramago\\Analyzer\\GuardedNullFlowIssueFilter($projectRoot),';
if(substr_count($registry,$marker)!==1||substr_count($registry,$registration)>1) { throw new RuntimeException('One production registry insertion point is required.'); }
$without=str_replace($registration,'',$registry);
$before=str_replace($marker,$marker."\n".'        new \\Ichinya\\Laramago\\Tests\\NullFlowTestPlugin($projectRoot, \'full-before\'),',$without);
$after=str_contains($registry,$registration)?$registry:str_replace($marker,$marker."\n".$registration,$registry);
$write($workspace.'/full-before-worker.php',$before);$write($workspace.'/full-after-worker.php',$after);
$worker=str_replace('\\','/',$data.'/nullable-flow-worker.php');$binary=$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');if(!is_file($binary)) { $binary=$package.'/vendor/bin/mago'; }
$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];
$inventory=static function()use($package):array {
    $process=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot capture public inventory.'); }$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$errors!=='') { throw new RuntimeException('Public inventory capture failed.'); }$hashes=[];foreach(array_unique(array_filter(explode("\0",$output))) as $file) { $hashes[$file]=hash_file('sha256',$package.'/'.$file); }ksort($hashes);return $hashes;
};
$publicBefore=$inventory();$candidateBefore=[];foreach([$candidate.'/src/Analyzer/GuardedNullFlowIssueFilter.php',$candidate.'/src/Analyzer/StaticAnalysis/SourceNullFacts.php',$candidate.'/src/Analyzer/StaticAnalysis/NullFlowNativeContracts.php',__FILE__,$worker,$data.'/nullable-flow-controls.php',$data.'/nullable-flow-cases.json',$data.'/nullable-flow-cases.php.stub'] as $file) { $candidateBefore[$file]=hash_file('sha256',$file); }
$assertSources=static function()use($held,$candidateBefore,$publicBefore,$inventory):void { foreach($held+$candidateBefore as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Held source changed during native observation.'); } }if($inventory()!==$publicBefore) { throw new RuntimeException('Public source inventory changed during native observation.'); } };
$reports=$receipts=[];$modes=$observeOnly?['native','observe']:['native','observe','compatibility','compatibility-three','guards-native','guards','full-before','full-after','full-after-three'];
foreach($modes as $mode) {
    $workerMode=match($mode) { 'compatibility-three'=>'compatibility','guards-native'=>'native','full-after-three'=>'full-after',default=>$mode };
    $arguments=[PHP_BINARY,'-d','opcache.enable_cli=0',$worker,$package.'/vendor/autoload.php',$workspace,$workerMode,$package,$candidate];if(str_starts_with($mode,'full-')) { $arguments[]=$workspace.'/'.($mode==='full-before'?'full-before':'full-after').'-worker.php'; }
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','threads'=>1,'source'=>['paths'=>['cases.php']],
        'extension-hosts'=>['test'=>['command'=>$arguments,'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];
    $configPath=$workspace.'/'.$mode.'.config.json';$write($configPath,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));$raw=$workspace.'/'.$mode.'.json';$stderrFile=$workspace.'/'.$mode.'.stderr.log';
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configPath,'analyze','--reporting-format=json'],[1=>['file',$raw,'w'],2=>['file',$stderrFile,'w']],$pipes,$package,null,['bypass_shell'=>true]);if(!is_resource($process)) { throw new RuntimeException('Cannot start root-owned native observation.'); }
    $exit=proc_close($process);$stderr=file_get_contents($stderrFile);$receipt=['mode'=>$mode,'closed'=>true,'exitCode'=>$exit,'rawSha256'=>hash_file('sha256',$raw),'stderrSha256'=>hash_file('sha256',$stderrFile),'engineThreads'=>1,'hostWorkers'=>str_ends_with($mode,'three')?3:1,'requestTimeoutMs'=>120000];
    $receipts[$mode]=$receipt;$write($workspace.'/'.$mode.'.closed-receipt.json',json_encode($receipt,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|falling back|hook[^\r\n]*failed|fatal|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i',$stderr)) { throw new RuntimeException('Operational native failure; raw/closed receipt preserved: '.$mode); }
    $report=json_decode(ltrim(file_get_contents($raw),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);if(!isset($report['issues'])||!is_array($report['issues'])) { throw new RuntimeException('Missing genuine native report.'); }$reports[$mode]=$report['issues'];$assertSources();
}
$signature=static function(array $issues):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows; };
$primary=static function(array $issue):array { foreach($issue['annotations'] as $annotation) { if($annotation['kind']==='Primary') { return $annotation; } }throw new RuntimeException('Native Primary missing.'); };
$span=static fn(array $issue):array=>[$primary($issue)['span']['start']['offset'],$primary($issue)['span']['end']['offset']];$issueFile=static fn(array $issue):string=>$primary($issue)['span']['file_id']['name'];
if($signature($reports['native'])!==$signature($reports['observe'])) { throw new RuntimeException('Always-Keep observer changed a whole native record.'); }
if($observeOnly) { $write($workspace.'/observation-only-result.json',json_encode(['nativeIssues'=>count($reports['native']),'wholeKeepIdentity'=>true,'closedReceipts'=>$receipts,'nativeAcceptance'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));echo "Closed source/native observation; full compatibility acceptance remains pending.\n";exit; }
$removed=[];foreach($catalogue['requiredNativePositiveLabels'] as $label) { $case=$catalogue['positives'][$label];
    foreach([$case['code'],...($case['companionNativeCodes']??[])] as $code) {
        $matches=array_values(array_filter($reports['native'],static fn(array $issue):bool=>$issueFile($issue)==='cases.php'&&$issue['level']==='Error'&&$issue['code']===$code&&$span($issue)===$case['span']));
        if(count($matches)!==1) { throw new RuntimeException('Genuine native positive is vacuous or changed: '.$label.' '.$code); }$removed[]=$matches[0];
    }
}
$removeSignatures=$signature($removed);$expected=array_values(array_filter($reports['native'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$removeSignatures,true)));
if(count($removed)!==13||$signature($expected)!==$signature($reports['compatibility'])||$signature($reports['compatibility'])!==$signature($reports['compatibility-three'])) { throw new RuntimeException('Exact thirteen native Errors / all other whole records / one-three workers did not match.'); }
foreach($catalogue['nativeCoveredSourceLabels'] as $label) { $case=$catalogue['positives'][$label];if(array_filter($reports['native'],static fn(array $issue):bool=>$issueFile($issue)==='cases.php'&&$issue['level']==='Error'&&$span($issue)===$case['span'])!==[]) { throw new RuntimeException('A native-covered source case changed: '.$label); } }
foreach($catalogue['mandatoryNativeErrorFunctions'] as $name=>$range) {
    $select=static fn(array $issue):bool=>$issueFile($issue)==='cases.php'&&$issue['level']==='Error'&&$span($issue)[0]>=$range[0]&&$span($issue)[1]<=$range[1];$negative=array_values(array_filter($reports['native'],$select));
    if($negative===[]) { throw new RuntimeException('Genuine source Error control is vacuous: '.$name); }
    if($signature($negative)!==$signature(array_values(array_filter($reports['compatibility'],$select)))) { throw new RuntimeException('Complete genuine negative Errors changed: '.$name); }
}
if($signature($reports['guards-native'])!==$signature($reports['guards'])) { throw new RuntimeException('Genuine cache/envelope controls changed native whole records.'); }
$controls=json_decode(file_get_contents($workspace.'/native-controls.json'),true,flags:JSON_THROW_ON_ERROR);if(count($controls['checks'])<55||count($controls['actualCacheVariantsChanged'])<30||in_array(false,$controls['checks'],true)) { throw new RuntimeException('Genuine controls are missing or failed.'); }
$events=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/full-before-issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));$fullRemoved=[];
$normalize=static fn(string $file):string=>strtolower(str_replace('\\','/',$file));
foreach($events as $event) {
    if($event['candidateDecision']!=='Remove') { continue; }$sdk=$event['completeSdkIssue'];$observation=$event['completedObservation'];$matches=[];
    if(($observation['certificate']['sourceAndNativeDeclarationsBound']??false)!==true||hash_file('sha256',$workspace.'/'.$event['file'])!==$event['sourceSha256']) { throw new RuntimeException('Full candidate has no current genuine source/native certificate.'); }
    foreach($reports['full-before'] as $issue) {
        if($issueFile($issue)!==$event['file']||$issue['level']!==$sdk['level']||$issue['code']!==$sdk['code']||$issue['message']!==$sdk['message']||($issue['notes']??[])!==$sdk['notes']||($issue['help']??null)!==$sdk['help']||($issue['link']??null)!==$sdk['link']||count($issue['annotations'])!==count($sdk['annotations'])) { continue; }
        $bound=true;foreach($issue['annotations'] as $index=>$annotation) { $actual=$sdk['annotations'][$index];if($annotation['kind']!==$actual['kind']||($annotation['message']??null)!==$actual['message']||[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']]!==[$actual['span']['start'],$actual['span']['end']]||$normalize($annotation['span']['file_id']['name'])!==$normalize($actual['file']??$event['file'])) { $bound=false;break; } }
        $edits=$issue['edits']??[];if(count($edits)!==count($sdk['edits'])) { $bound=false; }
        foreach($edits as $editIndex=>$edit) { $actual=$sdk['edits'][$editIndex];if(!is_array($edit)||count($edit)!==2) { $bound=false;break; }[$fileId,$payload]=$edit;$change=$payload['change']??[];
            if($normalize($fileId['name'])!==$normalize($actual['file']??$event['file'])||($payload['safety']??null)!==$actual['safety']||($change['range']??null)!==[$actual['span']['start'],$actual['span']['end']]||($change['new_text']??null)!==array_values(unpack('C*',$actual['newText']))) { $bound=false;break; }
        }
        if($bound) { $matches[]=$issue; }
    }
    if(count($matches)!==1) { throw new RuntimeException('Full candidate is not bound to one complete native issue.'); }$fullRemoved[]=json_encode($matches[0],JSON_THROW_ON_ERROR);
}
if($fullRemoved===[]||count(array_unique($fullRemoved))!==count($fullRemoved)) { throw new RuntimeException('Full native compatibility is vacuous or duplicated.'); }
$fullExpected=array_values(array_filter($reports['full-before'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$fullRemoved,true)));
if($signature($fullExpected)!==$signature($reports['full-after'])||$signature($reports['full-after'])!==$signature($reports['full-after-three'])) { throw new RuntimeException('Full registry altered unrelated complete records or differs at three workers.'); }
foreach(['bootstrap-executed','autoload-executed'] as $trap) { if(file_exists($workspace.'/'.$trap)) { throw new RuntimeException('A fixture bootstrap or body executed.'); } }$assertSources();
$write($workspace.'/accepted-result.json',json_encode(['isolatedNativeErrorsRemoved'=>13,'nativeCoveredSourceCases'=>4,'allocatedOriginalSourceCandidates'=>16,'genuineControls'=>count($controls['checks']),'actualCacheMutationControls'=>count($controls['actualCacheVariantsChanged']),'nativeErrorSourceObligations'=>count($catalogue['mandatoryNativeErrorFunctions']),'fullNativeErrorsRemoved'=>count($fullRemoved),'everyOtherWholeIssueUnchanged'=>true,'oneThreeWorkersMatch'=>true,'positiveNativeDTOsFabricated'=>false,'fixtureBodiesExecuted'=>false,'closedReceipts'=>$receipts],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS guarded null flow: thirteen isolated native Errors, four native-covered source cases, '.count($fullRemoved).' certified full-registry Errors, '.count($controls['checks'])." genuine controls.\n";
