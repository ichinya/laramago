<?php

declare(strict_types=1);

// Exercise native factory bodies through the real Mago worker without Laravel bootstrap.
// Laravel excerpts are covered by fixtures/analysis/filesystem-factory-LICENSE.md.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago filesystem factories '.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Filesystem';
mkdir($framework, 0777, true);

file_put_contents($framework.'/FilesystemAdapter.php', <<<'PHP'
    <?php
    namespace Illuminate\Filesystem;
    class FilesystemAdapter implements \Illuminate\Contracts\Filesystem\Cloud {
        public function __construct($filesystem, $adapter, array $config) {}
    }
    PHP);
$localAdapter = <<<'PHP'
    <?php
    namespace Illuminate\Filesystem;
    class LocalFilesystemAdapter extends FilesystemAdapter {
        /** @return $this */
        public function diskName(string $disk) { $this->disk = $disk; return $this; }
        /** @return $this */
        public function shouldServeSignedUrls(bool $serve = true, ?\Closure $urlGeneratorResolver = null) {
            $this->shouldServeSignedUrls = $serve;
            $this->urlGeneratorResolver = $urlGeneratorResolver;
            return $this;
        }
    }
    PHP;
file_put_contents($framework.'/LocalFilesystemAdapter.php', $localAdapter);
file_put_contents($framework.'/AwsS3V3Adapter.php', <<<'PHP'
    <?php
    namespace Illuminate\Filesystem;
    class AwsS3V3Adapter extends FilesystemAdapter {}
    PHP);
file_put_contents($framework.'/ReadThroughFilesystem.php', <<<'PHP'
    <?php
    namespace Illuminate\Filesystem;
    class ReadThroughFilesystem extends FilesystemAdapter {}
    PHP);
mkdir($workspace.'/vendor/laravel/framework/src/Illuminate/Contracts/Filesystem', 0777, true);
file_put_contents($workspace.'/vendor/laravel/framework/src/Illuminate/Contracts/Filesystem/Filesystem.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Filesystem;
    interface Filesystem {}
    PHP);
file_put_contents($workspace.'/vendor/laravel/framework/src/Illuminate/Contracts/Filesystem/Cloud.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Filesystem;
    interface Cloud extends Filesystem {}
    PHP);

$manager = <<<'PHP'
    <?php
    namespace Illuminate\Filesystem;
    use Aws\S3\S3Client;
    use Illuminate\Contracts\Filesystem\Filesystem;
    use InvalidArgumentException;
    use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
    use League\Flysystem\AwsS3V3\PortableVisibilityConverter as AwsS3PortableVisibilityConverter;
    use League\Flysystem\Ftp\FtpAdapter;
    use League\Flysystem\Ftp\FtpConnectionOptions;
    use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;
    use League\Flysystem\PhpseclibV3\SftpAdapter;
    use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
    use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
    use League\Flysystem\Visibility;
    use function Illuminate\Support\enum_value;
    /**
     * @mixin \Illuminate\Contracts\Filesystem\Filesystem
     * @mixin \Illuminate\Filesystem\FilesystemAdapter
     */
    class FilesystemManager {
        /** @return Filesystem */
        public function disk($name = null) {
            $name = enum_value($name) ?: $this->getDefaultDriver();
            return $this->disks[$name] = $this->get($name);
        }
        /** @return Filesystem */
        public function createFtpDriver(array $config) {
            if (! isset($config['root'])) { $config['root'] = ''; }
            $adapter = new FtpAdapter(FtpConnectionOptions::fromArray($config));
            return new FilesystemAdapter($this->createFlysystem($adapter, $config), $adapter, $config);
        }
        /** @return Filesystem */
        public function createSftpDriver(array $config) {
            $provider = SftpConnectionProvider::fromArray($config);
            $root = $config['root'] ?? '';
            $visibility = PortableVisibilityConverter::fromArray($config['permissions'] ?? []);
            $adapter = new SftpAdapter($provider, $root, $visibility);
            return new FilesystemAdapter($this->createFlysystem($adapter, $config), $adapter, $config);
        }
        /** @return \Illuminate\Contracts\Filesystem\Cloud */
        public function createS3Driver(array $config) {
            $s3Config = $this->formatS3Config($config);
            $root = (string) ($s3Config['root'] ?? '');
            $visibility = new AwsS3PortableVisibilityConverter($config['visibility'] ?? Visibility::PUBLIC);
            $streamReads = $s3Config['stream_reads'] ?? false;
            $client = new S3Client($s3Config);
            $adapter = new S3Adapter($client, $s3Config['bucket'], $root, $visibility, null, $config['options'] ?? [], $streamReads);
            return new AwsS3V3Adapter($this->createFlysystem($adapter, $config), $adapter, $s3Config, $client);
        }
        /** @return Filesystem */
        public function createReadThroughDriver(array $config, string $name = 'read-through') {
            if (empty($config['primary'])) {
                throw new InvalidArgumentException('Read-through disk is missing "primary" configuration option.');
            } elseif (empty($config['fallback'])) {
                throw new InvalidArgumentException('Read-through disk is missing "fallback" configuration option.');
            } elseif ($config['primary'] === $config['fallback']) {
                throw new InvalidArgumentException('Read-through disk requires distinct "primary" and "fallback" disks.');
            } elseif ($config['primary'] === $name || $config['fallback'] === $name) {
                throw new InvalidArgumentException("Read-through disk [{$name}] cannot reference itself.");
            }
            $primary = is_array($config['primary']) ? $this->build($config['primary']) : $this->disk($config['primary']);
            $fallback = is_array($config['fallback']) ? $this->build($config['fallback']) : $this->disk($config['fallback']);
            $adapter = new ReadThroughFilesystemAdapter(
                $primary->getDriver(), $fallback->getDriver(),
                $config['throw_on_promotion_failure'] ?? false, $config['copy'] ?? true,
            );
            return new ReadThroughFilesystem(
                $this->createFlysystem($adapter, $config),
                $primary->getAdapter(),
                array_replace($primary->getConfig(), $config),
                $primary,
                $fallback,
            );
        }
        /** @return Filesystem */
        public function createLocalDriver(array $config, string $name = 'local') {
            $visibility = PortableVisibilityConverter::fromArray(
                $config['permissions'] ?? [],
                $config['directory_visibility'] ?? $config['visibility'] ?? Visibility::PRIVATE
            );
            $links = ($config['links'] ?? null) === 'skip'
                ? LocalAdapter::SKIP_LINKS
                : LocalAdapter::DISALLOW_LINKS;
            $adapter = new LocalAdapter($config['root'], $visibility, $config['lock'] ?? LOCK_EX, $links);
            return (new LocalFilesystemAdapter(
                $this->createFlysystem($adapter, $config), $adapter, $config
            ))->diskName($name)->shouldServeSignedUrls(
                $config['serve'] ?? false,
                fn () => $this->app['url'],
            );
        }
    }
    class CustomManager extends FilesystemManager {
        /** @return Filesystem */
        public function createFtpDriver(array $config) { return new FilesystemAdapter(null, null, $config); }
    }
    PHP;
file_put_contents($framework.'/FilesystemManager.php', $manager);
$source = <<<'PHP'
    <?php
    use Illuminate\Filesystem\FilesystemManager;
    use Illuminate\Filesystem\CustomManager;
    use Illuminate\Filesystem\LocalFilesystemAdapter;
    use Illuminate\Filesystem\FilesystemAdapter;
    use Illuminate\Filesystem\AwsS3V3Adapter;
    use Illuminate\Filesystem\ReadThroughFilesystem;
    /** @return LocalFilesystemAdapter */
    function localFactory(FilesystemManager $manager) { return $manager->createLocalDriver(['root' => '/tmp']); }
    /** @return FilesystemAdapter */
    function ftpFactory(FilesystemManager $manager) { return $manager->createFtpDriver([]); }
    /** @return FilesystemAdapter */
    function sftpFactory(FilesystemManager $manager) { return $manager->createSftpDriver([]); }
    /** @return AwsS3V3Adapter */
    function s3Factory(FilesystemManager $manager) { return $manager->createS3Driver(['bucket' => 'test']); }
    /** @return ReadThroughFilesystem */
    function readThroughFactory(FilesystemManager $manager) { return $manager->createReadThroughDriver(['primary' => 'one', 'fallback' => 'two']); }
    /** @return LocalFilesystemAdapter */
    function diskStaysBroad(FilesystemManager $manager) { return $manager->disk('local'); }
    /** @return LocalFilesystemAdapter */
    function subclassStaysBroad(CustomManager $manager) { return $manager->createLocalDriver(['root' => '/tmp']); }
    PHP;
file_put_contents($workspace.'/cases.php', $source);

$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => ['vendor/laravel/framework/src/Illuminate'],
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
            'workers' => 1,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$analyze = static function (string $label, int $expectedCount) use ($command, $workspace): void {
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
        throw new RuntimeException('Cannot start Mago for '.$label.'.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $issues = array_map(static fn (array $issue): string => $issue['code'], $report['issues'] ?? []);
    if (
        $exit !== 1
        || count($issues) !== $expectedCount
        || (array_count_values($issues)['less-specific-return-statement'] ?? 0) !== $expectedCount
    ) {
        throw new RuntimeException($label.': unexpected diagnostics '.json_encode($issues).'; inspect '.$workspace);
    }
};

$analyze('native factories', 2);
$factoryReturn = 'return new FilesystemAdapter($this->createFlysystem($adapter, $config), $adapter, $config);';
$position = strpos($manager, $factoryReturn);
if ($position === false) {
    throw new RuntimeException('Missing native FTP factory return in fixture.');
}
$generatorManager = substr_replace($manager, 'yield null; '.$factoryReturn, $position, strlen($factoryReturn));
file_put_contents($framework.'/FilesystemManager.php', $generatorManager);
$analyze('generator factory', 3);
file_put_contents($framework.'/FilesystemManager.php', $manager);

$changedNativeReturn = str_replace(
    'function createFtpDriver(array $config)',
    'function createFtpDriver(array $config): string',
    $manager,
);
file_put_contents($framework.'/FilesystemManager.php', $changedNativeReturn);
$analyze('changed native return type', 3);
file_put_contents($framework.'/FilesystemManager.php', $manager);

$fluentStart = strpos($manager, '->shouldServeSignedUrls(');
$fluentEnd = $fluentStart === false ? false : strpos($manager, ');', $fluentStart);
if ($fluentStart === false || $fluentEnd === false) {
    throw new RuntimeException('Missing native local factory fluent call.');
}
$firstClassManager = substr_replace(
    $manager,
    '->shouldServeSignedUrls(...)',
    $fluentStart,
    $fluentEnd - $fluentStart + 1,
);
file_put_contents($framework.'/FilesystemManager.php', $firstClassManager);
$analyze('first-class fluent callable', 3);
file_put_contents($framework.'/FilesystemManager.php', $manager);

$changedFluent = str_replace('/** @return $this */', '/** @return FilesystemAdapter */', $localAdapter);
file_put_contents($framework.'/LocalFilesystemAdapter.php', $changedFluent);
$analyze('changed fluent PHPDoc', 3);
file_put_contents($framework.'/LocalFilesystemAdapter.php', $localAdapter);

$extraFluentDoc = str_replace(
    '/** @return $this */',
    '/** @return $this @phpstan-return FilesystemAdapter */',
    $localAdapter,
);
file_put_contents($framework.'/LocalFilesystemAdapter.php', $extraFluentDoc);
$analyze('extra fluent PHPDoc', 3);
file_put_contents($framework.'/LocalFilesystemAdapter.php', $localAdapter);

$classDoc = str_replace(
    'class FilesystemManager {',
    '/** @method Filesystem createFtpDriver(array $config) */ class FilesystemManager {',
    $manager,
);
file_put_contents($framework.'/FilesystemManager.php', $classDoc);
$analyze('class method PHPDoc', 7);
file_put_contents($framework.'/FilesystemManager.php', $manager);

$customMixin = str_replace(
    ' * @mixin \\Illuminate\\Filesystem\\FilesystemAdapter',
    ' * @mixin \\Illuminate\\Filesystem\\FilesystemAdapter'."\n".' * @mixin \\Custom\\StorageMixin',
    $manager,
);
file_put_contents($framework.'/FilesystemManager.php', $customMixin);
$analyze('custom class mixin', 7);
file_put_contents($framework.'/FilesystemManager.php', $manager);

$localClassDoc = str_replace(
    'class LocalFilesystemAdapter extends FilesystemAdapter {',
    '/** @method FilesystemAdapter diskName(string $disk) */ class LocalFilesystemAdapter extends FilesystemAdapter {',
    $localAdapter,
);
file_put_contents($framework.'/LocalFilesystemAdapter.php', $localClassDoc);
$analyze('local adapter class method PHPDoc', 3);
file_put_contents($framework.'/LocalFilesystemAdapter.php', $localAdapter);

$config['extension-hosts'] = new stdClass;
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$analyze('disabled baseline', 7);

$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if (
    $resolved === false
    || $temporary === false
    || ! str_starts_with(
        strtolower($resolved),
        strtolower($temporary.DIRECTORY_SEPARATOR.'laramago filesystem factories '),
    )
) {
    throw new RuntimeException('Refusing to remove a fixture outside the temporary directory.');
}
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($files as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
}
rmdir($resolved);
echo
    "PASS: native factories refine; generator, changed PHPDoc, disk and subclass stay broad; disabled baseline stays broad\n"
;
