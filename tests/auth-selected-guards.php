<?php

declare(strict_types=1);

// Production boundary: same guard class must never imply the same user model.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
foreach (['literal', 'disabled'] as $configurationMode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago selected guards '.bin2hex(random_bytes(8));
    mkdir($workspace);
    mkdir($workspace.'/config');
    copy(__DIR__.'/fixtures/analysis/auth-types.php.stub', $workspace.'/models.php');
    copy(__DIR__.'/fixtures/analysis/auth-chains.php.stub', $workspace.'/framework.php');
    $helperPath = $workspace.'/vendor/laravel/framework/src/Illuminate/Foundation';
    mkdir($helperPath, 0777, true);
    copy(__DIR__.'/fixtures/analysis/auth-chains-helpers.php.stub', $helperPath.'/helpers.php');
    $configuration = file_get_contents(__DIR__.'/fixtures/analysis/auth-config.php.stub');
    $configuration = str_replace("'driver' => 'session'", "'driver' => 'token'", $configuration);
    file_put_contents($workspace.'/config/auth.php', $configuration);
    $cases = [
        'admin retains native user contract' => ['return auth("admin")->user();', '?Authenticatable', []],
        'member retains native user contract' => ['return auth("web")->user();', '?Authenticatable', []],
        'facade admin selection does not invent model' => [
            'return Auth::guard("admin")->user();',
            '?AuthAdmin',
            ['less-specific-return-statement'],
        ],
        'same class member does not acquire admin model' => [
            '$admin = auth("admin"); $member = auth("web"); $admin->user(); return $member->user();',
            '?AuthAdmin',
            ['less-specific-return-statement'],
        ],
        'same class admin does not acquire member model' => [
            '$member = auth("web"); $admin = auth("admin"); $member->user(); return $admin->user();',
            '?AuthMember',
            ['less-specific-return-statement'],
        ],
        'same class union stays native' => [
            '$guard = random_int(0, 1) ? auth("admin") : auth("web"); return $guard->user();',
            '?Authenticatable',
            [],
        ],
        'union cannot collapse to one model' => [
            '$guard = random_int(0, 1) ? auth("admin") : auth("web"); return $guard->user();',
            '?AuthAdmin',
            ['less-specific-return-statement'],
        ],
        'raw guard stays native' => [
            'return (new TokenGuard)->user();',
            '?AuthAdmin',
            ['less-specific-return-statement'],
        ],
        'no visible state property' => ['return auth("admin")->__laramago_model;', 'mixed', ['non-existent-property']],
        'unknown user method remains diagnostic' => ['auth("admin")->uesr();', 'void', ['non-existent-method']],
        'direct named request remains supported' => [
            'return (new Request)->user("admin");',
            '?AuthAdmin',
            $configurationMode === 'disabled' ? ['less-specific-return-statement'] : [],
        ],
    ];
    $source = <<<'PHP'
        <?php
        use Illuminate\Http\Request;
        use Illuminate\Contracts\Auth\Authenticatable;
        use Illuminate\Contracts\Auth\Factory;
        use Illuminate\Contracts\Auth\Guard;
        use Illuminate\Auth\AuthManager;
        use Illuminate\Auth\SessionGuard;
        use Illuminate\Auth\TokenGuard;
        use Illuminate\Support\Facades\Auth;
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= "/**\n * @return ".$return."\n */\n";
        $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = [$name, $codes];
    }
    file_put_contents($workspace.'/cases.php', $source);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => [
            'paths' => ['cases.php'],
            'includes' => [
                'models.php',
                'framework.php',
                $configurationMode === 'custom-helper'
                    ? 'helper.php'
                    : 'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php',
            ],
        ],
        'extension-hosts' => $configurationMode === 'disabled'
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
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '
        .$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
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
        echo 'PASS ['.$configurationMode.']: '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics outside authentication scenarios; inspect '.$workspace);
    }
    if (is_file($workspace.'/config/executed.txt')) {
        throw new RuntimeException('Configuration was executed.');
    }
    if (is_file($workspace.'/config/auth.php')) {
        unlink($workspace.'/config/auth.php');
    }
    rmdir($workspace.'/config');
    $resolvedWorkspace = realpath($workspace);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $entry) {
        $file = $entry->getPathname();
        $resolvedFile = realpath($file);
        if (
            $resolvedWorkspace === false
            || $resolvedFile === false
            || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Refusing cleanup outside the test workspace.');
        }
        is_dir($resolvedFile) ? rmdir($resolvedFile) : unlink($resolvedFile);
    }
    rmdir($workspace);
}
