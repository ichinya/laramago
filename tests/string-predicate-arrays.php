<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
foreach ([
    'string' => static fn (string $value): bool => is_string($value),
    'string union' => static fn (string|bool $value): bool => is_string($value),
] as $name => $predicate) {
    if (array_filter([1], $predicate) !== [1]) { throw new RuntimeException('Native callback coercion control changed.'); }
    echo 'PASS: native '.$name." callback retains original integer\n";
}
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago string predicates '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{}');
$preamble = <<<'PHP'
    <?php
    /** @param list<string> $strings */
    function acceptStrings(array $strings): void {}
    /** @param array<string, string> $strings */
    function acceptStringMap(array $strings): void {}
    function acceptInt(int $value): void {}
    enum StringEnum: string { case One = 'one'; case Two = 'two'; }
    enum IntEnum: int { case One = 1; }
    PHP;
$cases = [
    'backed enum value column' => ['acceptStrings(array_column(StringEnum::cases(), "value"));', []],
    'named backed enum column' => ['acceptStrings(array_column(column_key: "value", array: StringEnum::cases()));', []],
    'integer backing remains integer' => ['acceptStrings(array_column(IntEnum::cases(), "value"));', ['invalid-argument']],
    'indexed enum column retains native keys' => ['acceptStrings(array_column(StringEnum::cases(), "value", "name"));', ['possibly-invalid-argument']],
    'literal builtin' => ['acceptStrings(array_values(array_filter($values, "is_string")));', []],
    'pure arrow guard' => ['acceptStrings(array_values(array_filter($values, static fn (mixed $value): bool => is_string($value))));', []],
    'untyped arrow guard' => ['acceptStrings(array_values(array_filter($values, static fn ($value): bool => \\is_string($value))));', []],
    'typed callback accepts original string values' => ['acceptStrings(array_values(array_filter(["a"], static fn (string $value): bool => \\is_string($value))));', []],
    'string callback coercion retains original integer' => ['acceptStrings(array_values(array_filter([1], static fn (string $value): bool => \\is_string($value))));', ['invalid-argument', 'invalid-argument']],
    'union callback coercion retains original integer' => ['acceptStrings(array_values(array_filter([1], static fn (string|bool $value): bool => \\is_string($value))));', ['invalid-argument', 'invalid-argument']],
    'guard and trim' => ['acceptStrings(array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== "")));', []],
    'guard and regex' => ['acceptStrings(array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && preg_match("/a/", $value) === 1)));', []],
    'regex output mutation deferred' => ['acceptStrings(array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && preg_match("/a/", $value, $value) === 1)));', ['less-specific-nested-argument-type']],
    'qualified guard' => ['acceptStrings(array_values(array_filter($values, static fn (mixed $value): bool => \\is_string($value))));', []],
    'named arguments' => ['acceptStrings(array_values(array_filter(callback: "is_string", array: $values)));', []],
    'key contract retained' => ['acceptStringMap(array_filter($map, "is_string"));', []],
    'integer consumer rejected' => ['foreach (array_filter($values, "is_string") as $value) { acceptInt($value); }', ['invalid-argument']],
    'unguarded callback' => ['acceptStrings(array_values(array_filter($values, static fn (mixed $value): bool => true)));', ['less-specific-nested-argument-type']],
    'disjunction allows integers' => ['acceptStrings(array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) || is_int($value))));', ['less-specific-nested-argument-type']],
    'key mode does not narrow guarded values' => ['acceptStrings(array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== "", ARRAY_FILTER_USE_KEY)));', ['less-specific-nested-argument-type']],
    'mutating predicate deferred' => ['acceptStrings(array_values(array_filter($values, static fn (mixed $value): bool => is_string($value = "x"))));', ['less-specific-nested-argument-type']],
];
$source = $preamble."\n";
$shadow = in_array('--namespace-shadow', $argv, true);
if ($shadow) {
    $source = str_replace('<?php', '<?php namespace FilterFixtures; function is_string(mixed $value): bool { return true; }', $source);
    foreach (['pure arrow guard', 'guard and trim', 'guard and regex'] as $name) { $cases[$name][1] = ['less-specific-nested-argument-type']; }
    $cases['guard and trim'][1][] = 'mixed-argument';
    $cases['guard and regex'][1][] = 'mixed-argument';
    $cases['regex output mutation deferred'][1][] = 'mixed-argument';
    $cases['key mode does not narrow guarded values'][1][] = 'less-specific-argument';
}
$lines = [];
foreach ($cases as $name => [$body, $expected]) {
    $source .= "/**\n * @param array<array-key, mixed> \$values\n * @param array<string, mixed> \$map\n */\n";
    $source .= 'function scenario'.count($lines).'(array $values, array $map): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $expected];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
    'source' => ['paths' => ['cases.php']],
    'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace], 'workers' => 1]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']], $pipes);
if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
fclose($pipes[0]);
$exit = proc_close($process);
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) { throw new RuntimeException('Mago failed: '.$workspace.' '.$log); }
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    if ($issue['level'] !== 'Error') { continue; }
    $primary = array_values(array_filter($issue['annotations'], static fn (array $a): bool => $a['kind'] === 'Primary'))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? []; sort($codes); sort($expected);
    if ($codes !== $expected) { throw new RuntimeException($name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace); }
    unset($actual[$line]); echo 'PASS: '.$name."\n";
}
if ($actual !== []) { throw new RuntimeException('Unexpected diagnostics: '.$workspace); }
