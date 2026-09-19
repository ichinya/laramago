<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\NamedRouteCatalog;

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago route files '.bin2hex(random_bytes(8));
mkdir($workspace);
$header = '<?php use Illuminate\\Support\\Facades\\Route; ';
$cases = [
    'literal registration' => ['Route::get("/", fn () => null)->name("home");', ['home']],
    'concrete name appends' => ['Route::post("/", "Controller@store")->name("user.")->name("store");', ['user.store']],
    'nested names concatenate' => [
        'Route::name("a.")->group(function () { Route::group(["as" => "b."], function () { Route::put("/", [\\App\\Controller::class, "save"])->name("c"); }); });',
        ['a.b.c'],
    ],
    'handler body ignored' => [
        'Route::get("/", function () { throw new \\LogicException("Do not execute"); Route::get("/hidden", "C@f")->name("hidden"); })->name("home");',
        ['home'],
    ],
    'dynamic name' => ['Route::get("/", fn () => null)->name($name);', null],
    'conditional registration' => ['if (true) { Route::get("/", fn () => null)->name("home"); }', null],
    'partial then conditional' => ['Route::get("/", fn () => null)->name("home"); if (false) {}', null],
    'resource is unsupported' => ['Route::resource("users", \\App\\Controller::class);', null],
    'group string is unsupported' => ['Route::name("admin.")->group("other.php");', null],
    'captured group' => ['Route::name("admin.")->group(function () use ($x) {});', null],
    'dynamic group prefix' => ['Route::name($prefix)->group(function () {});', null],
    'repeated registrar name is not concrete append' => ['Route::name("a.")->name("b.")->group(function () {});', null],
    'action name attribute is not ignored' => ['Route::get("/", ["uses" => "C@f", "as" => "a."])->name("b");', null],
    'group extra attributes deferred' => ['Route::group(["as" => "a.", "prefix" => "v1"], function () {});', null],
    'unpacked name' => ['Route::get("/", fn () => null)->name(...["home"]);', null],
    'named registration arguments deferred' => ['Route::get(uri: "/", action: fn () => null)->name("home");', null],
    'unrelated facade' => ['\\Custom\\Route::get("/", fn () => null)->name("home");', null],
    'malformed syntax' => ['Route::get(', null],
    'empty catalog source' => ['', []],
];
$cases['namespace alias'] = [
    'namespace Routes; use Illuminate\\Support\\Facades\\Route as R; R::delete("/", fn () => null)->name("deleted");',
    ['deleted'],
];
foreach ($cases as $label => [$body, $expected]) {
    file_put_contents($workspace.'/routes.php', $label === 'namespace alias' ? '<?php '.$body : $header.$body);
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'named-routes' => [
                    'complete' => true,
                    'missing-route-resolver' => false,
                    'names' => ['manual'],
                    'files' => ['routes.php'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $catalog = new NamedRouteCatalog($workspace);
    if ($catalog->enabled() !== ($expected !== null)) {
        throw new RuntimeException($label.': wrong enabled state; inspect '.$workspace);
    }
    if ($expected !== null) {
        foreach (['manual', ...$expected] as $name) {
            if ($catalog->missing($name)) {
                throw new RuntimeException($label.': missing '.$name);
            }
        }
        if (! $catalog->missing('absent') || ! $catalog->missing('hidden')) {
            throw new RuntimeException($label.': unexpected inferred name');
        }
    } elseif ($catalog->missing('anything')) {
        throw new RuntimeException($label.': partial catalog must not diagnose');
    }
    echo 'PASS: '.$label."\n";
}
foreach ([
    null,
    'routes.php',
    ['../outside.php'],
    ['/absolute.php'],
    ['C:/absolute.php'],
    [false],
    ['missing.php'],
    ['routes.php' => 'routes.php'],
] as $files) {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'named-routes' => [
                    'complete' => true,
                    'missing-route-resolver' => false,
                    'names' => [],
                    'files' => $files,
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    if ((new NamedRouteCatalog($workspace))->enabled()) {
        throw new RuntimeException('Invalid file input accepted: '.json_encode($files));
    }
}
echo "PASS: malformed, escaping and unreadable paths disable the catalog\n";
foreach ([
    ['complete' => false, 'missing-route-resolver' => false],
    ['complete' => true],
    ['complete' => true, 'missing-route-resolver' => true],
] as $assertions) {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['named-routes' => [...$assertions, 'names' => [], 'files' => ['routes.php']]]],
    ], JSON_THROW_ON_ERROR));
    if ((new NamedRouteCatalog($workspace))->enabled()) {
        throw new RuntimeException('Files must not imply completeness or absence of a resolver.');
    }
}
echo "PASS: route files never imply completeness\n";
