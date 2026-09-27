<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago collection static flavor '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
file_put_contents(
    $workspace.'/bootstrap.php',
    '<?php throw new RuntimeException("Do not bootstrap the application.");',
);
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php']],
    'extension-hosts' => [
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

// Fluent chains started from `self::`, `static::` and `$this->` carry a
// static-flavored model atom. Collections must still be verifiable against
// the plain-class contracts they are returned into, while scalar reads
// keep the `@return static` precision.
$methods = [
    'self where chain' => [
        '/** @return Collection<int, self> */',
        'public static function fromSelf(): Collection { return self::where(\'id\', 1)->get(); }',
        [],
    ],
    'static where chain' => [
        '/** @return Collection<int, self> */',
        'public static function fromStatic(): Collection { return static::where(\'id\', 1)->get(); }',
        [],
    ],
    'this where chain' => [
        '/** @return Collection<int, self> */',
        'public function fromThis(): Collection { return $this->where(\'id\', 1)->get(); }',
        [],
    ],
    'this query chain' => [
        '/** @return Collection<int, self> */',
        'public function fromThisQuery(): Collection { return $this->query()->get(); }',
        [],
    ],
    'explicit class chain stays concrete' => [
        '/** @return Collection<int, self> */',
        'public static function fromExplicit(): Collection { return FluentRecord::where(\'id\', 1)->get(); }',
        [],
    ],
    'scalar late static read keeps static flavor' => [
        '/** @return static */',
        'public static function lateStaticRead(): static { return static::firstOrFail(); }',
        [],
    ],
    'wrong element contract stays invalid' => [
        '/** @return Collection<int, OtherRecord> */',
        'public static function wrongElements(): Collection { return self::where(\'id\', 1)->get(); }',
        ['invalid-return-statement'],
    ],
];
$source = <<<'PHP'
<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $label
 */
class Record extends Model {}

class OtherRecord extends Model {}

class FluentRecord extends Record {

PHP;
$lines = [];
$line = substr_count($source, "\n") + 1;
foreach ($methods as $name => [$doc, $body, $codes]) {
    $source .= "    ".$doc."\n    ".$body."\n";
    $lines[$line + 1] = [$name, $codes];
    $line += 2;
}
$source .= "}\n";

file_put_contents($workspace.'/cases.php', $source);
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
if ($exit === 0 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Unexpected analyzer outcome; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    if ($primary['span']['file_id']['name'] === 'cases.php') {
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
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
    throw new RuntimeException('Unexpected diagnostics outside the fluent scenarios; inspect '.$workspace);
}

$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($resolved);
