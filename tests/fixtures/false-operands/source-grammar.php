<?php

declare(strict_types=1);

$class = \Ichinya\Laramago\Analyzer\StaticAnalysis\FalseOperandPolicy::class;
$checks = [];
foreach (['false|int', 'false|non-negative-int', 'false|positive-int', 'false|int(0)', 'false|int<1750595956, max>', 'int<min, -1>|false'] as $domain) {
    if (! $class::domain($domain, 'comparison')) { throw new RuntimeException('Valid integer domain rejected: '.$domain); }
    $checks['integer domain '.$domain] = true;
}
foreach (['false|non-empty-string', 'false|string', 'numeric-string|false'] as $domain) {
    if (! $class::domain($domain, 'concat')) { throw new RuntimeException('Valid string domain rejected: '.$domain); }
    $checks['concat domain '.$domain] = true;
}
foreach (['mixed', 'false', 'false|null', 'false|array', 'false|object', 'false|resource', 'false|bool', 'false|float',
    'false|int|mixed', 'false|false|int', 'false|int<2, 1>', 'false|int<max, min>', 'false|int<0, 99999999999999999999999>'] as $domain) {
    foreach (['comparison', 'concat'] as $kind) {
        if ($class::domain($domain, $kind)) { throw new RuntimeException('Invalid domain accepted: '.$kind.' '.$domain); }
        $checks[$kind.' negative '.$domain] = true;
    }
}
$cases = [
    'integer literal' => ['<?php function x(int|false $v): bool { return $v > 0; }', 'comparison|>|left|', '$v', true],
    'native integer parameter' => ['<?php function x(int|false $v,int $p): bool { return $v < $p; }', 'comparison|<|left|', '$v', true],
    'right comparison' => ['<?php function x(int|false $v): bool { return 0 < $v; }', 'comparison|<|right|', '$v', true],
    'grouping exact' => ['<?php function x(int|false $v): bool { return (($v)) > 0; }', 'comparison|>|left|', '(($v))', true],
    'middle concat' => ['<?php function x(string|false $v): string { return "a".$v."b"; }', 'concat|.|middle|', '$v', true],
    'right concat' => ['<?php function x(string|false $v): string { return "a".$v; }', 'concat|.|right|', '$v', true],
    'concat unknown peer' => ['<?php function x(string|false $v,mixed $m): string { return $m.$v; }', 'concat|.|right|', '$v', false],
    'string comparison peer' => ['<?php function x(int|false $v): bool { return $v > "text"; }', 'comparison|>|left|', '$v', false],
    'nullable int parameter' => ['<?php function x(int|false $v,?int $p): bool { return $v < $p; }', 'comparison|<|left|', '$v', false],
    'documented int parameter' => ['<?php /** @param int $p */ function x(int|false $v,$p): bool { return $v < $p; }', 'comparison|<|left|', '$v', false],
    'mutated int parameter' => ['<?php function x(int|false $v,int $p): bool { $p="x"; return $v < $p; }', 'comparison|<|left|', '$v', false],
    'reference parameter' => ['<?php function x(int|false $v,int &$p): bool { return $v < $p; }', 'comparison|<|left|', '$v', false],
    'target inside repeated loop' => ['<?php function x(int|false $v,int $p): bool { while(true){ if($v < $p){ return true; } $p="text"; } }', 'comparison|<|left|', '$v', false],
    'dynamic write before peer' => ['<?php function x(int|false $v,int $p): bool { extract([]); return $v < $p; }', 'comparison|<|left|', '$v', false],
    'later read of peer' => ['<?php function x(int|false $v,int $p): bool { if($v < $p){ return true; } return 0 < $p; }', 'comparison|<|left|', '$v', true],
    'nested diagnostic boundary' => ['<?php function x(mixed $v): bool { return strlen($v) > 0; }', 'comparison|>|left|', '$v', false],
    'arithmetic' => ['<?php function x(int|false $v): int { return $v + 1; }', 'comparison|>|left|', '$v', false],
    'division' => ['<?php function x(int|false $v): int|float { return 1 / $v; }', 'comparison|>|right|', '$v', false],
    'malformed' => ['<?php function x( { $v > 0;', 'comparison|>|left|', '$v', false],
];
foreach ($cases as $label => [$bytes, $prefix, $needle, $expected]) {
    $start = strrpos($bytes, $needle); if ($start === false) { throw new RuntimeException('Missing grammar needle.'); }
    $sites = $class::sites($bytes); $key = $prefix.$start.':'.($start + strlen($needle));
    if (isset($sites[$key]) !== $expected) { throw new RuntimeException('Wrong grammar outcome: '.$label); }
    $checks['source grammar '.$label] = true;
}
echo "PASS: ".count($checks)." false operand source/domain controls; no native DTO positive constructed.\n";
