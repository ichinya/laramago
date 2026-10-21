<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\FactoryFakerRegistrationReceivers;
use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';

$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/faker-observe-fusion/oracle.json'),true,flags:JSON_THROW_ON_ERROR);
$oracle=eval('namespace Ichinya\\Laramago\\Analyzer; use PhpParser\\{Node,NodeFinder}; use Ichinya\\Laramago\\Analyzer\\DefensiveBoundaryGuardSource as Source; return function(array $nodes,string $path):void {'.$fixture['observe_source_body'].'};');
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok){throw new RuntimeException($label);}++$checks;};
$sources=array_values(require __DIR__.'/fixtures/factory-faker-contracts/source-cases.php');
$sources=array_merge($sources,[
    '<?php function app(){} function class_uses_recursive(){} function trait_uses_recursive(){}',
    '<?php namespace Example; class A { function markConfigCached(){} function MARKROUTESCACHED(){} use T { markConfigCached as alias; other as markRoutesCached; } function f(){ return new class { use T { markConfigCached as other; } }; } }',
    '<?php class A { function f($name){ $this->app->{$name}(); app()->{$name}(); $other->{$name}(); A::{$name}(); } }',
    '<?php A::$applicationBuilder=1; B::$APPLICATIONBUILDER=2; A::$other=3; $x=fn()=>app();',
    '<?php class_alias("Carbon\\CarbonImmutable","Selected"); class_alias("Other","Faker\\Generator"); class_alias("Other",$unknown); class_alias(...);',
    '<?php namespace Example; use function class_alias as alias; alias("One","Two"); function nested(){ function app(){} }',
    '<?php class A extends \Illuminate\Support\Facades\Cache { function f(){ self::swap($x); static::$app=$y; } }',
    '<?php declare(ticks=1); interface I { function markRoutesCached(); } trait T { function markConfigCached(){} } enum E { case X; }',
]);
try{
    foreach($sources as $source){foreach(["\n","\r\n"] as $newline){
        $nodes=DefensiveBoundaryGuardSource::parse(str_replace("\n",$newline,str_replace("\r\n","\n",$source)));
        $unchanged=serialize($nodes);
        foreach([null,['sourceHashes'=>[],'classes'=>[],'initializerCalls'=>[]]] as $facade){
            foreach(['app/Example.php','vendor/laravel/framework/src/Illuminate/Support/helpers.php','vendor/laravel/framework/src/Illuminate/Foundation/helpers.php','other/Example.php'] as $path){
                $old=new FactoryFakerRegistrationReceivers('/invented/faker-source-root');
                $new=new FactoryFakerRegistrationReceivers('/invented/faker-source-root');
                foreach([$old,$new] as $receiver){(new ReflectionProperty($receiver,'cacheFacadeSource'))->setValue($receiver,$facade);}
                $legacy=Closure::bind($oracle,$old,FactoryFakerRegistrationReceivers::class);
                for($pass=0;$pass<2;$pass++){
                    $legacy($nodes,$path);$new->observeSource($nodes,$path);
                    $check(serialize($old)===serialize($new),'Complete original/new receiver state, order and repeated observation');
                    $check(serialize($nodes)===$unchanged,'Neither observer may mutate source nodes');
                }
            }
        }
    }}
}finally{CodebaseScanSyntax::clear();}
echo 'PASS Faker observation fusion: '.$checks." controls.\n";
