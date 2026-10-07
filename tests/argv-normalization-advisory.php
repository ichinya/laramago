<?php

declare(strict_types=1);

// Analyze invented source fixtures; never execute fixture, bootstrap or consumer autoload bodies.
require __DIR__.'/../vendor/autoload.php';

$package=str_replace('\\','/',dirname(__DIR__));
$fixtures=$package.'/tests/fixtures/argv-normalization';
$workspace=str_replace('\\','/',sys_get_temp_dir()).'/laramago argv normalization '.bin2hex(random_bytes(8));
foreach(['app','packages/composer','observe','controls']as$directory){mkdir($workspace.'/'.$directory,recursive:true);}
foreach(glob($fixtures.'/app/*.php')as$file){copy($file,$workspace.'/app/'.basename($file));}
file_put_contents($workspace.'/composer.json','{"config":{"vendor-dir":"packages"},"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php','<?php file_put_contents(__DIR__."/bootstrap-executed.txt","executed"); throw new RuntimeException("Consumer bootstrap must never execute.");');
file_put_contents($workspace.'/packages/composer/installed.json','{"packages":[]}');
file_put_contents($workspace.'/packages/composer/autoload_files.php','<?php file_put_contents(__DIR__."/autoload-executed.txt","executed"); throw new RuntimeException("Consumer autoload must never execute.");');
$header='<?php declare(strict_types=1); require '.var_export($package.'/vendor/autoload.php',true).'; ';
file_put_contents($workspace.'/worker.php',$header.'(new Mago\Sdk\Worker(new Mago\Sdk\Extension("fixture/argv-normalization","Argv normalization","1",analyzerPlugins:[new Ichinya\Laramago\Analyzer\ArgvNormalizationAdvisoryPlugin()])))->run();');
file_put_contents($workspace.'/policy-union-worker.php',$header.'(new Mago\Sdk\Worker(new Mago\Sdk\Extension("fixture/argv-policy-union","Argv policy union","1",analyzerPlugins:[new Ichinya\Laramago\Analyzer\ArgvNormalizationAdvisoryPlugin(),new Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardPlugin('.var_export($workspace,true).'),new Ichinya\Laramago\Analyzer\OrdinaryMixedAssignmentPlugin()])))->run();');
file_put_contents($workspace.'/observe-worker.php',$header.'require '.var_export($fixtures.'/ArgvNormalizationControlObserver.php',true).'; (new Mago\Sdk\Worker(new Mago\Sdk\Extension("fixture/argv-observer","Argv observer","1",analyzerPlugins:[new Ichinya\Laramago\Tests\Support\ArgvNormalizationControlObserver('.var_export($workspace.'/observe',true).',true)])))->run();');
file_put_contents($workspace.'/control-worker.php',$header.'require '.var_export($fixtures.'/ArgvNormalizationControlObserver.php',true).'; (new Mago\Sdk\Worker(new Mago\Sdk\Extension("fixture/argv-controls","Argv controls","1",analyzerPlugins:[new Ichinya\Laramago\Tests\Support\ArgvNormalizationControlObserver('.var_export($workspace.'/controls',true).')])))->run();');
$manifest=json_decode(file_get_contents($fixtures.'/manifest.json'),true,flags:JSON_THROW_ON_ERROR)['cases'];
$parser=(new \PhpParser\ParserFactory())->createForNewestSupportedVersion();$receipts=[];
foreach($manifest as$case=>$entry){
    $bytes=file_get_contents($workspace.'/'.$entry['file']);
    if(hash('sha256',$bytes)!==$entry['sha256']){throw new RuntimeException('Fixture source identity changed: '.$case);}
    $nodes=(new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver()))->traverse($parser->parse($bytes)??[]);
    $proofs=\Ichinya\Laramago\Analyzer\StaticAnalysis\ArgvNormalizationAdvisories::sourceProofs($nodes);
    if(count($proofs)!==$entry['sourceProofCount']){throw new RuntimeException('Source grammar control changed: '.$case);}
    $receipts[$case]=['sha256'=>hash('sha256',$bytes),'sourceProofCount'=>count($proofs)];
}
foreach(['worker.php','policy-union-worker.php','observe-worker.php','control-worker.php','bootstrap.php','packages/composer/autoload_files.php']as$file){$parser->parse(file_get_contents($workspace.'/'.$file));}
file_put_contents($workspace.'/settings.json',json_encode(['sourceRoot'=>$workspace,'outputRoot'=>$workspace],JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/source-receipts.json',json_encode($receipts,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
if(in_array('--prepare-only',$argv,true)){echo 'Prepared source and workers without analyzer or fixture execution: '.$workspace.PHP_EOL;exit(0);}

$binary=getenv('MAGO_BINARY')?:$package.'/vendor/bin/mago';$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];
$run=static function(string $label,?string $worker=null,int $workers=1,bool $single=false)use($workspace,$package,$command):array{
    $config=['php-version'=>'8.5','source'=>['paths'=>$single?['app/ordinary.php']:['app'],'includes'=>[]],
        'analyzer'=>['find-unused-definitions'=>false,'find-unused-parameters'=>false,'check-throws'=>false,'check-missing-override'=>false,'check-missing-type-hints'=>false,'register-super-globals'=>true,'ignore'=>[]]];
    if($worker!==null){$config['extension-hosts']=['argv-normalization'=>['command'=>[PHP_BINARY,'-d','opcache.enable_cli=0','-d','display_errors=stderr',$worker,$package.'/vendor/autoload.php',$workspace],'workers'=>$workers]];}
    $configPath=$workspace.'/'.$label.'.json';file_put_contents($configPath,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    $reportPath=$workspace.'/report-'.$label.'.json';$stderrPath=$workspace.'/report-'.$label.'.stderr';
    $process=proc_open([...$command,'--workspace',$workspace,'--config',$configPath,'analyze','--reporting-format=json'],[0=>['pipe','r'],1=>['file',$reportPath,'w'],2=>['file',$stderrPath,'w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('Cannot start native analyzer: '.$label);}fclose($pipes[0]);$exit=proc_close($process);
    $stderr=file_get_contents($stderrPath);
    if(!in_array($exit,[0,1],true)||preg_match('/provider failed|native analysis fallback|invalid extension frame|rejected request|hook .* failed|pars(?:e|ing)\s+errors?|incomplete codebase|timed out|did not answer|fatal error|orchestrator error|uncaught/i',$stderr)===1){throw new RuntimeException('Operational native analyzer failure '.$label.'; '.$workspace);}
    $report=json_decode(file_get_contents($reportPath),true,flags:JSON_THROW_ON_ERROR);
    if(!is_array($report['issues']??null)||$report['issues']===[]){throw new RuntimeException('Nonempty complete native report required: '.$label);}return$report['issues'];
};
$signature=static function(array $issues):array{$rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$issues);sort($rows);return$rows;};
$run('native');$run('observe',$workspace.'/observe-worker.php');$isolated=$run('draft',$workspace.'/worker.php');
$run('controls',$workspace.'/control-worker.php');$run('draft-three',$workspace.'/worker.php',3);
$run('single-native',single:true);$run('single-draft',$workspace.'/worker.php',single:true);
$verifyProcess=proc_open([PHP_BINARY,$fixtures.'/verify.php',$workspace],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$verifyPipes);
if(!is_resource($verifyProcess)){throw new RuntimeException('Cannot start complete native verification.');}fclose($verifyPipes[0]);
if(proc_close($verifyProcess)!==0){throw new RuntimeException('Whole native signature or genuine control failure; '.$workspace);}
if(in_array('--integrated',$argv,true)){
    // Exact source plans from the neutral fixtures, not an allowance for arbitrary Warnings.
    $policyPlans=json_decode(<<<'JSON'
[
    {"file":"app/conditional_scope.php","sha256":"ad1b3d55964a3384d1348ab1bdaeffb563388081b6f6c752d7a09d6811fde29a","code":"redundant-condition","message":"This condition (type `true`) will always evaluate to true.","span":[231,250],"source":"is_array($incoming)"},
    {"file":"app/conditional_scope.php","sha256":"ad1b3d55964a3384d1348ab1bdaeffb563388081b6f6c752d7a09d6811fde29a","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[231,250],"source":"is_array($incoming)"},
    {"file":"app/conditional_scope.php","sha256":"ad1b3d55964a3384d1348ab1bdaeffb563388081b6f6c752d7a09d6811fde29a","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[302,321],"source":"! is_string($entry)"},
    {"file":"app/conditional_scope.php","sha256":"ad1b3d55964a3384d1348ab1bdaeffb563388081b6f6c752d7a09d6811fde29a","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[304,321],"source":"is_string($entry)"},
    {"file":"app/different_predicate.php","sha256":"5cf9acdc50ab578987f7f73c0332f8296c62dc90ed48b002cc1aa40a5366c5f9","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[283,302],"source":"! is_string($entry)"},
    {"file":"app/different_predicate.php","sha256":"5cf9acdc50ab578987f7f73c0332f8296c62dc90ed48b002cc1aa40a5366c5f9","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[285,302],"source":"is_string($entry)"},
    {"file":"app/duplicate_binding.php","sha256":"46550f75537d2d06c24170daa93672e4cc237f7816359a6f1c795146e15fcb05","code":"redundant-condition","message":"This condition (type `true`) will always evaluate to true.","span":[223,242],"source":"is_array($incoming)"},
    {"file":"app/duplicate_binding.php","sha256":"46550f75537d2d06c24170daa93672e4cc237f7816359a6f1c795146e15fcb05","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[223,242],"source":"is_array($incoming)"},
    {"file":"app/duplicate_binding.php","sha256":"46550f75537d2d06c24170daa93672e4cc237f7816359a6f1c795146e15fcb05","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[294,313],"source":"! is_string($entry)"},
    {"file":"app/duplicate_binding.php","sha256":"46550f75537d2d06c24170daa93672e4cc237f7816359a6f1c795146e15fcb05","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[296,313],"source":"is_string($entry)"},
    {"file":"app/dynamic_predicate.php","sha256":"4b673343d4af72e900aac708534d0d6d68b25156301bff41df49a01a311618dd","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[282,301],"source":"! is_string($entry)"},
    {"file":"app/dynamic_predicate.php","sha256":"4b673343d4af72e900aac708534d0d6d68b25156301bff41df49a01a311618dd","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[284,301],"source":"is_string($entry)"},
    {"file":"app/function_scope.php","sha256":"4588e25ad3296bec0ab040b90887fe5b2a19b479c9fe0b13c05ba7b5421f7326","code":"redundant-condition","message":"This condition (type `true`) will always evaluate to true.","span":[236,255],"source":"is_array($incoming)"},
    {"file":"app/function_scope.php","sha256":"4588e25ad3296bec0ab040b90887fe5b2a19b479c9fe0b13c05ba7b5421f7326","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[236,255],"source":"is_array($incoming)"},
    {"file":"app/function_scope.php","sha256":"4588e25ad3296bec0ab040b90887fe5b2a19b479c9fe0b13c05ba7b5421f7326","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[307,326],"source":"! is_string($entry)"},
    {"file":"app/function_scope.php","sha256":"4588e25ad3296bec0ab040b90887fe5b2a19b479c9fe0b13c05ba7b5421f7326","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[309,326],"source":"is_string($entry)"},
    {"file":"app/global_names.php","sha256":"fe54ca6bb445630c22465829b55384304e7a7cc98a7da5c56860fed2a4c7b0e4","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[202,222],"source":"\\is_array($incoming)"},
    {"file":"app/global_names.php","sha256":"fe54ca6bb445630c22465829b55384304e7a7cc98a7da5c56860fed2a4c7b0e4","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[274,294],"source":"! \\is_string($entry)"},
    {"file":"app/global_names.php","sha256":"fe54ca6bb445630c22465829b55384304e7a7cc98a7da5c56860fed2a4c7b0e4","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[276,294],"source":"\\is_string($entry)"},
    {"file":"app/imported_names.php","sha256":"9148090fc044f5159a590c8c39977d1c5648b5ab96c629ea49ba790102a53681","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[286,311],"source":"arrayPredicate($incoming)"},
    {"file":"app/imported_names.php","sha256":"9148090fc044f5159a590c8c39977d1c5648b5ab96c629ea49ba790102a53681","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[363,386],"source":"! textPredicate($entry)"},
    {"file":"app/imported_names.php","sha256":"9148090fc044f5159a590c8c39977d1c5648b5ab96c629ea49ba790102a53681","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[365,386],"source":"textPredicate($entry)"},
    {"file":"app/imported_shadow.php","sha256":"08ce1ad3b5ac99127d8c422cf3b32fa300e8a09f4e8b795a7b8907abc9de9a68","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[335,354],"source":"! is_string($entry)"},
    {"file":"app/imported_shadow.php","sha256":"08ce1ad3b5ac99127d8c422cf3b32fa300e8a09f4e8b795a7b8907abc9de9a68","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[337,354],"source":"is_string($entry)"},
    {"file":"app/intervening_call.php","sha256":"8b7386cadfc76d554839fb69d730ae0e27f955e6d24b8a8747dc74eb37aae5f2","code":"redundant-condition","message":"This condition (type `true`) will always evaluate to true.","span":[229,248],"source":"is_array($incoming)"},
    {"file":"app/intervening_call.php","sha256":"8b7386cadfc76d554839fb69d730ae0e27f955e6d24b8a8747dc74eb37aae5f2","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[229,248],"source":"is_array($incoming)"},
    {"file":"app/intervening_call.php","sha256":"8b7386cadfc76d554839fb69d730ae0e27f955e6d24b8a8747dc74eb37aae5f2","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[300,319],"source":"! is_string($entry)"},
    {"file":"app/intervening_call.php","sha256":"8b7386cadfc76d554839fb69d730ae0e27f955e6d24b8a8747dc74eb37aae5f2","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[302,319],"source":"is_string($entry)"},
    {"file":"app/keyed_iteration.php","sha256":"455008bad8247f645ab42a2d165e03b9fe08f6061ebf7cf6dfa2cfdfdcabfd60","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[284,303],"source":"! is_string($entry)"},
    {"file":"app/keyed_iteration.php","sha256":"455008bad8247f645ab42a2d165e03b9fe08f6061ebf7cf6dfa2cfdfdcabfd60","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[286,303],"source":"is_string($entry)"},
    {"file":"app/known_source_write.php","sha256":"9baf413cd81ecfe676ef9a41d38f99ae2b48f4ddb06247497d5e4e0b46787167","code":"mixed-assignment","message":"Assigning `mixed` type to a variable may lead to unexpected behavior.","span":[280,286],"source":"$entry"},
    {"file":"app/malformed_variable_doc.php","sha256":"ac9ebca28c081d490f8c9ecdbde956230a2c95d896814d469451fff4d27a4810","code":"redundant-condition","message":"This condition (type `true`) will always evaluate to true.","span":[245,264],"source":"is_array($incoming)"},
    {"file":"app/malformed_variable_doc.php","sha256":"ac9ebca28c081d490f8c9ecdbde956230a2c95d896814d469451fff4d27a4810","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[245,264],"source":"is_array($incoming)"},
    {"file":"app/malformed_variable_doc.php","sha256":"ac9ebca28c081d490f8c9ecdbde956230a2c95d896814d469451fff4d27a4810","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[316,335],"source":"! is_string($entry)"},
    {"file":"app/malformed_variable_doc.php","sha256":"ac9ebca28c081d490f8c9ecdbde956230a2c95d896814d469451fff4d27a4810","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[318,335],"source":"is_string($entry)"},
    {"file":"app/ordinary.php","sha256":"6a3610bed87897031a122a1d1fc7d92029d3caabc0d14af86bf3b726e37605a6","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[198,217],"source":"is_array($incoming)"},
    {"file":"app/ordinary.php","sha256":"6a3610bed87897031a122a1d1fc7d92029d3caabc0d14af86bf3b726e37605a6","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[269,288],"source":"! is_string($entry)"},
    {"file":"app/ordinary.php","sha256":"6a3610bed87897031a122a1d1fc7d92029d3caabc0d14af86bf3b726e37605a6","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[271,288],"source":"is_string($entry)"},
    {"file":"app/other_source.php","sha256":"89bbc3fc1e71905906d23b3bebab3814e189df319e7203c83fdc7cab4d035463","code":"mixed-assignment","message":"Assigning `mixed` type to a variable may lead to unexpected behavior.","span":[258,264],"source":"$entry"},
    {"file":"app/prior_alias.php","sha256":"e30360abf14f9533c76a9613572043a44ad6bad5d9ad8d8cacadcea7b40a540b","code":"mixed-assignment","message":"Assigning `mixed` type to a variable may lead to unexpected behavior.","span":[278,284],"source":"$entry"},
    {"file":"app/rebound_input.php","sha256":"1d748e012def01d791e596fe55265eb8ce00716fd8fe5de4d4b874a9652504e7","code":"mixed-assignment","message":"Assigning `mixed` type to a variable may lead to unexpected behavior.","span":[268,274],"source":"$entry"},
    {"file":"app/reference_binding.php","sha256":"5622096699e6310ee90b32b7640116a6c012b35665123f23625d51f3bfa4bced","code":"redundant-condition","message":"This condition (type `true`) will always evaluate to true.","span":[228,247],"source":"is_array($incoming)"},
    {"file":"app/reference_binding.php","sha256":"5622096699e6310ee90b32b7640116a6c012b35665123f23625d51f3bfa4bced","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[228,247],"source":"is_array($incoming)"},
    {"file":"app/reference_binding.php","sha256":"5622096699e6310ee90b32b7640116a6c012b35665123f23625d51f3bfa4bced","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[299,318],"source":"! is_string($entry)"},
    {"file":"app/reference_binding.php","sha256":"5622096699e6310ee90b32b7640116a6c012b35665123f23625d51f3bfa4bced","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[301,318],"source":"is_string($entry)"},
    {"file":"app/root_try.php","sha256":"21e7ce7523e74055c81ddb11f81dee81ef151cfda93e1bc7a28916fa83577e6a","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[204,223],"source":"is_array($incoming)"},
    {"file":"app/root_try.php","sha256":"21e7ce7523e74055c81ddb11f81dee81ef151cfda93e1bc7a28916fa83577e6a","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[275,294],"source":"! is_string($entry)"},
    {"file":"app/root_try.php","sha256":"21e7ce7523e74055c81ddb11f81dee81ef151cfda93e1bc7a28916fa83577e6a","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[277,294],"source":"is_string($entry)"},
    {"file":"app/shadow_array.php","sha256":"8e4e47e1be81dc42a684cdddf55cc5ee05b976e4fff3232117d5c4663cd0ca83","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[328,347],"source":"! is_string($entry)"},
    {"file":"app/shadow_array.php","sha256":"8e4e47e1be81dc42a684cdddf55cc5ee05b976e4fff3232117d5c4663cd0ca83","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[330,347],"source":"is_string($entry)"},
    {"file":"app/strong_variable_doc.php","sha256":"8f912908a95ccc814394126a4652609fedbd47295e7e257c4f63fd4d72a3eebd","code":"redundant-condition","message":"This condition (type `true`) will always evaluate to true.","span":[245,264],"source":"is_array($incoming)"},
    {"file":"app/strong_variable_doc.php","sha256":"8f912908a95ccc814394126a4652609fedbd47295e7e257c4f63fd4d72a3eebd","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[316,335],"source":"! is_string($entry)"},
    {"file":"app/strong_variable_doc.php","sha256":"8f912908a95ccc814394126a4652609fedbd47295e7e257c4f63fd4d72a3eebd","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[318,335],"source":"is_string($entry)"},
    {"file":"app/throw_rejection.php","sha256":"cd43fd9165ba0ba155b0d88363b6e8a8a11dc300ebbbcd045bb9df4561adeeaa","code":"redundant-type-comparison","message":"Redundant type assertion: `$incoming` is already `list<string>`.","span":[205,224],"source":"is_array($incoming)"},
    {"file":"app/throw_rejection.php","sha256":"cd43fd9165ba0ba155b0d88363b6e8a8a11dc300ebbbcd045bb9df4561adeeaa","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[276,295],"source":"! is_string($entry)"},
    {"file":"app/throw_rejection.php","sha256":"cd43fd9165ba0ba155b0d88363b6e8a8a11dc300ebbbcd045bb9df4561adeeaa","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[278,295],"source":"is_string($entry)"},
    {"file":"app/wrong_append.php","sha256":"7ef50d7ee9285eda0d5d34afc408c17bd684f747cc088eb39667f911c92e609b","code":"impossible-condition","message":"This condition (type `false`) will always evaluate to false.","span":[273,292],"source":"! is_string($entry)"},
    {"file":"app/wrong_append.php","sha256":"7ef50d7ee9285eda0d5d34afc408c17bd684f747cc088eb39667f911c92e609b","code":"redundant-type-comparison","message":"Redundant type assertion: `$entry` is already `string`.","span":[275,292],"source":"is_string($entry)"},
    {"file":"app/wrong_receiver.php","sha256":"3e3d0b98b17e85bb8feb225fa9593fc8acd9a31c5e3d32bbaf9f578185f53f3e","code":"mixed-assignment","message":"Assigning `mixed` type to a variable may lead to unexpected behavior.","span":[255,261],"source":"$entry"}
]
JSON, true, flags:JSON_THROW_ON_ERROR);
    $existingPolicies=$run('existing-policies',$workspace.'/policy-union-worker.php');
    $expected=$isolated;$genuineWarnings=[];
    foreach($policyPlans as$plan){
        $bytes=file_get_contents($workspace.'/'.$plan['file']);
        if(hash('sha256',$bytes)!==$plan['sha256']||substr($bytes,$plan['span'][0],$plan['span'][1]-$plan['span'][0])!==$plan['source']){throw new RuntimeException('Current existing-policy source site changed: '.$plan['file']);}
        $matches=array_filter($isolated,static function(array $issue)use($plan):bool{
            if($issue['level']!=='Warning'||$issue['code']!==$plan['code']||$issue['message']!==$plan['message']){return false;}
            $primary=array_values(array_filter($issue['annotations'],static fn(array $annotation):bool=>$annotation['kind']==='Primary'));
            return count($primary)===1&&$primary[0]['span']['file_id']['name']===$plan['file']&&$primary[0]['span']['start']['offset']===$plan['span'][0]&&$primary[0]['span']['end']['offset']===$plan['span'][1];
        });
        if(count($matches)!==1){throw new RuntimeException('One genuine complete existing-policy Warning required: '.$plan['file']);}
        $issue=array_values($matches)[0];$index=array_search($issue,$expected,true);
        if($index===false){throw new RuntimeException('Duplicate or missing whole existing-policy Warning.');}unset($expected[$index]);$genuineWarnings[]=$issue;
    }
    $errors=static fn(array $issues):array=>array_values(array_filter($issues,static fn(array $issue):bool=>$issue['level']==='Error'));
    if(count($policyPlans)!==59||$signature($existingPolicies)!==$signature(array_values($expected))||$errors($isolated)===[]||$signature($errors($isolated))!==$signature($errors($existingPolicies))){throw new RuntimeException('The measured existing policy union changed more than its59 genuine source-bound Warnings. '.$workspace);}
    foreach([1,3]as$workers){$full=$run('integrated-'.$workers,$package.'/bin/laramago-worker.php',$workers);if($signature($full)!==$signature($existingPolicies)){throw new RuntimeException('Full worker issue identity differs from independently measured policies: '.$workers.'; '.$workspace);}}
    file_put_contents($workspace.'/existing-policy-union.json',json_encode(['status'=>'PASS','standaloneArgvAnd305ControlsRetained'=>true,'genuineWholeWarningsRemoved'=>$genuineWarnings,'extraRemovedWarnings'=>59,'allCompleteErrorsAndOtherIssuesPreserved'=>true,'productionOneAndThreeWorkerParity'=>true,'nativeTypesChanged'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
}
foreach(['app/fixture-body-executed.txt','bootstrap-executed.txt','packages/composer/autoload-executed.txt']as$marker){if(file_exists($workspace.'/'.$marker)){throw new RuntimeException('Analyzed source or consumer autoload body executed.');}}
echo 'PASS: 28 invented cases, five exact Warning corrections, 28 preserved Errors, 23 nonempty negative cases, genuine controls and whole/single/one-three signatures; evidence='.$workspace.PHP_EOL;
