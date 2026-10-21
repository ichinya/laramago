<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax as Syntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\FactoryResultContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties;
use Ichinya\Laramago\Analyzer\StaticAnalysis\StoredCallbackSourceDeclarations;
use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardProof;
require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';

$projectionNodeMutations=0;
function poisonReturnedSourceNode(mixed &$value): bool {
    if($value instanceof \PhpParser\Node){$value->setAttribute('projection-owner-mutation',true);return true;}
    if(is_array($value)){
        foreach($value as &$child){if(poisonReturnedSourceNode($child)){return true;}}
        unset($child);
    }
    return false;
}
/** Compare registered extractors with isolated trees and poison returned nodes across cache hits. */
function verifySourceProjectionOwners(string $source): int {
    global $projectionNodeMutations;
    Syntax::clear();
    $context=(new ReflectionClass(\Mago\Sdk\Analyzer\InitializationContext::class))->newInstanceWithoutConstructor();
    (new RefreshedModelProperties)->initialize($context);
    (new StoredCallbackSourceDeclarations)->initialize($context);
    (new DefensiveBoundaryGuardProof('.'))->initialize($context);
    (new \Ichinya\Laramago\Analyzer\DefaultDateArgumentFilter('.'))->initialize($context);
    (new \Ichinya\Laramago\Analyzer\RememberedGuardedGetterProvider('.'))->initialize($context);
    (new \Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof('.'))->initialize($context);
    $token=new class implements \Mago\Sdk\CancellationTokenInterface {
        public function isCancelled():bool{return false;} public function throwIfCancelled():void{}
        public function subscribe(Closure $callback):int{return 0;} public function unsubscribe(int $subscription):void{}
    };
    (new FactoryResultContract)->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext(\Mago\Sdk\PHPVersion::fromParts(8,5),$token,[],true,true));
    $projectors=(new ReflectionProperty(Syntax::class,'projections'))->getValue();
    if(count($projectors)!==8){throw new RuntimeException('All eight real source projections must be active');}
    $expected=[];$checks=0;
    foreach($projectors as $id=>$extractor) {
        $function=new ReflectionFunction($extractor);
        if($function->getClosureThis()!==null||$function->getStaticVariables()!==[]){throw new RuntimeException('Source projection retains consumer state');}
        $nodes=Syntax::parse($source);$before=serialize($nodes);
        try{$expected[$id]=['value'=>serialize($extractor($nodes))];}catch(Throwable $failure){$expected[$id]=['failure'=>[$failure::class,$failure->getMessage()]];}
        if(serialize($nodes)!==$before){throw new RuntimeException('Real projection mutates its source tree: '.$id);}
        ++$checks;
    }
    foreach($projectors as $id=>$extractor) {
        try{$answer=Syntax::query($source,$id,$extractor);$actual=['value'=>serialize($answer)];}catch(Throwable $failure){$actual=['failure'=>[$failure::class,$failure->getMessage()]];}
        if($actual!==$expected[$id]){throw new RuntimeException('Batched and isolated projection results differ: '.$id);}
        ++$checks;
        if(isset($actual['value'])){
            if(poisonReturnedSourceNode($answer)){++$projectionNodeMutations;}
            if(serialize(Syntax::query($source,$id,$extractor))!==$expected[$id]['value']){throw new RuntimeException('Returned projection mutation crossed consumer ownership: '.$id);}
            ++$checks;
        }
    }
    Syntax::clear();return $checks;
}
$checks=0;
foreach([
    '<?php namespace Example; use Vendor\Row as Alias; class Item { function f(): Alias { throw new \Exception; } }',
    '<?php namespace Example; class Item { function f($row) { $row->refresh(); return $row->value; } }',
    '<?php \Illuminate\Database\Eloquent\Factories\Factory::guessModelNamesUsing(static fn()=>\App\Models\Other::class);',
    '<?php class Nested { function f() { \Illuminate\Database\Eloquent\Factories\Factory::flushState(); } }',
    '<?php \Illuminate\Http\Request::macro("value", static fn()=>true);',
    '<?php declare(ticks=1); trait Shared {} interface Contract {} enum Choice { case One; }',
    '<?php if ($object->pick() instanceof \Example\Value && $object->pick()->run()) {}',
    '<?php namespace Illuminate\Support\Facades; abstract class Facade { abstract public static function swap($instance); }',
] as $source){$checks+=verifySourceProjectionOwners($source);}
if($projectionNodeMutations===0){throw new RuntimeException('No returned getter node was mutated across cache hits');}
echo 'PASS real source projection owners: '.$checks." controls.\n";
