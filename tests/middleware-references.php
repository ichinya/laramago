<?php

declare(strict_types=1);

// Analyze generated source with the real Mago worker. Never execute application PHP.
// Laravel excerpt: Copyright (c) Taylor Otwell. See fixtures/analysis/route-middleware-native.LICENSE.md.
if (! isset($argv[1])) {
    foreach (['native', 'incomplete', 'malformed', 'absent', 'runtime-catalogs', 'changed-body'] as $mode) {
        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, $mode],
            [STDIN, STDOUT, STDERR],
            $pipes,
        );
        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Middleware reference scenario failed: '.$mode);
        }
    }
    exit(0);
}

$package = str_replace('\\', '/', dirname(__DIR__));
require $package.'/vendor/autoload.php';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago middleware references '.bin2hex(random_bytes(8));
$mode = $argv[1] ?? 'native';
if (! in_array($mode, ['native', 'incomplete', 'malformed', 'absent', 'runtime-catalogs', 'changed-body'], true)) {
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

$policy = [
    'complete' => $mode !== 'incomplete',
    'references' => ['auth', 'web', 'auth:admin', 'Handler::run', ''],
];
if ($mode === 'malformed') {
    $policy['references'][] = 42;
}
$laramago = match ($mode) {
    'absent' => [],
    'runtime-catalogs' => [
        'middleware-aliases' => ['files' => [], 'complete' => true],
        'middleware-groups' => ['files' => [], 'complete' => true],
    ],
    default => ['middleware-references' => $policy],
};
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => $laramago],
], JSON_THROW_ON_ERROR));
if ($mode === 'runtime-catalogs') {
    $aliases = new Ichinya\Laramago\Analyzer\StaticAnalysis\MiddlewareAliasCatalog($workspace);
    $groups = new Ichinya\Laramago\Analyzer\StaticAnalysis\MiddlewareGroupCatalog($workspace);
    if (! $aliases->isComplete() || ! $groups->isComplete()) {
        throw new RuntimeException('Runtime catalogs must be genuinely complete for this scenario.');
    }
}

$warning = ['ichinya/laramago/laramago-uncataloged-middleware-reference'];
$cases = [
    'known direct reference' => ['$route->middleware("auth");', []],
    'uncataloged direct reference' => ['$route->middleware("typo");', $warning, '"typo"'],
    'known literal list' => ['$route->middleware(["web", "auth"]);', []],
    'uncataloged literal list member' => ['$route->middleware(["web", "list-typo"]);', $warning, '"list-typo"'],
    'exact parameterized reference' => ['$route->middleware("auth:admin");', []],
    'parameter suffix is not stripped' => ['$route->middleware("auth:guest");', $warning, '"auth:guest"'],
    'exact callable string' => ['$route->middleware("Handler::run");', []],
    'callable string is not colon-parsed' => ['$route->middleware("Handler::other");', $warning, '"Handler::other"'],
    'permitted empty reference' => ['$route->middleware("");', []],
    'dynamic reference defers' => ['$route->middleware($dynamic);', []],
    'mixed dynamic list defers' => ['$route->middleware(["mixed-typo", $dynamic]);', []],
    'keyed list defers' => ['$route->middleware(["slot" => "keyed-typo"]);', []],
    'getter call is not a reference' => ['$route->middleware();', []],
    'named middleware argument' => ['$route->middleware(middleware: "named-typo");', $warning, '"named-typo"'],
    'extra positional arguments defer' => ['$route->middleware("auth", "extra-typo");', ['too-many-arguments']],
    'first-class callable is not invoked' => ['$callback = $route->middleware(...);', []],
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
        'includes' => ['vendor/laravel/framework/src/Illuminate/Routing/Route.php'],
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
if ($exit !== 1) {
    throw new RuntimeException($mode.': unexpected exit '.$exit.'; inspect '.$workspace);
}
