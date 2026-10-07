<?php
declare(strict_types=1);

// Source grammar only; no native contexts or analyzer services are constructed.
$package=$argv[1];$candidate=$argv[2];$root=$argv[3];
require $package.'/vendor/autoload.php';
foreach(['PossibleTupleConstantDomain'] as $name) { require $candidate.'/src/Analyzer/'.$name.'.php'; }
$original=file_get_contents($candidate.'/tests/fixtures/possible-tuples/source/positive.php.stub');
$cases=[
    'current finite string wildcard'=>[$original,true],
    'no selected annotation'=>[str_replace('/** @param self::LABEL_* $input */','/** No selected annotation. */',$original),false],
    'duplicate annotation'=>[str_replace('@param self::LABEL_* $input */','@param self::LABEL_* $input'."\n * @param self::LABEL_* \$input */",$original),false],
    'stronger PHPStan annotation'=>[str_replace('@param self::LABEL_* $input */','@phpstan-param self::LABEL_* $input */',$original),false],
    'stronger Psalm annotation'=>[str_replace('@param self::LABEL_* $input */','@psalm-param self::LABEL_* $input */',$original),false],
    'output annotation'=>[str_replace('@param self::LABEL_* $input */','@param-out self::LABEL_* $input */',$original),false],
    'nullable caller bound'=>[str_replace('constantText(string $input','constantText(?string $input',$original),false],
    'reference caller bound'=>[str_replace('constantText(string $input','constantText(string &$input',$original),false],
    'mixed caller bound'=>[str_replace('constantText(string $input','constantText(mixed $input',$original),false],
    'nonstring selected constant'=>[str_replace("LABEL_SECOND='beta'","LABEL_SECOND=7",$original),false],
    'nonliteral selected constant'=>[str_replace("LABEL_SECOND='beta'","LABEL_SECOND=self::LABEL_FIRST",$original),false],
    'typed selected constant'=>[str_replace('public const LABEL_FIRST','public const string LABEL_FIRST',$original),false],
    'documented selected constant'=>[str_replace('public const LABEL_FIRST','/** @var string */ public const LABEL_FIRST',$original),false],
    'private selected constant'=>[str_replace('public const LABEL_FIRST','private const LABEL_FIRST',$original),false],
    'final selected constant'=>[str_replace('public const LABEL_FIRST','final public const LABEL_FIRST',$original),false],
    'inherited constant scope'=>[str_replace('class TupleExamples {','class TupleExamples extends ParentConstants {',$original),false],
    'trait constant scope'=>[str_replace('class TupleExamples {','class TupleExamples { use ForeignConstants;',$original),false],
    'missing selected prefix'=>[str_replace('@param self::LABEL_* $input */','@param self::ABSENT_* $input */',$original),false],
    'unbounded wildcard'=>[str_replace('@param self::LABEL_* $input */','@param self::* $input */',$original),false],
    'malformed selector'=>[str_replace('@param self::LABEL_* $input */','@param self::LABEL_** $input */',$original),false],
];
$checks=[];
foreach($cases as $label=>[$contents,$wanted]) {
    $file=$root.'/constant-source.php';file_put_contents($file,$contents);
    $source=new \Ichinya\Laramago\Analyzer\GuardedStringCastContracts($root);$read=$source->read($file,$contents);
    $methods=$read===null?[]:(new \PhpParser\NodeFinder)->findInstanceOf($read['nodes'],\PhpParser\Node\Stmt\ClassMethod::class);
    $selected=array_values(array_filter($methods,static fn(\PhpParser\Node\Stmt\ClassMethod $method):bool=>$method->name->name==='constantText'));
    $plan=count($selected)!==1?null:\Ichinya\Laramago\Analyzer\PossibleTupleConstantDomain::plan($source,$read,$selected[0],$selected[0]->params[0]);
    if(($plan!==null)!==$wanted) { throw new RuntimeException('Constant source grammar failed: '.$label); }
    if($plan!==null) {
        if(array_column($plan['constants'],'value')!==['alpha','beta']||$plan['typeToken']!=='self::LABEL_*'
            ||substr($contents,$plan['typeSpan'][0],$plan['typeSpan'][1]-$plan['typeSpan'][0])!==$plan['typeToken']) {
            throw new RuntimeException('Exact literal values or annotation span changed.');
        }
    }
    $checks[$label]=true;
}
echo json_encode(['sourceOnly'=>true,'checks'=>$checks,'genuineNativeAcceptanceClaimed'=>false,
    'constructedIssueContexts'=>0,'sdkRequests'=>0,'applicationBodiesExecuted'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
