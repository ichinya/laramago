<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago binding notes '.bin2hex(random_bytes(8));
mkdir($workspace.'/vendor/laravel/framework/src/Illuminate/Container', 0777, true);
file_put_contents($workspace.'/vendor/laravel/framework/src/Illuminate/Container/Container.php', <<<'PHP'
    <?php
    namespace Illuminate\Container;
    class Container {
        public function bind(string $abstract, mixed $concrete): void {}
        public function singleton(string $abstract, mixed $concrete): void {}
        public function scoped(string $abstract, mixed $concrete): void {}
    }
    PHP);
file_put_contents($workspace.'/types.php', <<<'PHP'
    <?php
    interface Service {}
    class Good implements Service {}
    class Bad {}
    class Incomplete extends UnknownParent {}
    class Custom extends \Illuminate\Container\Container {
        public function bind(string $abstract, mixed $concrete): void {}
    }
    /** @method void bind(string $abstract, mixed $concrete) */
    class Documented extends \Illuminate\Container\Container {}
    class Unrelated {
        public function bind(string $abstract, mixed $concrete): void {}
    }
    PHP);
file_put_contents($workspace.'/calls.php', <<<'PHP'
    <?php
    function cases(\Illuminate\Container\Container $app, Custom $custom, Unrelated $other, Documented $documented, string $dynamic): void {
        $app->bind(Service::class, Bad::class);
        $app->singleton(abstract: Service::class, concrete: Bad::class);
        $app->scoped(Service::class, Bad::class);
        $app->bind(Service::class, Good::class);
        $app->bind(Service::class, Incomplete::class);
        $app->bind($dynamic, Bad::class);
        $app->bind(Service::class, static fn (): Bad => new Bad());
        $custom->bind(Service::class, Bad::class);
        $other->bind(Service::class, Bad::class);
    $documented->bind(Service::class, Bad::class);
        $app->bind(SERVICE::class, GOOD::class);
    }
    PHP);
$policy = static function (mixed $enabled, array $files = ['types.php', 'calls.php']) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'binding-compatibility-candidates' => ['diagnose' => $enabled, 'files' => $files],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
};
$policy(true);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$analyze = static function (array $paths) use ($command, $package, $workspace): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => [
            'paths' => $paths,
            'includes' => ['vendor/laravel/framework/src/Illuminate/Container/Container.php'],
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
        ! in_array($exit, [0, 1], true)
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            $log,
        )
    ) {
        throw new RuntimeException('Mago or the extension worker failed; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_filter(
        $report['issues'] ?? [],
        static fn (array $issue): bool => (
            ($issue['code'] ?? null) === 'ichinya/laramago/laramago-binding-compatibility-candidate'
        ),
    ));
};

$issues = $analyze(['types.php', 'calls.php']);
if (count($issues) !== 3) {
    throw new RuntimeException(
        'Expected three native declaration candidates; inspect '.$workspace.' '.json_encode($issues),
    );
}
foreach ($issues as $issue) {
    if (
        strtolower($issue['level']) !== 'note'
        || ! str_contains($issue['message'], 'does not prove a container runtime failure')
    ) {
        throw new RuntimeException('Declaration candidates must remain notes.');
    }
}
echo
    "PASS: native bind/singleton/scoped emit declaration notes; compatible, dynamic, custom factory, incomplete and custom receiver cases defer\n"
;
if (count($analyze(['calls.php'])) !== 3) {
    throw new RuntimeException('Explicitly selected supporting declarations should work during single-file analysis.');
}
echo "PASS: explicit source catalog works during single-file analysis\n";
$policy(false);
if ($analyze(['types.php', 'calls.php']) !== []) {
    throw new RuntimeException('Disabled policy emitted notes.');
}
$policy('true');
if ($analyze(['types.php', 'calls.php']) !== []) {
    throw new RuntimeException('Malformed policy emitted notes.');
}
echo "PASS: explicit boolean opt-in required\n";
$policy(true, ['types.php', 'calls.php', 'missing.php']);
if ($analyze(['types.php', 'calls.php']) !== []) {
    throw new RuntimeException('Incomplete source selection emitted notes.');
}
echo "PASS: missing selected source defers notes\n";

$policy(true);
$hook = new \Ichinya\Laramago\Analyzer\BindingCompatibilityCandidatesHook($workspace);
file_put_contents($workspace.'/types.php', "\n// Changed after the snapshot.\n", FILE_APPEND);
$current = new ReflectionMethod($hook, 'currentHierarchy');
if ($current->invoke($hook) !== false) {
    throw new RuntimeException('Changed hierarchy must invalidate the snapshot.');
}
echo "PASS: stale hierarchy hash defers compatibility notes\n";

// Only remove known synthetic files; leave the workspace on any failed assertion.
foreach ([
    'types.php',
    'calls.php',
    'composer.json',
    'mago.json',
    'report.json',
    'stderr.log',
    'vendor/laravel/framework/src/Illuminate/Container/Container.php',
] as $file) {
    unlink($workspace.'/'.$file);
}
foreach ([
    'vendor/laravel/framework/src/Illuminate/Container',
    'vendor/laravel/framework/src/Illuminate',
    'vendor/laravel/framework/src',
    'vendor/laravel/framework',
    'vendor/laravel',
    'vendor',
    '',
] as $directory) {
    rmdir($workspace.($directory === '' ? '' : '/'.$directory));
}
