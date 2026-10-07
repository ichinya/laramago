<?php

declare(strict_types=1);

// Offline complete issue records; no application/fixture/SDK execution.
$settings = json_decode(file_get_contents(($argv[1]??'') . '/settings.json'),true,flags:JSON_THROW_ON_ERROR);
$output = $argv[1] ?? $settings['outputRoot'];
$manifest = json_decode(file_get_contents(__DIR__.'/manifest.json'),true,flags:JSON_THROW_ON_ERROR)['cases'];
$reports = [];
foreach (['native','observe','draft','controls','draft-three','single-native','single-draft'] as $mode) {
    $report = json_decode(file_get_contents($output.'/report-'.$mode.'.json'),true,flags:JSON_THROW_ON_ERROR);
    if (! is_array($report['issues'] ?? null)) { throw new RuntimeException('Complete report required: '.$mode); }
    $reports[$mode]=$report['issues'];
}
$primary = static function(array $issue,string $file,array $span,bool $contained=false):bool {
    foreach ($issue['annotations'] ?? [] as $annotation) {
        if (($annotation['kind'] ?? null)!=='Primary' || !str_ends_with(str_replace('\\','/',$annotation['span']['file_id']['name']),$file)) { continue; }
        $start=$annotation['span']['start']['offset']; $end=$annotation['span']['end']['offset'];
        if ($contained ? $start>=$span[0] && $end<=$span[1] : $start===$span[0] && $end===$span[1]) { return true; }
    }
    return false;
};
$allowed=[]; $negatives=0; $independentErrors=0;
foreach ($manifest as $case=>$entry) {
    $errors=array_filter($reports['native'],static function(array $issue)use($entry,$primary):bool {
        if (($issue['level']??null)!=='Error') { return false; }
        foreach($entry['independentErrorSites'] as $span) { if($primary($issue,$entry['file'],$span,true)) { return true; } }
        return false;
    });
    if ($errors===[]) { throw new RuntimeException('Vacuous independent Error control: '.$case); }
    $independentErrors+=count($errors);
    if($entry['kind']==='negative') { $negatives++; continue; }
    $issues=array_values(array_filter($reports['native'],static function(array $issue)use($entry,$primary):bool {
        if(($issue['level']??null)!=='Warning' || ($issue['code']??null)!=='redundant-condition'
            || ($issue['message']??null)!=='This condition (type `true`) will always evaluate to true.') { return false; }
        foreach($entry['arraySites'] as $span) { if($primary($issue,$entry['file'],$span)) { return true; } }
        return false;
    }));
    if(count($issues)!==1) { throw new RuntimeException('Missing/ambiguous actual array advisory: '.$case); }
    $allowed[]=$issues[0];
}
$keys=static function(array $issues):array { $keys=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($keys);return $keys; };
$expected=$reports['native'];
foreach($allowed as $issue) { $key=array_search($issue,$expected,true); if($key===false) {throw new RuntimeException('Exact native advisory absent.');} unset($expected[$key]); }
$checks=['observe'=>$keys($reports['native'])===$keys($reports['observe'])];
foreach(['draft','controls','draft-three'] as $mode) { $checks[$mode]=$keys($expected)===$keys($reports[$mode]); }
$singleAllowed=array_values(array_filter($allowed,static function(array $issue)use($primary,$manifest):bool { return $primary($issue,$manifest['ordinary']['file'],$manifest['ordinary']['arraySites'][0]); }));
$singleExpected=$reports['single-native'];
foreach($singleAllowed as $issue) { $key=array_search($issue,$singleExpected,true); if($key===false) {throw new RuntimeException('Single-file native envelope changed.');} unset($singleExpected[$key]); }
$checks['single']=$keys($singleExpected)===$keys($reports['single-draft']);
$eligible=0; $controlChecks=0; $companions=0;
foreach(file($output.'/controls/issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    $record=json_decode($line,true,flags:JSON_THROW_ON_ERROR);
    if(!$record['actualNativeContext'] || $record['nativeTypesChanged'] || $record['nativeContextOrCacheMutated']) {throw new RuntimeException('Native context/type mutation.');}
    if($record['facts']['arrayAdvisoryEligible']) {
        if($record['decision']!=='Remove' || $record['controls']['checks']!==55+($record['controls']['conditionalBranchChecks']??-100) || !$record['controls']['actualEligibleNativeContext']) {throw new RuntimeException('Actual eligible controls missing.');}
        $eligible++; $controlChecks+=$record['controls']['checks'];
    } elseif(($record['facts']['sourceCertificate']['selectedSite']??null)==='string-companion-observation-only') {
        if($record['decision']!=='Keep') {throw new RuntimeException('Unmeasured string companion removed.');} $companions++;
    }
}
foreach(['fixture-body-executed.txt','bootstrap-executed.txt'] as $marker) { if(file_exists($settings['sourceRoot'].'/app/'.$marker)||file_exists($settings['sourceRoot'].'/'.$marker)) {throw new RuntimeException('Fixture/app execution trap exists.');} }
$summary=['wholeNativeMultisetChecks'=>$checks,'genuineArrayAdvisories'=>count($allowed),'nonemptyNegativeCases'=>$negatives,'independentErrorsPreserved'=>$independentErrors,
    'actualEligibleContexts'=>$eligible,'genuineNegativeChecks'=>$controlChecks,'nativeStringCompanionsObservedAndKept'=>$companions,'nativeTypesChanged'=>false,'privateDiagnosticRemovalClaim'=>false];
file_put_contents($output.'/verification.json',json_encode($summary,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));echo json_encode($summary,JSON_THROW_ON_ERROR).PHP_EOL;
if(count($allowed)!==5 || $negatives!==23 || $eligible!==5 || $controlChecks<275 || in_array(false,$checks,true)) {exit(1);}
