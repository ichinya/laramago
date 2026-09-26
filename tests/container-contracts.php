<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago container contracts '.bin2hex(random_bytes(8));
$framework = $workspace.'/laravel/framework/src/Illuminate';
foreach (['Foundation', 'Contracts/Foundation', 'Contracts/Container', 'Contracts/Auth', 'Contracts/Unknown', 'Config', 'Contracts/Config'] as $folder) {
    if (! is_dir($framework.'/'.$folder)) {
        mkdir($framework.'/'.$folder, 0777, true);
    }
}
file_put_contents($framework.'/Contracts/Container/Container.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Container;
    interface Container {
        public function bound($abstract);
    }
    PHP);
file_put_contents($framework.'/Contracts/Foundation/Application.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Foundation;
    interface Application extends \Illuminate\Contracts\Container\Container {
        public function version();
    }
    PHP);
file_put_contents($framework.'/Contracts/Auth/Guard.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Auth;
    interface Guard {}
    PHP);
file_put_contents($framework.'/Contracts/Unknown/Handle.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Unknown;
    interface Handle {}
    PHP);
$applicationPath = $framework.'/Foundation/Application.php';
file_put_contents($applicationPath, <<<'PHP'
    <?php
    namespace Illuminate\Foundation;
    class Application implements \Illuminate\Contracts\Foundation\Application {
        public function version(): string { return '8.5.0'; }
        public function isProduction(): bool { return false; }
        public function detectEnvironment(\Closure $callback) { return $callback('local'); }
        public function bound($abstract): bool { return true; }
        public function alias($abstract, $alias): void {}
        public function registerCoreContainerAliases() {
            foreach (['app' => [self::class, \Illuminate\Contracts\Container\Container::class, \Illuminate\Contracts\Foundation\Application::class], 'auth.driver' => [\Illuminate\Contracts\Auth\Guard::class], 'handle' => [\App\Unknown\OptionalAdapter::class, \Illuminate\Contracts\Unknown\Handle::class], 'config' => [\Illuminate\Config\Repository::class, \Illuminate\Contracts\Config\Repository::class]] as $key => $aliases) {
                foreach ($aliases as $alias) { $this->alias($key, $alias); }
            }
        }
    }
    PHP);
file_put_contents($framework.'/Contracts/Config/Repository.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Config;
    interface Repository {}
    PHP);
file_put_contents($framework.'/Config/Repository.php', <<<'PHP'
    <?php
    namespace Illuminate\Config;
    class Repository implements \Illuminate\Contracts\Config\Repository {
        public function all(): array { return []; }
    }
    PHP);
file_put_contents($workspace.'/custom-contract.php', <<<'PHP'
    <?php
    namespace App\Contracts;
    interface Custom {}
    PHP);
$cases = [
    'contract-is-production' => "function contractIsProduction(\\Illuminate\\Contracts\\Foundation\\Application \$app): bool { return \$app->isProduction(); }",
    'contract-detect-environment' => "function contractDetectEnvironment(\\Illuminate\\Contracts\\Foundation\\Application \$app): void { \$app->detectEnvironment(static fn (): string => 'local'); }",
    'config-repository-all' => "function contractConfigAll(\\Illuminate\\Contracts\\Config\\Repository \$repo): array { return \$repo->all(); }",
    'contract-native-version' => "function contractVersion(\\Illuminate\\Contracts\\Foundation\\Application \$app): string { return \$app->version(); }",
    'contract-parent-bound' => "function contractBound(\\Illuminate\\Contracts\\Foundation\\Application \$app): bool { return \$app->bound('app'); }",
    'contract-missing' => "function contractMissing(\\Illuminate\\Contracts\\Foundation\\Application \$app): void { \$app->nonExistentAnything(); }",
    'concrete-is-production' => "function concreteIsProduction(\\Illuminate\\Foundation\\Application \$app): bool { return \$app->isProduction(); }",
    'union-is-production' => "function unionIsProduction(\\Illuminate\\Contracts\\Foundation\\Application|int \$app): bool { return \$app->isProduction(); }",
    'nullable-is-production' => "function nullableIsProduction(?\\Illuminate\\Contracts\\Foundation\\Application \$app): void { \$app->isProduction(); }",
    'custom-missing' => "function customMissing(\\App\\Contracts\\Custom \$custom): void { \$custom->anything(); }",
    'guard-missing' => "function guardMissing(\\Illuminate\\Contracts\\Auth\\Guard \$guard): void { \$guard->nonExistentAnything(); }",
    'handle-missing' => "function handleMissing(\\Illuminate\\Contracts\\Unknown\\Handle \$handle): void { \$handle->anything(); }",
];
$source = "<?php\n".implode("\n", array_values($cases))."\n";
file_put_contents($workspace.'/cases.php', $source);
$lines = [];
$line = 2;
foreach ($cases as $name => $_) {
    $lines[$name] = $line++;
}

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (bool $disabled) use ($workspace, $package, $command): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.5',
        'source' => ['paths' => ['cases.php'], 'includes' => [$workspace.'/laravel', $workspace.'/custom-contract.php']],
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
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request|analyzer issue-filter hook .* failed|method-return-type provider .* failed/i', $stderr)) {
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
    if (in_array($name, ['contract-is-production', 'config-repository-all'], true)) {
        // The false positive is removed. The call stays imprecise (Mago consults
        // return-type providers only for calls it already resolves), so the native
        // mixed-return-statement remains and must be pinned.
        if (
            $actual !== ['mixed-return-statement']
            || ! in_array('non-existent-method', $baseline, true)
            || ! in_array('mixed-return-statement', $baseline, true)
        ) {
            throw new RuntimeException($name.' should lose its false positive; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif ($name === 'contract-detect-environment') {
        if ($actual !== [] || ! in_array('non-existent-method', $baseline, true)) {
            throw new RuntimeException($name.' must be fixed by the filter alone; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif (in_array($name, ['union-is-production', 'nullable-is-production'], true)) {
        // Mago narrows the receiver per atomic: the contract atomic keeps its proven
        // removal while the int/null atomics keep their own diagnostics.
        $required = $name === 'union-is-production' ? 'invalid-method-access' : 'possible-method-access-on-null';
        if (
            $actual !== array_values(array_diff($baseline, ['non-existent-method']))
            || ! in_array('non-existent-method', $baseline, true)
            || ! in_array($required, $baseline, true)
        ) {
            throw new RuntimeException($name.' must keep the non-contract diagnostics; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif ($name === 'contract-parent-bound') {
        // The method is declared on the parent Container interface: native analysis
        // resolves it, so neither the provider nor the filter may interfere. The
        // provider only defers when methodExists walks the parent interfaces.
        if ($actual !== $baseline || $baseline !== ['mixed-return-statement']) {
            throw new RuntimeException($name.' must retain native parent-interface resolution; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif (in_array($name, ['contract-missing', 'custom-missing', 'guard-missing', 'handle-missing'], true)) {
        // guard-missing pins the native interface-first table row (auth.driver): the
        // contract maps to itself, so forwarding stays inert. handle-missing pins the
        // optional-package row: the mapped root is an undefined stub with no declared
        // methods, so per-call proof keeps the real diagnostic.
        if ($actual !== $baseline || ! in_array('non-existent-method', $baseline, true)) {
            throw new RuntimeException($name.' must keep its real diagnostic; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif ($actual !== $baseline) {
        throw new RuntimeException($name.' must retain native diagnostics; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
    }
    echo 'PASS: '.$name."\n";
}

$applicationSource = file_get_contents($applicationPath);
file_put_contents($applicationPath, str_replace(
    'registerCoreContainerAliases',
    'registerCoreContainerAliasesRenamed',
    $applicationSource,
));
$changedAliases = $summarize($run(false));
if (($changedAliases[$lines['contract-is-production']] ?? []) !== ($native[$lines['contract-is-production']] ?? [])) {
    throw new RuntimeException('Changed alias table must retain native diagnostics; inspect '.$workspace);
}
file_put_contents($applicationPath, $applicationSource);
echo "PASS: changed alias table defers\n";

$restored = $summarize($run(false));
if (($restored[$lines['contract-is-production']] ?? []) !== ['mixed-return-statement']) {
    throw new RuntimeException('Restored alias table must resolve contract methods again; inspect '.$workspace);
}
echo "PASS: restored alias table resolves\n";

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
