<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationKeyCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\MetadataConfidence;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago configuration index '.bin2hex(random_bytes(8));
mkdir($workspace.'/config', 0777, true);
file_put_contents($workspace.'/config/filesystems.php', <<<'PHP'
    <?php
    return [
        'default' => env('FILESYSTEM_DISK', 'local'),
        'disks' => [
            'local' => ['driver' => 'local', 'root' => env('LOCAL_ROOT')],
            env('EXTRA_DISK') => ['driver' => 'custom'],
            ...externalDisks(),
            'public' => diskConfiguration(),
        ],
    ];
    PHP);
file_put_contents($workspace.'/config/complete.php', <<<'PHP'
    <?php
    return [
        'default' => env('FILESYSTEM_DISK', 'local'),
        'disks' => [
            'local' => ['root' => env('LOCAL_ROOT')],
            'cloud' => cloudConfiguration(),
        ],
    ];
    PHP);
file_put_contents(
    $workspace.'/config/overridden.php',
    "<?php return ['disks' => ['stale' => []], ...runtimeConfiguration()];",
);
file_put_contents(
    $workspace.'/config/restored.php',
    "<?php return [...runtimeConfiguration(), 'disks' => ['known' => dynamicConfiguration()]];",
);
file_put_contents(
    $workspace.'/config/nonstring.php',
    "<?php return ['disks' => ['named' => [], '3' => [], '03' => [], '-0' => [], '9223372036854775808' => [], 3 => [], []]];",
);
file_put_contents($workspace.'/config/dynamic.php', '<?php return runtimeConfiguration();');
file_put_contents($workspace.'/config/empty.php', '<?php return [];');
file_put_contents($workspace.'/config/broken.php', '<?php return [;');

$source = new PhpSource($workspace);
$index = new ConfigurationIndex($source);

$assertCatalog = static function (
    ?ConfigurationKeyCatalog $catalog,
    array $keys,
    bool $sourceComplete,
    string $scenario,
): void {
    if ($catalog === null || $catalog->keys !== $keys || $catalog->sourceComplete !== $sourceComplete) {
        throw new RuntimeException($scenario.' did not retain the expected key evidence.');
    }
    echo 'PASS: '.$scenario."\n";
};

$assertCatalog(
    $index->stringKeys('filesystems.disks'),
    ['local', 'public'],
    false,
    'dynamic entries preserve known disk names without source completeness',
);
$assertCatalog(
    $index->stringKeys('complete.disks'),
    ['local', 'cloud'],
    true,
    'environment-dependent values do not hide literal disk names',
);
$assertCatalog(
    $index->stringKeys('complete'),
    ['default', 'disks'],
    true,
    'root configuration array keys are reusable',
);
$assertCatalog(
    $index->stringKeys('restored.disks'),
    ['known'],
    true,
    'a later literal ancestor key restores source certainty',
);
$assertCatalog(
    $index->stringKeys('nonstring.disks'),
    ['named', '03', '-0', '9223372036854775808'],
    false,
    'non-string entries do not overstate a string-key catalog',
);

foreach ([
    ['filesystems.disks', 'local',   MetadataConfidence::KnownPositive],
    ['filesystems.disks', 'missing', MetadataConfidence::Unknown],
    ['complete.disks',    'local',   MetadataConfidence::KnownPositive],
    ['complete.disks',    'missing', MetadataConfidence::CompleteAbsent],
    ['empty',             'missing', MetadataConfidence::CompleteAbsent],
    ['overridden.disks',  'stale',   MetadataConfidence::Unknown],
    ['dynamic.disks',     'missing', MetadataConfidence::Unknown],
    ['broken.disks',      'missing', MetadataConfidence::Unknown],
    ['missing.disks',     'missing', MetadataConfidence::Unknown],
] as [$catalogKey, $name, $expected]) {
    if ($index->stringKeyConfidence($catalogKey, $name) !== $expected) {
        throw new RuntimeException($catalogKey.'.'.$name.' has incorrect source confidence.');
    }
}
echo "PASS: source confidence distinguishes known, complete absence and unknown catalogs\n";

foreach (['overridden.disks', 'dynamic.disks', 'broken.disks', 'missing.disks', 'complete.unknown'] as $key) {
    if ($index->stringKeys($key) !== null) {
        throw new RuntimeException($key.' must remain unknown.');
    }
    echo 'PASS: '.$key." remains unknown\n";
}
if (count($source->warnings) !== 1 || ! str_ends_with(array_key_first($source->warnings), '/config/broken.php')) {
    throw new RuntimeException('Malformed configuration must retain its parse warning.');
}
echo "PASS: malformed configuration retains its parse warning\n";

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
        || ! str_starts_with($resolved, $resolvedRoot.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside temporary workspace.');
    }
    $entry->isDir() ? rmdir($resolved) : unlink($resolved);
}
rmdir($workspace);
