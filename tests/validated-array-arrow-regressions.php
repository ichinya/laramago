<?php
declare(strict_types=1);
$workspace=str_replace('\\','/',sys_get_temp_dir()).'/laramago lexical arrows '.bin2hex(random_bytes(8));

// Analyze independent lexical arrow fixtures without executing their bodies.

require __DIR__.'/../vendor/autoload.php';

mkdir($workspace,0777,true);
$arrows=[
    'integerSpaceship'=>['fn($left,$right)=>$left[\'label\']<=>$right[\'label\']',true],
    'integerTernary'=>['fn($left,$right)=>$left[\'label\']===$right[\'label\']?0:($left[\'label\']<=>$right[\'label\'])',true],
    'integerElvis'=>['fn($left,$right)=>($left[\'label\']<=>$right[\'label\'])?:1',true],
    'declaredString'=>['fn($left,$right):string=>$left[\'label\']<=>$right[\'label\']',false],
    'declaredInt'=>['fn($left,$right):int=>$left[\'label\']<=>$right[\'label\']',false],
    'documentedArrow'=>['/** @return string */ fn($left,$right)=>$left[\'label\']<=>$right[\'label\']',false],
    'attributedArrow'=>['#[Example] fn($left,$right)=>$left[\'label\']<=>$right[\'label\']',false],
    'typedParameter'=>['fn(array $left,$right)=>$left[\'label\']<=>$right[\'label\']',false],
    'referenceParameter'=>['fn(&$left,$right)=>$left[\'label\']<=>$right[\'label\']',false],
    'referenceReturn'=>['fn&($left,$right)=>$left[\'label\']<=>$right[\'label\']',false],
    'defaultParameter'=>['fn($left,$right=[])=>$left[\'label\']<=>$right[\'label\']',false],
    'variadicParameter'=>['fn($left,...$right)=>$left[\'label\']<=>$right[\'label\']',false],
    'capturedResult'=>['fn($left,$right)=>$other',false],
    'helperCall'=>['fn($left,$right)=>strcmp($left[\'label\'],$right[\'label\'])',false],
    'nonIntegerBranch'=>['fn($left,$right)=>$left[\'label\']===$right[\'label\']?0:$left[\'label\']',false],
    'unprovedField'=>['fn($left,$right)=>$left[\'unknown\']<=>$right[\'unknown\']',false],
    'callbackWrite'=>['fn($left,$right)=>($left[\'label\']=\'changed\')<=>$right[\'label\']',false],
];
$template=<<<'PHP'
<?php
declare(strict_types=1);
namespace LexicalArrowRegression;
/** @param list<mixed> $rows @return list<array{label:string}> */
function CASE_NAME(array $rows):array {
    foreach($rows as $row) {
        if(!is_array($row)||!is_string($row['label']??null)) { throw new \InvalidArgumentException('Malformed row.'); }
    }
    usort($rows,ARROW);
    return $rows;
}
PHP;
$rows=[];
foreach($arrows as $name=>[$arrow,$expected]) {
    $bytes=str_replace(['CASE_NAME','ARROW'],[$name,$arrow],$template);
    $file=$workspace.'/'.$name.'.php';file_put_contents($file,$bytes);
    $candidate=(new \Ichinya\Laramago\Analyzer\StaticAnalysis\ValidatedSortedArrayProofs)->inspect($bytes)['candidates'][0]??null;
    $accepted=($candidate['sourceStructureAccepted']??false)===true;
    if($accepted!==$expected) { throw new RuntimeException('Lexical source regression failed: '.$name); }
    $rows[$name]=['file'=>$file,'sourceSha256'=>hash('sha256',$bytes),'sourceCandidateAccepted'=>$accepted,'candidate'=>$candidate,
        'expectedNativeRemovals'=>$expected?count($candidate['comparatorReadSpans']):0,'nativeReceiptRequired'=>true];
}
file_put_contents($workspace.'/source-only-regressions.json',json_encode(['sourceOnly'=>true,'nativeAcceptance'=>false,'analyzerJobsStarted'=>0,'cases'=>$rows],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));




// Preserve closed native receipts and compare every unrelated complete issue.

$package=str_replace('\\','/',dirname(__DIR__));$root=str_replace('\\','/',$workspace);
$prepared=json_decode(file_get_contents($root.'/source-only-regressions.json'),true,flags:JSON_THROW_ON_ERROR);
if(($prepared['sourceOnly']??false)!==true||count($prepared['cases']??[])!==17) { throw new RuntimeException('Expected seventeen source regressions.'); }
$held=[];foreach($prepared['cases'] as $case) { $held[$case['file']]=$case['sourceSha256']; }
$reports=[];$receipts=[];
foreach(['native'=>1,'without-provider'=>1,'without-provider-three'=>3] as $mode=>$workers) {
    foreach($held as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Prepared arrow source changed.'); } }
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','threads'=>1,'source'=>['paths'=>array_map('basename',array_keys($held))],
        'extension-hosts'=>['test'=>['command'=>[PHP_BINARY,'-d','opcache.enable_cli=0',str_replace('\\','/',__DIR__).'/fixtures/analysis/validated-array-contract-worker.php',
            $package.'/vendor/autoload.php',$root,$mode==='native'?'native':'without-provider',$package],'workers'=>$workers,'request-timeout-ms'=>120000]]];
    $configPath=$root.'/'.$mode.'.json';$reportPath=$root.'/'.$mode.'-report.json';$stderrPath=$root.'/'.$mode.'.stderr';
    if(file_exists($reportPath)) { throw new RuntimeException('Preserve existing regression run; fresh directory required.'); }
    file_put_contents($configPath,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $process=proc_open([PHP_BINARY,$package.'/vendor/bin/mago','--workspace',$root,'--config',$configPath,'analyze','--reporting-format=json'],
        [1=>['file',$reportPath,'w'],2=>['file',$stderrPath,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot start native arrow regression.'); }
    $exit=proc_close($process);copy($reportPath,$reportPath.'.raw');
    $receipts[$mode]=['closed'=>true,'exitCode'=>$exit,'workers'=>$workers,'rawSha256'=>hash_file('sha256',$reportPath.'.raw'),'stderrSha256'=>hash_file('sha256',$stderrPath)];
    file_put_contents($root.'/'.$mode.'-closed-receipt.json',json_encode($receipts[$mode],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|fatal|hook[^\r\n]*failed|timed? out|timeout|parse error|PHP Warning/i',file_get_contents($stderrPath))) { throw new RuntimeException('Invalid native arrow gate: '.$mode); }
    $report=json_decode(file_get_contents($reportPath),true,flags:JSON_THROW_ON_ERROR);if(!isset($report['issues'])||!is_array($report['issues'])) { throw new RuntimeException('Missing native arrow report.'); }
    $reports[$mode]=$report['issues'];
}
$identity=static function(array $issues):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows; };
$excluded=[];$byCase=[];
foreach($prepared['cases'] as $name=>$case) {
    $native=array_values(array_filter($reports['native'],static function(array $issue)use($name):bool {
        foreach($issue['annotations'] as $annotation) { if($annotation['kind']==='Primary') { return $annotation['span']['file_id']['name']===$name.'.php'&&$issue['level']==='Error'; } }
        return false;
    }));
    if($native===[]) { throw new RuntimeException('Vacuous genuine negative/positive arrow regression: '.$name); }
    if(!$case['sourceCandidateAccepted']) { $byCase[$name]=['nativeErrors'=>count($native),'expectedRemovals'=>0];continue; }
    $candidate=$case['candidate'];$selected=[];
    foreach($native as $issue) {
        foreach($issue['annotations'] as $annotation) {
            if($annotation['kind']!=='Primary') { continue; }$span=[$annotation['span']['start']['offset'],$annotation['span']['end']['offset']];
            if($issue['code']==='mixed-array-access'&&in_array($span,$candidate['comparatorReadSpans'],true)
                ||$issue['code']==='less-specific-nested-return-statement'&&$span===$candidate['returnExpressionSpan']) { $selected[]=$issue; }
        }
    }
    if(count($selected)!==$case['expectedNativeRemovals']) { throw new RuntimeException('Genuine lexical arrow positive count changed: '.$name); }
    $excluded=[...$excluded,...$identity($selected)];$byCase[$name]=['nativeErrors'=>count($native),'expectedRemovals'=>count($selected)];
}
$expected=array_values(array_filter($reports['native'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$excluded,true)));
if($identity($expected)!==$identity($reports['without-provider'])||$identity($expected)!==$identity($reports['without-provider-three'])) { throw new RuntimeException('Exact arrow delta or provider-free one/three-worker equality failed.'); }
foreach($held as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Arrow source changed during gate.'); } }
file_put_contents($root.'/accepted-arrow-regressions.json',json_encode(['genuineNativeIssues'=>count($reports['native']),'removed'=>count($excluded),'caseReceipts'=>$byCase,
    'allOtherCompleteIssuesKept'=>true,'providerFreeOneThreeWorkersMatch'=>true,'closedReceipts'=>$receipts],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS genuine provider-free arrow regressions.'.PHP_EOL;
