<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/fixtures/runtime-schema/support.php';

use Ichinya\Laramago\Runtime\RuntimeSchemaSnapshot;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/laramago runtime envelope ' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0777, true);
$root = RuntimeSchemaSnapshot::canonical($root);
$file = $root . '/config/database.php';
file_put_contents($file, '<?php return [];');
$valid = [
    'schemaVersion' => 1,
    'projectRoot' => $root,
    'scope' => ['kind' => 'runtime-schema', 'evidence' => 'evaluated-schema'],
    'plan' => ['models' => ['SyntheticRuntime\\Record']],
    'models' => [
        'SyntheticRuntime\\Record' => [
            'connection' => 'alpha',
            'table' => 'records',
            'databaseIdentity' => str_repeat('a', 64),
            'casts' => ['score' => 'integer'],
            'columns' => ['score' => ['typeName' => 'INTEGER', 'nullable' => true, 'strict' => false]],
        ],
    ],
    'sources' => [$file => hash_file('sha256', $file)],
    'sourceSets' => [['directory' => $root . '/config', 'pattern' => '*.php', 'files' => [$file]]],
    'errors' => [],
    'truncated' => false,
];
$invalid = [
    'unsupported version' => static function (array &$s): void {
        $s['schemaVersion'] = 2;
    },
    'string version' => static function (array &$s): void {
        $s['schemaVersion'] = '1';
    },
    'wrong root' => static function (array &$s): void {
        $s['projectRoot'] .= '/other';
    },
    'wrong scope' => static function (array &$s): void {
        $s['scope']['evidence'] = 'parsed-source';
    },
    'string truncated' => static function (array &$s): void {
        $s['truncated'] = 'false';
    },
    'missing truncated' => static function (array &$s): void {
        unset($s['truncated']);
    },
    'missing models' => static function (array &$s): void {
        unset($s['models']);
    },
    'string models' => static function (array &$s): void {
        $s['models'] = 'bad';
    },
    'unplanned model' => static function (array &$s): void {
        $s['plan']['models'] = [];
    },
    'incomplete success' => static function (array &$s): void {
        $s['models'] = [];
    },
    'duplicated plan' => static function (array &$s): void {
        $s['plan']['models'][] = $s['plan']['models'][0];
    },
    'invalid class' => static function (array &$s): void {
        $s['plan']['models'] = ['../escape'];
    },
    'missing connection' => static function (array &$s): void {
        unset($s['models']['SyntheticRuntime\\Record']['connection']);
    },
    'missing table' => static function (array &$s): void {
        unset($s['models']['SyntheticRuntime\\Record']['table']);
    },
    'missing database identity' => static function (array &$s): void {
        unset($s['models']['SyntheticRuntime\\Record']['databaseIdentity']);
    },
    'invalid cast' => static function (array &$s): void {
        $s['models']['SyntheticRuntime\\Record']['casts']['score'] = false;
    },
    'invalid type' => static function (array &$s): void {
        $s['models']['SyntheticRuntime\\Record']['columns']['score']['typeName'] = [];
    },
    'integer nullable' => static function (array &$s): void {
        $s['models']['SyntheticRuntime\\Record']['columns']['score']['nullable'] = 1;
    },
    'missing strict' => static function (array &$s): void {
        unset($s['models']['SyntheticRuntime\\Record']['columns']['score']['strict']);
    },
    'malformed digest' => static function (array &$s): void {
        $s['sources'][array_key_first($s['sources'])] = 'secret-canary';
    },
    'relative source' => static function (array &$s): void {
        $s['sources'] = ['relative.php' => str_repeat('a', 64)];
    },
    'traversal source' => static function (array &$s): void {
        $s['sources'] = ['/tmp/../escape' => str_repeat('a', 64)];
    },
    'unknown glob' => static function (array &$s): void {
        $s['sourceSets'][0]['pattern'] = '*';
    },
    'missing config source set' => static function (array &$s): void {
        $s['sourceSets'] = [];
    },
    'unhashed set member' => static function (array &$s): void {
        $s['sourceSets'][0]['files'][] = '/unhashed.php';
    },
    'string errors' => static function (array &$s): void {
        $s['errors'] = 'secret-canary';
    },
    'invalid error' => static function (array &$s): void {
        $s['errors'] = [['code' => 'failure', 'message' => 'Failure.']];
    },
    'relative error path' => static function (array &$s): void {
        $s['errors'] = [['code' => 'failure', 'file' => 'relative.php', 'message' => 'Failure.']];
    },
    'oversized plan' => static function (array &$s): void {
        $s['plan']['models'] = array_fill(0, 129, 'SyntheticRuntime\\Record');
    },
];
RuntimeSchemaSnapshot::validate($valid, $root);
runtimeAssert(RuntimeSchemaSnapshot::currentInputs($valid), 'Fresh input snapshot is stale.');
echo 'PASS: valid snapshot and current input' . PHP_EOL;
foreach ($invalid as $name => $mutate) {
    $snapshot = $valid;
    $mutate($snapshot);
    try {
        RuntimeSchemaSnapshot::validate($snapshot, $root);
        throw new LogicException('Accepted invalid envelope: ' . $name);
    } catch (RuntimeException $error) {
        runtimeAssert(!str_contains($error->getMessage(), 'secret-canary'), 'Invalid snapshot leaked metadata values.');
    }
    echo 'PASS: ' . $name . PHP_EOL;
}
foreach (['{', 'null', '[]', str_repeat(' ', RuntimeSchemaSnapshot::MAX_BYTES + 1)] as $index => $json) {
    try {
        RuntimeSchemaSnapshot::decode($json, $root);
        throw new LogicException('Accepted malformed JSON: ' . $index);
    } catch (RuntimeException) {
    }
    echo 'PASS: malformed/bounded JSON ' . $index . PHP_EOL;
}
file_put_contents($file, '<?php return ["changed" => true];');
runtimeAssert(!RuntimeSchemaSnapshot::currentInputs($valid), 'Changed configuration was accepted.');
file_put_contents($file, '<?php return [];');
file_put_contents($root . '/config/added.php', '<?php return [];');
runtimeAssert(!RuntimeSchemaSnapshot::currentInputs($valid), 'Added source-set member was accepted.');
unlink($root . '/config/added.php');
unlink($file);
runtimeAssert(!RuntimeSchemaSnapshot::currentInputs($valid), 'Deleted configuration was accepted.');
echo 'PASS: changed/added/deleted source revisions' . PHP_EOL;
$reordered = array_reverse($valid, true);
runtimeAssert(
    RuntimeSchemaSnapshot::fingerprint($valid) === RuntimeSchemaSnapshot::fingerprint($reordered),
    'Snapshot fingerprint depends on object key order.',
);
echo 'PASS: stable snapshot fingerprint' . PHP_EOL;

// Actual collector subprocess faults, independent of a Laravel installation.
// Application autoload runs before bootstrap and can really stall or corrupt IPC.
use Ichinya\Laramago\Analyzer\StaticAnalysis\RuntimeSchemaMetadata;

$autoload = $root . '/autoload.php';
file_put_contents($file, '<?php return [];');
$faults = [
    'timeout' => ['<?php usleep(10000000); exit(0);', 'runtime-metadata-collector-timeout'],
    'nonzero' => ['<?php exit(7);', 'runtime-metadata-collector-failed'],
    'malformed' => ['<?php fwrite(STDOUT, "{"); exit(0);', 'runtime-metadata-invalid-response'],
    'oversized' => [
        '<?php fwrite(STDOUT, str_repeat("x", 5000000)); exit(0);',
        'runtime-metadata-collector-output-limit',
    ],
];
foreach ($faults as $name => [$source, $code]) {
    file_put_contents($autoload, $source);
    $control = $valid;
    $control['sources'][$autoload] = hash_file('sha256', $autoload);
    $control['plan']['autoload'] = $autoload;
    $path = $root . '/fault.json';
    file_put_contents($path, json_encode($control, JSON_THROW_ON_ERROR));
    $client = new RuntimeSchemaMetadata($root, $path);
    $started = hrtime(true);
    $client->prepare();
    $elapsed = (hrtime(true) - $started) / 1e9;
    runtimeAssert(
        !$client->current() && isset($client->errors()[$code]) && $client->model('SyntheticRuntime\\Record') === null,
        'Collector fault left trusted metadata: ' . $name . '; ' . json_encode($client->errors()),
    );
    runtimeAssert($elapsed < 8.0, 'Collector deadline was not bounded on this OS: ' . $name . '; ' . $elapsed);
    if ($name === 'timeout') {
        runtimeAssert($elapsed >= 4.5, 'Timeout fixture did not exercise the real collector deadline.');
    }
    echo 'PASS: actual collector ' . $name . ' (' . round($elapsed, 2) . 's)' . PHP_EOL;
}
