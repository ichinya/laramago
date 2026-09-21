<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\ControllerRouteContractExport;

require dirname(__DIR__).'/vendor/autoload.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$fixture = sys_get_temp_dir().'/laramago-controller-route-contracts-'.bin2hex(random_bytes(8));
mkdir($fixture.'/routes', 0777, true);
mkdir($fixture.'/app/Http/Controllers', 0777, true);

try {
    file_put_contents($fixture.'/routes/web.php', <<<'PHP'
        <?php

        namespace App\Routes;

        use App\Http\Controllers\MissingController;
        use App\Http\Controllers\ReportController;
        use Illuminate\Support\Facades\Route;

        file_put_contents(__DIR__.'/executed', 'unsafe');

        Route::get('/reports/{report}/{page?}', [ReportController::class, 'show'])
            ->domain('{account}.example.test')
            ->name('reports.show');
        Route::post('/jobs/{job_id}', ReportController::class);
        Route::put('/strings/{slug}', '\App\Http\Controllers\ReportController@store');
        Route::delete('/missing', [MissingController::class, 'show']);
        Route::get('/closure', fn () => null);
        PHP);
    file_put_contents($fixture.'/app/Http/Controllers/ReportController.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        interface Gateway {}

        final class ReportController
        {
            /** @param positive-int $differentName */
            public function show(string $differentName, Gateway $gateway, ?int $page = null): void {}

            public function __invoke($jobId = null): void {}

            public function store(int|string $slug): void {}

            private function hidden(string $value): void {}
        }
        PHP);

    $metadata = (new ControllerRouteContractExport)->export($fixture, [
        'routes/web.php',
        'app/Http/Controllers/ReportController.php',
    ]);

    $check($metadata['schemaVersion'] === 1, 'Schema version.');
    $check($metadata['scope']['runtimeDispatchValidated'] === false, 'Runtime validation is explicitly denied.');
    $check($metadata['scope']['exhaustive'] === false, 'Candidate selection is explicitly non-exhaustive.');
    $check(
        $metadata['selection'] === [
            'actionCandidates' => 4,
            'matchedContracts' => 3,
            'unmatchedActionCandidates' => 1,
            'ambiguousActionCandidates' => 0,
        ],
        'Matched and omitted literal action counts are visible.',
    );
    $check(count($metadata['contracts']) === 3, 'Three uniquely selected public declarations are linked.');
    $check(! is_file($fixture.'/routes/executed'), 'Selected project source is never executed.');

    $show = $metadata['contracts'][0];
    $check($show['route']['method'] === 'get', 'HTTP registration method.');
    $check($show['uri']['value'] === '/reports/{report}/{page?}', 'Literal URI candidate.');
    $check($show['domain']['state'] === 'literal-candidate', 'Literal chained domain candidate.');
    $check(
        array_column($show['uri']['placeholders'], 'name') === ['report', 'page'],
        'URI placeholders in source order.',
    );
    $check($show['domain']['placeholders'][0]['name'] === 'account', 'Domain placeholder candidate.');
    $check(
        $show['action']['class'] === 'App\\Http\\Controllers\\ReportController',
        'Imported action class resolves exactly.',
    );
    $check($show['controller']['method'] === 'show', 'Selected method declaration.');
    $check(count($show['controller']['parameters']) === 3, 'All declared parameters are retained.');

    [$scalar, $dependency, $optional] = $show['controller']['parameters'];
    $check($scalar['nativeType'] === 'string', 'Original native scalar type token.');
    $check(
        $scalar['matchingRoutePlaceholders'] === [],
        'Mismatched scalar names do not become an error or false match.',
    );
    $check(
        $dependency['namedClassDependencyCandidate'] === 'App\\Http\\Controllers\\Gateway',
        'Named class is only a DI candidate.',
    );
    $check(
        $dependency['matchingRoutePlaceholders'] === [],
        'Absent route placeholder does not imply an unresolvable dependency.',
    );
    $check($optional['nativeType'] === '?int' && $optional['hasDefault'] === true, 'Nullable scalar default metadata.');
    $check($optional['matchingRoutePlaceholders'][0]['optional'] === true, 'Optional placeholder lexical match.');
    $check($metadata['errors'] === [], 'Contracts do not carry invented diagnostic errors.');

    $invoke = $metadata['contracts'][1];
    $check($invoke['action']['kind'] === 'invokable-class', 'Invokable class action shape.');
    $check($invoke['controller']['method'] === '__invoke', 'Invokable method link.');
    $check(
        $invoke['controller']['parameters'][0]['matchingRoutePlaceholders'][0]['name'] === 'job_id',
        'Snake-case placeholder is exposed as a lexical name match.',
    );

    $store = $metadata['contracts'][2];
    $check($store['action']['kind'] === 'absolute-class-method-string', 'Absolute string action shape.');
    $check($store['controller']['parameters'][0]['nativeType'] === 'int|string', 'Composite native type token.');
    $check(
        $store['controller']['parameters'][0]['namedClassDependencyCandidate'] === null,
        'Union types are not claimed as DI injection.',
    );
    $check(
        substr(
            file_get_contents($fixture.'/routes/web.php'),
            $store['action']['start'],
            $store['action']['end'] - $store['action']['start'],
        ) === "'\\App\\Http\\Controllers\\ReportController@store'",
        'Action byte span identifies the original literal.',
    );

    file_put_contents($fixture.'/routes/broken.php', '<?php Route::get(');
    $broken = (new ControllerRouteContractExport)->export($fixture, ['routes/broken.php']);
    $check($broken['errors'][0]['code'] === 'parse-failure', 'Parse failures remain explicit and generic.');
    $check($broken['contracts'] === [], 'Broken sources produce no stale contracts.');

    $invalidList = false;
    try {
        (new ControllerRouteContractExport)->export($fixture, ['route' => 'routes/web.php']);
    } catch (InvalidArgumentException) {
        $invalidList = true;
    }
    $check($invalidList, 'Associative source selections are rejected.');
    $check(
        (new ControllerRouteContractExport)->export($fixture, ['../outside.php'])['errors'][0]['code']
        === 'invalid-source',
        'Parent traversal is rejected.',
    );
    $check(
        (new ControllerRouteContractExport)->export($fixture, [])['selection']['actionCandidates'] === 0,
        'Empty selection never discovers project files.',
    );
    $limited = (new ControllerRouteContractExport)->export($fixture, array_fill(0, 257, 'routes/web.php'));
    $check($limited['truncated'] === true, 'Explicit file selection is bounded.');
    $check($limited['truncationReasons'] === ['file-limit'], 'Reached limits retain their reason.');

    copy(
        $fixture.'/app/Http/Controllers/ReportController.php',
        $fixture.'/app/Http/Controllers/DuplicateReportController.php',
    );
    $ambiguous = (new ControllerRouteContractExport)->export($fixture, [
        'routes/web.php',
        'app/Http/Controllers/ReportController.php',
        'app/Http/Controllers/DuplicateReportController.php',
    ]);
    $check(
        $ambiguous['selection']['ambiguousActionCandidates'] === 3,
        'Duplicate selected declarations stay ambiguous.',
    );
    $check($ambiguous['contracts'] === [], 'Ambiguous declarations never produce a guessed contract.');
    file_put_contents(
        $fixture.'/app/Http/Controllers/DuplicateReportController.php',
        '<?php namespace App\\Http\\Controllers; class ReportController {}',
    );
    $ambiguousClass = (new ControllerRouteContractExport)->export($fixture, [
        'routes/web.php',
        'app/Http/Controllers/ReportController.php',
        'app/Http/Controllers/DuplicateReportController.php',
    ]);
    $check(
        $ambiguousClass['contracts'] === [] && $ambiguousClass['selection']['ambiguousActionCandidates'] === 3,
        'Duplicate classes remain ambiguous even when only one declares the method.',
    );
} finally {
    foreach (['web.php', 'broken.php', 'executed'] as $name) {
        if (is_file($fixture.'/routes/'.$name)) {
            unlink($fixture.'/routes/'.$name);
        }
    }
    if (is_file($fixture.'/app/Http/Controllers/ReportController.php')) {
        unlink($fixture.'/app/Http/Controllers/ReportController.php');
    }
    if (is_file($fixture.'/app/Http/Controllers/DuplicateReportController.php')) {
        unlink($fixture.'/app/Http/Controllers/DuplicateReportController.php');
    }
    rmdir($fixture.'/app/Http/Controllers');
    rmdir($fixture.'/app/Http');
    rmdir($fixture.'/app');
    rmdir($fixture.'/routes');
    rmdir($fixture);
}

echo "Controller route contract export: {$checks} checks passed.\n";
