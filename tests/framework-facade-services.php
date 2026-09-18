<?php

declare(strict_types=1);

// Check installed core service contracts through the real SDK worker.
// --disabled proves the same missing facade method without the provider.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago facade calls '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/facade-calls.php.stub', $workspace.'/facades.php');
$framework = $workspace.'/laravel/framework/src/Illuminate';
mkdir($framework.'/Foundation', 0777, true);
mkdir($framework.'/Support/Facades', 0777, true);
file_put_contents($framework.'/Foundation/Application.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation;
    class Application {
        public function registerCoreContainerAliases() {
            foreach (['url' => [\SyntheticUrlService::class]] as $key => $aliases) {
                foreach ($aliases as $alias) { $this->alias($key, $alias); }
            }
        }
    }
    PHP);
file_put_contents($framework.'/Support/Facades/URL.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    /** @method static int documentedOnly() */
    class URL extends Facade {
        public static function nativeOnly(): int { return 1; }
        protected static function getFacadeAccessor() { return 'url'; }
    }
    PHP);
file_put_contents($workspace.'/services.php', <<<'PHP'
    <?php
    class SyntheticUrlService {
        public function nativeOnly(): string { return "service"; }
        public function documentedOnly(): string { return "service"; }
        public function forceRootUrl(?string $root): void { throw new RuntimeException('Never execute'); }
        public function label(): string { throw new RuntimeException('Never execute'); }
    }
    class CustomUrlFacade extends \Illuminate\Support\Facades\Facade {
        protected static function getFacadeAccessor() { return 'url'; }
    }
    class UnknownShortName { }
    PHP);
$disabled = in_array('--disabled', $argv, true);
$cases = $disabled
    ? [
        'disabled service forwarding' => [
            '\Illuminate\Support\Facades\URL::forceRootUrl("https://example.test");',
            'void',
            ['non-documented-method'],
        ],
    ] : [
        'installed core service method' => [
            '\Illuminate\Support\Facades\URL::forceRootUrl("https://example.test");',
            'void',
            [],
        ],
        'installed core return contract' => ['return \Illuminate\Support\Facades\URL::label();', 'string', []],
        'native method wins' => ['return \\Illuminate\\Support\\Facades\\URL::nativeOnly();', 'int', []],
        'PHPDoc method wins' => ['return \\Illuminate\\Support\\Facades\\URL::documentedOnly();', 'int', []],
        'wrong argument preserved' => [
            '\Illuminate\Support\Facades\URL::forceRootUrl(12);',
            'void',
            ['invalid-argument'],
        ],
        'typo preserved' => ['\Illuminate\Support\Facades\URL::forceRootUrll("x");', 'void', ['non-documented-method']],
        'custom facade not guessed' => ['CustomUrlFacade::label();', 'void', ['non-documented-method']],
        'unknown class not guessed' => ['UnknownShortName::label();', 'void', ['non-existent-method']],
    ];
$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => ['framework.php', 'facades.php', 'services.php', $workspace.'/laravel'],
    ],
    'extension-hosts' => $disabled
        ? new stdClass
        : [
            'laramago' => [
                'command' => [
                    PHP_BINARY,
                    $package.'/bin/laramago-worker.php',
                    $package.'/vendor/autoload.php',
                    $workspace,
                ],
                'workers' => 3,
            ],
        ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
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
$log = file_get_contents($workspace.'/stderr.log');
if (
    $exit !== ($disabled ? 0 : 1)
    || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
) {
    throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
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
            $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics outside facade call scenarios; inspect '.$workspace);
}

removeContainerFactoryWorkspace($workspace);

function removeContainerFactoryWorkspace(string $workspace): void
{
    $resolved = realpath($workspace);
    $temporary = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refusing cleanup outside the temporary directory.');
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $path = $item->getPathname();
        if (! str_starts_with($path, $resolved.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing cleanup outside the test workspace.');
        }
        $item->isDir() ? rmdir($path) : unlink($path);
    }
    rmdir($resolved);
}
