<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$preset = str_replace('\\', '/', dirname(__DIR__).'/presets/laravel.toml');

foreach (['vendor', 'dependencies with spaces'] as $vendor) {
    $root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago source isolation '.bin2hex(random_bytes(8));
    $files = [
        $vendor
            .'/laravel/framework/Container.php' => '<?php namespace Illuminate\\Contracts\\Container; interface Container { public function bind(string $name, string $class): void; }',
        $vendor
            .'/laravel/framework/Application.php' => '<?php namespace Illuminate\\Contracts\\Foundation; interface Application extends \\Illuminate\\Contracts\\Container\\Container {}',
        $vendor
            .'/ichinya/laramago/src/Implementation.php' => '<?php class PackageImplementation { public function value(): int { return 1; } }',
        $vendor
            .'/ichinya/laramago/tests/Fake.php' => '<?php namespace Illuminate\\Contracts\\Container { interface Container {} } namespace { class FixtureInternalTest {} }',
        $vendor.'/ichinya/laramago/var/Snapshot.php' => '<?php class FixtureInternalResearch {}',
        'tests/Support.php' => '<?php class ApplicationTestSupport { public function value(): int { return 2; } }',
        'app/Example.php' => <<<'PHP'
            <?php
            function exercise(\Illuminate\Contracts\Foundation\Application $app): void {
                $app->bind('service', \stdClass::class);
                $app->missing();
            }
            function support(ApplicationTestSupport $support, PackageImplementation $implementation): int {
                return $support->value() + $implementation->value();
            }
            function excludedTest(): object { return new FixtureInternalTest(); }
            function excludedResearch(): object { return new FixtureInternalResearch(); }
            PHP,
    ];
    foreach ($files as $file => $contents) {
        $path = $root.'/'.$file;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }
    file_put_contents($root.'/mago.json', json_encode([
        'extends' => $preset,
        'php-version' => '8.2',
        'source' => ['paths' => ['app', 'tests'], 'includes' => [$vendor]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $root, 'analyze', '--no-extensions', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $root.'/stderr.log', 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Mago.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $messages = implode("\n", array_column($report['issues'], 'message'));
    if (
        $exit !== 1
        || ! str_contains(strtolower($messages), 'fixtureinternaltest')
        || ! str_contains(strtolower($messages), 'fixtureinternalresearch')
        || ! str_contains(strtolower($messages), 'missing')
        || str_contains(strtolower($messages), '`bind`')
        || str_contains(strtolower($messages), 'applicationtestsupport')
        || str_contains(strtolower($messages), 'packageimplementation')
    ) {
        throw new RuntimeException('Package source isolation failed: '.$root."\n".$messages);
    }
    echo 'PASS: package internals excluded; application tests and package source retained ('.$vendor.")\n";
}
