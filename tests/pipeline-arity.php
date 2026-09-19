<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-pipeline-arity-'.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Pipeline';
mkdir($framework, 0777, true);
$native = file_get_contents(__DIR__.'/fixtures/analysis/pipeline-native.php.stub');
file_put_contents($workspace.'/support.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Pipeline { interface Pipeline {} }
    namespace Illuminate\Contracts\Container { interface Container {} }
    namespace Illuminate\Support\Traits { trait Conditionable {} trait Macroable {} }
    PHP);
file_put_contents($workspace.'/objects.php', <<<'PHP'
    <?php
    namespace Example;
    class RequiredPipe { public function handle($a, $b, $c) {} }
    class OptionalPipe { public function handle($a, $b, $c = 'default') {} }
    class VariadicPipe { public function handle($a, $b, ...$args) {} }
    class FewPipe { public function handle($a) {} }
    class CallableBoth { public function __invoke($a, $b) {} public function handle($a, $b, $c) {} }
    class RequiredInvoke { public function __invoke($a, $b, $c) {} public function handle($a, $b) {} }
    class OptionalBeforeRequired { public function handle($a, $b, $c = 1, $d) {} }
    class ProtectedMagic { protected function handle($a, $b, $c) {} public function __call($name, $args) {} }
    class ProtectedPipe { protected function handle($a, $b, $c) {} }
    class DocPipe { /** @param mixed $a */ public function handle($a, $b, $c) {} }
    class Constructed { public function __construct() {} public function handle($a, $b, $c) {} }
    class InheritsRequired extends RequiredPipe {}
    trait RequiredMethods { public function handle($a, $b, $c) {} }
    class UsesTrait { use RequiredMethods; }
    class EmptyPipe {}
    class Handles { public function handle($value, $next) { return $next($value); } }
    class Invokes { public function __invoke($value, $next) { return $next($value); } }
    class Both extends Handles { public function __invoke($value, $next) { return $next($value); } }
    class Magic { public function __call($name, $args) {} }
    trait Methods { public function run($value, $next) {} }
    class Adapted { use Methods { run as handle; } }
    class Inherits extends Handles {}
    class Incomplete extends Missing {}
    abstract class AbstractPipe {}
    /** @method void handle(mixed $value, mixed $next) */
    class Documented {}
    class CustomPipeline extends \Illuminate\Pipeline\Pipeline {}
    PHP);
$prefix = '(new \Illuminate\Pipeline\Pipeline)->send("value")->through(';
$cases = [
    $prefix.'[new RequiredPipe])->thenReturn();',
    $prefix.'[new OptionalPipe])->thenReturn();',
    $prefix.'[new VariadicPipe])->thenReturn();',
    $prefix.'[new FewPipe])->thenReturn();',
    $prefix.'[new CallableBoth])->thenReturn();',
    $prefix.'[new RequiredInvoke])->thenReturn();',
    $prefix.'[new OptionalBeforeRequired])->thenReturn();',
    $prefix.'[new ProtectedMagic])->thenReturn();',
    $prefix.'[new ProtectedPipe])->thenReturn();',
    $prefix.'[new DocPipe])->thenReturn();',
    $prefix.'[new Constructed])->thenReturn();',
    $prefix.'[new InheritsRequired])->thenReturn();',
    $prefix.'[new UsesTrait])->thenReturn();',
    $prefix.'[new Incomplete])->thenReturn();',
    $prefix.'[RequiredPipe::class])->thenReturn();',
    $prefix.'[new OptionalPipe, new RequiredPipe])->thenReturn();',
    $prefix.'[new RequiredPipe(1)])->thenReturn();',
    $prefix.'[0 => new RequiredPipe, 0 => new OptionalPipe])->thenReturn();',
    $prefix.'[...[], new RequiredPipe])->thenReturn();',
    $prefix.'[new RequiredPipe])->via("other")->thenReturn();',
    '(new CustomPipeline)->send("value")->through([new RequiredPipe])->thenReturn();',
];
file_put_contents($workspace.'/cases.php', "<?php\nnamespace Example;\n".implode("\n", $cases));
foreach ([
    'native',
    'crlf',
    'disabled',
    'changed-carry',
    'changed-default',
    'changed-doc',
    'changed-then',
    'changed-finally',
    'changed-transaction',
] as $mode) {
    $source = match ($mode) {
        'crlf' => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $native)),
        'changed-carry' => str_replace('if (is_callable($pipe))', 'if (false)', $native),
        'changed-default' => str_replace("\$method = 'handle'", "\$method = 'other'", $native),
        'changed-doc' => str_replace('@return $this', '@return static', $native),
        'changed-then' => str_replace('array_reverse($this->pipes())', '[]', $native),
        'changed-finally' => str_replace('protected $finally;', 'protected $finally = true;', $native),
        'changed-transaction' => str_replace(
            'protected $withinTransaction = false;',
            'protected $withinTransaction = true;',
            $native,
        ),
        default => $native,
    };
    file_put_contents($framework.'/Pipeline.php', $source);
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['vendor', 'support.php', 'objects.php']],
        'extension-hosts' => $mode === 'disabled'
            ? new stdClass
            : [
                'laramago' => [
                    'command' => [
                        PHP_BINARY,
                        '-d',
                        'opcache.enable_cli=0',
                        $package.'/bin/laramago-worker.php',
                        $package.'/vendor/autoload.php',
                        $workspace,
                    ],
                    'workers' => 1,
                ],
            ],
    ];
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open(
        [PHP_BINARY, $package.'/vendor/bin/mago', '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
            2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
        ],
        $pipes,
    );
    fclose($pipes[0]);
    $exit = proc_close($process);
    if (
        $exit > 1
        || preg_match('/provider failed|rejected request|parse error/i', file_get_contents($workspace.'/'.$mode.'.log'))
    ) {
        throw new RuntimeException('Analyzer failed: '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $actual = [];
    $nativeCodes = [];
    foreach ($issues as $issue) {
        if ($issue['code'] !== 'ichinya/laramago/laramago-pipeline-required-arguments') {
            $nativeCodes[] = $issue['code'];
            continue;
        }
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary') {
                $actual[] = $annotation['span']['start']['line'] - 2;
                break;
            }
        }
    }
    sort($actual);
    if ($actual !== (in_array($mode, ['native', 'crlf'], true) ? [0, 5, 6] : [])) {
        throw new RuntimeException($mode.': '.json_encode($actual).' '.$workspace);
    }
    if (! in_array('duplicate-array-key', $nativeCodes, true)) {
        throw new RuntimeException('Native diagnostics lost: '.$workspace);
    }
    echo 'PASS '.$mode.PHP_EOL;
}
echo 'Fixture: '.$workspace.PHP_EOL;
