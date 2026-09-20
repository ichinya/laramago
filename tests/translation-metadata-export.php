<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\TranslationMetadataExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-translations-'.bin2hex(random_bytes(8));
mkdir($fixture.'/lang', 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$source = <<<'PHP'
    <?php
    // UTF-8 before spans: Жёлтый
    return [
        'title' => 'PRIVATE_TRANSLATION',
        'title' => file_put_contents(__DIR__.'/executed', 'yes'),
        'literal.dot' => ['leaf' => env('NO_ENV')],
        '0' => 'zero string',
        0 => 'zero integer',
        '08' => 'leading zero',
        -1 => 'negative',
        'uncertain' => ['old' => 'private'],
        ...dynamic_translations(),
        'certain' => ['new' => 'private'],
    ];
    PHP;
file_put_contents($fixture.'/lang/messages.php', $source);
file_put_contents($fixture.'/lang/broken.php', '<?php return ["PRIVATE_PARSE_TOKEN" => ;');
file_put_contents($fixture.'/lang/en.json', '{"JSON_SECRET":"private"}');
file_put_contents(
    $fixture.'/lang/trap.php',
    '<?php file_put_contents(__DIR__."/executed", "yes"); return ["x" => "y"];',
);
file_put_contents($fixture.'/lang/conditional.php', '<?php if (true) { return ["x" => "y"]; }');
file_put_contents(
    $fixture.'/lang/numeric.php',
    '<?php return ["0" => "a", 0 => "b", "08" => "c", "nested" => ["x" => 1], "nested" => []];',
);
file_put_contents(
    $fixture.'/lang/partial.php',
    '<?php return ["before" => 1, $key => 2, "after" => 3, "nested" => ["inside" => 4]];',
);
file_put_contents($fixture.'/lang/large.php', '<?php /*'.str_repeat('x', 1048576).'*/ return [];');
file_put_contents($fixture.'/lang/deep.php', '<?php return '.str_repeat('["a" => ', 35).'1'.str_repeat(']', 35).';');
file_put_contents($fixture.'/lang/many.php', '<?php return ['.str_repeat('"key" => "secret",', 20001).'];');
$exporter = new TranslationMetadataExport;
try {
    $result = $exporter->export($fixture, ['lang/messages.php']);
    $rows = $result['declarations'];
    $check(
        $result['schemaVersion'] === 1 && $result['scope'] === ['kind' => 'translations', 'evidence' => 'source-only'],
        'Versioned source-only envelope',
    );
    $check($result['errors'] === [] && $result['truncated'] === false, 'Valid literal array is supported');
    $check(
        ! str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'PRIVATE_TRANSLATION'),
        'No translated values exported',
    );
    $check(! file_exists($fixture.'/lang/executed'), 'Translation expressions were not executed');
    $titles = array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === 'title'));
    $check(count($titles) === 2, 'Duplicate declarations retained');
    $check(
        $titles[0]['sourceSelected'] === false && $titles[1]['sourceSelected'] === false,
        'Later unpack invalidates earlier selections',
    );
    foreach ($rows as $row) {
        $check($row['file'] === $fixture.'/lang/messages.php', 'Absolute source provenance');
        $check($row['contentHash'] === hash('sha256', $source), 'Exact source snapshot hash');
        $check($row['line'] === (substr_count(substr($source, 0, $row['start']), "\n") + 1), 'Original line number');
    }
    $leaf = array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === 'leaf'))[0];
    $check($leaf['segments'] === ['literal.dot', 'leaf'], 'Dotted raw key is one segment');
    $check(
        substr($source, $leaf['start'], $leaf['end'] - $leaf['start']) === "'leaf'",
        'Exclusive byte span survives UTF-8 prefix',
    );
    $new = array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === 'new'))[0];
    $check($new['sourceSelected'] === true, 'Later explicit subtree remains selected');
    $numeric = $exporter->export($fixture, ['lang/numeric.php'])['declarations'];
    $zero = array_values(array_filter($numeric, static fn (array $row): bool => $row['phpKey'] === 0));
    $check(
        count($zero) === 2 && $zero[0]['sourceSelected'] && ! $zero[1]['sourceSelected'],
        'PHP numeric-string key collisions select last declaration',
    );
    $leading = array_values(array_filter($numeric, static fn (array $row): bool => $row['name'] === '08'))[0];
    $check($leading['phpKey'] === '08', 'Leading zeros stay string keys');
    $shadowed = array_values(array_filter($numeric, static fn (array $row): bool => $row['name'] === 'x'))[0];
    $check(! $shadowed['sourceSelected'], 'Overwritten parent makes descendants unselected');
    $partial = $exporter->export($fixture, ['lang/partial.php'])['declarations'];
    $before = array_values(array_filter($partial, static fn (array $row): bool => $row['name'] === 'before'))[0];
    $after = array_values(array_filter($partial, static fn (array $row): bool => $row['name'] === 'after'))[0];
    $check(
        ! $before['sourceSelected'] && $after['sourceSelected'],
        'Partial dynamic array preserves positive declarations conservatively',
    );
    $errors = $exporter->export($fixture, [
        'lang/broken.php',
        'lang/missing.php',
        'lang/en.json',
        '../outside.php',
        $fixture.'/lang/messages.php',
        'lang/trap.php',
        'lang/conditional.php',
    ]);
    $check(
        count($errors['errors']) === 7,
        'Explicit generic errors for malformed, missing, non-PHP, unsafe, and unsupported sources',
    );
    $check(
        ! str_contains(json_encode($errors, JSON_THROW_ON_ERROR), 'PRIVATE_PARSE_TOKEN'),
        'Parser details do not leak source',
    );
    $check(! file_exists($fixture.'/lang/executed'), 'Top-level source trap was not executed');
    $large = $exporter->export($fixture, ['lang/large.php']);
    $check($large['truncated'] && $large['truncationReasons'] === ['source-byte-limit'], 'Oversized source is bounded');
    $deep = $exporter->export($fixture, ['lang/deep.php']);
    $check($deep['truncated'] && $deep['truncationReasons'] === ['depth-limit'], 'Array recursion is bounded');
    $many = $exporter->export($fixture, array_fill(0, 257, 'lang/numeric.php'));
    $check($many['truncated'] && $many['truncationReasons'] === ['file-limit'], 'Input file requests are bounded');
    $manyDeclarations = $exporter->export($fixture, ['lang/many.php']);
    $check(
        $manyDeclarations['truncated'] && count($manyDeclarations['declarations']) === 20000,
        'Declaration output is bounded',
    );
    $aliases = $exporter->export($fixture, ['lang/numeric.php', './lang/numeric.php']);
    $check(count($aliases['declarations']) === count($numeric), 'Resolved file aliases are deduplicated');
    file_put_contents($fixture.'/lang/messages.php', '<?php return ["fresh" => "NEW_SECRET"];');
    $fresh = $exporter->export($fixture, ['lang/messages.php']);
    $check(
        count($fresh['declarations']) === 1 && $fresh['declarations'][0]['name'] === 'fresh',
        'Repeated export refreshes edited source',
    );
    $check($fresh['declarations'][0]['contentHash'] !== $rows[0]['contentHash'], 'Edited source hash refreshed');
    echo "translation metadata export: {$checks} checks passed\n";
} finally {
    foreach (glob($fixture.'/lang/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($fixture.'/lang');
    rmdir($fixture);
}
