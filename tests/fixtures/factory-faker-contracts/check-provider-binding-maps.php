<?php
declare(strict_types=1);

$workspace=$sourceCaseRoot.'/provider-binding-map-cases-'.bin2hex(random_bytes(8));mkdir($workspace);
$write=static function(string $root,string $path,string $bytes):void{if(!is_dir(dirname($root.'/'.$path))){mkdir(dirname($root.'/'.$path),recursive:true);}file_put_contents($root.'/'.$path,$bytes);};
$cases=[
    'empty'=>['public $bindings=[];',[]],
    'literal-binding'=>['public $bindings=[\\Faker\\Generator::class=>\\stdClass::class];',['generator-binding']],
    'literal-singleton'=>['public $singletons=["Faker\\\\Generator"=>\\stdClass::class];',['generator-binding']],
    'singleton-shorthand'=>['public $singletons=[\\Faker\\Generator::class];',['generator-binding']],
    'singleton-integer-key'=>['public $singletons=[4=>\\Faker\\Generator::class];',['generator-binding']],
    'singleton-integer-string-key'=>['public $singletons=["4"=>\\Faker\\Generator::class];',['generator-binding']],
    'unrelated-binding'=>['public $bindings=[\\stdClass::class=>\\stdClass::class];',[]],
    'unknown-map-key'=>['public $bindings=[TARGET=>\\stdClass::class];',['unknown-selected-binding-map']],
    'unknown-map-value'=>['public $singletons=CATALOGUE;',['unknown-selected-binding-map']],
];
$results=[];
foreach($cases as $name=>[$property,$hazards]){
    $root=$workspace.'/'.$name;mkdir($root);$entry=['name'=>'example/maps','install-path'=>'../example/maps','autoload'=>['psr-4'=>['Example\\Maps\\'=>'src/']],'extra'=>['laravel'=>['providers'=>['Example\\Maps\\Provider']]]];
    $write($root,'composer.json','{}');$write($root,'vendor/composer/installed.json',json_encode(['packages'=>[$entry]],JSON_THROW_ON_ERROR));$write($root,'vendor/example/maps/composer.json',json_encode(array_diff_key($entry,['install-path'=>true]),JSON_THROW_ON_ERROR));$write($root,'vendor/example/maps/src/Provider.php','<?php namespace Example\\Maps;class Provider{'.$property.'}');
    $catalog=new Ichinya\Laramago\Analyzer\FactoryFakerInstalledSources($root);$actual=$catalog->discover();if(!$actual['complete']||!$actual['current']||$actual['hazards']!==$hazards){throw new RuntimeException('Declared provider binding map differs: '.$name.'; retained '.$workspace);}$results[$name]=$actual;
}
$receipt=['sourceOnly'=>true,'nativeRun'=>false,'providerExecuted'=>false,'cases'=>count($results),'results'=>$results];file_put_contents($sourceCaseRoot.'/provider-binding-map-results.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode(['sourceCases'=>count($results),'nativeRun'=>false],JSON_THROW_ON_ERROR)."\n";
