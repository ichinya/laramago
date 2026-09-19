<?php

declare(strict_types=1);

// Check native Config injection attributes through the real SDK worker without executing fixtures.
// Laravel excerpts: Copyright (c) Taylor Otwell. See fixtures/analysis/configuration-LICENSE.md.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago config attributes '.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
mkdir($framework.'/Container/Attributes', 0777, true);
mkdir($framework.'/Contracts/Container', 0777, true);
mkdir($framework.'/Config', 0777, true);
mkdir($workspace.'/config', 0777, true);
mkdir($workspace.'/bootstrap', 0777, true);
file_put_contents($framework.'/Contracts/Container/Container.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Container;
    interface Container { public function make($abstract, array $parameters = []); }
    PHP);
file_put_contents($framework.'/Contracts/Container/ContextualAttribute.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Container;
    interface ContextualAttribute {}
    PHP);
$repository = <<<'PHP'
    <?php
    namespace Illuminate\Config;
    class Repository {
        public function get($key, $default = null): mixed { return $default; }
    }
    PHP;
file_put_contents($framework.'/Config/Repository.php', $repository);
$configAttribute = <<<'PHP'
    <?php
    namespace Illuminate\Container\Attributes;
    use Attribute;
    use Illuminate\Contracts\Container\Container;
    use Illuminate\Contracts\Container\ContextualAttribute;
    #[Attribute(Attribute::TARGET_PARAMETER)]
    class Config implements ContextualAttribute {
        public function __construct(public string $key, public mixed $default = null) {}
        public static function resolve(self $attribute, Container $container) {
            return $container->make('config')->get($attribute->key, $attribute->default);
        }
    }
    PHP;
file_put_contents($framework.'/Container/Attributes/Config.php', $configAttribute);
file_put_contents($workspace.'/config/app.php', <<<'PHP'
    <?php
    return ['name' => 'Laramago', 'dynamic' => runtimeValue()];
    PHP);
$contract = [
    'complete' => true,
    'runtime-configuration-unchanged' => true,
];
$writeComposer = static function (mixed $configuration, ?array $bindingFiles = null) use ($workspace): void {
    $laramago = ['configuration-keys' => $configuration];
    if ($bindingFiles !== null) {
        $laramago['binding-files'] = $bindingFiles;
    }
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => $laramago],
    ], JSON_THROW_ON_ERROR));
};
$writeComposer($contract);

$missing = 'ichinya/laramago/laramago-missing-configuration-key';
$source = <<<'PHP'
    <?php
    use Illuminate\Container\Attributes\Config;
    #[\Attribute(\Attribute::TARGET_PARAMETER)]
    class CustomConfig { public function __construct(public string $key) {} }
    final class ConfigKeys { public const MISSING = 'app.missing'; }
    PHP;
$lines = [];
$cases = [
    'known key' => ['#[Config("app.name")]', []],
    'known dynamic value' => ['#[Config("app.dynamic")]', []],
    'missing key' => ['#[Config("app.missing")]', [$missing]],
    'named key' => ['#[Config(key: "app.missing")]', [$missing]],
    'missing key with default' => ['#[Config("app.missing", "fallback")]', [$missing]],
    'single namespace deferred' => ['#[Config("missing")]', []],
    'class constant deferred' => ['#[Config(ConfigKeys::MISSING)]', []],
    'custom attribute deferred' => ['#[CustomConfig("app.missing")]', []],
];
foreach ($cases as $name => [$attribute, $codes]) {
    $source .= 'function scenario'.count($lines).'('.$attribute.' mixed $value): void {}'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Contracts/Container/Container.php',
            'vendor/laravel/framework/src/Illuminate/Contracts/Container/ContextualAttribute.php',
            'vendor/laravel/framework/src/Illuminate/Container/Attributes/Config.php',
            'vendor/laravel/framework/src/Illuminate/Config/Repository.php',
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
            'workers' => 3,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$analyze = static function (string $label) use ($command, $workspace): array {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$label.'.json', 'w'],
            2 => ['file', $workspace.'/'.$label.'.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$label.'.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException($label.': external analyzer failed; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);

    return [$exit, $report];
};
$pluginIssues = static function (array $report): array {
    return array_values(array_filter(
        $report['issues'] ?? [],
        static fn (array $issue): bool => str_starts_with($issue['code'], 'ichinya/laramago/'),
    ));
};

[, $report] = $analyze('enabled');
$actual = [];
foreach ($pluginIssues($report) as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
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
    throw new RuntimeException('Unexpected plugin diagnostics outside attribute scenarios; inspect '.$workspace);
}

$guards = [
    'no contract' => null,
    'incomplete contract' => ['complete' => false, 'runtime-configuration-unchanged' => true],
    'runtime assertion omitted' => ['complete' => true],
    'runtime mutation allowed' => ['complete' => true, 'runtime-configuration-unchanged' => false],
];
foreach ($guards as $label => $configurationContract) {
    $writeComposer($configurationContract);
    [, $guardReport] = $analyze(str_replace(' ', '-', $label));
    if ($pluginIssues($guardReport) !== []) {
        throw new RuntimeException($label.': expected no plugin diagnostics; inspect '.$workspace);
    }
    echo 'PASS: '.$label."\n";
}

$writeComposer($contract);
file_put_contents($workspace.'/config/app.php', <<<'PHP'
    <?php
    return ['name' => 'Laramago', ...runtimeConfiguration()];
    PHP);
[, $guardReport] = $analyze('source-incomplete');
if ($pluginIssues($guardReport) !== []) {
    throw new RuntimeException('source-incomplete: expected no plugin diagnostics; inspect '.$workspace);
}
echo "PASS: source-incomplete catalog defers\n";
file_put_contents($workspace.'/config/app.php', <<<'PHP'
    <?php
    return ['name' => 'Laramago', 'dynamic' => runtimeValue()];
    PHP);

file_put_contents(
    $workspace.'/bootstrap/bindings.php',
    '<?php \\app()->singleton("config", CustomConfigRepository::class);',
);
$writeComposer($contract, ['bootstrap/bindings.php']);
[, $guardReport] = $analyze('custom-config-binding');
if ($pluginIssues($guardReport) !== []) {
    throw new RuntimeException('custom config binding: expected no plugin diagnostics; inspect '.$workspace);
}
echo "PASS: custom config binding defers\n";

file_put_contents(
    $workspace.'/bootstrap/bindings.php',
    '<?php \\app()->whenHasAttribute(\\Illuminate\\Container\\Attributes\\Config::class, fn () => null);',
);
$writeComposer($contract, ['bootstrap/bindings.php']);
[, $guardReport] = $analyze('custom-attribute-handler');
if ($pluginIssues($guardReport) !== []) {
    throw new RuntimeException('custom attribute handler: expected no plugin diagnostics; inspect '.$workspace);
}
echo "PASS: custom attribute handler defers\n";

$writeComposer($contract);
$attributeGuards = [
    'changed constructor' => str_replace(
        'public function __construct(public string $key, public mixed $default = null) {}',
        'public function __construct(public string $key, public mixed $default = null) { $this->key = $key; }',
        $configAttribute,
    ),
    'changed resolver' => str_replace(
        "return \$container->make('config')->get(\$attribute->key, \$attribute->default);",
        "return \$container->make('config')->get(\$attribute->key);",
        $configAttribute,
    ),
    'protected resolver' => str_replace(
        'public static function resolve(',
        'protected static function resolve(',
        $configAttribute,
    ),
    'reference resolver' => str_replace(
        'public static function resolve(',
        'public static function &resolve(',
        $configAttribute,
    ),
    'removed contextual marker' => str_replace(' implements ContextualAttribute', '', $configAttribute),
    'custom container contract' => str_replace(
        'Container $container',
        '\\CustomContainer $container',
        $configAttribute,
    ),
];
foreach ($attributeGuards as $label => $source) {
    file_put_contents($framework.'/Container/Attributes/Config.php', $source);
    [, $guardReport] = $analyze(str_replace(' ', '-', $label));
    if ($pluginIssues($guardReport) !== []) {
        throw new RuntimeException($label.': expected no plugin diagnostics; inspect '.$workspace);
    }
    echo 'PASS: '.$label." defers\n";
}
file_put_contents($framework.'/Container/Attributes/Config.php', $configAttribute);

file_put_contents($framework.'/Config/Repository.php', <<<'PHP'
    <?php
    namespace Illuminate\Config;
    class BaseRepository {
        public function get($key, $default = null): mixed { return $default; }
    }
    class Repository extends BaseRepository {}
    PHP);
[, $guardReport] = $analyze('inherited-repository-get');
if ($pluginIssues($guardReport) !== []) {
    throw new RuntimeException('inherited repository get: expected no plugin diagnostics; inspect '.$workspace);
}
echo "PASS: non-native Repository::get provenance defers\n";
file_put_contents($framework.'/Config/Repository.php', $repository);

$configurationWithoutExtension = $configuration;
unset($configurationWithoutExtension['extension-hosts']);
file_put_contents(
    $workspace.'/mago.json',
    json_encode($configurationWithoutExtension, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
);
[, $disabledReport] = $analyze('disabled');
if ($pluginIssues($disabledReport) !== []) {
    throw new RuntimeException('Disabled analyzer must leave Config attributes native; inspect '.$workspace);
}
echo "PASS: disabled analyzer leaves Config attributes native\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
$resolvedWorkspace = realpath($workspace);
foreach ($iterator as $file) {
    $resolvedFile = realpath($file->getPathname());
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);
