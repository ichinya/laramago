<?php
declare(strict_types=1);

/** All current production plugins remain registered except the candidate replaced by its test observer. */
function compatibilityProductionWorker(string $package,string $workspace,string $fixture,string $constant,string $testClass,string $runtimePlugin):string
{
    $production=file_get_contents($package.'/bin/laramago-worker.php');$production=preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/','',$production,1);
    $production=preg_replace('/^\s*new (?:\\\\?Ichinya\\\\Laramago\\\\Analyzer\\\\)?'.preg_quote($runtimePlugin,'/').'\([^\r\n]*\),\s*$/m','',$production);
    $prefix="<?php\ndeclare(strict_types=1);\n\$draftPackage=\$argv[1];\$draftMode=\$argv[3];\$draftOutput=\$argv[4];require \$draftPackage.'/vendor/autoload.php';define('".$constant."',true);require ".var_export($fixture.'/worker-classes.php',true).";\$argv[1]=\$draftPackage.'/vendor/autoload.php';\n";
    $needle='        new LaravelPlugin($projectRoot),';$production=str_replace($needle,$needle."\n        new \\".$testClass."(\$projectRoot,\$draftMode,\$draftOutput),",$production,$changed);
    if($changed!==1){throw new RuntimeException('Current production registry anchor changed.');}
    $path=$workspace.'/full-worker.php';file_put_contents($path,$prefix.$production);return $path;
}
function compatibilityNativeGate(string $package,string $workspace,string $worker,array $config,string $stage,string $mode,int $workers):array
{
    if(is_file($workspace.'/'.$stage.'.json')){throw new RuntimeException('Preserve earlier native stage.');}
    $observer=$workspace.'/'.$stage.'-observer.jsonl';file_put_contents($observer,'');$config['extends']=$package.'/presets/laravel.toml';
    // Cache mutation controls require a single analyzer thread. Ordinary gates
    // retain Mago's default parallelism, independently of extension worker count.
    if(str_starts_with($mode,'cache-')){$config['threads']=1;}else{unset($config['threads']);}
    $config['extension-hosts']=['compatibility'=>['command'=>[PHP_BINARY,'-d','memory_limit=512M','-d','opcache.enable_cli=0',$worker,$package,$workspace,$mode,$observer],'workers'=>$workers,'request-timeout-ms'=>120000]];
    file_put_contents($workspace.'/mago.json',json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));copy($workspace.'/mago.json',$workspace.'/'.$stage.'-config.json');
    $binary=getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';$command=str_ends_with($binary,'.exe')?[$binary]:[PHP_BINARY,$binary];
    $process=proc_open([...$command,'--workspace',$workspace,'analyze','--reporting-format=json'],[0=>['pipe','r'],1=>['file',$workspace.'/'.$stage.'.json','w'],2=>['file',$workspace.'/'.$stage.'.stderr','w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('Cannot start native compatibility fixture.');}fclose($pipes[0]);$exit=proc_close($process);
    foreach(['.json','.stderr'] as $suffix){copy($workspace.'/'.$stage.$suffix,$workspace.'/'.$stage.$suffix.'.raw');}
    file_put_contents($workspace.'/'.$stage.'-closed-receipt.json',json_encode(['closed'=>true,'exit'=>$exit,'threads'=>$config['threads']??'default','workers'=>$workers,'rawSha256'=>hash_file('sha256',$workspace.'/'.$stage.'.json.raw'),'stderrSha256'=>hash_file('sha256',$workspace.'/'.$stage.'.stderr.raw')],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    $stderr=file_get_contents($workspace.'/'.$stage.'.stderr');if(!in_array($exit,[0,1],true)||preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|invalid[^\r\n]*frame|hook[^\r\n]*failed|fatal|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i',$stderr)){throw new RuntimeException('Native fixture failure; retained '.$workspace);}
    return json_decode(file_get_contents($workspace.'/'.$stage.'.json'),true,flags:JSON_THROW_ON_ERROR)['issues'];
}
