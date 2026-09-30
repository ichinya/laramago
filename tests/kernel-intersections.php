<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago kernel contracts '.bin2hex(random_bytes(8));
$contracts = $workspace.'/vendor/laravel/framework/src/Illuminate/Contracts';
mkdir($contracts.'/Http', recursive: true);
mkdir($contracts.'/Console', recursive: true);
$http = <<<'PHP'
<?php
namespace Illuminate\Contracts\Http;
interface Kernel {
    /** @param \Symfony\Component\HttpFoundation\Request $request
     * @return \Symfony\Component\HttpFoundation\Response */
    public function handle($request);
    /** @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Symfony\Component\HttpFoundation\Response $response
     * @return void */
    public function terminate($request, $response);
}
PHP;
$console = <<<'PHP'
<?php
namespace Illuminate\Contracts\Console;
interface Kernel {
    /** @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface|null $output
     * @return int */
    public function handle($input, $output = null);
    /** @param \Symfony\Component\Console\Input\InputInterface $input
     * @param int $status
     * @return void */
    public function terminate($input, $status);
}
PHP;
file_put_contents($workspace.'/composer.json', json_encode(['autoload' => ['files' => ['bootstrap.php']]], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/types.php', <<<'PHP'
<?php
namespace Symfony\Component\HttpFoundation {
    class Request {}
    class Response { public function send(): static { return $this; } }
}
namespace Symfony\Component\Console\Input { interface InputInterface {} }
namespace Symfony\Component\Console\Output { interface OutputInterface {} }
namespace Fixtures {
    interface ThirdKernel {}
    function consumeResponse(\Symfony\Component\HttpFoundation\Response $response): void {}
    function kernel(): \Illuminate\Contracts\Http\Kernel|\Illuminate\Contracts\Console\Kernel { throw new \RuntimeException('Fixture bodies must not execute.'); }
    function input(): \Symfony\Component\Console\Input\InputInterface { throw new \RuntimeException('Fixture bodies must not execute.'); }
}
PHP);
$cases = [
    'HTTP positive guard' => ['function http(Http|Console $kernel, Request $request): void { if ($kernel instanceof Http) { $response = $kernel->handle($request); $response->send(); $kernel->terminate($request, $response); } }', 'fixed'],
    'console positive guard' => ['function console(Http|Console $kernel, Input $input): int { if ($kernel instanceof Console) { return $kernel->handle($input); } return 0; }', 'fixed'],
    'console optional output' => ['function output(Http|Console $kernel, Input $input, Output $output): int { if ($kernel instanceof Console) { return $kernel->handle($input, $output); } return 0; }', 'fixed'],
    'HTTP named arguments' => ['function httpNamed(Http|Console $kernel, Request $request): void { if ($kernel instanceof Http) { $response = $kernel->handle(request: $request); $kernel->terminate(response: $response, request: $request); } }', 'fixed'],
    'console named arguments' => ['function consoleNamed(Http|Console $kernel, Input $input): int { if ($kernel instanceof Console) { return $kernel->handle(output: null, input: $input); } return 0; }', 'fixed'],
    'earlier complementary guards' => ['function previous(Http|Console $kernel, Request $request, bool $http): void { if ($http) { if (!($kernel instanceof Http)) { throw new \RuntimeException(); } } elseif (!($kernel instanceof Console)) { throw new \RuntimeException(); } if ($kernel instanceof Http) { $response = $kernel->handle($request); $response->send(); $kernel->terminate($request, $response); } }', 'fixed'],
    'explicit intersection HTTP guard' => ['function bothHttpGuard(Http&Console $kernel, Request $request): void { if ($kernel instanceof Http) { $response = $kernel->handle($request); $response->send(); } }', 'fixed'],
    'explicit intersection console guard' => ['function bothConsoleGuard(Http&Console $kernel, Input $input): int { if ($kernel instanceof Console) { return $kernel->handle($input); } throw new \RuntimeException(); }', 'fixed'],
    'explicit intersection HTTP request' => ['function bothRequest(Http&Console $kernel, Request $request): void { $kernel->handle($request); }', 'same'],
    'explicit intersection console input' => ['function bothInput(Http&Console $kernel, Input $input): void { $kernel->handle($input); }', 'same'],
    'reverse explicit intersection HTTP request' => ['function reverseRequest(Console&Http $kernel, Request $request): void { $kernel->handle($request); }', 'same'],
    'reverse explicit intersection console input' => ['function reverseInput(Console&Http $kernel, Input $input): void { $kernel->handle($input); }', 'same'],
    'unguarded union' => ['function unguarded(Http|Console $kernel, Request $request): void { $response = $kernel->handle($request); $response->send(); }', 'same'],
    'negative branch' => ['function negative(Http|Console $kernel, Request $request): void { if (!($kernel instanceof Http)) { $kernel->handle($request); } }', 'same'],
    'else branch' => ['function alternative(Http|Console $kernel, Request $request): void { if ($kernel instanceof Http) {} else { $kernel->handle($request); } }', 'same'],
    'wrong HTTP input' => ['function wrongHttp(Http|Console $kernel): void { if ($kernel instanceof Http) { $kernel->handle("bad"); } }', 'error'],
    'wrong console input' => ['function wrongConsole(Http|Console $kernel): void { if ($kernel instanceof Console) { $kernel->handle("bad"); } }', 'error'],
    'uncorrelated terminate arguments' => ['function wrongPair(Http|Console $kernel, Request $request): void { if ($kernel instanceof Http) { $kernel->terminate($request, 0); } }', 'error'],
    'extra HTTP argument' => ['function extra(Http|Console $kernel, Request $request): void { if ($kernel instanceof Http) { $kernel->handle($request, null); } }', 'error'],
    'missing HTTP argument' => ['function missing(Http|Console $kernel): void { if ($kernel instanceof Http) { $kernel->handle(); } }', 'error'],
    'wrong HTTP argument name' => ['function wrongName(Http|Console $kernel, Request $request): void { if ($kernel instanceof Http) { $kernel->handle(input: $request); } }', 'error'],
    'wrong console return' => ['function wrongReturn(Http|Console $kernel, Input $input): Response { if ($kernel instanceof Console) { return $kernel->handle($input); } throw new \RuntimeException(); }', 'error'],
    'native interface remains strict' => ['function pure(Http $kernel, Request $request): void { $kernel->handle($request, null); }', 'same'],
    'reassigned receiver' => ['function reassigned(Http|Console $kernel, Console $other, Request $request): void { if ($kernel instanceof Http) { $kernel = $other; $kernel->handle($request); } }', 'same'],
    'reference alias' => ['function alias(Http|Console $kernel, Request $request): void { $alias =& $kernel; if ($kernel instanceof Http) { $kernel->handle($request); } }', 'same'],
    'reference parameter' => ['function reference(Http|Console &$kernel, Request $request): void { if ($kernel instanceof Http) { $kernel->handle($request); } }', 'same'],
    'foreach reference alias' => ['/** @param list<Http|Console> $kernels */ function foreachAlias(array $kernels, Request $request): void { foreach ($kernels as &$kernel) { if ($kernel instanceof Http) { $kernel->handle($request); } } }', 'same'],
    'symbol table reference' => ['function extracted(Http|Console $kernel, Request $request, array $values): void { extract($values, EXTR_REFS); if ($kernel instanceof Http) { $kernel->handle($request); } }', 'same'],
    'aliased symbol table function' => ['function extractedAlias(Http|Console $kernel, Request $request, array $values): void { symbolImport($values, EXTR_REFS); if ($kernel instanceof Http) { $kernel->handle($request); } }', 'same'],
    'aliased parse function' => ['function parsedAlias(Http|Console $kernel, Request $request): void { parseQuery("", $values); if ($kernel instanceof Http) { $kernel->handle($request); } }', 'same'],
    'dynamic function call' => ['function dynamicCall(Http|Console $kernel, Request $request, callable $run): void { $run(); if ($kernel instanceof Http) { $kernel->handle($request); } }', 'same'],
    'eval in scope' => ['function evaluated(Http|Console $kernel, Request $request, string $code): void { eval($code); if ($kernel instanceof Http) { $kernel->handle($request); } }', 'same'],
    'included scope' => ['function included(Http|Console $kernel, Request $request, string $path): void { include $path; if ($kernel instanceof Http) { $kernel->handle($request); } }', 'same'],
    'jump boundary' => ['function jumped(Http|Console $kernel, Request $request): void { goto label; label: if ($kernel instanceof Http) { $kernel->handle($request); } }', 'same'],
    'superglobal root' => ['/** @param Http|Console $_SERVER */ function globalRoot(Http|Console $_SERVER, Request $request): void { if ($_SERVER instanceof Http) { $_SERVER->handle($request); } }', 'same'],
    'closure boundary' => ['function closure(Http|Console $kernel, Request $request): void { if ($kernel instanceof Http) { $run = static function () use ($kernel, $request): void { $kernel->handle($request); }; $run(); } }', 'same'],
    'additional intersection member' => ['/** @param (Console&ThirdKernel)|Http $kernel */ function third(Console|Http $kernel, Request $request): void { if ($kernel instanceof Http) { $kernel->handle($request); } }', 'same'],
    'top-level HTTP after include' => ['require __DIR__."/bootstrap.php"; $httpKernel = kernel(); if ($httpKernel instanceof Http) { $request = new Request(); $response = $httpKernel->handle($request); $response->send(); $httpKernel->terminate($request, $response); }', 'fixed'],
    'top-level console' => ['$consoleKernel = kernel(); if ($consoleKernel instanceof Console) { $input = input(); $status = $consoleKernel->handle($input); $consoleKernel->terminate($input, $status); }', 'fixed'],
    'top-level reassignment' => ['$mutatedKernel = kernel(); if ($mutatedKernel instanceof Http) { $mutatedKernel = kernel(); $mutatedKernel->handle(new Request()); }', 'same'],
    'top-level reference' => ['$referencedKernel = kernel(); if ($referencedKernel instanceof Http) { $alias =& $referencedKernel; $referencedKernel->handle(new Request()); }', 'same'],
];
$source = "<?php\nnamespace Fixtures;\nuse Illuminate\\Contracts\\Http\\Kernel as Http;\nuse Illuminate\\Contracts\\Console\\Kernel as Console;\nuse Symfony\\Component\\HttpFoundation\\Request;\nuse Symfony\\Component\\HttpFoundation\\Response;\nuse Symfony\\Component\\Console\\Input\\InputInterface as Input;\nuse Symfony\\Component\\Console\\Output\\OutputInterface as Output;\nuse function extract as symbolImport;\nuse function parse_str as parseQuery;\n";
$lines = [];
foreach ($cases as $label => [$code]) {
    $lines[substr_count($source, "\n")] = $label;
    $source .= $code."\n";
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/kernel-contracts', 'Kernel contracts', '1', analyzerPlugins: [new \Ichinya\Laramago\Analyzer\KernelIntersectionPlugin($argv[2])])))->run();
PHP);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$variants = ['native-contracts', 'external-source', 'changed-http-param', 'changed-console-return', 'changed-native-param', 'wrong-source', 'span-collision'];
$reports = [];
foreach ($variants as $variant) {
    $path = match ($variant) {
        'wrong-source' => $workspace.'/custom',
        'external-source' => $workspace.' external/vendor/laravel/framework/src/Illuminate/Contracts',
        default => $contracts,
    };
    if (! is_dir($path.'/Http')) {
        mkdir($path.'/Http', recursive: true);
        mkdir($path.'/Console', recursive: true);
    }
    file_put_contents($path.'/Http/Kernel.php', match ($variant) {
        'changed-http-param' => str_replace('@param \\Symfony\\Component\\HttpFoundation\\Request $request', '@param string $request', $http),
        'changed-native-param' => str_replace('handle($request)', 'handle(object $request)', $http),
        default => $http,
    });
    file_put_contents($path.'/Console/Kernel.php', $variant === 'changed-console-return' ? str_replace('@return int', '@return string', $console) : $console);
    $collision = str_replace('namespace Fixtures;', 'namespace Collided;', $source);
    $collision = str_replace('$kernel->handle', '$kernel->unused', $collision);
    file_put_contents($workspace.'/collision.php', $collision);
    $modes = in_array('--integrated', $argv, true) ? ['native', 'isolated', 'integrated'] : ['native', 'isolated'];
    foreach ($modes as $mode) {
        $configuration = [
            'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
            'source' => ['paths' => $variant === 'span-collision' ? ['cases.php', 'collision.php'] : ['cases.php'], 'includes' => ['types.php', $path]],
            'extension-hosts' => $mode === 'native' ? new stdClass : ['test' => [
                'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => 2,
            ]],
        ];
        file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
        $name = $variant.'-'.$mode;
        $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
            0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$name.'.json', 'w'], 2 => ['file', $workspace.'/'.$name.'.log', 'w'],
        ], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start Mago.');
        }
        fclose($pipes[0]);
        $exit = proc_close($process);
        $report = json_decode(file_get_contents($workspace.'/'.$name.'.json'), true);
        if (! is_array($report) || ! isset($report['issues'])) {
            throw new RuntimeException("Mago $name failed ($exit): ".file_get_contents($workspace.'/'.$name.'.log'));
        }
        $reports[$name] = [];
        foreach ($report['issues'] as $issue) {
            $span = $issue['annotations'][0]['span'] ?? [];
            if (($span['file_id']['name'] ?? '') !== 'cases.php' || $issue['level'] !== 'Error') {
                continue;
            }
            $label = $lines[$span['start']['line'] ?? -1] ?? null;
            if ($label !== null) {
                $reports[$name][$label][] = $issue['code'].'|'.$issue['message'];
            }
        }
        foreach ($cases as $label => [$code, $expectation]) {
            $errors = $reports[$name][$label] ?? [];
            $native = $reports[$variant.'-native'][$label] ?? [];
            $enabled = in_array($variant, ['native-contracts', 'external-source'], true) && $mode !== 'native';
            if ($enabled && $expectation === 'fixed') {
                if ($native === [] || $errors !== []) {
                    throw new RuntimeException("Unexpected $name errors for $label: ".json_encode($errors)."; workspace $workspace");
                }
            } elseif ($enabled && $expectation === 'error') {
                if ($errors === []) {
                    throw new RuntimeException("Lost $name negative: $label; workspace $workspace");
                }
            } elseif ($mode !== 'native') {
                sort($native);
                sort($errors);
                if ($errors !== $native) {
                    throw new RuntimeException("Changed $name diagnostics for $label: ".json_encode([$native, $errors])."; workspace $workspace");
                }
            }
        }
        echo "$name: ".count($cases)." kernel cases verified\n";
    }
}
echo "Kernel contract tests passed. Workspace: $workspace\n";

require $package.'/vendor/autoload.php';
$cancel = new class implements \Mago\Sdk\CancellationTokenInterface {
    public bool $cancelled = false;
    public function isCancelled(): bool { return $this->cancelled; }
    public function throwIfCancelled(): void { if ($this->cancelled) { throw new RuntimeException('Scan cancelled.'); } }
    public function subscribe(Closure $callback): int { return 0; }
    public function unsubscribe(int $subscription): void {}
};
$version = \Mago\Sdk\PHPVersion::fromParts(8, 2);
$file = static function (string $path, string $contents) use ($version): \Mago\Sdk\Syntax\SourceFile {
    return new \Mago\Sdk\Syntax\SourceFile($version, $path, $contents, [],
        (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
};
$index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\KernelIntersectionCalls;
$snapshot = '<?php use Illuminate\\Contracts\\Http\\Kernel; function guarded($kernel, $request) { if ($kernel instanceof Kernel) { $kernel->handle($request); } }';
$start = strpos($snapshot, '$kernel->handle');
$span = new \Mago\Sdk\Span($start, strpos($snapshot, ';', $start));
$scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $cancel): void {
    $index->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($version, $cancel, $files, $first, $last));
};
$expect = static function (bool $known) use ($index, $span): void {
    if ($index->owner($span, 'handle') !== ($known ? 'Illuminate\\Contracts\\Http\\Kernel' : null)) {
        throw new RuntimeException('Unexpected source inventory or span identity state.');
    }
    if ($index->owner($span, 'terminate') !== null) {
        throw new RuntimeException('Method identity was lost.');
    }
};
$expect(false);
$scan([$file('/unsaved.php', $snapshot)], last: false);
$expect(false);
$scan([], first: false);
$expect(true);
$scan([$file('/first.php', $snapshot), $file('/second.php', $snapshot)]);
$expect(false);
foreach (['$kernel->unused', 'arbitrary______', 'new Arbitrary__'] as $replacement) {
    $scan([$file('/first.php', $snapshot), $file('/second.php', str_replace('$kernel->handle', $replacement, $snapshot))]);
    $expect(false);
}
$scan([$file('/unsaved.php', $snapshot), $file('/broken.php', '<?php function broken( {')]);
$expect(false);
$scan([$file('/unsaved.php', $snapshot), $file('/large.php', '<?php /*'.str_repeat('x', 2_000_000).'*/')]);
$expect(false);
$scan([$file('/unsaved.php', $snapshot)]);
$expect(true);
$changed = str_replace('instanceof Kernel', 'instanceof Object', $snapshot);
$scan([$file('/unsaved.php', $changed)]);
$expect(false);
$cancel->cancelled = true;
try {
    $scan([$file('/unsaved.php', $snapshot)], first: false);
    throw new RuntimeException('Cancellation was ignored.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Scan cancelled.') {
        throw $error;
    }
}
$expect(false);
$cancel->cancelled = false;
$scan([$file('/unsaved.php', $snapshot)]);
$expect(true);
echo "Source inventory: batching, collisions, limits, cancellation, and current snapshots verified\n";
