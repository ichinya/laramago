<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\SourceArgumentDeclarationContracts;
use PhpParser\Node;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$reject=new ReflectionMethod(SourceArgumentDeclarationContracts::class,'rejectsArgumentShape');
$parser=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
$checks=0;
$check=static function(bool $passed,string $label) use (&$checks):void {
    if (!$passed) { throw new RuntimeException($label); }
    ++$checks;
};
// Use the actual AST as the oracle, including unusual but legal spellings.
$expressions=['$value','$this','$_name','$name123','$переменная','($value)','(($value))',
    '/* leading */ $value',"# leading\n\$value",'${value}',"\${'value'}",'$$value',
    '$object->field','$object -> field','$object /* between */ -> field','($object)->field',
    '(($object))->field','$объект->поле','$object->class','$object->{field}',"\$object->{'field'}",
    '$object?->field','$object->nested->field','$values[0]','$callback()','call()','Type::call()',
    'new Type','[1]','42','"literal"','null','-$value','@$value','$value + 1'];
foreach ($expressions as $expression) {
    $nodes=$parser->parse('<?php selected('.$expression.');');
    $value=$nodes[0]->expr->args[0]->value;
    $possible=['variable'=>$value instanceof Node\Expr\Variable && is_string($value->name),
        'property'=>$value instanceof Node\Expr\PropertyFetch && $value->var instanceof Node\Expr\Variable
            && is_string($value->var->name) && $value->name instanceof Node\Identifier];
    foreach ($possible as $shape=>$accepted) {
        // Exercise the same exact byte span used by the production receiving gate.
        $source='<?php selected('.$expression.');';
        $span=substr($source,$value->getStartFilePos(),$value->getEndFilePos()-$value->getStartFilePos()+1);
        $check(!$accepted || !$reject->invoke(null,$span,$shape),'A valid AST shape was rejected: '.$expression.' / '.$shape);
    }
}
foreach (['$object->field','$values[0]','$callback()','call()','[1]','42'] as $expression) {
    $check($reject->invoke(null,$expression,'variable'),'A definite non-variable should skip the expensive proof.');
}
$check($reject->invoke(null,'$value','property'),'A simple variable cannot be a model property fetch.');
$check(!$reject->invoke(null,'($value)','property'),'Unknown parenthesized syntax must retain the full proof path.');
$check(!$reject->invoke(null,'anything','unrecognized'),'Unknown policies cannot reject an argument.');
echo 'Source argument syntax passed: '.$checks." controls.\n";
