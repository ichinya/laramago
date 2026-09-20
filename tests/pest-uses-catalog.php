<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\PestUsesCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago pest uses '.bin2hex(random_bytes(8));
mkdir($root.'/tests/Feature/Jobs', 0777, true);
mkdir($root.'/tests/Unit', 0777, true);
$write = static function (string $file, string $body) use ($root): void {
    file_put_contents($root.'/'.$file, $body);
};
$catalog = static function (array $sources, array $testFiles) use ($root, $write): PestUsesCatalog {
    $write('composer.json', json_encode([
        'extra' => [
            'laramago' => ['pest-uses' => [
                'sources' => $sources,
                'test-files' => $testFiles,
            ]],
        ],
    ], JSON_THROW_ON_ERROR));

    return new PestUsesCatalog($root);
};
$assert = static function (bool $value, string $message): void {
    if (! $value) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$write('tests/Feature/Jobs/SendJobTest.php', '<?php throw new RuntimeException("Test file executed");');
$write('tests/Feature/OtherTest.php', '<?php throw new RuntimeException("Test file executed");');
$write('tests/Unit/ExampleTest.php', '<?php throw new RuntimeException("Test file executed");');
$write('tests/Pest.php', <<<'PHP'
    <?php
    use Tests\TestCase as Base;
    use Illuminate\Foundation\Testing\RefreshDatabase as Refresh;
    throw new RuntimeException('Pest source executed');
    uses(Base::class, Refresh::class)->in('Feature');
    pest()->in('Feature/Jobs/*Job*.php')->extend(Base::class);
    pest()->extend(Base::class)->use(Refresh::class)->in(__DIR__.'/Unit');
    uses(Refresh::class)->in('Unit')->in('Feature/Jobs');
    pest()->project()->github('example/example');
    function helper(): void {}
    PHP);
$write('tests/Unit/ExampleTest.php', <<<'PHP'
    <?php
    pest()->extend(\Tests\SpecificCase::class);
    it('works', fn () => null);
    PHP);
$selected = ['tests/Feature/Jobs/SendJobTest.php', 'tests/Feature/OtherTest.php', 'tests/Unit/ExampleTest.php'];
$declarations = $catalog(['tests/Pest.php', 'tests/Unit/ExampleTest.php'], $selected)->declarations();
$assert(
    $declarations !== null && count($declarations) === 5,
    'ordered source declarations are parsed without executing test code',
);
$assert(
    $declarations[0]['names'] === ['Tests\\TestCase', 'Illuminate\\Foundation\\Testing\\RefreshDatabase'],
    'imported class and trait names resolve lexically',
);
$assert(
    $declarations[0]['files'] === [$root.'/tests/Feature/Jobs/SendJobTest.php', $root.'/tests/Feature/OtherTest.php'],
    'directory target applies to selected descendant files',
);
$assert(
    $declarations[1]['files'] === [$root.'/tests/Feature/Jobs/SendJobTest.php'],
    'globbed directory target matches only selected descendants',
);
$assert(
    $declarations[2]['names'] === ['Tests\\TestCase', 'Illuminate\\Foundation\\Testing\\RefreshDatabase']
    && $declarations[2]['files'] === [$root.'/tests/Unit/ExampleTest.php'],
    'fluent extend and use retain order with __DIR__ paths',
);
$assert(
    $declarations[3]['files'] === [$root.'/tests/Feature/Jobs/SendJobTest.php'],
    'later in() replaces earlier targets',
);
$assert(
    $declarations[4]['files'] === [$root.'/tests/Unit/ExampleTest.php'],
    'file-local declaration defaults to its own source file',
);
$assert(
    $declarations[1]['targets'] === [$root.'/tests/Feature/Jobs/*Job*.php'],
    'source target pattern is retained for later mapping',
);
$write('tests/Pest.php', '<?php uses(Tests\\TestCase::class); pest()->extend(Tests\\TestCase::class);');
$defaults = $catalog(['tests/Pest.php'], $selected)->declarations();
$assert(
    $defaults !== null
    && $defaults[0]['files'] === []
    && $defaults[1]['files'] === array_map(
        static fn (string $file): string => $root.'/'.$file,
        $selected,
    ),
    'uses() defaults to its source file while pest() from Pest.php defaults to its directory',
);
$assert($catalog(['tests/Pest.php'], ['../outside.php'])->declarations() === null, 'selected files cannot leave root');
$assert(
    $catalog(['tests/Pest.php'], ['tests/missing.php'])->declarations() === null,
    'missing selected files invalidate catalog',
);
$write('tests/Pest.php', '<?php uses(Tests\\TestCase::class)->in("../../outside");');
$assert($catalog(['tests/Pest.php'], $selected)->declarations() === [], 'escaping path declaration is omitted');
$write('tests/Pest.php', '<?php uses(Tests\\TestCase::class)->in(dynamicPath());');
$assert($catalog(['tests/Pest.php'], $selected)->declarations() === [], 'dynamic path declaration is omitted');
$write('tests/Pest.php', '<?php uses(dynamicClass())->in("Feature");');
$assert($catalog(['tests/Pest.php'], $selected)->declarations() === [], 'dynamic class declaration is omitted');
$write('tests/Pest.php', '<?php use function App\\uses; uses(Tests\\TestCase::class)->in("Feature");');
$assert(
    $catalog(['tests/Pest.php'], $selected)->declarations() === null,
    'shadowed global Pest functions are not inferred',
);

$write(
    'tests/Pest.php',
    '<?php pest(); uses(Tests\\TestCase::class)->extends(Tests\\Other::class); pest()->in("Feature")->uses(Tests\\Other::class);',
);
$assert(
    $catalog(['tests/Pest.php'], $selected)->declarations() === [],
    'bare configuration and unavailable fluent aliases are omitted',
);
$write('tests/Pest.php', '<?php pest()->extends(Tests\\TestCase::class); pest()->uses(Tests\\TraitName::class);');
$assert(
    count($catalog(['tests/Pest.php'], $selected)->declarations() ?? []) === 2,
    'configuration aliases remain supported',
);
$write('tests/Feature/.HiddenTest.php', '<?php');
$write('tests/Pest.php', '<?php uses(Tests\\TestCase::class)->in("Feature/*Test.php");');
$hidden = $catalog(['tests/Pest.php'], ['tests/Feature/.HiddenTest.php'])->declarations();
$assert($hidden !== null && $hidden[0]['files'] === [], 'wildcards do not infer platform-specific hidden-file matches');
unlink($root.'/tests/Feature/.HiddenTest.php');

foreach (['composer.json', 'tests/Pest.php', ...$selected] as $file) {
    unlink($root.'/'.$file);
}
rmdir($root.'/tests/Feature/Jobs');
rmdir($root.'/tests/Feature');
rmdir($root.'/tests/Unit');
rmdir($root.'/tests');
rmdir($root);
