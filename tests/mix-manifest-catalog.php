<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\MixManifestCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago mix catalog '.bin2hex(random_bytes(8));
mkdir($root.'/public assets/dist', 0777, true);
mkdir($root.'/web assets', 0777, true);

$configure = static function (array $files) use ($root): void {
    file_put_contents($root.'/composer.json', json_encode([
        'extra' => ['laramago' => ['reference-catalogs' => ['mix-manifests' => ['files' => $files]]]],
    ], JSON_THROW_ON_ERROR));
};
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$configure([
    ['directory' => '', 'path' => 'public assets/mix-manifest.json'],
    ['directory' => 'dist', 'path' => 'public assets/dist/mix-manifest.json'],
]);
$main = $root.'/public assets/mix-manifest.json';
file_put_contents(
    $main,
    '{"/css/app.css":"/css/app.css?id=first","/css/app.css":"/css/app.css?id=last","/a\\\\b.js":"/a\\\\b.js?id=1","/name with space.js":"/name with space.js?id=2"}',
);
$catalog = new MixManifestCatalog($root);
$check($catalog->status() === 'complete', 'A literal object with string values must parse.');
$check($catalog->contains('css/app.css') === true, 'Native Mix must add the leading slash.');
$check($catalog->contains('/css/app.css') === true, 'Already absolute Mix keys stay unchanged.');
$check($catalog->value('css/app.css') === '/css/app.css?id=last', 'Native JSON duplicate-key last wins.');
$check($catalog->value('a\\b.js') === '/a\\b.js?id=1', 'Escaped backslashes stay exact.');
$check($catalog->contains('name with space.js') === true, 'Spaces in literal keys stay exact.');
$check($catalog->contains('CSS/app.css') === false, 'A complete map proves exact-case absence.');
$check($catalog->manifestPath() === 'public assets/mix-manifest.json', 'Configured project-relative path is exposed.');
$check($catalog->hotFileState() === 'absent', 'An absent hot file is a snapshot observation.');
$check($catalog->status('dist') === 'missing', 'The configured secondary manifest is missing.');
$check($catalog->hotFileState('dist') === 'absent', 'The secondary directory is inspected separately.');
$check($catalog->status('other') === 'unconfigured', 'Unknown directories are not guessed.');
$check($catalog->contains('css/app.css', 'other') === null, 'Unknown directories cannot prove absence.');

file_put_contents($root.'/public assets/dist/mix-manifest.json', '{"/js/app.js":"/js/app.js?id=3"}');
file_put_contents($root.'/public assets/dist/hot', 'http://localhost:8080');
$check($catalog->status('/dist') === 'missing', 'Snapshots remain stable until reset.');
$catalog->reset();
$check($catalog->status('/dist') === 'complete', 'Reset reloads a newly created manifest.');
$check($catalog->contains('js/app.js', 'dist') === true, 'Slash-prefixed and bare directories use one manifest.');
$check($catalog->hotFileState('dist') === 'present', 'Hot mode is observed separately from parsed entries.');
$check($catalog->hotFileState() === 'absent', 'Hot files are scoped to their directory.');

$hotLink = $root.'/public assets/hot';
set_error_handler(static fn (): bool => true);
$linked = symlink($root.'/public assets/dist/hot', $hotLink);
restore_error_handler();
if ($linked) {
    $catalog->reset();
    $check($catalog->hotFileState() === 'unknown', 'Symlinked hot paths must remain unknown.');
    unlink($hotLink);
}

$invalidCases = [
    ['{',                           'invalid-json'],
    ['[]',                          'invalid-shape'],
    ['"hello"',                     'invalid-shape'],
    ['{"/app.js":null}',            'invalid-shape'],
    ['{"/app.js":42}',              'invalid-shape'],
    ['{"/app.js":{"nested":true}}', 'invalid-shape'],
];
foreach ($invalidCases as [$json, $status]) {
    file_put_contents($main, $json);
    $catalog->reset();
    $check($catalog->status() === $status, 'Malformed/unsupported manifest must retain its problem status.');
    $check($catalog->entries() === null, 'Malformed/unsupported manifest cannot expose a complete map.');
    $check($catalog->contains('absent.js') === null, 'Malformed/unsupported manifest cannot prove absence.');
}

file_put_contents($main, '{"2":"numeric","/2":"prefixed"}');
$catalog->reset();
$check(
    $catalog->entries() === [2 => 'numeric', '/2' => 'prefixed'],
    'Decoded numeric keys retain native integer semantics.',
);
$check($catalog->value('2') === 'prefixed', 'Native leading slash keeps numeric-looking requests distinct.');

file_put_contents($main, '{}');
$catalog->reset();
$check($catalog->status() === 'complete' && $catalog->entries() === [], 'An empty object is a complete empty map.');
$check($catalog->contains('absent.js') === false, 'An empty complete map proves literal absence.');

unlink($main);
$catalog->reset();
$check($catalog->status() === 'missing' && $catalog->entries() === null, 'A missing file is not a complete empty map.');

$invalidConfigurations = [
    [['directory' => '', 'path' => '../outside/mix-manifest.json']],
    [['directory' => 'dist/..', 'path' => 'public assets/dist/mix-manifest.json']],
    [['directory' => '', 'path' => 'C:/public/mix-manifest.json']],
    [['directory' => '', 'path' => 'public assets/other.json']],
    [
        ['directory' => '', 'path' => 'web assets/mix-manifest.json'],
        ['directory' => '/', 'path' => 'public assets/mix-manifest.json'],
    ],
];
foreach ($invalidConfigurations as $files) {
    $configure($files);
    $catalog->reset();
    $check($catalog->status() === 'invalid-configuration', 'Unsafe or ambiguous paths cannot enable the catalog.');
    $check($catalog->entries() === null, 'Invalid configuration cannot expose a complete map.');
}

$configure([['directory' => '', 'path' => 'web assets/mix-manifest.json']]);
file_put_contents($root.'/web assets/mix-manifest.json', '{"/custom.js":"/custom.js?id=1"}');
$catalog->reset();
$check($catalog->contains('custom.js') === true, 'An explicit custom public root is used exactly.');
$check($catalog->manifestPath() === 'web assets/mix-manifest.json', 'Custom root path stays project-relative.');

unlink($root.'/web assets/mix-manifest.json');
mkdir($root.'/web assets/mix-manifest.json');
$catalog->reset();
$check($catalog->status() === 'unreadable', 'A directory at the manifest path is unreadable as JSON.');

echo
    "PASS: Mix manifest catalog parses explicit paths, preserves native keys, and separates incomplete states and hot observations\n"
;
