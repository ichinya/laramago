<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\PestExpectationCatalog;
use PhpParser\Node;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-pest-expect-'.bin2hex(random_bytes(8));
mkdir($workspace);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$configure = static function (mixed $sources) use ($workspace): PestExpectationCatalog {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['pest-expectations' => ['sources' => $sources]]],
    ], JSON_THROW_ON_ERROR));

    return new PestExpectationCatalog($workspace);
};
file_put_contents($workspace.'/Pest.php', <<<'PHP'
    <?php
    use function Other\expect as otherExpect;
    expect()->extend('toHaveFlag', function (bool $flag = true): object {
        throw new \RuntimeException('extension body must not execute');
    });
    otherExpect()->extend('wrongHelper', fn (): bool => true);
    expect()->extend(name: 'toHaveCount', extend: fn (int $count): bool => true);
    if ($condition) { expect()->extend('conditional', fn (): bool => true); }
    function registerExtension(): void { expect()->extend('nested', fn (): bool => true); }
    expect()->extend($dynamicName, fn (): bool => true);
    expect()->extend('dynamicClosure', $closure);
    file_put_contents(__DIR__.'/executed', 'bad');
    PHP);
file_put_contents($workspace.'/more.php', <<<'PHP'
    <?php
    expect(42)->extend('toHaveFlag', function (int $count): void {});
    expect()->extend('toBeReady', fn (): bool => true);
    PHP);
$declarations = $configure(['Pest.php', 'more.php'])->declarations();
$assert(
    $declarations !== null
    && array_column($declarations, 'name') === [
        'toHaveFlag',
        'toHaveCount',
        'toHaveFlag',
        'toBeReady',
    ],
    'literal Pest registrations retain source order and duplicate names',
);
$assert(
    $declarations[0]['closure'] instanceof Node\Expr\Closure
    && $declarations[0]['closure']->params[0]->type instanceof Node\Identifier
    && $declarations[0]['closure']->params[0]->type->toString() === 'bool'
    && $declarations[1]['closure'] instanceof Node\Expr\ArrowFunction
    && $declarations[1]['closure']->params[0]->type instanceof Node\Identifier
    && $declarations[1]['closure']->params[0]->type->toString() === 'int',
    'source callable syntax and parameter types are preserved',
);
$assert(
    $declarations[0]['source'] === str_replace('\\', '/', realpath($workspace.'/Pest.php'))
    && $declarations[0]['line'] === 3
    && $declarations[2]['source'] === str_replace('\\', '/', realpath($workspace.'/more.php'))
    && $declarations[2]['line'] === 2,
    'each declaration retains its exact selected source location',
);
$assert(! file_exists($workspace.'/executed'), 'selected PHP and extension bodies never execute');
$assert($configure([])->declarations() === [], 'explicit empty source selection is a valid positive catalog');
foreach ([null, 'Pest.php', ['../Pest.php'], ['missing.php'], ['Pest.php', 123]] as $invalid) {
    $assert($configure($invalid)->declarations() === null, 'invalid source selection remains unknown');
}
file_put_contents(
    $workspace.'/shadow.php',
    '<?php function expect(): mixed {} expect()->extend("invalid", fn () => true);',
);
$assert($configure(['shadow.php'])->declarations() === null, 'local helper shadowing does not claim Pest registration');
file_put_contents(
    $workspace.'/import.php',
    '<?php use function Other\\expect; expect()->extend("invalid", fn () => true);',
);
$assert(
    $configure(['import.php'])->declarations() === null,
    'imported helper shadowing does not claim Pest registration',
);
file_put_contents($workspace.'/namespace.php', '<?php namespace App; expect()->extend("invalid", fn () => true);');
$assert($configure(['namespace.php'])->declarations() === null, 'namespaced helper resolution remains unknown');
file_put_contents($workspace.'/bad.php', '<?php invalid source !!');
$assert($configure(['bad.php'])->declarations() === null, 'unparseable selected source remains unknown');
foreach (['Pest.php', 'more.php', 'shadow.php', 'import.php', 'namespace.php', 'bad.php', 'composer.json'] as $file) {
    unlink($workspace.'/'.$file);
}
rmdir($workspace);
