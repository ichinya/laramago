<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago class aliases '.bin2hex(random_bytes(8));
$framework = $workspace.'/laravel/framework';
foreach (['config', 'src/Illuminate/Support/Facades', 'src/Illuminate/Support'] as $folder) {
    if (! is_dir($workspace.'/'.$folder)) {
        mkdir($workspace.'/'.$folder, 0777, true);
    }
    if (! is_dir($framework.'/'.$folder)) {
        mkdir($framework.'/'.$folder, 0777, true);
    }
}
file_put_contents($framework.'/src/Illuminate/Support/Str.php', <<<'PHP'
    <?php
    namespace Illuminate\Support;
    class Str {
        public static function random($length = 16) {}
    }
    PHP);
file_put_contents($framework.'/src/Illuminate/Support/Facades/Facade.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Str;
    class Facade {
        public static function defaultAliases()
        {
            return new Collection([
                'Str' => Str::class,
                'FacadeProbe' => FacadeProbe::class,
            ]);
        }
    }
    class FacadeProbe {
        public static function ping(): int { return 1; }
    }
    PHP);
$frameworkConfigPath = $framework.'/config/app.php';
file_put_contents($frameworkConfigPath, <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Facade;
    return [
        'aliases' => Facade::defaultAliases()->merge([
            'Merged' => \Illuminate\Support\Str::class,
        ])->toArray(),
    ];
    PHP);
$projectConfigPath = $workspace.'/config/app.php';
file_put_contents($projectConfigPath, <<<'PHP'
    <?php
    return [
        'name' => 'probe',
    ];
    PHP);
$plainCases = [
    'alias-random' => '$t = \Str::random(32);',
    'alias-merged' => '$m = \Merged::random(8);',
    'alias-same-namespace' => '$p = \FacadeProbe::ping();',
    'alias-missing-method' => '\Str::nonExistentAnything();',
    'not-aliased' => '\Unaliased::whatever();',
];
$writeCases = static function (array $cases) use ($workspace): void {
    file_put_contents($workspace.'/cases.php', "<?php\n".implode("\n", array_values($cases))."\n");
};
$writeCases($plainCases);
$lines = [];
$line = 2;
foreach ($plainCases as $name => $_) {
    $lines[$name] = $line++;
}

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (bool $disabled) use ($workspace, $package, $command): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.5',
        'source' => ['paths' => ['cases.php'], 'includes' => [$workspace.'/laravel']],
        'extension-hosts' => $disabled ? new stdClass : [
            'laramago' => [
                'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => 1,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/report.json', 'w'],
        2 => ['file', $workspace.'/stderr.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Mago.');
    }
    fclose($pipes[0]);
    proc_close($process);
    $stderr = file_get_contents($workspace.'/stderr.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request|analyzer issue-filter hook .* failed/i', $stderr)) {
        throw new RuntimeException('Provider failure: '.$stderr);
    }
    return json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$summarize = static function (array $issues): array {
    $result = [];
    foreach ($issues as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
        if ($primary['span']['file_id']['name'] === 'cases.php') {
            $result[$primary['span']['start']['line'] + 1][] = $issue['code'];
        }
    }
    foreach ($result as &$codes) {
        sort($codes);
    }
    return $result;
};

$native = $summarize($run(true));
$adapted = $summarize($run(false));
foreach ($lines as $name => $line) {
    $actual = $adapted[$line] ?? [];
    $baseline = $native[$line] ?? [];
    if (in_array($name, ['alias-random', 'alias-merged', 'alias-same-namespace'], true)) {
        // The false positive is removed. The call itself stays unresolved, so the
        // native mixed diagnostics on the same line honestly remain.
        if (
            $actual === $baseline
            || in_array('non-existent-method', $actual, true)
            || ! in_array('non-existent-method', $baseline, true)
        ) {
            throw new RuntimeException($name.' should lose its false positive; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif (in_array($name, ['alias-missing-method', 'not-aliased'], true)) {
        if ($actual !== $baseline || ! in_array('non-existent-method', $baseline, true)) {
            throw new RuntimeException($name.' must keep its real diagnostic; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif ($actual !== $baseline) {
        throw new RuntimeException($name.' must retain native diagnostics; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
    }
    echo 'PASS: '.$name."\n";
}

// The project declaring its own global Str class defeats the boot alias, so the
// diagnostic must survive.
$writeCases([
    'declared-class-shadow' => "class Str {\n    public function something(): void {}\n}\n\$shadow = \Str::random(32);",
]);
$shadowNative = $summarize($run(true));
$shadowAdapted = $summarize($run(false));
if (($shadowAdapted[5] ?? []) !== ($shadowNative[5] ?? []) || ! in_array('non-existent-method', $shadowNative[5] ?? [], true)) {
    throw new RuntimeException('declared-class-shadow must keep its real diagnostic; native '.json_encode($shadowNative[5] ?? []).'; adapted '.json_encode($shadowAdapted[5] ?? []).'; inspect '.$workspace);
}
echo "PASS: declared-class-shadow\n";

// A project config that carries any `aliases` key replaces the base table wholesale,
// so the filter must disable itself entirely.
$writeCases($plainCases);
file_put_contents($projectConfigPath, <<<'PHP'
    <?php
    return [
        'aliases' => ['Str' => App\Fake\Str::class],
    ];
    PHP);
$disabledConfig = $summarize($run(false));
foreach (['alias-random', 'alias-merged', 'alias-same-namespace'] as $name) {
    if (($disabledConfig[$lines[$name]] ?? []) !== ($native[$lines[$name]] ?? [])) {
        throw new RuntimeException($name.' must revert to native when the project overrides aliases; inspect '.$workspace);
    }
}
echo "PASS: project aliases override disables\n";

// A changed framework alias chain shape is equally unprovable and must defer.
file_put_contents($projectConfigPath, <<<'PHP'
    <?php
    return [
        'name' => 'probe',
    ];
    PHP);
file_put_contents($frameworkConfigPath, str_replace('defaultAliases', 'defaultAliasesRenamed', file_get_contents($frameworkConfigPath)));
$changedChain = $summarize($run(false));
foreach (['alias-random', 'alias-merged', 'alias-same-namespace'] as $name) {
    if (($changedChain[$lines[$name]] ?? []) !== ($native[$lines[$name]] ?? [])) {
        throw new RuntimeException($name.' must revert to native when the framework chain changes; inspect '.$workspace);
    }
}
echo "PASS: changed alias chain defers\n";

// Restoring the chain resolves the aliases again.
file_put_contents($frameworkConfigPath, str_replace('defaultAliasesRenamed', 'defaultAliases', file_get_contents($frameworkConfigPath)));
$restored = $summarize($run(false));
if (in_array('non-existent-method', $restored[$lines['alias-random']] ?? [], true)) {
    throw new RuntimeException('Restored alias chain must resolve again; inspect '.$workspace);
}
echo "PASS: restored alias chain resolves\n";

$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($resolved);
