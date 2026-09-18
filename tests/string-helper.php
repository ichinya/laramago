<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = sys_get_temp_dir().'/laramago-string-helper-'.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Support';
@mkdir($framework, 0777, true);
$helper = <<<'PHP'
    <?php
    /** @param string|null $string
     * @return ($string is null ? object : \Illuminate\Support\Stringable)
     */
    function str($string = null) {
        if (func_num_args() === 0) {
            return new class {
                public function __call($method, $parameters) {
                    return \Illuminate\Support\Str::$method(...$parameters);
                }
                public function __toString() { return ''; }
            };
        }
        return new \Illuminate\Support\Stringable($string);
    }
    PHP;
file_put_contents($framework.'/helpers.php', $helper);
file_put_contents($framework.'/Str.php', <<<'PHP'
    <?php
    namespace Illuminate\Support;
    class Str {
        public static function upper(string $value): string { return strtoupper($value); }
        public static function count(string $value, int $times = 1): int { return strlen($value) * $times; }
        public static function reference(string &$value): string { return $value; }
        public static function contextual(): static { throw new \RuntimeException(); }
        private static function hidden(): int { return 0; }
    }
    class Stringable {
        public function __construct(?string $value = '') {}
        public function append(string $value): self { return $this; }
        public function __toString(): string { return ''; }
    }
    PHP);
$cases = [
    'upper' => 'function upper(): string { return str()->upper("ok"); }',
    'return' => 'function wrongReturn(): int { return str()->upper("ok"); }',
    'argument' => 'function argument(): void { str()->upper(123); }',
    'arity' => 'function arity(): void { str()->upper(); }',
    'named' => 'function named(): string { return str()->upper(value: "ok"); }',
    'unknownNamed' => 'function unknownNamed(): void { str()->upper(other: "ok"); }',
    'null' => 'function nullValue(): \\Illuminate\\Support\\Stringable { return str(null)->append("ok"); }',
    'value' => 'function stringValue(): \\Illuminate\\Support\\Stringable { return str("x")->append("ok"); }',
    'unknown' => 'function unknown(): void { str()->missing(); }',
    'private' => 'function hidden(): void { str()->hidden(); }',
    'reference' => 'function reference(): void { $s = "ok"; str()->reference($s); }',
    'contextual' => 'function contextual(): void { str()->contextual(); }',
    'callable' => 'function callableValue(): \\Closure { return str()->upper(...); }',
    'dynamic' => 'function dynamic(string $method): void { str()->$method("ok"); }',
    'unpacked' => '/** @param list<string> $args */ function unpacked(array $args): void { str(...$args)->append("ok"); }',
];
file_put_contents($workspace.'/cases.php', "<?php\n".implode("\n", $cases)."\n");
$enabledExpected = [
    2 => ['invalid-return-statement'],
    3 => ['invalid-argument'],
    4 => ['too-few-arguments'],
    6 => ['invalid-named-argument'],
    9 => ['non-documented-method'],
    10 => ['non-documented-method'],
    11 => ['non-documented-method'],
    12 => ['non-documented-method'],
    14 => ['string-member-selector'],
    15 => ['ambiguous-object-method-access'],
];
$disabledExpected = [
    1 => ['ambiguous-object-method-access', 'mixed-return-statement'],
    2 => ['ambiguous-object-method-access', 'mixed-return-statement'],
    3 => ['ambiguous-object-method-access'],
    4 => ['ambiguous-object-method-access'],
    5 => ['ambiguous-object-method-access', 'mixed-return-statement'],
    6 => ['ambiguous-object-method-access'],
    7 => ['ambiguous-object-method-access', 'mixed-return-statement'],
    9 => ['ambiguous-object-method-access'],
    10 => ['ambiguous-object-method-access'],
    11 => ['ambiguous-object-method-access'],
    12 => ['ambiguous-object-method-access'],
    13 => ['ambiguous-object-method-access'],
    14 => ['string-member-selector'],
    15 => ['ambiguous-object-method-access'],
];
foreach (['enabled', 'disabled', 'custom-helper', 'changed-dispatch', 'native-declaration', 'native-doc'] as $mode) {
    $enabled = $mode !== 'disabled';
    $helperFile = $mode === 'custom-helper' ? $workspace.'/custom.php' : $framework.'/helpers.php';
    file_put_contents(
        $framework.'/helpers.php',
        $mode === 'custom-helper'
            ? '<?php'
            : ($mode === 'changed-dispatch' ? str_replace('Str::$method', 'Str::upper', $helper) : $helper),
    );
    if ($mode === 'native-declaration') {
        file_put_contents($helperFile, str_replace(
            'function str($string = null)',
            'function str($string = null): object',
            $helper,
        ));
    }
    if ($mode === 'native-doc') {
        file_put_contents($helperFile, str_replace(
            '@return ($string is null ? object : \Illuminate\Support\Stringable)',
            '@return object',
            $helper,
        ));
    }
    if ($mode === 'custom-helper') {
        file_put_contents($helperFile, $helper);
    }
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['vendor', $helperFile]],
        'extension-hosts' => $enabled
            ? [
                'laramago' => [
                    'command' => [
                        PHP_BINARY,
                        $package.'/bin/laramago-worker.php',
                        $package.'/vendor/autoload.php',
                        $workspace,
                    ],
                    'workers' => 1,
                ],
            ] : new stdClass,
    ];
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open(
        [PHP_BINARY, $package.'/vendor/bin/mago', '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/report.json', 'w'],
            2 => ['file', $workspace.'/stderr.log', 'w'],
        ],
        $pipes,
    );
    fclose($pipes[0]);
    $exit = proc_close($process);
    if ($exit > 1 || preg_match('/provider failed|rejected request/i', file_get_contents($workspace.'/stderr.log'))) {
        throw new RuntimeException('Analyzer failed');
    }
    $issues = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $actual = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $a) {
            if ($a['kind'] === 'Primary') {
                $actual[$a['span']['start']['line']][] = $issue['code'];
                break;
            }
        }
    }
    $expected = $mode === 'enabled' ? $enabledExpected : $disabledExpected;
    if ($mode === 'native-doc') {
        $expected[8] = ['ambiguous-object-method-access', 'mixed-return-statement'];
    }
    foreach ($actual as &$codes) {
        sort($codes);
    }
    unset($codes);
    foreach ($expected as &$codes) {
        sort($codes);
    }
    unset($codes);
    ksort($actual);
    ksort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException($mode.': '.json_encode($actual));
    }
    echo 'PASS: string helper '.$mode.' ('.count($cases).' cases)'."\n";
}
