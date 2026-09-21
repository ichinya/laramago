<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\JsonTranslationMetadataExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-json-translations-'.bin2hex(random_bytes(8));
$outside = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-json-translations-outside-'.bin2hex(random_bytes(8));
mkdir($fixture.'/lang', 0777, true);
mkdir($fixture.'/vendor', 0777, true);
mkdir($outside, 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$source = <<<'JSON'
    {
      "title": "PRIVATE_TRANSLATION",
      "nested": {"PRIVATE_NESTED_KEY": ["comma, brace }", {"deep": true}]},
      "title": "PRIVATE_REPLACEMENT",
      "Жёлтый": "raw UTF-8",
      "escaped\u002ekey": "escaped",
      "emoji \uD83D\uDE00": "surrogate pair",
      "0": "numeric",
      "08": "leading zero",
      "-1": "negative",
      "\u0030": "numeric duplicate"
    }
    JSON;
file_put_contents($fixture.'/lang/en.json', $source);
file_put_contents($fixture.'/lang/broken.json', '{"PRIVATE_BROKEN":"value",}');
file_put_contents($fixture.'/lang/bad-utf8.json', "{\"bad\":\"\xFF\"}");
file_put_contents($fixture.'/lang/bad-surrogate.json', '{"bad\uD800":"value"}');
file_put_contents($fixture.'/lang/list.json', '["not", "an", "object"]');
file_put_contents($fixture.'/lang/not-json.php', '<?php throw new RuntimeException("Application code executed");');
file_put_contents($fixture.'/vendor/autoload.php', '<?php file_put_contents(__DIR__."/executed", "yes");');
file_put_contents($outside.'/outside.json', '{"outside":"value"}');
$symlink = @symlink($outside.'/outside.json', $fixture.'/lang/outside-link.json');
file_put_contents($fixture.'/.env', '{"PRIVATE_ENV_KEY":"secret"}');
$environmentLink = @symlink($fixture.'/.env', $fixture.'/lang/environment-link.json');
$exporter = new JsonTranslationMetadataExport;

try {
    $result = $exporter->export($fixture, ['lang/en.json']);
    if ($environmentLink) {
        $environment = $exporter->export($fixture, ['lang/environment-link.json']);
        $check(
            $environment['declarations'] === [] && $environment['errors'][0]['code'] === 'unsupported-source-format',
            'Resolved non-JSON sources are rejected before reading.',
        );
    }
    $rows = $result['declarations'];
    $check(
        $result['schemaVersion'] === 1
        && $result['scope'] === ['kind' => 'translations-json', 'evidence' => 'source-only'],
        'Versioned JSON translation source scope.',
    );
    $check($result['errors'] === [] && ! $result['truncated'], 'Valid root JSON object is supported.');
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    $check(
        ! str_contains($encoded, 'PRIVATE_TRANSLATION') && ! str_contains($encoded, 'PRIVATE_REPLACEMENT'),
        'Translated message values are not exported.',
    );
    $check(! str_contains($encoded, 'PRIVATE_NESTED_KEY'), 'Nested value object keys are skipped.');
    $check(! file_exists($fixture.'/vendor/executed'), 'Application autoload code is not executed.');

    $titles = array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === 'title'));
    $check(
        count($titles) === 2 && ! $titles[0]['sourceSelected'] && $titles[1]['sourceSelected'],
        'Duplicate root declarations are retained with last decode selection.',
    );
    $escaped = array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === 'escaped.key'))[0];
    $emoji = array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === 'emoji 😀'))[0];
    $utf8 = array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === 'Жёлтый'))[0];
    $check(
        substr($source, $utf8['start'], $utf8['end'] - $utf8['start']) === '"Жёлтый"'
        && substr($source, $escaped['start'], $escaped['end'] - $escaped['start']) === '"escaped\u002ekey"'
        && substr($source, $emoji['start'], $emoji['end'] - $emoji['start']) === '"emoji \uD83D\uDE00"',
        'Raw UTF-8 and decoded escaped names retain original literal byte spans.',
    );
    foreach ($rows as $row) {
        $check($row['file'] === $fixture.'/lang/en.json', 'Absolute source provenance.');
        $check($row['contentHash'] === hash('sha256', $source), 'Exact source snapshot hash.');
        $check($row['line'] === (substr_count(substr($source, 0, $row['start']), "\n") + 1), 'Original line.');
    }
    $numeric = array_values(array_filter($rows, static fn (array $row): bool => $row['phpKey'] === 0));
    $leading = array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === '08'))[0];
    $negative = array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === '-1'))[0];
    $check(
        count($numeric) === 2 && ! $numeric[0]['sourceSelected'] && $numeric[1]['sourceSelected'],
        'Escaped numeric duplicate follows associative json_decode key selection.',
    );
    $check(
        $leading['phpKey'] === '08' && $negative['phpKey'] === -1,
        'Numeric JSON names expose PHP key normalization.',
    );

    $invalid = $exporter->export($fixture, [
        'lang/broken.json',
        'lang/bad-utf8.json',
        'lang/bad-surrogate.json',
        'lang/list.json',
        'lang/missing.json',
        'lang/not-json.php',
        '../outside.json',
        $fixture.'/lang/en.json',
        ...($symlink ? ['lang/outside-link.json'] : []),
    ]);
    $expectedErrors = $symlink ? 9 : 8;
    $check(
        count($invalid['errors']) === $expectedErrors,
        'Malformed, unsafe, missing, and unsupported inputs are reported.',
    );
    $check(
        count(array_filter($invalid['errors'], static fn (array $error): bool => $error['code'] === 'parse-failure'))
        === 3,
        'Malformed JSON, invalid UTF-8, and invalid surrogates produce generic parse failures.',
    );
    $check(
        ! str_contains(json_encode($invalid, JSON_THROW_ON_ERROR), 'PRIVATE_BROKEN'),
        'JSON parser errors do not leak source contents.',
    );
    if ($symlink) {
        $check(
            in_array('invalid-source-path', array_column($invalid['errors'], 'code'), true),
            'A selected symlink resolving outside the project is rejected.',
        );
    }

    file_put_contents($fixture.'/lang/large.json', '{"key":"'.str_repeat('x', 1048576).'"}');
    $large = $exporter->export($fixture, ['lang/large.json']);
    $check(
        $large['truncated'] && $large['truncationReasons'] === ['file-byte-limit'],
        'Per-file reads are bounded.',
    );
    $fileLimit = $exporter->export($fixture, array_fill(0, 257, 'lang/en.json'));
    $check(
        $fileLimit['truncated'] && $fileLimit['truncationReasons'] === ['file-limit'],
        'Input file requests are bounded.',
    );
    $totalFiles = [];
    for ($i = 0; $i < 9; $i++) {
        $file = 'lang/total-'.$i.'.json';
        file_put_contents($fixture.'/'.$file, '{"key":"'.str_repeat('x', 1047970).'"}');
        $totalFiles[] = $file;
    }
    $total = $exporter->export($fixture, $totalFiles);
    $check(
        $total['truncated'] && $total['truncationReasons'] === ['total-byte-limit'],
        'Aggregate source reads are bounded.',
    );

    $entries = [];
    for ($i = 0; $i < 20001; $i++) {
        $entries[] = json_encode('key-'.$i, JSON_THROW_ON_ERROR).':"value"';
    }
    file_put_contents($fixture.'/lang/many.json', '{'.implode(',', $entries).'}');
    $many = $exporter->export($fixture, ['lang/many.json']);
    $check(
        $many['truncated']
        && $many['truncationReasons'] === ['declaration-limit']
        && count($many['declarations']) === 20000,
        'Declaration output is bounded.',
    );

    $aliases = $exporter->export($fixture, ['lang/en.json', './lang/en.json']);
    $check(count($aliases['declarations']) === count($rows), 'Resolved source aliases are deduplicated.');
    file_put_contents($fixture.'/lang/en.json', '{"fresh":"NEW_PRIVATE_VALUE"}');
    $fresh = $exporter->export($fixture, ['lang/en.json']);
    $check(
        count($fresh['declarations']) === 1
        && $fresh['declarations'][0]['name'] === 'fresh'
        && $fresh['declarations'][0]['contentHash'] !== $rows[0]['contentHash'],
        'Repeated export refreshes source names and hashes.',
    );
    $check(
        ! str_contains(json_encode($fresh, JSON_THROW_ON_ERROR), 'NEW_PRIVATE_VALUE'),
        'Refreshed message values remain private.',
    );

    echo "JSON translation metadata export: {$checks} checks passed\n";
} finally {
    if ($environmentLink) {
        unlink($fixture.'/lang/environment-link.json');
    }
    unlink($fixture.'/.env');
    if ($symlink && is_link($fixture.'/lang/outside-link.json')) {
        unlink($fixture.'/lang/outside-link.json');
    }
    foreach (glob($fixture.'/lang/*') ?: [] as $file) {
        unlink($file);
    }
    foreach (glob($fixture.'/vendor/*') ?: [] as $file) {
        unlink($file);
    }
    unlink($outside.'/outside.json');
    rmdir($fixture.'/lang');
    rmdir($fixture.'/vendor');
    rmdir($fixture);
    rmdir($outside);
}
