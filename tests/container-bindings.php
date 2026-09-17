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
    $includes = [
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
        $exit !== $expectedExit
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

$enabled = [
    'app interface binding' => [
        'return app(\\ContainerFixtures\\ServiceContract::class);',
        '\\ContainerFixtures\\Service',
        [],
    ],
    'resolve abstract binding' => [
        'return resolve(\\ContainerFixtures\\AbstractService::class);',
        '\\ContainerFixtures\\AbstractImplementation',
        [],
    ],
    'literal alias binding' => ["return app('service.alias');", '\\ContainerFixtures\\Service', []],
    'chained alias binding' => ["return resolve('service.deep');", '\\ContainerFixtures\\Service', []],
    'static container catalog' => ["return app('static.service');", '\\ContainerFixtures\\Service', []],
    'named app arguments' => [
        'return app(parameters: [], abstract: \\ContainerFixtures\\ServiceContract::class);',
        '\\ContainerFixtures\\Service',
        [],
    ],
    'named resolve arguments' => [
        "return resolve(parameters: [], name: 'service.alias');",
        '\\ContainerFixtures\\Service',
        [],
    ],
    'case insensitive app helper' => [
        'return APP(\\ContainerFixtures\\ServiceContract::class);',
        '\\ContainerFixtures\\Service',
        [],
    ],
    'case insensitive resolve helper' => ["return RESOLVE('service.alias');", '\\ContainerFixtures\\Service', []],
    'native concrete make' => [
        '$container = new \\Illuminate\\Container\\Container(); return $container->make(\\ContainerFixtures\\ServiceContract::class);',
        '\\ContainerFixtures\\Service',
        [],
    ],
    'native contract make' => [
        'return (function (\\Illuminate\\Contracts\\Container\\Container $container) { return $container->make(\\ContainerFixtures\\ServiceContract::class); })(new \\Illuminate\\Container\\Container());',
        '\\ContainerFixtures\\Service',
        [],
    ],
    'native named make' => [
        '$container = new \\Illuminate\\Container\\Container(); return $container->make(parameters: [], abstract: \\ContainerFixtures\\ServiceContract::class);',
        '\\ContainerFixtures\\Service',
        [],
    ],
    'facade contract root' => [
        'return \\ContainerFixtures\\ContractFacade::getFacadeRoot();',
        '\\ContainerFixtures\\Service|null',
        [],
    ],
    'facade alias root' => [
        'return \\ContainerFixtures\\AliasFacade::getFacadeRoot();',
        '\\ContainerFixtures\\Service|null',
        [],
    ],
    'facade contract call' => ['return \\ContainerFixtures\\ContractFacade::label("bound");', 'string', []],
    'facade alias call' => ['return \\ContainerFixtures\\AliasFacade::label();', 'string', []],
    'facade wrong argument retained' => [
        '\\ContainerFixtures\\AliasFacade::label(new \\stdClass());',
        'void',
        ['invalid-argument'],
    ],
    'wrong implementation rejected' => [
        'return app(\\ContainerFixtures\\WrongContract::class);',
        '\\ContainerFixtures\\WrongService',
        ['invalid-return-statement'],
    ],
    'unknown alias rejected' => [
        "return resolve('unknown.alias');",
        '\\ContainerFixtures\\Service',
        ['mixed-return-statement'],
    ],
    'alias cycle rejected' => [
        "return app('cycle.first');",
        '\\ContainerFixtures\\Service',
        ['mixed-return-statement'],
    ],
    'conditional alias reassignment rejected' => [
        "return app('portal');",
        '\\ContainerFixtures\\Service',
        ['less-specific-return-statement'],
    ],
    'conflicting binding rejected' => [
        'return app(\\ContainerFixtures\\ConflictContract::class);',
        '\\ContainerFixtures\\ConflictImplementation',
        ['less-specific-return-statement'],
    ],
    'conditional binding rejected' => [
        'return app(\\ContainerFixtures\\ConditionalContract::class);',
        '\\ContainerFixtures\\ConditionalImplementation',
        ['less-specific-return-statement'],
    ],
    'runtime binding rejected' => [
        'return app(\\ContainerFixtures\\RuntimeContract::class);',
        '\\ContainerFixtures\\RuntimeImplementation',
        ['less-specific-return-statement'],
    ],
    'closure binding rejected' => [
        'return app(\\ContainerFixtures\\ClosureContract::class);',
        '\\ContainerFixtures\\ClosureImplementation',
        ['less-specific-return-statement'],
    ],
    'generic implementation rejected' => [
        'return app(\\ContainerFixtures\\GenericContract::class);',
        '\\ContainerFixtures\\GenericService',
        ['invalid-return-statement'],
    ],
    'unknown alias facade retained' => [
        '\\ContainerFixtures\\UnknownAliasFacade::label();',
        'void',
        ['non-documented-method'],
    ],
    'wrong facade binding retained' => [
        '\\ContainerFixtures\\WrongFacade::label();',
        'void',
        ['non-documented-method'],
    ],
    'custom facade resolver retained' => [
        '\\ContainerFixtures\\CustomResolverFacade::label();',
        'void',
        ['non-documented-method'],
    ],
    'custom facade dispatcher retained' => [
        '\\ContainerFixtures\\CustomDispatcherFacade::label();',
        'void',
        ['non-documented-method'],
    ],
    'native facade method preserved' => ['return \\ContainerFixtures\\NativeFacade::label();', 'int', []],
    'documented facade method preserved' => ['return \\ContainerFixtures\\DocumentedFacade::label();', 'int', []],
    'by-reference facade method retained' => [
        '$value = "before"; \\ContainerFixtures\\ContractFacade::touch($value);',
        'void',
        ['non-documented-method'],
    ],
    'custom container override declines catalog' => [
        '$container = new \\ContainerFixtures\\CustomContainer(); return $container->make(\\ContainerFixtures\\ServiceContract::class);',
        '\\ContainerFixtures\\Service',
        ['less-specific-return-statement'],
    ],
];
runContainerCases('enabled', $enabled, true);

$disabled = [
    'binding catalog disabled' => [
        'return app(\\ContainerFixtures\\ServiceContract::class);',
        '\\ContainerFixtures\\Service',
        ['less-specific-return-statement'],
    ],
    'alias catalog disabled' => [
        "return resolve('service.alias');",
        '\\ContainerFixtures\\Service',
        ['mixed-return-statement'],
    ],
    'class accessor remains native' => ['return \\ContainerFixtures\\ContractFacade::label();', 'string', []],
    'alias facade catalog disabled' => [
        '\\ContainerFixtures\\AliasFacade::label();',
        'void',
        ['non-documented-method'],
    ],
];
runContainerCases('disabled', $disabled, false);

$contextual = <<<'PHP'
    <?php
    app()->when(\ContainerFixtures\Service::class)->needs(\ContainerFixtures\ServiceContract::class)->give(\ContainerFixtures\AlternateService::class);
    PHP;
runContainerCases(
    'contextual',
    [
        'contextual mutation disables catalog' => [
            'return app(\\ContainerFixtures\\ServiceContract::class);',
            '\\ContainerFixtures\\Service',
            ['less-specific-return-statement'],
        ],
    ],
    true,
    $contextual,
);

$localApp = <<<'PHP'
    <?php
    namespace LocalCatalog;
    final class LocalContainer { public function bind($abstract, $concrete): void {} }
    function app(): LocalContainer { return new LocalContainer(); }
    app()->bind(\ContainerFixtures\ServiceContract::class, \ContainerFixtures\AlternateService::class);
    PHP;
runContainerCases(
    'namespaced-local-app',
    [
        'namespaced local app is not a catalog receiver' => [
            'return app(\\ContainerFixtures\\ServiceContract::class);',
            '\\ContainerFixtures\\Service',
            ['less-specific-return-statement'],
        ],
    ],
    true,
    $localApp,
);

$dynamicRegistration = <<<'PHP'
    <?php
    $arguments = [\ContainerFixtures\ServiceContract::class, \ContainerFixtures\AlternateService::class];
    app()->bind(...$arguments);
    PHP;
runContainerCases(
    'dynamic-registration',
    [
        'unpacked registration disables catalog' => [
            'return app(\\ContainerFixtures\\ServiceContract::class);',
            '\\ContainerFixtures\\Service',
            ['less-specific-return-statement'],
        ],
    ],
    true,
    $dynamicRegistration,
);

$firstClassRegistration = <<<'PHP'
    <?php
    app()->bind(...);
    PHP;
runContainerCases(
    'first-class-registration',
    [
        'first-class registration disables catalog' => [
            'return app(\\ContainerFixtures\\ServiceContract::class);',
            '\\ContainerFixtures\\Service',
            ['less-specific-return-statement'],
        ],
    ],
    true,
    $firstClassRegistration,
);

runContainerCases(
    'custom-helper',
    [
        'custom app helper preserved' => ['return app(\\ContainerFixtures\\ServiceContract::class);', 'int', []],
        'custom resolve helper preserved' => [
            'return resolve(\\ContainerFixtures\\ServiceContract::class);',
            'int',
            [],
        ],
    ],
    true,
    customHelper: true,
);

runContainerCases(
    'altered-helper-docblock',
    [
        'concrete app PHPDoc preserved' => [
            'return app(\\ContainerFixtures\\ServiceContract::class);',
            '\\ContainerFixtures\\WrongService',
            [],
        ],
        'concrete resolve PHPDoc preserved' => [
            'return resolve(\\ContainerFixtures\\ServiceContract::class);',
            '\\ContainerFixtures\\WrongService',
            [],
        ],
    ],
    true,
    alteredHelperDoc: true,
);

runContainerCases(
    'altered-make-docblock',
    [
        'concrete make PHPDoc preserved' => [
            '$container = new \\Illuminate\\Container\\Container(); return $container->make(\\ContainerFixtures\\ServiceContract::class);',
            '\\ContainerFixtures\\WrongService',
            [],
        ],
    ],
    true,
    alteredMakeDoc: true,
);

runContainerCases(
    'invalid-binding-metadata',
    [
        'non-list binding metadata disables inference' => [
            'return app(\\ContainerFixtures\\ServiceContract::class);',
            '\\ContainerFixtures\\Service',
            ['less-specific-return-statement'],
        ],
    ],
    true,
    invalidBindingMetadata: true,
);
