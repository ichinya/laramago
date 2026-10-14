<?php

declare(strict_types=1);

function runtimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{int, string, string} */
function runtimeProcess(array $command, string $root, string $name): array
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['file', $root . '/' . $name . '.out', 'w'],
            2 => ['file', $root . '/' . $name . '.err', 'w'],
        ],
        $pipes,
    );
    runtimeAssert(is_resource($process), 'Cannot start runtime control.');
    fclose($pipes[0]);
    $exit = proc_close($process);
    return [$exit, file_get_contents($root . '/' . $name . '.out'), file_get_contents($root . '/' . $name . '.err')];
}

/** Create synthetic databases before collection; never migrate or populate records. */
function runtimeProject(string $root, string $autoload): void
{
    foreach (['app', 'bootstrap/cache', 'config', 'database', 'storage/framework', 'vendor'] as $directory) {
        mkdir($root . '/' . $directory, 0777, true);
    }
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/composer.lock', '{}');
    file_put_contents($root . '/bootstrap/cache/packages.php', '<?php return [];');
    file_put_contents(
        $root . '/vendor/autoload.php',
        '<?php require '
        . var_export($autoload, true)
        . '; spl_autoload_register(static function(string $class): void { $prefix = "SyntheticRuntime\\\\"; if (str_starts_with($class, $prefix)) { require __DIR__."/../app/".substr($class, strlen($prefix)).".php"; } });',
    );
    file_put_contents(
        $root . '/bootstrap/app.php',
        '<?php file_put_contents(__DIR__."/entered", "yes"); if (is_file(__DIR__."/hang.flag")) { usleep(10000000); } return new \\Illuminate\\Foundation\\Application(dirname(__DIR__));',
    );
    file_put_contents(
        $root . '/config/app.php',
        '<?php return ["key" => "synthetic-only-no-real-secret", "providers" => ["NeverBootThisProvider"]];',
    );
    $alpha = $root . '/database/alpha.sqlite';
    $beta = $root . '/database/beta.sqlite';
    $replica = $root . '/database/replica.sqlite';
    foreach ([$alpha, $beta, $replica] as $database) {
        $pdo = new PDO('sqlite:' . $database);
        if ($database === $beta) {
            $pdo->exec('CREATE TABLE records (beta_only TEXT, score TEXT) STRICT');
        } elseif ($database === $replica) {
            $pdo->exec('CREATE TABLE p_records (replica_only TEXT)');
        } else {
            $pdo->exec(
                'CREATE TABLE records (score INTEGER, hard INTEGER NOT NULL, enabled INTEGER, label TEXT, accessed TEXT, alpha_only TEXT, native TEXT, documented INTEGER)',
            );
            $pdo->exec('CREATE TABLE p_records (write_only TEXT)');
            $pdo->exec('CREATE TABLE strict_records (amount INTEGER, title TEXT) STRICT');
        }
    }
    $connection = static fn(string $database, string $prefix = ''): array => [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => $prefix,
        'foreign_key_constraints' => true,
    ];
    $connections = [
        'alpha' => $connection($alpha),
        'beta' => $connection($beta),
        'prefixed' => $connection($alpha, 'p_'),
        'split' => $connection($alpha, 'p_'),
        'unavailable' => $connection($root . '/database/does-not-exist.sqlite'),
        'unsupported' => [
            'driver' => 'mysql',
            'database' => 'synthetic',
            'username' => 'synthetic',
            'password' => 'secret-canary-do-not-export',
        ],
    ];
    $connections['split']['read'] = ['database' => $replica];
    $connections['split']['write'] = ['database' => $alpha];
    file_put_contents(
        $root . '/config/database.php',
        '<?php return ' . var_export(['default' => 'alpha', 'connections' => $connections], true) . ';',
    );
    $models = [
        'Record' => 'protected $table = "records"; protected $casts = ["score" => "integer", "hard" => "integer", "enabled" => "boolean", "label" => NullStringCast::class]; public string $native = "native"; public function getAccessedAttribute(?string $value): string { return $value ?? "synthetic-null"; } public function setAccessedAttribute(?string $value): void { $this->attributes["accessed"] = $value; }',
        'BetaRecord' => 'protected $table = "records"; protected $connection = "beta";',
        'PrefixedRecord' => 'protected $table = "records"; protected $connection = "prefixed";',
        'SplitRecord' => 'protected $table = "records"; protected $connection = "split";',
        'StrictRecord' => 'protected $table = "strict_records";',
        'MissingRecord' => 'protected $table = "missing";',
        'UnavailableRecord' => 'protected $table = "records"; protected $connection = "unavailable";',
        'UnsupportedRecord' => 'protected $table = "records"; protected $connection = "unsupported";',
        'BootQueryRecord' => 'protected $table = "records"; protected static function booted(): void { static::query()->first(); }',
    ];
    foreach ($models as $name => $body) {
        $doc = $name === 'Record' ? '/** @property string $documented */ ' : '';
        file_put_contents(
            $root . '/app/' . $name . '.php',
            '<?php namespace SyntheticRuntime; '
            . $doc
            . 'class '
            . $name
            . ' extends \\Illuminate\\Database\\Eloquent\\Model { '
            . $body
            . ' }',
        );
    }
    file_put_contents($root . '/app/NullStringCast.php', <<<'PHP'
        <?php
        namespace SyntheticRuntime;
        class NullStringCast implements \Illuminate\Contracts\Database\Eloquent\CastsAttributes {
            public function get(\Illuminate\Database\Eloquent\Model $model, string $key, mixed $value, array $attributes): string { return $value === null ? 'synthetic-null' : (string) $value; }
            /** @param ?string $value */
            public function set(\Illuminate\Database\Eloquent\Model $model, string $key, mixed $value, array $attributes): ?string { return $value; }
        }
        PHP);
}

/** @return array{int, array, string} */
function runtimeCollect(string $package, string $root, array $models, string $name = 'collect'): array
{
    $command = [PHP_BINARY, $package . '/bin/laramago-runtime', '--project-root', $root];
    foreach ($models as $model) {
        $command[] = '--model';
        $command[] = 'SyntheticRuntime\\' . $model;
    }
    [$exit, $output, $stderr] = runtimeProcess($command, $root, $name);
    runtimeAssert(
        $stderr === '' || !preg_match('/PHP (Fatal|Warning|Notice)/', $stderr),
        'Runtime collector leaked PHP failure: ' . $stderr,
    );
    $snapshot = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    runtimeAssert(is_array($snapshot), 'Runtime collector did not return JSON object.');
    return [$exit, $snapshot, $output];
}
