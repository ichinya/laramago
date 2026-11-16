<?php
declare(strict_types=1);

/**
 * When the engine already accepts every positive source owner, require exact
 * native/adapter and full-before/full-after reports. No issue context is invented.
 * The caller still owns source hashing, operational checks and legacy controls.
 *
 * @param list<array{file:string,start:int,end:int,codes?:list<string>}> $positiveRanges
 */
function nativeCoveredSourceRanges(array $native,array $positiveRanges):bool
{
    if($positiveRanges===[]){throw new RuntimeException('Positive source owners are required.');}
    foreach($native as$issue){
        foreach($issue['annotations']as$a){
            if($a['kind']!=='Primary'){continue;}
            foreach($positiveRanges as$range){
                if($a['span']['file_id']['name']===$range['file']&&$a['span']['start']['offset']>=$range['start']&&$a['span']['end']['offset']<=$range['end']
                    &&(!isset($range['codes'])||in_array($issue['code'],$range['codes'],true))){return false;}
            }
        }
    }
    return true;
}
function nativeCoveredReports(array $reports,array $positiveRanges,array $closed,?array $negativeFiles=null):?array
{
    if(!nativeCoveredSourceRanges($reports['native'],$positiveRanges)){return null;}
    $signature=static function(array $issues):array{$rows=array_map(static fn(array $i):string=>json_encode($i,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows;};
    foreach(['native'=>['observe','isolated','isolated-three','controls'],'full-before'=>['full-after','full-after-three']]as$before=>$afterModes){
        foreach($afterModes as$after){
            if(!isset($reports[$before],$reports[$after],$closed[$before],$closed[$after])||!($closed[$before]['closed']??false)||!($closed[$after]['closed']??false)
                ||$signature($reports[$before])!==$signature($reports[$after])){throw new RuntimeException('Native-covered whole-report identity failed: '.$before.' -> '.$after);}
        }
    }
    $negativeErrors=array_filter($reports['native'],static function(array $issue)use($negativeFiles):bool{
        if($issue['level']!=='Error'){return false;}if($negativeFiles===null){return true;}
        foreach($issue['annotations']as$a){if($a['kind']==='Primary'){return in_array($a['span']['file_id']['name'],$negativeFiles,true);}}return false;
    });
    if($negativeErrors===[]){throw new RuntimeException('Independent genuine negative Errors must remain observable.');}
    return ['status'=>'PASS','nativeCoveredPositiveOwners'=>$positiveRanges,'exactDiagnosticsRemoved'=>0,
        'genuineNegativeErrorsPreserved'=>count($negativeErrors),'wholeNativeAndFullRegistryIdentity'=>true,'oneThreeWorkersMatch'=>true,
        'legacyPositiveIssueMutationControlsExecuted'=>false,'fabricatedIssueContexts'=>0,'fixtureBodiesExecuted'=>false,'closedReceipts'=>$closed];
}
