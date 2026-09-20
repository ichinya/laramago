<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\PestTestFileMapping;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PestUsesCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use PhpParser\Node;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-pest-mapping-'.bin2hex(random_bytes(8));
mkdir($root.'/tests/Feature', 0777, true);
mkdir($root.'/tests/Unit', 0777, true);
$write = static function (string $file, string $body) use ($root): void {
    file_put_contents($root.'/'.$file, $body);
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};

$write('composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'pest-uses' => [
                'sources' => ['tests/Pest.php', 'tests/Feature/OneTest.php'],
                'test-files' => [
                    'tests/Feature/OneTest.php',
                    'tests/Feature/TwoTest.php',
                    'tests/Feature/ThreeTest.php',
                    'tests/Unit/UnmatchedTest.php',
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
$write('tests/Types.php', <<<'PHP'
    <?php
    namespace App\Tests;
    class BaseCase {}
    class OtherCase {}
    trait Shared {}
    trait LocalTrait {}
    PHP);
$write('tests/Pest.php', <<<'PHP'
    <?php
    use App\Tests\BaseCase;
    use App\Tests\OtherCase;
    use App\Tests\Shared;
    throw new RuntimeException('Pest source must not execute');
    pest()->extend(BaseCase::class)->use(Shared::class)->in('Feature');
    uses(OtherCase::class)->in('Feature/TwoTest.php');
    uses(\App\Tests\MissingKind::class)->in('Feature/ThreeTest.php');
    PHP);
$write('tests/Feature/OneTest.php', <<<'PHP'
    <?php
    throw new RuntimeException('Test file must not execute');
    uses(\App\Tests\LocalTrait::class);
    PHP);
foreach (['TwoTest.php', 'ThreeTest.php'] as $file) {
    $write('tests/Feature/'.$file, '<?php throw new RuntimeException("Test file must not execute");');
}
$write('tests/Unit/UnmatchedTest.php', '<?php throw new RuntimeException("Test file must not execute");');

$types = [];
$nodes = (new PhpSource($root))->read('tests/Types.php');
foreach ($nodes ?? [] as $node) {
    foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
        if ($statement instanceof Node\Stmt\Class_ || $statement instanceof Node\Stmt\Trait_) {
            $types[strtolower($statement->namespacedName->toString())] = $statement instanceof Node\Stmt\Class_
                ? 'class'
                : 'trait';
        }
    }
}
$classify = static fn (string $name): ?string => $types[strtolower($name)] ?? null;
$mapping = new PestTestFileMapping(new PestUsesCatalog($root), $classify);
$one = $mapping->file($root.'/tests/Feature/OneTest.php');
$assert(
    $one !== null
    && $one['declaredBaseClass'] === 'App\\Tests\\BaseCase'
    && $one['classCandidates'] === ['App\\Tests\\BaseCase']
    && $one['traits'] === ['App\\Tests\\Shared', 'App\\Tests\\LocalTrait']
    && $one['unresolvedNames'] === []
    && count($one['declarations']) === 2
    && $one['declarations'][0]['source'] === $root.'/tests/Pest.php'
    && $one['declarations'][1]['source'] === $root.'/tests/Feature/OneTest.php',
    'ordered global and file-local declarations map to positive class, trait and provenance metadata',
);
$two = $mapping->file($root.'/tests/Feature/TwoTest.php');
$assert(
    $two !== null
    && $two['declaredBaseClass'] === null
    && $two['classCandidates'] === ['App\\Tests\\BaseCase', 'App\\Tests\\OtherCase']
    && $two['traits'] === ['App\\Tests\\Shared'],
    'overlapping test-case classes remain explicit candidates without choosing a base',
);
$three = $mapping->file($root.'/tests/Feature/ThreeTest.php');
$assert(
    $three !== null
    && $three['declaredBaseClass'] === null
    && $three['classCandidates'] === ['App\\Tests\\BaseCase']
    && $three['unresolvedNames'] === ['App\\Tests\\MissingKind']
    && $three['traits'] === ['App\\Tests\\Shared'],
    'unclassified names leave the base unresolved while retaining positive trait evidence',
);
$assert(
    $mapping->file($root.'/tests/Unit/UnmatchedTest.php') === null,
    'an unmatched selected file is unknown rather than assigned an inferred default',
);
$write('composer.json', '{}');
$assert(
    (new PestTestFileMapping(new PestUsesCatalog($root), $classify))->files() === null,
    'invalid or absent declaration catalogs do not produce mappings',
);

foreach ([
    'composer.json',
    'tests/Pest.php',
    'tests/Types.php',
    'tests/Feature/OneTest.php',
    'tests/Feature/TwoTest.php',
    'tests/Feature/ThreeTest.php',
    'tests/Unit/UnmatchedTest.php',
] as $file) {
    unlink($root.'/'.$file);
}
rmdir($root.'/tests/Feature');
rmdir($root.'/tests/Unit');
rmdir($root.'/tests');
rmdir($root);
