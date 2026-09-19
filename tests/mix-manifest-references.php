<?php

declare(strict_types=1);

// Synthetic source is analyzed by the real Mago worker, never executed.
$package = str_replace('\\', '/', dirname(__DIR__));
$mode = $argv[1] ?? 'native';
if (! in_array(
    $mode,
    [
        'native',
        'unasserted',
        'hot',
        'custom-helper',
        'custom-app',
        'custom-doc',
        'changed-return',
        'changed-helper',
        'changed-mix',
        'crlf-mix',
        'cr-mix',
        'shadowed-json',
        'changed-app-default',
        'changed-app-return',
        'changed-app-forwarding',
        'changed-helper-named-dispatch',
        'binding',
        'invalid-json',
    ],
    true,
)) {
    throw new RuntimeException('Unknown test mode.');
}
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago mix refs '.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Foundation';
mkdir($framework, 0777, true);
mkdir($workspace.'/public/admin', 0777, true);
$mix = file_get_contents(__DIR__.'/fixtures/analysis/mix-native.php.stub');
$mix = str_replace(["\r\n", "\r"], "\n", $mix);
if ($mode === 'changed-mix') {
    $mix = str_replace("if (! isset(\$manifest[\$path]))", "if (false && ! isset(\$manifest[\$path]))", $mix);
}
if ($mode === 'crlf-mix') {
    $mix = str_replace("\n", "\r\n", $mix);
}
if ($mode === 'cr-mix') {
    $mix = str_replace("\n", "\r", $mix);
}
file_put_contents($framework.'/Mix.php', $mix);
$helper = <<<'PHP'
    <?php
    use Illuminate\Foundation\Mix;
    use Illuminate\Container\Container;
    use Illuminate\Support\HtmlString;
    function app($abstract = null, array $parameters = [])
    {
        if (is_null($abstract)) {
            return Container::getInstance();
        }
        return Container::getInstance()->make($abstract, $parameters);
    }
    function mix($path, $manifestDirectory = ''): HtmlString|string
    {
        return app(Mix::class)(...func_get_args());
    }
    PHP;
if ($mode === 'changed-helper') {
    $helper = str_replace('app(Mix::class)', 'app("custom")', $helper);
}
if ($mode === 'custom-app') {
    $helper = str_replace(
        'return Container::getInstance()->make($abstract, $parameters);',
        'return "custom";',
        $helper,
    );
}
if ($mode === 'custom-doc') {
    $helper = str_replace('function mix($path,', '/** @return string */ function mix($path,', $helper);
}
if ($mode === 'changed-return') {
    $helper = str_replace('): HtmlString|string', '): string', $helper);
}
if ($mode === 'changed-app-default') {
    $helper = str_replace('array $parameters = []', 'array $parameters = ["changed"]', $helper);
}
if ($mode === 'changed-app-return') {
    $helper = str_replace(
        'function app($abstract = null, array $parameters = [])',
        'function app($abstract = null, array $parameters = []): mixed',
        $helper,
    );
}
if ($mode === 'changed-app-forwarding') {
    $helper = str_replace(
        'make($abstract, $parameters)',
        'make(abstract: $abstract, parameters: $parameters)',
        $helper,
    );
}
if ($mode === 'changed-helper-named-dispatch') {
    $helper = str_replace('app(Mix::class)', 'app(abstract: Mix::class)', $helper);
}
$helperPath = $mode === 'custom-helper' ? $workspace.'/custom/helpers.php' : $framework.'/helpers.php';
if ($mode === 'custom-helper') {
    mkdir($workspace.'/custom');
}
file_put_contents($helperPath, $helper);
if ($mode === 'shadowed-json') {
    file_put_contents($workspace.'/shadow.php', <<<'PHP'
        <?php
        namespace Illuminate\Foundation;
        function json_decode(string $value, ?bool $associative = null): array { return []; }
        PHP);
}
if ($mode === 'binding') {
    mkdir($workspace.'/bootstrap');
    file_put_contents($workspace.'/bootstrap/bindings.php', <<<'PHP'
        <?php
        \app()->bind(\Illuminate\Foundation\Mix::class, \Illuminate\Foundation\Mix::class);
        PHP);
}
$entry = static fn (string $directory, string $path): array => [
    'directory' => $directory,
    'path' => $path,
    'native-runtime' => $mode !== 'unasserted',
    'effective-public-path' => true,
    'hot-file-absent' => true,
    'manifest-stable' => true,
];
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'reference-catalogs' => [
                'mix-manifests' => ['files' => [
                    $entry('', 'public/mix-manifest.json'),
                    $entry('admin', 'public/admin/mix-manifest.json'),
                ]],
            ],
            'binding-files' => $mode === 'binding' ? ['bootstrap/bindings.php'] : [],
        ],
    ],
], JSON_THROW_ON_ERROR));
file_put_contents(
    $workspace.'/public/mix-manifest.json',
    $mode === 'invalid-json' ? '{broken' : '{"/app.js":"/app.js?id=abc"}',
);
file_put_contents($workspace.'/public/admin/mix-manifest.json', '{"/admin.js":"/admin.js?id=xyz"}');
if ($mode === 'native') {
    require $package.'/vendor/autoload.php';
    $hook = new \Ichinya\Laramago\Analyzer\MixManifestReferencesHook($workspace);
    $byReference = new \PhpParser\Node\Arg(new \PhpParser\Node\Scalar\String_('missing.js'), byRef: true);
    $referenceCall = new \PhpParser\Node\Expr\FuncCall(new \PhpParser\Node\Name('mix'), [$byReference]);
    if ($hook->literalArguments($referenceCall) !== [null, null]) {
        throw new RuntimeException('By-reference literal call must defer.');
    }
    $forward = new \ReflectionMethod($hook, 'variable');
    $forwardArgument = new \PhpParser\Node\Arg(new \PhpParser\Node\Expr\Variable('abstract'), byRef: true);
    if ($forward->invoke($hook, $forwardArgument, 'abstract') !== false) {
        throw new RuntimeException('By-reference native forwarding must defer.');
    }
    $asserted = new \ReflectionProperty($hook, 'asserted');
    if (count($asserted->getValue($hook)) !== 2) {
        throw new RuntimeException('Initial assertions missing.');
    }
    $nativeCache = new \ReflectionProperty($hook, 'native');
    $nativeCache->setValue($hook, true);
    $composer = file_get_contents($workspace.'/composer.json');
    file_put_contents($workspace.'/composer.json', str_replace(
        '"native-runtime":true',
        '"native-runtime":false',
        $composer,
    ));
    $hook->initialize(new \Mago\Sdk\Analyzer\InitializationContext(
        \Mago\Sdk\PHPVersion::fromParts(8, 2),
        new \Mago\Sdk\Internal\SignalCancellationToken,
    ));
    if ($asserted->getValue($hook) !== [] || $nativeCache->getValue($hook) !== null) {
        throw new RuntimeException('Initialization did not refresh Mix assertions and native cache.');
    }
    file_put_contents($workspace.'/composer.json', $composer);
}
if ($mode === 'hot') {
    file_put_contents($workspace.'/public/hot', 'http://localhost:8080');
}
$source = <<<'PHP'
    <?php
    namespace App {
    use function mix as native_mix;
    function check(string $dynamic): void {
        mix('app.js');
        mix('/missing.js');
        mix('admin.js', 'admin');
        mix(path: 'missing-admin.js', manifestDirectory: '/admin');
        native_mix('missing.js');
        \mix('missing.js');
        mix($dynamic);
        mix('missing.js', $dynamic);
        mix(...['missing.js']);
        $callback = mix(...);
    }
    }
    namespace Custom {
    function mix(string $path): string { return $path; }
    function check(): void { mix('missing.js'); }
    }
    PHP;
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            substr($helperPath, strlen($workspace) + 1),
            'vendor/laravel/framework/src/Illuminate/Foundation/Mix.php',
            ...($mode === 'shadowed-json' ? ['shadow.php'] : []),
        ],
    ],
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
            'workers' => 2,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$diagnostics = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $diagnostics[$line][] = $issue['code'];
}
$expected = in_array($mode, ['native', 'crlf-mix', 'cr-mix'], true)
    ? [6, 8, 9, 10]
    : ($mode === 'hot' || $mode === 'invalid-json' ? [8] : []);
$actual = [];
foreach ($diagnostics as $line => $codes) {
    foreach ($codes as $code) {
        if ($code === 'ichinya/laramago/laramago-missing-mix-manifest-key') {
            $actual[] = $line;
        }
    }
}
sort($actual);
if (
    $actual !== $expected
    || $exit !== 0
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/stderr.log'),
    )
) {
    throw new RuntimeException(
        $mode
        .' expected '
        .json_encode($expected)
        .' got '
        .json_encode($actual)
        .'; exit '
        .$exit
        .'; other '
        .json_encode($diagnostics)
        .'; inspect '
        .$workspace,
    );
}
echo 'PASS '.$mode.' '.json_encode($actual)."\n";
