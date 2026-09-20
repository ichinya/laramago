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
    // UTF-8 byte positions: café 日本語.
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
    R::name('group.')->group(function () {
        R::get('/group', fn () => null)->name('group.hidden');
    });
    if (true) { R::get('/conditional', fn () => null)->name('conditional.hidden'); }
    function register() { R::get('/function', fn () => null)->name('function.hidden'); }
    class Example { public function boot() { R::get('/method', fn () => null)->name('body.hidden'); } }
    throw new \RuntimeException('Source must never execute.');
    PHP;
file_put_contents($fixture.'/routes/web.php', $source);
file_put_contents($fixture.'/routes/shadow.php', <<<'PHP'
    <?php
    namespace Other;
    use Application\Route;
    Route::get('/shadow', fn () => null)->name('shadow.hidden');
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
