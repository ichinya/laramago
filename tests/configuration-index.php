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
file_put_contents($workspace.'/config/broken.php', '<?php return [;');
file_put_contents($workspace.'/config/empty.php', '<?php return [];');
$provenancePath = $workspace.'/config/provenance.php';
$provenanceSource = "<?php\r\nreturn [\r\n    'caption' => 'Café',\r\n    'mode' => 1,\r\n    'mode' => 2,\r\n    ...dynamicEntries(),\r\n    'after' => 3,\r\n    'mode' => 4,\r\n    'nested' => ['old' => 1],\r\n    'nested' => ['new' => 2],\r\n    'literal.dot' => 5,\r\n    'literal' => ['dot' => 6],\r\n];\r\n";
file_put_contents($provenancePath, $provenanceSource);

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

$declarations = $index->declarations('provenance');
if ($declarations === null || $declarations->sourceComplete || count($declarations->declarations) !== 6) {
    throw new RuntimeException('Literal declarations must remain available with an incomplete source array.');
}
$caption = $index->declaration('provenance.caption');
$mode = $index->declaration('provenance.mode');
$after = $index->declaration('provenance.after');
$nested = $index->declaration('provenance.nested');
if (
    $caption === null
    || $caption->sourceSelected
    || $mode === null
    || ! $mode->sourceSelected
    || $after === null
    || ! $after->sourceSelected
    || $nested === null
    || ! $nested->sourceSelected
) {
    throw new RuntimeException('Dynamic and later literal entries must retain their distinct source certainty.');
}
foreach ([$caption, $mode, $after, $nested] as $declaration) {
    if (
        $declaration->file !== $provenancePath
        || $declaration->contentHash !== hash('sha256', $provenanceSource)
        || $declaration->line !== (substr_count(substr($provenanceSource, 0, $declaration->start), "\n") + 1)
        || substr($provenanceSource, $declaration->start, $declaration->end - $declaration->start)
            !== "'".$declaration->name."'"
    ) {
        throw new RuntimeException(
            'Configuration declaration must identify its exact original key token and parsed snapshot.',
        );
    }
}
if ($mode->start !== strrpos($provenanceSource, "'mode'")) {
    throw new RuntimeException('Duplicate keys must point to the last source declaration.');
}
$literalDot = null;
foreach ($declarations->declarations as $candidate) {
    if ($candidate->name === 'literal.dot') {
        $literalDot = $candidate;
        break;
    }
}
if (
    $literalDot?->key !== null
    || $literalDot?->arrayKey !== 'provenance'
    || $index->declaration('provenance.literal.dot')?->start !== strrpos($provenanceSource, "'dot'")
    || ! in_array('literal.dot', $index->stringKeys('provenance')?->keys ?? [], true)
) {
    throw new RuntimeException('A literal dot key must not shadow a traversable nested declaration.');
}
if (
    $index->declaration('provenance.nested.old') !== null
    || $index->declaration('provenance.nested.new')?->sourceSelected !== true
    || $index->declaration('filesystems.disks.local')?->sourceSelected !== false
    || $index->declaration('filesystems.disks.public')?->sourceSelected !== true
    || $index->declarations('overridden.disks') !== null
    || $index->declaration('restored.disks.known')?->sourceSelected !== true
    || $index->declaration('nonstring.disks.3') !== null
    || $index->declaration('nonstring.disks.03')?->sourceSelected !== true
    || $index->declaration('provenance.absent') !== null
    || $index->declaration('provenance') !== null
    || $index->declarations('provenance.absent') !== null
) {
    throw new RuntimeException('Only selected literal array paths have declaration locations.');
}
echo "PASS: configuration declarations retain byte spans, source selection, and parsed hashes\n";

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
