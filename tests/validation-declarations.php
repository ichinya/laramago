<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidationRuleDeclarations;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;

/** @var array<string, array{string, string, string|null}> $cases */
$cases = [
    'factory make named rules' => [
        '$factory->make(rules: ["title" => "required|string"], data: []);',
        'Illuminate\\Validation\\Factory',
        '["title" => "required|string"]',
    ],
    'factory validate' => [
        '$factory->validate([], ["title" => "required|string"]);',
        'Illuminate\\Validation\\Factory',
        '["title" => "required|string"]',
    ],
    'factory contract make' => [
        '$factory->make([], ["title" => "required|string"]);',
        'Illuminate\\Contracts\\Validation\\Factory',
        '["title" => "required|string"]',
    ],
    'factory contract has no validate' => [
        '$factory->validate([], ["title" => "required|string"]);',
        'Illuminate\\Contracts\\Validation\\Factory',
        null,
    ],
    'facade make' => [
        'Validator::make([], ["title" => "required|string"]);',
        'Illuminate\\Support\\Facades\\Validator',
        '["title" => "required|string"]',
    ],
    'facade validate' => [
        'Validator::validate([], rules: ["title" => "required|string"]);',
        'Illuminate\\Support\\Facades\\Validator',
        '["title" => "required|string"]',
    ],
    'validator conditional rules' => [
        '$validator->sometimes("title", "required|string", fn () => true);',
        'Illuminate\\Validation\\Validator',
        '"required|string"',
    ],
    'validator replacement rules' => [
        '$validator->setRules(["title" => "required|string"]);',
        'Illuminate\\Validation\\Validator',
        '["title" => "required|string"]',
    ],
    'request macro rules' => [
        '$request->validate(["title" => "required|string"]);',
        'Illuminate\\Http\\Request',
        '["title" => "required|string"]',
    ],
    'request bag macro rules' => [
        '$request->validateWithBag("bag", rules: ["title" => "required|string"]);',
        'Illuminate\\Http\\Request',
        '["title" => "required|string"]',
    ],
    'request macro names are case sensitive' => [
        '$request->VALIDATE(["title" => "required|string"]);',
        'Illuminate\\Http\\Request',
        null,
    ],
    'request static call defers' => [
        'Request::validate(["title" => "required|string"]);',
        'Illuminate\\Http\\Request',
        null,
    ],
    'request bag macro names are case sensitive' => [
        '$request->ValidateWithBag("bag", ["title" => "required|string"]);',
        'Illuminate\\Http\\Request',
        null,
    ],
    'native factory methods are case insensitive' => [
        '$factory->MAKE([], ["title" => "required|string"]);',
        'Illuminate\\Validation\\Factory',
        '["title" => "required|string"]',
    ],
    'controller trait rules' => [
        '$controller->validate($request, ["title" => "required|string"]);',
        'Illuminate\\Foundation\\Validation\\ValidatesRequests',
        '["title" => "required|string"]',
    ],
    'controller trait bag rules' => [
        '$controller->validateWithBag("bag", $request, ["title" => "required|string"]);',
        'Illuminate\\Foundation\\Validation\\ValidatesRequests',
        '["title" => "required|string"]',
    ],
    'dynamic rule expression retained' => [
        '$factory->make([], $dynamicRules);',
        'Illuminate\\Validation\\Factory',
        '$dynamicRules',
    ],
    'unpacked argument defers' => [
        '$factory->make(...$arguments);',
        'Illuminate\\Validation\\Factory',
        null,
    ],
    'duplicate named rules defer' => [
        '$factory->make([], rules: ["title" => "required"], rules: ["title" => "string"]);',
        'Illuminate\\Validation\\Factory',
        null,
    ],
    'positional and named rules defer' => [
        '$factory->make([], ["title" => "required"], rules: ["title" => "string"]);',
        'Illuminate\\Validation\\Factory',
        null,
    ],
    'positional after named defers' => [
        '$factory->make(data: [], ["title" => "required"]);',
        'Illuminate\\Validation\\Factory',
        null,
    ],
    'first class callable defers' => [
        '$factory->make(...);',
        'Illuminate\\Validation\\Factory',
        null,
    ],
    'missing rules defers' => [
        '$factory->make([]);',
        'Illuminate\\Validation\\Factory',
        null,
    ],
    'unrelated validate defers' => [
        '$guard->validate(["title" => "required|string"]);',
        'Illuminate\\Auth\\SessionGuard',
        null,
    ],
    'unrelated make defers' => [
        '$view->make([], ["title" => "required|string"]);',
        'Illuminate\\View\\Factory',
        null,
    ],
    'factory static call defers' => [
        'Factory::make([], ["title" => "required|string"]);',
        'Illuminate\\Validation\\Factory',
        null,
    ],
    'facade instance call defers' => [
        '$facade->make([], ["title" => "required|string"]);',
        'Illuminate\\Support\\Facades\\Validator',
        null,
    ],
    'validator validate has no rules argument' => [
        '$validator->validate();',
        'Illuminate\\Validation\\Validator',
        null,
    ],
];

foreach ($cases as $label => [$source, $class, $expected]) {
    $nodes = $parser->parse('<?php '.$source) ?? [];
    $call = $finder->findFirst(
        $nodes,
        static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall,
    );
    if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) {
        throw new RuntimeException($label.': no parsed call');
    }
    $rules = ValidationRuleDeclarations::callRules($call, $class);
    $actual = $rules === null
        ? null
        : substr('<?php '.$source, $rules->getStartFilePos(), $rules->getEndFilePos() - $rules->getStartFilePos() + 1);
    if ($actual !== $expected) {
        throw new RuntimeException($label.': expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
    echo 'PASS: '.$label."\n";
}

$nodes = $parser->parse('<?php $factory->make([], ["title" => "required"]);') ?? [];
$call = $finder->findFirstInstanceOf($nodes, Node\Expr\MethodCall::class);
if (! $call instanceof Node\Expr\MethodCall) {
    throw new RuntimeException('By-reference case has no parsed call.');
}
$call->args[1]->byRef = true;
if (ValidationRuleDeclarations::callRules($call, 'Illuminate\\Validation\\Factory') !== null) {
    throw new RuntimeException('By-reference rules argument must defer.');
}
echo "PASS: by-reference rules defer\n";

foreach ([
    'literal FormRequest rules' => ['public function rules(): array { return ["title" => "required|string"]; }', true],
    'literal Livewire Form rules' => ['public function rules() { return ["title" => ["required", "string"]]; }', true],
    'dynamic rules defer' => ['public function rules(): array { return $this->ruleSet; }', false],
    'conditional rules defer' => [
        'public function rules(): array { if ($this->flag) { return []; } return ["title" => "required"]; }',
        false,
    ],
    'other method defers' => ['public function messages(): array { return ["title" => "required"]; }', false],
    'static rules defer' => ['public static function rules(): array { return ["title" => "required"]; }', false],
] as $label => [$methodSource, $expected]) {
    $nodes = $parser->parse('<?php class Sample { '.$methodSource.' }') ?? [];
    $method = $finder->findFirstInstanceOf($nodes, Node\Stmt\ClassMethod::class);
    if (! $method instanceof Node\Stmt\ClassMethod) {
        throw new RuntimeException($label.': no parsed method');
    }
    $actual = ValidationRuleDeclarations::rulesMethod($method) !== null;
    if ($actual !== $expected) {
        throw new RuntimeException($label.': expected '.($expected ? 'rules' : 'unknown'));
    }
    echo 'PASS: '.$label."\n";
}
