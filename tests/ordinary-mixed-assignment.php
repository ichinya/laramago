<?php
declare(strict_types=1);
// Analyze invented fixtures. Fixture bodies and consumer bootstrap/autoload files must never execute.
require __DIR__.'/../vendor/autoload.php';
$package=str_replace('\\','/',dirname(__DIR__));$fixtures=$package.'/tests/fixtures/ordinary-mixed-assignment';
$workspace=str_replace('\\','/',sys_get_temp_dir()).'/laramago ordinary mixed '.bin2hex(random_bytes(8));
foreach(['app','packages/composer','observe','controls','controls-three','controls-single']as$directory){mkdir($workspace.'/'.$directory,recursive:true);}
foreach(glob($fixtures.'/app/*.php')as$file){file_put_contents($workspace.'/app/'.basename($file),str_replace("\r\n","\n",file_get_contents($file)));}
file_put_contents($workspace.'/composer.json','{"config":{"vendor-dir":"packages"},"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php','<?php file_put_contents(__DIR__."/bootstrap-executed.marker","executed"); throw new RuntimeException("Consumer bootstrap must never execute.");');
file_put_contents($workspace.'/packages/composer/installed.json','{"packages":[]}');
file_put_contents($workspace.'/packages/composer/autoload_files.php','<?php file_put_contents(__DIR__."/autoload-executed.marker","executed"); throw new RuntimeException("Consumer autoload must never execute.");');
$header='<?php declare(strict_types=1); ini_set("display_errors","stderr"); require '.var_export($package.'/vendor/autoload.php',true).'; ';
file_put_contents($workspace.'/worker.php',$header.'(new Mago\Sdk\Worker(new Mago\Sdk\Extension("fixture/ordinary-mixed","Ordinary mixed","1",analyzerPlugins:[new Ichinya\Laramago\Analyzer\OrdinaryMixedAssignmentPlugin()])))->run();');
foreach(['observe','controls','controls-three','controls-single']as$mode){file_put_contents($workspace.'/'.$mode.'-worker.php',$header.'require '.var_export($fixtures.'/OrdinaryMixedControlObserver.php',true).'; (new Mago\Sdk\Worker(new Mago\Sdk\Extension("fixture/ordinary-mixed-observer","Ordinary mixed observer","1",analyzerPlugins:[new Ichinya\Laramago\Tests\Support\OrdinaryMixedControlObserver('.var_export($workspace.'/'.$mode,true).','.($mode==='observe'?'true':'false').')])))->run();');}
$manifest=json_decode(file_get_contents($fixtures.'/manifest.json'),true,flags:JSON_THROW_ON_ERROR);$receipts=[];
foreach($manifest as$name=>$case){$bytes=file_get_contents($workspace.'/'.$case['file']);$proofs=(new Ichinya\Laramago\Analyzer\StaticAnalysis\OrdinaryMixedBindings())->inspect($bytes);
    if(hash('sha256',$bytes)!==$case['sourceSha256']||$proofs!==$case['sourceCandidates']){throw new RuntimeException('Source certificate changed: '.$name);}$receipts[$name]=['sha256'=>hash('sha256',$bytes),'sourceCandidates'=>$proofs];}
$parser=(new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
foreach(['worker.php','observe-worker.php','controls-worker.php','controls-three-worker.php','controls-single-worker.php','bootstrap.php','packages/composer/autoload_files.php']as$file){$parser->parse(file_get_contents($workspace.'/'.$file));}
file_put_contents($workspace.'/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/settings.json',json_encode(['sourceRoot'=>$workspace,'outputRoot'=>$workspace],JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/source-receipts.json',json_encode($receipts,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if(in_array('--prepare-only',$argv,true)){echo 'Prepared source and workers without analyzer or fixture execution: '.$workspace.PHP_EOL;exit(0);}
$binary=getenv('MAGO_BINARY')?:$package.'/vendor/bin/mago';$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];
$run=static function(string$label,?string$worker=null,int$workers=1,bool$single=false)use($workspace,$package,$command):array{
    $config=['php-version'=>'8.5','source'=>['paths'=>$single?['app/coalesce-result.php']:['app'],'includes'=>$single?['app/contracts.php']:[]],
        'analyzer'=>['find-unused-definitions'=>false,'find-unused-parameters'=>false,'check-throws'=>false,'check-missing-override'=>false,'check-missing-type-hints'=>false,'register-super-globals'=>true,'ignore'=>[]]];
    if($worker!==null){$config['extension-hosts']=['ordinary-mixed'=>['command'=>[PHP_BINARY,'-d','opcache.enable_cli=0','-d','display_errors=stderr',$worker,$package.'/vendor/autoload.php',$workspace],'workers'=>$workers]];}
    $configPath=$workspace.'/'.$label.'.json';file_put_contents($configPath,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $reportPath=$workspace.'/report-'.$label.'.json';$stderrPath=$workspace.'/report-'.$label.'.stderr';
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configPath,'analyze','--reporting-format=json'],[0=>['pipe','r'],1=>['file',$reportPath,'w'],2=>['file',$stderrPath,'w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('Cannot start analyzer: '.$label);}fclose($pipes[0]);$exit=proc_close($process);$stderr=file_get_contents($stderrPath);
    if(!in_array($exit,[0,1],true)||preg_match('/provider failed|native analysis fallback|invalid extension frame|rejected request|hook .* failed|pars(?:e|ing)\s+errors?|incomplete codebase|timed out|did not answer|fatal error|orchestrator error|uncaught/i',$stderr)===1){throw new RuntimeException('Operational analyzer failure '.$label.'; '.$workspace);}
    $report=json_decode(file_get_contents($reportPath),true,flags:JSON_THROW_ON_ERROR);
    if(!is_array($report['issues']??null)||$report['issues']===[]){throw new RuntimeException('Nonempty complete native report required: '.$label);}return$report['issues'];
};
$signature=static function(array$issues):array{$rows=array_map(static fn(array$issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return$rows;};
$native=$run('native');$run('observe',$workspace.'/observe-worker.php');$isolated=$run('draft',$workspace.'/worker.php');
$run('controls',$workspace.'/controls-worker.php');$run('draft-three',$workspace.'/worker.php',3);$run('controls-three',$workspace.'/controls-three-worker.php',3);
$run('native-single',single:true);$run('draft-single',$workspace.'/worker.php',single:true);$run('controls-single',$workspace.'/controls-single-worker.php',single:true);
$process=proc_open([PHP_BINARY,$fixtures.'/verify.php',$workspace],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);
if(!is_resource($process)){throw new RuntimeException('Cannot start offline verification.');}fclose($pipes[0]);
if(proc_close($process)!==0){throw new RuntimeException('Whole native signature/control failure; '.$workspace);}
if(in_array('--integrated',$argv,true)){
    // Existing local-binding policies independently accept two by-value parameter/capture cases.
    // Measure the complete production registry with only this new plugin omitted.
    $production=file_get_contents($package.'/bin/laramago-worker.php');
    $anchor='        new OrdinaryMixedAssignmentPlugin,';
    if(substr_count($production,$anchor)!==1){throw new RuntimeException('Unique production registration required.');}
    $beforeWorker=$workspace.'/integrated-before-worker.php';
    file_put_contents($beforeWorker,str_replace($anchor,'',$production));
    $parser->parse(file_get_contents($beforeWorker));
    $before=$run('integrated-before',$beforeWorker);
    $removed=array_diff($signature($native),$signature($isolated));
    $expected=$signature($before);$newCorrections=0;
    foreach($removed as$record){$index=array_search($record,$expected,true);if($index!==false){unset($expected[$index]);++$newCorrections;}}
    $expected=array_values($expected);sort($expected);
    if($newCorrections===0){throw new RuntimeException('Integrated new policy must exercise actual corrections.');}
    foreach(array_diff($signature($native),$signature($before))as$record){
        if(in_array($record,$removed,true)){continue;}
        $issue=json_decode($record,true,flags:JSON_THROW_ON_ERROR);$primary=null;
        foreach($issue['annotations']as$annotation){if($annotation['kind']==='Primary'){$primary=$annotation;break;}}
        $name=basename(str_replace('\\','/',$primary['span']['file_id']['name']??''));
        $start=$primary['span']['start']['offset']??null;$end=$primary['span']['end']['offset']??null;
        if($issue['level']!=='Warning'||$issue['code']!=='mixed-assignment'
            ||!(($name==='captured-value-write.php'&&$start===460&&$end===467)||($name==='parameter-write.php'&&$start===434&&$end===441))){
            throw new RuntimeException('Unexpected preexisting policy effect: '.$workspace);
        }
    }
    foreach([1,3]as$workers){
        $full=$run('integrated-'.$workers,$package.'/bin/laramago-worker.php',$workers);
        if($signature($full)!==$expected){throw new RuntimeException('Integrated complete policy delta mismatch: '.$workspace);}
        $errors=static fn(array$issues):array=>array_values(array_filter($issues,static fn(array$issue):bool=>$issue['level']==='Error'));
        if($signature($errors($full))!==$signature($errors($before))){throw new RuntimeException('Integrated native Errors changed.');}
    }
    file_put_contents($workspace.'/integrated-policy-receipt.json',json_encode(['newExactCorrections'=>$newCorrections,'completeNativeErrorsPreserved'=>true,'oneThreeWholeMultisetsEqual'=>true,'preexistingPolicyMeasuredWithOnlyNewPluginOmitted'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
}
foreach(['app/executed.marker','bootstrap-executed.marker','packages/composer/autoload-executed.marker']as$marker){if(file_exists($workspace.'/'.$marker)){throw new RuntimeException('Fixture or consumer autoload executed.');}}
echo 'PASS: 41 invented cases, 14 exact Warning records, 29 nonempty negatives, preserved native Errors and genuine one/three/single controls; evidence='.$workspace.PHP_EOL;
