<?php

declare(strict_types=1);

// Analyze generated source with the real Mago worker. Never execute application PHP.
// Laravel excerpts: copyright Taylor Otwell; see fixtures/analysis/assert-view-is-LICENSE.md.
if (! isset($argv[1])) {
    foreach ([
        'native',
        'disabled',
        'absent',
        'malformed',
        'changed-assertion',
        'changed-view-guard',
    ] as $mode) {
        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, $mode],
            [STDIN, STDOUT, STDERR],
            $pipes,
        );
        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('assertViewIs identity reference scenario failed: '.$mode);
        }
    }
    exit(0);
}

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago assert view identity '.bin2hex(random_bytes(8));
$mode = $argv[1];
if (! in_array(
    $mode,
    ['native', 'disabled', 'absent', 'malformed', 'changed-assertion', 'changed-view-guard'],
    true,
)) {
    throw new RuntimeException('Unknown mode.');
}

$testing = $workspace.'/vendor/laravel/framework/src/Illuminate/Testing';
mkdir($testing, 0777, true);
$fixture = $package.'/tests/fixtures/analysis/';
$response = file_get_contents($fixture.'assert-view-is-TestResponse.php.stub');
if ($response === false) {
    throw new RuntimeException('Cannot read TestResponse fixture.');
}
if ($mode === 'changed-assertion') {
    $response = str_replace(
        'assertEquals($value, $this->original->name())',
        'assertEquals($value, "changed")',
        $response,
    );
}
if ($mode === 'changed-view-guard') {
    $response = str_replace(
        '$this->original instanceof View',
        '$this->original instanceof \\stdClass',
        $response,
    );
}
file_put_contents($testing.'/TestResponse.php', $response);
file_put_contents($workspace.'/support.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\View {
        interface View { public function name(); }
    }
    namespace Illuminate\Testing {
        class TestResponseAssert {
            public static function withResponse(TestResponse $response): self { return new self; }
            public function assertEquals(mixed $expected, mixed $actual): void {}
            public function fail(string $message): never { throw new \RuntimeException($message); }
        }
    }
    namespace App {
        class ChildResponse extends \Illuminate\Testing\TestResponse {}
        class CustomResponse { public function assertViewIs(string $value): self { return $this; } }
    }
    PHP);

$enabled = in_array($mode, ['native', 'changed-assertion', 'changed-view-guard'], true);
$policy = [
    'enabled' => $enabled,
    'identities' => ['dashboard', 'external/custom.php', '', '  exact identity  ', 'dashboard'],
];
if ($mode === 'malformed') {
    $policy['enabled'] = true;
    $policy['identities'][] = 42;
}
$laramago = $mode === 'absent' ? [] : ['assert-view-identity-policy' => $policy];
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => $laramago],
], JSON_THROW_ON_ERROR));

$warning = ['ichinya/laramago/laramago-assert-view-identity-outside-policy'];
$active = $enabled && $mode === 'native';
$cases = [
    ['permitted conventional identity',   '$response->assertViewIs("dashboard");',               'none'],
    ['permitted file identity',           '$response->assertViewIs("external/custom.php");',     'none'],
    ['permitted empty identity',          '$response->assertViewIs("");',                        'none'],
    ['permitted whitespace identity',     '$response->assertViewIs("  exact identity  ");',      'none'],
    ['outside conventional identity',     '$response->assertViewIs("typo");',                    'native'],
    ['outside arbitrary identity',        '$response->assertViewIs("unlisted.synthetic.name");', 'native'],
    ['outside file identity',             '$response->assertViewIs("other/custom.php");',        'native'],
    ['named value argument',              '$response->assertViewIs(value: "typo");',             'native'],
    ['case remains exact',                '$response->assertViewIs("Dashboard");',               'native'],
    ['dynamic identity defers',           '$response->assertViewIs($identity);',                 'none'],
    ['concatenated identity defers',      '$response->assertViewIs("dash".$identity);',          'none'],
    ['unpacked identity defers',          '$response->assertViewIs(...["typo"]);',               'none'],
    ['first class callable defers',       '$callable = $response->assertViewIs(...);',           'none'],
    ['subclass receiver defers',          '$child->assertViewIs("typo");',                       'none'],
    ['unrelated receiver defers',         '$custom->assertViewIs("typo");',                      'none'],
    ['native PHPDoc diagnostic retained', '$response->assertViewIs(42);',                        'invalid'],
];
$source = <<<'PHP'
    <?php
    namespace App;
    use Illuminate\Testing\TestResponse;
    PHP;
$source .= "\n";
$expected = [];
$expectedSpans = [];
foreach ($cases as $offset => [$label, $body, $target]) {
    $source .=
        'function case'
        .$offset
        .'(TestResponse $response, ChildResponse $child, CustomResponse $custom, string $identity): void { '
        .$body
        ." }\n";
    $line = substr_count($source, "\n");
    $codes = match ($target) {
        'native' => $active ? $warning : [],
        'invalid' => ['invalid-argument'],
        default => [],
    };
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
            'vendor/laravel/framework/src/Illuminate/Testing/TestResponse.php',
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
    if ($issue['code'] === 'ichinya/laramago/laramago-assert-view-identity-outside-policy') {
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
// Every mode retains one native PHPDoc error to prove the advisory does not suppress Mago.
if ($exit !== 1) {
    throw new RuntimeException('Unexpected exit '.$exit.'; inspect '.$workspace);
}
