<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\TranslationReplacementNameAdvisoryExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture =
    str_replace('\\', '/', sys_get_temp_dir()).'/laramago-translation-replacement-names-'.bin2hex(random_bytes(8));
mkdir($fixture.'/src', 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$source = <<<'PHP'
    <?php

    use Illuminate\Support\Facades\Lang;

    __('PRIVATE_TRANSLATED_TEXT :name', [
        'name' => private_value('PRIVATE_REPLACEMENT_VALUE'),
        42 => 'PRIVATE_NUMERIC_VALUE',
        '' => 'PRIVATE_EMPTY_VALUE',
        ':name' => 'PRIVATE_COLON_VALUE',
        'full name' => 'PRIVATE_SPACE_VALUE',
        'odd-key' => 'PRIVATE_OLD_VALUE',
        ...private_replacements(),
        'odd-key' => 'PRIVATE_NEW_VALUE',
        private_key() => 'PRIVATE_DYNAMIC_VALUE',
        'after!' => 'PRIVATE_AFTER_VALUE',
        'PRIVATE_IMPLICIT_VALUE',
    ]);
    trans('PRIVATE_TRANSLATION_KEY', ['normal_2' => private_value(), 'dot.name' => private_value()]);
    trans_choice('PRIVATE_CHOICE_KEY', 2, ['count' => 2, -1 => private_value()]);
    Lang::get('PRIVATE_LANG_KEY', replace: ['two words' => private_value()]);
    Lang::choice(key: 'PRIVATE_LANG_CHOICE_KEY', number: 2, replace: ['punct!' => private_value()]);
    trans('PRIVATE_DYNAMIC_DICTIONARY', private_replacements());
    PHP;
file_put_contents($fixture.'/src/calls.php', $source);
file_put_contents($fixture.'/src/broken.php', '<?php trans("PRIVATE_PARSE_TEXT", ["bad key" => ;');
file_put_contents($fixture.'/src/calls.json', '{"PRIVATE_JSON_TEXT":true}');

$exporter = new TranslationReplacementNameAdvisoryExport;
try {
    $result = $exporter->export($fixture, ['src/calls.php']);
    $check(
        $result['schemaVersion'] === 1
        && $result['scope'] === [
            'kind' => 'translation-replacement-name-advisories',
            'evidence' => 'selected-php-source',
            'semantics' => 'optional-literal-replacement-key-naming-advisory',
            'policy' => 'ascii-colon-word',
            'acceptedPattern' => '^[A-Za-z0-9_]+$',
            'enabledBy' => 'explicit-source-export',
            'exhaustive' => false,
            'runtimeValidityClaimed' => false,
            'callIdentityValidated' => false,
            'effectiveTranslationValidated' => false,
            'replacementValuesExported' => false,
            'translationTextExported' => false,
        ],
        'Envelope documents explicit advisory policy and runtime boundaries.',
    );
    $check($result['errors'] === [] && ! $result['truncated'], 'Valid selected source is fully inspected.');
    $check(
        $result['selection'] === [
            'candidateCalls' => 6,
            'literalReplacementKeys' => 14,
            'advisoryCandidates' => 10,
        ],
        'Selection counts calls and literal keys without exporting accepted names.',
    );

    $advisories = $result['advisories'];
    $names = array_column($advisories, 'name');
    foreach (['', ':name', 'full name', 'odd-key', 'after!', 'dot.name', -1, 'two words', 'punct!'] as $name) {
        $check(
            in_array($name, $names, true),
            'Unconventional literal key is an advisory candidate: '.var_export($name, true),
        );
    }
    foreach (['name', 42, 'normal_2', 'count'] as $name) {
        $check(! in_array($name, $names, true), 'ASCII colon-word policy accepts key: '.var_export($name, true));
    }
    $odd = array_values(array_filter(
        $advisories,
        static fn (array $advisory): bool => $advisory['name'] === 'odd-key',
    ));
    $check(
        count($odd) === 2 && ! $odd[0]['sourceSelected'] && ! $odd[1]['sourceSelected'],
        'Later dynamic and implicit keys conservatively make duplicate selection uncertain.',
    );
    foreach ($advisories as $advisory) {
        $check(
            $advisory['runtimeValidity'] === 'unknown' && $advisory['confidence'] === 'optional-style-review-candidate',
            'Every result remains review-only.',
        );
        $check(
            substr($source, $advisory['start'], $advisory['end'] - $advisory['start']) !== '',
            'Literal key keeps a source byte span.',
        );
        $check($advisory['contentHash'] === hash('sha256', $source), 'Advisory keeps the selected source hash.');
    }

    $uncertaintyCodes = array_column($result['uncertainties'], 'code');
    foreach ([
        'unpacked-replacement-entries',
        'dynamic-replacement-key',
        'implicit-numeric-replacement-key',
        'non-literal-replacement-array',
    ] as $code) {
        $check(in_array($code, $uncertaintyCodes, true), 'Uncertain valid replacement shape remains explicit: '.$code);
    }
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    foreach ([
        'PRIVATE_TRANSLATED_TEXT',
        'PRIVATE_TRANSLATION_KEY',
        'PRIVATE_REPLACEMENT_VALUE',
        'PRIVATE_NUMERIC_VALUE',
        'PRIVATE_EMPTY_VALUE',
        'PRIVATE_DYNAMIC_VALUE',
    ] as $private) {
        $check(! str_contains($encoded, $private), 'Translation text and replacement values stay private: '.$private);
    }

    $errors = $exporter->export($fixture, [
        'src/broken.php',
        'src/missing.php',
        'src/calls.json',
        '../outside.php',
    ]);
    $check(count($errors['errors']) === 4, 'Malformed, absent, non-PHP, and unsafe sources report generic errors.');
    $check(
        ! str_contains(json_encode($errors, JSON_THROW_ON_ERROR), 'PRIVATE_PARSE_TEXT'),
        'Parse errors do not leak source content.',
    );
    $check(
        $exporter->export($fixture, ['src/calls.php', './src/calls.php']) === $result,
        'Resolved file aliases are deduplicated.',
    );

    echo "translation replacement name advisory export: {$checks} checks passed\n";
} finally {
    foreach (glob($fixture.'/src/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($fixture.'/src');
    rmdir($fixture);
}
