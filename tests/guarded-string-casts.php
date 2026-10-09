<?php
declare(strict_types=1);

// Independent source fixtures are analyzed, never included. Every positive comes from a genuine native issue.
$option=static function(string $name)use($argv):?string { foreach($argv as $argument) { if(str_starts_with($argument,$name.'=')) { return substr($argument,strlen($name)+1); } }return null; };
$package=str_replace('\\','/',$option('--package-root')??dirname(__DIR__));
$workspace=str_replace('\\','/',$option('--workspace')??sys_get_temp_dir().'/laramago guarded string casts '.bin2hex(random_bytes(8)));
$prepareOnly=in_array('--prepare-only',$argv,true);$data=__DIR__.'/fixtures/guarded-string-casts';
require $package.'/vendor/autoload.php';
if(file_exists($workspace)) { throw new RuntimeException('Preserve the existing fixture directory; select a fresh workspace.'); }
mkdir($workspace,0777,true);
$write=static function(string $path,string $bytes):void { if(!is_dir(dirname($path))) { mkdir(dirname($path),0777,true); }file_put_contents($path,$bytes); };
$catalogue=json_decode(file_get_contents($data.'/cases.json'),true,flags:JSON_THROW_ON_ERROR);
if(count($catalogue['sources'])!==5||count($catalogue['positiveOwners'])!==6) { throw new RuntimeException('Expected five source files and six cast owners.'); }
$held=[];$sites=[];$scopes=[];$finder=new \PhpParser\NodeFinder;$parser=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
foreach($catalogue['sources'] as $file=>$hash) {
    $bytes=str_replace("\r\n","\n",file_get_contents($data.'/source/'.$file.'.stub'));if(hash('sha256',$bytes)!==$hash) { throw new RuntimeException('Guarded cast fixture source hash differs: '.$file); }
    $write($workspace.'/'.$file,$bytes);$held[$workspace.'/'.$file]=$hash;
    $nodes=$parser->parse($bytes)??[];
    foreach($finder->find($nodes,static fn(\PhpParser\Node $node):bool=>$node instanceof \PhpParser\Node\Stmt\Function_||$node instanceof \PhpParser\Node\Stmt\ClassMethod) as $owner) {
        $name=$owner->name->toString();$scopes[$file][$name]=[$owner->getStartFilePos(),$owner->getEndFilePos()+1];
        foreach($finder->findInstanceOf([$owner],\PhpParser\Node\Expr\Cast\String_::class) as $cast) { $sites[$file.':'.$cast->getStartFilePos().':'.($cast->getEndFilePos()+1)]=$name; }
    }
}
$write($workspace.'/bootstrap.php','<?php file_put_contents(__DIR__."/bootstrap-executed","unexpected"); throw new RuntimeException("Fixture bootstrap must never execute.");');
$write($workspace.'/dependency tree/autoload.php','<?php file_put_contents(__DIR__."/autoload-executed","unexpected"); throw new RuntimeException("Fixture autoload must never execute.");');
$write($workspace.'/composer.json',json_encode(['config'=>['vendor-dir'=>'dependency tree'],'autoload'=>['files'=>['bootstrap.php']]],JSON_THROW_ON_ERROR));
foreach(['bootstrap.php','dependency tree/autoload.php','composer.json'] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
$write($workspace.'/prepared.json',json_encode(['sourceOnly'=>true,'analyzerJobsStarted'=>0,'fixtureBodiesExecuted'=>false,'applicationBodiesExecuted'=>false,
    'environmentFileRequired'=>false,'sourceFiles'=>$catalogue['sources'],'sourceSites'=>$sites,'held'=>$held],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if($prepareOnly) { echo 'Prepared guarded cast source fixtures; no analyzer or fixture body executed.'.PHP_EOL;exit; }

$registry=file_get_contents($package.'/bin/laramago-worker.php');
$registration='        new \\Ichinya\\Laramago\\Analyzer\\GuardedStringCastCompatibilityPlugin($projectRoot),';
if(substr_count($registry,$registration)!==1) { throw new RuntimeException('Expected exactly one production guarded cast registration.'); }
$before=str_replace($registration,'',$registry);
$write($workspace.'/full-before-worker.php',$before);
$inventory=static function()use($package):array {
    $process=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot capture public inventory.'); }
    $bytes=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$errors!=='') { throw new RuntimeException('Public inventory capture failed.'); }
    $files=[];foreach(array_unique(array_filter(explode("\0",$bytes))) as $file) { $files[$file]=hash_file('sha256',$package.'/'.$file); }ksort($files);return $files;
};
$publicBefore=$inventory();
$assertHeld=static function()use($held,$inventory,$publicBefore,$workspace):void {
    foreach($held as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Fixture source changed.'); } }
    if($inventory()!==$publicBefore) { throw new RuntimeException('Public source inventory changed during cast gate.'); }
    foreach(['bootstrap-executed','autoload-executed','.env'] as $trap) { if(file_exists($workspace.'/'.$trap)) { throw new RuntimeException('Fixture bootstrap/autoload executed or environment file appeared.'); } }
};
$binary=$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');if(!is_file($binary)) { $binary=$package.'/vendor/bin/mago'; }
$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];$worker=str_replace('\\','/',$data).'/worker.php';$reports=[];$closed=[];
foreach(['native','observe','isolated','isolated-three','controls','full-before','full-after','full-after-three'] as $mode) {
    $workerMode=match($mode) { 'isolated-three'=>'isolated','full-after-three'=>'full-after',default=>$mode };
    $output=$workspace.'/'.$workerMode;if(!is_dir($output)) { mkdir($output,0777,true); }
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','source'=>['paths'=>array_keys($catalogue['sources'])],
        'extension-hosts'=>['test'=>['command'=>[PHP_BINARY,'-d','opcache.enable_cli=0',$worker,$package.'/vendor/autoload.php',$workspace,$workerMode,$package],
            'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];
    if($mode==='controls') { $config['threads']=1; }
    $configPath=$workspace.'/'.$mode.'.config.json';$raw=$workspace.'/'.$mode.'.json';$stderrPath=$workspace.'/'.$mode.'.stderr.log';
    $write($configPath,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configPath,'analyze','--reporting-format=json'],
        [1=>['file',$raw,'w'],2=>['file',$stderrPath,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot start native guarded cast gate.'); }
    $exit=proc_close($process);copy($raw,$raw.'.raw');$stderr=file_get_contents($stderrPath);
    $closed[$mode]=['closed'=>true,'exitCode'=>$exit,'rawSha256'=>hash_file('sha256',$raw.'.raw'),'stderrSha256'=>hash_file('sha256',$stderrPath),
        'hostWorkers'=>str_ends_with($mode,'three')?3:1,'analyzerThreads'=>$config['threads']??'default','requestTimeoutMs'=>120000];
    $write($workspace.'/'.$mode.'.closed-receipt.json',json_encode($closed[$mode],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|falling back|hook[^\r\n]*failed|fatal|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning|PHP Notice/i',$stderr)) { throw new RuntimeException('Operational cast gate failed: '.$mode); }
    $report=json_decode(ltrim(file_get_contents($raw),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
    if(!isset($report['issues'])||!is_array($report['issues'])) { throw new RuntimeException('Missing native cast issue report.'); }
    $reports[$mode]=$report['issues'];$assertHeld();
}
$identity=static function(array $issues):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows; };
$primary=static function(array $issue):array { $rows=array_values(array_filter($issue['annotations'],static fn(array $annotation):bool=>$annotation['kind']==='Primary'));if(count($rows)!==1) { throw new RuntimeException('Expected one native primary annotation.'); }return $rows[0]; };
$site=static function(array $issue)use($primary):string { $a=$primary($issue);return $a['span']['file_id']['name'].':'.$a['span']['start']['offset'].':'.$a['span']['end']['offset']; };
$select=static function(array $issues)use($catalogue,$sites,$site):array {
    $selected=[];$owners=[];
    foreach($issues as $issue) {
        if($issue['level']!=='Error'||$issue['code']!=='invalid-type-cast'||$issue['message']!=='Cannot reliably cast generic `object` to `string`.') { continue; }
        $owner=$sites[$site($issue)]??null;if(!in_array($owner,$catalogue['positiveOwners'],true)) { continue; }
        $selected[]=$issue;$owners[]=$owner;
    }
    sort($owners);$expected=$catalogue['positiveOwners'];sort($expected);
    if($owners!==$expected) { throw new RuntimeException('Every selected owner must have exactly one genuine full-span native Error.'); }
    return $selected;
};
$subtract=static function(array $before,array $removed)use($identity):array {
    $counts=array_count_values($identity($removed));$result=[];
    foreach($before as $issue) { $key=json_encode($issue,JSON_THROW_ON_ERROR);if(($counts[$key]??0)>0) { $counts[$key]--; }else { $result[]=$issue; } }
    if(array_sum($counts)!==0) { throw new RuntimeException('Selected complete native issues were not all present.'); }return $result;
};
if($identity($reports['native'])!==$identity($reports['observe'])) { throw new RuntimeException('AlwaysKeep observer changed complete native issues.'); }
$nativeSelected=$select($reports['native']);$isolatedExpected=$subtract($reports['native'],$nativeSelected);
foreach(['isolated','isolated-three','controls'] as $mode) { if($identity($isolatedExpected)!==$identity($reports[$mode])) { throw new RuntimeException('Exact isolated six-removal/worker/cache-restoration gate failed: '.$mode); } }
foreach($catalogue['negativeOwners'] as $name) {
    $scope=$scopes['cases.php'][$name]??null;if($scope===null) { throw new RuntimeException('Missing negative source owner: '.$name); }
    $errors=array_filter($reports['native'],static function(array $issue)use($primary,$scope):bool { $a=$primary($issue);return $issue['level']==='Error'&&$a['span']['file_id']['name']==='cases.php'&&$a['span']['start']['offset']>=$scope[0]&&$a['span']['end']['offset']<=$scope[1]; });
    if($errors===[]) { throw new RuntimeException('Vacuous native negative cast owner: '.$name); }
}
$fullSelected=$select($reports['full-before']);$fullExpected=$subtract($reports['full-before'],$fullSelected);
foreach(['full-after','full-after-three'] as $mode) { if($identity($fullExpected)!==$identity($reports[$mode])) { throw new RuntimeException('Full registry six-removal/whole-record/worker gate failed: '.$mode); } }
$controlCount=0;$mutationCount=0;
foreach(['guard'=>[50,31],'route'=>[80,61]] as $family=>[$expectedChecks,$expectedMutations]) {
    $receipt=json_decode(file_get_contents($workspace.'/controls/native-controls-'.$family.'.json'),true,flags:JSON_THROW_ON_ERROR);
    if(($receipt['genuinePositive']??false)!==true||($receipt['noVacuousControls']??false)!==true
        ||count($receipt['allChecks']??[])!==$expectedChecks||count($receipt['cacheMutations']??[])!==$expectedMutations
        ||in_array(false,$receipt['allChecks'],true)||($receipt['allChecks']['genuine native metadata restored']??false)!==true) { throw new RuntimeException('Missing exact genuine cache controls/restoration: '.$family); }
    foreach($receipt['cacheMutations'] as $mutation) { if(($mutation['touched']??0)<1||count($mutation['actualSlots']??[])!==$mutation['touched']||($mutation['changedMetadata']??false)!==true||($mutation['expectedRemove']??true)!==false) { throw new RuntimeException('Vacuous native cache mutation receipt.'); } }
    $controlCount+=$expectedChecks;$mutationCount+=$expectedMutations;
}
$sourceProbe=json_decode(file_get_contents($workspace.'/controls/outside-branch-source-control.json'),true,flags:JSON_THROW_ON_ERROR);
if(($sourceProbe['genuinePositiveBefore']??false)!==true||($sourceProbe['constructedNegative']??false)!==true
    ||($sourceProbe['nativeNegativeAcceptanceClaimed']??true)!==false||($sourceProbe['genuinePositiveRestored']??false)!==true
    ||($sourceProbe['actualDecision']??null)!=='Keep'||hash_file('sha256',$workspace.'/cases.php')!==($sourceProbe['sourceSha256']??null)) { throw new RuntimeException('Missing meaningful outside-branch source refusal control.'); }
$events=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/observe/issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));$observed=[];
foreach($events as $event) {
    if(($event['genuineIssueFilterContext']??false)!==true||($event['alwaysKeep']??false)!==true||hash_file('sha256',$workspace.'/'.$event['file'])!==$event['sourceSha256']) { throw new RuntimeException('Observer lacks genuine current source identity.'); }
    if($event['candidateDecision']!=='Remove') { continue; }$sdk=$event['issue'];$annotations=$sdk['annotations'];
    if(count($annotations)!==1||$annotations[0]['kind']!==\Mago\Sdk\Reporting\AnnotationKind::Primary->value||$sdk['level']!==\Mago\Sdk\Reporting\Level::Error->value||$sdk['code']!=='invalid-type-cast'
        ||$sdk['message']!=='Cannot reliably cast generic `object` to `string`.') { throw new RuntimeException('Wrong observed positive envelope.'); }
    $a=$annotations[0];$key=$event['file'].':'.$a['span']['start'].':'.$a['span']['end'];
    if(!isset($sites[$key])||!in_array($sites[$key],$catalogue['positiveOwners'],true)) { throw new RuntimeException('Observer selected an unapproved cast.'); }
    $matches=array_values(array_filter($nativeSelected,static fn(array $issue):bool=>$site($issue)===$key));
    if(count($matches)!==1) { throw new RuntimeException('Observer lacks one matching complete native positive.'); }
    $native=$matches[0];if($native['notes']!==$sdk['notes']||($native['help']??null)!==$sdk['help']||($native['link']??null)!==$sdk['link']
        ||($native['edits']??[])!==$sdk['edits']||($native['annotations'][0]['message']??null)!==$a['message']) { throw new RuntimeException('Observer/native complete envelope differs.'); }
    $observed[]=$sites[$key];
}
sort($observed);$expectedOwners=$catalogue['positiveOwners'];sort($expectedOwners);if($observed!==$expectedOwners) { throw new RuntimeException('Missing/duplicate genuine source-and-SDK positive observations.'); }
$assertHeld();
$write($workspace.'/accepted-result.json',json_encode(['sourceCases'=>5,'genuineNativeIssues'=>count($reports['native']),'isolatedNativeErrorRemovals'=>6,
    'fullRegistryNativeErrorRemovals'=>6,'positiveOwners'=>$observed,'negativeNativeErrorOwners'=>count($catalogue['negativeOwners']),
    'genuineControls'=>$controlCount,'nonVacuousNativeCacheMutations'=>$mutationCount,'oneThreeWorkersMatch'=>true,
    'constructedNegativeSourceControls'=>1,'constructedNegativeNativeAcceptanceClaimed'=>false,
    'alwaysKeepWholeNativeIssues'=>true,'allOtherCompleteIssuesPreserved'=>true,'nativeTypesReplaced'=>false,
    'fixtureBodiesExecuted'=>false,'applicationBodiesExecuted'=>false,'publicSourcesUnchanged'=>true,'closedReceipts'=>$closed],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS guarded string casts: six native Errors, 130 genuine controls, 92 native cache mutations, one/three-worker parity.'.PHP_EOL;
