<?php
declare(strict_types=1);
// Package/parser declarations only. No SDK hook, context, issue or application body is fabricated or invoked.
$package=dirname(__DIR__,3);require $package.'/vendor/autoload.php';
$method=new ReflectionMethod(\Ichinya\Laramago\Analyzer\ModelPropertyArgumentFilter::class,'ordinaryEarlierLocal');
$parser=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
$cases=[
    'documented local input'=>['$projects','$projects',true],
    'typed local input'=>['\\Illuminate\\Support\\Collection $projects','$projects',true],
    'independent wrong earlier type is not narrowed'=>['string $projects','$projects',true],
    'effectful earlier call'=>['$projects','effect($projects)',false],
    'earlier property getter'=>['$projects','$projects->items',false],
    'earlier rebinding expression'=>['$projects','($projects=null)',false],
    'reference caller input'=>['&$projects','$projects',false],
    'variadic caller input'=>['...$projects','$projects',false],
    'dynamic local name'=>['$key','$$key',false],
    'unknown local'=>['$projects','$other',false],
    'ambient superglobal'=>['$projects','$_GET',false],
];
$checks=[];
foreach($cases as $name=>[$input,$expression,$expected]){
    $nodes=$parser->parse('<?php function read('.$input.'){return '.$expression.';}');
    $scope=$nodes[0];$actual=$method->invoke(null,$scope,$scope->stmts[0]->expr);
    $checks[$name]=$actual===$expected;
}
if(in_array(false,$checks,true)){throw new RuntimeException('An invented ordinary-read grammar control failed.');}
return $checks;
