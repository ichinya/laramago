<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;

require dirname(__DIR__) . '/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()) . '/laramago inertia catalog ' . bin2hex(random_bytes(8));
foreach (['Frontend Pages/Admin', 'resources/frontend pages/Admin'] as $directory) {
    mkdir($workspace . '/' . $directory, 0777, true);
}
file_put_contents($workspace . '/Frontend Pages/Admin/Users.vue', '<script>unknown()</script>');
file_put_contents($workspace . '/Frontend Pages/Admin/Managers.Vue', '<script>unknown()</script>');
file_put_contents($workspace . '/Frontend Pages/Report.v2.tsx', 'unknown()');
file_put_contents($workspace . '/Frontend Pages/ignored.js', 'unknown()');
file_put_contents($workspace . '/resources/frontend pages/Admin/Users.vue', '<script>unknown()</script>');

/** @param array<string, mixed>|null $configuration */
$catalog = static function (?array $configuration) use ($workspace): ReferenceCatalogs {
    file_put_contents($workspace . '/composer.json', json_encode(
        $configuration === null
            ? []
            : ['extra' => ['laramago' => ['reference-catalogs' => ['inertia-pages' => $configuration]]]],
        JSON_THROW_ON_ERROR,
    ));

    return new ReferenceCatalogs($workspace);
};

$lazy = $catalog([
    'complete' => true,
    'paths' => ['resources/frontend pages'],
    'extensions' => ['vue'],
]);
file_put_contents($workspace.'/resources/frontend pages/Late.vue', '<script>unknown()</script>');
if ($lazy->containsInertiaPage('Late') !== true) {
    throw new RuntimeException('Expected Inertia page roots to be enumerated on first use.');
}
unlink($workspace.'/resources/frontend pages/Late.vue');

$incomplete = $catalog([
    'paths' => ['Frontend Pages', 'resources/frontend pages'],
    'extensions' => ['vue', '.tsx', 'Vue'],
]);
$expected = [
    [
        'name' => 'Admin/Managers',
        'path' => 'Frontend Pages/Admin/Managers.Vue',
        'root' => 'Frontend Pages',
        'extension' => 'Vue',
    ],
    [
        'name' => 'Admin/Users',
        'path' => 'Frontend Pages/Admin/Users.vue',
        'root' => 'Frontend Pages',
        'extension' => 'vue',
    ],
    ['name' => 'Report.v2', 'path' => 'Frontend Pages/Report.v2.tsx', 'root' => 'Frontend Pages', 'extension' => 'tsx'],
    [
        'name' => 'Admin/Users',
        'path' => 'resources/frontend pages/Admin/Users.vue',
        'root' => 'resources/frontend pages',
        'extension' => 'vue',
    ],
];
if ($incomplete->inertiaPages() !== $expected) {
    throw new RuntimeException(
        'Expected exact configured roots, duplicate pages, paths with spaces and case to be preserved; got '
            . json_encode($incomplete->inertiaPages(), JSON_THROW_ON_ERROR),
    );
}
if ($incomplete->containsInertiaPage('Admin/Users') !== true) {
    throw new RuntimeException('Expected an exact known page in an incomplete catalog.');
}
foreach (['admin/Users', 'Admin/USERS', 'absent'] as $name) {
    if ($incomplete->containsInertiaPage($name) !== null) {
        throw new RuntimeException('Incomplete catalog must not prove absence for ' . $name . '.');
    }
}
echo "PASS: incomplete catalog preserves known exact-case pages and duplicate roots\n";

$complete = $catalog([
    'complete' => true,
    'paths' => ['Frontend Pages', 'resources/frontend pages'],
    'extensions' => ['vue', '.tsx', 'Vue'],
]);
if ($complete->containsInertiaPage('Admin/Users') !== true || $complete->containsInertiaPage('admin/Users') !== false) {
    throw new RuntimeException('Complete catalog must distinguish exact known names from proven absence.');
}
if (is_file($workspace . '/executed.txt')) {
    throw new RuntimeException('Page discovery must not execute frontend files.');
}
echo "PASS: complete catalog proves only exact-case absence\n";

mkdir($workspace . '/linked target');
file_put_contents($workspace . '/linked target/Hidden.vue', 'unknown()');
set_error_handler(static fn(): bool => true);
$linked = symlink($workspace . '/linked target', $workspace . '/Frontend Pages/Linked');
restore_error_handler();
if ($linked) {
    $symlinked = $catalog([
        'complete' => true,
        'paths' => ['Frontend Pages'],
        'extensions' => ['vue'],
    ]);
    if ($symlinked->inertiaPages() !== null || $symlinked->containsInertiaPage('Linked/Hidden') !== null) {
        throw new RuntimeException('An unscanned linked directory must make the catalog unknown.');
    }
    unlink($workspace . '/Frontend Pages/Linked');
    echo "PASS: linked directories make the catalog unknown\n";
} else {
    echo "SKIP: host does not permit creating a directory symlink\n";
}

$unknownConfigurations = [
    null,
    ['complete' => true, 'paths' => ['missing root'], 'extensions' => ['vue']],
    ['complete' => true, 'paths' => ['Frontend Pages'], 'extensions' => ['vue', false]],
    ['complete' => 'yes', 'paths' => ['Frontend Pages'], 'extensions' => ['vue']],
    ['complete' => true, 'paths' => ['../outside'], 'extensions' => ['vue']],
];
foreach ($unknownConfigurations as $configuration) {
    $unknown = $catalog($configuration);
    if ($unknown->inertiaPages() !== null || $unknown->containsInertiaPage('Admin/Users') !== null) {
        throw new RuntimeException('Missing, unsafe or malformed contracts must remain unknown.');
    }
}
echo "PASS: missing, unsafe and malformed catalogs remain unknown\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
$resolvedRoot = realpath($workspace);
foreach ($iterator as $entry) {
    $resolved = realpath($entry->getPathname());
    if (
        $resolvedRoot === false
        || $resolved === false
        || !str_starts_with($resolved, $resolvedRoot . DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside temporary workspace.');
    }
    $entry->isDir() ? rmdir($resolved) : unlink($resolved);
}
rmdir($workspace);
