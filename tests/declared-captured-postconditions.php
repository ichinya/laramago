<?php
declare(strict_types=1);
// A standalone genuine native matrix; declarations are copied as source and never loaded.
$package=str_replace('\\','/',dirname(__DIR__));$fixture=__DIR__.'/fixtures/declared-captured-postconditions';
$root=$package.'/var/compatibility-declared-captured-postconditions root with spaces '.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/cases');
require $package.'/vendor/autoload.php';$sourceChecks=require $fixture.'/source-checks.php';
if(count($sourceChecks)!==36||in_array(false,$sourceChecks,true)) { throw new RuntimeException('All closed source grammar controls are required.'); }
$catalogue=json_decode(file_get_contents($fixture.'/catalogue.json'),true,flags:JSON_THROW_ON_ERROR)['caseCatalogue'];$held=[];
foreach(['contracts.php',...array_keys($catalogue)] as $relative) {
    $bytes=str_replace("\r\n","\n",file_get_contents($fixture.'/'.$relative));file_put_contents($root.'/'.$relative,$bytes);$held[$root.'/'.$relative]=hash('sha256',$bytes);
}
file_put_contents($root.'/manifest.json',json_encode(['caseCatalogue'=>$catalogue,'heldHashes'=>$held],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
file_put_contents($root.'/composer.json','{}');
$json=static fn(string $file,mixed $value)=>file_put_contents($file,json_encode($value,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$registry=file_get_contents($package.'/bin/laramago-worker.php');$marker='    analyzerPlugins: [';
if(substr_count($registry,$marker)!==1) { throw new RuntimeException('One actual current registry insertion point is required.'); }
$registration='        new \\Ichinya\\Laramago\\Analyzer\\DeclaredValuePostconditionPlugin($projectRoot),'."\n".'        new \\Ichinya\\Laramago\\Analyzer\\CapturedArrayPostconditionPlugin($projectRoot),';
$observer='        new \\Ichinya\\Laramago\\Tests\\DeclaredCapturedWarningObserverPlugin($projectRoot, $argv[4], \'full-before\'),';
$beforeRegistry=$registry;
foreach(explode("\n",$registration) as $line) {
    if(substr_count($beforeRegistry,$line)!==1) { throw new RuntimeException('Exactly one genuine new issue-filter plugin registration is required.'); }
    $beforeRegistry=str_replace($line,'',$beforeRegistry);
}
file_put_contents($root.'/full-before-worker.php',str_replace($marker,$marker."\n".$observer,$beforeRegistry));
file_put_contents($root.'/full-after-worker.php',$registry);
$worker=$fixture.'/worker.php';$modes=['native','observe','compatibility','compatibility-three','guards-native','guards','full-before','full-after','full-after-three'];
foreach($modes as $mode) {
    $mapped=match($mode) { 'compatibility-three'=>'compatibility','full-after-three'=>'full-after','guards-native'=>'native',default=>$mode };
    $arguments=[PHP_BINARY,'-d','memory_limit=512M','-d','opcache.enable_cli=0',$worker,$package.'/vendor/autoload.php',$root,$mapped,$root,str_starts_with($mode,'full-')?$root.'/'.($mode==='full-before'?'full-before':'full-after').'-worker.php':''];
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','source'=>['paths'=>str_starts_with($mode,'guards')?['cases/I01.php','cases/F01.php','cases/F11.php','cases/F12.php','cases/A01.php','cases/K01.php']:['cases'],'includes'=>['contracts.php']],
        'extension-hosts'=>['remaining-invented-warnings'=>['enabled'=>true,'command'=>$arguments,'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];
    if(str_starts_with($mode,'guards')) { $config['threads']=1; }$json($root.'/'.$mode.'.config.json',$config);
}
foreach(glob($fixture.'/*.php') as $file) { $held[$file]=hash_file('sha256',$file); }
foreach([$root.'/manifest.json',$root.'/full-before-worker.php',$root.'/full-after-worker.php',...array_map(static fn(string $mode):string=>$root.'/'.$mode.'.config.json',$modes)] as $file) { $held[$file]=hash_file('sha256',$file); }
$json($root.'/matrix-source-prepared.json',['sourceOnly'=>true,'analyzerStarts'=>0,'cases'=>count($catalogue),'runtimeCandidateNativeAccepted'=>false,'heldHashes'=>$held]);

$inventory=static function()use($package):array {
    $p=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($p)) { throw new RuntimeException('Cannot capture current public source.'); }$bytes=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0||$err!=='') { throw new RuntimeException('Public inventory failed.'); }$hashes=[];
    foreach(array_unique(array_filter(explode("\0",$bytes))) as $file) { $hashes[$file]=hash_file('sha256',$package.'/'.$file); }ksort($hashes);return $hashes;
};$public=$inventory();
$assert=static function()use($public,$inventory,$held):void { foreach($held as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Held source changed.'); } }if($public!==$inventory()) { throw new RuntimeException('Current public source changed.'); } };
$binary=getenv('MAGO_BINARY')?:$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');if(!is_file($binary)) { $binary=$package.'/vendor/bin/mago'; }$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];$reports=$closed=[];
$signature=static function(array $issues):array { $keys=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($keys);return $keys; };
$selectedNative=[];
foreach($modes as $mode) {
    if(file_exists($root.'/'.$mode.'.json')) { throw new RuntimeException('Preserve earlier native outputs; choose a fresh fixture root.'); }$assert();$raw=$root.'/'.$mode.'.json';$err=$root.'/'.$mode.'.stderr.log';
    $p=proc_open([...$command,'--workspace',$root,'--config',$root.'/'.$mode.'.config.json','analyze','--reporting-format=json'],[1=>['file',$raw,'w'],2=>['file',$err,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($p)) { throw new RuntimeException('Cannot start the native fixture job.'); }$exit=proc_close($p);$stderr=file_get_contents($err);
    $closed[$mode]=['closed'=>true,'exitCode'=>$exit,'rawSha256'=>hash_file('sha256',$raw),'stderrSha256'=>hash_file('sha256',$err),'hostWorkers'=>str_ends_with($mode,'three')?3:1,'engineThreads'=>str_starts_with($mode,'guards')?1:'default','workerMemoryLimit'=>'512M','requestTimeoutMs'=>120000];$json($root.'/'.$mode.'.closed-receipt.json',$closed[$mode]);
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|invalid frame|protocol error|panicked|fallback|fatal|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i',$stderr)) { throw new RuntimeException('Native operational failure; closed outputs preserved: '.$mode); }
    $report=json_decode(ltrim(file_get_contents($raw),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);if(!is_array($report['issues']??null)) { throw new RuntimeException('Complete native issues required.'); }$reports[$mode]=$report['issues'];$assert();
    if($mode==='observe') {
        // Fail before the remaining stages if a selected positive is absent or its
        // genuine completed native certificate refuses. No diagnostic DTO is invented.
        $observationLines=file($root.'/observe-issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        $observationEvents=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),$observationLines);
        if($signature($reports['native'])!==$signature($reports['observe'])) { throw new RuntimeException('Always-Keep changed complete native issues before the matrix.'); }
        foreach($catalogue as $file=>$case) {
            if(!$case['candidate']) { continue; }$selected=[];
            foreach($reports['native'] as $issue) {
                foreach($issue['annotations'] as $annotation) {
                    if($annotation['kind']==='Primary'&&str_replace('\\','/',$annotation['span']['file_id']['name'])===$file
                        &&[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']]===$case['targetPrimarySpan']
                        &&$issue['level']==='Warning'&&$issue['code']===$case['targetCode']) { $selected[]=$issue; }
                }
            }
            if(count($selected)!==1) { throw new RuntimeException('One genuine selected positive Warning required before the matrix: '.$file); }
            $selectedNative[]=$selected[0];
            $admitted=array_filter($observationEvents,static function(array $event)use($root,$file,$case):bool {
                $path=str_replace('\\','/',$event['file']);$sdk=$event['completeSdkIssue'];$annotations=$sdk['annotations'];
                return ($path===$file||str_ends_with($path,'/'.$file))&&$event['candidateDecision']==='Remove'&&$sdk['level']==='Warning'
                    &&$sdk['code']===$case['targetCode']&&count($annotations)===1&&$annotations[0]['kind']==='Primary'
                    &&[$annotations[0]['span']['start'],$annotations[0]['span']['end']]===$case['targetPrimarySpan']
                    &&$event['sourceSha256']===hash_file('sha256',$root.'/'.$file);
            });
            if(count($admitted)!==1) { throw new RuntimeException('Genuine selected native admission refused before the matrix: '.$file); }
        }
    }
    if($mode==='compatibility-three') {
        $selectedKeys=$signature($selectedNative);
        $expectedIsolated=array_values(array_filter($reports['native'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$selectedKeys,true)));
        if($signature($expectedIsolated)!==$signature($reports['compatibility'])||$signature($reports['compatibility'])!==$signature($reports['compatibility-three'])) {
            throw new RuntimeException('Isolated complete native records or one/three transport differ before controls.');
        }
    }
    if($mode==='guards'&&$signature($reports['guards-native'])!==$signature($reports['guards'])) { throw new RuntimeException('Genuine controls changed a whole native record before full registry jobs.'); }
}
if($signature($reports['native'])!==$signature($reports['observe'])) { throw new RuntimeException('Always-Keep changed complete native issues.'); }
$primary=static function(array $issue):array { foreach($issue['annotations'] as $annotation) { if($annotation['kind']==='Primary') { return $annotation; } }throw new RuntimeException('A genuine Primary annotation is required.'); };
$fileOf=static fn(array $issue):string=>str_replace('\\','/',$primary($issue)['span']['file_id']['name']);
$removed=$cases=$roles=[];
foreach($catalogue as $file=>$case) {
    $before=array_values(array_filter($reports['native'],static fn(array $issue):bool=>$fileOf($issue)===$file));
    $after=array_values(array_filter($reports['compatibility'],static fn(array $issue):bool=>$fileOf($issue)===$file));
    if(!in_array('Error',array_column($before,'level'),true)) { throw new RuntimeException('Unrelated genuine Error is vacuous: '.$file); }
    $targets=$case['candidate']?array_values(array_filter($before,static fn(array $issue):bool=>$issue['level']==='Warning'&&$issue['code']===$case['targetCode']
        &&[$primary($issue)['span']['start']['offset'],$primary($issue)['span']['end']['offset']]===$case['targetPrimarySpan'])):[];
    if($case['candidate']&&$targets===[]) { throw new RuntimeException('Positive genuine Warning is vacuous: '.$file); }
    $targetKeys=$signature($targets);$expected=array_values(array_filter($before,static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$targetKeys,true)));
    if($signature($expected)!==$signature($after)||!in_array('Error',array_column($expected,'level'),true)) { throw new RuntimeException('Target or unrelated complete issue changed: '.$file); }
    if($targets!==[]) { $roles[$case['role']]=true; }$removed=[...$removed,...$targets];$cases[$file]=['candidate'=>$case['candidate'],'exactTargets'=>count($targets),'allUnrelatedWholeIssuesKept'=>true];
}
if(count($roles)!==4) { throw new RuntimeException('Every warning role requires genuine native positive coverage.'); }
$removedKeys=$signature($removed);$expected=array_values(array_filter($reports['native'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$removedKeys,true)));
if($signature($expected)!==$signature($reports['compatibility'])||$signature($reports['compatibility'])!==$signature($reports['compatibility-three'])) { throw new RuntimeException('Isolated one/three whole native records differ.'); }
if($signature($reports['guards-native'])!==$signature($reports['guards'])) { throw new RuntimeException('Genuine controls changed a whole native record.'); }
$controlCount=0;foreach(['identity','factory','factory-inherited','factory-uninitialized','flag','key'] as $role) { $control=json_decode(file_get_contents($root.'/native-controls-'.$role.'.json'),true,flags:JSON_THROW_ON_ERROR);
    if(count($control['checks'])<25||in_array(false,$control['checks'],true)||$control['actualCacheVariantsChanged']===[]||count($control['actualDeclarationSourceChanges']??[])<2) { throw new RuntimeException('Missing genuine controls: '.$role); }$controlCount+=count($control['checks']); }
$absoluteFile=static function(string $file)use($root):string {
    $file=str_replace('\\','/',$file);if(str_starts_with($file,'//?/')) { $file=substr($file,4); }
    if(!str_starts_with($file,'/')&&preg_match('~^[A-Za-z]:/~',$file)!==1) { $file=$root.'/'.$file; }
    if(in_array('..',explode('/',$file),true)||in_array('.',explode('/',$file),true)) { throw new RuntimeException('Unclosed native source file identity.'); }
    return PHP_OS_FAMILY==='Windows'?strtolower($file):$file;
};
$events=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($root.'/full-before-issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));$fullRemoved=[];
foreach($events as $event) {
    if($event['candidateDecision']!=='Remove') { continue; }$sdk=$event['completeSdkIssue'];$matches=[];
    foreach($reports['full-before'] as $issue) {
        if($issue['level']!==$sdk['level']||$issue['code']!==$sdk['code']||$issue['message']!==$sdk['message']||($issue['notes']??[])!==$sdk['notes']||($issue['help']??null)!==$sdk['help']
            ||($issue['link']??null)!==$sdk['link']||($issue['edits']??[])!==[]||$sdk['edits']!==[]||count($issue['annotations'])!==count($sdk['annotations'])) { continue; }
        $bound=true;foreach($issue['annotations'] as $number=>$annotation) { $actual=$sdk['annotations'][$number];
            $path=str_replace('\\','/',$annotation['span']['file_id']['path']);if(str_starts_with($path,'//?/')) { $path=substr($path,4); }
            if($annotation['kind']!==$actual['kind']||($annotation['message']??null)!==$actual['message']
                ||[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']]!==[$actual['span']['start'],$actual['span']['end']]
                ||$absoluteFile($path)!==$absoluteFile($actual['file']??$event['file'])) { $bound=false;break; }
        }if($bound) { $matches[]=$issue; }
    }
    if($matches===[]) { continue; }$eventFile=str_replace('\\','/',$event['file']);if(str_starts_with($eventFile,'//?/')) { $eventFile=substr($eventFile,4); }
    if(!str_starts_with($eventFile,'/')&&preg_match('~^[A-Za-z]:/~',$eventFile)!==1) { $eventFile=$root.'/'.$eventFile; }
    if(count($matches)!==1||hash_file('sha256',$eventFile)!==$event['sourceSha256']) { throw new RuntimeException('Full candidate needs one source-bound complete native issue.'); }
    $fullRemoved[]=json_encode($matches[0],JSON_THROW_ON_ERROR);
}
if($fullRemoved===[]||count($fullRemoved)!==count(array_unique($fullRemoved))) { throw new RuntimeException('Full native positive set is vacuous or duplicate.'); }
$expected=array_values(array_filter($reports['full-before'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$fullRemoved,true)));
if($signature($expected)!==$signature($reports['full-after'])||$signature($reports['full-after'])!==$signature($reports['full-after-three'])) { throw new RuntimeException('Full registry changed unrelated complete records or one/three transport differs.'); }
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file) { if($file->isFile()&&str_contains($file->getFilename(),'executed')) { throw new RuntimeException('A fixture declaration body executed.'); } }$assert();
$json($root.'/accepted-result.json',['sourceCases'=>count($catalogue),'isolatedExactWarningsRemoved'=>count($removed),'fullExactWarningsRemoved'=>count($fullRemoved),'genuineNativeControls'=>$controlCount,
    'everyErrorAndOtherWholeIssueKept'=>true,'oneThreeHostWorkersMatch'=>true,'currentPublicHashes'=>$public,'sourceHashesUnchanged'=>true,'fixtureBodiesExecuted'=>false,'positiveNativeDTOsFabricated'=>false,'caseResults'=>$cases,'closedReceipts'=>$closed]);
echo 'PASS '.count($catalogue).' cases, '.count($removed).' isolated/'.count($fullRemoved).' full exact Warnings, '.$controlCount." genuine controls.\nEvidence: $root\n";
