<?php
declare(strict_types=1);
// Invented sources are analyzed, never loaded into PHP.
$package=str_replace('\\','/',dirname(__DIR__));
require $package.'/vendor/autoload.php';
$workspace=str_replace('\\','/',sys_get_temp_dir()).'/laramago object booleans '.bin2hex(random_bytes(8));
$data=__DIR__.'/fixtures/object-boolean';mkdir($workspace,0777,true);
file_put_contents($workspace.'/focus.php',file_get_contents($data.'/focus.php.stub'));
$sourceHash=hash_file('sha256',$workspace.'/focus.php');
file_put_contents($workspace.'/composer.json',json_encode(['config'=>['vendor-dir'=>'dependency tree'],'autoload'=>['files'=>['bootstrap.php']]],JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/bootstrap.php','<?php file_put_contents(__DIR__."/body-executed","unexpected"); throw new RuntimeException("Fixture body must never execute.");');
mkdir($workspace.'/dependency tree');copy($workspace.'/bootstrap.php',$workspace.'/dependency tree/autoload.php');
$registry=file_get_contents($package.'/bin/laramago-worker.php');
$registration='        new \\Ichinya\\Laramago\\Analyzer\\ObjectBooleanCompatibilityPlugin,';
if(substr_count($registry,$registration)!==1) { throw new RuntimeException('One actual production registration required.'); }
$before=str_replace($registration,'',$registry);
$anchor='    analyzerPlugins: [';
if(substr_count($before,$anchor)!==1) { throw new RuntimeException('Unique registry anchor required.'); }
$before=str_replace($anchor,$anchor."\n".'        new \\Ichinya\\Laramago\\Tests\\ObjectBoolean\\NativeObjectBooleanObserver($projectRoot."/full-observe",false),',$before);
file_put_contents($workspace.'/full-before-worker.php',$before);
file_put_contents($workspace.'/full-after-worker.php',$registry);
foreach(['observe','full-observe'] as $folder) { mkdir($workspace.'/'.$folder); }
$binary=getenv('MAGO_BINARY')?:$package.'/vendor/bin/mago';$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];
$reports=$receipts=[];
foreach(['native','observe','isolated','isolated-three','full-before','full-after','full-after-three'] as $mode) {
    $selected=str_ends_with($mode,'three')?substr($mode,0,-6):$mode;
    $workerArguments=[PHP_BINARY,'-d','opcache.enable_cli=0',$data.'/worker.php',$package.'/vendor/autoload.php',$workspace,$selected];
    if(str_starts_with($mode,'full-')) { $workerArguments[]=$workspace.'/'.($mode==='full-before'?'full-before':'full-after').'-worker.php'; }
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','threads'=>1,'source'=>['paths'=>['focus.php']],
        'extension-hosts'=>['test'=>['command'=>$workerArguments,'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];
    $configPath=$workspace.'/'.$mode.'.config.json';file_put_contents($configPath,json_encode($config,JSON_THROW_ON_ERROR));
    $raw=$workspace.'/'.$mode.'.json';$stderrPath=$workspace.'/'.$mode.'.stderr';
    $child=proc_open([...$command,'--workspace',$workspace,'--config',$configPath,'analyze','--reporting-format=json'],[1=>['file',$raw,'w'],2=>['file',$stderrPath,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($child)) { throw new RuntimeException('Cannot start native fixture.'); }
    $exit=proc_close($child);copy($raw,$raw.'.raw');copy($stderrPath,$stderrPath.'.raw');
    $receipts[$mode]=['closed'=>true,'exitCode'=>$exit,'rawSha256'=>hash_file('sha256',$raw.'.raw'),'hostWorkers'=>str_ends_with($mode,'three')?3:1];
    file_put_contents($workspace.'/'.$mode.'.closed-receipt.json',json_encode($receipts[$mode],JSON_THROW_ON_ERROR));
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|invalid[^\r\n]*frame|hook[^\r\n]*failed|fatal|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i',file_get_contents($stderrPath))) { throw new RuntimeException('Operational fixture failure: '.$workspace); }
    $reports[$mode]=json_decode(file_get_contents($raw),true,flags:JSON_THROW_ON_ERROR)['issues'];
    if(hash_file('sha256',$workspace.'/focus.php')!==$sourceHash||file_exists($workspace.'/body-executed')||file_exists($workspace.'/.env')) { throw new RuntimeException('Fixture source/body changed.'); }
}
$bag=static function(array $issues):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows; };
if($bag($reports['native'])!==$bag($reports['observe'])) { throw new RuntimeException('AlwaysKeep altered native records.'); }
$records=static function(string $directory,string $kind):array {
    $rows=[];foreach(glob($directory.'/'.$kind.'-*.jsonl') as $file) { foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) { $rows[]=json_decode($line,true,flags:JSON_THROW_ON_ERROR); } }return $rows;
};
$subtract=static function(array $before,array $observations)use($bag):array {
    foreach($observations as $row) {
        if($row['proposal']===null) { continue; }$sdk=$row['issue'];$matches=[];
        $sdk['level']=\Mago\Sdk\Reporting\Level::from($sdk['level'])->name;
        foreach($sdk['annotations'] as &$sdkAnnotation) { $sdkAnnotation['kind']=\Mago\Sdk\Reporting\AnnotationKind::from($sdkAnnotation['kind'])->name; }unset($sdkAnnotation);
        foreach($before as $key=>$issue) {
            if($issue['level']!==$sdk['level']||$issue['code']!==$sdk['code']||$issue['message']!==$sdk['message']||($issue['notes']??[])!==$sdk['notes']||($issue['help']??null)!==$sdk['help']||($issue['link']??null)!==$sdk['link']||($issue['edits']??[])!==$sdk['edits']||count($issue['annotations'])!==count($sdk['annotations'])) { continue; }
            $same=true;foreach($issue['annotations'] as $index=>$annotation) {
                $actual=$sdk['annotations'][$index];
                if($annotation['kind']!==$actual['kind']||($annotation['message']??null)!==$actual['message']||[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']]!==[$actual['span']['start'],$actual['span']['end']]||$annotation['span']['file_id']['name']!==$row['file']) { $same=false;break; }
            }if($same) { $matches[]=$key; }
        }
        if(count($matches)!==1) { throw new RuntimeException('Missing one genuine whole native object warning.'); }unset($before[$matches[0]]);
    }return array_values($before);
};
$observations=$records($workspace.'/observe','issues');
$positives=array_filter($observations,static fn(array $row):bool=>$row['proposal']!==null);
if(count($positives)!==11||$bag($subtract($reports['native'],$observations))!==$bag($reports['isolated'])||$bag($reports['isolated'])!==$bag($reports['isolated-three'])) { throw new RuntimeException('Eleven genuine positives or worker parity failed: '.$workspace); }
$checks=0;foreach($records($workspace.'/observe','controls') as $row) { if(!$row['genuineNativePositive']||in_array(false,$row['checks'],true)) { throw new RuntimeException('Native refusal control failed.'); }$checks+=count($row['checks']); }
if($checks!==418) { throw new RuntimeException('Missing native-derived refusal controls.'); }
$full=$subtract($reports['full-before'],$records($workspace.'/full-observe','issues'));
if($bag($full)!==$bag($reports['full-after'])||$bag($reports['full-after'])!==$bag($reports['full-after-three'])) { throw new RuntimeException('Full registry changed unrelated whole records: '.$workspace); }
$errors=static fn(array $issues):array=>array_values(array_filter($issues,static fn(array $issue):bool=>$issue['level']==='Error'));
if(count($errors($reports['native']))!==6||$bag($errors($reports['native']))!==$bag($errors($reports['isolated']))||$bag($errors($reports['full-before']))!==$bag($errors($reports['full-after']))) { throw new RuntimeException('Real Error preservation failed.'); }
file_put_contents($workspace.'/accepted-result.json',json_encode(['nativeWarningsRemoved'=>11,'nativeDerivedRefusalControls'=>418,'nativeErrorsPreserved'=>6,'oneThreeWorkersEqual'=>true,'completeResidualIssuesEqual'=>true,'closedReceipts'=>$receipts],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "PASS: object boolean advisories; 11 genuine Warnings, 418 native-derived refusal controls, six Errors retained.\n";
