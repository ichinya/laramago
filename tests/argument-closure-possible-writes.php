<?php
declare(strict_types=1);
$option=static function(string $key)use($argv):?string { foreach($argv as $item) { if(str_starts_with($item,$key.'=')) { return substr($item,strlen($key)+1); } }return null; };
$package=str_replace('\\','/',$option('--package-root')??dirname(__DIR__));
$candidate=str_replace('\\','/',$option('--candidate-root')??dirname(__DIR__));
$workspace=str_replace('\\','/',$option('--workspace')??sys_get_temp_dir().'/laramago argument closure possible writes '.bin2hex(random_bytes(8)));
require $package.'/vendor/autoload.php';$data=__DIR__.'/fixtures/argument-closure-possible-writes';
if(file_exists($workspace)) { throw new RuntimeException('Keep old evidence; select a fresh workspace.'); }
mkdir($workspace,0777,true);
$write=static function(string $path,string $contents):void { if(!is_dir(dirname($path))) { mkdir(dirname($path),0777,true); }file_put_contents($path,$contents); };
$held=[];$sites=[];$negativeScopes=[];$parser=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion();$finder=new \PhpParser\NodeFinder;
foreach(['cases.php'=>'cases.php.stub','negative.php'=>'negative.php.stub','packages/testo/assert/Assert.php'=>'Assert.php.stub','support.php'=>'support.php.stub'] as $file=>$stub) {
    $contents=file_get_contents($data.'/'.$stub);$write($workspace.'/'.$file,$contents);$held[$workspace.'/'.$file]=hash('sha256',$contents);
    if(!in_array($file,['cases.php','negative.php'],true)) { continue; }$nodes=$parser->parse($contents)??[];
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Stmt\Class_::class) as $class) {
        foreach($class->getMethods() as $method) {
            $span=[$method->getStartFilePos(),$method->getEndFilePos()+1];if($file==='negative.php') { $negativeScopes[$method->name->name]=$span; }
            foreach($finder->findInstanceOf($method->stmts,\PhpParser\Node\Expr\StaticCall::class) as $call) {
                if($call->name instanceof \PhpParser\Node\Identifier && $call->name->name==='true') { $sites[$file.':'.$call->getStartFilePos().':'.($call->getEndFilePos()+1)]=$method->name->name; }
            }
        }
    }
}
$write($workspace.'/bootstrap/app.php','<?php file_put_contents(dirname(__DIR__)."/bootstrap-executed","unexpected"); throw new RuntimeException("Never execute fixture bootstrap.");');
$write($workspace.'/dependency tree/autoload.php','<?php file_put_contents(dirname(__DIR__)."/autoload-executed","unexpected"); throw new RuntimeException("Never execute fixture autoload.");');
$write($workspace.'/composer.json',json_encode(['config'=>['vendor-dir'=>'dependency tree'],'autoload'=>['files'=>['bootstrap/app.php']]],JSON_THROW_ON_ERROR));
foreach(['bootstrap/app.php','dependency tree/autoload.php','composer.json'] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
$registry=file_get_contents($package.'/bin/laramago-worker.php');
$registration='        new \\Ichinya\\Laramago\\Analyzer\\ArgumentClosurePossibleWritePlugin($projectRoot),';
$shortRegistration='        new ArgumentClosurePossibleWritePlugin($projectRoot),';
$selected=array_values(array_filter([$registration,$shortRegistration],static fn(string $line):bool=>substr_count($registry,$line)===1));
if(count($selected)!==1) { throw new RuntimeException('Expected one exact production argument-closure registration.'); }
$write($workspace.'/full-before-worker.php',str_replace($selected[0],'',$registry));
$write($workspace.'/full-after-worker.php',$registry);
foreach(['full-before-worker.php','full-after-worker.php'] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
$inventory=static function()use($package):array {
    $process=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot capture public inventory.'); }$bytes=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0 || $errors!=='') { throw new RuntimeException('Cannot close public inventory.'); }$result=[];
    foreach(array_unique(array_filter(explode("\0",$bytes))) as $file) { $result[$file]=hash_file('sha256',$package.'/'.$file); }ksort($result);return $result;
};
$publicBefore=$inventory();$assertHeld=static function()use($inventory,$publicBefore,$held,$workspace):void {
    if($inventory()!==$publicBefore) { throw new RuntimeException('Public source changed during fixture gate.'); }
    foreach($held as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Fixture source changed.'); } }
    foreach(['bootstrap-executed','autoload-executed','.env'] as $trap) { if(file_exists($workspace.'/'.$trap)) { throw new RuntimeException('Application trap executed or environment file appeared.'); } }
};
$write($workspace.'/prepared.json',json_encode(['sourceOnly'=>true,'nativeAcceptanceClaimed'=>false,'sites'=>$sites,'negativeScopes'=>$negativeScopes],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if(in_array('--prepare-only',$argv,true)) { echo 'Prepared invented possible-write fixture sources; no analyzer or fixture body executed.'.PHP_EOL;exit; }
$binary=$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');if(!is_file($binary)) { $binary=$package.'/vendor/bin/mago'; }$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];$reports=[];$closed=[];
foreach(['native','observe','isolated','isolated-three','controls','full-before','full-after','full-after-three'] as $mode) {
    $workerMode=match($mode) {'isolated-three'=>'isolated','full-after-three'=>'full-after',default=>$mode};$output=$workspace.'/'.$workerMode;if(!is_dir($output)) { mkdir($output,0777,true); }
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','source'=>['paths'=>['cases.php','negative.php'],'includes'=>['packages','support.php']],
        'extension-hosts'=>['test'=>['command'=>[PHP_BINARY,'-d','opcache.enable_cli=0',$data.'/worker.php',$package.'/vendor/autoload.php',$workspace,$workerMode,$candidate],
            'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];if($mode==='controls') { $config['threads']=1; }
    $configFile=$workspace.'/'.$mode.'.config.json';$raw=$workspace.'/'.$mode.'.json';$stderrFile=$workspace.'/'.$mode.'.stderr.log';$write($configFile,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configFile,'analyze','--reporting-format=json'],[1=>['file',$raw,'w'],2=>['file',$stderrFile,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot start possible-write gate.'); }$exit=proc_close($process);copy($raw,$raw.'.raw');$stderr=file_get_contents($stderrFile);
    $closed[$mode]=['closed'=>true,'exitCode'=>$exit,'hostWorkers'=>str_ends_with($mode,'three')?3:1,'analyzerThreads'=>$config['threads']??'default',
        'rawSha256'=>hash_file('sha256',$raw.'.raw'),'stderrSha256'=>hash_file('sha256',$stderrFile)];$write($workspace.'/'.$mode.'.closed-receipt.json',json_encode($closed[$mode],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    if(!in_array($exit,[0,1],true) || preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|falling back|hook[^\r\n]*failed|fatal|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning|PHP Notice/i',$stderr)) { throw new RuntimeException('Operational possible-write gate failed: '.$mode); }
    $report=json_decode(ltrim(file_get_contents($raw),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);if(!isset($report['issues'])||!is_array($report['issues'])) { throw new RuntimeException('Missing native issue report.'); }$reports[$mode]=$report['issues'];$assertHeld();
}
$identity=static function(array $issues):array {$result=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($result);return $result;};
$primary=static function(array $issue):array {$annotations=array_values(array_filter($issue['annotations'],static fn(array $a):bool=>$a['kind']==='Primary'));if(count($annotations)!==1) {throw new RuntimeException('Expected one native primary annotation.');}return $annotations[0];};
$site=static function(array $issue)use($primary):string {$a=$primary($issue);return $a['span']['file_id']['name'].':'.$a['span']['start']['offset'].':'.$a['span']['end']['offset'];};
$positive=['listener','tryArgument','foreachArgument'];sort($positive);
$select=static function(array $issues)use($sites,$site,$positive):array {
    $selected=[];$owners=[];foreach($issues as $issue) {
        if($issue['code']!=='impossible-type-comparison'||$issue['level']!=='Error') {continue;} $key=$site($issue);$owner=$sites[$key]??null;
        if(!str_starts_with($key,'cases.php:')||!in_array($owner,$positive,true)) {continue;}$selected[]=$issue;$owners[]=$owner;
    }sort($owners);if($owners!==$positive) {throw new RuntimeException('Missing three individual genuine native positive Error owners.');}return $selected;
};
$subtract=static function(array $issues,array $selected)use($identity):array {
    $counts=array_count_values($identity($selected));$result=[];foreach($issues as $issue) {$key=json_encode($issue,JSON_THROW_ON_ERROR);if(($counts[$key]??0)>0) {$counts[$key]--;}else {$result[]=$issue;}}
    if(array_sum($counts)!==0) {throw new RuntimeException('Missing exact selected whole issue records.');}return $result;
};
if($identity($reports['native'])!==$identity($reports['observe'])) {throw new RuntimeException('AlwaysKeep changed native whole issues.');}
$selected=$select($reports['native']);$expected=$subtract($reports['native'],$selected);
foreach(['isolated','isolated-three','controls'] as $mode) {if($identity($reports[$mode])!==$identity($expected)) {throw new RuntimeException('Exact selected delta or other whole DTO preservation failed: '.$mode);}}
foreach($negativeScopes as $owner=>$span) {
    $errors=array_filter($reports['native'],static function(array $issue)use($primary,$span):bool {
        if($issue['level']!=='Error') {return false;}$a=$primary($issue);return $a['span']['file_id']['name']==='negative.php'&&$a['span']['start']['offset']>=$span[0]&&$a['span']['end']['offset']<=$span[1];
    });if($errors===[]) {throw new RuntimeException('Vacuous native Error refusal owner: '.$owner);}
}
$fullExpected=$subtract($reports['full-before'],$select($reports['full-before']));
foreach(['full-after','full-after-three'] as $mode) {if($identity($reports[$mode])!==$identity($fullExpected)) {throw new RuntimeException('Production registry delta failed: '.$mode);}}
$controls=json_decode(file_get_contents($workspace.'/controls/controls.json'),true,flags:JSON_THROW_ON_ERROR);
if(($controls['genuinePositive']??false)!==true||($controls['restored']??false)!==true||count($controls['cacheMutations']??[])!==27||count($controls['allChecks']??[])!==97||in_array(false,$controls['allChecks']??[],true)) {throw new RuntimeException('Missing exact meaningful native controls.');}
foreach($controls['cacheMutations'] as $mutation) {if(($mutation['touched']??0)<1||count($mutation['actualSlots']??[])!==$mutation['touched']||($mutation['meaningfullyChanged']??false)!==true) {throw new RuntimeException('Vacuous SDK cache mutation.');}}
$events=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/observe/issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));$observed=[];
foreach($events as $event) {
    if($event['candidateDecision']!=='Remove') {continue;}if(($event['genuineIssueFilterContext']??false)!==true||($event['alwaysKeep']??false)!==true||hash_file('sha256',$workspace.'/'.$event['file'])!==$event['sourceSha256']) {throw new RuntimeException('Missing genuine current observer source.');}
    $issue=$event['issue'];$a=$issue['annotations'][0];$key=$event['file'].':'.$a['span']['start'].':'.$a['span']['end'];$matches=array_values(array_filter($selected,static fn(array $native):bool=>$site($native)===$key));
    if(count($matches)!==1||$issue['level']!==\Mago\Sdk\Reporting\Level::Error->value||$a['kind']!==\Mago\Sdk\Reporting\AnnotationKind::Primary->value
        ||$matches[0]['message']!==$issue['message']||$matches[0]['notes']!==$issue['notes']||($matches[0]['help']??null)!==$issue['help']||($matches[0]['link']??null)!==$issue['link']
        ||($matches[0]['edits']??[])!==$issue['edits']||($matches[0]['annotations'][0]['message']??null)!==$a['message']||count($issue['annotations'])!==1||($a['file']!==null&&$a['file']!=='')) {throw new RuntimeException('Observed positive lacks complete native issue identity.');}
    $observed[]=$sites[$key];
}sort($observed);if($observed!==$positive) {throw new RuntimeException('Missing exact genuine observed positive owner set.');}$assertHeld();
$write($workspace.'/accepted-result.json',json_encode(['genuineNativePositiveErrors'=>3,'originalFamilies'=>['E003','E007','E008'],'nativeErrorNegativeOwners'=>count($negativeScopes),
    'genuineControls'=>97,'actualSdkCacheMutations'=>27,'oneThreeWorkersMatch'=>true,'allOtherWholeIssuesPreserved'=>true,'alwaysKeepWholeIssues'=>true,
    'guaranteedExecutionClaimed'=>false,'applicationBodiesExecuted'=>false,'fixtureBodiesExecuted'=>false,'nativeTypesReplaced'=>false,'sourcesUnchanged'=>true,'closedReceipts'=>$closed],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS argument closure possible writes: three source-bound boolean Errors, preserved refusal owners and actual metadata controls.'.PHP_EOL;
