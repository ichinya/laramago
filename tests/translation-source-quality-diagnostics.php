<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\TranslationSourceQualityHook;

require dirname(__DIR__).'/vendor/autoload.php';

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago translation quality '.bin2hex(random_bytes(8));
mkdir($workspace, 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks, $workspace): void {
    if (! $condition) {
        throw new RuntimeException($message.'; inspect '.$workspace);
    }
    $checks++;
};
$messages = [
    'en.php' => '<?php return ["hello"=>"PRIVATE EN :name", "same"=>":same", "plural"=>"one :first|many :second", "dynamic"=>getenv("MESSAGE"), "duplicate"=>":before", "duplicate"=>":after"];',
    'fr.php' => '<?php return ["hello"=>"PRIVATE FR :nom", "same"=>":same", "plural"=>"un :autre|plusieurs :autre", "dynamic"=>":known", "duplicate"=>":after"];',
    'calls.php' => '<?php function trans(string $key, array $replace = []): string { return $key; } trans("hello", ["bad-name"=>"PRIVATE VALUE", "name"=>1, "0"=>2]); trans("hello", ["unknown-key"=>1, ...[]]);',
];
foreach ($messages as $file => $source) {
    file_put_contents($workspace.'/'.$file, $source);
}
file_put_contents($workspace.'/bootstrap.php', '<?php file_put_contents(__DIR__."/executed", "bad");');
$associations = [
    ['locale' => 'en', 'catalog' => 'messages', 'file' => 'en.php'],
    ['locale' => 'fr', 'catalog' => 'messages', 'file' => 'fr.php'],
];
$policy = static function (mixed $settings) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'autoload' => ['files' => ['bootstrap.php']],
        'extra' => ['laramago' => ['translation-source-quality' => $settings]],
    ], JSON_THROW_ON_ERROR));
};
$enabled = [
    'diagnose' => true,
    'equal-placeholder-sources' => $associations,
    'ascii-replacement-name-files' => ['calls.php'],
];
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$analyze = static function (array $paths) use ($command, $workspace, $package): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => $paths],
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
                'workers' => 2,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
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
        throw new RuntimeException('Cannot start Mago');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/stderr.log');
    if (
        ! in_array($exit, [0, 1], true)
        || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
    ) {
        throw new RuntimeException('Mago failed: '.$log.'; '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_filter($report['issues'] ?? [], static fn (array $issue): bool => str_starts_with(
        $issue['code'] ?? '',
        'ichinya/laramago/laramago-translation-',
    )));
};
try {
    $policy($enabled);
    $issues = $analyze(['en.php', 'fr.php', 'calls.php']);
    $check(count($issues) === 2, 'Expected one parity and one naming note: '.json_encode($issues));
    foreach ($issues as $issue) {
        $check(strtolower($issue['level']) === 'note', 'Quality findings are notes');
        $check(str_contains($issue['message'], 'source-quality advice'), 'No runtime-failure claim');
        $check(! str_contains($issue['message'], 'PRIVATE'), 'Message values omitted from diagnostic prose');
    }
    $parity = array_values(array_filter($issues, static fn (array $issue): bool => str_ends_with(
        $issue['code'],
        'placeholder-parity',
    )))[0];
    $check(count($parity['annotations']) === 2, 'Parity reports both locale source locations');
    foreach ($parity['annotations'] as $annotation) {
        $span = $annotation['span'];
        $file = $workspace.'/'.$span['file_id']['name'];
        $text = file_get_contents($file);
        $check(
            substr($text, $span['start']['offset'], $span['end']['offset'] - $span['start']['offset']) === '"hello"',
            'Annotation points to key, not private translated message',
        );
    }
    $check(
        count($analyze(['calls.php'])) === 1,
        'Single-file naming analysis works and unindexed locale declarations defer',
    );
    $check($analyze(['fr.php']) === [], 'Partial locale analysis defers comparison');
    $policy(['diagnose' => false] + $enabled);
    $check($analyze(['en.php', 'fr.php', 'calls.php']) === [], 'Explicitly disabled policy has no findings');
    $policy(null);
    $check($analyze(['en.php', 'fr.php', 'calls.php']) === [], 'Default policy has no findings');
    $policy(['diagnose' => true, 'equal-placeholder-sources' => [...$associations, $associations[0]]]);
    $check($analyze(['en.php', 'fr.php']) === [], 'Ambiguous duplicate locale association defers');
    $policy([
        'diagnose' => true,
        'equal-placeholder-sources' => [
            ['locale' => 'en', 'catalog' => 'one', 'file' => 'en.php'],
            ['locale' => 'fr', 'catalog' => 'two', 'file' => 'fr.php'],
        ],
    ]);
    $check($analyze(['en.php', 'fr.php']) === [], 'Different logical catalogs are not compared');
    $policy(['diagnose' => true, 'equal-placeholder-sources' => 'bad', 'ascii-replacement-name-files' => [7]]);
    $check($analyze(['en.php', 'fr.php', 'calls.php']) === [], 'Invalid option shapes defer');
    $check(! file_exists($workspace.'/executed'), 'Application bootstrap never executes');
    $method = new ReflectionMethod(TranslationSourceQualityHook::class, 'indexedLocation');
    $check(
        $method->invoke(
            null,
            ['file' => 'stale', 'start' => 0, 'end' => 2, 'contentHash' => 'old'],
            ['stale' => ['file' => 'stale', 'hash' => 'new', 'length' => 3]],
        ) === null,
        'Stale snapshots defer',
    );
} finally {
    foreach ([
        'en.php',
        'fr.php',
        'calls.php',
        'bootstrap.php',
        'composer.json',
        'mago.json',
        'report.json',
        'stderr.log',
        'executed',
    ] as $file) {
        if (is_file($workspace.'/'.$file)) {
            unlink($workspace.'/'.$file);
        }
    }
    rmdir($workspace);
}
echo "Translation source-quality diagnostics: {$checks} checks passed.\n";
