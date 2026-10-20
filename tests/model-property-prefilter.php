<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\ModelPropertyArgumentFilter;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use PhpParser\{Node,NodeFinder};
require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$cases=[
    ['function run(Model $model) { selected($model->field); }',true],
    ['function run(Model $model) { selected(42, ($model->field)); }',true],
    ['function run(Model $model) { selected($model /* comment */ -> field); }',true],
    ['class Example { function run(\Vendor\Model $model) { $this->selected($model->field); } }',true],
    ['function run(Model $model) { $lambda=function() use($model) { selected($model->field); }; }',false],
    ['function run($model) { selected($model->field); }',false],
    ['function run(?Model $model) { selected($model->field); }',false],
    ['function run(Model &$model) { selected($model->field); }',false],
    ['function run(Model ...$model) { selected($model->field); }',false],
    ['function run(Model $model) { selected($model->field, 42); }',false],
    ['function run(Model $model) { selected(value: $model->field); }',false],
    ['class Example { function run() { selected($this->field); } }',false],
    ['function run(Model $model) { selected($model->field->nested); }',false],
    ['selected($model->field);',false],
];
$checks=0;$finder=new NodeFinder;
try {
    foreach($cases as [$body,$expected]) {
        foreach(["\n","\r\n"] as $newline) {
            $source='<?php namespace Example; use Vendor\Model;'.$newline.$body;$nodes=CodebaseScanSyntax::parse($source);
            $calls=$finder->findInstanceOf($nodes,Node\Expr\CallLike::class);
            foreach($calls as $call) {
                if($call->name->toString()!=='selected'){continue;}$arg=$call->args[0]->value;
                if(count($call->args)===2&&$call->args[1]->value instanceof Node\Expr\PropertyFetch){$arg=$call->args[1]->value;}
                foreach([1,2] as $pass){
                    if(ModelPropertyArgumentFilter::mayHaveSourceContract($source,$arg->getStartFilePos(),$arg->getEndFilePos()+1)!==$expected){throw new RuntimeException('Unexpected necessary model formal syntax: '.$body);}
                    ++$checks;
                }
            }
        }
    }
    if(!ModelPropertyArgumentFilter::mayHaveSourceContract('<?php broken {',0,5)||!ModelPropertyArgumentFilter::mayHaveSourceContract('<?php selected();',-1,1)){throw new RuntimeException('Unknown syntax or span must retain the fallback proof.');}
    echo 'Model property prefilter passed: '.($checks+2)." controls.\n";
} finally { CodebaseScanSyntax::clear(); }
