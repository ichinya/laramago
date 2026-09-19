<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-self-alias-'.bin2hex(random_bytes(8));
$framework = $workspace.'/custom vendor/laravel/framework/src/Illuminate/Container';
mkdir($framework, 0777, true);
$native = file_get_contents(__DIR__.'/fixtures/analysis/container-native.php.stub');
file_put_contents($workspace.'/support.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Container { interface Container {} }
    namespace Illuminate\Support\Traits { trait ReflectsClosures {} }
    namespace Example {
        class Custom extends \Illuminate\Container\Container {}
        class Other { public function alias($abstract, $alias) {} }
        function key(): string { return 'a'; }
    }
    PHP);
$prefix = '(new \Illuminate\Container\Container)->';
$cases = [
    $prefix."alias('same', 'same');",
    $prefix."alias(alias: 'a', abstract: 'a');",
    $prefix."alias('', '');",
    $prefix."alias('0', '0');",
    $prefix."ALIAS('same', 'same');",
    $prefix."alias('A', 'a');",
    $prefix."alias('a', '\\\\a');",
    $prefix."alias('a', 'b');",
    $prefix.'alias(key(), key());',
    $prefix."alias(...['a', 'a']);",
    $prefix."alias('a', alias: 'a');",
    "(new Custom)->alias('a', 'a');",
    "(new Other)->alias('a', 'a');",
    "(new \Illuminate\Container\Container(1))->alias('a', 'a');",
    $prefix."alias(Abstract: 'a', alias: 'a');",
    $prefix."alias('a');",
    $prefix."alias('a', 'a', 'extra');",
    $prefix.'alias(1, 1);',
    "(new \Illuminate\Container\Container)->missingMethod();",
    "(new Imported)->alias('slash\\\\key', 'slash\\\\key');",
];
file_put_contents(
    $workspace.'/cases.php',
    "<?php\nnamespace Example;\nuse Illuminate\\Container\\Container as Imported;\n".implode("\n", $cases),
);
$nativeDiagnostics = null;
foreach ([
    'native',
    'crlf',
    'disabled',
    'changed-body',
    'changed-signature',
    'constructor',
    'custom-doc',
    'abstract',
] as $mode) {
    $source = match ($mode) {
        'crlf' => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $native)),
        'changed-body' => str_replace('if ($alias === $abstract)', 'if (false)', $native),
        'changed-signature' => str_replace(
            'function alias($abstract, $alias)',
            'function alias($alias, $abstract)',
            $native,
        ),
        'constructor' => str_replace(
            'use ReflectsClosures;',
            'use ReflectsClosures; public function __construct() {}',
            $native,
        ),
        'custom-doc' => str_replace(
            'class Container implements',
            '/** @method void alias(string $abstract, string $alias) */ class Container implements',
            $native,
        ),
        'abstract' => str_replace('class Container implements', 'abstract class Container implements', $native),
        default => $native,
    };
    file_put_contents($framework.'/Container.php', $source);
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['custom vendor', 'support.php']],
        'extension-hosts' => $mode === 'disabled'
            ? new stdClass
            : [
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
    ];
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open(
        [PHP_BINARY, $package.'/vendor/bin/mago', '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
            2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
        ],
        $pipes,
    );
    fclose($pipes[0]);
    $exit = proc_close($process);
    if (
        $exit > 1
        || preg_match('/provider failed|rejected request|parse error/i', file_get_contents($workspace.'/'.$mode.'.log'))
    ) {
        throw new RuntimeException('Analyzer failed: '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $actual = [];
    $codes = [];
    $diagnostics = [];
    foreach ($issues as $issue) {
        $codes[] = $issue['code'];
        if ($issue['code'] !== 'ichinya/laramago/laramago-container-self-alias') {
            $primary = array_values(array_filter(
                $issue['annotations'],
                static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
            ));
            $diagnostics[] = json_encode([
                $issue['code'],
                $issue['message'],
                array_column($primary, 'span'),
            ], JSON_THROW_ON_ERROR);
            continue;
        }
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary') {
                $actual[] = $annotation['span']['start']['line'] - 3;
                break;
            }
        }
    }
    sort($diagnostics);
    if ($mode === 'native') {
        $nativeDiagnostics = $diagnostics;
    } elseif ($mode === 'disabled' && $diagnostics !== $nativeDiagnostics) {
        throw new RuntimeException('Native diagnostic multiset changed: '.$workspace);
    }
    sort($actual);
    if ($actual !== (in_array($mode, ['native', 'crlf'], true) ? [0, 1, 2, 3, 4, 10, 19] : [])) {
        throw new RuntimeException($mode.': '.json_encode($actual).' '.$workspace);
    }
    if (! in_array($mode === 'abstract' ? 'abstract-instantiation' : 'non-existent-method', $codes, true)) {
        throw new RuntimeException('Native errors lost: '.$workspace);
    }
    echo 'PASS '.$mode.PHP_EOL;
}
echo 'Fixture: '.$workspace.PHP_EOL;
