<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\TranslationPlaceholderConsistencyExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-placeholder-consistency-'.bin2hex(random_bytes(8));
mkdir($fixture.'/lang/en', 0777, true);
mkdir($fixture.'/lang/fr', 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$english = <<<'PHP'
    <?php
    return [
        'before_unpack' => 'PRIVATE :uncertain',
        ...dynamic_messages(),
        'same' => 'PRIVATE EN :name',
        'renamed' => 'PRIVATE EN :name',
        'omitted' => 'PRIVATE EN :detail',
        'plural' => '{0} PRIVATE|{1} PRIVATE :name|[2,*] PRIVATE :count',
        'fallback_only' => 'PRIVATE :fallback',
        'dynamic' => build_private_message(':hidden'),
        'after_unpack' => 'PRIVATE :certain',
        'duplicate' => 'PRIVATE :old',
        'duplicate' => 'PRIVATE :final',
    ];
    PHP;
$french = <<<'PHP'
    <?php
    return [
        'same' => 'PRIVATE FR :name',
        'renamed' => 'PRIVATE FR :nom',
        'omitted' => 'PRIVATE FR',
        'plural' => '{0} PRIVATE|{1} PRIVATE|[2,*] PRIVATE :count',
        'dynamic' => other_private_message(':hidden'),
        'after_unpack' => 'PRIVATE :certain',
        'duplicate' => 'PRIVATE :final',
    ];
    PHP;
$jsonEnglish = <<<'JSON'
    {
        "PRIVATE JSON PHRASE": "PRIVATE JSON EN :name",
        "PRIVATE MATCH": "PRIVATE :same",
        "PRIVATE DUPLICATE": "PRIVATE :old",
        "PRIVATE DUPLICATE": "PRIVATE :final"
    }
    JSON;
$jsonFrench = <<<'JSON'
    {
        "PRIVATE JSON PHRASE": "PRIVATE JSON FR :nom",
        "PRIVATE MATCH": "PRIVATE :same",
        "PRIVATE DUPLICATE": "PRIVATE :final"
    }
    JSON;

file_put_contents($fixture.'/lang/en/messages.php', $english);
file_put_contents($fixture.'/lang/fr/messages.php', $french);
file_put_contents($fixture.'/lang/en.json', $jsonEnglish);
file_put_contents($fixture.'/lang/fr.json', $jsonFrench);
file_put_contents($fixture.'/lang/broken.php', '<?php return ["PRIVATE" => ;');
file_put_contents(
    $fixture.'/lang/trap.php',
    '<?php file_put_contents(__DIR__."/executed", "yes"); return ["x" => "PRIVATE :x"];',
);
file_put_contents($fixture.'/lang/readme.txt', 'PRIVATE :text');

$exporter = new TranslationPlaceholderConsistencyExport;
$sources = [
    ['locale' => 'en', 'catalog' => 'messages', 'file' => 'lang/en/messages.php'],
    ['locale' => 'fr', 'catalog' => 'messages', 'file' => 'lang/fr/messages.php'],
    ['locale' => 'en', 'catalog' => 'json', 'file' => 'lang/en.json'],
    ['locale' => 'fr', 'catalog' => 'json', 'file' => 'lang/fr.json'],
];

try {
    $result = $exporter->export($fixture, $sources);
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    $check($result['schemaVersion'] === 1, 'Schema version is explicit.');
    $check(
        $result['scope'] === [
            'kind' => 'translation-placeholder-consistency-candidates',
            'evidence' => 'explicit-selected-source-only',
            'semantics' => 'optional-translation-quality-advisory',
            'localeAssociationInferred' => false,
            'fallbackResolutionValidated' => false,
            'loaderPrecedenceValidated' => false,
            'pluralBranchAlignmentValidated' => false,
            'runtimeFailureClaimed' => false,
            'exhaustive' => false,
        ],
        'Scope denies inferred locale, fallback, precedence, plural, and runtime claims.',
    );
    $check(count($result['sources']) === 4, 'Every explicit source association is retained.');
    $check(
        array_column($result['sources'], 'locale') === ['en', 'fr', 'en', 'fr'],
        'Locale associations remain in caller order.',
    );
    $check(
        array_column($result['sources'], 'selectedForComparison') === [true, true, true, true],
        'Unique valid associations are selected for comparison.',
    );
    $check($result['errors'] === [] && ! $result['truncated'], 'Valid bounded sources export cleanly.');
    $check(! str_contains($encoded, 'PRIVATE'), 'No translated PHP or JSON message text is exported.');
    $check(! file_exists($fixture.'/lang/executed'), 'Translation sources are never executed.');

    $phpCandidates = array_values(array_filter(
        $result['candidates'],
        static fn (array $candidate): bool => $candidate['catalog'] === 'messages',
    ));
    $jsonCandidates = array_values(array_filter(
        $result['candidates'],
        static fn (array $candidate): bool => $candidate['catalog'] === 'json',
    ));
    $check(
        count($phpCandidates) === 3,
        'Renamed, omitted, and plural PHP placeholders are advisory candidates: '.json_encode($phpCandidates),
    );
    $check(count($jsonCandidates) === 1, 'JSON string values participate without exposing phrase keys.');
    $check(
        array_column($phpCandidates, 'status') === array_fill(0, 3, 'placeholder-set-difference'),
        'Candidates describe source differences only.',
    );
    foreach ($result['candidates'] as $candidate) {
        $check($candidate['advisory'] && ! $candidate['runtimeFailure'], 'Every difference is advisory.');
        $check(count($candidate['locations']) === 2, 'Compared locale locations are paired.');
        foreach ($candidate['locations'] as $location) {
            $check(
                $location['contentHash'] === hash(
                    'sha256',
                    $location['locale'] === 'en'
                        ? ($candidate['catalog'] === 'json' ? $jsonEnglish : $english)
                        : ($candidate['catalog'] === 'json' ? $jsonFrench : $french),
                ),
                'Locations retain the exact source hash.',
            );
            $check($location['messageStart'] < $location['messageEnd'], 'Message literal span is half-open.');
            $check($location['keyStart'] < $location['keyEnd'], 'Key literal span is half-open.');
        }
    }
    $plural = array_values(array_filter(
        $phpCandidates,
        static fn (array $candidate): bool => $candidate['message']['segments'] === ['plural'],
    ))[0];
    $check(
        $plural['uncertaintyReasons'] === ['plural-branch-alignment-unvalidated'],
        'Plural branch alignment remains explicitly unknown.',
    );
    $renamed = array_values(array_filter(
        $phpCandidates,
        static fn (array $candidate): bool => $candidate['message']['segments'] === ['renamed'],
    ))[0];
    $check(
        array_column($renamed['locations'], 'names') === [['name'], ['nom']],
        'Exact-case placeholder sets are retained per explicit locale.',
    );
    $check(
        $jsonCandidates[0]['message']['kind'] === 'json-key-hash'
        && strlen($jsonCandidates[0]['message']['sha256']) === 64,
        'JSON phrase identity is hashed rather than exported as message text.',
    );

    $uncertaintyKinds = array_column($result['uncertainties'], 'kind');
    $check(in_array('dynamic-message-value', $uncertaintyKinds, true), 'Dynamic message values remain uncertain.');
    $check(in_array('dynamic-array-entry', $uncertaintyKinds, true), 'Dynamic array entries remain uncertain.');
    $check(
        in_array('message-selection-uncertain', $uncertaintyKinds, true),
        'Entries before an unpack are not treated as selected.',
    );
    $check(
        in_array('shadowed-message-declaration', $uncertaintyKinds, true),
        'PHP and JSON literal collisions retain source uncertainty.',
    );
    $check(
        in_array('message-missing-from-selected-source', $uncertaintyKinds, true),
        'Missing locale messages are uncertainty rather than mismatches.',
    );
    $fallback = array_values(array_filter(
        $result['uncertainties'],
        static fn (array $uncertainty): bool => (
            ($uncertainty['kind'] ?? null) === 'message-missing-from-selected-source'
        ),
    ));
    $check(
        in_array('unknown', array_column($fallback, 'runtimeResolution'), true),
        'Missing message metadata does not resolve Laravel fallback.',
    );

    $collision = $exporter->export($fixture, [
        ['locale' => 'en', 'catalog' => 'messages', 'file' => 'lang/en/messages.php'],
        ['locale' => 'en', 'catalog' => 'messages', 'file' => 'lang/trap.php'],
        ['locale' => 'fr', 'catalog' => 'messages', 'file' => 'lang/fr/messages.php'],
    ]);
    $check($collision['candidates'] === [], 'Ambiguous locale/catalog source collisions are not compared.');
    $check(
        array_column($collision['sources'], 'selectedForComparison') === [false, false, true],
        'Every colliding association is excluded while unrelated locales remain usable.',
    );
    $check(
        count(array_filter(
            $collision['uncertainties'],
            static fn (array $uncertainty): bool => $uncertainty['kind'] === 'source-association-collision',
        )) === 2,
        'Association collisions retain both selected source locations.',
    );
    $check(! file_exists($fixture.'/lang/executed'), 'Collision sources are parsed without execution.');

    $invalid = $exporter->export($fixture, [
        ['locale' => '', 'catalog' => 'messages', 'file' => 'lang/en/messages.php'],
        ['locale' => 'en', 'catalog' => 'messages', 'file' => 'lang/broken.php'],
        ['locale' => 'en', 'catalog' => 'missing', 'file' => 'lang/missing.php'],
        ['locale' => 'en', 'catalog' => 'text', 'file' => 'lang/readme.txt'],
        ['locale' => 'en', 'catalog' => 'outside', 'file' => '../outside.php'],
    ]);
    $check(
        array_column($invalid['errors'], 'code') === [
            'invalid-source-association',
            'parse-failure',
            'unreadable-source',
            'unsupported-source-format',
            'invalid-source-path',
        ],
        'Invalid associations, parse failures, missing files, formats, and paths stay explicit.',
    );
    $check(! str_contains(json_encode($invalid, JSON_THROW_ON_ERROR), 'PRIVATE'), 'Errors do not leak source text.');

    $thrown = false;
    try {
        $exporter->export($fixture, ['en' => ['locale' => 'en', 'catalog' => 'x', 'file' => 'lang/en.json']]);
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    $check($thrown, 'Top-level associations must be a list.');

    echo "translation placeholder consistency export: {$checks} checks passed\n";
} finally {
    foreach ([
        'lang/en/messages.php',
        'lang/fr/messages.php',
        'lang/en.json',
        'lang/fr.json',
        'lang/broken.php',
        'lang/trap.php',
        'lang/readme.txt',
    ] as $file) {
        if (is_file($fixture.'/'.$file)) {
            unlink($fixture.'/'.$file);
        }
    }
    foreach (['lang/en', 'lang/fr', 'lang'] as $directory) {
        if (is_dir($fixture.'/'.$directory)) {
            rmdir($fixture.'/'.$directory);
        }
    }
    rmdir($fixture);
}
