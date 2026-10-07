<?php
declare(strict_types=1);
// Offline native complete report verification; no SDK/context/source bodies execute.
$settings=json_decode(file_get_contents($argv[1].'/settings.json'),true,flags:JSON_THROW_ON_ERROR);
$output=$settings['outputRoot'];$manifest=json_decode(file_get_contents(__DIR__.'/manifest.json'),true,flags:JSON_THROW_ON_ERROR);$reports=[];
foreach(['native','observe','draft','draft-three','native-single','draft-single','controls','controls-three','controls-single']as$mode){$file=$output.'/report-'.$mode.'.json';
    if(!is_file($file)||filesize($file)===0){throw new RuntimeException('Missing complete native report: '.$mode);}
    $report=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
    if(!is_array($report['issues']??null)||$report['issues']===[]){throw new RuntimeException('Nonempty genuine native issues required.');}$reports[$mode]=$report['issues'];
}
$keys=static function(array$issues):array{$keys=array_map(static fn(array$i):string=>json_encode($i,JSON_THROW_ON_ERROR),$issues);sort($keys);return$keys;};
$errorKeys=static fn(array$issues):array=>$keys(array_values(array_filter($issues,static fn(array$i):bool=>$i['level']==='Error')));
$primary=static function(array$i):?array{$p=array_values(array_filter($i['annotations']??[],static fn(array$a):bool=>$a['kind']==='Primary'));return count($p)===1?$p[0]:null;};
$allRemoved=[];$negative=[];$positive=[];$independentErrors=[];
foreach($manifest as$name=>$case){
    $source=file_get_contents($settings['sourceRoot'].'/'.$case['file']);if(hash('sha256',$source)!==$case['sourceSha256']){throw new RuntimeException('Fixture source changed.');}
    $issues=array_values(array_filter($reports['native'],static fn(array$i):bool=>($primary($i)['span']['file_id']['name']??null)===$case['file']));
    if($issues===[]){throw new RuntimeException('Vacuous actual-native fixture baseline: '.$name);}
    $eligible=[];
    foreach($issues as$i){
        if(($i['code']??null)!=='mixed-assignment'||$i['level']!=='Warning'){continue;}
        $a=$primary($i);$s=$a['span'];$proof=$case['sourceCandidates'][$s['start']['offset'].':'.$s['end']['offset']]??null;
        if($proof===null){continue;}
        if($i['message']!=='Assigning `mixed` type to a variable may lead to unexpected behavior.'
            ||$i['notes']!==['Using `mixed` can lead to runtime errors if the variable is used in a way that assumes a specific type.']
            ||$i['help']!=='Consider using a more specific type to avoid potential issues.'||$a['message']!=='Assigning `mixed` type here.'
            ||!in_array(count($i['annotations']),[1,2],true)){throw new RuntimeException('Changed genuine whole native advisory envelope.');}
        $secondary=array_values(array_filter($i['annotations'],static fn(array$a):bool=>$a['kind']==='Secondary'));
        if(count($i['annotations'])===2){
            if(count($secondary)!==1||$secondary[0]['message']!=='This expression has type `mixed`.'
                ||[$secondary[0]['span']['start']['offset'],$secondary[0]['span']['end']['offset']]!==$proof['rhs']
                ||$secondary[0]['span']['file_id']!==$a['span']['file_id']){throw new RuntimeException('Secondary RHS not exactly current source-bound.');}
        }
        if($case['role']!=='positive'){throw new RuntimeException('Source control unexpectedly eligible: '.$name);}
        $eligible[]=$i;$allRemoved[]=$i;
    }
    if($case['role']==='positive'){
        if($eligible===[]){throw new RuntimeException('Missing genuine positive Warning: '.$name);}
        $positive[$name]=count($eligible);
        $independentErrors[$name]=count(array_filter($issues,static fn(array$i):bool=>$i['level']==='Error'));
    }else{$negative[$name]=count($issues);}
}
$subtract=static function(array$report,array$removed):array{foreach($removed as$issue){$matches=array_keys(array_filter($report,static fn(array$i):bool=>$i===$issue));
    if(count($matches)!==1){throw new RuntimeException('Missing/duplicate exact allowed native record.');}unset($report[$matches[0]]);}return array_values($report);};
foreach(['draft'=>'controls','draft-three'=>'controls-three','draft-single'=>'controls-single']as$production=>$observer){if($keys($reports[$production])!==$keys($reports[$observer])){throw new RuntimeException('Production/control whole native multiset mismatch: '.$production);}}
$expected=$subtract($reports['native'],$allRemoved);$singleRemoved=array_values(array_filter($allRemoved,static fn(array$i):bool=>($primary($i)['span']['file_id']['name']??null)==='app/coalesce-result.php'));
$singleExpected=$subtract($reports['native-single'],$singleRemoved);
$same=['observe'=>$keys($reports['native'])===$keys($reports['observe']),'draft'=>$keys($expected)===$keys($reports['draft']),
    'draft-three'=>$keys($expected)===$keys($reports['draft-three']),'draft-single'=>$keys($singleExpected)===$keys($reports['draft-single'])];
$errorsPreserved=[];foreach(['observe','draft','draft-three']as$mode){$errorsPreserved[$mode]=$errorKeys($reports['native'])===$errorKeys($reports[$mode]);}
$errorsPreserved['draft-single']=$errorKeys($reports['native-single'])===$errorKeys($reports['draft-single']);
$controlCount=0;$bindings=[];$envelopes=['one'=>0,'two'=>0];
foreach(['observe','draft','draft-three','draft-single']as$mode){$receiptMode=['draft'=>'controls','draft-three'=>'controls-three','draft-single'=>'controls-single'][$mode]??$mode;$path=$output.'/'.$receiptMode.'/issues.jsonl';if(!is_file($path)){throw new RuntimeException('Missing genuine observer receipts: '.$receiptMode);}$bound=[];
    foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)as$line){$row=json_decode($line,true,flags:JSON_THROW_ON_ERROR);
        if(!$row['actualNativeContext']||$row['nativeTypeChanged']||$row['runtimePurityGuarantee']){throw new RuntimeException('Invalid actual context/type-preservation receipt.');}
        if($mode==='observe'&&$row['decision']!=='Keep'){throw new RuntimeException('Always-Keep observer changed native issue.');}
        if($row['eligible']&&$mode!=='observe'){if(($row['controls']['checks']??0)!==9){throw new RuntimeException('Missing genuine issue-envelope negative controls.');}$controlCount+=$row['controls']['checks'];}
        if(!$row['eligible']){if($row['decision']!=='Keep'){throw new RuntimeException('Uncertified removal.');}continue;}
        $proof=$row['sourceProof'];$code=$row['wholeNativeEnvelope']['code'];$level=$row['wholeNativeEnvelope']['level']['name'];
        if($proof===null||$code!=='mixed-assignment'||$level!=='Warning'||$proof['nativeTypeChanged']){throw new RuntimeException('Not an exact native Warning/source certificate.');}
        if($mode!=='observe'&&$row['decision']!=='Remove'){throw new RuntimeException('Expected source advisory removal not observed.');}
        $bound[$row['file'].':'.implode(':',$proof['primary'])]=true;
        if($mode==='draft'){$envelopes[count($row['wholeNativeEnvelope']['annotations'])===1?'one':'two']++;}
    }
    $required=$mode==='draft-single'?count($singleRemoved):count($allRemoved);if(count($bound)!==$required){throw new RuntimeException('Missing distinct genuine source/issue bindings: '.$mode);}$bindings[$mode]=count($bound);
}
if(array_sum($independentErrors)===0){throw new RuntimeException('No genuine independent unsafe-use Error controls.');}
foreach(['executed.marker','bootstrap-executed.marker']as$marker){if(file_exists($settings['sourceRoot'].'/'.$marker)||file_exists($settings['sourceRoot'].'/app/'.$marker)){throw new RuntimeException('Execution marker exists.');}}
$summary=['wholeNativeMultisetChecks'=>$same,'completeNativeErrorMultisetsPreserved'=>$errorsPreserved,'genuinePositiveCases'=>$positive,'nonemptyNegativeCases'=>$negative,
    'exactWarningRecordsRemoved'=>count($allRemoved),'actualNativeSourceBindings'=>$bindings,'actualNativeEnvelopeVariants'=>$envelopes,'genuineEnvelopeNegativeChecks'=>$controlCount,
    'independentPositiveUnsafeUseErrorCount'=>array_sum($independentErrors),'nativeTypesChanged'=>false,'ordinarySharedReferencesRemainBoundary'=>true];
file_put_contents($output.'/verification.json',json_encode($summary,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));echo json_encode($summary,JSON_THROW_ON_ERROR).PHP_EOL;
if(in_array(false,$same,true)||in_array(false,$errorsPreserved,true)){throw new RuntimeException('Complete native Warning/Error record multiset mismatch.');}
if(count($positive)!==12||count($negative)!==29){throw new RuntimeException('Incomplete genuine source positive/negative controls.');}
