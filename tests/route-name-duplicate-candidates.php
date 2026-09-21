<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\RouteNameDuplicateCandidates;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago route name duplicates '.bin2hex(random_bytes(8));
mkdir($workspace.'/routes', recursive: true);

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$firstSource = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Route;

    file_put_contents(__DIR__.'/../executed', 'route source executed');
    Route::get('/one', 'Controller@one')->name('shared');
    Route::get('/replacement', 'Controller@first')->name('replacement');
    Route::get('/replacement', 'Controller@second')->name('replacement');
    Route::name('admin.')->group(function () {
        Route::post('/one', 'Controller@edit')->name('edit');
    });
    if (true) {
        Route::get('/conditional', 'Controller@conditional')->name('shared');
    }
    PHP;
$secondSource = <<<'PHP'
    <?php
    namespace Other;
    use Illuminate\Support\Facades\Route as R;

    R::name('admin.')->group(function () {
        R::post('/two', 'Controller@edit')->name('edit');
    });
    R::get('/two', 'Controller@two')->name('shared');
    PHP;
file_put_contents($workspace.'/routes/first.php', $firstSource);
file_put_contents($workspace.'/routes/second.php', $secondSource);
file_put_contents($workspace.'/routes/broken.php', '<?php broken(');

try {
    $export = (new RouteNameDuplicateCandidates)->export(
        $workspace,
        ['routes/first.php', 'routes/second.php', 'routes/broken.php'],
    );

    $check(
        $export['scope'] === [
            'kind' => 'route-name-duplicates',
            'evidence' => 'source-only',
            'semantics' => 'advisory-duplicate-name-candidates',
            'matching' => 'exact-case',
            'ordering' => 'selected-file-then-source',
            'exhaustive' => false,
        ],
        'Scope labels the result as non-exhaustive source-only advice.',
    );
    $check(
        array_column($export['candidates'], 'name') === ['replacement', 'admin.edit', 'shared'],
        'Exact duplicate candidates follow selected-file and source order.',
    );
    $check(
        array_unique(array_column($export['candidates'], 'confidence')) === ['source-only-candidate']
        && array_unique(array_column($export['candidates'], 'activeRouteConflict')) === ['unknown'],
        'Candidates do not claim an active route conflict.',
    );
    $replacement = $export['candidates'][0];
    $check(
        $replacement['line'] === 7
        && $replacement['firstLocation']['line'] === 6
        && $replacement['activeRouteConflict'] === 'unknown',
        'A known same-method and same-URI replacement remains advisory rather than a runtime error.',
    );
    $grouped = $export['candidates'][1];
    $check(
        $grouped['rawName'] === 'edit'
        && $grouped['firstLocation']['rawName'] === 'edit'
        && implode('', array_column($grouped['nameProvenance']['tokens'], 'value')) === 'admin.edit'
        && implode('', array_column($grouped['firstLocation']['nameProvenance']['tokens'], 'value')) === 'admin.edit',
        'Both grouped locations retain literal composition provenance.',
    );
    foreach ($export['candidates'] as $candidate) {
        foreach ([$candidate, $candidate['firstLocation']] as $location) {
            $source = file_get_contents($location['file']);
            $literal = substr($source, $location['start'], $location['end'] - $location['start']);
            $check(
                in_array($literal, ["'replacement'", "'edit'", "'shared'"], true),
                'Each first and repeated location preserves its original literal span.',
            );
            $check(
                $location['contentHash'] === hash('sha256', $source)
                && $location['declarationConfidence'] === 'known-positive',
                'Each location retains exact source hash and declaration confidence.',
            );
        }
    }
    $check(
        array_column($export['errors'], 'code') === ['parse-failure'],
        'Underlying parse uncertainty remains visible beside positive candidates.',
    );
    $check(! file_exists($workspace.'/executed'), 'Selected route PHP is never executed.');

    $reversed = (new RouteNameDuplicateCandidates)->export(
        $workspace,
        ['routes/second.php', 'routes/first.php'],
    );
    $check(
        $reversed['candidates'][0]['name'] === 'shared'
        && $reversed['candidates'][0]['firstLocation']['file'] === str_replace(
            '\\',
            '/',
            realpath($workspace.'/routes/second.php'),
        ),
        'Explicit file order determines which occurrence is first.',
    );

    $bounded = (new RouteNameDuplicateCandidates)->export(
        $workspace,
        array_fill(0, 257, 'routes/first.php'),
    );
    $check(
        $bounded['truncated'] === true
        && $bounded['truncationReasons'] === ['file-limit']
        && array_column($bounded['candidates'], 'name') === ['replacement'],
        'Truncation is disclosed without discarding source-proven candidates.',
    );
} finally {
    foreach (['first.php', 'second.php', 'broken.php'] as $file) {
        if (is_file($workspace.'/routes/'.$file)) {
            unlink($workspace.'/routes/'.$file);
        }
    }
    if (is_file($workspace.'/executed')) {
        unlink($workspace.'/executed');
    }
    rmdir($workspace.'/routes');
    rmdir($workspace);
}

echo "Route name duplicate candidates: {$checks} checks passed.\n";
