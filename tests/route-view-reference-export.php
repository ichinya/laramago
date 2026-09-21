<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\RouteViewReferenceExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-route-view-references-'.bin2hex(random_bytes(8));
mkdir($fixture.'/routes', 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$source = <<<'PHP'
    <?php

    namespace App\Routes;

    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Facades\Route as NativeRoute;
    use Custom\Route as CustomRoute;

    file_put_contents(__DIR__.'/executed', 'forbidden');

    Route::view('/welcome', 'pages.welcome');
    NativeRoute::view(view: 'pages.named', uri: '/named');
    \Illuminate\Support\Facades\Route::VIEW('/upper', "pages.upper")
        ->defaults('view', 'pages.replaced');
    Route::view('/dynamic', $view);
    Route::view(...$arguments);
    Route::view('/missing');
    Route::view(uri: '/wrong-name', View: 'pages.wrong-name');
    CustomRoute::view('/custom', 'pages.custom');
    Route::get('/ordinary', 'pages.not-a-view');
    PHP;
file_put_contents($fixture.'/routes/web.php', $source);
file_put_contents($fixture.'/routes/broken.php', '<?php Route::view(');
file_put_contents($fixture.'/routes/not-php.txt', 'Route::view("/", "private-name");');
file_put_contents($fixture.'/composer.json', '{"autoload":{"files":["routes/web.php"]}}');

$exporter = new RouteViewReferenceExport;
try {
    $result = $exporter->export($fixture, ['routes/web.php']);
    $check($result['schemaVersion'] === 1, 'Versioned contract.');
    $check(
        $result['scope'] === [
            'kind' => 'route-view-references',
            'evidence' => 'selected-php-source',
            'semantics' => 'optional-declaration-policy',
            'exhaustive' => false,
            'runtimeLookupValidated' => false,
        ],
        'Scope explicitly separates declaration policy from runtime lookup.',
    );
    $check(
        array_column($result['references'], 'name') === ['pages.welcome', 'pages.named', 'pages.upper'],
        'Native facade literals, aliases, named arguments, and case-insensitive methods are selected.',
    );
    $check(
        array_column($result['uncertainties'], 'code') === [
            'non-literal-view-argument',
            'non-literal-view-argument',
            'non-literal-view-argument',
            'non-literal-view-argument',
        ],
        'Dynamic, unpacked, and missing view arguments remain uncertain.',
    );
    $check($result['errors'] === [], 'Supported source has no read or parse errors.');
    $check($result['truncated'] === false, 'Unsupported call shapes do not imply source truncation.');
    foreach ($result['references'] as $reference) {
        $literal = substr($source, $reference['start'], $reference['end'] - $reference['start']);
        $check(
            in_array($literal, ["'pages.welcome'", "'pages.named'", '"pages.upper"'], true),
            'Reference preserves the original half-open literal span.',
        );
        $check($reference['contentHash'] === hash('sha256', $source), 'Reference preserves exact source hash.');
        $check(
            $reference['confidence'] === 'declaration-policy-reference' && $reference['runtimeLookup'] === 'unresolved',
            'Each reference denies effective runtime lookup proof.',
        );
    }
    foreach ($result['uncertainties'] as $uncertainty) {
        $check($uncertainty['contentHash'] === hash('sha256', $source), 'Uncertainty preserves exact source hash.');
        $check(
            str_starts_with(substr($source, $uncertainty['start'], 11), 'Route::view'),
            'Uncertainty preserves the native call span.',
        );
    }
    $check(! file_exists($fixture.'/routes/executed'), 'Selected project PHP is never executed.');
    $check(
        ! str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'pages.replaced')
        && ! str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'pages.wrong-name')
        && ! str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'pages.custom')
        && ! str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'pages.not-a-view'),
        'Later defaults, custom facades, and unrelated methods are not reclassified.',
    );

    $bad = $exporter->export($fixture, [
        'routes/broken.php',
        'routes/missing.php',
        'routes/not-php.txt',
        '../outside.php',
    ]);
    $check(
        array_column($bad['errors'], 'code') === [
            'parse-failure',
            'unreadable-source',
            'unsupported-source-format',
            'invalid-source',
        ],
        'Parse, missing, format, and containment errors stay explicit and generic.',
    );
    $check(
        ! str_contains(json_encode($bad, JSON_THROW_ON_ERROR), 'private-name'),
        'Rejected source contents are not exported.',
    );
    $duplicate = $exporter->export($fixture, ['routes/web.php', 'routes/./web.php']);
    $check(count($duplicate['references']) === 3, 'Canonical duplicate paths are read once.');
    $check($exporter->export($fixture, [])['references'] === [], 'Empty selection never discovers route files.');
    $limited = $exporter->export($fixture, array_fill(0, 257, 'routes/web.php'));
    $check($limited['truncationReasons'] === ['file-limit'], 'Explicit source count is bounded.');

    $invalidList = false;
    try {
        $exporter->export($fixture, ['source' => 'routes/web.php']);
    } catch (InvalidArgumentException) {
        $invalidList = true;
    }
    $check($invalidList, 'Associative source selections are rejected.');
} finally {
    foreach (['web.php', 'broken.php', 'not-php.txt', 'executed'] as $name) {
        if (is_file($fixture.'/routes/'.$name)) {
            unlink($fixture.'/routes/'.$name);
        }
    }
    unlink($fixture.'/composer.json');
    rmdir($fixture.'/routes');
    rmdir($fixture);
}

echo "Route view reference export: {$checks} checks passed.\n";
