<?php
declare(strict_types=1);
require $argv[1];
[$unused,$autoload,$root,$mode,$output,$package,$controls]=$argv;
ini_set('display_errors','stderr');ini_set('log_errors','1');ini_set('error_log',$output.'/worker-'.getmypid().'.error.log');
// Reserve only enough bytes to close bounded fatal telemetry; this is not a larger heap.
$fatalReserve=str_repeat('x',65536);
register_shutdown_function(static function()use(&$fatalReserve,$output):void{
    $fatalReserve='';$error=error_get_last();
    if($error!==null&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){
        $error['message']=substr($error['message'],0,4096);
        file_put_contents($output.'/worker-'.getmypid().'.fatal.json',json_encode(['pid'=>getmypid(),'error'=>$error,'peakBytes'=>memory_get_peak_usage(true),'memoryLimit'=>ini_get('memory_limit')],JSON_THROW_ON_ERROR));
    }
});
if(str_starts_with($mode,'full-')){
    $kind=str_starts_with($mode,'full-before')?'full-before':'full-after';
    require $root.'/'.$kind.'-worker.php';
    exit;
}
if($mode!=='native'){
    foreach(['StandardEloquentFirstDeclarationContracts','LiteralGuardedTupleModelProjection','GuardedLocalQueryModelContract',
        'GuardedLocalModelTupleArgumentFilter','GuardedLocalModelTuplePlugin'] as $class){require_once $package.'/src/Analyzer/'.$class.'.php';}
}
if($mode==='observe'){
    require_once __DIR__.'/LocalModelObservation.php';require_once __DIR__.'/RouteDeclarationObservation.php';
    $plugins=[new \Example\LocalModelTests\LocalModelObservation($root,$output)];
}elseif($mode==='controls'){
    require_once $controls.'/SourceControls.php';require_once $controls.'/NativeHarness.php';
    $plugins=[new \Example\LocalModelTests\LocalModelNativeControls($root,$output)];
}elseif(in_array($mode,['isolated','isolated-three'],true)){
    $plugins=[new \Ichinya\Laramago\Analyzer\GuardedLocalModelTuplePlugin($root)];
}elseif($mode==='native'){$plugins=[];}
else{throw new RuntimeException('Unknown guarded local model matrix mode.');}
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/guarded-local-model',name:'Guarded local model tuple controls',version:'1',analyzerPlugins:$plugins)))->run();
