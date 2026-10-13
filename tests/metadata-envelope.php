<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\ConfigurationWatch;
use Ichinya\Laramago\Metadata\SelectedSourceWatch;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/laramago metadata envelope ' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0777, true);
mkdir($root . '/routes');
file_put_contents($root . '/composer.json', '{}');
file_put_contents($root . '/config/app.php', '<?php throw new RuntimeException("Never execute config");');
file_put_contents($root . '/routes/web.php', '<?php throw new RuntimeException("Never execute routes");');
$valid = [
    'schemaVersion' => 1,
    'projectRoot' => $root,
    'scope' => ['kind' => 'fixture', 'evidence' => 'source-only'],
    'errors' => [],
    'truncated' => false,
    'declarations' => [['name' => 'opaque-kind-specific-data']],
];
$cases = [
    'missing envelope' => [[], 'invalid-metadata-envelope'],
    'unsupported schema' => [[...$valid, 'schemaVersion' => 2], 'unsupported-metadata-version'],
    'string schema' => [[...$valid, 'schemaVersion' => '1'], 'invalid-metadata-envelope'],
    'float schema' => [[...$valid, 'schemaVersion' => 1.0], 'invalid-metadata-envelope'],
    'boolean schema' => [[...$valid, 'schemaVersion' => true], 'invalid-metadata-envelope'],
    'missing root' => [array_diff_key($valid, ['projectRoot' => true]), 'invalid-metadata-envelope'],
    'invalid root' => [[...$valid, 'projectRoot' => null], 'invalid-metadata-envelope'],
    'missing scope' => [array_diff_key($valid, ['scope' => true]), 'invalid-metadata-envelope'],
    'invalid scope' => [[...$valid, 'scope' => ['kind' => 'fixture']], 'invalid-metadata-envelope'],
    'missing errors' => [array_diff_key($valid, ['errors' => true]), 'invalid-metadata-envelope'],
    'string errors' => [[...$valid, 'errors' => 'PRIVATE_BAD_VALUE'], 'invalid-metadata-envelope'],
    'keyed errors' => [[...$valid, 'errors' => ['named' => []]], 'invalid-metadata-envelope'],
    'invalid error row' => [[...$valid, 'errors' => ['PRIVATE_BAD_VALUE']], 'invalid-metadata-envelope'],
    'missing error fields' => [[...$valid, 'errors' => [['code' => 'failure']]], 'invalid-metadata-envelope'],
    'missing truncated' => [array_diff_key($valid, ['truncated' => true]), 'invalid-metadata-envelope'],
    'string truncated' => [[...$valid, 'truncated' => 'true'], 'invalid-metadata-envelope'],
    'integer truncated' => [[...$valid, 'truncated' => 1], 'invalid-metadata-envelope'],
    'real error' => [
        [...$valid, 'errors' => [['code' => 'parse-failure', 'file' => null, 'message' => 'Source unavailable.']]],
        'parse-failure',
    ],
    'real truncation' => [[...$valid, 'truncated' => true], 'truncated-export'],
];
$checks = 0;
foreach ($cases as $name => [$metadata, $code]) {
    foreach (['configuration', 'selected'] as $kind) {
        $watch = $kind === 'configuration'
            ? new ConfigurationWatch($root)
            : new SelectedSourceWatch($root, ['routes/web.php'], ['php']);
        $first = $watch->refresh(static fn(): array => $valid);
        $path = $root . ($kind === 'configuration' ? '/config/app.php' : '/routes/web.php');
        file_put_contents($path, '<?php // ' . $name . ' ' . bin2hex(random_bytes(4)));
        $error = $watch->refresh(static fn(): array => $metadata);
        if (
            $first['status'] !== 'ready'
            || $error['status'] !== 'error'
            || $error['metadata'] !== null
            || ($error['errors'][0]['code'] ?? null) !== $code
            || str_contains(json_encode($error['errors'], JSON_THROW_ON_ERROR), 'PRIVATE_BAD_VALUE')
            || $error['revision'] <= $first['revision']
        ) {
            throw new RuntimeException('Envelope was not rejected: ' . $kind . ' / ' . $name);
        }
        if ($watch->refresh(static fn(): array => $metadata) !== null) {
            throw new RuntimeException('Stable failure was emitted twice.');
        }
        $recovered = $watch->refresh(static fn(): array => $valid);
        if (
            $recovered['status'] !== 'ready'
            || $recovered['metadata'] !== $valid
            || $recovered['revision'] <= $error['revision']
        ) {
            throw new RuntimeException('Same-source schema recovery failed.');
        }
        ++$checks;
    }
}
echo 'PASS: ' . $checks . ' envelope invalidation and same-source recovery controls' . PHP_EOL;
