<?php

declare(strict_types=1);

// Real Mago/SDK behavior over exact Pest 3 forwarding method ASTs.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-pest-forwarding-'.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/pest-expectation-forwarding.php.stub', $workspace.'/pest.php');

$cases = [
    'builtin expectation result' => ['return expect(1)->toBe(1);', '\\Pest\\Expectation', []],
    'builtin fluent chain' => ['return expect(1)->toBe(1)->toBeInt();', '\\Pest\\Expectation', []],
    'negated builtin result' => ['return expect(1)->not()->toBe(2);', '\\Pest\\Expectation', []],
    'each builtin result' => ['return expect([1, 2])->each()->toBeInt();', '\\Pest\\Expectations\\EachExpectation', []],
    'higher-order builtin result' => [
        'return (new \\Pest\\Expectations\\HigherOrderExpectation(expect(1), 1))->toBe(1);',
        '\\Pest\\Expectations\\HigherOrderExpectation',
        [],
    ],
    'native method retains priority' => ['return expect(1)->native();', 'int', []],
    'documented method retains priority' => ['return expect(1)->documented();', 'string', []],
    'missing builtin argument' => ['expect(1)->toBe();', 'void', ['too-few-arguments']],
    'wrong builtin argument' => ['expect(1)->toBeInt(1);', 'void', ['invalid-argument']],
    'unknown expectation remains unknown' => ['expect(1)->toUnregistered();', 'void', ['non-documented-method']],
    'static mixin call stays invalid' => ['\\Pest\\Expectation::toBe(1);', 'void', ['non-existent-method']],
];
$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= 'function scenario'.count($lines).'(): '.$return.' { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['pest.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 1,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$analyze = static function () use ($package, $workspace): array {
    $process = proc_open(
        [PHP_BINARY, $package.'/vendor/bin/mago', '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/report.json', 'w'],
            2 => ['file', $workspace.'/stderr.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/stderr.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)) {
        throw new RuntimeException('Worker failure; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($exit !== 1 || ! is_array($report)) {
        throw new RuntimeException('Expected the deliberate negative cases; inspect '.$workspace);
    }
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }

    return $actual;
};
$actual = $analyze();
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException(
            $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics: '.json_encode($actual).'; inspect '.$workspace);
}

// A changed forwarding body must fail closed, even when the surrounding
// method names, signatures and mixin annotation stay intact.
$pest = str_replace("\r\n", "\n", file_get_contents($workspace.'/pest.php'));
$old = "->run();\n    return \$this;";
$new = "->run();\n    return new \\Pest\\Expectations\\HigherOrderExpectation(\$this, 1);";
if (substr_count($pest, $old) !== 1) {
    throw new RuntimeException('Cannot locate the exact Pest return in fixture.');
}
file_put_contents($workspace.'/pest.php', str_replace($old, $new, $pest));
$mutated = $analyze();
$builtinLine = array_key_first($lines);
if (! in_array('invalid-return-statement', $mutated[$builtinLine] ?? [], true)) {
    throw new RuntimeException('Changed dispatch body did not defer to native Mago; inspect '.$workspace);
}
echo "PASS: changed forwarding body defers\n";

file_put_contents($workspace.'/pest.php', str_replace(
    'namespace Pest {',
    'namespace Pest { function method_exists(object|string $object, string $method): bool { return false; }',
    $pest,
));
$shadowed = $analyze();
if (! in_array('invalid-return-statement', $shadowed[$builtinLine] ?? [], true)) {
    throw new RuntimeException('Shadowed native dispatch helper did not defer; inspect '.$workspace);
}
echo "PASS: shadowed dispatch helper defers\n";

$resolvedWorkspace = realpath($workspace);
foreach (glob($workspace.'/*') ?: [] as $path) {
    $resolved = realpath($path);
    if (
        $resolvedWorkspace === false
        || $resolved === false
        || ! str_starts_with($resolved, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside fixture workspace.');
    }
    unlink($resolved);
}
rmdir($workspace);
