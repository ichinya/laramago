<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax as Syntax;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$checks=0;
$check=static function(bool $condition,string $message)use(&$checks):void{
    if(!$condition){throw new RuntimeException($message);}++$checks;
};
$encode=new ReflectionMethod(Syntax::class,'storedTree');
$decode=new ReflectionMethod(Syntax::class,'serializedTree');
$hasCodec=function_exists('gzdeflate')&&function_exists('gzinflate');
$noise='';for($i=0;$i<256;++$i){$noise.=hash('sha256','fixed-incompressible-control-'.$i,true);}
foreach([[],['small'=>'value'],['repeated'=>str_repeat('source snapshot ',10000)],['binary'=>$noise]] as $value){
    $raw=serialize($value);$stored=$encode->invoke(null,$raw);
    $check($decode->invoke(null,$stored,strlen($raw))===$raw,'Codec changed exact serialized bytes');
    $check(strlen($stored)<=strlen($raw),'Storage expanded a snapshot');
    if(!$hasCodec){$check($stored===$raw,'Optional zlib fallback changed storage');}
}
$raw=serialize(['repeated'=>str_repeat('source snapshot ',10000)]);
$stored=$encode->invoke(null,$raw);
$check(!$hasCodec||strlen($stored)<strlen($raw)/4,'Compressible snapshot was not compressed');
$check($encode->invoke(null,$noise)===$noise,'Incompressible storage did not retain raw bytes');

$sources=[
    '<?php',
    '<?php namespace Sample; use Vendor\Thing as Alias; /** Doc */ final class Demo { public function make(): Alias { return new Alias; } }',
    '<?php $a = ["\0binary", [1, null, false]]; $f = fn ($x) => $x?->name;',
    '<?php function {',
];
$real=0;
if(isset($argv[1],$argv[2])){
    $inputs=json_decode(file_get_contents($argv[2]),true,flags:JSON_THROW_ON_ERROR);
    foreach($inputs as $name=>$digest){
        if(!str_ends_with($name,'.php')){continue;}
        $contents=file_get_contents($argv[1].'/'.$name);
        $check(hash('sha256',$contents)===$digest,'Real source contents changed');
        $sources[]=$contents;++$real;
    }
    $check($real===580,'Real source corpus is incomplete');
}
foreach($sources as $contents){
    Syntax::clear();
    try{
        // Independent parser/resolver; neither expected graph nor errors use cache code.
        $expected=(new ParserFactory)->createForNewestSupportedVersion()->parse($contents)??[];
        $expected=(new NodeTraverser(new NameResolver))->traverse($expected);
        $expected=serialize($expected);$failure=null;
    }catch(\PhpParser\Error $error){$failure=[$error::class,$error->getMessage()];}
    for($hit=0;$hit<3;++$hit){
        try{
            $actual=Syntax::parse($contents);
            $check($failure===null&&serialize($actual)===$expected,'Cold/warm resolved AST graph changed');
            foreach($actual as $node){if($node instanceof Node){$node->setAttribute('mutation-control',new stdClass);break;}}
        }catch(\PhpParser\Error $error){
            $check($failure===[$error::class,$error->getMessage()],'Parse error behavior changed');
            $check((new ReflectionProperty(Syntax::class,'trees'))->getValue()===[],'Failed parse was retained');
        }
    }
    $trees=(new ReflectionProperty(Syntax::class,'trees'))->getValue();
    $sizes=(new ReflectionProperty(Syntax::class,'uncompressedSizes'))->getValue();
    $check((new ReflectionProperty(Syntax::class,'bytes'))->getValue()===array_sum(array_map('strlen',$trees)),'Stored bytes were miscounted');
    $check((new ReflectionProperty(Syntax::class,'uncompressedBytes'))->getValue()===array_sum($sizes),'Original logical budget was miscounted');
    $check(array_keys($trees)===array_keys($sizes),'Stored snapshot sizes lost ownership');
}
Syntax::clear();
$check((new ReflectionProperty(Syntax::class,'uncompressedBytes'))->getValue()===0,'Clear retained logical bytes');
$check((new ReflectionProperty(Syntax::class,'uncompressedSizes'))->getValue()===[],'Clear retained logical sizes');
echo json_encode(['complete'=>true,'checks'=>$checks,'real_php_files'=>$real,'zlib_available'=>$hasCodec,
    'independent_resolved_graph_oracle'=>true,'cache_hit_mutation_isolation'=>true],JSON_THROW_ON_ERROR)."\n";
