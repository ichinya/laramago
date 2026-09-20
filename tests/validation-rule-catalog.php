<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BuiltinValidationRuleCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeValidationMethodContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ResolvedClassIdentity;
use PhpParser\Node;
use PhpParser\NodeFinder;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago validation catalog '.bin2hex(random_bytes(8));
$validation = $root.'/vendor/laravel/framework/src/Illuminate/Validation';
mkdir($validation.'/Concerns', 0777, true);
mkdir($root.'/vendor/composer', 0777, true);
file_put_contents($root.'/composer.json', '{}');
file_put_contents($root.'/vendor/composer/installed.json', json_encode([
    'packages' => [['name' => 'laravel/framework', 'version' => '12.34.0']],
], JSON_THROW_ON_ERROR));

$parser = <<<'PHP'
    <?php
    namespace Illuminate\Validation;
    use Illuminate\Support\Str;
    class ValidationRuleParser {
        protected static function parseStringRule($rule) { return [Str::studly(trim($rule)), []]; }
        protected static function normalizeRule($rule) { return match ($rule) { 'Int' => 'Integer', 'Bool' => 'Boolean', default => $rule }; }
    }
    PHP;
$validator = <<<'PHP'
    <?php
    namespace Illuminate\Validation;
    class Validator {
        use Concerns\ValidatesAttributes;
        protected function validateAttribute($attribute, $rule) {
            [$rule, $parameters] = ValidationRuleParser::parse($rule);
            $method = "validate{$rule}";
            return $this->$method($attribute, $rule, $parameters, $this);
        }
    }
    PHP;
$trait = <<<'PHP'
    <?php
    namespace Illuminate\Validation\Concerns;
    trait ValidatesAttributes {
        public function validateInteger($attribute, $value, array $parameters = []) { return true; }
        public function validateRequired($attribute, $value) { return true; }
        public function validateNotRegex($attribute, $value, $parameters) { return true; }
        public function validateAlphaNum($attribute, $value, $parameters) { return true; }
        public function validateNullable() { return true; }
        public function validateBail() { return true; }
        public function validateJson($attribute, $value) { return true; }
        protected function validateCurrentPassword($attribute, $value, $parameters) { return true; }
        public function validateInternalHelper($context) { return true; }
        private function validatePrivateRule($attribute, $value) { return true; }
        public static function validateStaticRule($attribute, $value) { return true; }
    }
    PHP;
$write = static function (string $source) use ($validation, $parser, $validator): void {
    file_put_contents($validation.'/ValidationRuleParser.php', $parser);
    file_put_contents($validation.'/Validator.php', $validator);
    file_put_contents($validation.'/Concerns/ValidatesAttributes.php', $source);
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};

$write($trait);
$catalog = new BuiltinValidationRuleCatalog($root);
$assert(
    $catalog->rules() !== null
    && $catalog->methodFor('integer') === 'validateInteger'
    && $catalog->methodFor('int:strict') === 'validateInteger'
    && $catalog->methodFor('alpha_num') === 'validateAlphaNum'
    && $catalog->methodFor('alphaNum') === 'validateAlphaNum'
    && $catalog->methodFor('AlPhA_NuM') === 'validateAlphaNum'
    && $catalog->methodFor('JSON') === 'validateJson'
    && $catalog->methodFor('ReQuIrEd') === 'validateRequired'
    && $catalog->methodFor('notregex') === 'validateNotRegex'
    && $catalog->methodFor('nullable') === 'validateNullable'
    && $catalog->methodFor('current_password') === 'validateCurrentPassword',
    'native parser aliases and source-proven rule methods are exposed',
);
$assert(
    $catalog->methodFor('internal_helper') === null
    && $catalog->methodFor('private_rule') === null
    && $catalog->methodFor('static_rule') === null
    && $catalog->methodFor('custom_rule') === null,
    'helper-shaped, inaccessible and unknown methods never become positive built-ins',
);
$assert(
    $catalog->frameworkVersion() === '12.34.0' && $catalog->sourcePath() === $validation,
    'catalog identifies the installed source and Composer version',
);

$write(str_replace('public function validateJson($attribute, $value) { return true; }', '', $trait));
$older = new BuiltinValidationRuleCatalog($root);
$assert(
    $older->methodFor('json') === null && $catalog->methodFor('json') === 'validateJson',
    'installed source controls rule availability across versions',
);

file_put_contents($validation.'/Validator.php', str_replace(
    'return $this->$method(',
    'return $this->unknownDispatch(',
    $validator,
));
$unknown = new BuiltinValidationRuleCatalog($root);
$assert(
    $unknown->rules() === null && $unknown->methodFor('integer') === null,
    'changed validator dispatch leaves native catalog unknown',
);

$write($trait);
file_put_contents($validation.'/Validator.php', str_replace(
    'namespace Illuminate\\Validation;',
    'namespace Other\\Validation;',
    $validator,
));
$wrongNamespace = new BuiltinValidationRuleCatalog($root);
$assert($wrongNamespace->rules() === null, 'unrelated Validator namespace does not supply native methods');

$write($trait);
file_put_contents(
    $validation.'/Concerns/ValidatesAttributes.php',
    $trait."\n"
        .str_replace(
            'namespace Illuminate\\Validation\\Concerns;',
            'namespace Other\\Validation\\Concerns;',
            substr($trait, strlen('<?php')),
        ),
);
$duplicate = new BuiltinValidationRuleCatalog($root);
$assert($duplicate->rules() === null, 'ambiguous same-name declarations leave source unknown');

$write($trait);
file_put_contents($validation.'/Validator.php', str_replace(
    ['class Validator {', 'use Concerns\\ValidatesAttributes;', 'ValidationRuleParser::parse($rule)'],
    [
        'use Illuminate\\Validation\\Concerns\\ValidatesAttributes as NativeRules;'
            ."\n"
            .'    use Illuminate\\Validation\\ValidationRuleParser as RuleParser;'
            ."\n"
            .'    class Validator {',
        'use NativeRules;',
        'RuleParser::parse($rule)',
    ],
    $validator,
));
file_put_contents($validation.'/ValidationRuleParser.php', str_replace(
    ['class ValidationRuleParser {', 'Str::studly'],
    ['use Illuminate\\Support\\Str as NativeStr;'."\n".'    class ValidationRuleParser {', 'NativeStr::studly'],
    $parser,
));
$aliased = new BuiltinValidationRuleCatalog($root);
$assert(
    $aliased->methodFor('int') === 'validateInteger',
    'resolved trait, parser and support imports preserve native rule evidence',
);

$write($trait);
file_put_contents($validation.'/Validator.php', $validator."\n".'namespace Other\\Validation; class Validator {}');
$otherNamespace = new BuiltinValidationRuleCatalog($root);
$assert(
    $otherNamespace->rules() === null,
    'a same-short-name class in another namespace leaves native source ambiguous',
);

$write($trait);
file_put_contents($validation.'/Validator.php', $validator."\n".'namespace Other\\Validation; class OtherValidator {}');
$distinctNamespace = new BuiltinValidationRuleCatalog($root);
$assert(
    $distinctNamespace->methodFor('integer') === 'validateInteger',
    'a distinct class in another namespace does not change native identity',
);

$write($trait);
file_put_contents($validation.'/Validator.php', $validator."\n".'namespace Illuminate\\Validation; class Validator {}');
$sameIdentity = new BuiltinValidationRuleCatalog($root);
$assert($sameIdentity->rules() === null, 'duplicate resolved class identity leaves native catalog unknown');

$factoryFile = $root.'/Factory.php';
$factorySource = file_get_contents(__DIR__.'/fixtures/analysis/native-validation-Factory.php.stub');
if ($factorySource === false) {
    throw new RuntimeException('Missing pinned native validation fixture.');
}
file_put_contents($factoryFile, $factorySource);
$nativeMethods = new NativeValidationMethodContract($root);
$assert(
    $nativeMethods->matches('Illuminate\\Validation\\Factory', 'make', $factoryFile),
    'pinned native method contract identifies its sole resolved declaration',
);
file_put_contents($factoryFile, $factorySource."\n".'namespace Illuminate\\Validation; class Factory {}');
$ambiguousMethods = new NativeValidationMethodContract($root);
$assert(
    ! $ambiguousMethods->matches('Illuminate\\Validation\\Factory', 'make', $factoryFile),
    'native method contract rejects a duplicate class declaration',
);
unlink($factoryFile);

$namesFile = $root.'/Names.php';
file_put_contents($namesFile, <<<'PHP'
    <?php
    namespace Example;
    use Illuminate\Validation\Validator as NativeValidator;
    class Child extends NativeValidator {
        public function check() {
            NativeValidator::parse();
            self::parse();
            static::parse();
            parent::parse();
        }
    }
    if (true) { class Conditional {} }
    PHP);
$nodes = (new PhpSource($root))->read($namesFile);
$calls = (new NodeFinder)->findInstanceOf($nodes ?? [], Node\Expr\StaticCall::class);
$assert(
    count($calls) === 4
    && $calls[0]->class instanceof Node\Name
    && ResolvedClassIdentity::is($calls[0]->class, 'Illuminate\\Validation\\Validator')
    && ! ResolvedClassIdentity::is($calls[1]->class, 'Illuminate\\Validation\\Validator')
    && ! ResolvedClassIdentity::is($calls[2]->class, 'Illuminate\\Validation\\Validator')
    && ! ResolvedClassIdentity::is($calls[3]->class, 'Illuminate\\Validation\\Validator'),
    'resolved import matches while lexical self, static and parent stay contextual',
);
$assert(
    ResolvedClassIdentity::uniqueDeclaration($nodes ?? [], 'Example\\Conditional') === null,
    'conditional class syntax cannot prove a top-level native declaration',
);
unlink($namesFile);

$globalFile = $root.'/RootIdentity.php';
file_put_contents($globalFile, '<?php class RootIdentity {}');
$assert(
    ResolvedClassIdentity::uniqueDeclaration(
        (new PhpSource($root))->read($globalFile) ?? [],
        '\\RootIdentity',
    ) instanceof Node\Stmt\Class_,
    'leading slash resolves a unique global class declaration',
);
unlink($globalFile);

file_put_contents(
    $validation.'/Validator.php',
    '<?php namespace Illuminate\\Validation; use A\\Alias; use B\\Alias; class Validator {}',
);
$assert(
    (new BuiltinValidationRuleCatalog($root))->rules() === null,
    'conflicting imports defer instead of escaping the source reader',
);

foreach (['ValidationRuleParser.php', 'Validator.php', 'Concerns/ValidatesAttributes.php'] as $file) {
    unlink($validation.'/'.$file);
}
unlink($root.'/vendor/composer/installed.json');
unlink($root.'/composer.json');
rmdir($validation.'/Concerns');
rmdir($validation);
rmdir($root.'/vendor/laravel/framework/src/Illuminate');
rmdir($root.'/vendor/laravel/framework/src');
rmdir($root.'/vendor/laravel/framework');
rmdir($root.'/vendor/laravel');
rmdir($root.'/vendor/composer');
rmdir($root.'/vendor');
rmdir($root);
