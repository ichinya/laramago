<?php
declare(strict_types=1);
// Parse the actual control literals and production regex; no hook, DTO or source body execution.
require_once dirname(__DIR__,3).'/vendor/autoload.php';
$parser=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion();$finder=new \PhpParser\NodeFinder;$checks=[];
$nodes=$parser->parse(file_get_contents(__DIR__.'/ModelSchemaNativeControls.php'));
$arrays=[];
foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Expr\Assign::class) as $assignment){
    if($assignment->var instanceof \PhpParser\Node\Expr\Variable&&$assignment->var->name==='sources'){$arrays[]=$assignment->expr;}
}
if(count($arrays)!==1||!$arrays[0] instanceof \PhpParser\Node\Expr\Array_){throw new RuntimeException('The actual source control recipe is not uniquely literal.');}
foreach($arrays[0]->items as $item){
    if(!$item->key instanceof \PhpParser\Node\Scalar\String_||!$item->value instanceof \PhpParser\Node\Expr\Array_){throw new RuntimeException('A source control is not literal.');}
    $values=$item->value->items;$path=$values[0]->value;$before=$values[1]->value;$after=$values[2]->value;
    if(!$path instanceof \PhpParser\Node\Expr\BinaryOp\Concat||!$path->right instanceof \PhpParser\Node\Scalar\String_
        ||!$before instanceof \PhpParser\Node\Scalar\String_||!$after instanceof \PhpParser\Node\Scalar\String_){throw new RuntimeException('A control anchor is not a literal source expression.');}
    $bytes=str_replace("\r\n","\n",file_get_contents(__DIR__.'/data'.$path->right->value));
    if(strpos($bytes,$before->value)===false||$before->value===$after->value){throw new RuntimeException('An actual source control anchor is absent or unchanged: '.$item->key->value);}
    $checks['current control anchor: '.$item->key->value]=true;
    if($item->key->value==='same-length malformed temporal documentation suffix'&&strlen($before->value)!==strlen($after->value)){throw new RuntimeException('The malformed suffix control must preserve every source byte offset.');}
}
if(count($checks)!==13){throw new RuntimeException('Thirteen source controls require physically current literal anchors.');}
$nodes=$parser->parse(file_get_contents(dirname(__DIR__,3).'/src/Analyzer/StaticAnalysis/BoundedPrimitiveSchemaContract.php'));$patterns=[];
foreach($finder->findInstanceOf($nodes,\PhpParser\Node\Expr\FuncCall::class) as $call){
    if($call->name instanceof \PhpParser\Node\Name&&$call->name->toString()==='preg_match'&&($call->args[0]->value??null) instanceof \PhpParser\Node\Scalar\String_
        &&str_starts_with($call->args[0]->value->value,'/@var\s+')){$patterns[]=$call->args[0]->value->value;}
}
if(count($patterns)!==1){throw new RuntimeException('One current constant type boundary grammar required.');}
foreach(['ordinary nullable'=>'@var string|null\n','reversed nullable'=>'@var null|string */','malformed suffix'=>'@var string|nullX */',
    'unsupported extra union'=>'@var string|null|object */','another malformed suffix'=>'@var null|stringX */'] as $name=>$text){
    $text=str_replace('\\n',"\n",$text);$expected=in_array($name,['ordinary nullable','reversed nullable'],true);
    if((preg_match($patterns[0],$text)===1)!==$expected){throw new RuntimeException('Current constant boundary grammar failed: '.$name);}
    $checks['current constant tag boundary: '.$name]=true;
}
return $checks;
