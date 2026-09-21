<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\TranslationPluralBranchExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-plural-branches-'.bin2hex(random_bytes(8));
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
        'plain' => 'PRIVATE_PLAIN',
        'forms' => 'PRIVATE_ONE|PRIVATE_MANY',
        'conditions' => '{0}PRIVATE_ZERO|[1,2]PRIVATE_FEW|{3,*}PRIVATE_MANY',
        'mismatched' => '[0}PRIVATE_ZERO|PRIVATE_OTHER',
        'overlap' => '[0,2]PRIVATE_FIRST|[1,3]PRIVATE_SECOND',
        'empty' => 'PRIVATE_ONLY|',
        'whitespace' => 'PRIVATE_ONLY|   ',
        'unrecognized' => '{x}PRIVATE_FIRST|PRIVATE_SECOND',
        'single_condition' => '{1}PRIVATE_SINGLE',
        'escaped_pipe' => "PRIVATE_LEFT\x7CPRIVATE_RIGHT",
        'nested' => ['leaf' => 'PRIVATE_A|PRIVATE_B'],
        'dynamic' => build_private_message('PRIVATE_DYNAMIC|PRIVATE_DYNAMIC'),
        'before' => 'PRIVATE_BEFORE|PRIVATE_BEFORE',
        ...dynamic_translations(),
        'after' => 'PRIVATE_AFTER|PRIVATE_AFTER',
        'duplicate' => 'PRIVATE_OLD|PRIVATE_OLD',
        'duplicate' => 'PRIVATE_NEW|PRIVATE_NEW',
    ];
    PHP;
file_put_contents($fixture.'/lang/en/messages.php', $source);
file_put_contents($fixture.'/lang/en/broken.php', '<?php return ["PRIVATE_PARSE_VALUE" => ;');
file_put_contents($fixture.'/lang/en/en.json', '{"PRIVATE_JSON_VALUE":"a|b"}');
file_put_contents(
    $fixture.'/lang/en/many-branches.php',
    '<?php return ["many" => '.var_export(str_repeat('x|', 20001), true).'];',
);
$exporter = new TranslationPluralBranchExport;
try {
    $result = $exporter->export($fixture, ['lang/en/messages.php']);
    $candidates = $result['candidates'];
    $check(
        $result['schemaVersion'] === 1
        && $result['scope'] === [
            'kind' => 'translation-plural-branches',
            'evidence' => 'source-only',
            'semantics' => 'plural-branch-structure-candidates',
            'exhaustive' => false,
            'advisoryOnly' => true,
            'effectiveLocale' => 'unknown',
            'selectorIdentity' => 'unknown',
            'runtimeSelection' => 'unknown',
        ],
        'Versioned source-only advisory envelope preserves selector uncertainty',
    );
    $check($result['errors'] === [] && ! $result['truncated'], 'Valid literal translation source is supported');
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    foreach (['PRIVATE_ONE', 'PRIVATE_ZERO', 'PRIVATE_DYNAMIC', 'PRIVATE_NEW'] as $private) {
        $check(! str_contains($encoded, $private), 'Translated content stays private: '.$private);
    }
    $for = static fn (string|int $message): array => array_values(array_filter(
        $candidates,
        static fn (array $candidate): bool => $candidate['messageName'] === $message,
    ));
    $check($for('plain') === [], 'Unconditioned single messages are outside plural branch metadata');
    $forms = $for('forms')[0];
    $check(
        $forms['branchCount'] === 2
        && $forms['branchesComplete']
        && array_column($forms['branches'], 'conditionKind') === ['none', 'none'],
        'Pipe-separated native branch structure is retained without payloads',
    );
    $conditions = $for('conditions')[0]['branches'];
    $check(
        array_column($conditions, 'conditionKind') === ['single', 'range', 'range']
        && array_column($conditions, 'delimiterPair') === ['{}', '[]', '{}']
        && array_column($conditions, 'conditionCommaCount') === [0, 1, 1],
        'Native-recognized condition prefix shapes are retained',
    );
    $check(
        $for('mismatched')[0]['branches'][0]['delimiterPair'] === '[}' && $for('overlap')[0]['branchCount'] === 2,
        'Permissive mismatched and overlapping-looking forms remain candidates, not errors',
    );
    $check(
        $for('empty')[0]['branches'][1]['payloadState'] === 'empty'
        && $for('whitespace')[0]['branches'][1]['payloadState'] === 'whitespace-only',
        'Empty and whitespace branches are described without rejecting them',
    );
    $check(
        ! $for('unrecognized')[0]['branches'][0]['conditionPrefixRecognized']
        && $for('single_condition')[0]['branchCount'] === 1
        && $for('single_condition')[0]['branches'][0]['conditionPrefixRecognized'],
        'Unrecognized prefixes stay payload while native-recognized single conditions are included',
    );
    $check($for('escaped_pipe')[0]['branchCount'] === 2, 'Decoded PHP string values determine pipe branches');
    $leaf = $for('leaf')[0];
    $check($leaf['segments'] === ['nested', 'leaf'], 'Nested message key provenance is retained');
    $check($for('dynamic') === [], 'Dynamic message values are deferred without execution');
    $duplicates = $for('duplicate');
    $check(
        count($duplicates) === 2 && $duplicates[0]['sourceSelected'] && ! $duplicates[1]['sourceSelected'],
        'Duplicate message declarations preserve static selection state',
    );
    $check(
        ! $for('before')[0]['sourceSelected'] && $for('after')[0]['sourceSelected'],
        'Dynamic unpack uncertainty remains directional',
    );
    foreach ($candidates as $candidate) {
        $check($candidate['file'] === $fixture.'/lang/en/messages.php', 'Absolute source provenance');
        $check($candidate['contentHash'] === hash('sha256', $source), 'Exact source snapshot hash');
        $check(
            $candidate['messageLine'] === (substr_count(substr($source, 0, $candidate['messageStart']), "\n") + 1),
            'UTF-8-safe enclosing message span and line',
        );
        $check(
            $candidate['keyLine'] === (substr_count(substr($source, 0, $candidate['keyStart']), "\n") + 1),
            'Original key span and line',
        );
    }
    $escaped = $for('escaped_pipe')[0];
    $check(
        substr($source, $escaped['messageStart'], $escaped['messageEnd'] - $escaped['messageStart'])
        === '"PRIVATE_LEFT\x7CPRIVATE_RIGHT"',
        'Decoded branch metadata retains only the exact enclosing source token span',
    );
    $errors = $exporter->export($fixture, [
        'lang/en/broken.php',
        'lang/en/missing.php',
        'lang/en/en.json',
        '../outside.php',
    ]);
    $check(count($errors['errors']) === 4, 'Malformed, missing, non-PHP, and unsafe sources report generic errors');
    $check(
        ! str_contains(json_encode($errors, JSON_THROW_ON_ERROR), 'PRIVATE_PARSE_VALUE'),
        'Parse failures do not leak source content',
    );
    $check(
        $exporter->export($fixture, ['lang/en/messages.php', './lang/en/messages.php']) === $result,
        'Resolved file aliases are deduplicated',
    );
    $bounded = $exporter->export($fixture, ['lang/en/many-branches.php']);
    $check(
        $bounded['truncated']
        && $bounded['truncationReasons'] === ['branch-limit']
        && $bounded['candidates'][0]['branchCount'] === 20002
        && count($bounded['candidates'][0]['branches']) === 20000
        && ! $bounded['candidates'][0]['branchesComplete'],
        'Branch metadata is bounded with explicit partial-candidate state',
    );
    echo "translation plural branch export: {$checks} checks passed\n";
} finally {
    foreach (glob($fixture.'/lang/en/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($fixture.'/lang/en');
    rmdir($fixture.'/lang');
    rmdir($fixture);
}
