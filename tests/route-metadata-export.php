<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\RouteMetadataExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-route-export-'.bin2hex(random_bytes(8));
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
    // UTF-8 byte positions: café.
    file_put_contents(__DIR__.'/../executed', 'source executed');
    R::get('/one', fn () => 'private-handler-content')->name('123');
    R::POST('/two', 'Controller@method')->name("café.show");
    \Illuminate\Support\Facades\Route::any('/three', function () {
        R::get('/nested', fn () => null)->name('handler.hidden');
    })->name('three');
    R::get('/dynamic', fn () => null)->name($dynamic);
    R::get($uri, fn () => null)->name('dynamic.uri');
    R::get('/concat', fn () => null)->name('a'.'b');
    R::get('/chain', fn () => null)->middleware('auth')->name('fluent.hidden');
    R::get('/twice', fn () => null)->name('first')->name('second');
    R::get(uri: '/named', action: fn () => null)->name('named.hidden');
    R::get('/unpack', fn () => null)->name(...['unpack.hidden']);
    R::get('/placeholder', fn () => null)->name(...);
    R::$method('/dynamic-method', fn () => null)->name('method.hidden');
    $class::get('/dynamic-class', fn () => null)->name('class.hidden');
    $object->name('object.hidden');
    Route::get('/unimported', fn () => null)->name('unimported.hidden');
    R::name($prefix)->group(function () {
        R::get('/group', fn () => null)->name('group.hidden');
    });
    if (true) { R::get('/conditional', fn () => null)->name('conditional.hidden'); }
    function register() { R::get('/function', fn () => null)->name('function.hidden'); }
    class Example { public function boot() { R::get('/method', fn () => null)->name('body.hidden'); } }
    throw new \RuntimeException('Source must never execute.');
    PHP;
file_put_contents($fixture.'/routes/web.php', $source);
file_put_contents($fixture.'/routes/groups.php', <<<'PHP'
    <?php
    namespace Grouped;
    use Illuminate\Support\Facades\Route as R;
    R::name('admin.')->group(function () {
        R::get('/users', fn () => null)->name('users');
        R::name("nested.")->group(static function (): void {
            R::post('/first', 'Controller@first')->name('show');
            R::post('/second', 'Controller@second')->name('show');
            R::get('/handler', function () {
                R::get('/hidden', fn () => null)->name('handler.hidden');
            })->name('handler.visible');
        });
        R::group(['as' => 'array.'], function () {
            R::patch('/array', fn () => null)->name('leaf');
        });
        R::group(['as' => $dynamicPrefix], function () {
            R::get('/dynamic-array', fn () => null)->name('array-dynamic.hidden');
        });
        R::group(['as' => 'extra.', 'prefix' => '/extra'], function () {
            R::get('/extra', fn () => null)->name('array-extra.hidden');
        });
        R::name($dynamicPrefix)->group(function () {
            R::get('/dynamic-prefix', fn () => null)->name('dynamic.hidden');
        });
        R::NAME('wrong-case.')->group(function () {
            R::get('/wrong-case', fn () => null)->name('case.hidden');
        });
        R::name('captured.')->group(function () use ($capture) {
            R::get('/captured', fn () => null)->name('captured.hidden');
        });
        R::name('parameter.')->group(function ($router) {
            R::get('/parameter', fn () => null)->name('parameter.hidden');
        });
        R::name('callback.')->group($callback);
        if (true) {
            R::get('/conditional', fn () => null)->name('conditional.hidden');
        }
        function registerHidden(): void {
            R::get('/function', fn () => null)->name('function.hidden');
        }
    });
    R::get('/flat', fn () => null)->name('flat');
    PHP);
file_put_contents($fixture.'/routes/shadow.php', <<<'PHP'
    <?php
    namespace Other;
    use Application\Route;
    Route::get('/shadow', fn () => null)->name('shadow.hidden');
    Route::name('shadow.')->group(function () {
        Route::get('/group', fn () => null)->name('group.hidden');
    });
    PHP);
file_put_contents($fixture.'/routes/global.php', "<?php Route::get('/', fn () => null)->name('global.hidden');");
file_put_contents($fixture.'/routes/broken.php', "<?php 'private-parse-content'; broken(");
file_put_contents($fixture.'/bootstrap.php', "<?php file_put_contents(__DIR__.'/bootstrap-executed', 'yes');");
file_put_contents($fixture.'/.env', 'SECRET=private-environment-content');
$exporter = new RouteMetadataExport;
try {
    $result = $exporter->export($fixture, ['routes/web.php', 'routes/shadow.php', 'routes/global.php']);
    $check($result['schemaVersion'] === 1, 'Versioned contract.');
    $check($result['scope'] === ['kind' => 'routes', 'evidence' => 'source-only'], 'Explicit source-only scope.');
    $check($result['errors'] === [] && $result['truncated'] === false, 'Supported files export without errors.');
    $check(
        array_column($result['declarations'], 'name') === ['123', 'café.show', 'three'],
        'Only resolved direct literal candidates.',
    );
    $check(
        array_keys($result['declarations'][0]) === [
            'name',
            'file',
            'start',
            'end',
            'line',
            'contentHash',
            'confidence',
        ],
        'Flat candidate shape remains backward compatible.',
    );
    foreach ($result['declarations'] as $declaration) {
        $literal = substr($source, $declaration['start'], $declaration['end'] - $declaration['start']);
        $check(in_array($literal, ["'123'", '"café.show"', "'three'"], true), 'Original half-open literal byte span.');
        $check(
            $declaration['line'] === (substr_count(substr($source, 0, $declaration['start']), "\n") + 1),
            'Original source line.',
        );
        $check($declaration['contentHash'] === hash('sha256', $source), 'Hash matches exact parsed bytes.');
        $check(
            $declaration['file'] === str_replace('\\', '/', realpath($fixture.'/routes/web.php')),
            'Canonical source path.',
        );
    }
    $check(
        ! file_exists($fixture.'/executed') && ! file_exists($fixture.'/bootstrap-executed'),
        'No source or bootstrap execution.',
    );
    $check(
        ! str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'private-'),
        'No handler or environment values exported.',
    );
    $duplicate = $exporter->export($fixture, ['routes/web.php', 'routes/./web.php']);
    $check(count($duplicate['declarations']) === 3, 'Canonical duplicate sources read once.');
    $groups = $exporter->export($fixture, ['routes/groups.php', 'routes/shadow.php']);
    $check(
        array_column($groups['declarations'], 'name') === [
            'admin.users',
            'admin.nested.show',
            'admin.nested.show',
            'admin.nested.handler.visible',
            'admin.array.leaf',
            'flat',
        ],
        'Literal prefixes compose in source order while duplicates remain candidates.',
    );
    $groupSource = file_get_contents($fixture.'/routes/groups.php');
    foreach (array_slice($groups['declarations'], 0, 5) as $declaration) {
        $check($declaration['rawName'] !== $declaration['name'], 'Composed candidates preserve the raw leaf name.');
        $check(
            substr($groupSource, $declaration['start'], $declaration['end'] - $declaration['start']) === substr(
                $groupSource,
                $declaration['nameProvenance']['tokens'][array_key_last(
                    $declaration['nameProvenance']['tokens'],
                )]['start'],
                $declaration['nameProvenance']['tokens'][array_key_last(
                    $declaration['nameProvenance']['tokens'],
                )]['end']
                - $declaration['nameProvenance']['tokens'][array_key_last(
                    $declaration['nameProvenance']['tokens'],
                )]['start'],
            ),
            'Primary source span is exactly the raw leaf token, not a synthetic composed span.',
        );
        $check(
            implode('', array_column($declaration['nameProvenance']['tokens'], 'value')) === $declaration['name'],
            'Composition provenance reconstructs the candidate from original literal tokens.',
        );
        $check(
            array_column($declaration['nameProvenance']['tokens'], 'role')[array_key_last(
                $declaration['nameProvenance']['tokens'],
            )] === 'route-name',
            'Composition provenance distinguishes the raw route name token.',
        );
        foreach ($declaration['nameProvenance']['tokens'] as $token) {
            $check(
                str_starts_with(substr($groupSource, $token['start'], $token['end'] - $token['start']), "'")
                || str_starts_with(substr($groupSource, $token['start'], $token['end'] - $token['start']), '"'),
                'Each composition token carries its own original source span.',
            );
        }
    }
    $check(
        array_keys($groups['declarations'][5]) === [
            'name',
            'file',
            'start',
            'end',
            'line',
            'contentHash',
            'confidence',
        ],
        'Ungrouped candidates stay flat when grouped candidates share a source.',
    );
    $check(
        ! str_contains(json_encode($groups, JSON_THROW_ON_ERROR), '.hidden'),
        'Dynamic groups, captured or parameterized callbacks, conditions, functions, handlers, and shadow facades defer.',
    );
    $bad = $exporter->export($fixture, [
        'routes/broken.php',
        'routes/missing.php',
        '../outside.php',
        '/absolute.php',
        'php://filter.php',
        "bad\0.php",
    ]);
    $check(
        array_column($bad['errors'], 'code') === [
            'parse-failure',
            'unreadable-source',
            'invalid-source',
            'invalid-source',
            'invalid-source',
            'invalid-source',
        ],
        'Generic parse, read, and containment errors.',
    );
    $check(
        ! str_contains(json_encode($bad, JSON_THROW_ON_ERROR), 'private-parse-content'),
        'Parser errors never expose source fragments.',
    );
    file_put_contents($fixture.'/routes/web.php', '<?php broken(');
    $changed = $exporter->export($fixture, ['routes/web.php']);
    $check(
        $changed['declarations'] === [] && $changed['errors'][0]['code'] === 'parse-failure',
        'Reparse changed invalid source without stale declarations.',
    );
    file_put_contents($fixture.'/routes/web.php', $source);
    $check(
        count($exporter->export($fixture, ['routes/web.php'])['declarations']) === 3,
        'Recovery reparses repaired source.',
    );
    file_put_contents($fixture.'/routes/large.php', '<?php '.str_repeat(' ', 1024 * 1024));
    $large = $exporter->export($fixture, ['routes/large.php']);
    $check($large['truncated'] === true && $large['errors'][0]['code'] === 'source-limit', 'Bounded per-file read.');
    $check($large['truncationReasons'] === ['file-byte-limit'], 'Explicit file byte truncation reason.');
    $largeFiles = [];
    for ($index = 0; $index < 9; $index++) {
        $largeFiles[] = 'routes/large'.$index.'.php';
        file_put_contents($fixture.'/routes/large'.$index.'.php', '<?php '.str_repeat(' ', 1024 * 1024));
    }
    $totalLimit = $exporter->export($fixture, $largeFiles);
    $check(
        in_array('total-byte-limit', $totalLimit['truncationReasons'], true),
        'Oversized reads also consume total byte budget.',
    );
    $check(count($totalLimit['errors']) === 8, 'Stop reading once aggregate byte budget is exhausted.');
    file_put_contents(
        $fixture.'/routes/many.php',
        '<?php use Illuminate\\Support\\Facades\\Route; '.str_repeat("Route::get('/', 'C')->name('n');", 10001),
    );
    $many = $exporter->export($fixture, ['routes/many.php']);
    $check(
        count($many['declarations']) === 10000 && $many['truncationReasons'] === ['declaration-limit'],
        'Bounded declaration export.',
    );
    $depthSource =
        "<?php use Illuminate\\Support\\Facades\\Route;\n"
        .str_repeat("Route::name('d.')->group(function () {\n", 33)
        ."Route::get('/', fn () => null)->name('inside');\n"
        .str_repeat("});\n", 33)
        ."Route::get('/outside', fn () => null)->name('outside');\n";
    file_put_contents($fixture.'/routes/deep.php', $depthSource);
    $deep = $exporter->export($fixture, ['routes/deep.php']);
    $check(
        array_column($deep['declarations'], 'name') === ['outside']
        && $deep['truncated'] === true
        && $deep['truncationReasons'] === ['group-depth-limit'],
        'Group traversal depth is bounded without hiding safe sibling candidates.',
    );
    file_put_contents(
        $fixture.'/routes/wide.php',
        "<?php use Illuminate\\Support\\Facades\\Route; Route::name('"
        .str_repeat('x', 5000)
        ."')->group(function () { Route::get('/', fn () => null)->name('leaf'); });",
    );
    $wide = $exporter->export($fixture, ['routes/wide.php']);
    $check(
        $wide['declarations'] === []
        && $wide['truncated'] === true
        && $wide['truncationReasons'] === ['composed-name-byte-limit'],
        'Composed name expansion is bounded and disclosed.',
    );
    $check(
        $exporter->export($fixture, array_fill(0, 257, 'routes/web.php'))['truncated'] === true,
        'Bounded explicit file list.',
    );
    $check($exporter->export($fixture, [])['declarations'] === [], 'Empty selection does not discover files.');
    file_put_contents($fixture.'/routes/import.php', <<<'PHP'
        <?php
        namespace Example;
        use Illuminate\Support\Facades\{Route as Routes};
        Routes::get('/', 'Controller')->name("escaped\x2ename");
        PHP);
    $import = $exporter->export($fixture.'/routes/..', ['routes/import.php']);
    $check(
        $import['projectRoot'] === str_replace('\\', '/', realpath($fixture)),
        'Project root is canonical even for relative segments.',
    );
    $check($import['declarations'][0]['name'] === 'escaped.name', 'Group imports and decoded literal strings.');
    $importSource = file_get_contents($fixture.'/routes/import.php');
    $importDeclaration = $import['declarations'][0];
    $check(
        substr($importSource, $importDeclaration['start'], $importDeclaration['end'] - $importDeclaration['start'])
        === '"escaped\\x2ename"',
        'Escape sequences keep original source byte span.',
    );
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
    foreach (['bootstrap.php', '.env', 'executed', 'bootstrap-executed'] as $name) {
        if (is_file($fixture.'/'.$name)) {
            unlink($fixture.'/'.$name);
        }
    }
    rmdir($fixture.'/routes');
    rmdir($fixture);
}
echo "Route metadata export: {$checks} checks passed.\n";
