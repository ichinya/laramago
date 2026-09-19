<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-pipeline-dispatch-'.bin2hex(random_bytes(8));
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
    $prefix.'[new EmptyPipe])->thenReturn();',
    $prefix.'[new Handles])->thenReturn();',
    $prefix.'[new Invokes])->thenReturn();',
    $prefix.'[new Both])->thenReturn();',
    $prefix.'[new Magic])->thenReturn();',
    $prefix.'[new Adapted])->thenReturn();',
    $prefix.'[new Inherits])->thenReturn();',
    $prefix.'[new Incomplete])->thenReturn();',
    $prefix.'[new AbstractPipe])->thenReturn();',
    $prefix.'[new Documented])->thenReturn();',
    $prefix.'[new Handles, new EmptyPipe])->thenReturn();',
    $prefix.'[new EmptyPipe, new Handles])->thenReturn();',
    $prefix.'[0 => new EmptyPipe, 0 => new Handles])->thenReturn();',
    $prefix.'[...[], new EmptyPipe])->thenReturn();',
    $prefix.'[EmptyPipe::class])->thenReturn();',
    $prefix.'[new EmptyPipe])->via("other")->thenReturn();',
    $prefix.'[new EmptyPipe]);',
    '(new CustomPipeline)->send("value")->through([new EmptyPipe])->thenReturn();',
    '$p = new \Illuminate\Pipeline\Pipeline; $p->send("value")->through([new EmptyPipe])->thenReturn();',
];
file_put_contents($workspace.'/cases.php', "<?php\nnamespace Example;\n".implode("\n", $cases));
foreach ([
    'native',
    'crlf',
    'custom-vendor',
    'shadow-array_reverse',
    'shadow-array_reduce',
    'shadow-is_callable',
    'shadow-is_object',
    'shadow-is_array',
    'shadow-func_get_args',
    'shadow-method_exists',
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
    $vendor = $mode === 'custom-vendor' ? 'dependencies' : 'vendor';
    $directory = $workspace.'/'.$vendor.'/laravel/framework/src/Illuminate/Pipeline';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    file_put_contents($directory.'/Pipeline.php', $source);
    file_put_contents(
        $workspace.'/shadow.php',
        str_starts_with($mode, 'shadow-')
            ? '<?php namespace Illuminate\\Pipeline; function '.substr($mode, 7).'(...$args) { return []; }'
            : '<?php',
    );
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => [$vendor, 'support.php', 'objects.php', 'shadow.php']],
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
        if ($issue['code'] !== 'ichinya/laramago/laramago-missing-pipeline-dispatch') {
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
    if ($actual !== (in_array($mode, ['native', 'crlf', 'custom-vendor'], true) ? [0, 11] : [])) {
        throw new RuntimeException($mode.': '.json_encode($actual).' '.$workspace);
    }
    if (! in_array('duplicate-array-key', $nativeCodes, true)) {
        throw new RuntimeException('Native diagnostics lost: '.$workspace);
    }
    echo 'PASS '.$mode.PHP_EOL;
}
echo 'Fixture: '.$workspace.PHP_EOL;
