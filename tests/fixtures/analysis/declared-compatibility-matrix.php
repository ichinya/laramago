<?php
declare(strict_types=1);
// Shared standalone public test runner. Source declarations and consumer autoload are never executed.
$option=static function(string $name)use($argv):?string { foreach($argv as $arg) { if(str_starts_with($arg,$name.'=')) { return substr($arg,strlen($name)+1); } }return null; };
$package=str_replace('\\','/',$option('--package-root')??dirname(__DIR__,3));$candidate=str_replace('\\','/',$option('--candidate-root')??$package);
$workspace=str_replace('\\','/',$option('--workspace')??sys_get_temp_dir().'/laramago '.$family.' declarations '.bin2hex(random_bytes(8)));
require $package.'/vendor/autoload.php';
if(file_exists($workspace)) { throw new RuntimeException('Preserve earlier evidence by choosing a fresh workspace.'); }mkdir($workspace,0777,true);
$names=['coalesce'=>'coalesce-model-properties','collection'=>'nullable-collection-offsets','input'=>'framework-null-flow'];$data=__DIR__.'/'.$names[$family];
$manifest=json_decode(file_get_contents($data.'/manifest.json'),true,flags:JSON_THROW_ON_ERROR);$catalogue=$manifest['caseCatalogue'];$held=[];
foreach($manifest['fixtureFiles'] as $relative=>$record) {
    $bytes=str_replace("\r\n","\n",file_get_contents($data.'/'.$record['dataFile']));if(hash('sha256',$bytes)!==$record['sha256']||str_contains($relative,'..')||str_starts_with($relative,'/')) { throw new RuntimeException('Invented source manifest changed.'); }
    if(str_ends_with($relative,'.php')) { (new PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($bytes); }
    $file=$workspace.'/'.$relative;if(!is_dir(dirname($file))) { mkdir(dirname($file),0777,true); }file_put_contents($file,$bytes);$held[$file]=$record['sha256'];
}
$json=static fn(string $file,mixed $value)=>file_put_contents($file,json_encode($value,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$registration=match($family) {
    'coalesce'=>'        new \\Ichinya\\Laramago\\Analyzer\\CoalesceModelPropertyPlugin($projectRoot),',
    'collection'=>'        new \\Ichinya\\Laramago\\Analyzer\\NullableCollectionOffsetPlugin($projectRoot),',
    'input'=>'        new \\Ichinya\\Laramago\\Analyzer\\GuardedNullFlowIssueFilter($projectRoot),',
};
$registry=file_get_contents($package.'/bin/laramago-worker.php');$marker='    analyzerPlugins: [';
if(substr_count($registry,$marker)!==1||substr_count($registry,$registration)>1) { throw new RuntimeException('One actual production registration and registry insertion point required.'); }
$beforeRegistry=str_replace($registration,'',$registry);
$observer=$family==='input'?'        new \\Ichinya\\Laramago\\Tests\\FrameworkNullFlowTestPlugin($projectRoot, $argv[4], \'full-before\'),':
    '        new \\Ichinya\\Laramago\\Tests\\DeclaredOffsetCoalesceTestPlugin($projectRoot, $argv[4], $argv[7], \'full-before\'),';
$json($workspace.'/prepared.json',['sourceOnly'=>true,'analyzerStarts'=>0,'sourceCases'=>count($catalogue),'fixtureBodiesExecuted'=>false,'heldSourceHashes'=>$held]);
file_put_contents($workspace.'/full-before-worker.php',str_replace($marker,$marker."\n".$observer,$beforeRegistry));
$afterRegistry=str_contains($registry,$registration)?$registry:str_replace($marker,$marker."\n".$registration,$registry);
file_put_contents($workspace.'/full-after-worker.php',$afterRegistry);
if(in_array('--source-only',$argv,true)) { echo 'Prepared '.count($catalogue)." standalone source cases; native admission remains pending.\nEvidence: $workspace\n";return; }
$worker=__DIR__.($family==='input'?'/framework-null-flow-worker.php':'/declared-offset-coalesce-worker.php');
$inventory=static function()use($package):array {
    $p=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);if(!is_resource($p)) { throw new RuntimeException('Cannot read public source inventory.'); }
    $bytes=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||$stderr!=='') { throw new RuntimeException('Public inventory failed.'); }
    $rows=[];foreach(array_unique(array_filter(explode("\0",$bytes))) as $file) { $rows[$file]=hash_file('sha256',$package.'/'.$file); }ksort($rows);return $rows;
};
$public=$inventory();$candidateFiles=[];
foreach(['src','tests'] as $directory) { foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($candidate.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file) {
    if($file->isFile()) { $candidateFiles[str_replace('\\','/',$file->getPathname())]=hash_file('sha256',$file->getPathname()); }
} }
$assert=static function()use($held,$candidateFiles,$public,$inventory):void { foreach($held+$candidateFiles as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Held source changed during native matrix.'); } }if($public!==$inventory()) { throw new RuntimeException('Public bytes changed during native matrix.'); } };
$binary=getenv('MAGO_BINARY')?:$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');if(!is_file($binary)) { $binary=$package.'/vendor/bin/mago'; }$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];$reports=$closed=[];
$modes=in_array('--observe-only',$argv,true)?['native','observe']:($family==='input'?['native','observe','compatibility','compatibility-three','guards-native','guards','full-before','full-after','full-after-three']:
    ['native','observe','compatibility','compatibility-three','single-native','single-compatibility','guards','full-before','full-after','full-after-three']);
$includes=['contracts.php'];if($family==='input') { foreach(array_keys($manifest['fixtureFiles']) as $file) { if(str_starts_with($file,'packages/')&&str_ends_with($file,'.php')&&!str_contains($file,'composer')) { $includes[]=$file; } } }
foreach($modes as $mode) {
    $mapped=match($mode) { 'compatibility-three','single-compatibility'=>'compatibility','guards-native','single-native'=>'native','full-after-three'=>'full-after',default=>$mode };
    $full=str_starts_with($mode,'full-');$registryFile=$full?$workspace.'/'.($mode==='full-before'?'full-before':'full-after').'-worker.php':'';
    $args=[PHP_BINARY,'-d','opcache.enable_cli=0','-d','memory_limit=512M',$worker,$package.'/vendor/autoload.php',$workspace,$mapped,$workspace,$registryFile,$candidate,$family];
    $paths=$family==='input'?(str_starts_with($mode,'guards')?['cases/C01.php','cases/R01.php','cases/H01.php']:['cases']):
        (str_starts_with($mode,'single-')||$mode==='guards'?['focus.php']:['cases','focus.php']);
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','source'=>['paths'=>$paths,'includes'=>$includes],
        'extension-hosts'=>['probe'=>['command'=>$args,'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];
    if(str_starts_with($mode,'guards')) { $config['threads']=1; }$json($workspace.'/'.$mode.'.config.json',$config);
    $raw=$workspace.'/'.$mode.'.json';$err=$workspace.'/'.$mode.'.stderr.log';$assert();
    $p=proc_open([...$command,'--workspace',$workspace,'--config',$workspace.'/'.$mode.'.config.json','analyze','--reporting-format=json'],[1=>['file',$raw,'w'],2=>['file',$err,'w']],$pipes,$package,null,['bypass_shell'=>true]);if(!is_resource($p)) { throw new RuntimeException('Cannot start native fixture job.'); }$exit=proc_close($p);$stderr=file_get_contents($err);
    $closed[$mode]=['closed'=>true,'exitCode'=>$exit,'rawSha256'=>hash_file('sha256',$raw),'stderrSha256'=>hash_file('sha256',$err),'engineThreads'=>str_starts_with($mode,'guards')?1:'default','hostWorkers'=>str_ends_with($mode,'three')?3:1,'requestTimeoutMs'=>120000,'workerMemoryLimit'=>'512M'];$json($workspace.'/'.$mode.'.closed-receipt.json',$closed[$mode]);
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|invalid frame|protocol error|panicked|fallback|falling back|hook[^\r\n]*failed|fatal|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i',$stderr)) { throw new RuntimeException('Native matrix operational failure; closed raw receipts retained: '.$mode); }
    $native=json_decode(ltrim(file_get_contents($raw),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);if(!is_array($native['issues']??null)) { throw new RuntimeException('Missing complete native report.'); }$reports[$mode]=$native['issues'];$assert();
}
$signature=static function(array $issues):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows; };
$primary=static function(array $issue):array { foreach($issue['annotations'] as $annotation) { if($annotation['kind']==='Primary') { return $annotation; } }throw new RuntimeException('Native Primary annotation required.'); };
$fileOf=static fn(array $issue):string=>str_replace('\\','/',$primary($issue)['span']['file_id']['name']);
if($signature($reports['native'])!==$signature($reports['observe'])) { throw new RuntimeException('Always-Keep observer changed a complete native issue.'); }
if(in_array('--observe-only',$argv,true)) { $json($workspace.'/observation-only.json',['wholeKeepIdentity'=>true,'nativeAcceptance'=>false,'closedReceipts'=>$closed]);echo "PASS complete native Always-Keep observation.\nEvidence: $workspace\n";return; }
$removed=$cases=$covered=$roles=[];$code=$family==='coalesce'?'non-documented-property':'invalid-array-index';$level=$family==='coalesce'?'Warning':'Error';
foreach($catalogue as $file=>$case) {
    $before=array_values(array_filter($reports['native'],static fn(array $i):bool=>$fileOf($i)===$file));$after=array_values(array_filter($reports['compatibility'],static fn(array $i):bool=>$fileOf($i)===$file));
    if(!in_array('Error',array_column($before,'level'),true)) { throw new RuntimeException('Unrelated native negative Error is vacuous: '.$case['label']); }
    $positive=$case['candidate'];$targets=$positive?array_values(array_filter($before,static fn(array $i):bool=>$family==='input'?$i['level']==='Error'&&in_array($i['code'],$case['targetCodes'],true):$i['code']===$code&&$i['level']===$level)):[];
    if($positive&&$targets===[]) { if($family!=='input') { throw new RuntimeException('Positive native target is vacuous: '.$case['label']); }$covered[$file]=['label'=>$case['label'],'nativeTargetIssues'=>0]; }
    if($family==='coalesce'&&$positive&&count($targets)!==1) { throw new RuntimeException('Exactly one genuine coalesce Warning required per positive.'); }
    if($family==='input'&&$targets!==[]) { $roles[$case['role']]=true; }
    $targetRows=$signature($targets);$expected=array_values(array_filter($before,static fn(array $i):bool=>!in_array(json_encode($i,JSON_THROW_ON_ERROR),$targetRows,true)));
    if($signature($expected)!==$signature($after)||!in_array('Error',array_column($expected,'level'),true)) { throw new RuntimeException('Target or unrelated complete issue changed: '.$case['label']); }
    $removed=[...$removed,...$targets];$cases[$file]=['label'=>$case['label'],'positive'=>$positive,'exactTargets'=>count($targets),'unrelatedErrorsKept'=>true];
}
if($family==='input'&&!isset($roles['console'],$roles['route'],$roles['header'])) { throw new RuntimeException('Each framework null-flow role needs a genuine native target.'); }
if($family!=='input') {
    $focus=array_values(array_filter($reports['native'],static fn(array $i):bool=>$fileOf($i)==='focus.php'));$targets=array_values(array_filter($focus,static fn(array $i):bool=>$i['code']===$code&&$i['level']===$level));
    if(count($targets)!==1) { throw new RuntimeException('Exactly one genuine focus target required.'); }$removed=[...$removed,...$targets];$rows=$signature($targets);$expected=array_values(array_filter($focus,static fn(array $i):bool=>!in_array(json_encode($i,JSON_THROW_ON_ERROR),$rows,true)));
    if($signature($focus)!==$signature($reports['single-native'])||$signature($expected)!==$signature($reports['single-compatibility'])||$signature($expected)!==$signature($reports['guards'])) { throw new RuntimeException('Single-file/control whole records differ.'); }
}
$rows=$signature($removed);$expected=array_values(array_filter($reports['native'],static fn(array $i):bool=>!in_array(json_encode($i,JSON_THROW_ON_ERROR),$rows,true)));
if($signature($expected)!==$signature($reports['compatibility'])||$signature($reports['compatibility'])!==$signature($reports['compatibility-three'])) { throw new RuntimeException('Isolated one/three-worker complete reports differ.'); }
$controls=0;if($family==='input') {
    if($signature($reports['guards-native'])!==$signature($reports['guards'])) { throw new RuntimeException('Native guard contexts changed a complete report.'); }
    foreach(['console','route','header'] as $role) { $value=json_decode(file_get_contents($workspace.'/native-controls-'.$role.'.json'),true,flags:JSON_THROW_ON_ERROR);if(count($value['checks'])<25||in_array(false,$value['checks'],true)||$value['actualCacheVariantsChanged']===[]) { throw new RuntimeException('Missing genuine role controls: '.$role); }$controls+=count($value['checks']); }
} else { $value=json_decode(file_get_contents($workspace.($family==='coalesce'?'/coalesce-native-controls.json':'/native-controls.json')),true,flags:JSON_THROW_ON_ERROR);if(count($value)<($family==='coalesce'?40:100)||in_array(false,$value,true)) { throw new RuntimeException('Missing genuine native controls.'); }$controls=count($value); }
$events=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/full-before-issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));$fullRemoved=[];$normalize=static fn(string $file):string=>strtolower(str_replace('\\','/',$file));
foreach($events as $event) {
    if($event['candidateDecision']!=='Remove') { continue; }$sdk=$event['completeSdkIssue'];$matches=[];
    if(hash_file('sha256',$workspace.'/'.$event['file'])!==$event['sourceSha256']) { throw new RuntimeException('Full candidate source bytes changed.'); }
    foreach($reports['full-before'] as $issue) {
        if($fileOf($issue)!==$event['file']||$issue['level']!==$sdk['level']||$issue['code']!==$sdk['code']||$issue['message']!==$sdk['message']||($issue['notes']??[])!==$sdk['notes']||($issue['help']??null)!==$sdk['help']||($issue['link']??null)!==$sdk['link']||($issue['edits']??[])!==[]||$sdk['edits']!==[]||count($issue['annotations'])!==count($sdk['annotations'])) { continue; }
        $bound=true;foreach($issue['annotations'] as $n=>$annotation) { $actual=$sdk['annotations'][$n];if($annotation['kind']!==$actual['kind']||($annotation['message']??null)!==$actual['message']||[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']]!==[$actual['span']['start'],$actual['span']['end']]||$normalize($annotation['span']['file_id']['name'])!==$normalize($actual['file']??$event['file'])) { $bound=false;break; } }if($bound) { $matches[]=$issue; }
    }
    if($matches===[]) { continue; }if(count($matches)!==1) { throw new RuntimeException('Full candidate needs one complete genuine native issue.'); }$fullRemoved[]=json_encode($matches[0],JSON_THROW_ON_ERROR);
}
if($fullRemoved===[]||count($fullRemoved)!==count(array_unique($fullRemoved))) { throw new RuntimeException('Full-registry native positives are vacuous or duplicate.'); }
$expected=array_values(array_filter($reports['full-before'],static fn(array $i):bool=>!in_array(json_encode($i,JSON_THROW_ON_ERROR),$fullRemoved,true)));
if($signature($expected)!==$signature($reports['full-after'])||$signature($reports['full-after'])!==$signature($reports['full-after-three'])) { throw new RuntimeException('Full registry changed unrelated complete records or worker transport differs.'); }
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace,FilesystemIterator::SKIP_DOTS)) as $file) { if($file->isFile()&&str_contains($file->getFilename(),'executed')) { throw new RuntimeException('A declaration, application or fixture body executed.'); } }$assert();
$json($workspace.'/accepted-result.json',['sourceCases'=>count($catalogue),'isolatedExactIssuesRemoved'=>count($removed),'fullExactIssuesRemoved'=>count($fullRemoved),'genuineNativeControls'=>$controls,'nativeCoveredCases'=>$covered,
    'everyOtherWholeIssueUnchanged'=>true,'oneThreeHostWorkersMatch'=>true,'currentPublicHashes'=>$public,'sourceInventoryUnchanged'=>true,'fixtureBodiesExecuted'=>false,'newProviderRegistrations'=>0,'positiveNativeDTOsFabricated'=>false,'caseResults'=>$cases,'closedReceipts'=>$closed]);
echo 'PASS '.$family.': '.count($catalogue).' cases, '.count($removed).' isolated/'.count($fullRemoved).' full exact issues, '.$controls." genuine controls.\nEvidence: $workspace\n";
