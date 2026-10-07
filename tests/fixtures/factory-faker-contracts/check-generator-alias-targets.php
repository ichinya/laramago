<?php
declare(strict_types=1);

$workspace=$sourceCaseRoot.'/generator-alias-cases-'.bin2hex(random_bytes(8));mkdir($workspace);
$write=static function(string $root,string $path,string $bytes):void{if(!is_dir(dirname($root.'/'.$path))){mkdir(dirname($root.'/'.$path),recursive:true);}file_put_contents($root.'/'.$path,$bytes);};
$cases=[
    'selected-abstract'=>['$app->alias(\\Faker\\Generator::class,\\stdClass::class);',['generator-binding']],
    'selected-alias'=>['$app->alias(\\stdClass::class,\\Faker\\Generator::class);',['generator-binding']],
    'selected-literal-alias'=>['$app->alias(\\stdClass::class,"Faker\\\\Generator");',['generator-binding']],
    'unrelated-literal-keys'=>['$app->alias(\\stdClass::class,"Example\\\\Other");',[]],
    'dynamic-abstract'=>['$app->alias($target,\\stdClass::class);',['unknown-selected-binding-target']],
    'dynamic-alias'=>['$app->alias(\\stdClass::class,$target);',['unknown-selected-binding-target']],
    'missing-alias'=>['$app->alias(\\stdClass::class);',['unknown-selected-binding-target']],
    'named-alias'=>['$app->alias(abstract: \\stdClass::class,alias: \\Faker\\Generator::class);',['unknown-selected-binding-target']],
    'first-class-reference'=>['$callback=$app->alias(...);',[]],
];
$results=[];
foreach($cases as $name=>[$call,$hazards]){
    $root=$workspace.'/'.$name;mkdir($root);$entry=['name'=>'example/alias-catalogue','install-path'=>'../example/alias-catalogue','autoload'=>['files'=>['src/Registration.php']]];
    $write($root,'composer.json','{}');$write($root,'vendor/composer/installed.json',json_encode(['packages'=>[$entry]],JSON_THROW_ON_ERROR));$write($root,'vendor/example/alias-catalogue/composer.json',json_encode(array_diff_key($entry,['install-path'=>true]),JSON_THROW_ON_ERROR));
    $write($root,'vendor/example/alias-catalogue/src/Registration.php','<?php function register($app,$target):void{'.$call.'}');
    $catalog=new Ichinya\Laramago\Analyzer\FactoryFakerInstalledSources($root);$actual=$catalog->discover();
    if(!$actual['complete']||!$actual['current']||$actual['hazards']!==$hazards){throw new RuntimeException('Registered Generator alias key priority differs: '.$name.'; retained '.$workspace);}$results[$name]=$actual;
}
$receipt=['sourceOnly'=>true,'nativeRun'=>false,'registrationExecuted'=>false,'cases'=>count($results),'results'=>$results];file_put_contents($sourceCaseRoot.'/generator-alias-case-results.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode(['sourceCases'=>count($results),'nativeRun'=>false],JSON_THROW_ON_ERROR)."\n";
