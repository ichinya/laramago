<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
foreach ([
    'literal',
    'overlay',
    'overlay-source',
    'overlay-unindexed',
    'duplicate',
    'dynamic',
    'disabled',
    'unindexed',
    'kernel',
    'alias',
] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-cycles-'.bin2hex(random_bytes(8));
    mkdir($workspace);
    $body = <<<'PHP'
        [
            'self' => ['self'],
            'a' => ['b'],
            'b' => ['a'],
            'entry' => ['a'],
            'diamond' => ['left', 'right'],
            'left' => ['leaf'],
            'right' => ['leaf'],
            'leaf' => [],
            'parameter' => ['parameter:x'],
            'exact:x' => ['exact:x'],
            'case' => ['CASE'],
        ]
        PHP;
    $source = '<?php return '.$body.';';
    $options = ['files' => ['groups.php']];
    $expected = ['self', 'a', 'b', 'exact:x'];
    $expectedFile = 'groups.php';
    if ($mode === 'overlay') {
        file_put_contents($workspace.'/override.php', "<?php return ['self' => [], 'b' => [], 'exact:x' => []];");
        $options['files'][] = 'override.php';
        $expected = [];
    } elseif ($mode === 'overlay-source' || $mode === 'overlay-unindexed') {
        $source = "<?php return ['self' => ['self']];";
        file_put_contents($workspace.'/override.php', "<?php return [
'self' => ['self']];");
        $options['files'][] = 'override.php';
        $expected = $mode === 'overlay-source' ? ['self'] : [];
        $expectedFile = 'override.php';
    } elseif ($mode === 'duplicate') {
        $source = "<?php return ['self' => ['self'], 'self' => []];";
        $expected = [];
    } elseif ($mode === 'dynamic') {
        file_put_contents($workspace.'/override.php', "<?php return ['x' => [getenv('MIDDLEWARE')]];");
        $options['files'][] = 'override.php';
        $expected = [];
    } elseif ($mode === 'disabled' || $mode === 'unindexed') {
        $expected = [];
    } elseif ($mode === 'kernel') {
        $source = '<?php namespace App; class Kernel { protected array $middlewareGroups = '.$body.'; }';
        $options = ['kernel-file' => 'groups.php', 'kernel-class' => 'App\\Kernel'];
    }
    file_put_contents($workspace.'/groups.php', $source);
    file_put_contents($workspace.'/unrelated.php', "<?php return ['self' => ['self']];");
    $metadata = $mode === 'disabled' ? [] : ['middleware-groups' => $options];
    if ($mode === 'alias') {
        file_put_contents($workspace.'/aliases.php', "<?php return ['a' => 'ExampleMiddleware'];");
        $metadata['middleware-aliases'] = ['files' => ['aliases.php'], 'complete' => true];
    }
    file_put_contents($workspace.'/composer.json', json_encode(['extra' => [
        'laramago' => $metadata,
    ]], JSON_THROW_ON_ERROR));
    $paths = $mode === 'unindexed' ? ['unrelated.php'] : ['groups.php', 'unrelated.php'];
    if ($mode === 'overlay-source') {
        $paths[] = 'override.php';
    }
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
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
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
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    $nativeDuplicates = 0;
    foreach ($report['issues'] ?? [] as $issue) {
        if ($mode === 'duplicate' && $issue['code'] === 'duplicate-array-key') {
            $nativeDuplicates++;
            continue;
        }
        if ($issue['code'] !== 'ichinya/laramago/laramago-middleware-group-cycle') {
            throw new RuntimeException('Unexpected issue '.$issue['code'].'; inspect '.$workspace);
        }
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        if ($primary['span']['file_id']['name'] !== $expectedFile) {
            throw new RuntimeException('Wrong effective declaration file; inspect '.$workspace);
        }
        $actual[] = trim(
            substr(
                file_get_contents($workspace.'/'.$expectedFile),
                $primary['span']['start']['offset'],
                $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
            ),
            "'",
        );
    }
    sort($actual);
    sort($expected);
    if (
        $actual !== $expected
        || $mode !== 'duplicate'
        && $exit !== 0
        || $mode === 'duplicate'
        && $nativeDuplicates !== 1
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($workspace.'/stderr.log'),
        )
    ) {
        throw new RuntimeException(
            $mode.': expected '.json_encode($expected).', got '.json_encode($actual).'; inspect '.$workspace,
        );
    }
    echo 'PASS: '.$mode."\n";
}
