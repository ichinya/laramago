<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago contextual injection '.bin2hex(random_bytes(8));
mkdir($workspace, 0777, true);
file_put_contents($workspace.'/cases.php', <<<'PHP'
    <?php
    namespace Fixture;
    interface Service {}
    class Good implements Service {}
    class Bad {}
    class Unknown extends MissingBase {}
    class Consumer {
        public function __construct(Service $service) {}
    }
    class ChildConsumer extends Consumer {}
    class NullableConsumer {
        public function __construct(?Service $service) {}
    }
    class UnionConsumer {
        public function __construct(Service|Bad $service) {}
    }
    class VariadicConsumer {
        public function __construct(Service ...$service) {}
    }
    class RefConsumer {
        public function __construct(Service &$service) {}
    }
    class ScalarConsumer {
        public function __construct(string $service) {}
    }
    class PrivateConsumer {
        private function __construct(Service $service) {}
    }
    class AttributeConsumer {
        public function __construct(#[\UnknownInjection] Service $service) {}
    }
    function nativeFailure(): int { return 'wrong'; }
    throw new \RuntimeException('Application source must not execute.');
    PHP);
$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php']],
    'extension-hosts' => [
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
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
$analyze = static function (string $label) use ($command, $workspace): array {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$label.'.json', 'w'],
            2 => ['file', $workspace.'/'.$label.'.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$label.'.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException($label.': external analyzer failed; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);

    return [$exit, $report];
};
$pluginIssues = static function (array $report): array {
    return array_values(array_filter(
        $report['issues'] ?? [],
        static fn (array $issue): bool => str_starts_with($issue['code'], 'ichinya/laramago/'),
    ));
};

$entry = static fn (string $owner, string $concrete = 'Bad', string $parameter = 'service'): array => [
    'consumer' => 'Fixture\\'.$owner,
    'parameter' => $parameter,
    'concrete' => 'Fixture\\'.$concrete,
];
$cases = [
    'incompatible' => [true, [$entry('Consumer')], 1],
    'compatible' => [true, [$entry('Consumer', 'Good')], 0],
    'unknown hierarchy' => [true, [$entry('Consumer', 'Unknown')], 0],
    'unknown concrete' => [true, [$entry('Consumer', 'Missing')], 0],
    'parameter names case sensitive' => [true, [$entry('Consumer', 'Bad', 'Service')], 0],
    'duplicate contracts defer' => [true, [$entry('Consumer'), $entry('Consumer', 'Good')], 0],
    'repeated duplicate contracts defer' => [true, [$entry('Consumer'), $entry('Consumer'), $entry('Consumer')], 0],
    'inherited constructor deferred' => [true, [$entry('ChildConsumer')], 0],
    'nullable deferred' => [true, [$entry('NullableConsumer')], 0],
    'union deferred' => [true, [$entry('UnionConsumer')], 0],
    'variadic deferred' => [true, [$entry('VariadicConsumer')], 0],
    'reference deferred' => [true, [$entry('RefConsumer')], 0],
    'scalar deferred' => [true, [$entry('ScalarConsumer')], 0],
    'private constructor deferred' => [true, [$entry('PrivateConsumer')], 0],
    'final result includes attribute dispatch' => [true, [$entry('AttributeConsumer')], 1],
    'assertion false' => [false, [$entry('Consumer')], 0],
    'assertion missing' => [null, [$entry('Consumer')], 0],
    'default off' => [null, [], 0],
];
foreach ($cases as $label => [$assertion, $entries, $expected]) {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => ['contextual-injection-contracts' => [
                'effective-resolution-asserted' => $assertion,
                'entries' => $entries,
            ]],
        ],
    ], JSON_THROW_ON_ERROR));
    [, $report] = $analyze(str_replace(' ', '-', $label));
    if (! in_array('invalid-return-statement', array_column($report['issues'] ?? [], 'code'), true)) {
        throw new RuntimeException($label.': native return diagnostic was lost; inspect '.$workspace);
    }
    $issues = $pluginIssues($report);
    if (
        count($issues) !== $expected
        || array_filter(
            $issues,
            static fn (array $issue): bool => (
                $issue['code'] !== 'ichinya/laramago/laramago-incompatible-contextual-injection'
            ),
        ) !== []
    ) {
        throw new RuntimeException($label.': unexpected diagnostics '.json_encode($issues).'; inspect '.$workspace);
    }
    echo 'PASS: '.$label."\n";
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
$resolvedWorkspace = realpath($workspace);
foreach ($iterator as $file) {
    $resolvedFile = realpath($file->getPathname());
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);
