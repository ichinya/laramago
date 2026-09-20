<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\LiteralValidationRegex;
use PhpParser\Node;

require __DIR__.'/../vendor/autoload.php';

$cases = [
    'scalar sequence' => [
        'required|regex:#a,b:c#iu|not_regex:~z+~',
        [
            ['rule' => 'regex', 'pattern' => '#a,b:c#iu', 'body' => 'a,b:c', 'delimiter' => '#', 'modifiers' => 'iu'],
            ['rule' => 'not_regex', 'pattern' => '~z+~', 'body' => 'z+', 'delimiter' => '~', 'modifiers' => ''],
        ],
    ],
    'array alternation' => [
        ['required', 'regex:/a|b/i', 'notregex:{x{2}y}m'],
        [
            ['rule' => 'regex', 'pattern' => '/a|b/i', 'body' => 'a|b', 'delimiter' => '/', 'modifiers' => 'i'],
            ['rule' => 'not_regex', 'pattern' => '{x{2}y}m', 'body' => 'x{2}y', 'delimiter' => '{', 'modifiers' => 'm'],
        ],
    ],
    'escaped delimiter' => [
        ['regex:#a\\#b#', 'regex:(a\\)b)i', 'regex:_a_'],
        [
            ['rule' => 'regex', 'pattern' => '#a\\#b#', 'body' => 'a\\#b', 'delimiter' => '#', 'modifiers' => ''],
            ['rule' => 'regex', 'pattern' => '(a\\)b)i', 'body' => 'a\\)b', 'delimiter' => '(', 'modifiers' => 'i'],
            ['rule' => 'regex', 'pattern' => '_a_', 'body' => 'a', 'delimiter' => '_', 'modifiers' => ''],
        ],
    ],
    'character class and paired delimiter' => [
        ['regex:#[a-z]#', 'regex:/[\\/]/', 'regex:{[a-z]{2}}', 'regex:<a<b>c>u'],
        [
            ['rule' => 'regex', 'pattern' => '#[a-z]#', 'body' => '[a-z]', 'delimiter' => '#', 'modifiers' => ''],
            ['rule' => 'regex', 'pattern' => '/[\\/]/', 'body' => '[\\/]', 'delimiter' => '/', 'modifiers' => ''],
            ['rule' => 'regex', 'pattern' => '{[a-z]{2}}', 'body' => '[a-z]{2}', 'delimiter' => '{', 'modifiers' => ''],
            ['rule' => 'regex', 'pattern' => '<a<b>c>u', 'body' => 'a<b>c', 'delimiter' => '<', 'modifiers' => 'u'],
        ],
    ],
    'leading pattern whitespace' => [
        ["ReGeX: \t#ok#i \n"],
        [['rule' => 'regex', 'pattern' => " \t#ok#i \n", 'body' => 'ok', 'delimiter' => '#', 'modifiers' => "i \n"]],
    ],
    'no regex' => ['required|string', []],
];

foreach ($cases as $label => [$literal, $expected]) {
    $actual = LiteralValidationRegex::fromLiteral($literal);
    if ($actual !== $expected) {
        throw new RuntimeException($label.': '.var_export($actual, true));
    }
}

$rejected = [
    'scalar alternation' => 'regex:/a|b/',
    'scalar pipe delimiter' => 'regex:|a|',
    'delimiter in class still closes' => ['regex:/[/]/'],
    'paired delimiter in class still closes' => ['regex:{[}]}'],
    'unknown modifier' => ['regex:#a#z'],
    'missing ending delimiter' => ['regex:#a\\#'],
    'missing parameter' => ['regex'],
    'untrimmed raw name' => [' regex:#a,b#'],
    'trailing raw name space' => ['regex :#a,b#'],
    'hyphenated name' => ['not-regex:#a,b#'],
    'spaced name' => ['not regex:#a,b#'],
    'repeated underscore name' => ['not__regex:#a,b#'],
    'repeated hyphen name' => ['not--regex:#a,b#'],
];
foreach ($rejected as $label => $literal) {
    if (LiteralValidationRegex::fromLiteral($literal) !== null) {
        throw new RuntimeException($label.' should remain unknown');
    }
}

$array = new Node\Expr\Array_([
    new Node\Expr\ArrayItem(new Node\Scalar\String_('required')),
    new Node\Expr\ArrayItem(new Node\Scalar\String_('regex:/a|b/i')),
]);
if (LiteralValidationRegex::from($array) !== [$cases['array alternation'][1][0]]) {
    throw new RuntimeException('Literal array source extraction failed');
}
if (LiteralValidationRegex::from(new Node\Scalar\String_('regex:/a|b/')) !== null) {
    throw new RuntimeException('Scalar source extraction lost Laravel pipe splitting');
}
if (
    LiteralValidationRegex::from(new Node\Expr\Array_([
        new Node\Expr\ArrayItem(new Node\Expr\Variable('dynamic')),
    ])) !== null
) {
    throw new RuntimeException('Dynamic source extraction should remain unknown');
}

echo 'literal validation regex: ok'.PHP_EOL;
