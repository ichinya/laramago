<?php

declare(strict_types=1);

// Analyze generated source with the real Mago worker. Never execute application PHP.
// Laravel excerpt: Copyright (c) Taylor Otwell. See fixtures/analysis/route-middleware-native.LICENSE.md.
if (! isset($argv[1])) {
    foreach (['native', 'incomplete', 'absent', 'changed-body', 'missing-source', 'malformed'] as $mode) {
        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, $mode],
            [STDIN, STDOUT, STDERR],
            $pipes,
        );
        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Middleware parameter scenario failed: '.$mode);
        }
    }
    exit(0);
}

$package = str_replace('\\', '/', dirname(__DIR__));
require $package.'/vendor/autoload.php';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago middleware parameters '.bin2hex(random_bytes(8));
$mode = $argv[1] ?? 'native';
if (! in_array($mode, ['native', 'incomplete', 'absent', 'changed-body', 'missing-source', 'malformed'], true)) {
    throw new RuntimeException('Unknown mode.');
}
mkdir($workspace.'/vendor/laravel/framework/src/Illuminate/Routing', 0777, true);
$route = file_get_contents($package.'/tests/fixtures/analysis/route-middleware-native.php.stub');
if ($route === false) {
    throw new RuntimeException('Cannot read native Route fixture.');
}
if ($mode === 'changed-body') {
    $route = str_replace(
        '$middleware[$index] = (string) $value;',
        '$middleware[$index] = $value;',
        $route,
    );
}
file_put_contents(
    $workspace.'/vendor/laravel/framework/src/Illuminate/Routing/Route.php',
    $route,
);

file_put_contents(
    $workspace.'/aliases.php',
    '<?php return ["role" => App\\Role::class, "optional" => App\\OptionalRole::class, "invoke" => App\\Invokable::class, "many" => App\\Many::class, "ok" => App\\Role::class, "nested" => App\\OptionalRole::class];',
);
file_put_contents(
    $workspace.'/groups.php',
    '<?php return ["web" => ["role"], "nested" => ["web"], "ok" => ["role:admin"], "cycle" => ["cycle"], "empty-group" => ["role:"], "zero-group" => ["role:0"], "nested-empty" => ["empty-group"]];',
);
file_put_contents($workspace.'/handlers.php', <<<'PHP'
    <?php
    namespace App;
    class Role { public function handle(mixed $request, mixed $next, string $role): void {} }
    class InheritedRole extends Role {}
    class InheritedInvokable extends Invokable {}
    class OptionalRole { public function handle(mixed $request, mixed $next, string $role = 'guest'): void {} }
    class Many { public function handle(mixed $request, mixed $next, string ...$roles): void {} }
    class Invokable { public function __invoke(): void {} public function handle(mixed $request, mixed $next, string $role): void {} }
    PHP);
if ($mode === 'malformed') {
    file_put_contents($workspace.'/groups.php', '<?php return dynamicGroups();');
}
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'middleware-native-dispatch' => $mode !== 'absent',
            'middleware-aliases' => ['files' => ['aliases.php'], 'complete' => $mode !== 'incomplete'],
            'middleware-groups' => ['files' => ['groups.php'], 'complete' => true],
        ],
    ],
], JSON_THROW_ON_ERROR));
$warning = ['ichinya/laramago/laramago-missing-middleware-parameters'];
$cases = [
    'missing alias parameter' => ['$route->middleware("role");', $warning, '"role"'],
    'supplied alias parameter' => ['$route->middleware("role:admin");', []],
    'empty suffix is one argument' => ['$route->middleware("role:");', []],
    'direct zero suffix is one argument' => ['$route->middleware("role:0");', []],
    'group empty suffix is dropped' => ['$route->middleware("empty-group");', $warning, '"empty-group"'],
    'group zero suffix is dropped' => ['$route->middleware("zero-group");', $warning, '"zero-group"'],
    'nested group empty suffix is dropped' => ['$route->middleware("nested-empty");', $warning, '"nested-empty"'],
    'extra arguments allowed' => ['$route->middleware("role:admin,other");', []],
    'optional parameter' => ['$route->middleware("optional");', []],
    'variadic parameter' => ['$route->middleware("many");', []],
    'invokable defers' => ['$route->middleware("invoke");', []],
    'unknown alias defers' => ['$route->middleware("unknown");', []],
    'nested group missing parameter' => ['$route->middleware("nested");', $warning, '"nested"'],
    'group valid parameter' => ['$route->middleware("ok");', []],
    'group cycle defers' => ['$route->middleware("cycle");', []],
    'group with suffix is not group' => ['$route->middleware("web:value");', []],
    'class direct missing parameter' => ['$route->middleware("App\\\\Role");', $warning, '"App\\\\Role"'],
    'inherited handle' => ['$route->middleware("App\\\\InheritedRole");', $warning, '"App\\\\InheritedRole"'],
    'inherited invoke defers' => ['$route->middleware("App\\\\InheritedInvokable");', []],
    'literal list' => ['$route->middleware(["optional", "role"]);', $warning, '"role"'],
    'dynamic defers' => ['$route->middleware($dynamic);', []],
    'mixed array defers' => ['$route->middleware(["role", $dynamic]);', []],
    'callable string defers' => ['$route->middleware("App\\\\Role::handle");', []],
    'named argument' => ['$route->middleware(middleware: "role");', $warning, '"role"'],
    'getter no parameters' => ['$route->middleware();', []],
    'first class callable defers' => ['$callback = $route->middleware(...);', []],
];
$source = "<?php\nnamespace App;\nuse Illuminate\\Routing\\Route;\n";
$expected = [];
$expectedSpans = [];
$index = 0;
foreach ($cases as $label => $case) {
    [$body, $codes] = $case;
    $span = $case[2] ?? null;
    $source .= 'function case'.$index.'(Route $route, string $dynamic): void { '.$body." }\n";
    $index++;
    $line = substr_count($source, "\n");
    $expected[$line] = [
        $label,
        $mode === 'native' || $codes !== $warning ? $codes : [],
    ];
    if ($mode === 'native' && $codes === $warning) {
        $expectedSpans[$line] = [$span];
    }
}
$source .= <<<'PHP'
    final class CustomRoute
    {
        public function middleware(mixed $middleware = null): static
        {
            return $this;
        }
    }
    function customRoute(CustomRoute $route): void { $route->middleware("custom-typo"); }
    PHP;
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => $mode === 'missing-source'
            ? ['vendor/laravel/framework/src/Illuminate/Routing/Route.php']
            : ['vendor/laravel/framework/src/Illuminate/Routing/Route.php', 'handlers.php'],
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

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
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
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
$spans = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $actual[$line][] = $issue['code'];
    if ($issue['code'] === $warning[0]) {
        $spans[$line][] = substr(
            $source,
            $primary['span']['start']['offset'],
            $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
        );
    }
}
foreach ($expected as $line => [$label, $codes]) {
    sort($codes);
    $actual[$line] ??= [];
    sort($actual[$line]);
    if ($actual[$line] !== $codes) {
        throw new RuntimeException(
            $mode
            .' '
            .$label
            .': expected '
            .json_encode($codes)
            .', got '
            .json_encode($actual[$line])
            .'; inspect '
            .$workspace,
        );
    }
    if ($codes === $warning && ($spans[$line] ?? []) !== $expectedSpans[$line]) {
        throw new RuntimeException($mode.' '.$label.': wrong literal span; inspect '.$workspace);
    }
    unset($actual[$line]);
    echo 'PASS: '.$mode.' '.$label."\n";
}
if (
    $actual !== []
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/stderr.log'),
    )
) {
    throw new RuntimeException('Unexpected issues or worker failure; inspect '.$workspace);
}
if ($exit !== 0) {
    throw new RuntimeException($mode.': unexpected exit '.$exit.'; inspect '.$workspace);
}
