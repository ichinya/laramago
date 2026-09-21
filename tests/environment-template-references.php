<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\EnvironmentTemplateReferences;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-env-references-'.bin2hex(random_bytes(8));
mkdir($workspace.'/nested', 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$source = <<<'ENV'
    BASE_DIR=/srv/private-root
    CACHE_DIR=${BASE_DIR}/cache
    PAIR="${BASE_DIR}:${CACHE_DIR}"
    MULTILINE="first private line
    ${BASE_DIR}
    ${CACHE_DIR}"
    SINGLE='${SINGLE_SECRET}'
    ESCAPED="\${ESCAPED_SECRET}"
    UNQUOTED_BACKSLASH=\${BASE_DIR}
    BARE=$BASE_DIR
    COMMENTED=value # ${COMMENT_SECRET}
    HASH=value#${HASH_SECRET}
    UNSUPPORTED="${NAME:-private-fallback}"
    NESTED="${${INDIRECT}}"
    BROKEN="\qPRIVATE_PARSE_VALUE ${BROKEN_SECRET}"
    ENV;
file_put_contents($workspace.'/.env.example', $source);
file_put_contents($workspace.'/nested/.env.template', "ROOT=/tmp\r\nCHILD=\"\${ROOT}/child\"\r\n");
file_put_contents($workspace.'/.env', 'PRIVATE_RUNTIME_VALUE=must-never-be-read');
file_put_contents($workspace.'/ordinary.env.example.txt', 'LEAKED=private');

$exporter = new EnvironmentTemplateReferences;
try {
    $result = $exporter->export($workspace, ['.env.example', 'nested/.env.template']);
    $check($result['schemaVersion'] === 1, 'Versioned envelope.');
    $check(
        $result['scope'] === ['kind' => 'environment-references', 'evidence' => 'source-only'],
        'Explicit source-only environment reference scope.',
    );
    $check($result['truncated'] === false, 'Small selected templates are not truncated.');
    $check(
        array_column($result['references'], 'name') === [
            'BASE_DIR',
            'BASE_DIR',
            'CACHE_DIR',
            'BASE_DIR',
            'CACHE_DIR',
            'BASE_DIR',
            'INDIRECT',
            'ROOT',
        ],
        'Only supported unquoted and double-quoted literal name references are exported.',
    );
    $check(
        ! in_array('BROKEN_SECRET', array_column($result['references'], 'name'), true),
        'References from an invalid entry are discarded.',
    );
    $check(
        array_column($result['uncertainties'], 'code') === [
            'unsupported-interpolation',
            'unsupported-interpolation',
        ],
        'Unsupported interpolation-like forms are reported once per source line.',
    );
    $check(
        array_column($result['errors'], 'code') === ['unsupported-entry'],
        'Invalid dotenv entries receive generic source-only errors.',
    );
    $check(
        ! in_array('BROKEN', array_column($result['declarations'], 'name'), true),
        'Invalid assignments are not positive declarations.',
    );
    $check(
        in_array('SINGLE', array_column($result['declarations'], 'name'), true)
        && in_array('ESCAPED', array_column($result['declarations'], 'name'), true),
        'Literal quoted assignments remain positive declarations without references.',
    );
    foreach ($result['references'] as $reference) {
        $contents = file_get_contents($reference['file']);
        $check(
            substr($contents, $reference['start'], $reference['end'] - $reference['start']) === $reference['name'],
            'Reference half-open byte span covers only the referenced name.',
        );
        $check($reference['contentHash'] === hash('sha256', $contents), 'Reference hash matches exact source bytes.');
        $check(
            $reference['line'] === (substr_count(substr($contents, 0, $reference['start']), "\n") + 1),
            'Reference line matches its source location.',
        );
    }
    foreach ($result['declarations'] as $declaration) {
        $contents = file_get_contents($declaration['file']);
        $check(
            substr($contents, $declaration['start'], $declaration['end'] - $declaration['start'])
            === $declaration['name'],
            'Declaration half-open byte span covers only the declared name.',
        );
    }

    $json = json_encode($result, JSON_THROW_ON_ERROR);
    foreach ([
        'private-root',
        'private line',
        'private-fallback',
        'PRIVATE_PARSE_VALUE',
        'must-never-be-read',
    ] as $secret) {
        $check(! str_contains($json, $secret), 'Export envelope contains no source values.');
    }

    $rejected = $exporter->export($workspace, [
        '.env',
        'ordinary.env.example.txt',
        '../.env.example',
        '/absolute/.env.example',
        'php://filter/.env.example',
        "bad\0/.env.example",
    ]);
    $check(
        array_column($rejected['errors'], 'code') === array_fill(0, 6, 'invalid-source'),
        'Actual env, wrong suffix, traversal, absolute, stream, and NUL paths are rejected before reads.',
    );
    $check(
        ! str_contains(json_encode($rejected, JSON_THROW_ON_ERROR), 'must-never-be-read'),
        'Rejected actual environment file cannot leak its value.',
    );

    $empty = $exporter->export($workspace, []);
    $check($empty['references'] === [] && $empty['declarations'] === [], 'Empty selection performs no discovery.');
    $duplicate = $exporter->export($workspace, ['.env.example', './.env.example']);
    $check(
        count($duplicate['references']) === count($exporter->export($workspace, ['.env.example'])['references']),
        'Canonical source aliases are read once.',
    );

    $large = str_repeat('x', (1024 * 1024) + 1);
    file_put_contents($workspace.'/nested/.env.template', $large);
    $bounded = $exporter->export($workspace, ['nested/.env.template']);
    $check(
        $bounded['truncated'] && $bounded['truncationReasons'] === ['file-byte-limit'] && $bounded['references'] === [],
        'Per-file reads are bounded.',
    );

    $associativeRejected = false;
    try {
        $exporter->export($workspace, ['source' => '.env.example']);
    } catch (InvalidArgumentException) {
        $associativeRejected = true;
    }
    $check($associativeRejected, 'Associative source selections are rejected.');

    $alias = $workspace.'/nested/.env.example';
    if (@symlink($workspace.'/.env', $alias)) {
        $symlink = $exporter->export($workspace, ['nested/.env.example']);
        $check(
            array_column($symlink['errors'], 'code') === ['unsupported-source'],
            'Resolved basename blocks an environment-file symlink alias.',
        );
        unlink($alias);
    }
} finally {
    foreach (['.env.example', '.env', 'ordinary.env.example.txt'] as $file) {
        if (is_file($workspace.'/'.$file)) {
            unlink($workspace.'/'.$file);
        }
    }
    foreach (glob($workspace.'/nested/*') ?: [] as $file) {
        unlink($file);
    }
    foreach (['.env.example', '.env.template'] as $file) {
        if (is_file($workspace.'/nested/'.$file) || is_link($workspace.'/nested/'.$file)) {
            unlink($workspace.'/nested/'.$file);
        }
    }
    rmdir($workspace.'/nested');
    rmdir($workspace);
}

echo "Environment template references: {$checks} checks passed.\n";
