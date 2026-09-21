<?php

declare(strict_types=1);

// Analyze generated source with the real Mago worker. Never execute application PHP.
// Laravel excerpts: copyright Taylor Otwell; see fixtures/analysis/gate-ability.LICENSE.md.
if (! isset($argv[1])) {
    foreach ([
        'native',
        'disabled',
        'absent',
        'malformed',
        'changed-gate',
        'changed-facade',
        'custom-binding',
    ] as $mode) {
        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, $mode],
            [STDIN, STDOUT, STDERR],
            $pipes,
        );
        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Gate ability reference scenario failed: '.$mode);
        }
    }
    exit(0);
}

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago gate abilities '.bin2hex(random_bytes(8));
$mode = $argv[1];
if (! in_array(
    $mode,
    ['native', 'disabled', 'absent', 'malformed', 'changed-gate', 'changed-facade', 'custom-binding'],
    true,
)) {
    throw new RuntimeException('Unknown mode.');
}

$gateDirectory = $workspace.'/vendor/laravel/framework/src/Illuminate/Auth/Access';
$facadeDirectory = $workspace.'/vendor/laravel/framework/src/Illuminate/Support/Facades';
mkdir($gateDirectory, 0777, true);
mkdir($facadeDirectory, 0777, true);
$fixture = $package.'/tests/fixtures/analysis/';
$gate = file_get_contents($fixture.'gate-ability-Gate.php.stub');
$gateFacade = file_get_contents($fixture.'gate-ability-GateFacade.php.stub');
if ($gate === false || $gateFacade === false) {
    throw new RuntimeException('Cannot read Gate fixtures.');
}
if ($mode === 'changed-gate') {
    $gate = str_replace('return $this->check($ability, $arguments);', 'return true;', $gate);
}
if ($mode === 'changed-facade') {
    $gateFacade = str_replace('@method static bool allows(', '@method static string allows(', $gateFacade);
}
file_put_contents($gateDirectory.'/Gate.php', $gate);
file_put_contents($facadeDirectory.'/Gate.php', $gateFacade);
copy($fixture.'gate-ability-Facade.php.stub', $facadeDirectory.'/Facade.php');
file_put_contents($workspace.'/support.php', <<<'PHP'
    <?php
    namespace Illuminate\Auth\Access {
        class Response { public function allowed(): bool { return true; } public function authorize(): self { return $this; } public static function allow(): self { return new self; } public static function deny(): self { return new self; } }
        class AuthorizationException extends \Exception { public function toResponse(): Response { return new Response; } }
    }
    namespace Illuminate\Support {
        class Collection { public function __construct(mixed $items) {} public function every(callable $callback): bool { return true; } public function contains(callable $callback): bool { return true; } }
        class Arr { public static function wrap(mixed $value): array { return []; } }
        function enum_value(mixed $value): mixed { return $value; }
    }
    namespace { function tap(mixed $value, callable $callback): mixed { return $value; } }
    PHP);

$enabled = in_array($mode, ['native', 'changed-gate', 'changed-facade', 'custom-binding'], true);
$policy = ['enabled' => $enabled, 'abilities' => ['known', '', '  odd ability  ', 'known']];
if ($mode === 'malformed') {
    $policy['abilities'][] = 42;
}
$laramago = $mode === 'absent' ? [] : ['gate-ability-policy' => $policy];
if ($mode === 'custom-binding') {
    $laramago['binding-files'] = ['app/bindings.php'];
    mkdir($workspace.'/app');
    file_put_contents(
        $workspace.'/app/bindings.php',
        '<?php \\app()->bind(\\Illuminate\\Contracts\\Auth\\Access\\Gate::class, App\\CustomGate::class);',
    );
}
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => $laramago],
], JSON_THROW_ON_ERROR));

$warning = ['ichinya/laramago/laramago-gate-ability-outside-policy'];
$nativeMethods = $enabled && $mode !== 'changed-gate' && $mode !== 'malformed';
$nativeFacade = $nativeMethods && $mode !== 'changed-facade' && $mode !== 'custom-binding';
$cases = [
    ['known instance ability',       '$gate->allows("known");',                                  'none'],
    ['instance allows',              '$gate->allows("typo");',                                   'instance'],
    ['instance denies',              '$gate->denies("typo");',                                   'instance'],
    ['instance check singular',      '$gate->check("typo");',                                    'instance'],
    ['instance any singular',        '$gate->any("typo");',                                      'instance'],
    ['instance none singular',       '$gate->none("typo");',                                     'instance'],
    ['instance authorize',           '$gate->authorize("typo");',                                'instance'],
    ['instance inspect',             '$gate->inspect("typo");',                                  'instance'],
    ['instance raw',                 '$gate->raw("typo");',                                      'instance'],
    ['named ability',                '$gate->authorize(ability: "typo");',                       'instance'],
    ['reordered named ability',      '$gate->authorize(arguments: [], ability: "typo");',        'instance'],
    ['named abilities',              '$gate->check(abilities: "typo");',                         'instance'],
    ['facade allows',                'NativeGate::allows("typo");',                              'facade'],
    ['facade authorize',             '\\Illuminate\\Support\\Facades\\Gate::authorize("typo");', 'facade'],
    ['permitted empty ability',      '$gate->authorize("");',                                    'none'],
    ['permitted unusual ability',    '$gate->authorize("  odd ability  ");',                     'none'],
    ['literal ability array defers', '$gate->check(["typo"]);',                                  'none'],
    ['dynamic ability defers',       '$gate->authorize($ability);',                              'none'],
    ['concatenated ability defers',  '$gate->authorize("ty".$ability);',                         'none'],
    ['enum ability defers',          '$gate->authorize(Permission::Known);',                     'none'],
    ['custom gate defers',           '$custom->authorize("typo");',                              'none'],
    ['native subclass defers',       '$child->authorize("typo");',                               'none'],
    ['first class callable defers',  '$callable = $gate->authorize(...);',                       'none'],
];
$source = <<<'PHP'
    <?php
    namespace App;
    use Illuminate\Auth\Access\Gate;
    use Illuminate\Support\Facades\Gate as NativeGate;
    enum Permission: string { case Known = 'known'; }
    class CustomGate { public function authorize(string $ability, mixed $arguments = []): bool { return true; } }
    class ChildGate extends Gate {}
    PHP;
$source .= "\n";
$expected = [];
$expectedSpans = [];
foreach ($cases as $offset => [$label, $body, $target]) {
    $source .=
        'function case'
        .$offset
        .'(Gate $gate, CustomGate $custom, ChildGate $child, string $ability): void { '
        .$body
        ." }\n";
    $line = substr_count($source, "\n");
    $codes = $target === 'instance' && $nativeMethods || $target === 'facade' && $nativeFacade ? $warning : [];
    $expected[$line] = [$label, $codes];
    if ($codes === $warning) {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"/', $body, $matches);
        $expectedSpans[$line] = [end($matches[0])];
    }
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'support.php',
            'vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php',
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'vendor/laravel/framework/src/Illuminate/Support/Facades/Gate.php',
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
            'workers' => 2,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
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
    if ($issue['code'] === 'ichinya/laramago/laramago-gate-ability-outside-policy') {
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
$stderr = file_get_contents($workspace.'/stderr.log');
if (
    $actual !== []
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        $stderr === false ? '' : $stderr,
    )
) {
    throw new RuntimeException('Unexpected issues or worker failure; inspect '.$workspace);
}
if ($exit !== 0) {
    throw new RuntimeException('Unexpected exit '.$exit.'; inspect '.$workspace);
}
