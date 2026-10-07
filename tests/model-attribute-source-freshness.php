<?php
declare(strict_types=1);
// Analyze the existing neutral declarations; fixture and application bodies stay unexecuted.
$package=str_replace('\\','/',dirname(__DIR__));
$fixture=__DIR__.'/fixtures/model-attribute-source-freshness';
require $package.'/vendor/autoload.php';
$heldSourceHashes=[];
foreach([
    'tests/refreshed-model-properties.php',
    'tests/fixtures/analysis/factory-result-LICENSE.md',
    'src/Analyzer/StaticAnalysis/PhpSource.php',
    'src/Analyzer/StaticAnalysis/ModelAttributeReadDomains.php',
    'src/Analyzer/StaticAnalysis/RefreshedModelProperties.php',
    'src/Analyzer/RefreshedModelPropertyIssueFilter.php',
    'src/Analyzer/StaticAnalysis/NativeModelAttributeRefresh.php',
    'src/Analyzer/StaticAnalysis/ModelReflection.php',
    'src/Analyzer/StaticAnalysis/ModelPropertyReadContracts.php',
    'src/Analyzer/StaticAnalysis/SchemaIndex.php',
    'src/Analyzer/DeprecatedMethodCompatibilityProof.php',
    'tests/fixtures/model-attribute-source-freshness/NativeFreshnessControls.php',
    'tests/fixtures/model-attribute-source-freshness/worker.php',
    'tests/model-attribute-source-freshness.php',
] as $relative) {
    $file=$package.'/'.$relative;$hash=hash_file('sha256',$file);
    if($hash===false) { throw new RuntimeException('A required regression source is absent.'); }
    $heldSourceHashes[$file]=$hash;
}
$process=proc_open([PHP_BINARY,'-d','memory_limit=512M','-d','opcache.enable_cli=0',$package.'/tests/refreshed-model-properties.php','--prepare-only'],
    [1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
if(!is_resource($process)) { throw new RuntimeException('Cannot prepare the existing neutral native fixture.'); }
$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
if($exit!==0||$stderr!==''||preg_match('/^Prepared and parsed [0-9]+ generated sources without analyzer or fixture execution: (.+)\r?$/m',$stdout,$match)!==1) {
    throw new RuntimeException('The unchanged neutral fixture source preparation failed.');
}
$workspace=str_replace('\\','/',trim($match[1]));
file_put_contents($workspace.'/freshness-prepare.stdout',$stdout);file_put_contents($workspace.'/freshness-prepare.stderr',$stderr);
$includes=['models.php','support.php'];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace.'/packages',FilesystemIterator::SKIP_DOTS)) as $file) {
    if($file->isFile()&&strtolower($file->getExtension())==='php') { $includes[]=substr(str_replace('\\','/',$file->getPathname()),strlen($workspace)+1); }
}
sort($includes);$held=[];foreach(['composer.json','bootstrap.php','proof.php',...$includes] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
if(in_array('--prepare-only',$argv,true)) {
    foreach($held+$heldSourceHashes as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('A prepared source changed.'); } }
    if(file_exists($workspace.'/executed')) { throw new RuntimeException('A neutral fixture or application bootstrap body executed.'); }
    echo 'Prepared model source freshness declarations without analyzer or fixture execution: '.$workspace."\n";exit(0);
}
$binary=$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');if(!is_file($binary)) { $binary=$package.'/vendor/bin/mago'; }
$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];$reports=[];
foreach(['native','controls'] as $mode) {
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','source'=>['paths'=>['proof.php'],'includes'=>$includes],
        'extension-hosts'=>$mode==='native'?new stdClass:['freshness'=>['command'=>[PHP_BINARY,'-d','memory_limit=512M','-d','opcache.enable_cli=0',$fixture.'/worker.php',$package.'/vendor/autoload.php',$workspace],'workers'=>1,'request-timeout-ms'=>120000]]];
    if($mode==='controls') { $config['threads']=1; }
    $configPath=$workspace.'/freshness-'.$mode.'.config.json';file_put_contents($configPath,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $raw=$workspace.'/freshness-'.$mode.'.json.raw';$log=$workspace.'/freshness-'.$mode.'.stderr';
    $arguments=[...$command,'--workspace',$workspace,'--config',$configPath,'analyze','--reporting-format=json'];
    $process=proc_open($arguments,[1=>['file',$raw,'w'],2=>['file',$log,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot start the parent-owned native regression.'); }$exit=proc_close($process);
    file_put_contents($workspace.'/freshness-'.$mode.'.closed.json',json_encode(['closed'=>true,'mode'=>$mode,'command'=>$arguments,'exitCode'=>$exit,
        'rawSha256'=>hash_file('sha256',$raw),'stderrSha256'=>hash_file('sha256',$log),'configSha256'=>hash_file('sha256',$configPath)],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    if(!in_array($exit,[0,1],true)||preg_match('/failed|rejected request|protocol error|panicked|fallback|fatal|timed? out|timeout|PHP Warning|parse error/i',file_get_contents($log))) {
        throw new RuntimeException('The native regression failed operationally; its raw and closed receipts are preserved.');
    }
    $reports[$mode]=json_decode(file_get_contents($raw),true,flags:JSON_THROW_ON_ERROR)['issues'];
    if($mode==='native') {
        $positive=array_filter($reports[$mode],static function(array $issue):bool {
            if($issue['level']!=='Error'||$issue['code']!=='impossible-type-comparison') { return false; }
            foreach($issue['annotations'] as $annotation) { if($annotation['kind']==='Primary'&&$annotation['span']['file_id']['name']==='proof.php') { return true; } }return false;
        });
        if(count($positive)!==1) { throw new RuntimeException('The unchanged neutral refresh does not have one genuine native Error witness.'); }
    }
    foreach($held+$heldSourceHashes as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Source bytes were not fully held or restored.'); } }
}
$signature=static function(array $issues):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows; };
if($signature($reports['native'])!==$signature($reports['controls'])) { throw new RuntimeException('The Always-Keep observer changed a complete native issue.'); }
$checks=json_decode(file_get_contents($workspace.'/freshness-controls.json'),true,flags:JSON_THROW_ON_ERROR);
if($checks['status']!=='PASS'||$checks['genuinePositive']!==true||$checks['restored']!==true||$checks['sourceMutations']!==1||count($checks['checks'])!==13||in_array(false,$checks['checks'],true)) {
    throw new RuntimeException('The actual eviction/source/restoration regression is incomplete.');
}
if(file_exists($workspace.'/executed')) { throw new RuntimeException('A neutral fixture or application bootstrap body executed.'); }
file_put_contents($workspace.'/freshness-accepted.json',json_encode(['status'=>'PASS','wholeNativeDtoIdentity'=>true,'genuinePositive'=>true,
    'checks'=>count($checks['checks']),'actualSourceMutations'=>1,'originalSourceRestored'=>true,'fixtureBodiesExecuted'=>false,
    'existingRegisteredMetadataControlsUnchanged'=>true,'workspace'=>$workspace],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS model source freshness: actual unscanned dependency evicted, changed source rejected, exact restoration readmitted; complete native DTOs unchanged. '.$workspace."\n";
