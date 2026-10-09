<?php
declare(strict_types=1);

// This program analyzes invented source; it never includes a fixture or application body.
$option=static function(string $key)use($argv):?string { foreach($argv as $item) { if(str_starts_with($item,$key.'=')) { return substr($item,strlen($key)+1); } }return null; };
$package=str_replace('\\','/',$option('--package-root')??dirname(__DIR__));
$candidate=str_replace('\\','/',$option('--candidate-root')??dirname(__DIR__));
$workspace=str_replace('\\','/',$option('--workspace')??sys_get_temp_dir().'/laramago selected chunk collection arguments '.bin2hex(random_bytes(8)));
$data=__DIR__.'/fixtures/selected-chunk-collection';require $package.'/vendor/autoload.php';
if(file_exists($workspace)) { throw new RuntimeException('Preserve existing evidence and select a fresh workspace.'); }
mkdir($workspace,0777,true);$write=static function(string $path,string $contents):void { if(!is_dir(dirname($path))) { mkdir(dirname($path),0777,true); }file_put_contents($path,$contents); };
$matrix=json_decode(file_get_contents($data.'/cases.json'),true,flags:JSON_THROW_ON_ERROR);$held=[];$sites=[];$scopes=[];
$finder=new \PhpParser\NodeFinder;$parser=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
foreach($matrix['sources'] as $file=>$hash) {
    $contents=str_replace("\r\n","\n",file_get_contents($data.'/source/'.$file.'.stub'));if(hash('sha256',$contents)!==$hash) { throw new RuntimeException('Fixture source hash differs: '.$file); }
    $write($workspace.'/'.$file,$contents);$held[$workspace.'/'.$file]=$hash;$nodes=$parser->parse($contents)??[];
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Stmt\Function_::class) as $owner) { $scopes[$file][$owner->name->name]=[$owner->getStartFilePos(),$owner->getEndFilePos()+1]; }
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Stmt\Class_::class) as $class) {
        if ($class->name===null) { continue; }
        foreach($class->getMethods() as $owner) { $scopes[$file][$class->name->name.'::'.$owner->name->name]=[$owner->getStartFilePos(),$owner->getEndFilePos()+1]; }
    }
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Expr\Assign::class) as $binding) {
        if($binding->var instanceof \PhpParser\Node\Expr\Variable && is_string($binding->var->name) && $binding->expr instanceof \PhpParser\Node\Expr\Closure) {
            $scopes[$file][$binding->var->name]=[$binding->expr->getStartFilePos(),$binding->expr->getEndFilePos()+1];
        }
    }
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Expr\CallLike::class) as $call) {
        if($call->isFirstClassCallable()) { continue; }$owner='top';$size=PHP_INT_MAX;
        foreach($scopes[$file]??[] as $name=>$span) { if($span[0]<=$call->getStartFilePos()&&$span[1]>=$call->getEndFilePos()+1&&$span[1]-$span[0]<$size) { $owner=$name;$size=$span[1]-$span[0]; } }
        $method=($call instanceof \PhpParser\Node\Expr\MethodCall || $call instanceof \PhpParser\Node\Expr\StaticCall) && $call->name instanceof \PhpParser\Node\Identifier?$call->name->name:null;
        foreach($call->args as $argument) { if(!$argument instanceof \PhpParser\Node\Arg) { continue; }$value=$argument->value;
            $sites[$file.':'.$value->getStartFilePos().':'.($value->getEndFilePos()+1)]=['owner'=>$owner,'receivingMethod'=>$method,'receivingCallSpan'=>[$call->getStartFilePos(),$call->getEndFilePos()+1]];
        }
    }
}
$write($workspace.'/bootstrap/app.php','<?php file_put_contents(dirname(__DIR__)."/bootstrap-executed","unexpected"); throw new RuntimeException("Fixture bootstrap must never execute.");');
$write($workspace.'/dependency tree/autoload.php','<?php file_put_contents(dirname(__DIR__)."/autoload-executed","unexpected"); throw new RuntimeException("Fixture autoload must never execute.");');
$write($workspace.'/composer.json',json_encode(['config'=>['vendor-dir'=>'dependency tree'],'autoload'=>['files'=>['bootstrap/app.php']]],JSON_THROW_ON_ERROR));
foreach(['bootstrap/app.php','dependency tree/autoload.php','composer.json'] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
foreach([$candidate.'/src/Analyzer/SelectedChunkCollectionContracts.php',$candidate.'/src/Analyzer/SelectedChunkCollectionArgumentFilter.php',$candidate.'/src/Analyzer/SelectedChunkCollectionArgumentPlugin.php',
    $data.'/NativeHarness.php',$data.'/worker.php',$data.'/cases.json',__FILE__] as $file) { $held[$file]=hash_file('sha256',$file); }
$registry=file_get_contents($package.'/bin/laramago-worker.php');$registration='        new \\Ichinya\\Laramago\\Analyzer\\SelectedChunkCollectionArgumentPlugin($projectRoot),';
if(substr_count($registry,$registration)===1) { $before=str_replace($registration,'',$registry);$after=$registry; }
else {
    $anchor='        new FalseOperandCompatibilityPlugin,';
    if(substr_count($registry,$anchor)!==1||str_contains($registry,'SelectedChunkCollectionArgumentPlugin')) { throw new RuntimeException('Expected one precise production registry insertion point.'); }
    $before=$registry;$after=str_replace($anchor,$anchor."\n".$registration,$registry);
}
$write($workspace.'/full-before-worker.php',$before);$write($workspace.'/full-after-worker.php',$after);
foreach(['full-before-worker.php','full-after-worker.php'] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
$write($workspace.'/prepared.json',json_encode(['sourceOnly'=>true,'analyzerJobsStarted'=>0,'fixtureBodiesExecuted'=>false,'applicationBodiesExecuted'=>false,
    'nativeAcceptanceClaimed'=>false,'sourceSites'=>$sites,'sources'=>$matrix['sources'],'registrationOnlyDifference'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if(in_array('--prepare-only',$argv,true)) { echo 'Prepared source argument fixture files; no analyzer or fixture body executed.'.PHP_EOL;exit; }
$inventory=static function()use($package):array {
    $process=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot capture public source inventory.'); }
    $bytes=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$errors!=='') { throw new RuntimeException('Source inventory failed.'); }
    $result=[];foreach(array_unique(array_filter(explode("\0",$bytes))) as $file) { $result[$file]=hash_file('sha256',$package.'/'.$file); }ksort($result);return $result;
};
$publicBefore=$inventory();$assertHeld=static function()use($inventory,$publicBefore,$held,$workspace):void {
    if($inventory()!==$publicBefore) { throw new RuntimeException('Public source changed during argument gate.'); }
    foreach($held as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Fixture source changed.'); } }
    foreach(['bootstrap-executed','autoload-executed','.env'] as $trap) { if(file_exists($workspace.'/'.$trap)) { throw new RuntimeException('Fixture body executed or environment file appeared.'); } }
};
$binary=$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');if(!is_file($binary)) { $binary=$package.'/vendor/bin/mago'; }
$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];$reports=[];$closed=[];
foreach(['native','observe','isolated','isolated-three','controls','full-before','full-after','full-after-three'] as $mode) {
    $workerMode=match($mode) { 'isolated-three'=>'isolated','full-after-three'=>'full-after',default=>$mode };
    $output=$workspace.'/'.$workerMode;if(!is_dir($output)) { mkdir($output,0777,true); }
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','source'=>['paths'=>array_keys($matrix['sources'])],
        'extension-hosts'=>['test'=>['command'=>[PHP_BINARY,'-d','opcache.enable_cli=0',$data.'/worker.php',$package.'/vendor/autoload.php',$workspace,$workerMode,$package,$candidate],
            'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];
    if($mode==='controls') { $config['threads']=1; }
    $configFile=$workspace.'/'.$mode.'.config.json';$raw=$workspace.'/'.$mode.'.json';$stderrFile=$workspace.'/'.$mode.'.stderr.log';
    $write($configFile,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configFile,'analyze','--reporting-format=json'],[1=>['file',$raw,'w'],2=>['file',$stderrFile,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot start source argument gate.'); }
    $exit=proc_close($process);copy($raw,$raw.'.raw');$stderr=file_get_contents($stderrFile);
    $closed[$mode]=['closed'=>true,'exitCode'=>$exit,'hostWorkers'=>str_ends_with($mode,'three')?3:1,'analyzerThreads'=>$config['threads']??'default',
        'rawSha256'=>hash_file('sha256',$raw.'.raw'),'stderrSha256'=>hash_file('sha256',$stderrFile)];
    $write($workspace.'/'.$mode.'.closed-receipt.json',json_encode($closed[$mode],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|falling back|hook[^\r\n]*failed|fatal|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning|PHP Notice/i',$stderr)) { throw new RuntimeException('Operational argument gate failed: '.$mode); }
    $report=json_decode(ltrim(file_get_contents($raw),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
    if(!isset($report['issues'])||!is_array($report['issues'])) { throw new RuntimeException('Missing native issue report.'); }
    $reports[$mode]=$report['issues'];$assertHeld();
}
$identity=static function(array $issues):array { $result=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($result);return $result; };
$primary=static function(array $issue):array { $annotations=array_values(array_filter($issue['annotations'],static fn(array $annotation):bool=>$annotation['kind']==='Primary'));if(count($annotations)!==1) { throw new RuntimeException('Expected one native primary annotation.'); }return $annotations[0]; };
$site=static function(array $issue)use($primary):string { $a=$primary($issue);return $a['span']['file_id']['name'].':'.$a['span']['start']['offset'].':'.$a['span']['end']['offset']; };
$select=static function(array $issues)use($sites,$matrix,$site):array {
    $selected=[];$owners=[];
    foreach($issues as $issue) {
        if($issue['level']!=='Error'||$issue['code']!=='less-specific-argument') { continue; }
        $key=$site($issue);$owner=$sites[$key]['owner']??null;$file=explode(':',$key,2)[0];$identity=$file.'::'.$owner;
        if(!in_array($identity,$matrix['positiveOwners'],true)||($sites[$key]['receivingMethod']??null)!=='accept') { continue; }$selected[]=$issue;$owners[]=$identity;
    }
    sort($owners);$expected=$matrix['positiveOwners'];sort($expected);if($owners!==$expected) { throw new RuntimeException('Missing individual genuine positive Error witness.'); }return $selected;
};
$subtract=static function(array $issues,array $selected)use($identity):array {
    $counts=array_count_values($identity($selected));$result=[];
    foreach($issues as $issue) { $key=json_encode($issue,JSON_THROW_ON_ERROR);if(($counts[$key]??0)>0) { $counts[$key]--; }else { $result[]=$issue; } }
    if(array_sum($counts)!==0) { throw new RuntimeException('Selected complete original records are missing.'); }return $result;
};
if($identity($reports['native'])!==$identity($reports['observe'])) { throw new RuntimeException('AlwaysKeep changed native issues.'); }
$selected=$select($reports['native']);$expected=$subtract($reports['native'],$selected);
foreach(['isolated','isolated-three','controls'] as $mode) { if($identity($reports[$mode])!==$identity($expected)) { throw new RuntimeException('Exact selected removals/other whole records differ: '.$mode); } }
foreach($matrix['negativeOwners'] as $owner) {
    $span=$scopes['negative.php'][$owner]??null;if($span===null) { throw new RuntimeException('Missing negative source owner.'); }
    $native=array_filter($reports['native'],static function(array $issue)use($span,$primary,$sites,$site,$owner):bool {
        if($issue['level']!=='Error'||!in_array($issue['code'],['less-specific-argument','possibly-invalid-argument','invalid-argument','mixed-argument','invalid-named-argument'],true)) { return false; }
        $a=$primary($issue);return $a['span']['file_id']['name']==='negative.php'&&$a['span']['start']['offset']>=$span[0]&&$a['span']['end']['offset']<=$span[1]
            && (($sites[$site($issue)]['owner']??null)===$owner
                || (array_any(array_keys($sites),static fn(string $sourceKey):bool=>
                    str_starts_with($sourceKey,'negative.php:') && $sites[$sourceKey]['owner']===$owner
                    && $sites[$sourceKey]['receivingCallSpan']===[$a['span']['start']['offset'],$a['span']['end']['offset']])));
    });
    if($native===[]) { throw new RuntimeException('Vacuous native negative: '.$owner); }
}
$fullSelected=$select($reports['full-before']);$fullExpected=$subtract($reports['full-before'],$fullSelected);
foreach(['full-after','full-after-three'] as $mode) { if($identity($reports[$mode])!==$identity($fullExpected)) { throw new RuntimeException('Full production registry argument delta differs: '.$mode); } }
$mutations=0;$checks=0;
foreach(['chunk'=>[228,71]] as $family=>[$expectedChecks,$expectedMutations]) {
    $receipt=json_decode(file_get_contents($workspace.'/controls/controls-'.$family.'.json'),true,flags:JSON_THROW_ON_ERROR);
    if(($receipt['genuinePositive']??false)!==true||($receipt['restored']??false)!==true||($receipt['noVacuousControls']??false)!==true||($receipt['cacheMutations']??[])===[]
        ||count($receipt['allChecks']??[])!==$expectedChecks||count($receipt['cacheMutations']??[])!==$expectedMutations
        ||in_array(false,$receipt['allChecks']??[],true)||($receipt['allChecks']['genuine native contracts restored']??false)!==true) { throw new RuntimeException('Missing exact genuine controls and restoration.'); }
    foreach($receipt['cacheMutations'] as $mutation) { if(($mutation['touched']??0)<1||count($mutation['actualSlots']??[])!==$mutation['touched']||($mutation['meaningfullyChanged']??false)!==true) { throw new RuntimeException('Vacuous native cache mutation.'); } }
    $mutations+=count($receipt['cacheMutations']);$checks+=count($receipt['allChecks']);
}
$events=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/observe/issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));$observed=[];
foreach($events as $event) {
    if(($event['genuineIssueFilterContext']??false)!==true||($event['alwaysKeep']??false)!==true||hash_file('sha256',$workspace.'/'.$event['file'])!==$event['sourceSha256']) { throw new RuntimeException('Observer source identity differs.'); }
    if($event['candidateDecision']!=='Remove') { continue; }$issue=$event['issue'];$a=$issue['annotations'][0];$key=$event['file'].':'.$a['span']['start'].':'.$a['span']['end'];
    if($issue['level']!==\Mago\Sdk\Reporting\Level::Error->value||$a['kind']!==\Mago\Sdk\Reporting\AnnotationKind::Primary->value||!isset($sites[$key])) { throw new RuntimeException('Wrong native observer envelope.'); }
    $matches=array_values(array_filter($selected,static fn(array $native):bool=>$site($native)===$key));
    if(count($matches)!==1||$matches[0]['message']!==$issue['message']||$matches[0]['notes']!==$issue['notes']||($matches[0]['help']??null)!==$issue['help']
        ||($matches[0]['link']??null)!==$issue['link']||($matches[0]['edits']??[])!==$issue['edits']||count($issue['annotations'])!==2) { throw new RuntimeException('Observed positive lacks original whole issue identity.'); }
    foreach($issue['annotations'] as $index=>$annotation) {
        $native=$matches[0]['annotations'][$index];$kind=($index===0?\Mago\Sdk\Reporting\AnnotationKind::Primary:\Mago\Sdk\Reporting\AnnotationKind::Secondary)->value;
        if($annotation['kind']!==$kind||($annotation['file']!==null&&$annotation['file']!=='')||($native['message']??null)!==$annotation['message']
            ||[$native['span']['start']['offset'],$native['span']['end']['offset']]!==[$annotation['span']['start'],$annotation['span']['end']]) { throw new RuntimeException('Native observer complete annotation differs.'); }
    }
    $observed[]=$event['file'].'::'.$sites[$key]['owner'];
}
sort($observed);$positive=$matrix['positiveOwners'];sort($positive);if($observed!==$positive) { throw new RuntimeException('Missing exact observed positive set.'); }$assertHeld();
$write($workspace.'/accepted-result.json',json_encode(['genuineNativePositiveErrors'=>count($selected),'individualOriginalFamilies'=>['E024'],
    'nativeNegativeOwners'=>count($matrix['negativeOwners']),'genuineControls'=>$checks,'nonVacuousNativeCacheMutations'=>$mutations,
    'alwaysKeepWholeIssues'=>true,'allOtherWholeIssuesPreserved'=>true,'oneThreeWorkersMatch'=>true,'nativeTypesReplaced'=>false,
    'fixtureBodiesExecuted'=>false,'applicationBodiesExecuted'=>false,'sourcesUnchanged'=>true,'closedReceipts'=>$closed],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS selected chunk collection arguments: exact native collection argument witnesses, receiving contracts and one/three-worker deltas.'.PHP_EOL;
