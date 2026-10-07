<?php
declare(strict_types=1);

// This program analyzes invented source; it never includes a fixture or application body.
$option=static function(string $key)use($argv):?string { foreach($argv as $item) { if(str_starts_with($item,$key.'=')) { return substr($item,strlen($key)+1); } }return null; };
$package=str_replace('\\','/',$option('--package-root')??dirname(__DIR__));
$candidate=str_replace('\\','/',$option('--candidate-root')??dirname(__DIR__));
$workspace=str_replace('\\','/',$option('--workspace')??sys_get_temp_dir().'/laramago possible callback tuples '.bin2hex(random_bytes(8)));
$data=__DIR__.'/fixtures/possible-tuples';require $package.'/vendor/autoload.php';
if(file_exists($workspace)) { throw new RuntimeException('Preserve existing evidence and select a fresh workspace.'); }
mkdir($workspace,0777,true);$write=static function(string $path,string $contents):void { if(!is_dir(dirname($path))) { mkdir(dirname($path),0777,true); }file_put_contents($path,$contents); };
$matrix=json_decode(file_get_contents($data.'/cases.json'),true,flags:JSON_THROW_ON_ERROR);$held=[];$sites=[];$scopes=[];
$finder=new \PhpParser\NodeFinder;$parser=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
foreach($matrix['sources'] as $file=>$hash) {
    $contents=file_get_contents($data.'/source/'.$file.'.stub');if(hash('sha256',$contents)!==$hash) { throw new RuntimeException('Fixture source hash differs: '.$file); }
    $write($workspace.'/'.$file,$contents);$held[$workspace.'/'.$file]=$hash;$nodes=$parser->parse($contents)??[];
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Stmt\Function_::class) as $owner) { $scopes[$file][$owner->name->name]=[$owner->getStartFilePos(),$owner->getEndFilePos()+1]; }
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Stmt\Class_::class) as $class) {
        foreach($class->getMethods() as $owner) { $scopes[$file][$class->name->name.'::'.$owner->name->name]=[$owner->getStartFilePos(),$owner->getEndFilePos()+1]; }
    }
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Expr\Assign::class) as $binding) {
        if($binding->var instanceof \PhpParser\Node\Expr\Variable && is_string($binding->var->name) && $binding->expr instanceof \PhpParser\Node\Expr\Closure) {
            $scopes[$file][$binding->var->name]=[$binding->expr->getStartFilePos(),$binding->expr->getEndFilePos()+1];
        }
    }
    $points=[];
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Expr\CallLike::class) as $call) {
        foreach($call->args as $argument) { if($argument instanceof \PhpParser\Node\Arg && $argument->value instanceof \PhpParser\Node\Expr\Variable) { $points[]=$argument->value; } }
    }
    foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Stmt\Foreach_::class) as $loop) { if($loop->valueVar instanceof \PhpParser\Node\Expr\List_||$loop->valueVar instanceof \PhpParser\Node\Expr\Array_) { $points[]=$loop->valueVar; } }
    foreach($points as $point) {
        $owner='top';$size=PHP_INT_MAX;
        foreach($scopes[$file]??[] as $name=>$span) { if($span[0]<=$point->getStartFilePos()&&$span[1]>=$point->getEndFilePos()+1&&$span[1]-$span[0]<$size) { $owner=$name;$size=$span[1]-$span[0]; } }
        $sites[$file.':'.$point->getStartFilePos().':'.($point->getEndFilePos()+1)]=['owner'=>$owner];
    }
}
$write($workspace.'/bootstrap/app.php','<?php file_put_contents(dirname(__DIR__)."/bootstrap-executed","unexpected"); throw new RuntimeException("Fixture bootstrap must never execute.");');
$write($workspace.'/dependency tree/autoload.php','<?php file_put_contents(dirname(__DIR__)."/autoload-executed","unexpected"); throw new RuntimeException("Fixture autoload must never execute.");');
$write($workspace.'/composer.json',json_encode(['config'=>['vendor-dir'=>'dependency tree'],'autoload'=>['files'=>['bootstrap/app.php']]],JSON_THROW_ON_ERROR));
foreach(['bootstrap/app.php','dependency tree/autoload.php','composer.json'] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
foreach([$candidate.'/src/Analyzer/SourceArgumentDeclarationContracts.php',$candidate.'/src/Analyzer/PossibleCallbackTupleArgumentFilter.php',$candidate.'/src/Analyzer/PossibleCallbackTupleShapeFilter.php',$candidate.'/src/Analyzer/PossibleCallbackTupleContracts.php',$candidate.'/src/Analyzer/PossibleTupleConstantDomain.php',$candidate.'/src/Analyzer/PossibleCallbackTupleCompatibilityPlugin.php',
    $data.'/NativeHarness.php',$data.'/ConstantDomainControls.php',$data.'/worker.php',$data.'/cases.json',__FILE__] as $file) { $held[$file]=hash_file('sha256',$file); }
$registry=file_get_contents($package.'/bin/laramago-worker.php');$registration='        new \\Ichinya\\Laramago\\Analyzer\\PossibleCallbackTupleCompatibilityPlugin($projectRoot),';
if(substr_count($registry,$registration)===1) { $before=str_replace($registration,'',$registry);$after=$registry; }
else {
    $anchor='        new FalseOperandCompatibilityPlugin,';
    if(substr_count($registry,$anchor)!==1||str_contains($registry,'PossibleCallbackTupleCompatibilityPlugin')) { throw new RuntimeException('Expected one precise production registry insertion point.'); }
    $before=$registry;$after=str_replace($anchor,$anchor."\n".$registration,$registry);
}
$write($workspace.'/full-before-worker.php',$before);$write($workspace.'/full-after-worker.php',$after);
foreach(['full-before-worker.php','full-after-worker.php'] as $file) { $held[$workspace.'/'.$file]=hash_file('sha256',$workspace.'/'.$file); }
$write($workspace.'/prepared.json',json_encode(['sourceOnly'=>true,'analyzerJobsStarted'=>0,'fixtureBodiesExecuted'=>false,'applicationBodiesExecuted'=>false,
    'nativeAcceptanceClaimed'=>false,'sourceSites'=>$sites,'sources'=>$matrix['sources'],'registrationOnlyDifference'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
$constantGrammarScript=$data.'/constant-domain-source-cases.php';$held[$constantGrammarScript]=hash_file('sha256',$constantGrammarScript);
if(in_array('--prepare-only',$argv,true)) { echo 'Prepared source argument fixture files; no analyzer or fixture body executed.'.PHP_EOL;exit; }
$inventory=static function()use($package):array {
    $process=proc_open(['git','ls-files','--cached','--others','--exclude-standard','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot capture public source inventory.'); }
    $bytes=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$errors!=='') { throw new RuntimeException('Source inventory failed.'); }
    $result=[];foreach(array_unique(array_filter(explode("\0",$bytes))) as $file) { $result[$file]=hash_file('sha256',$package.'/'.$file); }ksort($result);return $result;
};
$publicBefore=$inventory();$assertHeld=static function()use($inventory,$publicBefore,$held,$workspace):void {
    if($inventory()!==$publicBefore) { throw new RuntimeException('Public source changed during argument gate.'); }
    foreach($held as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { throw new RuntimeException('Fixture source changed.'); } }
    foreach(['bootstrap-executed','autoload-executed','.env'] as $trap) { if(file_exists($workspace.'/'.$trap)) { throw new RuntimeException('Fixture body executed or environment file appeared.'); } }
};
// Run the finite constant grammar in its own process to isolate globals and declarations.
$constantGrammarOutput=$workspace.'/constant-domain-source-checks.json';
$constantGrammarErrors=$workspace.'/constant-domain-source-checks.stderr.log';
$constantGrammarProcess=proc_open([PHP_BINARY,'-d','memory_limit=512M','-d','opcache.enable_cli=0',
    $constantGrammarScript,$package,$candidate,$workspace],
    [1=>['file',$constantGrammarOutput,'w'],2=>['file',$constantGrammarErrors,'w']],$constantGrammarPipes,$package,null,['bypass_shell'=>true]);
if(!is_resource($constantGrammarProcess)) { throw new RuntimeException('Cannot start the source-only constant grammar checks.'); }
$constantGrammarExit=proc_close($constantGrammarProcess);
$write($workspace.'/constant-domain-source-checks.closed-receipt.json',json_encode(['closed'=>true,
    'sourceOnly'=>true,'exitCode'=>$constantGrammarExit,'nativeJobsStarted'=>0,
    'scriptSha256'=>hash_file('sha256',$constantGrammarScript),'stdoutSha256'=>hash_file('sha256',$constantGrammarOutput),
    'stderrSha256'=>hash_file('sha256',$constantGrammarErrors)],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if($constantGrammarExit!==0||file_get_contents($constantGrammarErrors)!=='') { throw new RuntimeException('Source-only constant grammar failed; preserve its closed output.'); }
$constantGrammar=json_decode(file_get_contents($constantGrammarOutput),true,flags:JSON_THROW_ON_ERROR);
if(($constantGrammar['sourceOnly']??false)!==true||count($constantGrammar['checks']??[])!==20
    ||in_array(false,$constantGrammar['checks']??[],true)||($constantGrammar['constructedIssueContexts']??null)!==0
    ||($constantGrammar['sdkRequests']??null)!==0||($constantGrammar['applicationBodiesExecuted']??true)!==false
    ||($constantGrammar['genuineNativeAcceptanceClaimed']??true)!==false) {
    throw new RuntimeException('All twenty source-only constant grammar checks must pass before native stages.');
}
$assertHeld();
$binary=$package.'/vendor/bin/mago'.(PHP_OS_FAMILY==='Windows'?'.exe':'');if(!is_file($binary)) { $binary=$package.'/vendor/bin/mago'; }
$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];$reports=[];$closed=[];
foreach(['native','observe','isolated','isolated-three','controls','full-before','full-after','full-after-three'] as $mode) {
    $workerMode=match($mode) { 'isolated-three'=>'isolated','full-after-three'=>'full-after',default=>$mode };
    $output=$workspace.'/'.$workerMode;if(!is_dir($output)) { mkdir($output,0777,true); }
    $config=['extends'=>$package.'/presets/laravel.toml','php-version'=>'8.5','source'=>['paths'=>array_keys($matrix['sources'])],
        'extension-hosts'=>['test'=>['command'=>[PHP_BINARY,'-d','memory_limit=512M','-d','opcache.enable_cli=0',$data.'/worker.php',$package.'/vendor/autoload.php',$workspace,$workerMode,$package,$candidate],
            'workers'=>str_ends_with($mode,'three')?3:1,'request-timeout-ms'=>120000]]];
    if($mode==='controls') { $config['threads']=1; }
    $configFile=$workspace.'/'.$mode.'.config.json';$raw=$workspace.'/'.$mode.'.json';$stderrFile=$workspace.'/'.$mode.'.stderr.log';
    $write($configFile,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configFile,'analyze','--reporting-format=json'],[1=>['file',$raw,'w'],2=>['file',$stderrFile,'w']],$pipes,$package,null,['bypass_shell'=>true]);
    if(!is_resource($process)) { throw new RuntimeException('Cannot start source argument gate.'); }
    $exit=proc_close($process);copy($raw,$raw.'.raw');$stderr=file_get_contents($stderrFile);
    $closed[$mode]=['closed'=>true,'exitCode'=>$exit,'hostWorkers'=>str_ends_with($mode,'three')?3:1,'analyzerThreads'=>$config['threads']??'default',
        'rawSha256'=>hash_file('sha256',$raw.'.raw'),'stderrSha256'=>hash_file('sha256',$stderrFile)];
    $write($workspace.'/'.$mode.'.closed-receipt.json',json_encode($closed[$mode],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|falling back|hook[^\r\n]*failed|fatal|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning|PHP Notice/i',$stderr)) { throw new RuntimeException('Operational argument gate failed: '.$mode); }
    $report=json_decode(ltrim(file_get_contents($raw),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
    if(!isset($report['issues'])||!is_array($report['issues'])) { throw new RuntimeException('Missing native issue report.'); }
    $reports[$mode]=$report['issues'];$assertHeld();
    if($mode==='native') {
        $nativeTargets=[];
        $wanted=array_map(static fn(array $entry):string=>$entry['owner'].'::'.$entry['code'],$matrix['positiveTargets']);
        foreach($reports[$mode] as $issue) {
            if($issue['level']!=='Error'||!in_array($issue['code'],['invalid-destructuring-source','mixed-argument'],true)) { continue; }
            $annotations=array_values(array_filter($issue['annotations'],static fn(array $a):bool=>$a['kind']==='Primary'));
            if(count($annotations)!==1) { continue; }$a=$annotations[0];
            $key=$a['span']['file_id']['name'].':'.$a['span']['start']['offset'].':'.$a['span']['end']['offset'];
            $target=$a['span']['file_id']['name'].'::'.($sites[$key]['owner']??'unknown').'::'.$issue['code'];
            if(in_array($target,$wanted,true)) { $nativeTargets[]=$target; }
        }
        sort($nativeTargets);sort($wanted);
        if($nativeTargets!==$wanted) { throw new RuntimeException('Genuine target Error witnesses are absent; stop before observation and controls.'); }
        $requiredNegativeWitnesses=[];
        foreach($matrix['requiredNativeNegativeTargets'] as $selected) {
            $found=[];
            foreach($reports[$mode] as $issue) {
                if($issue['level']!=='Error'||$issue['code']!==$selected['code']) { continue; }
                $primary=array_values(array_filter($issue['annotations'],static fn(array $a):bool=>$a['kind']==='Primary'));
                if(count($primary)!==1) { continue; }$a=$primary[0];
                $key=$a['span']['file_id']['name'].':'.$a['span']['start']['offset'].':'.$a['span']['end']['offset'];
                $owner=$a['span']['file_id']['name'].'::'.($sites[$key]['owner']??'unknown');
                if($owner!==$selected['owner']||!str_contains($issue['message'],'of `'.$selected['receivingFunction'].'`:')) { continue; }
                $found[]=$issue;
            }
            if(count($found)!==1) { throw new RuntimeException('Exact genuine builtin mixed-argument witness absent or ambiguous; stop before observation and controls.'); }
            $requiredNegativeWitnesses[]=['selector'=>$selected,'genuineNativeIssue'=>$found[0]];
        }
        $write($workspace.'/native-required-negative-witnesses.json',json_encode(['beforeAnySdkProofStage'=>true,
            'constructedContexts'=>0,'genuineWitnesses'=>$requiredNegativeWitnesses],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        $negativeWitnesses=[];
        foreach($matrix['negativeOwners'] as $selectedOwner) {
            [$file,$owner]=explode('::',$selectedOwner,2);$span=$scopes[$file][$owner]??null;
            if($span===null) { throw new RuntimeException('Missing negative source owner before observation.'); }
            $retained=array_values(array_filter($reports[$mode],static function(array $issue)use($span,$file,$matrix):bool {
                if($issue['level']!=='Error'||!in_array($issue['code'],$matrix['negativeWitnessCodes'],true)) { return false; }
                $primary=array_values(array_filter($issue['annotations'],static fn(array $annotation):bool=>$annotation['kind']==='Primary'));
                if(count($primary)!==1) { return false; }$a=$primary[0];
                return $a['span']['file_id']['name']===$file&&$a['span']['start']['offset']>=$span[0]&&$a['span']['end']['offset']<=$span[1];
            }));
            if($retained===[]) {
                $write($workspace.'/native-negative-witness-failure.json',json_encode(['owner'=>$selectedOwner,'sourceSpan'=>$span,
                    'genuineNativeWitnesses'=>0,'noRuleRefusalInferred'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                throw new RuntimeException('Native negative Error witness absent; stop before observation and controls: '.$selectedOwner);
            }
            $negativeWitnesses[$selectedOwner]=array_map(static fn(array $issue):string=>$issue['code'],$retained);
        }
        $write($workspace.'/native-negative-witnesses.json',json_encode(['genuineNativeOwners'=>count($negativeWitnesses),
            'requiredBeforeObservationAndControls'=>true,'owners'=>$negativeWitnesses],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    }

    if($mode==='observe') {
        $entries=[];$completed=[];$observationRead=static function(string $path):array {
            if(!is_file($path)) { throw new RuntimeException('Missing genuine observation event log.'); }
            return array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
        };
        foreach($observationRead($workspace.'/observe/hook-entries.jsonl') as $entry) {
            $id=$entry['eventId']??null;
            if(!is_string($id)||isset($entries[$id])||($entry['genuineIssueFilterContext']??false)!==true
                ||($entry['documentaryEventIdOnly']??false)!==true||($entry['controls']??true)!==false
                ||hash_file('sha256',$workspace.'/'.$entry['file'])!==$entry['sourceSha256']) { throw new RuntimeException('Genuine observation entry identity is incomplete.'); }
            $entries[$id]=$entry;
        }
        foreach($observationRead($workspace.'/observe/issues.jsonl') as $event) {
            $id=$event['eventId']??null;$entry=is_string($id)?($entries[$id]??null):null;
            if($entry===null||isset($completed[$id])||($event['alwaysKeep']??false)!==true
                ||($event['candidateEvaluated']??false)!==true) { throw new RuntimeException('Genuine observation completion has no distinct hook entry.'); }
            foreach($entry as $field=>$value) { if(!array_key_exists($field,$event)||$event[$field]!==$value) { throw new RuntimeException('Actual context changed between hook entry and fresh proof completion.'); } }
            $completed[$id]=true;
        }
        if(count($entries)!==count($completed)) {
            $missing=array_diff_key($entries,$completed);
            $write($workspace.'/observation-coverage-failure.json',json_encode(['missingActualEntries'=>array_values($missing),
                'missingEntriesAreNotProofRefusals'=>true,'entries'=>count($entries),'completed'=>count($completed)],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
            throw new RuntimeException('Actual hook entries lack proof completion; no rule refusal is inferred.');
        }
        $concurrent=[];
        if(is_file($workspace.'/observe/concurrent-issues.jsonl')) {
            foreach($observationRead($workspace.'/observe/concurrent-issues.jsonl') as $event) {
                if(($event['candidateEvaluationPlanned']??false)!==true||($event['controls']??true)!==false
                    ||($event['activeGenuineEntries']??[])===[]||!isset($completed[$event['eventId']])) { throw new RuntimeException('Concurrent original-context observation is incomplete.'); }
                foreach($event['activeGenuineEntries'] as $active) { if(($entries[$active['eventId']]??null)!==$active) { throw new RuntimeException('Concurrent context stack lacks its actual hook entry.'); } }
                $concurrent[]=$event['eventId'];
            }
        }
        $write($workspace.'/observation-coverage.json',json_encode(['allGenuineEntriesCompleted'=>true,
            'genuineEntries'=>count($entries),'completed'=>count($completed),'concurrentEntries'=>count($concurrent),
            'completeOriginalIssueDtoUnchangedDuringProof'=>true,'documentaryIdsAuthorizeNothing'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        $builtinVetoes=[];
        foreach($observationRead($workspace.'/observe/issues.jsonl') as $event) {
            if($event['issue']['code']!=='mixed-argument') { continue; }
            $primary=$event['issue']['annotations'][0];$key=$event['file'].':'.$primary['span']['start'].':'.$primary['span']['end'];
            $owner=$event['file'].'::'.($sites[$key]['owner']??'unknown');
            foreach($matrix['requiredNativeNegativeTargets'] as $selected) {
                if($owner!==$selected['owner']) { continue; }
                $native=$event['dependencies']['receiving']??null;
                $stages=array_values(array_filter($event['stages'],static fn(array $stage):bool=>$stage['stage']===$matrix['builtinReceiverGuardStage']));
                if($event['candidateDecision']!=='Keep'||count($stages)!==1||$stages[0]['passed']!==false
                    ||!is_array($native)||!array_key_exists('class',$native['identifier'])||$native['identifier']['class']!==null
                    ||$native['identifier']['name']!==$selected['receivingFunction']) { throw new RuntimeException('Genuine builtin receiver did not conservatively stop at the class-identity guard.'); }
                foreach(array_keys($event['dependencies']) as $role) { if(str_starts_with($role,'class:')) { throw new RuntimeException('Builtin receiver reached a class metadata query.'); } }
                $builtinVetoes[]=$event;
            }
        }
        if(count($builtinVetoes)!==count($matrix['requiredNativeNegativeTargets'])) { throw new RuntimeException('Genuine builtin receiver completion is missing or duplicated.'); }
        $write($workspace.'/builtin-receiver-veto.json',json_encode(['actualIssueContextOnly'=>true,'decision'=>'Keep',
            'classMetadataQuerySkipped'=>true,'proofGuardStage'=>$matrix['builtinReceiverGuardStage'],'genuineEvents'=>$builtinVetoes],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        $constantEvents=[];
        foreach($observationRead($workspace.'/observe/issues.jsonl') as $event) {
            if($event['issue']['code']!=='mixed-argument'||($event['dependencies']['owner']['identifier']['name']??null)!=='constantText'
                ||$event['file']!=='positive.php') { continue; }
            $owner=$event['dependencies']['owner'];$formal=$owner['parameters'][0]??null;
            $member=$formal['type']['type']['atomicTypes'][0]??null;
            if($event['candidateDecision']!=='Remove'||!is_array($member)||($member['kind']??null)!=='Member'
                ||($member['name']??null)!==$owner['identifier']['class']||($member['selector']??null)!=='StartsWith'
                ||($member['member']??null)!=='LABEL_'||($formal['type']['fromDocblock']??false)!==true
                ||($formal['type']['inferred']??true)!==false) { throw new RuntimeException('Genuine constant member/selector/doc witness incomplete before controls.'); }
            foreach(['parameters','variances','intersections'] as $field) {
                if(!array_key_exists($field,$member)||$member[$field]!==null) { throw new RuntimeException('Unknown member-reference structure before controls.'); }
            }
            $constants=[];
            foreach($event['dependencies'] as $role=>$metadata) {
                if(!str_starts_with($role,'constant:')) { continue; }
                foreach(['name','location','visibility','declaredType','type','inferredType','attributes','flags','availableVersions'] as $field) {
                    if(!array_key_exists($field,$metadata)) { throw new RuntimeException('Incomplete genuine constant metadata field: '.$field); }
                }
                $constants[$role]=$metadata;
            }
            if(count($constants)!==2) { throw new RuntimeException('Both genuine native constant bindings required before controls.'); }
            $constantEvents[]=$event;
        }
        if(count($constantEvents)!==1) { throw new RuntimeException('Genuine constant positive event absent or ambiguous before controls.'); }
        $write($workspace.'/genuine-constant-reference-witness.json',json_encode(['genuineHookContextOnly'=>true,
            'beforeAnyConstructedControls'=>true,'literalUnionPreserved'=>true,'events'=>$constantEvents],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        $observed=[];
        foreach(file($workspace.'/observe/issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
            $event=json_decode($line,true,flags:JSON_THROW_ON_ERROR);if($event['candidateDecision']!=='Remove') { continue; }
            $primary=$event['issue']['annotations'][0];$key=$event['file'].':'.$primary['span']['start'].':'.$primary['span']['end'];
            $owner=$event['file'].'::'.($sites[$key]['owner']??'unknown').'::'.$event['issue']['code'];
            if(!in_array($owner,array_map(static fn(array $entry):string=>$entry['owner'].'::'.$entry['code'],$matrix['positiveTargets']),true)) { throw new RuntimeException('Early native observation admitted a negative owner.'); }$observed[]=$owner;
        }
        sort($observed);$wanted=array_map(static fn(array $entry):string=>$entry['owner'].'::'.$entry['code'],$matrix['positiveTargets']);sort($wanted);
        if($observed!==$wanted) { throw new RuntimeException('Completed genuine proof evaluations do not admit every selected positive; preserve both stages.'); }
        $discoveryIdentity=static function(array $issues):array {
            $records=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);
            sort($records);return $records;
        };
        if($discoveryIdentity($reports['native'])!==$discoveryIdentity($reports['observe'])) {
            throw new RuntimeException('Native and Always-Keep whole DTO multisets differ before discovery or controls.');
        }
        if(in_array('--discovery-only',$argv,true)) {
            $write($workspace.'/discovery-result.json',json_encode(['status'=>'GENUINE_NATIVE_AND_ALWAYS_KEEP_EVIDENCE_ONLY',
                'acceptanceClaimed'=>false,'nativeWholeDtoParity'=>true,
                'genuinePositiveCount'=>count($observed),'nativeClosedReceipts'=>$closed,
                'cacheOrSourceControlsStarted'=>false,'fullRegistryAcceptanceClaimed'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
            echo 'Closed genuine native and Always-Keep discovery; full matrix acceptance pending.'.PHP_EOL;exit;
        }

    }
}
$constantControls=json_decode(file_get_contents($workspace.'/controls/controls-constant-domain.json'),true,flags:JSON_THROW_ON_ERROR);
if(($constantControls['genuinePositive']??false)!==true||($constantControls['restored']??false)!==true
    ||count($constantControls['cacheMutations']??[])!==$matrix['expectedConstantDomainControls']['cacheMutations']
    ||count($constantControls['allChecks']??[])!==$matrix['expectedConstantDomainControls']['checks']
    ||count($constantControls['sourceMutations']??[])!==$matrix['expectedConstantDomainControls']['sourceMutations']
    ||in_array(false,$constantControls['allChecks']??[],true)) { throw new RuntimeException('Current literal constant domain controls incomplete.'); }
$identity=static function(array $issues):array { $result=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($result);return $result; };
$primary=static function(array $issue):array { $annotations=array_values(array_filter($issue['annotations'],static fn(array $annotation):bool=>$annotation['kind']==='Primary'));if(count($annotations)!==1) { throw new RuntimeException('Expected one native primary annotation.'); }return $annotations[0]; };
$site=static function(array $issue)use($primary):string { $a=$primary($issue);return $a['span']['file_id']['name'].':'.$a['span']['start']['offset'].':'.$a['span']['end']['offset']; };
$select=static function(array $issues)use($sites,$matrix,$site):array {
    $selected=[];$owners=[];
    foreach($issues as $issue) {
        if($issue['level']!=='Error'||!in_array($issue['code'],['invalid-destructuring-source','mixed-argument'],true)) { continue; }
        $key=$site($issue);$owner=$sites[$key]['owner']??null;$file=explode(':',$key,2)[0];$identity=$file.'::'.$owner.'::'.$issue['code'];
        if(!in_array($identity,array_map(static fn(array $entry):string=>$entry['owner'].'::'.$entry['code'],$matrix['positiveTargets']),true)) { continue; }$selected[]=$issue;$owners[]=$identity;
    }
    sort($owners);$expected=array_map(static fn(array $entry):string=>$entry['owner'].'::'.$entry['code'],$matrix['positiveTargets']);sort($expected);if($owners!==$expected) { throw new RuntimeException('Missing individual genuine positive Error witness.'); }return $selected;
};
$subtract=static function(array $issues,array $selected)use($identity):array {
    $counts=array_count_values($identity($selected));$result=[];
    foreach($issues as $issue) { $key=json_encode($issue,JSON_THROW_ON_ERROR);if(($counts[$key]??0)>0) { $counts[$key]--; }else { $result[]=$issue; } }
    if(array_sum($counts)!==0) { throw new RuntimeException('Selected complete original records are missing.'); }return $result;
};
if($identity($reports['native'])!==$identity($reports['observe'])) { throw new RuntimeException('AlwaysKeep changed native issues.'); }
$selected=$select($reports['native']);$expected=$subtract($reports['native'],$selected);
foreach(['isolated','isolated-three','controls'] as $mode) { if($identity($reports[$mode])!==$identity($expected)) { throw new RuntimeException('Exact selected removals/other whole records differ: '.$mode); } }
foreach($matrix['negativeOwners'] as $selectedOwner) {
    [$file,$owner]=explode('::',$selectedOwner,2);$span=$scopes[$file][$owner]??null;if($span===null) { throw new RuntimeException('Missing negative source owner.'); }
    $retained=array_filter($reports['isolated'],static function(array $issue)use($span,$primary,$file,$matrix):bool {
        if($issue['level']!=='Error'||!in_array($issue['code'],$matrix['negativeWitnessCodes'],true)) { return false; }
        $a=$primary($issue);return $a['span']['file_id']['name']===$file&&$a['span']['start']['offset']>=$span[0]&&$a['span']['end']['offset']<=$span[1];
    });
    if($retained===[]) { throw new RuntimeException('Vacuous or lost native negative: '.$selectedOwner); }
}
$fullSelected=$select($reports['full-before']);$fullExpected=$subtract($reports['full-before'],$fullSelected);
foreach(['full-after','full-after-three'] as $mode) { if($identity($reports[$mode])!==$identity($fullExpected)) { throw new RuntimeException('Full production registry argument delta differs: '.$mode); } }
$mutations=0;$checks=0;
foreach($matrix['expectedControls'] as $family=>[$expectedChecks,$expectedMutations]) {
    $receipt=json_decode(file_get_contents($workspace.'/controls/controls-'.$family.'.json'),true,flags:JSON_THROW_ON_ERROR);
    if(($receipt['genuinePositive']??false)!==true||($receipt['restored']??false)!==true||($receipt['noVacuousControls']??false)!==true||($receipt['cacheMutations']??[])===[]
        ||count($receipt['allChecks']??[])!==$expectedChecks||count($receipt['cacheMutations']??[])!==$expectedMutations
        ||in_array(false,$receipt['allChecks']??[],true)||($receipt['allChecks']['genuine native contracts restored']??false)!==true) { throw new RuntimeException('Missing exact genuine controls and restoration.'); }
    foreach($receipt['cacheMutations'] as $mutation) { if(($mutation['touched']??0)<1||count($mutation['actualSlots']??[])!==$mutation['touched']||($mutation['meaningfullyChanged']??false)!==true) { throw new RuntimeException('Vacuous native cache mutation.'); } }
    $mutations+=count($receipt['cacheMutations']);$checks+=count($receipt['allChecks']);
}
$events=array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file($workspace.'/observe/issues.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));$observed=[];
foreach($events as $event) {
    if(($event['genuineIssueFilterContext']??false)!==true||($event['alwaysKeep']??false)!==true||hash_file('sha256',$workspace.'/'.$event['file'])!==$event['sourceSha256']) { throw new RuntimeException('Observer source identity differs.'); }
    if($event['candidateDecision']!=='Remove') { continue; }$issue=$event['issue'];$a=$issue['annotations'][0];$key=$event['file'].':'.$a['span']['start'].':'.$a['span']['end'];
    if($issue['level']!==\Mago\Sdk\Reporting\Level::Error->value||$a['kind']!==\Mago\Sdk\Reporting\AnnotationKind::Primary->value||!isset($sites[$key])) { throw new RuntimeException('Wrong native observer envelope.'); }
    $matches=array_values(array_filter($selected,static fn(array $native):bool=>$site($native)===$key));
    if(count($matches)!==1||$matches[0]['message']!==$issue['message']||$matches[0]['notes']!==$issue['notes']||($matches[0]['help']??null)!==$issue['help']
        ||($matches[0]['link']??null)!==$issue['link']||($matches[0]['edits']??[])!==$issue['edits']||count($issue['annotations'])!==count($matches[0]['annotations'])) { throw new RuntimeException('Observed positive lacks original whole issue identity.'); }
    foreach($issue['annotations'] as $index=>$annotation) {
        $native=$matches[0]['annotations'][$index];$kind=$index===0?\Mago\Sdk\Reporting\AnnotationKind::Primary->value:\Mago\Sdk\Reporting\AnnotationKind::Secondary->value;
        if($annotation['kind']!==$kind||($annotation['file']!==null&&$annotation['file']!=='')||($native['message']??null)!==$annotation['message']
            ||[$native['span']['start']['offset'],$native['span']['end']['offset']]!==[$annotation['span']['start'],$annotation['span']['end']]) { throw new RuntimeException('Native observer complete annotation differs.'); }
    }
    $observed[]=$event['file'].'::'.$sites[$key]['owner'].'::'.$issue['code'];
}
sort($observed);$positive=array_map(static fn(array $entry):string=>$entry['owner'].'::'.$entry['code'],$matrix['positiveTargets']);sort($positive);if($observed!==$positive) { throw new RuntimeException('Missing exact observed positive set.'); }$assertHeld();
$write($workspace.'/accepted-result.json',json_encode(['genuineNativePositiveErrors'=>count($selected),'individualOriginalFamilies'=>['E018','E037','E038'],'relatedE032ModelProjectionStillSeparate'=>true,
    'nativeNegativeOwners'=>count($matrix['negativeOwners']),'genuineControls'=>$checks,'nonVacuousNativeCacheMutations'=>$mutations,
    'alwaysKeepWholeIssues'=>true,'allOtherWholeIssuesPreserved'=>true,'oneThreeWorkersMatch'=>true,'nativeTypesReplaced'=>false,
    'fixtureBodiesExecuted'=>false,'applicationBodiesExecuted'=>false,'sourcesUnchanged'=>true,'closedReceipts'=>$closed],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo 'PASS possible callback tuples: '.count($selected).' exact native Error removals, retained unsafe Errors, whole DTO equality and cache restoration.'.PHP_EOL;
