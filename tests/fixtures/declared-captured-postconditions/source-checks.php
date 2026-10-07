<?php
declare(strict_types=1);
// Parser/source checks only. Native admission still requires the genuine SDK fixture gate.

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhysicalFactoryModelDefault;
$checks=[];$check=static function(string $name,bool $passed)use(&$checks):void { if(!$passed) { throw new RuntimeException('Physical model source check failed: '.$name); }$checks[$name]=true; };
$bytes= <<<'PHP'
<?php
namespace SourceControls;
use Example\Model as ModelAlias;
use Example\Factory as FactoryAlias;
trait MethodTrait { public function invoke(): void {} }
class ParentFactory extends FactoryAlias { protected $model = ModelAlias::class; }
class ChildFactory extends ParentFactory { use MethodTrait { invoke as otherInvoke; } }
class OpenFactory extends FactoryAlias { /** @var class-string<TModel> */ protected $model; }
PHP;
$read=static fn(string $input):array=>PhysicalFactoryModelDefault::source($input);
$source=$read($bytes);$literal=$source['sourcecontrols\parentfactory']['properties']['model'];
$check('literal class initializer resolves current import',$literal['default']['kind']==='class'&&$literal['default']['class']==='Example\Model');
$check('current imported parent resolves',$source['sourcecontrols\parentfactory']['parent']==='Example\Factory');
$check('inherited owner remains physical parent',!isset($source['sourcecontrols\childfactory']['properties']['model'])&&$source['sourcecontrols\childfactory']['parent']==='SourceControls\ParentFactory');
$check('method trait adaptation retains physical trait closure',$source['sourcecontrols\childfactory']['traits']===['SourceControls\MethodTrait']);
$check('uninitialized physical model is distinguished',$source['sourcecontrols\openfactory']['properties']['model']['default']['kind']==='uninitialized');
$check('uninitialized model retains exact doc source span',$source['sourcecontrols\openfactory']['properties']['model']['docSpan']!==null);
$changed=$read(str_replace('Example\Model as ModelAlias','Example\Other as ModelAlias',$bytes));
$check('changed import changes literal semantic binding',$changed['sourcecontrols\parentfactory']['properties']['model']['default']['class']==='Example\Other');
foreach(['quoted class name'=>"'Example\\Model'",'self class constant'=>'self::class','static class constant'=>'static::class','foreign class constant'=>'ModelAlias::OTHER','array default'=>'[]'] as $name=>$expression) {
    $changed=$read(str_replace('ModelAlias::class',$expression,$bytes));$check($name.' is not a closed literal class certificate',$changed['sourcecontrols\parentfactory']['properties']['model']['default']['kind']==='unknown');
}
$changed=$read(str_replace('ModelAlias::class','null',$bytes));$check('explicit null differs from absent initializer',$changed['sourcecontrols\parentfactory']['properties']['model']['default']['kind']==='null');
$changed=$read(str_replace('protected $model = ModelAlias::class','protected string $model = ModelAlias::class',$bytes));$check('physical type declaration is recorded for rejection',$changed['sourcecontrols\parentfactory']['properties']['model']['typeSpan']!==null);
$changed=$read(str_replace('protected $model = ModelAlias::class','protected static $model = ModelAlias::class',$bytes));$check('static declaration is rejected structurally',!$changed['sourcecontrols\parentfactory']['properties']['model']['ordinary']);
$changed=$read(str_replace('protected $model = ModelAlias::class','#[Example] protected $model = ModelAlias::class',$bytes));$check('attributed declaration is rejected structurally',!$changed['sourcecontrols\parentfactory']['properties']['model']['ordinary']);
$changed=$read(str_replace('protected $model = ModelAlias::class;','protected $model = ModelAlias::class; protected $model = null;',$bytes));$check('duplicate physical model declaration poisons the class',$changed['sourcecontrols\parentfactory']['traits']===['invalid duplicate or promoted model declaration']);
$check('over-budget source is refused',$read(str_repeat(' ',2_000_001))===[]);
$check('invalid declaration source is refused',$read('<?php class Broken {')===[]);
$generic= <<<'PHP'
<?php
namespace SourceGenerics;
use Example\Model as ModelAlias;
use Example\Factory as FactoryAlias;
/** @extends FactoryAlias<ModelAlias> */
class SpecificFactory extends FactoryAlias { protected $model = ModelAlias::class; }
/** @template TModel of ModelAlias */
class GenericFactory { /** @var class-string<TModel> */ protected $model; }
PHP;
$projection=$read($generic);
$check('generic extends binding follows current imports',$projection['sourcegenerics\specificfactory']['genericExtends']===['Example\Factory','Example\Model']);
$check('generic constraint follows current imports',$projection['sourcegenerics\genericfactory']['genericTemplate']==='Example\Model');
$projection=$read(str_replace('Example\Model as ModelAlias','Example\Other as ModelAlias',$generic));
$check('changed model import changes generic projection binding',$projection['sourcegenerics\specificfactory']['genericExtends']===['Example\Factory','Example\Other']);
$check('changed model import changes template constraint',$projection['sourcegenerics\genericfactory']['genericTemplate']==='Example\Other');
$projection=$read(str_replace('@extends FactoryAlias<ModelAlias>','@extends FactoryAlias<ModelAlias> @extends FactoryAlias<ModelAlias>',$generic));
$check('duplicate generic extends tags are refused',$projection['sourcegenerics\specificfactory']['genericExtends']===false);
$projection=$read(str_replace('@template TModel of ModelAlias','@template TModel of ModelAlias @template Other of ModelAlias',$generic));
$check('duplicate generic template tags are refused',$projection['sourcegenerics\genericfactory']['genericTemplate']===false);
$projection=$read(str_replace('@template TModel of ModelAlias','@template-covariant TModel of ModelAlias',$generic));
$check('unobserved template variance syntax is refused',$projection['sourcegenerics\genericfactory']['genericTemplate']===false);
$projection=$read(str_replace('@extends FactoryAlias<ModelAlias>','@extends FactoryAlias<self>',$generic));
$check('self generic model is not a closed class binding',$projection['sourcegenerics\specificfactory']['genericExtends']===false);
$projection=$read(str_replace('/** @extends FactoryAlias<ModelAlias> */','',$generic));
$check('absent generic model binding stays absent',$projection['sourcegenerics\specificfactory']['genericExtends']===null);
foreach(['lone namespace delimiter'=>'\\','repeated namespace delimiter'=>'Example\\\\Model','trailing namespace delimiter'=>'Example\\','invalid numeric segment'=>'Example\\9Model'] as $name=>$token) {
    $projection=$read(str_replace('@extends FactoryAlias<ModelAlias>','@extends '.$token.'<ModelAlias>',$generic));
    $check('malformed extends '.$name.' is refused without a parser constructor error',$projection['sourcegenerics\specificfactory']['genericExtends']===false);
    $projection=$read(str_replace('@template TModel of ModelAlias','@template TModel of '.$token,$generic));
    $check('malformed constraint '.$name.' is refused without a parser constructor error',$projection['sourcegenerics\genericfactory']['genericTemplate']===false);
}
return $checks;
