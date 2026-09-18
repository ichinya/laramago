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
    $parent = 'Facade';
    $ancestors = '';
    if (in_array($mode, ['--inherited-method', '--inherited-trait'], true)) {
        $parent = 'Bridge'.$class;
        $declaration = $class === 'URL'
            ? 'public static function route(\\BackedEnum|string $name, mixed $parameters = [], bool $absolute = true): int { return 42; }'
            : 'public static function route(\\BackedEnum|string $route, mixed $parameters = [], int $status = 302, array $headers = []): int { return 42; }';
        if ($mode === '--inherited-trait') {
            $ancestors = "trait RouteMethods$class { $declaration }\n";
            $declaration = "use RouteMethods$class;";
        }
        $ancestors .= "class Intermediate$class extends Facade { $declaration }\nclass $parent extends Intermediate$class {}\n";
    }
    file_put_contents(
        $framework.'/Support/Facades/'.$class.'.php',
        "<?php\nnamespace Illuminate\\Support\\Facades;\n/** @method static $signature */\n$ancestors class $class extends $parent { protected static function getFacadeAccessor() { $body } $extra }\n",
    );
}
// Match the installed declarations and Redirector's forwarding argument semantics.
file_put_contents($framework.'/Routing/UrlGenerator.php', <<<'PHP'
    <?php
    namespace Illuminate\Routing;
    use BackedEnum;
    use InvalidArgumentException;
    use Symfony\Component\Routing\Exception\RouteNotFoundException;
    class UrlGenerator {
        /** @param \BackedEnum|string $name @param mixed $parameters @param bool $absolute @return string */
        public function route($name, $parameters = [], $absolute = true) {
            if ($name instanceof BackedEnum && ! is_string($name = $name->value)) {
                throw new InvalidArgumentException('Attribute [name] expects a string backed enum.');
            }
            if (! is_null($route = $this->routes->getByName($name))) {
                return $this->toRoute($route, $parameters, $absolute);
            }
            if (! is_null($this->missingNamedRouteResolver) &&
                ! is_null($url = call_user_func($this->missingNamedRouteResolver, $name, $parameters, $absolute))) {
                return $url;
            }
            throw new RouteNotFoundException("Route [{$name}] not defined.");
        }
    }
    PHP);
file_put_contents($framework.'/Routing/Redirector.php', <<<'PHP'
    <?php
    namespace Illuminate\Routing;
    class Redirector {
        protected $generator;
        /** @param \BackedEnum|string $route @param mixed $parameters @param int $status @param array $headers @return \Illuminate\Http\RedirectResponse */
        public function route($route, $parameters = [], $status = 302, $headers = []) {
            return $this->to($this->generator->route($route, $parameters), $status, $headers);
        }
        public function to($path, $status = 302, $headers = [], $secure = null) { throw new \RuntimeException('Never execute'); }
    }
    PHP);
if ($mode === '--custom-url-method') {
    mkdir($workspace.'/custom');
    rename($framework.'/Routing/UrlGenerator.php', $workspace.'/custom/UrlGenerator.php');
}
file_put_contents($workspace.'/support.php', <<<'PHP'
    <?php
    namespace Illuminate\Http { class RedirectResponse {} }
    namespace App {
        class CustomURL extends \Illuminate\Support\Facades\URL {}
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
            'named-routes' => ['complete' => true, 'missing-route-resolver' => false, 'names' => ['home']],
            'binding-files' => $binding !== null ? ['bootstrap/bindings.php'] : [],
        ],
    ],
], JSON_THROW_ON_ERROR));
$missing = ['ichinya/laramago/laramago-missing-named-route'];
$urlMissing = in_array($mode, ['', '--custom-doc', '--redirect-binding'], true) ? $missing : [];
$redirectMissing = in_array($mode, ['', '--custom-doc'], true) ? $missing : [];
$cases = [
    'known URL route' => ['NativeURL::route("home");', 'void', []],
    'known redirect route' => ['Redirect::route("home");', 'void', []],
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
$source = <<<'PHP'
    <?php
    namespace App;
    use Illuminate\Support\Facades\{URL as NativeURL, Redirect};
    PHP;
$lines = $spans = [];
$nativeCodes = ['invalid-argument', 'invalid-return-statement', 'non-documented-method'];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'(string $name) { '.$body.' }'."\n";
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
