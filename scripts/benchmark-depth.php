<?php

declare(strict_types=1);

// Public invented witnesses. Use read-only dependencies and a disposable fixture root.
$options = getopt('', ['dependencies:', 'root:', 'mago:', 'output:']);
foreach (['dependencies', 'root', 'mago', 'output'] as $key) {
    if (! isset($options[$key])) {
        throw new InvalidArgumentException('Required: --dependencies --root --mago --output');
    }
}
$root = $options['root'];
$dependencies = $options['dependencies'];
if (is_writable($root.'/vendor') || is_writable($dependencies.'/vendor')) {
    throw new RuntimeException('Both dependency mounts must be read-only.');
}
foreach (['app', 'depth', 'config', 'database/migrations', 'bootstrap/cache', 'storage/framework/views', 'storage/logs'] as $directory) {
    if (! is_dir($root.'/'.$directory)) {
        mkdir($root.'/'.$directory, recursive: true);
    }
}
$fixture = dirname(__DIR__).'/tests/fixtures/analyzer-depth/cases.php';
copy($fixture, $root.'/depth/cases.php');
file_put_contents($root.'/composer.json', json_encode(['name' => 'benchmark/analyzer-depth', 'require' => ['laravel/framework' => '13.31.0']]));
file_put_contents($root.'/config/depth.php', '<?php return ["count" => 7];');
file_put_contents($root.'/config/app.php', '<?php return ["name" => "AnalyzerDepth", "env" => "testing", "debug" => false];');
file_put_contents($root.'/config/database.php', '<?php return ["default" => "sqlite", "connections" => ["sqlite" => ["driver" => "sqlite", "database" => ":memory:", "prefix" => ""]]];');
file_put_contents($root.'/bootstrap/providers.php', '<?php return [];');
file_put_contents($root.'/bootstrap/app.php', '<?php return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))->create();');
file_put_contents($root.'/benchmark-autoload.php', '<?php $loader = require __DIR__."/vendor/autoload.php"; $loader->addClassMap(["AnalyzerDepth\\\\DepthModel" => __DIR__."/depth/cases.php", "AnalyzerDepth\\\\SchemaModel" => __DIR__."/depth/cases.php"]);');
file_put_contents($root.'/database/migrations/2026_01_01_000000_create_schema_models.php', <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('schema_models', function (Blueprint $table): void { $table->id(); $table->string('label'); }); }
};
PHP);
$ini = $root.'/ini';
mkdir($ini);
file_put_contents($ini.'/benchmark.ini', "opcache.enable_cli=0\nopcache.jit=disable\nopcache.jit_buffer_size=0\nmemory_limit=2G\n");
putenv('PHP_INI_SCAN_DIR='.$ini);
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_BASE_PATH' => $root,
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
}
putenv('LARAMAGO_EVALUATE_RUNTIME');
putenv('LARAMAGO_RUNTIME_METADATA');
chdir($root);
$run = static function (array $command) use ($root, $options): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start analyzer.');
    }
    fclose($pipes[0]);
    // Drain both streams while the analyzer runs.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $start = microtime(true);
    do {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (microtime(true) - $start > 180) {
            proc_terminate($process, 9);
            throw new RuntimeException('Depth analyzer deadline exceeded.');
        }
        usleep(10000);
    } while ($status['running']);
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if (! in_array($status['exitcode'], [0, 1], true)
        || preg_match('/external plugin error|violated the protocol|did not answer request|internal error|analysis.*incomplete/i', $err)) {
        file_put_contents(dirname($options['output']).'/depth-failure-private.txt', $err."\n".$out);
        throw new RuntimeException('Depth analyzer infrastructure failure.');
    }
    return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
};
$markers = [];
$autoloadControl = $run([PHP_BINARY, '-r', 'require '.var_export($root.'/benchmark-autoload.php', true).'; $class = new ReflectionClass("AnalyzerDepth\\\\SchemaModel"); $model = $class->newInstanceWithoutConstructor(); echo json_encode(["loaded" => true, "table" => $model->getTable()]);']);
if (($autoloadControl['table'] ?? null) !== 'schema_models') {
    throw new RuntimeException('The minimal application cannot autoload the model fixture.');
}
foreach (file($fixture) as $index => $line) {
    if (preg_match('/DEPTH ([a-z-]+) (bad|good)/', $line, $match)) {
        if (isset($markers[$match[1]][$match[2]])) {
            throw new RuntimeException('Duplicate depth marker.');
        }
        $markers[$match[1]][$match[2]] = [$index + 1];
        if ($match[1] === 'incompatible-phpdoc') {
            $markers[$match[1]][$match[2]][] = $index + 2;
        }
    }
}
foreach ($markers as $pair) {
    if (array_keys($pair) !== ['bad', 'good']) {
        throw new RuntimeException('Every witness needs an independently marked corrected control.');
    }
}
$select = static function (array $byLine) use ($markers): array {
    $result = [];
    foreach ($markers as $id => $pair) {
        foreach ($pair as $kind => $lines) {
            $codes = [];
            foreach ($lines as $line) {
                array_push($codes, ...($byLine[$line] ?? []));
            }
            $result[$id][$kind] = array_values(array_unique($codes));
        }
    }
    return $result;
};
$report = ['schema_version' => 1, 'fixture_sha256' => hash_file('sha256', $fixture),
    'harness_sha256' => hash_file('sha256', __FILE__),
    'case_count' => count($markers), 'phpstan_levels' => [], 'complete' => false,
    'expectations_sha256' => hash_file('sha256', dirname($fixture).'/expectations.json'), 'php' => PHP_VERSION, 'versions' => []];
$report['model_autoload_control'] = true;
foreach (json_decode(file_get_contents($dependencies.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR)['packages'] as $package) {
    if (in_array($package['name'], ['phpstan/phpstan', 'larastan/larastan', 'laravel/framework', 'ichinya/laramago', 'carthage-software/mago'], true)) {
        $report['versions'][$package['name']] = $package['version'];
    }
}
$jobs = array_map(static fn (int $level): array => ['level' => $level, 'config_types' => true], range(0, 10));
$jobs[] = ['level' => 10, 'config_types' => false];
foreach ($jobs as $job) {
    $level = $job['level'];
    $cache = '/tmp/depth-phpstan-'.bin2hex(random_bytes(6));
    file_put_contents($root.'/phpstan.neon', "includes:\n    - vendor/larastan/larastan/extension.neon\nparameters:\n    paths:\n        - depth\n    tmpDir: ".$cache."\n    ignoreErrors: []\n    checkConfigTypes: ".($job['config_types'] ? 'true' : 'false')."\n    bootstrapFiles:\n        - benchmark-autoload.php\n    parallel:\n        maximumNumberOfProcesses: 2\n");
    $body = $run([PHP_BINARY, $dependencies.'/vendor/bin/phpstan', 'analyse', '-c', $root.'/phpstan.neon',
        '--level='.$level, '--error-format=json', '--no-progress', '--no-ansi', '--memory-limit=2G']);
    if (($body['totals']['errors'] ?? 1) !== 0) {
        throw new RuntimeException('Depth PHPStan had non-file errors.');
    }
    $byLine = [];
    foreach ($body['files'] as $path => $file) {
        if (basename($path) !== 'cases.php') {
            throw new RuntimeException('Unexpected source in depth result.');
        }
        foreach ($file['messages'] as $issue) {
            $byLine[$issue['line']][] = $issue['identifier'] ?? 'uncoded';
        }
    }
    if ($job['config_types']) {
        $report['phpstan_levels'][(string) $level] = $select($byLine);
    } else {
        $report['phpstan_default_config_types_level10'] = $select($byLine);
    }
}
$report['primary_matrix_checkConfigTypes'] = true;
$includes = [];
foreach (['autoload_psr4.php', 'autoload_namespaces.php', 'autoload_classmap.php', 'autoload_files.php'] as $map) {
    foreach (require $dependencies.'/vendor/composer/'.$map as $locations) {
        foreach ((array) $locations as $location) {
            $location = realpath($location);
            if ($location !== false && str_starts_with($location, $dependencies.'/vendor/')) {
                $includes[str_replace($dependencies.'/vendor/', $root.'/vendor/', $location)] = true;
            }
        }
    }
}
$config = ['extends' => [$root.'/vendor/ichinya/laramago/presets/laravel.toml'], 'php-version' => '8.5', 'threads' => 2,
    'source' => ['paths' => ['depth'], 'includes' => array_keys($includes),
        'excludes' => ['vendor/laravel/framework/tests/Foundation/Fixtures/bad-syntax-strategy.php', 'vendor/testo/bridge-symfony-console/resources/stubs/testo.php']],
    'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, '-d', 'memory_limit=2G', '-d', 'opcache.enable_cli=0',
        $root.'/vendor/ichinya/laramago/bin/laramago-worker.php', $dependencies.'/vendor/autoload.php', $root],
        'workers' => 2, 'request-timeout-ms' => 120000]]];
file_put_contents($root.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
foreach (['native', 'laramago'] as $mode) {
    $command = [$options['mago'], '--config', $root.'/mago.json', '--threads', '2', 'analyze', 'depth', '--reporting-format=json'];
    if ($mode === 'native') {
        $command[] = '--no-extensions';
    }
    $body = $run($command);
    if (! isset($body['issues']) || ! is_array($body['issues'])) {
        throw new RuntimeException('Mago did not return a complete issue report.');
    }
    $byLine = [];
    foreach ($body['issues'] as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary') {
                if (basename(str_replace('\\', '/', $annotation['span']['file_id']['name'])) !== 'cases.php') {
                    throw new RuntimeException('Unexpected primary source in depth result.');
                }
                $byLine[$annotation['span']['start']['line'] + 1][] = $issue['code'].'/'.$issue['level'];
            }
        }
    }
    $report['mago_'.$mode] = $select($byLine);
}
$report['complete'] = true;
file_put_contents($options['output'], json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['complete' => true, 'cases' => count($markers)])."\n";
