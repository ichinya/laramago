<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidationFieldReferences;
use PhpParser\Node;
use PhpParser\ParserFactory;

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$parse = static function (string $text) use ($parser): Node\Expr {
    $statements = $parser->parse('<?php '.$text.';');
    $expression = $statements[0] ?? null;
    if (! $expression instanceof Node\Stmt\Expression) {
        throw new RuntimeException('Expected an expression fixture');
    }

    return $expression->expr;
};

$rules = $parse(<<<'PHP'
    [
        'password' => 'confirmed|same:account.password|different:old_password,backup_password',
        'users.*.email' => ['required_if:users.*.type,"staff,contractor"', 'required_with:users.*.name,users.*.phone'],
        'literal\.key' => 'exclude_if:other\.key,yes|prohibited',
        'email' => 'same:"address,primary"',
        'ratio' => 'min:3|in:yes,no|regex:/a,b/|after:tomorrow',
        'choice' => 'in_array:other.*|prohibits:archive,backup',
    ]
    PHP);
$actual = array_map(
    static fn (array $hint): array => [$hint['field'], $hint['rule'], $hint['reference'], $hint['implicit']],
    ValidationFieldReferences::from($rules),
);
$expected = [
    ['password',      'confirmed',     'password_confirmation', true],
    ['password',      'same',          'account.password',      false],
    ['password',      'different',     'old_password',          false],
    ['password',      'different',     'backup_password',       false],
    ['users.*.email', 'required_if',   'users.*.type',          false],
    ['users.*.email', 'required_with', 'users.*.name',          false],
    ['users.*.email', 'required_with', 'users.*.phone',         false],
    ['literal\.key',  'exclude_if',    'other\.key',            false],
    ['email',         'same',          'address,primary',       false],
    ['choice',        'in_array',      'other.*',               false],
    ['choice',        'prohibits',     'archive',               false],
    ['choice',        'prohibits',     'backup',                false],
];
if ($actual !== $expected) {
    throw new RuntimeException('Cross-field references: '.var_export($actual, true));
}
foreach (ValidationFieldReferences::from($rules) as $hint) {
    if (! $hint['source'] instanceof Node\Scalar\String_) {
        throw new RuntimeException('Reference lost its source literal');
    }
}

$cases = [
    'numeric field keys preserve their names' => [
        $parse("['0' => 'confirmed|same:other']"),
        [
            ['0', 'confirmed', '0_confirmation', true],
            ['0', 'same',      'other',          false],
        ],
    ],
    'comparison values are not fields' => [
        $parse("['x' => 'required_if:state,open,closed|prohibited_if:mode,strict']"),
        [
            ['x', 'required_if',   'state', false],
            ['x', 'prohibited_if', 'mode',  false],
        ],
    ],
    'one-field exclusion rule' => [
        $parse("['x' => 'exclude_with:one,two|exclude_without:three,four']"),
        [
            ['x', 'exclude_with',    'one',   false],
            ['x', 'exclude_without', 'three', false],
        ],
    ],
    'effective duplicate declaration' => [
        $parse("['x' => 'same:old', 'x' => 'same:new']"),
        [
            ['x', 'same', 'new', false],
        ],
    ],
];
foreach ($cases as $label => [$rules, $expected]) {
    $actual = array_map(
        static fn (array $hint): array => [$hint['field'], $hint['rule'], $hint['reference'], $hint['implicit']],
        ValidationFieldReferences::from($rules),
    );
    if ($actual !== $expected) {
        throw new RuntimeException($label.': '.var_export($actual, true));
    }
}

if (ValidationFieldReferences::from($parse("['x' => 'same:old', ...\$dynamic]")) !== []) {
    throw new RuntimeException('Unpacked rules could override a literal key');
}
if (ValidationFieldReferences::from($parse("['x' => 'same:old', \$key => 'required']")) !== []) {
    throw new RuntimeException('Dynamic rule keys could override a literal key');
}
if (ValidationFieldReferences::fromValue('x', $parse("['same:old', ...\$dynamic]")) !== []) {
    throw new RuntimeException('Unpacked rule lists remain unknown');
}
$single = ValidationFieldReferences::fromValue('users.*.password', $parse("'confirmed:users.*.secret'"));
if (count($single) !== 1 || $single[0]['reference'] !== 'users.*.secret' || $single[0]['implicit']) {
    throw new RuntimeException('Explicit confirmed reference from a single-field value was lost');
}

echo 'validation field references: ok'.PHP_EOL;
