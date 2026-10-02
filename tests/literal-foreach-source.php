<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\LiteralForeachSelection;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$base = <<<'PHP'
$chosen = null;
foreach ([12.5, -3.25, 8.0] as $position => $number) {
    $record = buildRecord();
    if ($number === -3.25) {
        $chosen = $record;
    }
}
return $chosen;
PHP;
$copy = str_replace('buildRecord()', 'Record::factory()->create(["amount" => $number, "label" => sprintf("item %02d", $position + 1)])', $base);
$cases = [
    'direct producer' => [$base, true],
    'fresh copied attributes and builtin candidate' => [$copy, true],
    'earlier unrelated arrow and later calls' => ['prepare(fn () => new \\stdClass());'.$copy, true],
    'later unrelated call' => [str_replace('return $chosen;', 'finish(["value" => externalValue()]); return $chosen;', $copy), true],
    'reversed strict operands' => [str_replace('$number === -3.25', '-3.25 === $number', $base), true],
    'unkeyed iteration' => [str_replace('$position => ', '', $base), true],
    'positive sign selector' => [str_replace(['-3.25', '$number === +3.25'], ['+3.25', '$number === 3.25'], $base), true],
    'string selection' => [str_replace(['[12.5, -3.25, 8.0]', '$number === -3.25'], ['["first", "pick", "last"]', '$number === "pick"'], $base), true],
    'boolean selection' => [str_replace(['[12.5, -3.25, 8.0]', '$number === -3.25'], ['[false, true]', '$number === true'], $base), true],
    'nested copied values' => [str_replace('buildRecord()', 'buildRecord(["details" => ["amount" => $number]])', $base), true],
    'empty list' => [str_replace('[12.5, -3.25, 8.0]', '[]', $base), false],
    'selector absent' => [str_replace('[12.5, -3.25, 8.0]', '[12.5, 8.0]', $base), false],
    'integer float mismatch' => [str_replace(['[12.5, -3.25, 8.0]', '$number === -3.25'], ['[1, 2, 3]', '$number === 2.0'], $base), false],
    'duplicate explicit key' => [str_replace('[12.5, -3.25, 8.0]', '["same" => -3.25, "same" => 8.0]', $base), false],
    'explicit sequential keys' => [str_replace('[12.5, -3.25, 8.0]', '[0 => -3.25]', $base), false],
    'unpacked list' => [str_replace('[12.5, -3.25, 8.0]', '[...[12.5, -3.25, 8.0]]', $base), false],
    'runtime list expression' => [str_replace('[12.5, -3.25, 8.0]', 'values()', $base), false],
    'nonfinite literal' => [str_replace('[12.5, -3.25, 8.0]', '[1e999, -3.25]', $base), false],
    'arithmetic list item' => [str_replace('[12.5, -3.25, 8.0]', '[12.5, -3.0 - 0.25, 8.0]', $base), false],
    'null list item' => [str_replace('[12.5, -3.25, 8.0]', '[null, -3.25]', $base), false],
    'loose comparison' => [str_replace('===', '==', $base), false],
    'different loop local comparison' => [str_replace('$number ===', '$other ===', $base), false],
    'break before selection' => [str_replace('$record =', 'break; $record =', $base), false],
    'continue before selection' => [str_replace('$record =', 'continue; $record =', $base), false],
    'else reset' => [str_replace('$chosen = $record;', '$chosen = $record;', str_replace("    }\n}", "    } else { \$chosen = null; }\n}", $base)), false],
    'extra loop statement' => [str_replace('$record =', 'touch(); $record =', $base), false],
    'caught producer exceptions' => ['try {'.str_replace('return $chosen;', '', $base).'} catch (\\Throwable) {} return $chosen;', false],
    'conditional loop' => [str_replace('foreach (', 'if (condition()) { foreach (', str_replace('return $chosen;', '} return $chosen;', $base)), false],
    'nested loop' => [str_replace('foreach (', 'foreach ([1] as $outer) { foreach (', str_replace('return $chosen;', '} return $chosen;', $base)), false],
    'reference iteration' => [str_replace('=> $number', '=> &$number', $base), false],
    'sentinel alias before reset' => [str_replace('$chosen = null;', '$alias =& $chosen; $chosen = null;', $base), false],
    'sentinel reset after loop' => [str_replace('return $chosen;', '$chosen = null; return $chosen;', $base), false],
    'later reference capture' => [str_replace('return $chosen;', '$reset = function () use (&$chosen) { $chosen = null; }; return $chosen;', $base), false],
    'earlier value capture' => ['$callback = fn () => $chosen;'.$base, false],
    'earlier producer local' => ['$record = new \\stdClass();'.$base, false],
    'earlier value local' => ['$number = new \\stdClass();'.$base, false],
    'earlier key local' => ['$position = new \\stdClass();'.$base, false],
    'dynamic variable' => ['$$name = null;'.$base, false],
    'dynamic function invocation' => ['$function();'.$base, false],
    'extract before fresh locals' => ['extract($attributes);'.$base, false],
    'parse_str after selection' => [str_replace('return $chosen;', 'parse_str("chosen=", $chosen); return $chosen;', $base), false],
    'eval after selection' => [str_replace('return $chosen;', 'eval($code); return $chosen;', $base), false],
    'include after selection' => [str_replace('return $chosen;', 'include $file; return $chosen;', $base), false],
    'goto around selection' => ['goto done;'.str_replace('return $chosen;', 'done: return $chosen;', $base), false],
    'direct tracked producer argument' => [str_replace('buildRecord()', 'changeNumber($number)', $base), false],
    'direct tracked key argument' => [str_replace('buildRecord()', 'buildRecord($position)', $base), false],
    'nested by-reference call in copied array' => [str_replace('buildRecord()', 'buildRecord(["amount" => changeNumber($number)])', $base), false],
    'captured tracked array value' => [str_replace('buildRecord()', 'buildRecord(["callback" => fn () => $number])', $base), false],
    'tracked copied array key' => [str_replace('buildRecord()', 'buildRecord([$number => "amount"])', $base), false],
    'reference copied array value' => [str_replace('buildRecord()', 'buildRecord(["amount" => &$number])', $base), false],
    'tracked array spread' => [str_replace('buildRecord()', 'buildRecord([...$number])', $base), false],
    'mutation within copied scalar' => [str_replace('buildRecord()', 'buildRecord(["amount" => $number++])', $base), false],
    'mutation within sprintf' => [str_replace('buildRecord()', 'buildRecord(["label" => sprintf("%d", ++$position)])', $base), false],
    'producer reads sentinel' => [str_replace('buildRecord()', 'buildRecord(["value" => $chosen])', $base), false],
    'producer reads old candidate' => [str_replace('buildRecord()', 'buildRecord(["value" => $record])', $base), false],
    'tracked locals overlap' => [str_replace('$record', '$number', $base), false],
    'special sentinel' => [str_replace('$chosen', '$GLOBALS', $base), false],
    'implicit HTTP header sentinel' => [str_replace('$chosen', '$http_response_header', $base), false],
    'implicit HTTP header candidate' => [str_replace('$record', '$http_response_header', $base), false],
    'implicit HTTP header value' => [str_replace('$number', '$http_response_header', $base), false],
    'implicit HTTP header key' => [str_replace('$position', '$http_response_header', $base), false],
    'parameter sentinel' => [$base, false, '$chosen'],
    'reference parameter' => [$base, false, '&$unrelated'],
    'reference return' => [$base, false, '', '&'],
    'statement after direct return' => [$base.'finish();', false],
    'bounded inline list' => [str_replace('[12.5, -3.25, 8.0]', '['.implode(',', array_fill(0, 129, '-3.25')).']', $base), false],
];
foreach ($cases as $label => [$body, $expected]) {
    $parameters = $cases[$label][2] ?? '';
    $reference = $cases[$label][3] ?? '';
    $nodes = (new NodeTraverser(new NameResolver))->traverse($parser->parse('<?php namespace SelectionFixture; function '.$reference.'choose('.$parameters.'): object {'.$body.'}') ?? []);
    $function = $nodes[0]->stmts[0];
    $returns = (new \PhpParser\NodeFinder)->findInstanceOf($function->stmts, Node\Stmt\Return_::class);
    $return = $returns[count($returns) - 1];
    $proof = LiteralForeachSelection::prove($function, $return);
    if (($proof !== null) !== $expected) {
        throw new RuntimeException('Unexpected literal foreach proof: '.$label);
    }
    if ($proof !== null && ($proof['sentinel'] !== 'chosen' || $proof['candidate'] !== 'record'
        || $proof['producer'] !== $proof['loop']->stmts[0]->expr->expr)) {
        throw new RuntimeException('Wrong source proof identity: '.$label);
    }
    if ($label === 'fresh copied attributes and builtin candidate' && count($proof['safeIntrinsics']) !== 1) {
        throw new RuntimeException('Missing tracked sprintf metadata check.');
    }
}

// The same proof applies to direct class methods, without running their bodies.
$methodNodes = $parser->parse('<?php class SelectionFixture { function choose(): object {'.$base.'} }');
$method = $methodNodes[0]->stmts[0];
if (LiteralForeachSelection::prove($method, $method->stmts[count($method->stmts) - 1]) === null) {
    throw new RuntimeException('Direct method selection was not proved.');
}

echo 'Literal foreach source proof: '.count($cases)." function controls and one method passed.\n";
