<?php

declare(strict_types=1);

// User-authored offline contracts must refine only known framework entry points.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$contract = [
    'default-guard' => 'web',
    'guards' => [
        'web' => ['model' => 'AuthMember'],
        'custom' => ['class' => 'ContractNativeGuard', 'model' => 'AuthAdmin'],
        'documented' => ['class' => 'ContractDocGuard', 'model' => 'AuthAdmin'],
    ],
];
foreach ([
    'contract',
    'no-config',
    'custom-default',
    'disabled',
    'absent',
    'malformed-json',
    'malformed-shape',
    'bad-default',
    'bad-model',
    'bad-class',
    'conflicting-model',
    'partial',
    'binding-override',
] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago auth contracts '.bin2hex(random_bytes(8));
    mkdir($workspace);
    mkdir($workspace.'/config');
    mkdir($workspace.'/bootstrap');
    $helperPath = $workspace.'/vendor/laravel/framework/src/Illuminate/Foundation';
    mkdir($helperPath, 0777, true);
    copy(__DIR__.'/fixtures/analysis/auth-types.php.stub', $workspace.'/models.php');
    copy(__DIR__.'/fixtures/analysis/auth-chains.php.stub', $workspace.'/framework.php');
    copy(__DIR__.'/fixtures/analysis/auth-contracts.php.stub', $workspace.'/contracts.php');
    copy(__DIR__.'/fixtures/analysis/auth-chains-helpers.php.stub', $helperPath.'/helpers.php');
    $trap = '<?php file_put_contents(__DIR__."/executed.txt", "Must not execute"); throw new RuntimeException;';
    file_put_contents($workspace.'/bootstrap/app.php', $trap);
    file_put_contents($workspace.'/vendor/autoload.php', $trap);
    file_put_contents($workspace.'/.env', "AUTH_GUARD=admin\nAUTH_MODEL=AuthAdmin\n");
    $configuration = file_get_contents(__DIR__.'/fixtures/analysis/auth-config.php.stub');
    $configuration = str_replace(
        ["'defaults' => ['guard' => 'web']", "'model' => Member::class"],
        ["'defaults' => ['guard' => env('AUTH_GUARD', 'admin')]", "'model' => env('AUTH_MODEL', AuthAdmin::class)"],
        $configuration,
    );
    if ($mode !== 'no-config') {
        file_put_contents($workspace.'/config/auth.php', $configuration);
    }
    $auth = $contract;
    if ($mode === 'custom-default') {
        $auth['default-guard'] = 'custom';
    } elseif ($mode === 'bad-default') {
        $auth['default-guard'] = ['web'];
    } elseif ($mode === 'bad-model') {
        $auth['guards']['web']['model'] = 'ContractWrongClass';
        $auth['guards']['custom']['model'] = 'UnknownAuthClass';
    } elseif ($mode === 'bad-class') {
        $auth['guards']['custom']['class'] = 'ContractWrongClass';
        $auth['guards']['documented']['class'] = 'UnknownAuthClass';
    } elseif ($mode === 'conflicting-model') {
        $auth['guards']['custom']['model'] = 'AuthMember';
        $auth['guards']['documented']['model'] = 'AuthMember';
    } elseif ($mode === 'partial') {
        $auth = ['guards' => ['web' => ['model' => false], 'custom' => ['class' => false]]];
    }
    if ($mode !== 'absent') {
        file_put_contents(
            $workspace.'/composer.json',
            $mode === 'malformed-json'
                ? '{invalid'
                : json_encode([
                    'extra' => ['laramago' => ['auth' => $mode === 'malformed-shape' ? ['web'] : $auth]],
                ], JSON_THROW_ON_ERROR),
        );
    }
    if ($mode === 'binding-override') {
        file_put_contents(
            $workspace.'/bootstrap/bindings.php',
            '<?php app()->bind("auth", ContractNativeGuard::class);',
        );
        file_put_contents($workspace.'/composer.json', json_encode([
            'extra' => ['laramago' => ['auth' => $auth, 'binding-files' => ['bootstrap/bindings.php']]],
        ], JSON_THROW_ON_ERROR));
    }
    $cases = [
        'default contract beats env fallback' => ['return Auth::user();', '?AuthMember', []],
        'manager contract' => ['return auth()->user();', '?AuthMember', []],
        'request contract' => ['return (new Request)->user();', '?AuthMember', []],
        'named request custom contract' => ['return (new Request)->user(guard: "custom");', '?AuthAdmin', []],
        'custom helper guard class' => ['return auth("custom");', 'ContractNativeGuard', []],
        'custom manager guard class' => ['return auth()->guard("custom");', 'ContractNativeGuard', []],
        'custom facade guard class' => ['return Auth::guard("custom");', 'ContractNativeGuard', []],
        'custom native user retained' => ['return auth("custom")->user();', '?AuthAdmin', []],
        'custom phpdoc user retained' => ['return auth("documented")->user();', '?AuthAdmin', []],
        'request native override retained' => ['return (new CustomAuthRequest)->user();', '?AuthAdmin', []],
        'request phpdoc retained' => ['return (new ContractDocRequest)->user();', '?AuthAdmin', []],
        'nullable preserved' => [
            'return Auth::user();',
            'AuthMember',
            ['invalid-return-statement', 'nullable-return-statement'],
        ],
        'wrong model rejected' => ['return Auth::user();', '?AuthAdmin', ['invalid-return-statement']],
        'unknown guard not guessed' => [
            'return (new Request)->user("missing");',
            '?AuthMember',
            ['less-specific-return-statement'],
        ],
        'dynamic guard not guessed' => [
            '(static fn (string $name): ?AuthMember => (new Request)->user($name))("web");',
            'void',
            ['less-specific-return-statement'],
        ],
        'standard guard model remains broad' => [
            'return auth("web")->user();',
            '?AuthMember',
            ['less-specific-return-statement'],
        ],
        'native argument rejection' => ['auth(123);', 'void', ['invalid-argument']],
        'custom method typo rejected' => ['auth("custom")->uesr();', 'void', ['non-existent-method']],
    ];
    if (in_array(
        $mode,
        ['disabled', 'absent', 'malformed-json', 'malformed-shape', 'bad-default', 'bad-model', 'partial'],
        true,
    )) {
        $cases = [
            'unknown default remains broad' => ['return Auth::user();', '?Authenticatable', []],
            'default model not invented' => ['return Auth::user();', '?AuthMember', ['less-specific-return-statement']],
            'literal unrelated guard remains known' => [
                'return (new Request)->user("admin");',
                $mode === 'disabled' ? '?Authenticatable' : '?AuthAdmin',
                [],
            ],
        ];
        if ($mode === 'bad-default') {
            $cases['explicit model survives invalid default'] = [
                'return (new Request)->user("web");',
                '?AuthMember',
                [],
            ];
        }
        if ($mode === 'partial') {
            $cases['invalid class remains broad'] = ['return auth("custom");', 'Guard', []];
        }
        if ($mode === 'bad-model') {
            $cases['missing model remains broad'] = [
                'return (new Request)->user("custom");',
                '?AuthAdmin',
                ['less-specific-return-statement'],
            ];
        }
    } elseif ($mode === 'binding-override') {
        $cases = [
            'custom factory keeps native helper contract' => [
                'return auth();',
                '\\Illuminate\\Contracts\\Auth\\Factory',
                [],
            ],
            'custom factory keeps user contract broad' => ['return Auth::user();', '?Authenticatable', []],
            'custom factory does not invent model' => [
                'return (new Request)->user();',
                '?AuthMember',
                ['less-specific-return-statement'],
            ],
            'custom factory keeps selected guard broad' => ['return auth("custom");', 'Guard', []],
            'custom factory keeps facade guard broad' => ['return Auth::guard("custom");', 'Guard', []],
            'custom factory preserves request override' => [
                'return (new CustomAuthRequest)->user();',
                '?AuthAdmin',
                [],
            ],
        ];
    } elseif ($mode === 'custom-default') {
        $cases = [
            'explicit custom default user' => ['return Auth::user();', '?AuthAdmin', []],
            'explicit custom default guard' => ['return Auth::guard();', 'ContractNativeGuard', []],
            'falsey named guard uses contract default' => ['return auth("0");', 'ContractNativeGuard', []],
            'default null user stays nullable' => [
                'return (new Request)->user(null);',
                'AuthAdmin',
                ['invalid-return-statement', 'nullable-return-statement'],
            ],
            'explicit unrelated guard model' => ['return (new Request)->user("web");', '?AuthMember', []],
        ];
    } elseif ($mode === 'bad-class') {
        $cases = [
            'unrelated default survives invalid classes' => ['return Auth::user();', '?AuthMember', []],
            'non guard class rejected' => [
                'return auth("custom");',
                'ContractWrongClass',
                ['invalid-return-statement'],
            ],
            'missing class remains broad' => ['return auth("documented");', 'Guard', []],
            'invalid class model remains broad' => [
                'return (new Request)->user("custom");',
                '?AuthAdmin',
                ['less-specific-return-statement'],
            ],
        ];
    } elseif ($mode === 'conflicting-model') {
        $cases = [
            'native custom method wins' => ['return auth("custom")->user();', '?AuthAdmin', []],
            'phpdoc custom method wins' => ['return auth("documented")->user();', '?AuthAdmin', []],
            'conflicting native contract refused' => [
                'return (new Request)->user("custom");',
                '?AuthMember',
                ['less-specific-return-statement'],
            ],
            'conflicting phpdoc contract refused' => [
                'return (new Request)->user("documented");',
                '?AuthMember',
                ['less-specific-return-statement'],
            ],
        ];
    }
    $source = <<<'PHP'
        <?php
        use Illuminate\Http\Request;
        use Illuminate\Contracts\Auth\Authenticatable;
        use Illuminate\Contracts\Auth\Guard;
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
                'contracts.php',
                'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php',
            ],
        ],
        'extension-hosts' => $mode === 'disabled'
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
        throw new RuntimeException('Expected native negatives without worker fallback; inspect '.$workspace);
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
                $mode.' '.$name.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS ['.$mode.']: '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics outside scenarios; inspect '.$workspace);
    }
    foreach ([
        'config/executed.txt',
        'bootstrap/executed.txt',
        'vendor/executed.txt',
        'classes-executed.txt',
    ] as $trapFile) {
        if (is_file($workspace.'/'.$trapFile)) {
            throw new RuntimeException('Application code was executed: '.$trapFile);
        }
    }
    $resolvedWorkspace = realpath($workspace);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $entry) {
        $resolved = realpath($entry->getPathname());
        if (
            $resolvedWorkspace === false
            || $resolved === false
            || ! str_starts_with($resolved, $resolvedWorkspace.DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Refusing cleanup outside test workspace.');
        }
        is_dir($resolved) ? rmdir($resolved) : unlink($resolved);
    }
    rmdir($workspace);
}
