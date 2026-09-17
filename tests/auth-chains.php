<?php

declare(strict_types=1);

// Exercise native helper, facade and guard contracts with and without the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
foreach ([
    'literal',
    'dynamic-default',
    'disabled',
    'custom-helper',
    'altered-helper',
    'altered-guard',
    'altered-user',
    'altered-contract',
    'concrete-facade',
    'dynamic-model',
    'missing',
] as $configurationMode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago auth '.bin2hex(random_bytes(8));
    mkdir($workspace);
    file_put_contents($workspace.'/.env', "AUTH_GUARD=admin\nAUTH_MODEL=AuthAdmin\n");
    mkdir($workspace.'/config');
    copy(__DIR__.'/fixtures/analysis/auth-types.php.stub', $workspace.'/models.php');
    copy(__DIR__.'/fixtures/analysis/auth-chains.php.stub', $workspace.'/framework.php');
    $helperPath = $workspace.'/vendor/laravel/framework/src/Illuminate/Foundation';
    mkdir($helperPath, 0777, true);
    $helper = file_get_contents(__DIR__.'/fixtures/analysis/auth-chains-helpers.php.stub');
    if ($configurationMode === 'altered-helper') {
        $helper = str_replace('Factory : Guard', '\\Illuminate\\Auth\\TokenGuard : Guard', $helper);
        $helper = str_replace('Factory|Guard', '\\Illuminate\\Auth\\TokenGuard', $helper);
    }
    file_put_contents(
        $configurationMode === 'custom-helper' ? $workspace.'/helper.php' : $helperPath.'/helpers.php',
        $helper,
    );
    if ($configurationMode === 'altered-guard') {
        $framework = file_get_contents($workspace.'/framework.php');
        $framework = str_replace(
            'public function guard(?string $name = null): \\Illuminate\\Contracts\\Auth\\Guard',
            'public function guard(?string $name = null): \\Illuminate\\Auth\\TokenGuard',
            $framework,
        );
        $framework = str_replace(
            '@method static \\Illuminate\\Contracts\\Auth\\Guard guard',
            '@method static \\Illuminate\\Auth\\TokenGuard guard',
            $framework,
        );
        file_put_contents($workspace.'/framework.php', $framework);
    }
    if ($configurationMode === 'altered-contract') {
        $framework = file_get_contents($workspace.'/framework.php');
        $framework = str_replace(
            'public function user(): ?Authenticatable;',
            'public function user(): ?\\AuthAdmin;',
            $framework,
        );
        file_put_contents($workspace.'/framework.php', $framework);
    }
    if ($configurationMode === 'altered-user' || $configurationMode === 'concrete-facade') {
        $framework = file_get_contents($workspace.'/framework.php');
        $framework = $configurationMode === 'altered-user'
            ? str_replace(
                '@method static \\Illuminate\\Contracts\\Auth\\Authenticatable|null user()',
                '@method static \\AuthAdmin|null user()',
                $framework,
            )
            : str_replace(
                'class Auth {',
                'class Auth { public static function user(): ?\\Illuminate\\Contracts\\Auth\\Authenticatable { return null; }',
                $framework,
            );
        file_put_contents($workspace.'/framework.php', $framework);
    }
    if ($configurationMode !== 'missing') {
        $configuration = file_get_contents(__DIR__.'/fixtures/analysis/auth-config.php.stub');
        if ($configurationMode === 'dynamic-default') {
            $configuration = str_replace(
                "'defaults' => ['guard' => 'web']",
                "'defaults' => ['guard' => env('AUTH_GUARD', 'web')]",
                $configuration,
            );
        }
        if ($configurationMode === 'dynamic-model') {
            $configuration = str_replace(
                "'model' => Member::class",
                "'model' => env('AUTH_MODEL', Member::class)",
                $configuration,
            );
        }
        file_put_contents($workspace.'/config/auth.php', $configuration);
    }
    $cases = [
        'helper manager' => ['return auth();', 'AuthManager', []],
        'helper null manager' => ['return auth(null);', 'AuthManager', []],
        'helper user' => ['return auth()->user();', '?AuthMember', []],
        'helper check' => ['return auth()->check();', 'bool', []],
        'helper id' => ['return auth()->id();', 'int|string|null', []],
        'literal guard' => ['return auth("admin");', 'TokenGuard', []],
        'named literal guard' => ['return auth(guard: "admin");', 'TokenGuard', []],
        'falsey guard follows default' => ['return auth("0");', 'SessionGuard', []],
        'dynamic guard defers' => ['return (static fn (string $guard): Guard => auth($guard))("web");', 'Guard', []],
        'named facade guard' => ['return Auth::guard(name: "admin");', 'TokenGuard', []],
        'literal guard user' => ['return auth("admin")->user();', '?Authenticatable', []],
        'manager guard' => ['return auth()->guard("web");', 'SessionGuard', []],
        'facade guard' => ['return Auth::guard("admin");', 'TokenGuard', []],
        'facade user' => ['return Auth::user();', '?AuthMember', []],
        'facade id' => ['return Auth::id();', 'int|string|null', []],
        'facade check' => ['return Auth::check();', 'bool', []],
        'nullable facade' => [
            'return Auth::user();',
            'AuthMember',
            ['invalid-return-statement', 'nullable-return-statement'],
        ],
        'unknown guard' => ['return auth("missing");', 'Guard', []],
        'custom driver' => ['return auth("custom");', 'Guard', []],
        'guard model not invented' => [
            'return auth("admin")->user();',
            '?AuthAdmin',
            ['less-specific-return-statement'],
        ],
        'custom manager user' => ['return (new CustomAuthManager)->user();', '?AuthAdmin', []],
        'custom manager guard' => ['return (new CustomAuthManager)->guard();', 'TokenGuard', []],
        'custom facade user' => ['return CustomAuthFacade::user();', '?AuthAdmin', []],
        'custom resolver user' => ['return (new ResolverAuthRequest)->user();', '?Authenticatable', []],
        'resolver model not guessed' => [
            'return (new ResolverAuthRequest)->user();',
            '?AuthMember',
            ['less-specific-return-statement'],
        ],
        'helper bad argument' => ['auth(123);', 'void', ['invalid-argument']],
        'facade property typo' => [
            '$user = Auth::user(); if ($user !== null) { return $user->emial; } return "";',
            'mixed',
            ['non-existent-property'],
        ],
        'guard method typo' => ['auth("admin")->uesr();', 'void', ['non-existent-method']],
    ];
    if ($configurationMode === 'dynamic-default') {
        $cases = [
            'dynamic helper manager' => ['return auth();', 'AuthManager', []],
            'dynamic helper check' => ['return auth()->check();', 'bool', []],
            'dynamic facade user' => ['return Auth::user();', '?Authenticatable', []],
            'dynamic default not guessed' => [
                'return Auth::user();',
                '?AuthMember',
                ['less-specific-return-statement'],
            ],
            'dynamic explicit guard' => ['return auth("admin");', 'TokenGuard', []],
        ];
    } elseif (in_array($configurationMode, ['disabled', 'custom-helper'], true)) {
        $cases = [
            'original helper declaration' => ['return auth();', 'Factory', []],
            'manager not invented' => ['return auth();', 'AuthManager', ['less-specific-return-statement']],
            'original explicit guard' => ['return auth("admin");', 'Guard', []],
        ];
    } elseif ($configurationMode === 'altered-helper') {
        $cases = [
            'changed helper declaration' => ['return auth();', 'TokenGuard', []],
            'changed helper not replaced' => ['return auth();', 'AuthManager', ['invalid-return-statement']],
        ];
    } elseif ($configurationMode === 'altered-contract') {
        $cases = [
            'changed mixin user declaration' => ['return auth()->user();', '?AuthAdmin', []],
            'mixin user contract not replaced' => [
                'return auth()->user();',
                '?AuthMember',
                ['invalid-return-statement'],
            ],
        ];
    } elseif ($configurationMode === 'altered-user') {
        $cases = [
            'changed facade user declaration' => ['return Auth::user();', '?AuthAdmin', []],
            'changed user not replaced' => ['return Auth::user();', '?AuthMember', ['invalid-return-statement']],
        ];
    } elseif (in_array($configurationMode, ['dynamic-model', 'missing', 'concrete-facade'], true)) {
        $cases = [
            'unknown or explicit user contract' => ['return Auth::user();', '?Authenticatable', []],
            'model not guessed or overridden' => [
                'return Auth::user();',
                '?AuthMember',
                ['less-specific-return-statement'],
            ],
        ];
    } elseif ($configurationMode === 'altered-guard') {
        $cases = [
            'changed manager declaration' => ['return auth()->guard("web");', 'TokenGuard', []],
            'changed facade declaration' => ['return Auth::guard("web");', 'TokenGuard', []],
            'changed guard not replaced' => [
                'return Auth::guard("web");',
                'SessionGuard',
                ['invalid-return-statement'],
            ],
        ];
    }
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
