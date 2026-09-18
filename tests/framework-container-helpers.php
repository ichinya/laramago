<?php

declare(strict_types=1);

// Check opt-in static container bindings through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));

/** @param array<string, array{string, string, list<string>}> $cases */
function runContainerCases(
    string $name,
    array $cases,
    bool $enabled,
    ?string $extraCatalog = null,
    bool $customHelper = false,
    bool $alteredHelperDoc = false,
    bool $alteredMakeDoc = false,
    bool $invalidBindingMetadata = false,
): void {
    global $command, $package;
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago container '.$name.' '.bin2hex(random_bytes(8));
    $framework = $workspace.'/dependencies with spaces/laravel/framework/src/Illuminate';
    mkdir($framework.'/Contracts/Container', 0777, true);
    mkdir($framework.'/Container', 0777, true);
    mkdir($framework.'/Foundation', 0777, true);
    mkdir($framework.'/Support/Facades', 0777, true);
    mkdir($workspace.'/bootstrap', 0777, true);
    copy(__DIR__.'/fixtures/analysis/container-bindings.php.stub', $workspace.'/bootstrap/bindings.php');

    file_put_contents($framework.'/Contracts/Container/Container.php', <<<'PHP'
        <?php
        namespace Illuminate\Contracts\Container;
        interface Container {
            /**
             * @template TClass of object
             * @param string|class-string<TClass> $abstract
             * @return ($abstract is class-string<TClass> ? TClass : mixed)
             */
            public function make($abstract, array $parameters = []);
        }
        PHP);
    $containerSource = <<<'PHP'
        <?php
        namespace Illuminate\Container;
        class Container implements \Illuminate\Contracts\Container\Container {
            public static function getInstance(): static { throw new \RuntimeException('Do not resolve containers.'); }
            __MAKE_DOC__
            public function make($abstract, array $parameters = []) { throw new \RuntimeException('Do not make services.'); }
            public function getAlias($abstract) { return $abstract; }
            public function resolve($abstract) { throw new \RuntimeException(); }
            public function bind($abstract, $concrete = null, $shared = false): void { throw new \RuntimeException('Do not bind.'); }
            public function singleton($abstract, $concrete = null): void { throw new \RuntimeException('Do not bind.'); }
            public function alias($abstract, $alias): void { throw new \RuntimeException('Do not alias.'); }
            public function when($concrete): mixed { throw new \RuntimeException('Do not configure context.'); }
        }
        PHP;
    $makeDoc = $alteredMakeDoc
        ? '/** @return \\ContainerFixtures\\WrongService */'
        : <<<'PHP'
            /**
             * @template TClass of object
             * @param string|class-string<TClass> $abstract
             * @return ($abstract is class-string<TClass> ? TClass : mixed)
             */
            PHP;
    file_put_contents($framework.'/Container/Container.php', str_replace('__MAKE_DOC__', $makeDoc, $containerSource));
    file_put_contents($framework.'/Support/Facades/Facade.php', <<<'PHP'
        <?php
        namespace Illuminate\Support\Facades;
        abstract class Facade {
            /** @return mixed */
            public static function getFacadeRoot() { throw new \RuntimeException('Do not resolve facades.'); }
            /** @return mixed */
            protected static function resolveFacadeInstance($name) { throw new \RuntimeException('Do not resolve facades.'); }
            /** @return mixed */
            protected static function getFacadeAccessor() { throw new \RuntimeException('Do not execute accessors.'); }
            public static function __callStatic(string $method, array $arguments): mixed { throw new \RuntimeException('Do not dispatch facades.'); }
        }
        PHP);
    $helpers = match (true) {
        $customHelper => <<<'PHP'
            <?php
            function app($abstract = null, array $parameters = []): int { return 1; }
            function resolve($name, array $parameters = []): int { return 1; }
            PHP,
        $alteredHelperDoc => <<<'PHP'
            <?php
            /** @return \ContainerFixtures\WrongService */
            function app($abstract = null, array $parameters = []) { throw new \RuntimeException('Do not resolve helpers.'); }
            /** @return \ContainerFixtures\WrongService */
            function resolve($name, array $parameters = []) { throw new \RuntimeException('Do not resolve helpers.'); }
            PHP,
        default => <<<'PHP'
            <?php
            /**
             * @template TClass of object
             * @param string|class-string<TClass>|null $abstract
             * @return ($abstract is class-string<TClass> ? TClass : ($abstract is null ? \Illuminate\Container\Container : mixed))
             */
            function app($abstract = null, array $parameters = []) { throw new \RuntimeException('Do not resolve helpers.'); }
            /**
             * @template TClass of object
             * @param string|class-string<TClass> $name
             * @return ($name is class-string<TClass> ? TClass : mixed)
             */
            function resolve($name, array $parameters = []) { throw new \RuntimeException('Do not resolve helpers.'); }
            PHP,
    };
    $helperPath = $customHelper ? $workspace.'/custom/helpers.php' : $framework.'/Foundation/helpers.php';
    if ($customHelper) {
        mkdir(dirname($helperPath), 0777, true);
    }
    file_put_contents($helperPath, $helpers);
    if ($extraCatalog !== null) {
        file_put_contents($workspace.'/bootstrap/contextual.php', $extraCatalog);
    }

    $source = "<?php\n";
    $lines = [];
    foreach ($cases as $case => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = [$case, $codes];
    }
    file_put_contents($workspace.'/cases.php', $source);
    $bindingFiles = $enabled ? ['bootstrap/bindings.php'] : [];
    if ($extraCatalog !== null) {
        $bindingFiles[] = 'bootstrap/contextual.php';
    }
    $bindingConfiguration = $invalidBindingMetadata ? 'bootstrap/bindings.php' : $bindingFiles;
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['binding-files' => $bindingConfiguration]],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($framework.'/Foundation/Application.php', <<<'PHP'
        <?php
        namespace Illuminate\Foundation;
        class Application extends \Illuminate\Container\Container {
            public function registerCoreContainerAliases() {
                foreach (['cache' => [\ContainerFixtures\Service::class], 'session' => [\ContainerFixtures\Service::class], 'router' => [\ContainerFixtures\Service::class]] as $key => $aliases) {
                    foreach ($aliases as $alias) { $this->alias($key, $alias); }
                }
            }
        }
        PHP);
    file_put_contents($workspace.'/custom-application.php', <<<'PHP'
        <?php
        class CustomAliasApplication extends \Illuminate\Foundation\Application {
            public function getAlias($abstract) { return 'custom'; }
        }
        class CustomRegistrationApplication extends \Illuminate\Foundation\Application {
            public function registerCoreContainerAliases() {}
        }
        class CustomResolveApplication extends \Illuminate\Foundation\Application {
            public function resolve($abstract) { return new \stdClass(); }
        }
        PHP);
    $includes = [
        'custom-application.php',
        $framework.'/Foundation/Application.php',
        str_replace('\\', '/', $framework.'/Contracts/Container/Container.php'),
        str_replace('\\', '/', $framework.'/Container/Container.php'),
        str_replace('\\', '/', $framework.'/Support/Facades/Facade.php'),
        str_replace('\\', '/', $helperPath),
        'bootstrap/bindings.php',
    ];
    if ($extraCatalog !== null) {
        $includes[] = 'bootstrap/contextual.php';
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => $includes],
        'extension-hosts' => in_array('--disabled', $GLOBALS['argv'], true)
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
    $expectedExit = array_filter($cases, static fn (array $case): bool => $case[2] !== []) === [] ? 0 : 1;
    if (
        ! in_array($exit, [0, 1], true)
        || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
    ) {
        throw new RuntimeException('Unexpected analyzer exit or extension fallback; inspect '.$workspace);
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
    foreach ($lines as $line => [$case, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $name.' / '.$case.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$name.' / '.$case."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics outside '.$name.' scenarios; inspect '.$workspace);
    }
    removeContainerWorkspace($workspace);
}

function removeContainerWorkspace(string $workspace): void
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

$disabled = in_array('--disabled', $argv, true);
runContainerCases(
    'core',
    [
        'app cache' => [
            'return app("cache")->label();',
            'string',
            $disabled ? ['ambiguous-object-method-access', 'mixed-return-statement'] : [],
        ],
        'resolve session' => [
            'return resolve("session")->label();',
            'string',
            $disabled ? ['ambiguous-object-method-access', 'mixed-return-statement'] : [],
        ],
        'named app router' => [
            'return app(abstract: "router")->label();',
            'string',
            $disabled ? ['ambiguous-object-method-access', 'mixed-return-statement'] : [],
        ],
        'application make' => [
            '$app = new \Illuminate\Foundation\Application(); return $app->make("cache")->label();',
            'string',
            $disabled ? ['ambiguous-object-method-access', 'mixed-return-statement'] : [],
        ],
        'plain container remains unknown' => [
            '$app = new \Illuminate\Container\Container(); $app->make("cache")->label();',
            'void',
            ['ambiguous-object-method-access'],
        ],
        'overridden aliases remain unknown' => [
            '$app = new \CustomAliasApplication(); $app->make("cache")->label();',
            'void',
            ['ambiguous-object-method-access'],
        ],
        'overridden registrations remain unknown' => [
            '$app = new \CustomRegistrationApplication(); $app->make("cache")->label();',
            'void',
            ['ambiguous-object-method-access'],
        ],
        'overridden resolution remains unknown' => [
            '$app = new \CustomResolveApplication(); $app->make("cache")->label();',
            'void',
            ['ambiguous-object-method-access'],
        ],
        'unknown remains unknown' => ['app("unknown")->label();', 'void', ['ambiguous-object-method-access']],
    ],
    false,
);
if (! $disabled) {
    runContainerCases(
        'override',
        [
            'custom binding wins' => ['return app("cache");', '\ContainerFixtures\AlternateService', []],
        ],
        true,
        '<?php app()->bind("cache", \ContainerFixtures\AlternateService::class);',
    );
    runContainerCases(
        'uncertain',
        [
            'uncertain catalog wins' => ['app("cache")->label();', 'void', ['ambiguous-object-method-access']],
        ],
        true,
        '<?php app()->when("x");',
    );
    runContainerCases(
        'native',
        [
            'custom helper wins' => ['return app("cache");', 'int', []],
        ],
        true,
        customHelper: true,
    );
    runContainerCases(
        'phpdoc',
        [
            'PHPDoc helper wins' => ['return app("cache");', '\ContainerFixtures\WrongService', []],
        ],
        true,
        alteredHelperDoc: true,
    );
}
