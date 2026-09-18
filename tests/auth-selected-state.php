<?php

declare(strict_types=1);

// Real-engine proof: generic payloads transport selection but do not track alias mutation.
// The experimental worker is deliberately absent from the production plugin.
$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach (['native', 'carrier'] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-selected-state-'.bin2hex(random_bytes(8));
    mkdir($workspace);
    copy(__DIR__.'/fixtures/analysis/auth-selected-sdk.php.stub', $workspace.'/framework.php');
    $lessSpecific = ['less-specific-return-statement'];
    $cases = [
        'direct carrier' => [
            'return selectedGuardProbe("admin")->user();',
            '?SelectedAdmin',
            $mode === 'native' ? $lessSpecific : [],
        ],
        'independent same-class guards' => [
            '$admin = selectedGuardProbe("admin"); $member = selectedGuardProbe("member"); $member->user(); return $admin->user();',
            '?SelectedAdmin',
            $mode === 'native' ? $lessSpecific : [],
        ],
        'wrong selected model' => [
            'return selectedGuardProbe("member")->user();',
            '?SelectedAdmin',
            $mode === 'native' ? $lessSpecific : ['invalid-return-statement'],
        ],
        'both branch alternatives survive' => [
            '$guard = $flag ? selectedGuardProbe("admin") : selectedGuardProbe("member"); return $guard->user();',
            'SelectedAdmin|SelectedMember|null',
            $mode === 'native' ? $lessSpecific : [],
        ],
        'branch cannot collapse to one model' => [
            '$guard = $flag ? selectedGuardProbe("admin") : selectedGuardProbe("member"); return $guard->user();',
            '?SelectedAdmin',
            $mode === 'native' ? $lessSpecific : ['invalid-return-statement'],
        ],
        'raw guard remains native' => ['return (new SelectedGuardProbe)->user();', '?SelectedAdmin', $lessSpecific],
        'no phantom properties' => [
            'return selectedGuardProbe("admin")->__laramago_model;',
            'mixed',
            ['non-existent-property'],
        ],
        'unknown methods remain errors' => ['selectedGuardProbe("admin")->uesr();', 'void', ['non-existent-method']],
        // This accepts an incorrect return only with the experimental carrier. Its runtime
        // user is SelectedMember after mutation, proving the carrier is not a safe fix.
        'alias mutation is not invalidated' => [
            '$guard = selectedGuardProbe("admin"); $alias = $guard; $alias->setUser(new SelectedMember); return $guard->user();',
            '?SelectedAdmin',
            $mode === 'native' ? $lessSpecific : [],
        ],
        'direct mutation is not invalidated' => [
            '$guard = selectedGuardProbe("admin"); $guard->setUser(new SelectedMember); return $guard->user();',
            '?SelectedAdmin',
            $mode === 'native' ? $lessSpecific : [],
        ],
    ];
    $source = "<?php\n";
    $lines = [];
    foreach ($cases as $name => [$body, $return, $expected]) {
        $source .= "/**\n * @return ".$return."\n */\n";
        $source .= 'function scenario'.count($lines).'(bool $flag) { '.$body." }\n";
        $lines[substr_count($source, "\n")] = [$name, $expected];
    }
    file_put_contents($workspace.'/cases.php', $source);
    file_put_contents($workspace.'/mago.json', json_encode([
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php']],
        'extension-hosts' => $mode === 'native'
            ? new stdClass
            : [
                'probe' => [
                    'command' => [
                        PHP_BINARY,
                        $package.'/tests/fixtures/analysis/auth-selected-sdk-worker.php.stub',
                        $package.'/vendor/autoload.php',
                    ],
                    'workers' => 1,
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
    if (
        $exit !== 1
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($workspace.'/stderr.log'),
        )
    ) {
        throw new RuntimeException('Expected negative diagnostics without worker fallback: '.$workspace);
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
        echo 'PASS ['.$mode.']: '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics: '.$workspace);
    }
    foreach (['framework.php', 'cases.php', 'mago.json', 'report.json', 'stderr.log'] as $file) {
        unlink($workspace.'/'.$file);
    }
    rmdir($workspace);
}
