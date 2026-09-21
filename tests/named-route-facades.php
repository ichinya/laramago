<?php

declare(strict_types=1);

// Real-worker facade dispatch checks; generated fixtures are never executed.
// Laravel framework excerpts retain the license in fixtures/analysis/named-route-LICENSE.md.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$mode = $argv[1] ?? '';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago named route facades '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
mkdir($framework.'/Support/Facades', 0777, true);
mkdir($framework.'/Routing', 0777, true);
file_put_contents($framework.'/Support/Facades/Facade.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    abstract class Facade {
        protected static $app;
        protected static $resolvedInstance;
        protected static $cached = true;
        public static function getFacadeRoot() {
            return static::resolveFacadeInstance(static::getFacadeAccessor());
        }
        protected static function getFacadeAccessor() {
            throw new \RuntimeException('Facade does not implement getFacadeAccessor method.');
        }
        protected static function resolveFacadeInstance($name) {
            if (isset(static::$resolvedInstance[$name])) { return static::$resolvedInstance[$name]; }
            if (static::$app) {
                if (static::$cached) { return static::$resolvedInstance[$name] = static::$app[$name]; }
                return static::$app[$name];
            }
        }
        public static function __callStatic($method, $args) {
            $instance = static::getFacadeRoot();
            if (! $instance) { throw new \RuntimeException('A facade root has not been set.'); }
            return $instance->$method(...$args);
        }
    }
    PHP);
foreach (['URL' => 'url', 'Redirect' => 'redirect'] as $class => $accessor) {
    $signedParameters = $class === 'URL'
        ? '\\BackedEnum|string $name, mixed $parameters = [], \\DateTimeInterface|\\DateInterval|int|null $expiration = null, bool $absolute = true'
        : '\\BackedEnum|string $route, mixed $parameters = [], \\DateTimeInterface|\\DateInterval|int|null $expiration = null, int $status = 302, array $headers = []';
    $temporaryParameters = $class === 'URL'
        ? '\\BackedEnum|string $name, \\DateTimeInterface|\\DateInterval|int $expiration, array $parameters = [], bool $absolute = true'
        : '\\BackedEnum|string $route, \\DateTimeInterface|\\DateInterval|int|null $expiration, mixed $parameters = [], int $status = 302, array $headers = []';
    $signedReturn = $class === 'URL'
        ? ($mode === '--custom-doc' ? 'int' : 'string')
        : '\\Illuminate\\Http\\RedirectResponse';
    $signedSignatures = "\n * @method static $signedReturn signedRoute($signedParameters)\n * @method static $signedReturn temporarySignedRoute($temporaryParameters)\n";
    $signedDeclarations = "public static function signedRoute($signedParameters): int { return 42; } public static function temporarySignedRoute($temporaryParameters): int { return 42; }";
    $signature = $class === 'URL'
        ? 'string route(\\BackedEnum|string $name, mixed $parameters = [], bool $absolute = true)'
        : '\\Illuminate\\Http\\RedirectResponse route(\\BackedEnum|string $route, mixed $parameters = [], int $status = 302, array $headers = [])';
    if ($mode === '--custom-doc') {
        $signature = str_replace('string route(', 'int route(', $signature);
    }
    $body = $mode === '--custom-accessor' ? "return 'custom';" : "return '$accessor';";
    $extra = match ($mode) {
        '--custom-root' => 'public static function getFacadeRoot() { return new \\stdClass; }',
        '--custom-method'
            => 'public static function route(\\BackedEnum|string $name, mixed $parameters = [], bool $absolute = true): int { return 42; }',
        default => '',
    };
    if ($class === 'Redirect' && $mode === '--custom-method') {
        $extra = 'public static function route(\\BackedEnum|string $route, mixed $parameters = [], int $status = 302, array $headers = []): int { return 42; }';
    }
    if ($mode === '--custom-method') {
        $extra .= $signedDeclarations;
    }
    $parent = 'Facade';
    $ancestors = '';
    if (in_array($mode, ['--inherited-method', '--inherited-trait'], true)) {
        $parent = 'Bridge'.$class;
        $declaration = $class === 'URL'
            ? 'public static function route(\\BackedEnum|string $name, mixed $parameters = [], bool $absolute = true): int { return 42; }'
            : 'public static function route(\\BackedEnum|string $route, mixed $parameters = [], int $status = 302, array $headers = []): int { return 42; }';
        $declaration .= $signedDeclarations;
        if ($mode === '--inherited-trait') {
            $ancestors = "trait RouteMethods$class { $declaration }\n";
            $declaration = "use RouteMethods$class;";
        }
        $ancestors .= "class Intermediate$class extends Facade { $declaration }\nclass $parent extends Intermediate$class {}\n";
    }
    file_put_contents(
        $framework.'/Support/Facades/'.$class.'.php',
        "<?php\nnamespace Illuminate\\Support\\Facades;\n/** @method static $signature $signedSignatures */\n$ancestors class $class extends $parent { protected static function getFacadeAccessor() { $body } $extra }\n",
    );
}
// Keep the installed declarations and complete signed forwarding bodies.
foreach (['UrlGenerator', 'Redirector'] as $class) {
    copy(__DIR__.'/fixtures/analysis/signed-route-'.$class.'.php.stub', $framework.'/Routing/'.$class.'.php');
}
if ($mode === '--changed-signed-signature') {
    $path = $framework.'/Routing/UrlGenerator.php';
    file_put_contents($path, str_replace(
        'public function signedRoute($name, $parameters = [], $expiration = null, $absolute = true)',
        'public function signedRoute($name, $parameters = [], $expiration = 123, $absolute = true)',
        file_get_contents($path),
    ));
}
if ($mode === '--custom-url-method') {
    mkdir($workspace.'/custom');
    rename($framework.'/Routing/UrlGenerator.php', $workspace.'/custom/UrlGenerator.php');
}
file_put_contents($workspace.'/support.php', <<<'PHP'
    <?php
    namespace Illuminate\Http { class RedirectResponse {} }
    namespace App {
        class CustomURL extends \Illuminate\Support\Facades\URL {}
        class CustomGenerator extends \Illuminate\Routing\UrlGenerator {}
        class URL { public static function route(string $name): string { return $name; } }
        enum RouteName: string { case Home = 'home'; }
    }
    PHP);
$binding = match ($mode) {
    '--url-binding' => 'url',
    '--url-contract-binding' => 'Illuminate\\Contracts\\Routing\\UrlGenerator',
    '--redirect-binding' => 'redirect',
    default => null,
};
if ($binding !== null) {
    mkdir($workspace.'/bootstrap');
    file_put_contents(
        $workspace.'/bootstrap/bindings.php',
        '<?php \\app()->bind('.var_export($binding, true).', \\stdClass::class);',
    );
}
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'named-routes' => [
                'complete' => true,
                'missing-route-resolver' => false,
                'names' => ['home', 'account.show'],
            ],
            'named-route-parameters' => [
                'native-url-generation' => true,
                'url-defaults-complete' => true,
                'routes' => ['account.show' => ['account']],
            ],
            'binding-files' => $binding !== null ? ['bootstrap/bindings.php'] : [],
        ],
    ],
], JSON_THROW_ON_ERROR));
$missing = ['ichinya/laramago/laramago-missing-named-route'];
$parameterMissing = ['ichinya/laramago/laramago-missing-named-route-parameter'];
$urlMissing = in_array($mode, ['', '--custom-doc', '--redirect-binding', '--changed-signed-signature'], true)
    ? $missing
    : [];
$redirectMissing = in_array($mode, ['', '--custom-doc', '--changed-signed-signature'], true) ? $missing : [];
$urlParameterMissing = in_array($mode, ['', '--custom-doc', '--redirect-binding', '--changed-signed-signature'], true)
    ? $parameterMissing
    : [];
$redirectParameterMissing = in_array($mode, ['', '--custom-doc', '--changed-signed-signature'], true)
    ? $parameterMissing
    : [];
$cases = [
    'known URL route' => ['NativeURL::route("home");', 'void', []],
    'known redirect route' => ['Redirect::route("home");', 'void', []],
    'URL route required parameter omitted' => [
        'NativeURL::route("account.show");',
        'void',
        $urlParameterMissing,
    ],
    'URL route required parameter supplied' => [
        'NativeURL::route("account.show", ["account" => 7]);',
        'void',
        [],
    ],
    'redirect route required parameter omitted' => [
        'Redirect::route("account.show");',
        'void',
        $redirectParameterMissing,
    ],
    'imported URL alias missing' => ['NativeURL::route("typo");', 'void', $urlMissing],
    'fully qualified URL missing' => ['\\Illuminate\\Support\\Facades\\URL::route("typo");', 'void', $urlMissing],
    'group import redirect missing' => ['Redirect::route("typo");', 'void', $redirectMissing],
    'reordered named URL name' => ['NativeURL::route(absolute: false, name: "typo");', 'void', $urlMissing],
    'reordered named redirect name' => ['Redirect::route(status: 301, route: "typo");', 'void', $redirectMissing],
    'dynamic name deferred' => ['NativeURL::route($name);', 'void', []],
    'concatenation deferred' => ['Redirect::route("home.".$name);', 'void', []],
    'unpacked arguments deferred' => ['NativeURL::route(...["typo"]);', 'void', []],
    'first class callable deferred' => ['$callback = NativeURL::route(...);', 'void', []],
    'enum deferred' => ['NativeURL::route(RouteName::Home);', 'void', []],
    'facade subclass deferred' => ['CustomURL::route("typo");', 'void', []],
    'same short name deferred' => ['URL::route("typo");', 'void', []],
    'invalid argument retained' => ['NativeURL::route(3);', 'void', ['invalid-argument']],
    'return contract retained' => ['return NativeURL::route("home");', 'bool', ['invalid-return-statement']],
    'unknown method retained' => ['NativeURL::missing();', 'void', ['non-documented-method']],
];
if (in_array($mode, ['--custom-doc', '--custom-method', '--inherited-method', '--inherited-trait'], true)) {
    $cases['custom return contract retained'] = [
        'return NativeURL::route("home");',
        $mode === '--custom-doc' ? 'int' : 'string',
        [],
    ];
}
foreach (['signedRoute', 'temporarySignedRoute'] as $method) {
    if ($mode === '--changed-signed-signature') {
        $urlMissing = $redirectMissing = [];
    }
    $expiration = $method === 'temporarySignedRoute' ? ', 123' : '';
    $namedExpiration = $method === 'temporarySignedRoute' ? ', expiration: 123' : '';
    $directUrlMissing = in_array($mode, ['--custom-url-method', '--changed-signed-signature'], true) ? [] : $missing;
    $directRedirectMissing = in_array(
        $mode,
        ['--custom-url-method', '--url-binding', '--url-contract-binding', '--changed-signed-signature'],
        true,
    )
        ? []
        : $missing;
    $signedUrlParameterMissing = $mode === '--changed-signed-signature' ? [] : $urlParameterMissing;
    $signedRedirectParameterMissing = $mode === '--changed-signed-signature' ? [] : $redirectParameterMissing;
    $directUrlParameterMissing = in_array($mode, ['--custom-url-method', '--changed-signed-signature'], true)
        ? []
        : $parameterMissing;
    $directRedirectParameterMissing = in_array(
        $mode,
        ['--custom-url-method', '--url-binding', '--url-contract-binding', '--changed-signed-signature'],
        true,
    )
        ? []
        : $parameterMissing;
    $cases[$method.' known URL'] = ['NativeURL::'.$method.'("home"'.$expiration.');', 'void', []];
    $cases[$method.' URL required parameter omitted'] = [
        'NativeURL::'.$method.'("account.show"'.$expiration.');',
        'void',
        $signedUrlParameterMissing,
    ];
    $cases[$method.' redirect required parameter omitted'] = [
        'Redirect::'.$method.'("account.show"'.$expiration.');',
        'void',
        $signedRedirectParameterMissing,
    ];
    $cases[$method.' direct URL required parameter omitted'] = [
        '$url->'.$method.'("account.show"'.$expiration.');',
        'void',
        $directUrlParameterMissing,
    ];
    $cases[$method.' direct redirect required parameter omitted'] = [
        '$redirect->'.$method.'("account.show"'.$expiration.');',
        'void',
        $directRedirectParameterMissing,
    ];
    $cases[$method.' URL literal'] = ['NativeURL::'.$method.'("typo"'.$expiration.');', 'void', $urlMissing];
    $cases[$method.' redirect literal'] = ['Redirect::'.$method.'("typo"'.$expiration.');', 'void', $redirectMissing];
    $cases[$method.' named URL'] = [
        'NativeURL::'.$method.'(absolute: false, name: "typo"'.$namedExpiration.');',
        'void',
        $urlMissing,
    ];
    $cases[$method.' named redirect'] = [
        'Redirect::'.$method.'(status: 301, route: "typo"'.$namedExpiration.');',
        'void',
        $redirectMissing,
    ];
    $cases[$method.' dynamic'] = ['NativeURL::'.$method.'($name'.$expiration.');', 'void', []];
    $cases[$method.' enum'] = ['NativeURL::'.$method.'(RouteName::Home'.$expiration.');', 'void', []];
    $cases[$method.' unpacked'] = ['NativeURL::'.$method.'(...["typo"'.$expiration.']);', 'void', []];
    $cases[$method.' callable'] = ['$callback = NativeURL::'.$method.'(...);', 'void', []];
    $cases[$method.' subclass'] = ['CustomURL::'.$method.'("typo"'.$expiration.');', 'void', []];
    $cases[$method.' direct URL'] = ['$url->'.$method.'("typo"'.$expiration.');', 'void', $directUrlMissing];
    $cases[$method.' direct redirect'] = [
        '$redirect->'.$method.'(route: "typo"'.$namedExpiration.');',
        'void',
        $directRedirectMissing,
    ];
    $cases[$method.' direct subclass'] = ['$custom->'.$method.'("typo"'.$expiration.');', 'void', []];
    $cases[$method.' invalid argument retained'] = [
        'NativeURL::'.$method.'(3'.$expiration.');',
        'void',
        ['invalid-argument'],
    ];
    $cases[$method.' return contract retained'] = [
        'return NativeURL::'.$method.'("home"'.$expiration.');',
        'bool',
        ['invalid-return-statement'],
    ];
    $cases[$method.' invalid expiration retained'] = [
        '$url->'.$method.'("home", expiration: new \\stdClass);',
        'void',
        ['possibly-invalid-argument'],
    ];
    if ($mode === '--custom-doc') {
        $cases[$method.' custom PHPDoc retained'] = [
            'return NativeURL::'.$method.'("home"'.$expiration.');',
            'int',
            [],
        ];
    }
}
$source = <<<'PHP'
    <?php
    namespace App;
    use Illuminate\Support\Facades\{URL as NativeURL, Redirect};
    PHP;
$lines = $spans = [];
$nativeCodes = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    foreach ($codes as $code) {
        if (! in_array($code, [$missing[0], $parameterMissing[0]], true)) {
            $nativeCodes[] = $code;
        }
    }
    $source .= '/** @return '.$return.' */'."\n";
    $source .=
        'function scenario'
        .count($lines)
        .'(string $name, \\Illuminate\\Routing\\UrlGenerator $url, \\Illuminate\\Routing\\Redirector $redirect, CustomGenerator $custom) { '
        .$body
        .' }'
        ."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
    $spans[substr_count($source, "\n")] = in_array($missing[0], $codes, true) ? ['"typo"'] : [];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => ['vendor', 'support.php', ...($mode === '--custom-url-method' ? ['custom'] : [])],
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
        || array_column($report['issues'] ?? [], 'code') !== $nativeCodes
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
    || array_column($report['issues'] ?? [], 'code') !== $nativeCodes
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
