<?php

declare(strict_types=1);

// Analyze native Inertia component assertions without executing fixture application code.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago inertia test component '.bin2hex(random_bytes(8));
$inertia = $workspace.'/vendor/inertiajs/inertia-laravel/src/Testing';
$pages = $workspace.'/resources/js/Pages/Admin';
mkdir($inertia, 0777, true);
mkdir($pages, 0777, true);
copy(__DIR__.'/fixtures/analysis/inertia-test-component.php.stub', $inertia.'/AssertableInertia.php');
file_put_contents($pages.'/Users.vue', '<template></template>');
file_put_contents($workspace.'/application.php', '<?php throw new RuntimeException("Fixtures must not execute.");');

$catalog = ['complete' => true, 'paths' => ['resources/js/Pages'], 'extensions' => ['vue']];
$composer = static function (?array $contract) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'autoload' => ['files' => ['application.php']],
        'extra' => ['laramago' => ['reference-catalogs' => ['inertia-pages' => $contract]]],
    ], JSON_THROW_ON_ERROR));
};
$composer($catalog);

$missing = ['ichinya/laramago/laramago-missing-inertia-page'];
$cases = [
    'known page' => ['$page->component("Admin/Users");', []],
    'missing page' => ['$page->component("Admin/User");', $missing],
    'native method case-insensitive' => ['$page->COMPONENT("Admin/User");', $missing],
    'named expected page' => ['$page->component(value: "Admin/User");', $missing],
    'explicit existence check' => ['$page->component("Admin/User", true);', $missing],
    'named existence check' => ['$page->component(shouldExist: true, value: "Admin/User");', $missing],
    'case-sensitive page' => ['$page->component("admin/Users");', $missing],
    'disabled existence check' => ['$page->component("Admin/User", false);', []],
    'named disabled check' => ['$page->component(value: "Admin/User", shouldExist: false);', []],
    'dynamic existence check' => ['$page->component("Admin/User", $exists);', []],
    'dynamic page' => ['$page->component($name);', []],
    'concatenated page' => ['$page->component("Admin/".$name);', []],
    'unpacked arguments' => ['$page->component(...["Admin/User"]);', []],
    'subclass receiver' => ['$custom->component("Admin/User");', []],
    'documented subclass receiver' => ['$documented->component("Admin/User");', []],
    'first-class callable' => ['$callback = $page->component(...);', []],
    'response callback' => [
        '$response->assertInertia(fn ($assertable) => $assertable->component("Admin/User"));',
        $missing,
    ],
    'response callback disabled check' => [
        '$response->assertInertia(fn ($assertable) => $assertable->component("Admin/User", false));',
        [],
    ],
];
$source = <<<'PHP'
    <?php
    use Inertia\Testing\AssertableInertia;
    use Illuminate\Testing\TestResponse;
    class CustomAssertable extends AssertableInertia {}
    /** @method string component(string $value) */
    class DocumentedAssertable extends AssertableInertia {}
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $codes]) {
    $source .=
        'function scenario'
        .count($lines)
        .'(AssertableInertia $page, CustomAssertable $custom, DocumentedAssertable $documented, TestResponse $response, string $name, bool $exists): void {'
        .$body
        .'}'
        ."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);

$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => ['vendor/inertiajs/inertia-laravel/src/Testing/AssertableInertia.php'],
    ],
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
];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$analyze = static function () use ($command, $workspace): array {
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
    $log = file_get_contents($workspace.'/stderr.log');
    if ($exit !== 0 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Analyzer failed; inspect '.$workspace.' ('.$log.')');
    }

    return $report['issues'] ?? [];
};
$codesByLine = static function (array $issues): array {
    $actual = [];
    foreach ($issues as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }

    return $actual;
};
$assertCases = static function (array $issues, array $lines) use ($codesByLine, $workspace): void {
    $actual = $codesByLine($issues);
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics; inspect '.$workspace);
    }
};
$assertCases($analyze(), $lines);

$composer([...$catalog, 'complete' => false]);
if ($analyze() !== []) {
    throw new RuntimeException('Incomplete catalog must not warn; inspect '.$workspace);
}
echo "PASS: incomplete catalog defers\n";
$composer(null);
if ($analyze() !== []) {
    throw new RuntimeException('Missing catalog must not warn; inspect '.$workspace);
}
echo "PASS: missing catalog defers\n";
$composer($catalog);
$native = file_get_contents($inertia.'/AssertableInertia.php');
file_put_contents($inertia.'/AssertableInertia.php', str_replace(
    'inertia.view-finder',
    'inertia.testing.view-finder',
    $native,
));
$assertCases($analyze(), $lines);
echo "PASS: native 2.x finder variant\n";
file_put_contents($inertia.'/AssertableInertia.php', str_replace(
    'PHPUnit::assertSame($value',
    'PHPUnit::assertNotSame($value',
    $native,
));
if ($analyze() !== []) {
    throw new RuntimeException('Altered component body must defer; inspect '.$workspace);
}
echo "PASS: altered component body defers\n";
file_put_contents($inertia.'/AssertableInertia.php', str_replace(
    '$shouldExist = null',
    '$shouldExist = true',
    $native,
));
if ($analyze() !== []) {
    throw new RuntimeException('Altered component signature must defer; inspect '.$workspace);
}
echo "PASS: altered component signature defers\n";
$customPackage = $workspace.'/custom';
mkdir($customPackage);
copy(__DIR__.'/fixtures/analysis/inertia-test-component.php.stub', $customPackage.'/AssertableInertia.php');
$configuration['source']['includes'] = ['custom/AssertableInertia.php'];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
if ($analyze() !== []) {
    throw new RuntimeException('Custom Inertia replacement must defer; inspect '.$workspace);
}
echo "PASS: custom Inertia replacement defers\n";

$resolvedWorkspace = realpath($workspace);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($iterator as $file) {
    $resolvedFile = realpath($file->getPathname());
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Unsafe fixture cleanup path.');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);
