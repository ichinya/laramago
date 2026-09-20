<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\BuiltinValidationRuleCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidationRuleParameters;

$source = __DIR__.'/fixtures/analysis';
$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-rule-parameters-'.bin2hex(random_bytes(8));
$validation = $root.'/vendor/laravel/framework/src/Illuminate/Validation';
mkdir($validation.'/Concerns', 0777, true);
file_put_contents($root.'/composer.json', '{}');
foreach (['Validator.php', 'ValidationRuleParser.php', 'Concerns/ValidatesAttributes.php'] as $file) {
    copy($source.'/native-validation-'.basename($file).'.stub', $validation.'/'.$file);
}
$contracts = new ValidationRuleParameters(new BuiltinValidationRuleCatalog($root));
$cases = [
    'min' => ['required' => 1, 'provided' => 0],
    'max' => ['required' => 1, 'provided' => 0],
    'size' => ['required' => 1, 'provided' => 0],
    'between:1' => ['required' => 2, 'provided' => 1],
    'required_if:status' => ['required' => 2, 'provided' => 1],
    'required_if:status,ready' => null,
    'between:1,2' => null,
    'min:1' => null,
    'in' => null,
    'in:a,b' => null,
    'required' => null,
    'company_code:foo' => null,
    'regex' => null,
];
foreach ($cases as $rule => $expected) {
    $actual = $contracts->missing($rule);
    if ($actual !== $expected) {
        throw new RuntimeException($rule.': expected '.json_encode($expected).', got '.json_encode($actual));
    }
    echo 'PASS: '.$rule."\n";
}

// The catalog must stop claiming a contract when installed native source changes.
$trait = file_get_contents($validation.'/Concerns/ValidatesAttributes.php');
file_put_contents(
    $validation.'/Concerns/ValidatesAttributes.php',
    str_replace("\$this->requireParameterCount(1, \$parameters, 'min');", '// changed minimum', $trait),
);
$changed = new ValidationRuleParameters(new BuiltinValidationRuleCatalog($root));
if ($changed->missing('min') !== null || $changed->missing('max') === null) {
    throw new RuntimeException('Changed native method did not narrow the contract.');
}
echo "PASS: changed native method\n";
copy($source.'/native-validation-ValidatesAttributes.php.stub', $validation.'/Concerns/ValidatesAttributes.php');
$parserFile = $validation.'/ValidationRuleParser.php';
$parser = file_get_contents($parserFile);
$alteredParser = str_replace('return static::ruleIsRegex($rule) ?', 'return true ?', $parser);
if ($alteredParser === $parser) {
    throw new RuntimeException('Parser fixture did not contain expected CSV branch.');
}
file_put_contents($parserFile, $alteredParser);
$changed = new ValidationRuleParameters(new BuiltinValidationRuleCatalog($root));
if ($changed->missing('between:1') !== null) {
    throw new RuntimeException('Changed parser did not disable parameter contracts.');
}
echo "PASS: changed parser disables parameter contracts\n";
copy($source.'/native-validation-ValidationRuleParser.php.stub', $parserFile);
$alteredCounter = str_replace('count($parameters) < $count', 'count($parameters) <= $count', $trait);
if ($alteredCounter === $trait) {
    throw new RuntimeException('Counter fixture did not contain expected comparison.');
}
file_put_contents($validation.'/Concerns/ValidatesAttributes.php', $alteredCounter);
$changed = new ValidationRuleParameters(new BuiltinValidationRuleCatalog($root));
if ($changed->missing('min') !== null) {
    throw new RuntimeException('Changed counter did not disable parameter contracts.');
}
echo "PASS: changed native counter disables parameter contracts\n";
copy($source.'/native-validation-ValidatesAttributes.php.stub', $validation.'/Concerns/ValidatesAttributes.php');
$validatorFile = $validation.'/Validator.php';
$validator = file_get_contents($validatorFile);
$override = str_replace(
    '    protected $translator;',
    '    public function validateMin($attribute, $value, $parameters) { return true; }'
    ."\n"
    .'    protected $translator;',
    $validator,
);
if ($override === $validator) {
    throw new RuntimeException('Validator fixture did not contain expected property.');
}
file_put_contents($validatorFile, $override);
$changed = new ValidationRuleParameters(new BuiltinValidationRuleCatalog($root));
if ($changed->missing('min') !== null || $changed->missing('max') === null) {
    throw new RuntimeException('Native method override did not narrow contracts.');
}
echo "PASS: Validator method override disables that parameter contract\n";
