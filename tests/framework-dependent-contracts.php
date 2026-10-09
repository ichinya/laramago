<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago dependent contracts '.bin2hex(random_bytes(8));
mkdir($workspace, recursive: true);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
$files = [
    'Support/Str.php' => file_get_contents(__DIR__.'/fixtures/analysis/environment-str.php.stub'),
    'Database/Migrations/Migrator.php' => file_get_contents(__DIR__.'/fixtures/analysis/migrator-connection.php.stub'),
    'Http/RedirectResponse.php' => <<<'PHP'
        <?php namespace Illuminate\Http;
        class RedirectResponse {
            private Request $request;
            public function withInput(array $input): static { return $this; }
            public function onlyInput() { return $this->withInput($this->request->only(func_get_args())); }
            public function exceptInput() { return $this->withInput($this->request->except(func_get_args())); }
        }
        class Request { public function only(array $keys): array { return []; } public function except(array $keys): array { return []; } }
        PHP,
    'Support/Facades/Facade.php' => <<<'PHP'
        <?php namespace Illuminate\Support\Facades;
        class Facade {
            protected static array $resolvedInstance = [];
            protected static function getFacadeAccessor(): string { return ''; }
            protected static function isMock(): bool { return false; }
            protected static function createFreshMockInstance(): \Mockery\MockInterface { return new \Mockery\MockInterface; }
            /** @return \Mockery\Expectation */
            public static function shouldReceive() { $name = static::getFacadeAccessor(); $mock = static::isMock() ? static::$resolvedInstance[$name] : static::createFreshMockInstance(); return $mock->shouldReceive(...func_get_args()); }
            /** @return \Mockery\Expectation */
            public static function expects() { $name = static::getFacadeAccessor(); $mock = static::isMock() ? static::$resolvedInstance[$name] : static::createFreshMockInstance(); return $mock->expects(...func_get_args()); }
        }
        class Cache extends Facade {}
        PHP,
    'Foundation/Application.php' => <<<'PHP'
        <?php namespace Illuminate\Foundation;
        use Illuminate\Support\Str;
        class Application implements \ArrayAccess {
            /**
             * @param string|array ...$environments
             * @return string|bool
             */
            public function environment(...$environments) { if ($environments !== []) { $patterns = is_array($environments[0]) ? $environments[0] : $environments; return Str::is($patterns, $this['env']); } return $this['env']; }
            public function offsetGet(mixed $offset): mixed { return 'testing'; }
            public function offsetExists(mixed $offset): bool { return true; }
            public function offsetSet(mixed $offset, mixed $value): void {}
            public function offsetUnset(mixed $offset): void {}
        }
        PHP,
];
$preamble = <<<'PHP'
    <?php
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Foundation\Application;
    use Illuminate\Database\Migrations\Migrator;
    function acceptBool(bool $value): void {}
    function acceptInt(int $value): void {}
    class CustomRedirect extends RedirectResponse { public function onlyInput() {} }
    class CustomCache extends Cache { public static function shouldReceive() { return new \Mockery\Expectation; } }
    class CustomApplication extends Application { public function environment(...$environments) { return 'custom'; } }
    PHP;
file_put_contents($workspace.'/extras.php', <<<'PHP'
    <?php namespace Mockery { class Expectation {} class MockInterface { public function shouldReceive(...$args): Expectation { return new Expectation; } public function expects(...$args): Expectation { return new Expectation; } } }
    namespace Other { class Str { public static function is(mixed $patterns, mixed $value): string { return 'other'; } } }
    PHP);
file_put_contents($workspace.'/composer.json', '{}');
$config = [
    'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => [$framework, 'extras.php']],
    'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace], 'workers' => 1]],
];
foreach (['lf', 'crlf', 'changed-body', 'disabled', 'changed-helper', 'changed-import', 'changed-direct', 'token-collision'] as $mode) {
    foreach ($files as $path => $source) {
        $source = str_replace("\r\n", "\n", $source);
        if ($mode === 'changed-body') {
            $source = str_replace('func_get_args()', '[]', $source);
            $source = str_replace("return Str::is(\$patterns, \$this['env']);", "return 'custom';", $source);
            $source = str_replace('if (is_null($name))', 'if (false)', $source);
        }
        if ($mode === 'changed-helper' && $path === 'Support/Str.php') { $source = str_replace('return false;', "return 'wrong';", $source); }
        if ($mode === 'changed-import' && $path === 'Foundation/Application.php') { $source = str_replace('use Illuminate\\Support\\Str;', 'use Other\\Str;', $source); }
        if ($mode === 'token-collision' && $path === 'Foundation/Application.php') { $source = str_replace('return Str::is', 'returnStr::is', $source); }
        if ($mode === 'changed-direct' && $path === 'Database/Migrations/Migrator.php') { $source = str_replace("return \$this->resolver->connection(\$name)->hasDirectConnection() ? \$name.'::direct' : \$name;", "return 'changed';", $source); }
        if ($mode === 'crlf') { $source = str_replace("\n", "\r\n", $source); }
        if (! is_dir(dirname($framework.'/'.$path))) { mkdir(dirname($framework.'/'.$path), recursive: true); }
        file_put_contents($framework.'/'.$path, $source);
    }
    $enabled = ! in_array($mode, ['changed-body', 'disabled'], true);
    $environmentEnabled = $enabled && ! in_array($mode, ['changed-helper', 'changed-import', 'token-collision'], true);
    $migratorEnabled = $enabled && $mode !== 'changed-direct';
    $cases = [
        'null migration connection' => ['acceptInt((new Migrator)->usingConnection(null, fn (): int => 7));', $migratorEnabled ? [] : ['null-argument']],
        'named null migration connection' => ['acceptBool((new Migrator)->usingConnection(callback: fn (): bool => true, name: null));', $migratorEnabled ? [] : ['null-argument']],
        'migration callback type retained' => ['acceptBool((new Migrator)->usingConnection("sqlite", fn (): int => 7));', ['invalid-argument']],
        'migration invalid connection rejected' => ['(new Migrator)->usingConnection(7, fn (): int => 7);', ['invalid-argument']],
        'migration callback required' => ['(new Migrator)->usingConnection(null);', $migratorEnabled ? ['too-few-arguments'] : ['null-argument', 'too-few-arguments']],
        'redirect keys' => ['(new RedirectResponse)->onlyInput("a", "b");', $enabled ? [] : ['too-many-arguments']],
        'redirect excluded keys' => ['(new RedirectResponse)->exceptInput("a");', $enabled ? [] : ['too-many-arguments']],
        'facade expectations' => ['Cache::shouldReceive("get", "put");', $enabled ? [] : ['too-many-arguments']],
        'facade expects' => ['Cache::expects("get");', $enabled ? [] : ['too-many-arguments']],
        'environment patterns' => ['acceptBool((new Application)->environment("testing"));', $environmentEnabled ? [] : ['possibly-invalid-argument']],
        'array environment patterns' => ['acceptBool((new Application)->environment(["testing", "local"]));', $environmentEnabled ? [] : ['possibly-invalid-argument']],
        'getter retains string' => ['acceptBool((new Application)->environment());', ['possibly-invalid-argument']],
        'redirect override' => ['(new CustomRedirect)->onlyInput("a");', ['too-many-arguments']],
        'facade override' => ['CustomCache::shouldReceive("a");', ['too-many-arguments']],
        'environment override' => ['acceptBool((new CustomApplication)->environment("testing"));', ['possibly-invalid-argument']],
        'named extras remain rejected' => ['(new RedirectResponse)->onlyInput(extra: "a");', ['invalid-named-argument']],
        'unpacking remains native' => ['(new RedirectResponse)->onlyInput(...["a"]);', ['too-many-arguments']],
    ];
    $source = $preamble."\n";
    $lines = [];
    foreach ($cases as $name => [$body, $expected]) {
        $source .= 'function scenario'.count($lines).'(): void { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = [$name, $expected];
    }
    file_put_contents($workspace.'/cases.php', $source);
    $config['analyzer'] = ['disable-default-plugins' => $mode === 'disabled'];
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/stderr.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) { throw new RuntimeException('Mago failed: '.$workspace.' '.$log); }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        if ($issue['level'] !== 'Error') { continue; }
        $primary = array_values(array_filter($issue['annotations'], static fn (array $a): bool => $a['kind'] === 'Primary'))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? []; sort($codes); sort($expected);
        if ($codes !== $expected) { throw new RuntimeException($mode.' / '.$name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace); }
        unset($actual[$line]); echo 'PASS ['.$mode.']: '.$name."\n";
    }
    if ($actual !== []) { throw new RuntimeException('Unexpected diagnostics: '.$workspace); }
}
// Keep the source and JSON reports for inspection; no application files are touched.
