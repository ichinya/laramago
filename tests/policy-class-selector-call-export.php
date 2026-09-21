<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\PolicyClassSelectorCallExport;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-policy-class-selector-'.bin2hex(random_bytes(8));
mkdir($root, 0777, true);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions, $root): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message.'; inspect '.$root);
    }
};

try {
    $source = <<<'PHP'
        <?php
        namespace App;

        use Domain\Post;
        use Illuminate\Support\Facades\Gate as Authorization;

        Authorization::allows('create', Post::class);
        Authorization::authorize(arguments: [Post::class], ability: 'viewAny');
        Authorization::check(['restore-any', 'custom_ability'], [Post::class, $context, 7]);
        Authorization::raw('publish-special', [Post::class, new Context()]);
        \Illuminate\Support\Facades\Gate::none(['alpha', 'beta'], Post::class);

        Authorization::denies($dynamicAbility, Post::class);
        Authorization::inspect('special-self', self::class);
        Authorization::allows('literal-string', 'Domain\\Post');
        Authorization::allows('spread', [Post::class, ...$extra]);
        Authorization::allows('explicit-key', [0 => Post::class]);
        Authorization::allows('extra-call-argument', Post::class, $context);
        Authorization::allows(...$callArguments);
        Authorization::authorize(['invalid-list'], Post::class);
        CustomGate::allows('custom-facade', Post::class);

        throw new \RuntimeException('Selected source must never execute.');
        PHP;
    file_put_contents($root.'/calls.php', $source);
    file_put_contents($root.'/broken.php', '<?php Gate::allows(');

    $export = (new PolicyClassSelectorCallExport)->export($root, ['calls.php', 'calls.php']);
    $assert($export['schemaVersion'] === 1, 'schema version is stable');
    $assert(
        $export['scope'] === [
            'kind' => 'policy-class-selector-call-candidates',
            'evidence' => 'source-only',
            'semantics' => 'remove-leading-literal-class-string-before-policy-invocation',
            'exhaustive' => false,
            'runtimePolicyResolved' => false,
        ],
        'scope states source-only non-runtime semantics',
    );
    $assert($export['errors'] === [], 'valid duplicate source has no errors');
    $assert($export['truncated'] === false && $export['truncationReasons'] === [], 'small export is complete');
    $assert($export['selection']['selectedFiles'] === 1, 'duplicate resolved source is read once');
    $assert($export['selection']['nativeFacadeSyntaxCalls'] === 13, 'native facade syntax calls are counted');
    $assert($export['selection']['classSelectorCandidates'] === 5, 'bounded class selector calls are counted');
    $assert($export['selection']['unsupportedCandidates'] === 8, 'unsupported native facade shapes stay visible');

    $calls = $export['calls'];
    $assert(count($calls) === 5, 'five calls retain a proven literal class selector transformation');
    $assert(
        array_column($calls, 'method') === ['allows', 'authorize', 'check', 'raw', 'none'],
        'methods retain source order',
    );
    $assert(array_map(
        static fn (array $call): array => array_column($call['abilities'], 'value'),
        $calls,
    ) === [
        ['create'],
        ['viewAny'],
        ['restore-any', 'custom_ability'],
        ['publish-special'],
        ['alpha', 'beta'],
    ], 'literal ability values are preserved without name classification');
    $assert(
        array_column(array_column($calls, 'selector'), 'class') === array_fill(0, 5, 'Domain\\Post'),
        'imports resolve selector classes',
    );
    $assert(
        array_column(array_column($calls, 'selector'), 'inputShape') === [
            'scalar-class-constant',
            'literal-list',
            'literal-list',
            'literal-list',
            'scalar-class-constant',
        ],
        'scalar and literal-list normalization shapes remain distinct',
    );
    $assert(
        array_column(array_column($calls, 'selector'), 'normalizedIndex') === [0, 0, 0, 0, 0],
        'selector is always normalized index zero',
    );
    $assert(
        ! in_array(false, array_column(array_column($calls, 'selector'), 'removedBeforePolicyInvocation'), true),
        'all exported selectors are marked removed',
    );
    $assert(
        array_column(array_column($calls, 'transformation'), 'normalization') === array_fill(0, 5, 'Arr::wrap'),
        'native argument normalization is explicit',
    );
    $assert(
        array_column(array_column($calls, 'transformation'), 'operation') === array_fill(
            0,
            5,
            'remove-first-normalized-argument',
        ),
        'native selector operation is explicit',
    );
    $assert(
        array_column(array_column($calls, 'transformation'), 'policyArgumentCount') === [0, 0, 2, 1, 0],
        'remaining policy argument counts exclude selectors',
    );
    $assert(
        array_column($calls[2]['policyArguments'], 'positionAfterUser') === [0, 1],
        'remaining arguments are positioned after the injected user',
    );
    $assert(
        array_column($calls[2]['policyArguments'], 'expressionKind') === ['Expr_Variable', 'Scalar_Int'],
        'remaining expression kinds are source metadata only',
    );
    $assert(
        $calls[3]['policyArguments'][0]['expressionKind'] === 'Expr_New',
        'object argument expression remains visible',
    );
    $assert(
        ! in_array(true, array_column($calls, 'runtimePolicyResolved'), true),
        'runtime policy resolution is never claimed',
    );
    $assert(
        array_unique(array_column($calls, 'confidence')) === ['literal-class-selector-transformation'],
        'confidence is transformation-specific',
    );

    $hash = hash('sha256', $source);
    foreach ($calls as $call) {
        $assert($call['file'] === $root.'/calls.php', 'call retains canonical source file');
        $assert($call['contentHash'] === $hash, 'call retains exact source hash');
        $assert(
            substr($source, $call['start'], $call['end'] - $call['start']) !== '',
            'call span selects original bytes',
        );
        $selector = $call['selector'];
        $assert(
            substr($source, $selector['start'], $selector['end'] - $selector['start']) === 'Post::class',
            'selector span selects the original class constant',
        );
        foreach ($call['abilities'] as $ability) {
            $assert(
                str_contains(
                    substr($source, $ability['start'], $ability['end'] - $ability['start']),
                    $ability['value'],
                ),
                'ability span selects its original literal',
            );
        }
    }

    $errors = (new PolicyClassSelectorCallExport)->export(
        $root,
        ['../calls.php', 'missing.php', 'calls.txt', 'broken.php'],
    );
    $assert(
        array_column($errors['errors'], 'code') === [
            'invalid-source',
            'unreadable-source',
            'invalid-source',
            'parse-failure',
        ],
        'invalid, missing, unsupported, and malformed sources remain explicit',
    );
    $assert($errors['calls'] === [], 'source errors cannot create selector candidates');
    $assert($errors['selection']['selectedFiles'] === 0, 'only parsed sources count as selected');

    try {
        (new PolicyClassSelectorCallExport)->export($root, ['named' => 'calls.php']);
        $assert(false, 'non-list source selection must throw');
    } catch (InvalidArgumentException $exception) {
        $assert(str_contains($exception->getMessage(), 'list'), 'non-list source error explains the contract');
    }

    echo 'PASS: '.$assertions." policy class selector call checks\n";
} finally {
    foreach (glob($root.'/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($root);
}
