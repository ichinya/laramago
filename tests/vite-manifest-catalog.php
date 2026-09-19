<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\ViteManifestCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago vite catalog '.bin2hex(random_bytes(8));
mkdir($root.'/custom public/dist', 0777, true);
mkdir($root.'/custom public/alternate', 0777, true);
mkdir($root.'/custom public/2', 0777, true);
$manifest = $root.'/custom public/dist/entries.json';
$hot = $root.'/custom public/hot';
$check = static function (bool $result, string $message): void {
    if (! $result) {
        throw new RuntimeException($message);
    }
};
$configure = static function (array $files) use ($root): void {
    file_put_contents($root.'/composer.json', json_encode([
        'extra' => ['laramago' => ['reference-catalogs' => ['vite-manifests' => ['files' => $files]]]],
    ], JSON_THROW_ON_ERROR));
};
$configure([
    ['build-directory' => 'dist', 'path' => 'custom public/dist/entries.json', 'hot-file' => 'custom public/hot'],
    ['build-directory' => 'alternate', 'path' => 'custom public/alternate/manifest.json'],
    ['build-directory' => '2', 'path' => 'custom public/2/manifest.json'],
]);
file_put_contents(
    $root.'/custom public/2/manifest.json',
    '{"2":{"file":"assets/two.js"},"/2":{"file":"assets/slash.js"}}',
);
file_put_contents($manifest, json_encode([
    'resources/js/app.js' => [
        'file' => 'assets/app-123.js',
        'src' => 'resources/js/app.js',
        'isEntry' => true,
        'imports' => ['_shared.js'],
        'dynamicImports' => ['resources/js/lazy.js'],
        'css' => ['assets/app-123.css'],
        'assets' => ['assets/icon-123.svg'],
        'integrity' => 'sha384-example',
        'custom' => ['nested' => 'unchanged'],
    ],
    '_shared.js' => ['file' => 'assets/shared-123.js'],
], JSON_THROW_ON_ERROR));

$catalog = new ViteManifestCatalog($root);
$check($catalog->status('dist') === 'complete', 'Explicit Vite manifest parses.');
$check(
    $catalog->manifestPath('dist') === 'custom public/dist/entries.json',
    'Custom filename and root remain explicit.',
);
$entry = $catalog->entry('resources/js/app.js', 'dist');
$check($entry !== null && $entry['file'] === 'assets/app-123.js', 'Compiled asset filename is preserved.');
$check(
    $entry['imports'] === ['_shared.js'] && $entry['dynamicImports'] === ['resources/js/lazy.js'],
    'Import edges remain exact.',
);
$check(
    $entry['css'] === ['assets/app-123.css'] && $entry['assets'] === ['assets/icon-123.svg'],
    'CSS and asset lists remain exact.',
);
$check(
    $entry['integrity'] === 'sha384-example' && $entry['custom'] === ['nested' => 'unchanged'],
    'Unknown fields remain available.',
);
$check($catalog->contains('resources/js/app.js', 'dist') === true, 'Exact source key resolves.');
$check($catalog->contains('/resources/js/app.js', 'dist') === false, 'Vite does not prepend a slash to keys.');
$check($catalog->status('alternate') === 'missing', 'Other configured build has separate status.');
$check($catalog->status('build') === 'unconfigured', 'Default build directory is not guessed.');
$check($catalog->hotFileState('dist') === 'absent', 'Configured hot path is observed.');
$check($catalog->hotFileState('alternate') === 'unknown', 'Unconfigured hot path is unknown.');
$check($catalog->status('2') === 'complete', 'Numeric-looking build directory resolves.');
$check($catalog->manifestPath('2') === 'custom public/2/manifest.json', 'Numeric-looking directory retains its path.');
$check(
    $catalog->entry('2', '2') === ['file' => 'assets/two.js'],
    'Numeric-looking source key follows PHP array semantics.',
);
$check($catalog->entry('/2', '2') === ['file' => 'assets/slash.js'], 'Slash-prefixed numeric source remains distinct.');
$check(
    $catalog->contains('2', '2') === true && $catalog->contains('3', '2') === false,
    'Numeric source lookup uses complete map.',
);

file_put_contents($hot, 'http://localhost:5173');
file_put_contents($manifest, '{}');
$check(
    $catalog->status('dist') === 'complete' && $catalog->hotFileState('dist') === 'absent',
    'Snapshot is stable until reset.',
);
$catalog->reset();
$check($catalog->hotFileState('dist') === 'present', 'Reset refreshes hot observation.');
$check(
    $catalog->entries('dist') === [] && $catalog->contains('missing.js', 'dist') === false,
    'Empty object is a complete empty map.',
);

foreach ([
    ['{',                                               'invalid-json'],
    ['[]',                                              'invalid-shape'],
    ['{"app.js":null}',                                 'invalid-shape'],
    ['{"app.js":{"imports":["other.js"]}}',             'invalid-shape'],
    ['{"app.js":{"file":"app.js","imports":[42]}}',     'invalid-shape'],
    ['{"app.js":{"file":"app.js","assets":{"a":"b"}}}', 'invalid-shape'],
] as [$json, $status]) {
    file_put_contents($manifest, $json);
    $catalog->reset();
    $check($catalog->status('dist') === $status, 'Bad JSON or unsupported chunk shape retains status.');
    $check($catalog->contains('absent.js', 'dist') === null, 'Incomplete maps cannot prove absence.');
}

$deep = '{"app.js":{"file":"app.js","custom":'.str_repeat('[', 65).'"x"'.str_repeat(']', 65).'}}';
file_put_contents($manifest, $deep);
$catalog->reset();
$check(
    $catalog->status('dist') === 'oversized',
    'Valid JSON beyond the nesting limit reports a bound, not malformed JSON.',
);
$check($catalog->contains('app.js', 'dist') === null, 'Depth-limited files cannot prove key presence.');

file_put_contents($manifest, str_repeat(' ', 8388609));
$catalog->reset();
$check($catalog->status('dist') === 'oversized', 'Oversized files are not parsed.');
unlink($manifest);
$catalog->reset();
$check($catalog->status('dist') === 'missing', 'Missing manifest does not imply an empty map.');

file_put_contents($root.'/blocked', 'regular file ancestor');
$configure([['build-directory' => 'blocked', 'path' => 'blocked/manifest.json', 'hot-file' => 'blocked/hot']]);
$catalog->reset();
$check($catalog->status('blocked') === 'unreadable', 'A regular-file ancestor cannot be traversed as a directory.');
$check($catalog->hotFileState('blocked') === 'unknown', 'A hot file below a regular-file ancestor is unknown.');

foreach ([
    [['build-directory' => '../dist', 'path' => 'custom public/dist/entries.json']],
    [['build-directory' => 'dist', 'path' => 'C:/outside/entries.json']],
    [['build-directory' => 'dist', 'path' => 'custom public/dist/entries.json', 'hot-file' => '../hot']],
    [
        ['build-directory' => 'dist', 'path' => 'custom public/dist/entries.json'],
        ['build-directory' => 'dist', 'path' => 'custom public/alternate/manifest.json'],
    ],
] as $files) {
    $configure($files);
    $catalog->reset();
    $check($catalog->status('dist') === 'invalid-configuration', 'Unsafe or duplicate explicit paths are rejected.');
}

echo "PASS: Vite manifest catalog preserves chunks and bounds incomplete states\n";
