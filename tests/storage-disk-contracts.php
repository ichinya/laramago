<?php

declare(strict_types=1);

// Check storage disk contracts through the real SDK worker without executing fixtures.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago storage disks '.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
mkdir($framework.'/Support/Facades', 0777, true);
mkdir($framework.'/Filesystem', 0777, true);
mkdir($framework.'/Contracts/Filesystem', 0777, true);
mkdir($framework.'/Container/Attributes', 0777, true);
mkdir($workspace.'/config', 0777, true);
mkdir($workspace.'/bootstrap', 0777, true);
file_put_contents($framework.'/Support/Facades/Facade.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    abstract class Facade {
        public static function getFacadeRoot(): mixed { return null; }
        protected static function resolveFacadeInstance(string $name): mixed { return null; }
        public static function __callStatic(string $method, array $arguments): mixed { return null; }
    }
    PHP);
$storageFacade = <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    /**
     * @method static \Illuminate\Contracts\Filesystem\Filesystem disk(?string $name = null)
     * @method static void fake(?string $disk = null)
     */
    class Storage extends Facade {
        protected static function getFacadeAccessor(): string { return 'filesystem'; }
    }
    class CustomStorage extends Storage {}
    PHP;
file_put_contents($framework.'/Support/Facades/Storage.php', $storageFacade);
$manager = <<<'PHP'
    <?php
    namespace Illuminate\Filesystem;
    class FilesystemManager {
        public function disk(?string $name = null): \Illuminate\Contracts\Filesystem\Filesystem { throw new \RuntimeException; }
    }
    PHP;
file_put_contents($framework.'/Filesystem/FilesystemManager.php', $manager);
file_put_contents($framework.'/Contracts/Filesystem/Filesystem.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Filesystem;
    interface Filesystem {}
    PHP);
file_put_contents($framework.'/Container/Attributes/Storage.php', <<<'PHP'
    <?php
    namespace Illuminate\Container\Attributes;
    #[\Attribute(\Attribute::TARGET_PARAMETER)]
    class Storage { public function __construct(public string $disk) {} }
    PHP);
file_put_contents($workspace.'/config/filesystems.php', <<<'PHP'
    <?php
    return ['disks' => ['local' => ['driver' => 'local'], 'remote' => dynamicConfiguration()]];
    PHP);
$contract = [
    'complete' => true,
    'runtime-disks-unchanged' => true,
];
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['storage-disks' => $contract]],
], JSON_THROW_ON_ERROR));
$missing = ['ichinya/laramago/laramago-missing-storage-disk'];
$cases = [
    'known disk' => ['Storage::disk("local");', []],
    'known dynamic-value disk' => ['Storage::disk("remote");', []],
    'missing disk' => ['Storage::disk("missing");', $missing],
    'named argument' => ['Storage::disk(name: "missing");', $missing],
    'mis-cased named argument stays native' => ['Storage::disk(Name: "missing");', ['invalid-named-argument']],
    'case sensitive' => ['Storage::disk("Local");', $missing],
    'empty string selects default' => ['Storage::disk("");', []],
    'zero string selects default' => ['Storage::disk("0");', []],
    'dynamic name deferred' => ['Storage::disk($name);', []],
    'concatenation deferred' => ['Storage::disk("local".$name);', []],
    'unpacked argument deferred' => ['Storage::disk(...["missing"]);', []],
    'fake excluded' => ['Storage::fake("missing");', []],
    'custom facade deferred' => ['CustomStorage::disk("missing");', []],
    'direct manager deferred' => ['$manager->disk("missing");', []],
    'unknown method retained' => ['Storage::diks("missing");', ['non-documented-method']],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Facades\CustomStorage;
    use Illuminate\Filesystem\FilesystemManager;
    use Illuminate\Container\Attributes\Storage as StorageAttribute;
    use Illuminate\Contracts\Filesystem\Filesystem;
    class FilesystemImplementation implements Filesystem {}
    function attributeScenario(#[StorageAttribute('local')] Filesystem $filesystem): void {}
    function missingAttributeScenario(#[StorageAttribute('missing')] Filesystem $filesystem): void {}
    function callableScenario(): Closure { return Storage::disk(...); }
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $codes]) {
    $source .= 'function scenario'.count($lines).'(string $name, FilesystemManager $manager): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Storage.php',
            'vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemManager.php',
            'vendor/laravel/framework/src/Illuminate/Contracts/Filesystem/Filesystem.php',
            'vendor/laravel/framework/src/Illuminate/Container/Attributes/Storage.php',
        ],
    ],
    'extension-hosts' => [
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

[$exit, $report] = $analyze('enabled');
if ($exit !== 1) {
    throw new RuntimeException('Expected warnings from enabled contracts; inspect '.$workspace);
}
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
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
    throw new RuntimeException('Unexpected diagnostics outside storage scenarios; inspect '.$workspace);
}

$writeComposer = static function (mixed $storage, ?array $bindingFiles = null) use ($workspace): void {
    $laramago = ['storage-disks' => $storage];
    if ($bindingFiles !== null) {
        $laramago['binding-files'] = $bindingFiles;
    }
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => $laramago],
    ], JSON_THROW_ON_ERROR));
};
$nativeOnly = ['invalid-named-argument', 'non-documented-method'];
$guards = [
    'no contract' => null,
    'incomplete contract' => ['complete' => false, 'runtime-disks-unchanged' => true],
    'runtime assertion omitted' => ['complete' => true],
    'runtime mutations allowed' => ['complete' => true, 'runtime-disks-unchanged' => false],
    'malformed contract' => 'complete',
];
foreach ($guards as $label => $storage) {
    $writeComposer($storage);
    [$guardExit, $guardReport] = $analyze(str_replace(' ', '-', $label));
    if ($guardExit !== 1 || array_column($guardReport['issues'] ?? [], 'code') !== $nativeOnly) {
        throw new RuntimeException($label.': expected native diagnostics only; inspect '.$workspace);
    }
    echo 'PASS: '.$label."\n";
}

$writeComposer($contract);
file_put_contents($workspace.'/config/filesystems.php', <<<'PHP'
    <?php
    return ['disks' => ['local' => [], ...runtimeDisks()]];
    PHP);
[$guardExit, $guardReport] = $analyze('source-incomplete');
if ($guardExit !== 1 || array_column($guardReport['issues'] ?? [], 'code') !== $nativeOnly) {
    throw new RuntimeException('source-incomplete: expected native diagnostics only; inspect '.$workspace);
}
echo "PASS: source-incomplete catalog defers\n";
file_put_contents($workspace.'/config/filesystems.php', <<<'PHP'
    <?php
    return ['disks' => ['local' => [], 'remote' => dynamicConfiguration()]];
    PHP);

foreach (['config', 'filesystem'] as $service) {
    file_put_contents(
        $workspace.'/bootstrap/bindings.php',
        '<?php app()->singleton('.var_export($service, true).', CustomService::class);',
    );
    $writeComposer($contract, ['bootstrap/bindings.php']);
    [$guardExit, $guardReport] = $analyze('custom-'.$service);
    if ($guardExit !== 1 || array_column($guardReport['issues'] ?? [], 'code') !== $nativeOnly) {
        throw new RuntimeException(
            'custom '.$service.' binding: expected native diagnostics only; inspect '.$workspace,
        );
    }
    echo 'PASS: custom '.$service." binding defers\n";
}

$writeComposer($contract);
file_put_contents($framework.'/Support/Facades/Storage.php', str_replace(
    "return 'filesystem';",
    "return 'custom.filesystem';",
    $storageFacade,
));
[$guardExit, $guardReport] = $analyze('custom-accessor');
if ($guardExit !== 1 || array_column($guardReport['issues'] ?? [], 'code') !== $nativeOnly) {
    throw new RuntimeException('custom accessor: expected native diagnostics only; inspect '.$workspace);
}
echo "PASS: custom facade accessor defers\n";
file_put_contents($framework.'/Support/Facades/Storage.php', $storageFacade);
file_put_contents($framework.'/Filesystem/FilesystemManager.php', <<<'PHP'
    <?php
    namespace Illuminate\Filesystem;
    class BaseManager {
        public function disk(?string $name = null): \Illuminate\Contracts\Filesystem\Filesystem { throw new \RuntimeException; }
    }
    class FilesystemManager extends BaseManager {}
    PHP);
[$guardExit, $guardReport] = $analyze('inherited-manager-method');
if ($guardExit !== 1 || array_column($guardReport['issues'] ?? [], 'code') !== $nativeOnly) {
    throw new RuntimeException('inherited manager method: expected native diagnostics only; inspect '.$workspace);
}
echo "PASS: non-native FilesystemManager method provenance defers\n";
file_put_contents($framework.'/Filesystem/FilesystemManager.php', $manager);

$configurationWithoutExtension = $configuration;
unset($configurationWithoutExtension['extension-hosts']);
file_put_contents(
    $workspace.'/mago.json',
    json_encode($configurationWithoutExtension, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
);
[$disabledExit, $disabledReport] = $analyze('disabled');
if ($disabledExit !== 1 || array_column($disabledReport['issues'] ?? [], 'code') !== $nativeOnly) {
    throw new RuntimeException('Disabled analyzer must leave storage calls native; inspect '.$workspace);
}
echo "PASS: disabled analyzer leaves storage calls native\n";

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
