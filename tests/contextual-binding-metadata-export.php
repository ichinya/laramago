<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\ContextualBindingMetadataExport;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-contextual-bindings-'.bin2hex(random_bytes(8));
mkdir($root.'/app', 0777, true);
$assertions = 0;
$check = static function (bool $condition, string $description) use (&$assertions): void {
    if (! $condition) {
        throw new RuntimeException($description);
    }
    $assertions++;
    echo 'PASS: '.$description."\n";
};
$write = static function (string $file, string $contents) use ($root): void {
    file_put_contents($root.'/'.$file, $contents);
};
$remove = static function (string $directory) use (&$remove): void {
    foreach (new FilesystemIterator($directory) as $entry) {
        if ($entry->isDir() && ! $entry->isLink()) {
            $remove($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
};

try {
    $supportedSource = <<<'PHP'
        <?php
        namespace App\Providers;

        use App\Contracts\Clock;
        use App\Jobs\ReportJob as Job;
        use App\Services\SystemClock;
        use App\Values\Zone;
        use function app;

        app()->when(Job::class)->needs(Clock::class)->give(SystemClock::class);

        \Illuminate\Container\Container::getInstance()
            ->when([Job::class, 'literal.context'])
            ->needs('$timezone')
            ->give([Zone::class]);

        app()->when(concrete: Job::class)
            ->needs(abstract: '$logger')
            ->giveTagged(tag: 'reports');

        if ($registerAtRuntime) {
            app()->when(Job::class)
                ->needs('$driver')
                ->giveConfig(key: 'services.mail.driver', default: 'smtp');
        }
        PHP;
    $write('app/contextual.php', $supportedSource);

    $export = (new ContextualBindingMetadataExport)->export($root, ['app/contextual.php']);
    $check($export['schemaVersion'] === 1, 'Schema version is explicit.');
    $check(
        $export['scope'] === [
            'kind' => 'contextual-binding-declaration-candidates',
            'evidence' => 'selected-source-only',
            'semantics' => 'literal-container-shaped-when-needs-give-chain',
            'exhaustive' => false,
            'receiverNativeValidated' => false,
            'runtimeRegistrationValidated' => false,
            'effectiveResolutionValidated' => false,
        ],
        'Scope does not claim native receiver, runtime registration, or effective resolution.',
    );
    $check($export['errors'] === [] && $export['uncertainties'] === [], 'Supported source exports cleanly.');
    $check(! $export['truncated'], 'Supported source remains within bounds.');
    $check(
        $export['selection'] === [
            'selectedFiles' => 1,
            'candidateChains' => 4,
            'literalDeclarations' => 4,
            'unsupportedCandidates' => 0,
        ],
        'Selection counts source candidates without asserting completeness.',
    );

    [$classBinding, $listBinding, $tagBinding, $configBinding] = $export['declarations'];
    $check(
        $classBinding['contexts'][0]['value'] === 'App\\Jobs\\ReportJob'
        && $classBinding['contexts'][0]['source'] === 'Job::class'
        && $classBinding['contexts'][0]['kind'] === 'class-string',
        'Imported context class preserves resolved identity and original token.',
    );
    $check(
        $classBinding['need']['value'] === 'App\\Contracts\\Clock' && $classBinding['need']['needKind'] === 'abstract',
        'Class need remains an abstract declaration candidate.',
    );
    $check(
        $classBinding['provision']['kind'] === 'literal-implementation'
        && $classBinding['provision']['tokens'][0]['value'] === 'App\\Services\\SystemClock',
        'Literal class provision is retained without resolving it.',
    );
    $check(
        $classBinding['receiver'] === 'global-app-helper-syntax'
        && ! $classBinding['receiverNativeValidated']
        && ! $classBinding['runtimeRegistrationValidated']
        && ! $classBinding['effectiveResolutionValidated'],
        'Recognized helper syntax remains explicitly unvalidated.',
    );
    $check(
        substr($supportedSource, $classBinding['start'], $classBinding['end'] - $classBinding['start'])
        === 'app()->when(Job::class)->needs(Clock::class)->give(SystemClock::class)',
        'Declaration span addresses the exact selected source bytes.',
    );
    $check(
        $classBinding['contentHash'] === hash('sha256', $supportedSource) && $classBinding['sourceSelected'] === true,
        'Declaration carries exact snapshot identity and selection provenance.',
    );

    $check(
        array_column($listBinding['contexts'], 'value') === ['App\\Jobs\\ReportJob', 'literal.context']
        && array_column($listBinding['contexts'], 'kind') === ['class-string', 'string'],
        'Literal when context lists retain distinct token kinds.',
    );
    $check(
        $listBinding['need']['value'] === '$timezone' && $listBinding['need']['needKind'] === 'primitive-parameter',
        'Dollar-prefixed needs are identified as primitive parameter keys.',
    );
    $check(
        $listBinding['provision']['kind'] === 'literal-implementation-list'
        && array_column($listBinding['provision']['tokens'], 'value') === ['App\\Values\\Zone'],
        'A one-item implementation list remains distinct from a scalar provision.',
    );
    $check(
        $listBinding['receiver'] === 'container-singleton-syntax',
        'Fully qualified Container::getInstance syntax is distinguished.',
    );
    $check(
        $tagBinding['provision']['kind'] === 'tagged' && $tagBinding['provision']['tag']['value'] === 'reports',
        'Literal giveTagged declarations retain tag provenance.',
    );
    $check(
        $configBinding['provision']['kind'] === 'config'
        && $configBinding['provision']['key']['value'] === 'services.mail.driver'
        && $configBinding['provision']['hasDefault'] === true,
        'Literal giveConfig declarations expose the key and default presence only.',
    );
    $check(
        $configBinding['line'] > $tagBinding['line'] && ! $configBinding['effectiveResolutionValidated'],
        'Conditional source syntax remains a candidate rather than an active binding claim.',
    );

    $unsupportedSource = <<<'PHP'
        <?php
        namespace App\Providers;
        use App\Jobs\ReportJob as Job;
        use App\Contracts\Clock;

        function app(): object { throw new \RuntimeException('must not execute'); }
        app()->when(Job::class)->needs(Clock::class)->give('local');
        \app()->when($context)->needs(Clock::class)->give('dynamic-context');
        \app()->when(Job::class)->needs($need)->give('dynamic-need');
        \app()->when(Job::class)->needs(Clock::class)->give(fn () => new \stdClass());
        \app()->when(Job::class)->needs(Clock::class)->giveTagged($tag);
        \app()->when(Job::class, Clock::class)->needs(Clock::class)->give('extra');
        \app()->when([])->needs(Clock::class)->give('empty');
        $custom->when(Job::class)->needs(Clock::class)->give('custom');
        PHP;
    $write('app/unsupported.php', $unsupportedSource);
    $unsupported = (new ContextualBindingMetadataExport)->export($root, ['app/unsupported.php']);
    $check($unsupported['declarations'] === [], 'Unsupported chains never become literal declarations.');
    $check(
        $unsupported['selection']['candidateChains'] === 8 && $unsupported['selection']['unsupportedCandidates'] === 8,
        'Every container-shaped unsupported chain remains visible.',
    );
    $check(
        array_column($unsupported['uncertainties'], 'code') === [
            'unproven-receiver',
            'unsupported-context',
            'unsupported-need',
            'unsupported-provision',
            'unsupported-provision',
            'unsupported-arguments',
            'unsupported-context',
            'unproven-receiver',
        ],
        'Receiver, context, need, callback, dynamic terminal, and argument uncertainty are distinct.',
    );
    $check(
        ! str_contains(json_encode($unsupported, JSON_THROW_ON_ERROR), 'must not execute'),
        'Uncertainty output never includes unrelated selected source contents.',
    );

    $write('app/broken.php', '<?php PRIVATE_PARSE_TOKEN {');
    $write('app/not-php.txt', '<?php');
    $invalid = (new ContextualBindingMetadataExport)->export($root, [
        'app/broken.php',
        'app/missing.php',
        '../outside.php',
        'app/not-php.txt',
    ]);
    $check(
        array_column($invalid['errors'], 'code') === [
            'parse-failure',
            'unreadable-source',
            'invalid-source',
            'invalid-source',
        ],
        'Parse, read, containment, and extension errors remain explicit.',
    );
    $check(
        ! str_contains(json_encode($invalid, JSON_THROW_ON_ERROR), 'PRIVATE_PARSE_TOKEN'),
        'Parse errors never disclose source fragments.',
    );

    $deduplicated = (new ContextualBindingMetadataExport)->export(
        $root,
        ['app/contextual.php', 'app/./contextual.php'],
    );
    $check(
        $deduplicated['selection']['selectedFiles'] === 1 && count($deduplicated['declarations']) === 4,
        'Canonical duplicate source paths are scanned once.',
    );

    $fileLimited = (new ContextualBindingMetadataExport)->export(
        $root,
        array_fill(0, 257, 'app/contextual.php'),
    );
    $check(
        $fileLimited['truncated']
        && $fileLimited['truncationReasons'] === ['file-limit']
        && $fileLimited['selection']['selectedFiles'] === 1,
        'File selection is bounded before canonical deduplication.',
    );

    $write('app/large.php', '<?php /*'.str_repeat('x', 1024 * 1024).'*/');
    $large = (new ContextualBindingMetadataExport)->export($root, ['app/large.php']);
    $check(
        $large['truncated']
        && $large['truncationReasons'] === ['file-byte-limit']
        && array_column($large['errors'], 'code') === ['source-limit'],
        'Per-file reads are bounded and do not publish partial declarations.',
    );

    try {
        (new ContextualBindingMetadataExport)->export($root, ['source' => 'app/contextual.php']);
        $check(false, 'Associative source input must fail.');
    } catch (InvalidArgumentException $error) {
        $check(str_contains($error->getMessage(), 'must be a list'), 'Associative source input fails clearly.');
    }
    try {
        (new ContextualBindingMetadataExport)->export($root.'/absent', []);
        $check(false, 'Missing project root must fail.');
    } catch (InvalidArgumentException $error) {
        $check(str_contains($error->getMessage(), 'existing directory'), 'Missing project root fails clearly.');
    }

    echo 'PASS: '.$assertions." contextual binding metadata assertions\n";
} finally {
    $remove($root);
}
