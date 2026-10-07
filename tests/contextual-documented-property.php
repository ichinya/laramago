<?php
declare(strict_types=1);

// This test writes and analyzes invented declarations. It never includes fixture or application bodies.
$option=static function(string $name)use($argv):?string { foreach($argv as $argument) { if(str_starts_with($argument,$name.'=')) { return substr($argument,strlen($name)+1); } }return null; };
$package=str_replace('\\','/',$option('--package-root')??dirname(__DIR__));
$workspace=str_replace('\\','/',$option('--workspace')??sys_get_temp_dir().'/laramago documented property '.bin2hex(random_bytes(8)));
$prepareOnly=in_array('--prepare-only',$argv,true);
$data=__DIR__.'/fixtures/analysis/contextual-documented-property';
require $package.'/vendor/autoload.php';
if(file_exists($workspace)) { throw new RuntimeException('Preserve existing fixture workspace; choose a fresh path.'); }
mkdir($workspace,0o777,true);
$write=static function(string $path,string $bytes):void { if(!is_dir(dirname($path))) { mkdir(dirname($path),0o777,true); }file_put_contents($path,$bytes); };
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data.'/framework',FilesystemIterator::SKIP_DOTS)) as $entry) {
    if(!$entry->isFile()||!str_ends_with($entry->getFilename(),'.php.stub')) { continue; }
    $relative=str_replace('\\','/',substr($entry->getPathname(),strlen($data.'/framework/') ));
    $write($workspace.'/laravel/framework/src/Illuminate/'.substr($relative,0,-5),file_get_contents($entry->getPathname()));
}
$write($workspace.'/declarations.php',file_get_contents($data.'/declarations.php.stub'));
$write($workspace.'/bootstrap.php','<?php file_put_contents(__DIR__."/bootstrap-executed","unexpected"); throw new RuntimeException("Fixture bootstrap must remain unused.");');
$write($workspace.'/dependency tree/autoload.php','<?php file_put_contents(__DIR__."/autoload-executed","unexpected"); throw new RuntimeException("Fixture autoload must remain unused.");');
$write($workspace.'/composer.json',json_encode(['config'=>['vendor-dir'=>'dependency tree'],'autoload'=>['files'=>['bootstrap.php']]],JSON_THROW_ON_ERROR));
$cases=json_decode(file_get_contents($data.'/cases.json'),true,flags:JSON_THROW_ON_ERROR);
if(count($cases)!==32||count(array_filter($cases,static fn(array $case):bool=>$case['expectedCandidateRead']))!==7) { throw new RuntimeException('Expected thirty-two cases with seven positive contracts.'); }
$parser=(new PhpParser\ParserFactory)->createForNewestSupportedVersion();
foreach($cases as $number=>$case) {
    $bytes=file_get_contents($data.'/'.$case['file'].'.stub');
    if(hash('sha256',$bytes)!==$case['sourceSha256']) { throw new RuntimeException('Portable source fixture hash mismatch.'); }
    $write($workspace.'/'.$case['file'],$bytes);
    $nodes=(new PhpParser\NodeTraverser(new PhpParser\NodeVisitor\NameResolver))->traverse($parser->parse($bytes)??[]);
    $sites=[];
    foreach((new PhpParser\NodeFinder)->findInstanceOf($nodes,PhpParser\Node\Expr\PropertyFetch::class) as $fetch) {
        if(!$fetch->name instanceof PhpParser\Node\Identifier) { continue; }
        $sites[]=['fetch'=>[$fetch->getStartFilePos(),$fetch->getEndFilePos()+1],'name'=>[$fetch->name->getStartFilePos(),$fetch->name->getEndFilePos()+1],'field'=>$fetch->name->name];
        $head='<?php function collision'.$number.'_'.count($sites).'($foreign): void { ';$receiver='$foreign->';$target=$fetch->name->getStartFilePos();
        if($target<strlen($head.$receiver)) { throw new RuntimeException('Cannot create actual source name-span collision.'); }
        $write($workspace.'/collisions/collision-'.$number.'-'.count($sites).'.php',$head.str_repeat(' ',$target-strlen($head.$receiver)).$receiver.$fetch->name->name.'; }');
    }
    if($sites!==$case['sites']) { throw new RuntimeException('Portable parsed source spans differ.'); }
}
copy($workspace.'/cases/case-00.php',$workspace.'/focus.php');
$write($workspace.'/cases.json',json_encode($cases,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
$held=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace,FilesystemIterator::SKIP_DOTS)) as $entry) {
    if(!$entry->isFile()) { continue; }
    if($entry->getExtension()==='php') { $parser->parse(file_get_contents($entry->getPathname())); }
    $held[str_replace('\\','/',$entry->getPathname())]=hash_file('sha256',$entry->getPathname());
}
$write($workspace.'/prepared.json',json_encode(['sourceOnly'=>true,'cases'=>32,'positiveContracts'=>7,'fixtureBodiesExecuted'=>false,'analyzerStarts'=>0,'held'=>$held],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if($prepareOnly) { echo "Prepared thirty-two source cases; no analyzer or fixture body executed.\n";exit; }

$registry=file_get_contents($package.'/src/Analyzer/ContextualCollectionMemberPlugin.php');
$registration='        $registry->registerIssueFilterHook(new ContextualDocumentedPropertyIssueFilter($provider));';
if(substr_count($registry,$registration)!==1) { throw new RuntimeException('Expected one production issue-filter registration.'); }
// Baseline removes only the new correction hook and adds an always-Keep observer sharing the existing real provider/index.
$before=str_replace($registration,'        $registry->registerIssueFilterHook(new \\ContextualDocumentedPropertyTestObserver($provider, $this->root, \'full-before\'));',$registry);
$after=$registry;
$write($workspace.'/full-before-plugin.php',$before);$write($workspace.'/full-after-plugin.php',$after);
$worker=str_replace('\\','/',__DIR__.'/fixtures/analysis/contextual-documented-property-worker.php');
$binary=getenv('MAGO_BINARY')?:$package.'/vendor/bin/mago';
$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];
$publicInventory=static function()use($package):array {
    $process=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot capture public source inventory.'); }
    $output=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$stderr!=='') { throw new RuntimeException('Public source inventory failed.'); }
    $hashes=[];foreach(array_unique(array_filter(explode("\0",$output))) as $path) { $hashes[$path]=hash_file('sha256',$package.'/'.$path); }ksort($hashes);return $hashes;
};
$publicBefore=$publicInventory();
$assertSources=static function()use($held,$publicBefore,$publicInventory):void {
    foreach($held as $path=>$hash) { if(hash_file('sha256',$path)!==$hash) { throw new RuntimeException('Fixture declaration/source changed.'); } }
    if($publicInventory()!==$publicBefore) { throw new RuntimeException('Public source/inventory changed during native test.'); }
};
$reports=[];
foreach(['native','observe','compatibility','compatibility-three','single-native','guards','full-before','full-after','full-after-three'] as $mode) {
    $workerMode=match($mode) { 'compatibility-three'=>'compatibility','single-native'=>'native','full-after-three'=>'full-after',default=>$mode };
    $focus=in_array($mode,['single-native','guards'],true);
    $arguments=[PHP_BINARY,'-d','opcache.enable_cli=0',$worker,$package.'/vendor/autoload.php',$workspace,$workerMode,$package];
    if(str_starts_with($mode,'full-')) { $arguments[]=$workspace.'/'.($mode==='full-before'?'full-before':'full-after').'-plugin.php'; }
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.2','threads'=>1,'source'=>['paths'=>$focus?['focus.php']:['cases','collisions'],
        'includes'=>[$workspace.'/laravel/framework/src/Illuminate',$workspace.'/declarations.php']],
        'extension-hosts'=>['test'=>['command'=>$arguments,'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];
    $configPath=$workspace.'/'.$mode.'.config.json';$write($configPath,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $outputPath=$workspace.'/'.$mode.'.json';$errorPath=$workspace.'/'.$mode.'.stderr.log';
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configPath,'analyze','--reporting-format=json'],[1=>['file',$outputPath,'w'],2=>['file',$errorPath,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot start genuine native test.'); }
    $exit=proc_close($process);$stderr=file_get_contents($errorPath);
    copy($outputPath,$outputPath.'.raw');
    $write($workspace.'/'.$mode.'.process.json',json_encode(['exitCode'=>$exit,'childClosed'=>true,'rawSha256'=>hash_file('sha256',$outputPath.'.raw')],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|falling back|invalid[^\r\n]*extension[^\r\n]*frame|hook[^\r\n]*failed|fatal|orchestrat(?:or|ion)[^\r\n]*error|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i',$stderr)) { throw new RuntimeException('Operational failure in '.$mode.': '.$stderr); }
    $report=json_decode(ltrim(file_get_contents($outputPath),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
    if(!isset($report['issues'])||!is_array($report['issues'])) { throw new RuntimeException('Missing genuine native report.'); }
    $reports[$mode]=$report['issues'];$assertSources();
}
$signature=static function(array $issues):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return $rows; };
$primary=static function(array $issue):array { foreach($issue['annotations'] as $annotation) { if($annotation['kind']==='Primary') { return $annotation; } }throw new RuntimeException('Missing native Primary.'); };
$file=static fn(array $issue):string=>str_replace('\\','/',$primary($issue)['span']['file_id']['name']);
if($signature($reports['native'])!==$signature($reports['observe'])) { throw new RuntimeException('Always-Keep observer changed complete records.'); }
$removed=[];
foreach($cases as $case) {
    $before=array_values(array_filter($reports['native'],static fn(array $issue):bool=>$file($issue)===$case['file']));
    $after=array_values(array_filter($reports['compatibility'],static fn(array $issue):bool=>$file($issue)===$case['file']));
    if(!in_array('Error',array_column($before,'level'),true)) { throw new RuntimeException('Vacuous native unsafe-use control: '.$case['label']); }
    $target=[];
    if($case['expectedCandidateRead']) {
        $target=array_values(array_filter($before,static function(array $issue)use($case,$primary):bool {
            if($issue['level']!=='Warning'||$issue['code']!=='non-documented-property') { return false; }
            $site=$primary($issue);return in_array([$site['span']['start']['offset'],$site['span']['end']['offset']],array_column($case['sites'],'name'),true);
        }));
        if($target===[]) { throw new RuntimeException('Vacuous native warning positive: '.$case['label']); }
    }
    $exclude=$signature($target);$expected=array_values(array_filter($before,static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$exclude,true)));
    if($signature($expected)!==$signature($after)) { throw new RuntimeException('Unrelated complete record changed in '.$case['label']); }
    $removed=array_merge($removed,$target);
}
$exclude=$signature($removed);$expected=array_values(array_filter($reports['native'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$exclude,true)));
if(count($removed)!==7||$signature($expected)!==$signature($reports['compatibility'])||$signature($reports['compatibility'])!==$signature($reports['compatibility-three'])) { throw new RuntimeException('Isolated exact-seven-warning or one/three-worker gate failed.'); }
if($signature($reports['single-native'])!==$signature($reports['guards'])) { throw new RuntimeException('Native mutation controls changed actual diagnostics.'); }
$controls=json_decode(file_get_contents($workspace.'/native-controls.json'),true,flags:JSON_THROW_ON_ERROR);
if(count($controls)!==40||in_array(false,$controls,true)) { throw new RuntimeException('Missing forty genuine native/source controls.'); }

// Full-registry positives are certified from actual remaining native warnings, rather than assuming all isolated warnings survive other providers.
$fullEvents=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/full-before-issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$fullRemoved=[];
foreach($fullEvents as $event) {
    if($event['decision']!=='Remove') { continue; }
    $sdk=$event['completeSdkIssue'];$matches=[];
    foreach($reports['full-before'] as $issue) {
        if($file($issue)!==$event['file']||$issue['level']!=='Warning'||$issue['code']!=='non-documented-property') { continue; }
        $same=true;foreach(['level','code','message','notes','help'] as $field) { $same=$same&&($sdk[$field]??null)===($issue[$field]??null); }
        $same=$same&&count($sdk['annotations'])===count($issue['annotations']);
        foreach($sdk['annotations'] as $index=>$annotation) {
            $heldAnnotation=$issue['annotations'][$index]??null;
            $same=$same&&$heldAnnotation!==null&&$annotation['kind']===$heldAnnotation['kind']&&$annotation['message']===$heldAnnotation['message']
                &&$annotation['span']['start']===$heldAnnotation['span']['start']['offset']&&$annotation['span']['end']===$heldAnnotation['span']['end']['offset'];
        }
        if($same) { $matches[]=$issue; }
    }
    if(count($matches)!==1||hash_file('sha256',$workspace.'/'.$event['file'])!==$event['sourceSha256']) { throw new RuntimeException('Full-registry candidate is not bound to one real current native record.'); }
    $fullRemoved[]=json_encode($matches[0],JSON_THROW_ON_ERROR);
}
if(count(array_unique($fullRemoved))!==count($fullRemoved)) { throw new RuntimeException('Duplicate full-registry candidate receipt.'); }
$fullExpected=array_values(array_filter($reports['full-before'],static fn(array $issue):bool=>!in_array(json_encode($issue,JSON_THROW_ON_ERROR),$fullRemoved,true)));
if($signature($fullExpected)!==$signature($reports['full-after'])||$signature($reports['full-after'])!==$signature($reports['full-after-three'])) { throw new RuntimeException('Full-registry complete-record or one/three-worker gate failed.'); }
foreach(['compatibility','full-after'] as $mode) {
    $before=$mode==='compatibility'?'native':'full-before';
    if($signature(array_values(array_filter($reports[$before],static fn(array $issue):bool=>$issue['level']==='Error')))!==$signature(array_values(array_filter($reports[$mode],static fn(array $issue):bool=>$issue['level']==='Error')))) { throw new RuntimeException('An Error changed.'); }
}
foreach(['/bootstrap-executed','/dependency tree/autoload-executed'] as $trap) { if(file_exists($workspace.$trap)) { throw new RuntimeException('Fixture/application body executed.'); } }
$assertSources();
$write($workspace.'/accepted-result.json',json_encode(['cases'=>32,'isolatedExactWarningsRemoved'=>7,'fullRegistryCertifiedWarningsRemoved'=>count($fullRemoved),'allErrorsAndOtherCompleteRecordsPreserved'=>true,
    'oneThreeIdentity'=>true,'genuineNativeControls'=>40,'realPropertyProviderKeptInFullRegistry'=>true,'fixtureBodiesExecuted'=>false,'publicSourceUnchanged'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS contextual documented property: 32 cases, 7 isolated warnings, '.count($fullRemoved)." certified full-registry warnings, 40 genuine controls.\n";
