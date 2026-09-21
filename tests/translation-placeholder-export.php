<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\TranslationPlaceholderExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-translation-placeholders-'.bin2hex(random_bytes(8));
mkdir($fixture.'/lang/en', 0777, true);
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
        'welcome' => 'Hello :name, :name and :NAME. PRIVATE_MESSAGE',
        'escaped' => "Escaped \x3Acode and literal :path. PRIVATE_ESCAPE",
        'prefixes' => ':name :names :name-id',
        'closure' => '<link>Read :title</link>',
        'group' => [
            'leaf' => 'Count :count',
            'dynamic' => build_private_message(':not_exported'),
            'interpolated' => "Hello {$private}:also_not_exported",
        ],
        'before' => 'Before :uncertain',
        ...dynamic_translations(),
        'after' => 'After :certain',
        'duplicate' => 'Old :old',
        'duplicate' => 'New :new',
        'numeric' => [
            '0' => 'Zero :first',
            0 => 'Zero :second',
        ],
    ];
    PHP;
file_put_contents($fixture.'/lang/en/messages.php', $source);
file_put_contents($fixture.'/lang/en/broken.php', '<?php return ["PRIVATE_PARSE_VALUE" => ;');
file_put_contents($fixture.'/lang/en/en.json', '{"PRIVATE_JSON_VALUE":":secret"}');
file_put_contents(
    $fixture.'/lang/en/long-name.php',
    '<?php return ["message" => ":'.str_repeat('a', 1025).' :short"];',
);
$longParent = str_repeat('p', 12000);
$amplifiedLeaves = [];
for ($i = 0; $i < 200; $i++) {
    $amplifiedLeaves[] = var_export('leaf-'.$i, true).' => '.var_export(':candidate'.$i, true);
}
file_put_contents(
    $fixture.'/lang/en/amplified.php',
    '<?php return ['.var_export($longParent, true).' => ['.implode(',', $amplifiedLeaves).']];',
);
$exporter = new TranslationPlaceholderExport;
try {
    $result = $exporter->export($fixture, ['lang/en/messages.php']);
    $candidates = $result['candidates'];
    $check(
        $result['schemaVersion'] === 1
        && $result['scope'] === [
            'kind' => 'translation-placeholders',
            'evidence' => 'source-only',
            'semantics' => 'completion-candidates',
            'exhaustive' => false,
        ],
        'Versioned non-exhaustive source-only completion envelope',
    );
    $check($result['errors'] === [] && ! $result['truncated'], 'Valid literal translation array is supported');
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    foreach (['PRIVATE_MESSAGE', 'PRIVATE_ESCAPE', 'not_exported', 'also_not_exported'] as $private) {
        $check(! str_contains($encoded, $private), 'Translated and dynamic values stay private: '.$private);
    }
    $for = static fn (string|int $message): array => array_values(array_filter(
        $candidates,
        static fn (array $candidate): bool => $candidate['messageName'] === $message,
    ));
    $welcome = $for('welcome');
    $check(
        array_column($welcome, 'name') === ['name', 'NAME'],
        'Literal candidates retain spelling and collapse an exact repeated completion',
    );
    $escaped = $for('escaped');
    $check(array_column($escaped, 'name') === ['code', 'path'], 'Decoded escaped literal values yield candidates');
    $check(
        $escaped[0]['spanKind'] === 'message-literal'
        && substr($source, $escaped[0]['messageStart'], $escaped[0]['messageEnd'] - $escaped[0]['messageStart'])
            === '"Escaped \x3Acode and literal :path. PRIVATE_ESCAPE"',
        'Escaped candidate provenance uses the whole original literal token',
    );
    $check(
        array_column($for('prefixes'), 'name') === ['name', 'names'],
        'Prefix and punctuation behavior produces candidates without claiming required keys',
    );
    $check(
        array_column($for('closure'), 'name') === ['title'],
        'Closure tags are not misreported as scalar colon candidates',
    );
    $leaf = $for('leaf')[0];
    $check($leaf['segments'] === ['group', 'leaf'] && $leaf['name'] === 'count', 'Nested group provenance is retained');
    $check($for('dynamic') === [] && $for('interpolated') === [], 'Dynamic message values are deferred');
    $duplicates = $for('duplicate');
    $check(
        count($duplicates) === 2
        && $duplicates[0]['name'] === 'new'
        && $duplicates[0]['sourceSelected']
        && $duplicates[1]['name'] === 'old'
        && ! $duplicates[1]['sourceSelected'],
        'Duplicate message declarations are retained with static selection state',
    );
    $check(
        ! $for('before')[0]['sourceSelected'] && $for('after')[0]['sourceSelected'],
        'Dynamic unpack uncertainty is directional',
    );
    $numeric = array_values(array_filter(
        $candidates,
        static fn (array $candidate): bool => (
            $candidate['segments'][0] === 'numeric'
            && $candidate['messagePhpKey'] === 0
        ),
    ));
    $check(
        count($numeric) === 2
        && $numeric[0]['name'] === 'second'
        && $numeric[0]['sourceSelected']
        && ! $numeric[1]['sourceSelected'],
        'PHP numeric-string key collisions preserve duplicate provenance',
    );
    foreach ($candidates as $candidate) {
        $check($candidate['file'] === $fixture.'/lang/en/messages.php', 'Absolute file provenance');
        $check($candidate['contentHash'] === hash('sha256', $source), 'Source snapshot hash');
        $check(
            $candidate['messageLine'] === (substr_count(substr($source, 0, $candidate['messageStart']), "\n") + 1),
            'UTF-8-safe message byte span and original line',
        );
        $check(
            $candidate['keyLine'] === (substr_count(substr($source, 0, $candidate['keyStart']), "\n") + 1),
            'UTF-8-safe key byte span and original line',
        );
    }
    $errors = $exporter->export($fixture, [
        'lang/en/broken.php',
        'lang/en/missing.php',
        'lang/en/en.json',
        '../outside.php',
    ]);
    $check(count($errors['errors']) === 4, 'Malformed, missing, non-PHP, and unsafe sources report generic errors');
    $check(
        ! str_contains(json_encode($errors, JSON_THROW_ON_ERROR), 'PRIVATE_PARSE_VALUE'),
        'Parse failure does not leak source content',
    );
    $check(
        $exporter->export($fixture, ['lang/en/messages.php', './lang/en/messages.php']) === $result,
        'Resolved file aliases are deduplicated',
    );
    $longName = $exporter->export($fixture, ['lang/en/long-name.php']);
    $check(
        $longName['truncated']
        && $longName['truncationReasons'] === ['candidate-name-limit']
        && array_column($longName['candidates'], 'name') === ['short'],
        'Oversized candidate names are omitted and disclosed',
    );
    $amplified = $exporter->export($fixture, ['lang/en/amplified.php']);
    $check(
        $amplified['truncated']
        && $amplified['truncationReasons'] === ['output-byte-limit']
        && count($amplified['candidates']) < 200,
        'Repeated long key provenance cannot amplify output without a disclosed bound',
    );
    echo "translation placeholder export: {$checks} checks passed\n";
} finally {
    foreach (glob($fixture.'/lang/en/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($fixture.'/lang/en');
    rmdir($fixture.'/lang');
    rmdir($fixture);
}
