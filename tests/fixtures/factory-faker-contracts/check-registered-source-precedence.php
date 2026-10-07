<?php
declare(strict_types=1);

$workspace=$sourceCaseRoot.'/registered-source-precedence-'.bin2hex(random_bytes(8));mkdir($workspace);
$write=static function(string $root,string $path,string $bytes):void{if(!is_dir(dirname($root.'/'.$path))){mkdir(dirname($root.'/'.$path),recursive:true);}file_put_contents($root.'/'.$path,$bytes);};
$results=[];
foreach(['provider-and-autoload'=>true,'missing-provider-physical-class'=>false,'provider-autoload-classmap'=>true] as $name=>$complete){
    $root=$workspace.'/'.$name;mkdir($root);$entry=['name'=>'example/collision','install-path'=>'../example/collision','extra'=>['laravel'=>['providers'=>['Example\\Collision\\Provider']]],'autoload'=>['psr-4'=>['Example\\Collision\\'=>'src/'],'files'=>['src/Provider.php']]];
    if($name==='provider-autoload-classmap'){$entry['autoload']['classmap']=['src/Provider.php'];}
    $write($root,'composer.json','{}');$write($root,'vendor/composer/installed.json',json_encode(['packages'=>[$entry]],JSON_THROW_ON_ERROR));$write($root,'vendor/example/collision/composer.json',json_encode(array_diff_key($entry,['install-path'=>true]),JSON_THROW_ON_ERROR));
    $code=$name==='missing-provider-physical-class'?'<?php namespace Example\\Collision;class WrongClass{}':'<?php namespace Example\\Collision;class Provider{function register(\\Faker\\Generator $faker):void{$faker->addProvider(new \\stdClass);}}';
    // The classmap version contains an unrelated words method; classmap discovery
    // must retain provider/autoload registration authority rather than replace it.
    if($name==='provider-autoload-classmap'){$code=str_replace('function register','function words(){return [];}function register',$code);}
    $write($root,'vendor/example/collision/src/Provider.php',$code);$catalog=new Ichinya\Laramago\Analyzer\FactoryFakerInstalledSources($root);$actual=$catalog->discover();$expected=$complete?['provider-registration']:[];
    if($actual['complete']!==$complete||$actual['hazards']!==$expected){throw new RuntimeException('Registered source precedence differs: '.$name.'; retained '.$workspace);}$results[$name]=$actual;
}
$receipt=['sourceOnly'=>true,'nativeRun'=>false,'cases'=>count($results),'results'=>$results];file_put_contents($sourceCaseRoot.'/registered-source-precedence-results.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode(['sourceCases'=>count($results),'nativeRun'=>false],JSON_THROW_ON_ERROR)."\n";
