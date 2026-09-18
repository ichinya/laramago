<?php

declare(strict_types=1);

// Analyze these declarations as data; never load the framework or a Date factory.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago carbon '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/framework.php', <<<'PHP'
    <?php
    namespace Carbon;
    /**
     * @property int $month
     * @property-read int $daysInMonth
     * @property-write string $input
     */
    interface CarbonInterface {
        public function __get(string $name): mixed;
        public function __set(string $name, mixed $value): void;
    }
    namespace Illuminate\Database\Eloquent;
    class Model {
        public function __get(string $name): mixed {}
        public function __set(string $name, mixed $value): void {}
    }
    namespace Example;
    class Record extends \Illuminate\Database\Eloquent\Model {
        protected $casts = ['happened_at' => 'datetime'];
    }
    /** @property string $month */
    abstract class CustomDate implements \Carbon\CarbonInterface {}
    /** @property-read CustomDate $happened_at */
    class CustomRecord extends Record {}
    abstract class NativeDate implements \Carbon\CarbonInterface { public string $month; }
    PHP);
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php']],
    'extension-hosts' => [
        'laramago' => ['command' => [
            PHP_BINARY,
            $package.'/bin/laramago-worker.php',
            $package.'/vendor/autoload.php',
            $workspace,
        ]],
    ],
];
$cases = [
    'cast retains interface' => ['return $model->happened_at;', '?Carbon\CarbonInterface', []],
    'cast month uses interface documentation' => ['return $model->happened_at?->month;', '?int', []],
    'interface month native' => ['return $date->month;', 'int', []],
    'custom date documentation native' => ['return $custom->month;', 'string', []],
    'custom date declared property native' => ['return $native->month;', 'string', []],
    'model custom date contract wins' => ['return $customModel->happened_at->month;', 'string', []],
    'read only property native' => ['return $date->daysInMonth;', 'int', []],
    'write native' => ['$date->month = 4;', 'void', []],
    'write only native' => ['$date->input = "value";', 'void', []],
    'typo preserved' => ['return $date->monht;', 'int', ['non-documented-property', 'mixed-return-statement']],
    'invalid return preserved' => ['return $date->month;', 'string', ['invalid-return-statement']],
    'invalid write preserved' => ['$date->month = new stdClass;', 'void', ['invalid-property-assignment-value']],
    'read only write preserved' => ['$date->daysInMonth = 4;', 'void', ['invalid-property-write']],
    'write only read preserved' => ['$date->input;', 'void', ['invalid-property-read', 'unused-statement']],
];
foreach ([false, true] as $disabled) {
    $config['analyzer'] = ['disable-default-plugins' => $disabled];
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $source = "<?php\n";
    $lines = [];
    foreach ($cases as $name => [$body, $return, $expected]) {
        if ($disabled && str_starts_with($name, 'cast ')) {
            continue;
        }
        $source .= '/** @return '.$return." */\n";
        $source .=
            'function scenario'
            .count($lines)
            .'(Example\Record $model, Carbon\CarbonInterface $date, Example\CustomDate $custom, Example\NativeDate $native, Example\CustomRecord $customModel) { '
            .$body
            ." }\n";
        $lines[substr_count($source, "\n")] = [$name, $expected];
    }
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
    if ($exit !== 1 || preg_match('/provider failed|rejected request/i', file_get_contents($workspace.'/stderr.log'))) {
        throw new RuntimeException('Expected native negative diagnostics; inspect '.$workspace);
    }
    $actual = [];
    foreach (json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues']
        ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        unset($actual[$line]);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace,
            );
        }
        echo 'PASS: '.($disabled ? 'disabled ' : '').$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics: '.json_encode($actual).'; inspect '.$workspace);
    }
}
foreach (glob($workspace.'/*') ?: [] as $file) {
    $resolved = realpath($file);
    if ($resolved === false || ! str_starts_with($resolved, realpath($workspace).DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Unsafe cleanup path.');
    }
    unlink($resolved);
}
rmdir($workspace);
