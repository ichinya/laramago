<?php
declare(strict_types=1);
$package=dirname(__DIR__);$fixture=__DIR__.'/fixtures/defensive-boundary-route-keys';
$workspace=$package.'/var/compatibility-defensive-boundary-route-keys-'.bin2hex(random_bytes(8));mkdir($workspace,recursive:true);
foreach(require $fixture.'/sources.php' as $path=>$bytes){if(!is_dir(dirname($workspace.'/'.$path))){mkdir(dirname($workspace.'/'.$path),recursive:true);}file_put_contents($workspace.'/'.$path,$bytes);}
require $package.'/tests/fixtures/compatibility-bounded-worker-support.php';
$worker=compatibilityProductionWorker($package,$workspace,$fixture,'BOUNDARY_GUARD_CLASSES_ONLY','BoundaryGuardDraftPlugin','DefensiveBoundaryGuardPlugin');
$config=array (
  'extends' => '',
  'php-version' => '8.5',
  'threads' => 1,
  'source' => 
  array (
    'paths' => 
    array (
      0 => 'cases.php',
      1 => 'argv.php',
      2 => 'cli.php',
      3 => 'shadow.php',
      4 => 'mutated-global.php',
    ),
    'includes' => 
    array (
      0 => 'vendor',
    ),
  ),
  'extension-hosts' => 
  array (
  ),
);
$run=static function(string $stage,string $mode,int $workers=1)use($workspace,$package,$worker,$config):array{return compatibilityNativeGate($package,$workspace,$worker,$config,$stage,$mode,$workers);};
$manifest=array (
  'cases' => 
  array (
    'configuration-array' => 
    array (
      'kind' => 'positive',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 270,
        1 => 369,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 274,
            1 => 290,
          ),
          'name' => 'is_array',
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 275,
            1 => 290,
          ),
          'name' => 'is_array',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'configuration-string' => 
    array (
      'kind' => 'positive',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 553,
        1 => 657,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'condition',
          'span' => 
          array (
            0 => 557,
            1 => 598,
          ),
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 558,
            1 => 578,
          ),
          'name' => 'is_string',
        ),
        2 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 557,
            1 => 578,
          ),
          'name' => 'is_string',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'configuration-optional' => 
    array (
      'kind' => 'positive',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 846,
        1 => 946,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'condition',
          'span' => 
          array (
            0 => 850,
            1 => 887,
          ),
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 851,
            1 => 868,
          ),
          'name' => 'is_string',
        ),
        2 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 850,
            1 => 868,
          ),
          'name' => 'is_string',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'configuration-key' => 
    array (
      'kind' => 'positive',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 1114,
        1 => 1201,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 1118,
            1 => 1134,
          ),
          'name' => 'is_string',
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 1119,
            1 => 1134,
          ),
          'name' => 'is_string',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'configuration-report' => 
    array (
      'kind' => 'positive',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 1349,
        1 => 1436,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'condition',
          'span' => 
          array (
            0 => 1353,
            1 => 1385,
          ),
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 1354,
            1 => 1369,
          ),
          'name' => 'is_array',
        ),
        2 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 1353,
            1 => 1369,
          ),
          'name' => 'is_array',
        ),
        3 => 
        array (
          'kind' => 'empty-comparison',
          'span' => 
          array (
            0 => 1373,
            1 => 1385,
          ),
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'configuration-row' => 
    array (
      'kind' => 'positive',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 1514,
        1 => 1576,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 1518,
            1 => 1533,
          ),
          'name' => 'is_array',
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 1519,
            1 => 1533,
          ),
          'name' => 'is_array',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'configuration-field' => 
    array (
      'kind' => 'positive',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 1660,
        1 => 1743,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'condition',
          'span' => 
          array (
            0 => 1664,
            1 => 1693,
          ),
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 1665,
            1 => 1679,
          ),
          'name' => 'is_string',
        ),
        2 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 1664,
            1 => 1679,
          ),
          'name' => 'is_string',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'request-validation' => 
    array (
      'kind' => 'positive',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 1922,
        1 => 2019,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 1926,
            1 => 1944,
          ),
          'name' => 'is_array',
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 1927,
            1 => 1944,
          ),
          'name' => 'is_array',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'request-composite' => 
    array (
      'kind' => 'positive',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 2234,
        1 => 2346,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'condition',
          'span' => 
          array (
            0 => 2238,
            1 => 2295,
          ),
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 2239,
            1 => 2256,
          ),
          'name' => 'is_array',
        ),
        2 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 2261,
            1 => 2295,
          ),
          'name' => 'is_string',
        ),
        3 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 2238,
            1 => 2256,
          ),
          'name' => 'is_array',
        ),
        4 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 2260,
            1 => 2295,
          ),
          'name' => 'is_string',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'fixed-integer-offset' => 
    array (
      'kind' => 'positive',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 2657,
        1 => 2694,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 2661,
            1 => 2675,
          ),
          'name' => 'is_int',
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 2662,
            1 => 2675,
          ),
          'name' => 'is_int',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'arbitrary-formal' => 
    array (
      'kind' => 'negative',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 2803,
        1 => 2886,
      ),
      'sites' => 
      array (
      ),
      'sourceGrammarCandidate' => false,
    ),
    'ordinary-business-branch' => 
    array (
      'kind' => 'negative',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 3030,
        1 => 3057,
      ),
      'sites' => 
      array (
      ),
      'sourceGrammarCandidate' => false,
    ),
    'nonrejecting-body' => 
    array (
      'kind' => 'negative',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 3171,
        1 => 3215,
      ),
      'sites' => 
      array (
      ),
      'sourceGrammarCandidate' => false,
    ),
    'reference-escape' => 
    array (
      'kind' => 'negative',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 3343,
        1 => 3423,
      ),
      'sites' => 
      array (
      ),
      'sourceGrammarCandidate' => false,
    ),
    'condition-assignment' => 
    array (
      'kind' => 'negative',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 3540,
        1 => 3644,
      ),
      'sites' => 
      array (
      ),
      'sourceGrammarCandidate' => false,
    ),
    'unrelated-boolean' => 
    array (
      'kind' => 'negative',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 3770,
        1 => 3869,
      ),
      'sites' => 
      array (
      ),
      'sourceGrammarCandidate' => false,
    ),
    'unknown-producer' => 
    array (
      'kind' => 'negative',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 3957,
        1 => 4041,
      ),
      'sites' => 
      array (
      ),
      'sourceGrammarCandidate' => false,
    ),
    'unpack-format' => 
    array (
      'kind' => 'negative',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 4340,
        1 => 4377,
      ),
      'sites' => 
      array (
      ),
      'sourceGrammarCandidate' => false,
    ),
    'unproved-offset-presence' => 
    array (
      'kind' => 'negative',
      'file' => 'cases.php',
      'guard' => 
      array (
        0 => 4658,
        1 => 4695,
      ),
      'sites' => 
      array (
      ),
      'sourceGrammarCandidate' => false,
    ),
    'argv-normalization' => 
    array (
      'kind' => 'positive',
      'file' => 'argv.php',
      'guard' => 
      array (
        0 => 89,
        1 => 279,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 93,
            1 => 113,
          ),
          'name' => 'is_array',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'argv-element' => 
    array (
      'kind' => 'positive',
      'file' => 'argv.php',
      'guard' => 
      array (
        0 => 199,
        1 => 238,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 203,
            1 => 224,
          ),
          'name' => 'is_string',
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 204,
            1 => 224,
          ),
          'name' => 'is_string',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'cli-list' => 
    array (
      'kind' => 'positive',
      'file' => 'cli.php',
      'guard' => 
      array (
        0 => 59,
        1 => 135,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'condition',
          'span' => 
          array (
            0 => 63,
            1 => 121,
          ),
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 64,
            1 => 78,
          ),
          'name' => 'is_array',
        ),
        2 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 83,
            1 => 102,
          ),
          'name' => 'array_is_list',
        ),
        3 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 63,
            1 => 78,
          ),
          'name' => 'is_array',
        ),
        4 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 82,
            1 => 102,
          ),
          'name' => 'array_is_list',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'cli-slice-element' => 
    array (
      'kind' => 'positive',
      'file' => 'cli.php',
      'guard' => 
      array (
        0 => 220,
        1 => 308,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 224,
            1 => 245,
          ),
          'name' => 'is_string',
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 225,
            1 => 245,
          ),
          'name' => 'is_string',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'cli-getopt' => 
    array (
      'kind' => 'positive',
      'file' => 'cli.php',
      'guard' => 
      array (
        0 => 487,
        1 => 588,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'condition',
          'span' => 
          array (
            0 => 491,
            1 => 526,
          ),
        ),
        1 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 509,
            1 => 526,
          ),
          'name' => 'is_string',
        ),
        2 => 
        array (
          'kind' => 'negated-predicate',
          'span' => 
          array (
            0 => 508,
            1 => 526,
          ),
          'name' => 'is_string',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'predicate-shadow' => 
    array (
      'kind' => 'negative',
      'file' => 'shadow.php',
      'guard' => 
      array (
        0 => 168,
        1 => 300,
      ),
      'sites' => 
      array (
        0 => 
        array (
          'kind' => 'predicate',
          'span' => 
          array (
            0 => 172,
            1 => 192,
          ),
          'name' => 'is_array',
        ),
      ),
      'sourceGrammarCandidate' => true,
    ),
    'changed-global-source' => 
    array (
      'kind' => 'negative',
      'file' => 'mutated-global.php',
      'guard' => 
      array (
        0 => 135,
        1 => 267,
      ),
      'sites' => 
      array (
      ),
      'sourceGrammarCandidate' => false,
    ),
  ),
);
$manifest['cases']['configuration-route-keys']=array (
  'kind' => 'positive',
  'file' => 'cases.php',
  'guard' => 
  array (
    0 => 4847,
    1 => 4941,
  ),
  'sites' => 
  array (
    0 => 
    array (
      'kind' => 'condition',
      'span' => 
      array (
        0 => 4850,
        1 => 4868,
      ),
    ),
    1 => 
    array (
      'kind' => 'predicate',
      'span' => 
      array (
        0 => 4851,
        1 => 4868,
      ),
      'name' => 'is_array',
    ),
    2 => 
    array (
      'kind' => 'negated-predicate',
      'span' => 
      array (
        0 => 4850,
        1 => 4868,
      ),
      'name' => 'is_array',
    ),
  ),
  'sourceGrammarCandidate' => true,
);
$normalize=static function(array $issues):array{$rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows;};
$rows=static function(string $stage) use($workspace):array{return array_map(static fn(string $row):array=>json_decode($row,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/'.$stage.'-observer.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));};
$issueKey=static function(array $issue):string{foreach($issue['annotations'] as $annotation){if($annotation['kind']==='Primary'){return $annotation['span']['file_id']['name'].':'.$annotation['span']['start']['offset'].':'.$annotation['span']['end']['offset'].':'.$issue['code'].':'.$issue['message'];}}return '';};
$native=$run('native','observe'); $observed=$rows('native'); $admitted=[];$admittedSites=[];
foreach($observed as $row){if($row['stage']==='boundary-guard-policy' && $row['alwaysKeep'] && $row['proof']['remove']){$file=basename(str_replace('\\','/',$row['file']));$site=$file.':'.$row['span'][0].':'.$row['span'][1];$admittedSites[$site]=true;$admitted[$site.':'.$row['wholeNativeEnvelope']['code'].':'.$row['wholeNativeEnvelope']['message']]=$row;}}
foreach($manifest['cases'] as $name=>$case){
    $sites=array_map(static fn(array $site):string=>$case['file'].':'.$site['span'][0].':'.$site['span'][1],$case['sites']);
    $witnesses=array_intersect_key($admittedSites,array_flip($sites));
    if($case['kind']==='positive' && $witnesses===[]){throw new RuntimeException('No genuine admitted native positive: '.$name.'; retained '.$workspace);}
    if($case['kind']==='negative' && $witnesses!==[]){throw new RuntimeException('Near miss was admitted: '.$name.'; retained '.$workspace);}
}
$expected=array_values(array_filter($native,static fn(array $issue):bool=>!isset($admitted[$issueKey($issue)])));
$draft=$run('draft','draft'); if($normalize($expected)!==$normalize($draft)){throw new RuntimeException('Every non-target complete issue DTO must remain exact; retained '.$workspace);}
$errors=array_values(array_filter($native,static fn(array $issue):bool=>$issue['level']==='Error')); if(count($errors)<4){throw new RuntimeException('Independent invalid arguments must produce real Errors; retained '.$workspace);}
foreach(['preserve','cache-predicate-domain','cache-producer-contract'] as $mode){
    if($normalize($run($mode,$mode))!==$normalize($native)){throw new RuntimeException('Control changed native DTOs: '.$mode.'; retained '.$workspace);}
    if(str_starts_with($mode,'cache-')){
        $controls=array_filter($rows($mode),static fn(array $row):bool=>$row['stage']==='real-native-cache-control' && $row['role']===$mode && $row['genuinePositiveBefore'] && $row['slotsChanged']>0 && $row['deferredAfter'] && $row['completeProofRestored']);
        if($controls===[]){throw new RuntimeException('No genuine cache control: '.$mode.'; retained '.$workspace);}
        if($mode==='cache-predicate-domain' && count($controls)<count($admitted)){throw new RuntimeException('Each admitted native issue needs its actual cache control; retained '.$workspace);}
    }
}
if($normalize($run('draft-three-workers','draft',3))!==$normalize($draft)){throw new RuntimeException('One/three worker semantic drift; retained '.$workspace);}
// A physical helper-source control starts only after the original genuine config positive.
$helper=$workspace.'/vendor/laravel/framework/src/Illuminate/Foundation/helpers.php';$original=file_get_contents($helper);$changed=str_replace('$key','$lookup',$original);
if($changed===$original){throw new RuntimeException('Physical helper source control was a no-op.');}file_put_contents($helper,$changed);
try{
    $changedNative=$run('source-helper-native','observe');$changedDraft=$run('source-helper-draft','draft');
    $changedRows=$rows('source-helper-native'); foreach($changedRows as $row){if($row['stage']==='boundary-guard-policy' && $row['proof']['remove']){foreach($row['proof']['nativeProducers'] as $producer){if(in_array($producer['kind'],['config-helper','config-facade'],true)){throw new RuntimeException('Changed physical helper was still trusted; retained '.$workspace);}}}}
    $expectedChanged=[];$changedAdmitted=[];foreach($changedRows as $row){if($row['stage']==='boundary-guard-policy' && $row['proof']['remove']){$changedAdmitted[basename(str_replace('\\','/',$row['file'])).':'.$row['span'][0].':'.$row['span'][1].':'.$row['wholeNativeEnvelope']['code'].':'.$row['wholeNativeEnvelope']['message']]=true;}}
    foreach($changedNative as $issue){if(!isset($changedAdmitted[$issueKey($issue)])){$expectedChanged[]=$issue;}}
    if($normalize($expectedChanged)!==$normalize($changedDraft)){throw new RuntimeException('Changed-source residual DTO drift; retained '.$workspace);}
}finally{file_put_contents($helper,$original);}
if($normalize($run('source-helper-restored','draft'))!==$normalize($draft)){throw new RuntimeException('Restored physical source drift; retained '.$workspace);}
file_put_contents($workspace.'/gate-result.json',json_encode(['status'=>'fixture-draft-gates-passed','publicIntegrated'=>false,'genuineAdmittedIssues'=>count($admitted),'genuineAdmittedSites'=>count($admittedSites),'nativeCount'=>count($native),'draftCount'=>count($draft),'nativeErrorsPreserved'=>count($errors),'nativeTypesChanged'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
echo 'Genuine fixture results retained at '.$workspace."\n";
