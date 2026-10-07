<?php
declare(strict_types=1);

$workspace=$sourceCaseRoot.'/static-provider-cases-'.bin2hex(random_bytes(8));mkdir($workspace);
$write=static function(string $root,string $path,string $bytes):void{if(!is_dir(dirname($root.'/'.$path))){mkdir(dirname($root.'/'.$path),recursive:true);}file_put_contents($root.'/'.$path,$bytes);};
$results=[];
foreach(['empty'=>[true,[]],'vendor-provider'=>[true,['generator-binding']],'application-provider'=>[true,['provider-registration']],'missing-provider'=>[false,[]],'dynamic-list'=>[false,[]]] as $name=>[$complete,$hazards]){
    $root=$workspace.'/'.$name;mkdir($root);$entry=['name'=>'example/static','install-path'=>'../example/static','autoload'=>['psr-4'=>['Example\\StaticProvider\\'=>'src/']]];
    $write($root,'composer.json',json_encode(['autoload'=>['psr-4'=>['App\\'=>'app/']]],JSON_THROW_ON_ERROR));$write($root,'vendor/composer/installed.json',json_encode(['packages'=>[$entry]],JSON_THROW_ON_ERROR));$write($root,'vendor/example/static/composer.json',json_encode(array_diff_key($entry,['install-path'=>true]),JSON_THROW_ON_ERROR));
    $write($root,'vendor/example/static/src/Provider.php','<?php namespace Example\\StaticProvider;class Provider{function register($app):void{$app->singleton(\\Faker\\Generator::class,fn()=>new \\Faker\\Generator);}}');
    $write($root,'app/Provider.php','<?php namespace App;class Provider{function register(\\Faker\\Generator $generator):void{$generator->addProvider(new \\stdClass);}}');
    $list=match($name){'empty'=>'<?php return [];','vendor-provider'=>'<?php return [\\Example\\StaticProvider\\Provider::class];','application-provider'=>'<?php return [\\App\\Provider::class];','missing-provider'=>'<?php return [\\Example\\StaticProvider\\Missing::class];','dynamic-list'=>'<?php return chooseProviders();'};$write($root,'bootstrap/providers.php',$list);
    $catalog=new Ichinya\Laramago\Analyzer\FactoryFakerInstalledSources($root);$actual=$catalog->discover();if($actual['complete']!==$complete||$actual['hazards']!==$hazards){throw new RuntimeException('Static provider source policy differs: '.$name.'; retained '.$workspace);}$results[$name]=$actual;
}
$receipt=['sourceOnly'=>true,'nativeRun'=>false,'bootstrapExecuted'=>false,'cases'=>count($results),'results'=>$results];file_put_contents($sourceCaseRoot.'/static-provider-source-results.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode(['sourceCases'=>count($results),'nativeRun'=>false],JSON_THROW_ON_ERROR)."\n";
