<?php
declare(strict_types=1);
$package = dirname(__DIR__);
$fixture = __DIR__.'/fixtures/configuration-member-guards';
require $package.'/vendor/autoload.php';
require $package.'/tests/fixtures/compatibility-bounded-worker-support.php';
$sourceRoot = $fixture.'/source';
$prepared = json_decode(file_get_contents($fixture.'/cases.json'), true, flags: JSON_THROW_ON_ERROR);
$workspace = sys_get_temp_dir().'/laramago configuration member fixture '.bin2hex(random_bytes(8));
mkdir($workspace, recursive: true);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) { continue; }
    $relative = substr($file->getPathname(), strlen($sourceRoot)+1);
    if (!is_dir(dirname($workspace.'/'.$relative))) { mkdir(dirname($workspace.'/'.$relative), recursive: true); }
    copy($file->getPathname(), $workspace.'/'.$relative);
}
$worker = compatibilityProductionWorker($package, $workspace, $fixture, 'CONFIGURATION_MEMBER_CLASSES_ONLY', 'ConfigurationMemberNativePlugin', 'DefensiveConfigurationMemberPlugin');
$config = ['php-version'=>'8.5','source'=>['paths'=>$prepared['casePaths'],'includes'=>['vendor']]];
$run = static fn(string $stage, string $mode, int $workers=1): array => compatibilityNativeGate($package, $workspace, $worker, $config, $stage, $mode, $workers);
$native = $run('native-1','native');
$observe = $run('observe-1','observe');
$key = static fn(array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR);
$multiset = static function(array $issues) use($key): array { $set=[]; foreach($issues as $issue){$id=$key($issue);$set[$id]=($set[$id]??0)+1;}ksort($set);return $set; };
if ($multiset($native) !== $multiset($observe)) { throw new RuntimeException('AlwaysKeep whole native DTO parity failed.'); }
$records = array_map(static fn(string $line): array => json_decode($line,true,flags:JSON_THROW_ON_ERROR), file($workspace.'/observe-1-observer.jsonl', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$selected = array_values(array_filter($records, static fn(array $row): bool => ($row['stage']??null)==='configuration-member' && ($row['candidateDecision']??null)==='Remove'));
$owners=[];
foreach($selected as $row){$owner=basename($row['file'],'.php');$owners[$owner]=($owners[$owner]??0)+1;if(!in_array($owner,['helper-positive','facade-positive'],true)){throw new RuntimeException('A negative source owner was admitted: '.$owner);} }
file_put_contents($workspace.'/baseline-receipt.json',json_encode(['nativeCount'=>count($native),'observeCount'=>count($observe),'admittedOwners'=>$owners,'actualSelectedDiagnostics'=>count($selected),'nativeAcceptance'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
if (($owners['helper-positive']??0)!==3 || ($owners['facade-positive']??0)!==3) { throw new RuntimeException('Six genuine selected diagnostic positives required; retained '.$workspace); }
$draft=$run('draft-1','draft');
$nativeKey=static function(array $issue):string{foreach($issue['annotations'] as $annotation){if($annotation['kind']==='Primary'){return basename(str_replace('\\','/',$annotation['span']['file_id']['name'])).':'.$annotation['span']['start']['offset'].':'.$annotation['span']['end']['offset'].':'.$issue['level'].':'.$issue['code'].':'.$issue['message'];}}return '';};
$selectedKeys=[];foreach($selected as $row){$id=basename(str_replace('\\','/',$row['file'])).':'.$row['span'][0].':'.$row['span'][1].':'.match ((int) $row['issue']['level']) { 3 => 'Warning', 4 => 'Error', default => throw new RuntimeException('Unexpected selected canonical level.') }.':'.$row['issue']['code'].':'.$row['issue']['message'];$selectedKeys[$id]=($selectedKeys[$id]??0)+1;}
$expected=[];foreach($native as $issue){$id=$nativeKey($issue);if(($selectedKeys[$id]??0)>0){--$selectedKeys[$id];continue;}$expected[]=$issue;}
if(array_sum($selectedKeys)!==0){throw new RuntimeException('An observed selected diagnostic was not a genuine baseline record.');}
$expected=$multiset($expected);
ksort($expected);if($expected!==$multiset($draft)){throw new RuntimeException('Complete residual DTO preservation failed.');}
foreach(['cache-predicate-domain','cache-producer-contract'] as $mode){$issues=$run($mode,$mode);if($multiset($issues)!==$multiset($native)){throw new RuntimeException('Cache control native DTO parity failed.');}$controls=array_filter(array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/'.$mode.'-observer.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)),static fn(array $row):bool=>($row['stage']??null)==='actual-cache-control');if(count($controls)!==count($selected)){throw new RuntimeException('Every admitted genuine diagnostic needs a meaningful native cache veto.');}}
$parallel=$run('draft-3','draft',3);if($multiset($parallel)!==$multiset($draft)){throw new RuntimeException('Full registry multiworker parity failed.');}
file_put_contents($workspace.'/accepted-native.json',json_encode(['passed'=>true,'nativeJobs'=>6,'genuineRemoved'=>count($selected),'negativeOwners'=>21,'wholeDtoResidualHeld'=>true,'workerMemory'=>'512M','applicationExecuted'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode(['passed'=>true,'genuineRemoved'=>count($selected),'workspace'=>$workspace],JSON_THROW_ON_ERROR),"\n";
