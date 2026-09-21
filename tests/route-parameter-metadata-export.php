<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\RouteParameterMetadataExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-route-parameters-'.bin2hex(random_bytes(8));
mkdir($fixture.'/routes', 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$source = <<<'PHP'
    <?php
    namespace Demo;
    use Illuminate\Support\Facades\Route as R;

    file_put_contents(__DIR__.'/../executed', 'route source executed');

    R::domain('{tenant:slug}.example.test')->prefix('/api')->name('api.')->group(function () {
        R::group([
            'as' => 'v1.',
            'prefix' => '/v1',
            'middleware' => $middleware,
        ], static function (): void {
            R::get('/posts/{post:slug}/{section?}', fn () => null)
                ->defaults('section', 'news')
                ->name('posts.show');
        });

        R::domain('{region?}.example.test')->group(function () {
            R::post('/reports/{report}', 'ReportController@store')->name('reports.store');
        });
    });

    R::get('/files/{file:path}', fn () => null)
        ->domain('{account:uuid}.example.test')
        ->prefix('{locale}')
        ->defaults('locale', null)
        ->defaults('locale', 'en')
        ->defaults('file', $dynamicDefault)
        ->whereNumber('locale')
        ->name('files.show');

    R::get('/zero/{value}', fn () => null)->defaults('value', 0)->name('zero');
    R::get('/false/{value}', fn () => null)->defaults('value', false)->name('false');
    R::get('/unnamed/{id}', fn () => null);
    R::get($dynamicUri, fn () => null)->name('dynamic.uri');
    R::get('/dynamic-domain/{id}', fn () => null)->domain($domain)->name('dynamic.domain');
    R::get('/unsupported/{id}', fn () => null)->setUri('/changed')->name('unsupported');
    R::group(['as' => 'dynamic.', 'prefix' => $prefix], function () {
        R::get('/hidden/{id}', fn () => null)->name('hidden');
    });
    if (true) {
        R::get('/conditional/{id}', fn () => null)->name('conditional');
    }
    throw new RuntimeException('Route source must never execute.');
    PHP;
file_put_contents($fixture.'/routes/web.php', $source);
file_put_contents($fixture.'/routes/broken.php', "<?php 'private parse value'; broken(");
file_put_contents($fixture.'/.env', 'SECRET=private-environment-value');

$exporter = new RouteParameterMetadataExport;
try {
    file_put_contents($fixture.'/routes/guards.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route;
        Route::any('/any', fn () => null)->name('any');
        Route::DOMAIN('example.test')->group(function () { Route::get('/hidden', fn () => null)->name('bad.domain'); });
        Route::PREFIX('api')->group(function () { Route::get('/hidden', fn () => null)->name('bad.prefix'); });
        Route::group(function () { Route::get('/hidden', fn () => null)->name('bad.arity'); });
        PHP);
    $guards = $exporter->export($fixture, ['routes/guards.php']);
    $check(
        array_column($guards['declarations'], 'name') === ['any'],
        'Magic group attribute case and direct group arity preserve native boundaries.',
    );
    $check(
        $guards['declarations'][0]['registrationIdentity']['methods'] === [
            'GET',
            'HEAD',
            'POST',
            'PUT',
            'PATCH',
            'DELETE',
            'OPTIONS',
        ],
        'Native any-method registration order is preserved.',
    );
    $result = $exporter->export($fixture, ['routes/web.php']);
    $check($result['schemaVersion'] === 1, 'Versioned metadata contract.');
    $check(
        $result['scope'] === [
            'kind' => 'route-parameters',
            'evidence' => 'source-only',
            'effectiveRuntime' => false,
            'declarationCoverage' => 'bounded-positive-only',
        ],
        'Source candidates do not claim effective runtime state.',
    );
    $check($result['errors'] === [] && $result['truncated'] === false, 'Supported source exports cleanly.');
    $check(
        array_column($result['declarations'], 'name') === [
            'api.v1.posts.show',
            'api.reports.store',
            'files.show',
            'zero',
            'false',
        ],
        'Only bounded top-level named route candidates are exported.',
    );

    $posts = $result['declarations'][0];
    $check($posts['uri'] === 'api/v1/posts/{post}/{section?}', 'Nested literal prefixes compose with the route URI.');
    $check($posts['domain'] === '{tenant}.example.test', 'Binding syntax is normalized in the domain.');
    $check(
        array_column($posts['parameters'], 'name') === ['tenant', 'post', 'section'],
        'Parameters retain native domain-before-URI order.',
    );
    $check(
        array_column($posts['parameters'], 'source') === ['domain', 'uri', 'uri'],
        'Parameter source distinguishes host and path placeholders.',
    );
    $check(
        array_column($posts['parameters'], 'bindingField') === ['slug', 'slug', null],
        'Domain and URI binding fields follow native RouteUri parsing.',
    );
    $check(
        array_column($posts['parameters'], 'urlDefaultKey') === ['tenant:slug', 'post:slug', 'section'],
        'URL default lookup keys include binding fields.',
    );
    $check(
        array_column($posts['parameters'], 'optional') === [false, false, true],
        'Only the optional URI placeholder is URL-optional.',
    );
    $check(
        $posts['declarationDefaults']['complete'] === true
        && $posts['declarationDefaults']['entries'][0]['key'] === 'section'
        && $posts['declarationDefaults']['entries'][0]['valueKind'] === 'string',
        'Literal route declaration defaults retain keys, kinds, and source locations.',
    );
    $check(
        $posts['registrationIdentity'] === [
            'methods' => ['GET', 'HEAD'],
            'domain' => '{tenant}.example.test',
            'uri' => 'api/v1/posts/{post}/{section?}',
            'survival' => 'unresolved',
        ],
        'Candidate identity is available without claiming collection survival.',
    );
    $check(
        array_column($posts['provenance']['name'], 'value') === ['api.', 'v1.', 'posts.show']
        && array_column($posts['provenance']['uri'], 'value') === ['/api', '/v1', '/posts/{post:slug}/{section?}'],
        'Composed values retain their literal source tokens.',
    );

    $reports = $result['declarations'][1];
    $check($reports['domain'] === '{region?}.example.test', 'Inner group domain replaces the outer domain.');
    $check(
        $reports['parameters'][0]['declaredOptional'] === true && $reports['parameters'][0]['optional'] === false,
        'A domain question-mark marker is recorded but is not URL-optional.',
    );

    $files = $result['declarations'][2];
    $check($files['uri'] === '{locale}/files/{file}', 'Route-level prefix prepends the existing URI.');
    $check($files['domain'] === '{account}.example.test', 'Route-level domain is normalized.');
    $check(
        array_column($files['parameters'], 'name') === ['account', 'locale', 'file'],
        'Explicit route domains remain first in positional order.',
    );
    $check(
        array_column($files['parameters'], 'bindingField') === [null, null, null],
        'A later Route::prefix call resets existing domain and URI binding fields like native setUri.',
    );
    $check(
        $files['declarationDefaults']['complete'] === false
        && array_column($files['declarationDefaults']['entries'], 'key') === ['locale', 'locale', 'file']
        && array_column($files['declarationDefaults']['entries'], 'effective') === [false, true, true],
        'Ordered duplicate defaults and a dynamic value retain bounded completeness.',
    );
    $check(
        array_column($files['declarationDefaults']['entries'], 'valueKind') === ['null', 'string', 'dynamic'],
        'Literal null and string defaults are distinct from a dynamic value without exporting values.',
    );
    $check(
        $result['declarations'][3]['declarationDefaults']['entries'][0]['valueKind'] === 'int'
        && $result['declarations'][4]['declarationDefaults']['entries'][0]['valueKind'] === 'bool',
        'False and zero route defaults retain distinct source kinds without exporting values.',
    );

    foreach ($result['declarations'] as $declaration) {
        $literal = substr($source, $declaration['start'], $declaration['end'] - $declaration['start']);
        $check(
            str_contains(
                $literal,
                $declaration['provenance']['name'][array_key_last($declaration['provenance']['name'])]['value'],
            ),
            'Primary span covers the final name literal.',
        );
        $check($declaration['contentHash'] === hash('sha256', $source), 'Content hash matches exact selected bytes.');
        $check($declaration['confidence'] === 'declaration-candidate', 'Every result remains a declaration candidate.');
    }
    $check(! file_exists($fixture.'/executed'), 'Selected route source is never executed.');
    $check(
        ! str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'private-'),
        'Unselected environment and handler content is absent from metadata.',
    );

    $bad = $exporter->export($fixture, ['routes/broken.php', 'routes/missing.php', '../outside.php']);
    $check(
        array_column($bad['errors'], 'code') === ['parse-failure', 'unreadable-source', 'invalid-source'],
        'Parse, read, and containment failures stay generic.',
    );
    $check(
        ! str_contains(json_encode($bad, JSON_THROW_ON_ERROR), 'private parse value'),
        'Parse errors do not expose selected source fragments.',
    );
    $duplicate = $exporter->export($fixture, ['routes/web.php', 'routes/./web.php']);
    $check(count($duplicate['declarations']) === 5, 'Canonical duplicate source paths are read once.');
    $check($exporter->export($fixture, [])['declarations'] === [], 'Empty selections do not discover route files.');

    $invalidList = false;
    try {
        $exporter->export($fixture, ['source' => 'routes/web.php']);
    } catch (InvalidArgumentException) {
        $invalidList = true;
    }
    $check($invalidList, 'Associative source selections are rejected.');
} finally {
    foreach (glob($fixture.'/routes/*') ?: [] as $path) {
        unlink($path);
    }
    foreach (['.env', 'executed'] as $name) {
        if (is_file($fixture.'/'.$name)) {
            unlink($fixture.'/'.$name);
        }
    }
    rmdir($fixture.'/routes');
    rmdir($fixture);
}

echo "Route parameter metadata export: {$checks} checks passed.\n";
