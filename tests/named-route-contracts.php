<?php

declare(strict_types=1);

// Check named-route calls through the real SDK worker without executing fixtures.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$customApp = in_array('--custom-app', $argv, true);
$customRedirect = in_array('--custom-redirect', $argv, true);
$customUrlBinding = in_array('--custom-url-binding', $argv, true);
$customUrlMethod = in_array('--custom-url-method', $argv, true);
$customRedirectMethod = in_array('--custom-redirect-method', $argv, true);
$customDoc = in_array('--custom-doc', $argv, true);
$literalFiles = in_array('--literal-files', $argv, true);
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago named route contracts '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Routing';
mkdir($framework, 0777, true);
foreach (['UrlGenerator', 'Redirector'] as $class) {
    $custom = $class === 'UrlGenerator' ? $customUrlMethod : $customRedirectMethod;
    $directory = $custom ? $workspace.'/custom' : $framework;
    @mkdir($directory, 0777, true);
    copy(__DIR__.'/fixtures/analysis/named-route-'.$class.'.php.stub', $directory.'/'.$class.'.php');
}
$urlGeneratorFile = $customUrlMethod
    ? 'custom/UrlGenerator.php'
    : 'vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php';
$redirectorFile = $customRedirectMethod
    ? 'custom/Redirector.php'
    : 'vendor/laravel/framework/src/Illuminate/Routing/Redirector.php';
$foundation = $workspace.'/vendor/laravel/framework/src/Illuminate/Foundation';
mkdir($foundation, 0777, true);
$helpers = file_get_contents(__DIR__.'/fixtures/analysis/named-route-helpers.php.stub');
if ($customApp) {
    $helpers = str_replace('function app(', 'function framework_app(', $helpers);
    file_put_contents($workspace.'/custom-dispatcher.php', <<<'PHP'
        <?php
        function app(mixed $abstract = null, array $parameters = []): mixed { return null; }
        PHP);
}
if ($customRedirect) {
    $helpers = str_replace('function redirect(', 'function framework_redirect(', $helpers);
    file_put_contents($workspace.'/custom-dispatcher.php', <<<'PHP'
        <?php
        function redirect(mixed $to = null, int $status = 302, array $headers = [], ?bool $secure = null): mixed
        {
            return null;
        }
        PHP);
}
if ($customDoc) {
    $helpers = str_replace('@return \\Illuminate\\Http\\RedirectResponse', '@return string', $helpers);
}
file_put_contents($foundation.'/helpers.php', $helpers);
if ($customUrlBinding) {
    mkdir($workspace.'/bootstrap');
    file_put_contents(
        $workspace.'/bootstrap/bindings.php',
        '<?php \\app()->bind("url", \\Illuminate\\Routing\\UrlGenerator::class);',
    );
}
if ($literalFiles) {
    mkdir($workspace.'/routes');
    file_put_contents($workspace.'/routes/web.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route as Routing;
        Routing::get('/', fn () => 'ok')->name('home');
        Routing::name('admin.')->group(function () {
            Routing::group(['as' => 'users.'], function () {
                Routing::post('/users', [\App\Controller::class, 'store'])->name('store');
            });
        });
        PHP);
}
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'named-routes' => [
                'complete' => true,
                'missing-route-resolver' => false,
                'names' => $literalFiles ? ['provider.added'] : ['home', 'provider.added'],
                ...($literalFiles ? ['files' => ['routes/web.php']] : []),
            ],
            'binding-files' => $customUrlBinding ? ['bootstrap/bindings.php'] : [],
        ],
    ],
], JSON_THROW_ON_ERROR));
$missing = ['ichinya/laramago/laramago-missing-named-route'];
$urlMethodMissing = $customUrlMethod ? [] : $missing;
$redirectMethodMissing = $customRedirectMethod ? [] : $missing;
$routeHelperMissing = $customApp || $customUrlBinding || $customUrlMethod ? [] : $missing;
$redirectHelperMissing = $customApp || $customRedirect || $customUrlBinding || $customUrlMethod || $customRedirectMethod
    ? []
    : $missing;
$cases = [
    ...(
        $literalFiles
            ? [
                'nested group name extracted' => ['$url->route("admin.users.store");', 'void', []],
                'unprefixed name is missing' => ['$url->route("store");', 'void', $urlMethodMissing],
            ] : []
    ),
    'known route' => ['$url->route("home");', 'void', []],
    'provider route listed' => ['$url->route("provider.added");', 'void', []],
    'missing URL route' => ['$url->route("typo");', 'void', $urlMethodMissing],
    'missing redirect route' => ['$redirect->route("typo");', 'void', $redirectMethodMissing],
    'named URL argument' => ['$url->route(name: "typo");', 'void', $urlMethodMissing],
    'named redirect argument' => ['$redirect->route(route: "typo");', 'void', $redirectMethodMissing],
    'case sensitive' => ['$url->route("Home");', 'void', $urlMethodMissing],
    'dynamic name deferred' => ['$url->route($name);', 'void', []],
    'concatenation deferred' => ['$url->route("home.".$name);', 'void', []],
    'unpacked arguments deferred' => ['$url->route(...["typo"]);', 'void', []],
    'subclass deferred' => ['(new \Illuminate\Routing\ExtendedUrlGenerator)->route("typo");', 'void', []],
    'redirect subclass deferred' => ['(new \Illuminate\Routing\ExtendedRedirector)->route("typo");', 'void', []],
    'known route helper' => ['route("home");', 'void', []],
    'missing route helper' => ['route("typo");', 'void', $routeHelperMissing, true],
    'missing fully-qualified route helper' => ['\\route("typo");', 'void', $routeHelperMissing, true],
    'missing imported route helper' => ['named_route("typo");', 'void', $routeHelperMissing, true],
    'missing redirect helper' => ['to_route("typo");', 'void', $redirectHelperMissing, true],
    'named route helper argument' => ['route(name: "typo");', 'void', $routeHelperMissing, true],
    'named redirect helper argument' => ['to_route(route: "typo");', 'void', $redirectHelperMissing, true],
    'dynamic helper name deferred' => ['route($name);', 'void', []],
    'concatenated helper name deferred' => ['to_route("home.".$name);', 'void', []],
    'unpacked helper arguments deferred' => ['route(...["typo"]);', 'void', []],
    'helper first-class callable deferred' => ['$callback = route(...);', 'void', []],
    'native helper argument error retained' => ['route(3);', 'void', ['invalid-argument']],
    'native helper return retained' => ['return route("home");', 'int', ['invalid-return-statement']],
    'unknown method retained' => ['$url->missing();', 'void', ['non-existent-method']],
];
if ($customDoc) {
    $cases['custom helper PHPDoc preserved'] = ['return to_route("home");', 'string', []];
}
$source = <<<'PHP'
    <?php
    namespace App {
    use Illuminate\Routing\UrlGenerator;
    use Illuminate\Routing\Redirector;
    use function route as named_route;
    PHP;
$lines = [];
$spans = [];
foreach ($cases as $name => $case) {
    [$body, $return, $codes] = $case;
    $source .= '/**'."\n".' * @return '.$return."\n".' */'."\n";
    $source .=
        'function scenario'.count($lines).'(UrlGenerator $url, Redirector $redirect, string $name) { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
    if (($case[3] ?? false) === true) {
        $spans[substr_count($source, "\n")] = in_array($missing[0], $codes, true) ? ['"typo"'] : [];
    }
}
$source .= <<<'PHP'
    }
    namespace Custom {
    function route(string $name): string { return $name; }
    function to_route(string $route): string { return $route; }
    PHP;
$source .= '/** @return void */'."\n";
$source .= 'function customRoute() { route("typo"); }'."\n";
$lines[substr_count($source, "\n")] = ['custom namespaced route helper deferred', []];
$source .= '/** @return void */'."\n";
$source .= 'function customRedirect() { to_route(route: "typo"); }'."\n";
$lines[substr_count($source, "\n")] = ['custom namespaced redirect helper deferred', []];
$source .= "}\n";
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            $urlGeneratorFile,
            $redirectorFile,
            'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php',
            ...($customApp || $customRedirect ? ['custom-dispatcher.php'] : []),
        ],
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
    throw new RuntimeException('Expected successful analysis with extension warnings and no fallback; inspect '
    .$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
$actualSpans = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    if ($issue['code'] === $missing[0]) {
        $actualSpans[$primary['span']['start']['line'] + 1][] = substr(
            $source,
            $primary['span']['start']['offset'],
            $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
        );
    }
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
    if (isset($spans[$line]) && ($actualSpans[$line] ?? []) !== $spans[$line]) {
        throw new RuntimeException(
            $name
            .': expected literal spans '
            .json_encode($spans[$line])
            .', got '
            .json_encode($actualSpans[$line] ?? [])
            .'; see '
            .$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics outside named-route scenarios; inspect '.$workspace);
}
foreach ([
    'no catalog' => null,
    'incomplete catalog' => ['complete' => false, 'missing-route-resolver' => false, 'names' => []],
    'fallback unspecified' => ['complete' => true, 'names' => []],
    'fallback enabled' => ['complete' => true, 'missing-route-resolver' => true, 'names' => []],
    'malformed names' => ['complete' => true, 'missing-route-resolver' => false, 'names' => ['home', false]],
    ...(
        $literalFiles
            ? [
                'unreadable route source' => [
                    'complete' => true,
                    'missing-route-resolver' => false,
                    'names' => [],
                    'files' => ['missing.php'],
                ],
                'unsupported route source' => [
                    'complete' => true,
                    'missing-route-resolver' => false,
                    'names' => [],
                    'files' => ['routes/web.php', 'cases.php'],
                ],
            ] : []
    ),
] as $label => $catalog) {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['named-routes' => $catalog]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/contract.json', 'w'],
            2 => ['file', $workspace.'/contract.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $report = json_decode(file_get_contents($workspace.'/contract.json'), true, flags: JSON_THROW_ON_ERROR);
    if (
        $exit !== 1
        || array_column($report['issues'] ?? [], 'code') !== [
            'invalid-argument',
            'invalid-return-statement',
            'non-existent-method',
        ]
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($workspace.'/contract.log'),
        )
    ) {
        throw new RuntimeException($label.': expected native diagnostics only; inspect '.$workspace);
    }
    echo 'PASS: '.$label."\n";
}
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'named-routes' => [
                'complete' => true,
                'missing-route-resolver' => false,
                'names' => ['home', 'provider.added'],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
$configuration = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
unset($configuration['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/disabled.json', 'w'],
        2 => ['file', $workspace.'/disabled.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/disabled.json'), true, flags: JSON_THROW_ON_ERROR);
if (
    $exit !== 1
    || array_column($report['issues'] ?? [], 'code') !== [
        'invalid-argument',
        'invalid-return-statement',
        'non-existent-method',
    ]
) {
    throw new RuntimeException('Disabled analyzer must leave named-route calls native; inspect '.$workspace);
}
echo "PASS: disabled analyzer leaves named-route calls native\n";
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
$resolvedWorkspace = realpath($workspace);
foreach ($iterator as $file) {
    $resolvedFile = realpath($file->getPathname());
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);
