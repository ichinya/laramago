<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\PublicAssetCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago public assets '.bin2hex(random_bytes(8));
mkdir($workspace.'/public/images', 0777, true);
mkdir($workspace.'/custom public/images', 0777, true);
file_put_contents($workspace.'/public/images/Logo.png', 'one');
file_put_contents($workspace.'/custom public/images/Logo.png', 'two');
file_put_contents($workspace.'/custom public/app.js', 'three');

$disabled = new PublicAssetCatalog($workspace);
if ($disabled->assets() !== null || $disabled->contains('images/Logo.png') !== null) {
    throw new RuntimeException('An existing public directory must not enable an unconfigured catalog.');
}

$configure = static function (array $configuration) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['reference-catalogs' => ['public-assets' => $configuration]]],
    ], JSON_THROW_ON_ERROR));
};

$configure(['paths' => ['public', 'custom public']]);
$catalog = new PublicAssetCatalog($workspace);
file_put_contents($workspace.'/custom public/later.css', 'late');
$expected = [
    ['name' => 'images/Logo.png', 'path' => 'public/images/Logo.png', 'root' => 'public'],
    ['name' => 'app.js', 'path' => 'custom public/app.js', 'root' => 'custom public'],
    ['name' => 'images/Logo.png', 'path' => 'custom public/images/Logo.png', 'root' => 'custom public'],
    ['name' => 'later.css', 'path' => 'custom public/later.css', 'root' => 'custom public'],
];
if ($catalog->assets() !== $expected) {
    throw new RuntimeException('Public asset catalog lost configured root order, duplicates, spaces, or lazy files.');
}
if ($catalog->contains('/images/Logo.png') !== true || $catalog->contains('missing.png') !== null) {
    throw new RuntimeException('Incomplete catalog must preserve exact known assets without proving absence.');
}
unlink($workspace.'/custom public/later.css');
if ($catalog->contains('later.css') !== true) {
    throw new RuntimeException('Catalog should keep a stable snapshot until reset.');
}
$catalog->reset();
if ($catalog->contains('later.css') !== null) {
    throw new RuntimeException('Reset should discard the old snapshot.');
}

$configure(['complete' => true, 'paths' => ['public', 'custom public']]);
$catalog->reset();
if ($catalog->contains('missing.png') !== false || $catalog->contains('images/Logo.png') !== true) {
    throw new RuntimeException('Complete catalog should prove an ordinary missing public path.');
}
foreach ([
    'images/logo.png',
    'https://cdn.example/logo.png',
    '//cdn.example/logo.png',
    '/images/Logo.png?v=1',
    '/images/Logo.png#part',
    'images/%4cogo.png',
    'images/../secret.txt',
    'images//Logo.png',
    'C:/images/Logo.png',
    '\\images\\Logo.png',
] as $unknown) {
    if ($catalog->contains($unknown) !== null) {
        throw new RuntimeException('Ambiguous or unsafe asset reference should remain unknown: '.$unknown);
    }
}

$configure(['complete' => true, 'paths' => ['missing-public']]);
$catalog->reset();
if ($catalog->assets() !== null || $catalog->contains('missing.png') !== null) {
    throw new RuntimeException('Missing configured root must invalidate the catalog.');
}
$configure(['complete' => true, 'paths' => ['../outside']]);
$catalog->reset();
if ($catalog->contains('missing.png') !== null) {
    throw new RuntimeException('Escaping configured root must invalidate the catalog.');
}

$configure(['complete' => true, 'paths' => ['public']]);
$catalog->reset();
$linked = $workspace.'/public/linked.png';
if (@symlink($workspace.'/custom public/app.js', $linked)) {
    if ($catalog->contains('missing.png') !== null) {
        throw new RuntimeException('Linked entries must invalidate the catalog.');
    }
    unlink($linked);
    $catalog->reset();
}

for ($index = 0; $index <= 4096; $index++) {
    file_put_contents($workspace.'/public/asset-'.$index.'.txt', 'x');
}
if ($catalog->contains('missing.png') !== null) {
    throw new RuntimeException('Oversized scans must not prove absence.');
}

$cleanup = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($cleanup as $entry) {
    if ($entry->isDir() && ! $entry->isLink()) {
        rmdir($entry->getPathname());
    } else {
        unlink($entry->getPathname());
    }
}
rmdir($workspace);

echo "PASS: public assets require an explicit safe catalog and completeness for absence\n";
