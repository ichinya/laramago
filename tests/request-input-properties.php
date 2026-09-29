<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago request input '.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
mkdir($framework.'/Http', 0777, true);
mkdir($framework.'/Support', 0777, true);
mkdir($workspace.'/bootstrap');
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Application booted.");');
$request = <<<'PHP'
    <?php
    namespace Illuminate\Http;
    use Illuminate\Support\Arr;
    class Request {
        public string $declared = '';
        /** @return array<string, mixed> */
        public function all(): array { return []; }
        public function route(string $key): mixed { return null; }
        /** @return mixed */
        public function __get($key) {
            return Arr::get($this->all(), $key, fn () => $this->route($key));
        }
    }
    PHP;
file_put_contents($framework.'/Support/Arr.php', <<<'PHP'
    <?php
    namespace Illuminate\Support;
    class Arr {
        public static function get(array $values, string $key, mixed $default = null): mixed { return null; }
    }
    PHP);
file_put_contents($workspace.'/types.php', <<<'PHP'
    <?php
    use Illuminate\Http\Request;
    class InputRequest extends Request {}
    /** @property int $documented */
    class DocumentedRequest extends Request {}
    class CustomRequest extends Request {
        public function __get($key): mixed { return null; }
    }
    class OrdinaryObject {
        public function __get($key): mixed { return null; }
    }
    PHP);
$cases = [
    'guarded raw input' => 'function guarded(InputRequest $request): string { $value = $request->title; return is_string($value) ? $value : ""; }',
    'unsafe raw return' => 'function unsafe(InputRequest $request): string { return $request->title; }',
    'unsafe raw method' => 'function method(InputRequest $request): void { $request->title->unknown(); }',
    'custom getter' => 'function custom(CustomRequest $request): mixed { return $request->title; }',
    'documented property' => 'function documented(DocumentedRequest $request): string { return $request->documented; }',
    'declared property' => 'function declared(InputRequest $request): int { return $request->declared; }',
    'ordinary object' => 'function ordinary(OrdinaryObject $object): mixed { return $object->typo; }',
    'raw write' => 'function write(InputRequest $request): void { $request->title = 3; }',
    'raw mixed return' => 'function raw(InputRequest $request): mixed { return $request->arbitrary_input_name; }',
];
file_put_contents($workspace.'/cases.php', "<?php\n".implode("\n", $cases)."\n");
foreach (['enabled', 'disabled', 'changed-body'] as $mode) {
    file_put_contents($framework.'/Http/Request.php', $mode === 'changed-body'
        ? str_replace('return Arr::get($this->all(), $key, fn () => $this->route($key));', 'throw new \\RuntimeException("Unknown property");', $request)
        : $request);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['vendor', 'types.php']],
        'extension-hosts' => $mode === 'disabled' ? new stdClass : [
            'laramago' => [
                'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => 1,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
        2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request|hook .* failed|parse error/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $codes = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'cases.php') {
                $codes[$annotation['span']['start']['line'] - 1][] = $issue['code'];
                break;
            }
        }
    }
    $required = [
        1 => 'mixed-return-statement',
        2 => 'mixed-method-access',
        3 => 'non-documented-property',
        4 => 'invalid-return-statement',
        5 => 'invalid-return-statement',
        6 => 'non-documented-property',
    ];
    foreach ($required as $line => $code) {
        if (! in_array($code, $codes[$line] ?? [], true)) {
            throw new RuntimeException($mode.' lost '.$code.' at '.$line.': '.json_encode($codes).' '.$workspace);
        }
    }
    foreach ([0, 1, 2, 8] as $line) {
        $hasUnknown = in_array('non-documented-property', $codes[$line] ?? [], true);
        if ($hasUnknown !== ($mode !== 'enabled')) {
            throw new RuntimeException($mode.' has wrong raw input contract: '.json_encode($codes).' '.$workspace);
        }
    }
    if (($codes[7] ?? []) === []) {
        throw new RuntimeException('Raw write was incorrectly accepted: '.$workspace);
    }
    echo 'PASS: request input properties '.$mode.' ('.count($cases).' cases)' . "\n";
}
