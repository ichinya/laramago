<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\InertiaEntryReferencesHook;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;
use Mago\Sdk\Analyzer\NodeAnalysisContext;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$root=sys_get_temp_dir().'/laramago inertia eligibility '.bin2hex(random_bytes(8));
mkdir($root.'/pages',0777,true);
file_put_contents($root.'/pages/Present.vue','<template/>');
$write=static function(mixed $configuration)use($root):void{
    $data=$configuration===null?[]:['extra'=>['laramago'=>['reference-catalogs'=>['inertia-pages'=>$configuration]]]];
    file_put_contents($root.'/composer.json',json_encode($data,JSON_THROW_ON_ERROR));
};
$valid=['complete'=>true,'paths'=>['pages'],'extensions'=>['vue']];
$cases=[null,[],['complete'=>false]+$valid,['complete'=>null]+$valid,
    ['complete'=>0]+$valid,['complete'=>1]+$valid,['complete'=>'true']+$valid,
    ['complete'=>[]]+$valid,['complete'=>true,'paths'=>[],'extensions'=>['vue']],
    ['complete'=>true,'paths'=>['absent'],'extensions'=>['vue']],
    ['complete'=>true,'paths'=>['pages'],'extensions'=>[]],$valid,
    ['complete'=>true,'unique'=>true,'paths'=>['pages'],'extensions'=>['vue']]];
$checks=0;$unknown=0;$contexts=0;
foreach($cases as $configuration){
    $write($configuration);$catalog=new ReferenceCatalogs($root);
    $before=serialize($catalog);
    $may=$catalog->mayProveMissingInertiaPages();
    if(serialize($catalog)!==$before){throw new RuntimeException('Eligibility changed a lazy catalog snapshot');}
    ++$checks;
    foreach(['Present','Missing','present','','Nested/Missing'] as $name){
        $membership=$catalog->containsInertiaPage($name);
        // Independent existing membership semantics are the diagnostic oracle.
        if(!$may&&$membership===false){throw new RuntimeException('Skipped configuration could prove absence');}
        ++$checks;
    }
    if(!$may){
        ++$unknown;
        $hook=new InertiaEntryReferencesHook($root);
        // Irrelevant policy must need neither source syntax nor SDK capabilities.
        $context=(new ReflectionClass(NodeAnalysisContext::class))->newInstanceWithoutConstructor();
        $hook->analyze($context);++$contexts;
    }
}
$write(['complete'=>false]+$valid);$old=new ReferenceCatalogs($root);
$write($valid);$fresh=new ReferenceCatalogs($root);
if($old->mayProveMissingInertiaPages()||!$fresh->mayProveMissingInertiaPages()
    ||$old->containsInertiaPage('Missing')!==null||$fresh->containsInertiaPage('Missing')!==false){
    throw new RuntimeException('Constructor configuration snapshot behavior changed');
}
$write($valid);$lazy=new ReferenceCatalogs($root);
if(!$lazy->mayProveMissingInertiaPages()){throw new RuntimeException('Complete configuration was excluded');}
file_put_contents($root.'/pages/Late.vue','<template/>');
if($lazy->containsInertiaPage('Late')!==true){throw new RuntimeException('Eligibility loaded the filesystem catalog too early');}
echo json_encode(['complete'=>true,'controls'=>$checks+2,'configurations'=>count($cases),
    'unreportable_configurations'=>$unknown,'no_context_access_controls'=>$contexts,
    'constructor_snapshot_preserved'=>true,'lazy_filesystem_timing_preserved'=>true],JSON_THROW_ON_ERROR)."\n";
