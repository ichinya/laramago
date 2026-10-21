<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\{DefensiveBoundaryGuardSource,FactoryFakerProof,FactoryFakerSource};
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$extract=new ReflectionMethod(FactoryFakerProof::class,'sourceProofs');$checks=0;$positive=0;
$check=static function(bool $passed,string $label)use(&$checks):void{if(!$passed){throw new RuntimeException($label);}++$checks;};
$sources=require __DIR__.'/fixtures/factory-faker-contracts/source-cases.php';
try{
    foreach($sources as $name=>$original){
        foreach(["\n","\r\n"] as $newline){
            $source=str_replace("\n",$newline,str_replace("\r\n","\n",$original));CodebaseScanSyntax::clear();
            $oracle=FactoryFakerSource::compile(DefensiveBoundaryGuardSource::parse($source));if($oracle!==[]){++$positive;}
            $cold=$extract->invoke(null,$source);$check($cold===$oracle,'fresh cached Faker facts match the independent compiler: '.$name);
            $check(count((new ReflectionProperty(CodebaseScanSyntax::class,'queries'))->getValue())===1,'Faker source extraction uses the bounded query cache');
            (new ReflectionProperty(CodebaseScanSyntax::class,'trees'))->setValue(null,[]);(new ReflectionProperty(CodebaseScanSyntax::class,'bytes'))->setValue(null,0);
            $check($extract->invoke(null,$source)===$oracle&&(new ReflectionProperty(CodebaseScanSyntax::class,'trees'))->getValue()===[],'Faker cache hit avoids tree cloning and extraction');
            $changed=$extract->invoke(null,$source);$changed['consumerMutation']=['nested'=>true];
            if($changed!==[]){$key=array_key_first($changed);$changed[$key]['class']='Mutated';}
            $check($extract->invoke(null,$source)===$oracle,'Faker source facts are independent across consumers');
            $edited="<?php // shifted\n".substr($source,5);$expected=FactoryFakerSource::compile(DefensiveBoundaryGuardSource::parse($edited));
            $check($extract->invoke(null,$edited)===$expected,'changed Faker source cannot reuse old spans');
            $check($extract->invoke(null,$source)===$oracle,'Faker source restoration recovers exact facts');
        }
    }
    $check($positive===12,'all six admitted source shapes exercise LF and CRLF');
    foreach(['<?php broken {','<?php '.str_repeat(' ',2000000)] as $source){
        foreach([1,2] as $pass){$refused=false;try{$extract->invoke(null,$source);}catch(\PhpParser\Error|\RuntimeException){$refused=true;}$check($refused,'malformed and oversized Faker source always refuse');}
    }
    echo 'Faker source cache passed: '.$checks." controls.\n";
}finally{CodebaseScanSyntax::clear();}
