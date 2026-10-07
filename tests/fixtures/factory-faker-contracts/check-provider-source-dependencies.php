<?php
declare(strict_types=1);

$workspace=$sourceCaseRoot.'/provider-dependency-cases-'.bin2hex(random_bytes(8));mkdir($workspace);
$write=static function(string $root,string $path,string $bytes):void{if(!is_dir(dirname($root.'/'.$path))){mkdir(dirname($root.'/'.$path),recursive:true);}file_put_contents($root.'/'.$path,$bytes);};
$cases=[
    'parent-binding'=>[true,'class Provider extends ParentProvider{}','class ParentProvider{public $bindings=[\\Faker\\Generator::class=>\\stdClass::class];}',['generator-binding']],
    'trait-registration'=>[true,'class Provider{use Registration;}','trait Registration{function register(\\Faker\\Generator $faker):void{$faker->addProvider(new \\stdClass);}}',['provider-registration']],
    'unknown-parent'=>[false,'class Provider extends Missing{}','',[]],
    'unknown-trait'=>[false,'class Provider{use Missing;}','',[]],
    'autoload-parent-selection-priority'=>[true,'class Provider extends ParentProvider{}','class ParentProvider{public $singletons=[\\Faker\\Generator::class];}',['generator-binding']],
];
$results=[];
foreach($cases as $name=>[$complete,$body,$dependency,$hazards]){
    $root=$workspace.'/'.$name;mkdir($root);$entry=['name'=>'example/dependencies','install-path'=>'../example/dependencies','autoload'=>['psr-4'=>['Example\\Dependencies\\'=>'src/']],'extra'=>['laravel'=>['providers'=>['Example\\Dependencies\\Provider']]]];
    if($name==='autoload-parent-selection-priority'){$entry['autoload']['files']=['src/ParentProvider.php'];}
    $write($root,'composer.json','{}');$write($root,'vendor/composer/installed.json',json_encode(['packages'=>[$entry]],JSON_THROW_ON_ERROR));$write($root,'vendor/example/dependencies/composer.json',json_encode(array_diff_key($entry,['install-path'=>true]),JSON_THROW_ON_ERROR));$write($root,'vendor/example/dependencies/src/Provider.php','<?php namespace Example\\Dependencies;'.$body);
    if($dependency!==''){$path=$name==='trait-registration'?'Registration':'ParentProvider';$write($root,'vendor/example/dependencies/src/'.$path.'.php','<?php namespace Example\\Dependencies;'.$dependency);}
    $catalog=new Ichinya\Laramago\Analyzer\FactoryFakerInstalledSources($root);$actual=$catalog->discover();if($actual['complete']!==$complete||$actual['current']!==$complete||$actual['hazards']!==$hazards){throw new RuntimeException('Registered provider dependency source differs: '.$name.'; retained '.$workspace);}$results[$name]=$actual;
}
$receipt=['sourceOnly'=>true,'nativeRun'=>false,'providerExecuted'=>false,'cases'=>count($results),'results'=>$results];file_put_contents($sourceCaseRoot.'/provider-dependency-results.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode(['sourceCases'=>count($results),'nativeRun'=>false],JSON_THROW_ON_ERROR)."\n";
