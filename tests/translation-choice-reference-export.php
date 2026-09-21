<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\TranslationChoiceReferenceExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-translation-choice-'.bin2hex(random_bytes(8));
mkdir($fixture.'/app', 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$global = <<<'PHP'
    <?php
    // UTF-8 before spans: Жёлтый
    trans_choice('messages.apples', 2);
    \trans_choice('messages.pears', -1.5, locale: 'fr');
    trans_choice(key: 'messages.dynamic', number: $items, locale: $locale);
    trans_choice('messages.default', 0, [], null);
    trans_choice($dynamic, 3);
    trans_choice(...$arguments);
    file_put_contents(__DIR__.'/executed', 'yes');
    PHP;
$namespaced = <<<'PHP'
    <?php
    namespace App;
    use function trans_choice as pluralize;
    pluralize('messages.imported', ['one']);
    trans_choice('messages.possibly-shadowed', 2);
    PHP;
file_put_contents($fixture.'/app/global.php', $global);
file_put_contents($fixture.'/app/namespaced.php', $namespaced);
file_put_contents($fixture.'/app/broken.php', '<?php trans_choice("PRIVATE_PARSE";');
file_put_contents($fixture.'/app/large.php', '<?php /*'.str_repeat('x', 1024 * 1024).'*/');

$exporter = new TranslationChoiceReferenceExport;
try {
    $result = $exporter->export($fixture, ['app/global.php', 'app/namespaced.php']);
    $check($result['schemaVersion'] === 1, 'Schema is versioned');
    $check(
        $result['scope'] === [
            'kind' => 'translation-choice-reference-candidates',
            'evidence' => 'source-only',
            'semantics' => 'navigation-candidates',
            'exhaustive' => false,
            'actualLocaleProven' => false,
            'runtimeLookupProven' => false,
            'missingNameDiagnostic' => false,
        ],
        'Scope states the runtime and diagnostic boundaries',
    );
    $check($result['errors'] === [] && ! $result['truncated'], 'Selected valid sources are complete within bounds');
    $check(! file_exists($fixture.'/app/executed'), 'Selected project source is never executed');
    $check(
        $result['selection'] === [
            'selectedFiles' => 2,
            'lexicallyGlobalCalls' => 7,
            'literalReferences' => 5,
            'unsupportedCalls' => 2,
        ],
        'Literal, dynamic, unpacked, and namespaced calls are classified',
    );
    $references = [];
    foreach ($result['references'] as $reference) {
        $references[$reference['name']] = $reference;
        $check($reference['dispatchEvidence'] === 'lexically-global-function', 'Lexical dispatch evidence is explicit');
        $check(! $reference['nativeHelperProven'], 'Global helper ownership remains unproven');
        $check($reference['actualLocale']['state'] === 'unknown', 'Actual locale remains unknown');
        $check($reference['actualLocale']['maySelectFallback'], 'Fallback selection remains possible');
        $check(
            $reference['lookupChannels'] === ['selected-locale-json', 'selected-locale-php-with-fallback'],
            'Native JSON and PHP lookup channels are preserved',
        );
        $check(
            ! $reference['runtimeLookupProven'] && ! $reference['missingNameDiagnostic'],
            'Candidates cannot prove absence',
        );
        $source = str_ends_with($reference['file'], '/global.php') ? $global : $namespaced;
        $check($reference['contentHash'] === hash('sha256', $source), 'Exact source hash is retained');
        $check(
            substr($source, $reference['start'], $reference['end'] - $reference['start']) === "'{$reference['name']}'",
            'Original key byte span is retained',
        );
    }
    $check(
        array_keys($references) === [
            'messages.apples',
            'messages.pears',
            'messages.dynamic',
            'messages.default',
            'messages.imported',
        ],
        'Only literal lexically global calls produce references',
    );
    $check($references['messages.apples']['number']['value'] === 2, 'Literal integer count is retained');
    $check($references['messages.pears']['number']['value'] === -1.5, 'Negative literal float count is retained');
    $check(
        $references['messages.imported']['number']['kind'] === 'dynamic-or-countable',
        'Countable expressions stay unresolved',
    );
    $check(
        $references['messages.pears']['requestedLocale']['kind'] === 'literal-string'
        && $references['messages.pears']['requestedLocale']['value'] === 'fr'
        && ! $references['messages.pears']['requestedLocale']['mayUseDefault'],
        'Literal requested locale is retained without claiming selection',
    );
    $check(
        $references['messages.dynamic']['requestedLocale']['kind'] === 'dynamic'
        && $references['messages.dynamic']['requestedLocale']['mayUseDefault'],
        'Dynamic locale uncertainty is retained',
    );
    $check(
        $references['messages.default']['requestedLocale']['kind'] === 'null'
        && $references['messages.default']['requestedLocale']['mayUseDefault'],
        'Explicit null records default-locale behavior',
    );
    $check(
        $references['messages.apples']['requestedLocale']['kind'] === 'omitted',
        'Omitted locale is distinguished from explicit null',
    );
    $check(
        ! isset($references['messages.possibly-shadowed']),
        'Unqualified namespaced functions are not attributed to the global helper',
    );

    $errors = $exporter->export($fixture, [
        'app/broken.php',
        'app/missing.php',
        '../outside.php',
        'app/global.txt',
    ]);
    $check(count($errors['errors']) === 4, 'Malformed, missing, unsafe, and non-PHP sources report errors');
    $check(
        ! str_contains(json_encode($errors, JSON_THROW_ON_ERROR), 'PRIVATE_PARSE'),
        'Parse failures do not leak source content',
    );
    $check(
        $exporter->export($fixture, ['app/global.php', './app/global.php'])['references']
        === $exporter->export($fixture, ['app/global.php'])['references'],
        'Resolved file aliases are deduplicated',
    );
    $large = $exporter->export($fixture, ['app/large.php']);
    $check(
        $large['truncated'] && $large['truncationReasons'] === ['file-byte-limit'],
        'Oversized sources are bounded and disclosed',
    );
    file_put_contents($fixture.'/app/non-finite.php', '<?php trans_choice("messages.items", 1e999);');
    $nonFinite = $exporter->export($fixture, ['app/non-finite.php']);
    $check(
        $nonFinite['references'][0]['number']['kind'] === 'non-finite-number',
        'Overflowing number literals retain explicit uncertainty',
    );
    $check(
        $nonFinite['references'][0]['number']['value'] === null,
        'Non-finite numbers are not emitted as JSON numeric values',
    );
    $check(
        json_decode(json_encode($nonFinite, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR) === $nonFinite,
        'Non-finite source evidence remains JSON serializable',
    );
    echo "translation choice reference export: {$checks} checks passed\n";
} finally {
    foreach (glob($fixture.'/app/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($fixture.'/app');
    rmdir($fixture);
}
