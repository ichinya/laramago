<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago transaction '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = $workspace.'/laravel/framework/src/Illuminate';
mkdir($framework.'/Foundation', 0777, true);
mkdir($framework.'/Support/Facades', 0777, true);
file_put_contents($framework.'/Foundation/Application.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation;
    class Application {
        public function registerCoreContainerAliases() {
            foreach (['db' => [\Illuminate\Database\DatabaseManager::class]] as $key => $aliases) {
                foreach ($aliases as $alias) { $this->alias($key, $alias); }
            }
        }
    }
    PHP);
file_put_contents($framework.'/Support/Facades/DB.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    /** @method static mixed transaction(\Closure $callback, int $attempts = 1) */
    class DB extends Facade {
        protected static function getFacadeAccessor() { return 'db'; }
    }
    PHP);
file_put_contents($framework.'/Support/Facades/Facade.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    class Facade {
        public static function getFacadeRoot() { return new \Illuminate\Database\DatabaseManager(); }
        protected static function resolveFacadeInstance($name) {}
        public static function __callStatic($method, $args) {
            $instance = static::getFacadeRoot();
            if (! $instance) { throw new \RuntimeException('A facade root has not been set.'); }
            return $instance->$method(...$args);
        }
    }
    PHP);
mkdir($framework.'/Database', 0777, true);
file_put_contents($framework.'/Database/DatabaseManager.php', <<<'PHP'
    <?php
    namespace Illuminate\Database;
    class DatabaseManager {
        public function connection() { return new Connection(); }
        public function __call($method, $parameters) { return $this->connection()->$method(...$parameters); }
    }
    PHP);
file_put_contents($workspace.'/framework.php', <<<'PHP'
    <?php
    class TransactionModel {
        public function fresh(): ?static { return random_int(0, 1) ? $this : null; }
        public function refresh(): static { return $this; }
    }
    class CustomDB extends \Illuminate\Support\Facades\DB {
        public static function transaction(\Closure $callback, int $attempts = 1): string { throw new \RuntimeException('Never execute'); }
    }
    PHP);
mkdir($framework.'/Database/Concerns', 0777, true);
file_put_contents($framework.'/Database/Concerns/ManagesTransactions.php', <<<'PHP'
    <?php
    namespace Illuminate\Database\Concerns;
    trait ManagesTransactions {
        /** @template TReturn
         * @param \Closure(static): TReturn $callback
         * @return TReturn
         */
        public function transaction(\Closure $callback, int $attempts = 1) {
            if ($attempts <= 0) { return null; }
            return $callback($this);
        }
    }
    PHP);
file_put_contents($framework.'/Database/Connection.php', <<<'PHP'
    <?php
    namespace Illuminate\Database;
    class Connection { use Concerns\ManagesTransactions; }
    PHP);
$disabled = in_array('--disabled', $argv, true);
$cases = $disabled
    ? [
        'disabled control' => [
            'return \Illuminate\Support\Facades\DB::transaction(fn (): int => 7);',
            'int',
            ['mixed-return-statement'],
        ],
    ] : [
        'integer result' => ['return \Illuminate\Support\Facades\DB::transaction(fn (): int => 7);', 'int', []],
        'nullable result' => ['return \Illuminate\Support\Facades\DB::transaction(fn (): ?int => null);', '?int', []],
        'void result' => ['return \Illuminate\Support\Facades\DB::transaction(function (): void {});', 'null', []],
        'null result' => ['return \Illuminate\Support\Facades\DB::transaction(fn (): null => null);', 'null', []],
        'never result' => [
            '\Illuminate\Support\Facades\DB::transaction(fn (): never => throw new \RuntimeException());',
            'never',
            [],
        ],
        'named retries' => [
            'return \Illuminate\Support\Facades\DB::transaction(attempts: 3, callback: fn (): int => 7);',
            'int',
            [],
        ],
        'positional callback named retries' => [
            'return \\Illuminate\\Support\\Facades\\DB::transaction(fn (): int => 7, attempts: 3);',
            'int',
            [],
        ],
        'captured typed model closure' => [
            '$model = new TransactionModel(); return \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($model): TransactionModel { return $model; }, attempts: 3);',
            'TransactionModel',
            [],
        ],
        'native contextual model result' => [
            '$model = new TransactionModel(); return \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($model): TransactionModel { return $model->refresh(); }, attempts: 3);',
            'TransactionModel',
            [],
        ],
        'nullable native contextual model result' => [
            '$model = new TransactionModel(); return \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($model): ?TransactionModel { return $model->fresh(); }, 3);',
            '?TransactionModel',
            [],
        ],
        'native contextual model result rejects wrong outer type' => [
            '$model = new TransactionModel(); return \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($model): TransactionModel { return $model->refresh(); });',
            'string',
            ['invalid-return-statement'],
        ],
        'untyped contextual result stays unresolved' => [
            '$model = new TransactionModel(); return \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($model) { return $model->refresh(); });',
            'TransactionModel',
            ['mixed-return-statement'],
        ],
        'PHPDoc-only contextual result stays unresolved' => [
            '$model = new TransactionModel(); /** @return TransactionModel */ $callback = function () use ($model) { return $model->refresh(); }; return \\Illuminate\\Support\\Facades\\DB::transaction($callback);',
            'TransactionModel',
            ['mixed-return-statement'],
        ],
        'array with mixed elements' => [
            'return \\Illuminate\\Support\\Facades\\DB::transaction(fn (): array => [json_decode("null")], attempts: 3);',
            'array<array-key, mixed>',
            [],
        ],
        'mixed element remains mixed' => [
            '$result = \\Illuminate\\Support\\Facades\\DB::transaction(fn (): array => [json_decode("null")]); return $result[0];',
            'int',
            ['mixed-return-statement'],
        ],
        'generic with mixed parameter' => [
            'return \\Illuminate\\Support\\Facades\\DB::transaction(fn (): \\ArrayObject => new \\ArrayObject([json_decode("null")]));',
            '\\ArrayObject<int, mixed>',
            [],
        ],
        'composite wrong outer result' => [
            'return \\Illuminate\\Support\\Facades\\DB::transaction(fn (): array => [json_decode("null")]);',
            'string',
            ['invalid-return-statement'],
        ],
        'zero retries' => ['return \Illuminate\Support\Facades\DB::transaction(fn (): int => 7, 0);', 'null', []],
        'negative retries' => ['return \Illuminate\Support\Facades\DB::transaction(fn (): int => 7, -1);', 'null', []],
        'unknown retries' => [
            '$attempts = random_int(-1, 2); return \Illuminate\Support\Facades\DB::transaction(fn (): int => 7, $attempts);',
            '?int',
            [],
        ],
        'wrong expected result' => [
            'return \Illuminate\Support\Facades\DB::transaction(fn (): int => 7);',
            'string',
            ['invalid-return-statement'],
        ],
        'callable string rejected' => [
            '\Illuminate\Support\Facades\DB::transaction("strlen");',
            'void',
            ['invalid-argument'],
        ],
        'wrong argument' => ['\Illuminate\Support\Facades\DB::transaction(4);', 'void', ['invalid-argument']],
        'wrong retries' => [
            '\Illuminate\Support\Facades\DB::transaction(fn (): int => 7, "three");',
            'void',
            ['invalid-argument'],
        ],
        'mixed result preserved' => [
            'return \Illuminate\Support\Facades\DB::transaction(fn (): mixed => json_decode("null"));',
            'int',
            ['mixed-return-statement'],
        ],
        'custom native result' => ['return CustomDB::transaction(fn (): int => 7);', 'string', []],
    ];
foreach ([
    '--changed-facade' => [$framework.'/Support/Facades/Facade.php', 'return $instance->$method(...$args);'],
    '--changed-manager' => [$framework.'/Database/DatabaseManager.php', 'return $this->connection()->$method(...$parameters);'],
    '--changed-connection' => [$framework.'/Database/DatabaseManager.php', 'return new Connection();'],
    '--changed-transaction' => [$framework.'/Database/Concerns/ManagesTransactions.php', 'return $callback($this);'],
] as $mode => [$path, $original]) {
    if (! in_array($mode, $argv, true)) {
        continue;
    }
    $source = file_get_contents($path);
    if (substr_count($source, $original) !== 1) {
        throw new RuntimeException('Cannot mutate the forwarding fixture.');
    }
    file_put_contents($path, str_replace($original, 'return null;', $source));
    $cases = [
        'changed forwarding defers' => [
            '$model = new TransactionModel(); return \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($model): TransactionModel { return $model->refresh(); });',
            'TransactionModel',
            ['mixed-return-statement'],
        ],
    ];
}
if (in_array('--custom-doc', $argv, true)) {
    $path = $framework.'/Support/Facades/DB.php';
    file_put_contents($path, str_replace(
        'static mixed transaction',
        'static string transaction',
        file_get_contents($path),
    ));
    $cases = [
        'custom PHPDoc preserved' => [
            'return \Illuminate\Support\Facades\DB::transaction(fn (): int => 7);',
            'string',
            [],
        ],
    ];
}
if (in_array('--custom-binding', $argv, true)) {
    mkdir($workspace.'/app');
    file_put_contents($workspace.'/app/bindings.php', '<?php app()->bind("db.connection", CustomDB::class);');
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['binding-files' => ['app/bindings.php']]],
    ]));
    $cases = [
        'custom connection binding defers' => [
            'return \Illuminate\Support\Facades\DB::transaction(fn (): int => 7);',
            'int',
            ['mixed-return-statement'],
        ],
    ];
}
$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => ['framework.php', $workspace.'/laravel'],
    ],
    'extension-hosts' => $disabled
        ? new stdClass
        : [
            'laramago' => [
                'command' => [
                    PHP_BINARY,
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
if (
    $exit !== (in_array('--custom-doc', $argv, true) ? 0 : 1)
    || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
) {
    throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
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
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics outside facade call scenarios; inspect '.$workspace);
}

removeContainerFactoryWorkspace($workspace);

function removeContainerFactoryWorkspace(string $workspace): void
{
    $resolved = realpath($workspace);
    $temporary = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refusing cleanup outside the temporary directory.');
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $path = $item->getPathname();
        if (! str_starts_with($path, $resolved.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing cleanup outside the test workspace.');
        }
        $item->isDir() ? rmdir($path) : unlink($path);
    }
    rmdir($resolved);
}
