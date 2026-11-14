<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\{CodebaseScanSyntax,ConditionalArrayGuards,SourceNullFacts,ValidatedSortedArrayProofs};

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$checks=0;
$same=static fn(array $left,array $right):bool=>serialize($left)===serialize($right);
$check=static function(bool $passed,string $label)use(&$checks):void {
    if(!$passed) { throw new RuntimeException($label); }++$checks;
};
$policies=[
    'null-flow'=>[static fn(string $source):array=>(new SourceNullFacts)->proofs($source),[
        'fixtures/analysis/nullable-flow-cases.php.stub',
    ]],
    'sorted-array'=>[static fn(string $source):array=>(new ValidatedSortedArrayProofs)->inspect($source),[
        'fixtures/analysis/validated-array-contracts/fixture-cases.php.stub',
        'fixtures/analysis/validated-array-contracts/top-positive.php.stub',
    ]],
    'script-array'=>[ConditionalArrayGuards::lexicalScriptProofs(...),[
        'fixtures/analysis/validated-array-contracts/top-positive.php.stub',
        'fixtures/analysis/validated-array-contracts/top-reference-alias.php.stub',
    ]],
];
try {
    foreach($policies as $policy=>[$extract,$fixtures]) {
        foreach($fixtures as $fixture) {
            $source=file_get_contents(__DIR__.'/'.$fixture);CodebaseScanSyntax::clear();$cold=$extract($source);
            $queries=(new ReflectionProperty(CodebaseScanSyntax::class,'queries'))->getValue();
            $check(count($queries)===1,$policy.' retains one compact exact-source extraction');
            $check($same($extract($source),$cold),$policy.' cold and warm results agree');
            // Retain only the compact query. A warm lookup must not reconstruct a tree.
            (new ReflectionProperty(CodebaseScanSyntax::class,'trees'))->setValue(null,[]);
            (new ReflectionProperty(CodebaseScanSyntax::class,'bytes'))->setValue(null,0);
            $check($same($extract($source),$cold)&&(new ReflectionProperty(CodebaseScanSyntax::class,'trees'))->getValue()===[],$policy.' repeated extraction avoids parsing and tree cloning');
            $changed=$extract($source);$changed['consumerMutation']=['nested'=>true];
            $mutate=static function(mixed &$value)use(&$mutate):void {
                if($value instanceof \PhpParser\Node) { $value->setAttribute('startFilePos',-99); }
                elseif(is_array($value)) { foreach($value as &$item) { $mutate($item); }unset($item); }
            };
            $mutate($changed);$mutate=null;
            $check($same($extract($source),$cold),$policy.' consumer mutations never cross calls');
            $edited=$source."\n// changed source\n";$next=$extract($edited);
            $check(count((new ReflectionProperty(CodebaseScanSyntax::class,'queries'))->getValue())===2,$policy.' changed bytes extract independently');
            CodebaseScanSyntax::clear();$check($same($extract($edited),$next),$policy.' changed-source cached and fresh facts agree');
            $check($same($extract($source),$cold),$policy.' restoration recovers exact original facts');
        }
        foreach([1,2] as $attempt) {
            try { $result=$extract('<?php broken {');$check($result===[],$policy.' malformed source refuses'); }
            catch(\PhpParser\Error) { $check($policy==='sorted-array','only the original throwing policy propagates syntax errors'); }
        }
    }
    $check((new ValidatedSortedArrayProofs)->inspect('<?php '.str_repeat(' ',1024*1024))===['refusal'=>'source-size'],'sorted-array size limit is unchanged');
    $check(ConditionalArrayGuards::lexicalScriptProofs('<?php '.str_repeat(' ',1024*1024))===[],'script-array size limit is unchanged');
    echo 'Issue source cache passed: '.$checks." controls.\n";
} finally { CodebaseScanSyntax::clear(); }
