<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\PaginationViewReferenceExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-pagination-view-references-'.bin2hex(random_bytes(8));
mkdir($fixture.'/app', 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$source = <<<'PHP'
    <?php

    namespace App;

    use Illuminate\Pagination\Paginator;
    use Illuminate\Pagination\Paginator as NativePaginator;
    use Custom\Paginator as CustomPaginator;

    file_put_contents(__DIR__.'/executed', 'forbidden');

    $page->links('pagination.custom');
    $page?->RENDER(view: "pagination.named", data: []);
    $unproven->links('policy.candidate');
    $page->links('');
    $page->render('0');
    $page->links(null);
    $page->render(false);
    $page->links(0);
    $page->render(0.0);
    $page->links([]);
    $page->render($dynamic);
    $page->links(...$arguments);
    $page->links(data: []);
    $page->links(View: 'wrong-case');
    $page->links();

    Paginator::defaultView('pagination.default');
    NativePaginator::DEFAULTSIMPLEVIEW('');
    \Illuminate\Pagination\Paginator::defaultView(view: '0');
    CustomPaginator::defaultView('custom.ignored');
    PHP;
file_put_contents($fixture.'/app/Pagination.php', $source);
file_put_contents($fixture.'/app/Broken.php', '<?php $page->links(');
file_put_contents($fixture.'/app/not-php.txt', '$page->links("private-name");');

$exporter = new PaginationViewReferenceExport;
try {
    $result = $exporter->export($fixture, ['app/Pagination.php']);
    $check($result['schemaVersion'] === 1, 'Versioned contract.');
    $check(
        $result['scope'] === [
            'kind' => 'pagination-view-references',
            'evidence' => 'selected-php-source',
            'semantics' => 'optional-declaration-policy-candidates',
            'exhaustive' => false,
            'paginatorReceiverValidated' => false,
            'viewFactoryResolverValidated' => false,
            'runtimeLookupValidated' => false,
        ],
        'Scope denies receiver, mutable factory resolver, and runtime lookup proof.',
    );
    $check(
        array_column($result['references'], 'name') === [
            'pagination.custom',
            'pagination.named',
            'policy.candidate',
            'pagination.default',
            '',
            '0',
        ],
        'Truthy terminal arguments and exact native mutable-default declarations are exported.',
    );
    $check(
        array_column($result['references'], 'syntax') === [
            'pagination-links-argument',
            'pagination-render-argument',
            'pagination-links-argument',
            'paginator-default-view-declaration',
            'paginator-default-simple-view-declaration',
            'paginator-default-view-declaration',
        ],
        'Terminal and default declaration syntax remains distinct.',
    );
    $check(
        array_column($result['uncertainties'], 'code') === [
            'falsey-view-uses-runtime-default',
            'falsey-view-uses-runtime-default',
            'falsey-view-uses-runtime-default',
            'falsey-view-uses-runtime-default',
            'falsey-view-uses-runtime-default',
            'falsey-view-uses-runtime-default',
            'falsey-view-uses-runtime-default',
            'non-literal-view-argument',
            'unresolved-view-argument',
            'unresolved-view-argument',
            'unresolved-view-argument',
            'implicit-runtime-default',
        ],
        'Falsey, dynamic, unpacked, and omitted arguments preserve native default uncertainty.',
    );
    $check($result['errors'] === [], 'Supported source has no read or parse errors.');
    $check($result['truncated'] === false, 'Unsupported call shapes do not imply truncation.');
    foreach ($result['references'] as $index => $reference) {
        $literal = substr($source, $reference['start'], $reference['end'] - $reference['start']);
        $check(
            in_array(
                $literal,
                [
                    "'pagination.custom'",
                    '"pagination.named"',
                    "'policy.candidate'",
                    "'pagination.default'",
                    "''",
                    "'0'",
                ],
                true,
            ),
            'Reference preserves the original half-open literal span.',
        );
        $check($reference['contentHash'] === hash('sha256', $source), 'Reference preserves exact source hash.');
        $check(
            $reference['confidence'] === 'declaration-policy-candidate'
            && $reference['viewFactoryResolver'] === 'unresolved'
            && $reference['runtimeLookup'] === 'unresolved',
            'Every reference denies factory identity and effective lookup proof.',
        );
        $expectedReceiver = $index < 3 ? 'unresolved' : 'exact-static-paginator';
        $check($reference['paginatorReceiver'] === $expectedReceiver, 'Receiver proof is explicit per syntax.');
    }
    foreach ($result['uncertainties'] as $uncertainty) {
        $check($uncertainty['contentHash'] === hash('sha256', $source), 'Uncertainty preserves exact source hash.');
        $check($uncertainty['end'] > $uncertainty['start'], 'Uncertainty preserves a nonempty call span.');
    }
    $check(! file_exists($fixture.'/app/executed'), 'Selected project PHP is never executed.');
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    $check(! str_contains($encoded, 'custom.ignored'), 'Custom static paginator declarations are excluded.');

    $bad = $exporter->export($fixture, [
        'app/Broken.php',
        'app/Missing.php',
        'app/not-php.txt',
        '../outside.php',
    ]);
    $check(
        array_column($bad['errors'], 'code') === [
            'parse-failure',
            'unreadable-source',
            'unsupported-source-format',
            'invalid-source',
        ],
        'Parse, missing, format, and containment errors stay explicit.',
    );
    $check(
        ! str_contains(json_encode($bad, JSON_THROW_ON_ERROR), 'private-name'),
        'Rejected contents are not exported.',
    );
    $duplicate = $exporter->export($fixture, ['app/Pagination.php', 'app/./Pagination.php']);
    $check(count($duplicate['references']) === 6, 'Canonical duplicate paths are read once.');
    $check($exporter->export($fixture, [])['references'] === [], 'Empty selection never discovers sources.');
    $limited = $exporter->export($fixture, array_fill(0, 257, 'app/Pagination.php'));
    $check($limited['truncationReasons'] === ['file-limit'], 'Explicit source count is bounded.');

    $invalidList = false;
    try {
        $exporter->export($fixture, ['source' => 'app/Pagination.php']);
    } catch (InvalidArgumentException) {
        $invalidList = true;
    }
    $check($invalidList, 'Associative source selections are rejected.');
} finally {
    foreach (['Pagination.php', 'Broken.php', 'not-php.txt', 'executed'] as $name) {
        if (is_file($fixture.'/app/'.$name)) {
            unlink($fixture.'/app/'.$name);
        }
    }
    rmdir($fixture.'/app');
    rmdir($fixture);
}

echo "Pagination view reference export: {$checks} checks passed.\n";
