<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\MailContentReferenceExport;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = sys_get_temp_dir().'/laramago-mail-content-'.bin2hex(random_bytes(8));
mkdir($fixture);
mkdir($fixture.'/vendor');
$source = <<<'PHP'
    <?php

    namespace App\Mail;

    use Illuminate\Mail\Mailables\Content as MailContent;

    file_put_contents(__DIR__.'/executed', 'application source ran');

    new MailContent(
        view: 'mail.primary',
        html: 'mail.alias',
        text: 'mail.text',
        markdown: 'mail.markdown',
        with: ['private' => secret()],
        htmlString: '<h1>raw</h1>',
    );
    new MailContent('position.view', 'position.html', 'position.text', 'position.markdown', [], '<p>raw</p>');
    new MailContent(view: '', html: '0', text: $dynamic, markdown: "mail.$dynamic");
    new MailContent(...$parts, text: 'after.unpack.text');
    new \ILLUMINATE\MAIL\MAILABLES\CONTENT(markdown: 'case.markdown');

    class Content {}
    new Content(view: 'local.ignored');

    (new MailContent(view: 'mutable.original'))->view('mutable.replacement');
    PHP;
file_put_contents($fixture.'/mail.php', $source);
file_put_contents($fixture.'/broken.php', '<?php new Content(');
file_put_contents($fixture.'/notes.txt', 'not PHP');
file_put_contents($fixture.'/large.php', '<?php /*'.str_repeat('x', 1024 * 1024).'*/');
file_put_contents(
    $fixture.'/vendor/autoload.php',
    '<?php throw new RuntimeException("Application autoload executed");',
);

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

try {
    $export = new MailContentReferenceExport;
    $result = $export->export($fixture, ['mail.php', './mail.php']);
    $check($result['schemaVersion'] === 1, 'Schema is versioned.');
    $check($result['projectRoot'] === str_replace('\\', '/', realpath($fixture)), 'Project root is canonical.');
    $check($result['scope']['kind'] === 'mail-content-reference-candidates', 'Scope identifies mail content.');
    $check($result['scope']['semantics'] === 'editor-reference-candidates', 'Candidates have editor semantics.');
    $check($result['scope']['exhaustive'] === false, 'Selected source is not an exhaustive runtime catalog.');
    $check($result['scope']['constructorSignatureValidated'] === false, 'Runtime constructor identity stays unknown.');
    $check($result['scope']['effectiveContentStateValidated'] === false, 'Mutable Content state stays unknown.');
    $check($result['scope']['terminalRenderValidated'] === false, 'Terminal rendering stays unknown.');
    $check(
        $result['scope']['runtimeLookupRequirementValidated'] === false,
        'Declarations do not validate a required lookup.',
    );
    $check($result['scope']['missingViewDiagnosticEligible'] === false, 'Missing-view diagnostics are ineligible.');
    $check($result['selection']['filesRequested'] === 2, 'Requested aliases remain visible.');
    $check($result['selection']['filesRead'] === 1, 'Resolved source aliases are read once.');
    $check($result['selection']['constructorsScanned'] === 6, 'Only resolved native Content constructors are scanned.');
    $check($result['selection']['literalReferences'] === 11, 'All truthy literal constructor references are exported.');
    $check($result['selection']['falseyLiteralsSkipped'] === 2, 'Falsey hydration values are skipped.');
    $check($result['selection']['dynamicArgumentsDeferred'] === 2, 'Dynamic view arguments are deferred.');
    $check($result['selection']['unpackedArgumentsDeferred'] === 1, 'Unpacked arguments are disclosed.');
    $check($result['selection']['rawHtmlArgumentsObserved'] === 2, 'Raw HTML arguments are counted but not exported.');
    $check($result['errors'] === [], 'Valid selected source has no errors.');
    $check($result['truncated'] === false, 'Valid selected source is complete within the declared bounds.');

    $references = $result['references'];
    $names = array_column($references, 'name');
    $check(
        $names === [
            'mail.primary',
            'mail.alias',
            'mail.text',
            'mail.markdown',
            'position.view',
            'position.html',
            'position.text',
            'position.markdown',
            'after.unpack.text',
            'case.markdown',
            'mutable.original',
        ],
        'Candidates preserve source order and exclude raw, falsey, dynamic, local, and setter values.',
    );
    $check(
        array_column(array_slice($references, 0, 4), 'referenceKind') === [
            'html-view',
            'html-view-alias',
            'text-view',
            'markdown-view',
        ],
        'Named Content fields retain distinct reference kinds.',
    );
    $check(
        array_column(array_slice($references, 4, 4), 'referenceKind') === [
            'html-view',
            'html-view-alias',
            'text-view',
            'markdown-view',
        ],
        'Positional Content fields use the native argument layout.',
    );
    $check(
        array_column(array_slice($references, 4, 4), 'constructorArgument') === [
            ['role' => 'view', 'position' => 0, 'name' => null],
            ['role' => 'html', 'position' => 1, 'name' => null],
            ['role' => 'text', 'position' => 2, 'name' => null],
            ['role' => 'markdown', 'position' => 3, 'name' => null],
        ],
        'Positional provenance is explicit.',
    );
    $check(
        $references[8]['constructorArgument'] === ['role' => 'text', 'position' => null, 'name' => 'text'],
        'Named arguments remain stable after an unpack.',
    );
    $check(! in_array('local.ignored', $names, true), 'A project-local Content class is not matched.');
    $check(! in_array('mutable.replacement', $names, true), 'Fluent mutable state is outside constructor metadata.');
    $check(! in_array('<h1>raw</h1>', $names, true), 'Named raw HTML is not a view reference.');
    $check(! in_array('<p>raw</p>', $names, true), 'Positional raw HTML is not a view reference.');
    $check(! file_exists($fixture.'/executed'), 'Selected application PHP is never executed.');
    $check(
        ! str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'application source ran'),
        'Unrelated source values are absent.',
    );

    $expectedPath = str_replace('\\', '/', realpath($fixture.'/mail.php'));
    foreach ($references as $reference) {
        $token = substr($source, $reference['start'], $reference['end'] - $reference['start']);
        $check($reference['file'] === $expectedPath, 'Reference path is canonical.');
        $check(str_contains($token, $reference['name']), 'Half-open span covers the original literal token.');
        $check($reference['contentHash'] === hash('sha256', $source), 'Reference preserves exact source hash.');
        $check($reference['runtimeLookup'] === 'unknown', 'Every runtime lookup stays unknown.');
        $check($reference['confidence'] === 'declaration-candidate', 'Every result is a declaration candidate.');
    }

    $partial = $export->export($fixture, ['missing.php', 'broken.php', 'notes.txt', '../outside.php']);
    $check($partial['references'] === [], 'Invalid and unreadable sources do not create candidates.');
    $check(
        array_column($partial['errors'], 'code') === [
            'unreadable-source',
            'parse-failure',
            'invalid-source',
            'invalid-source',
        ],
        'Source errors are stable and explicit.',
    );
    $check(
        ! str_contains(json_encode($partial, JSON_THROW_ON_ERROR), 'not PHP'),
        'Source errors do not leak contents.',
    );

    $limited = $export->export($fixture, ['large.php']);
    $check($limited['references'] === [], 'Oversized source is not parsed.');
    $check($limited['truncated'] === true, 'Oversized source marks the export truncated.');
    $check($limited['truncationReasons'] === ['file-byte-limit'], 'File bound names its truncation reason.');
    $check($limited['errors'][0]['code'] === 'source-limit', 'File bound returns a source-limit error.');

    try {
        $export->export($fixture, ['mail.php' => 'mail.php']);
        $check(false, 'Associative source input must fail.');
    } catch (InvalidArgumentException $exception) {
        $check(
            str_contains($exception->getMessage(), 'list'),
            'Associative source failure explains the list contract.',
        );
    }
    try {
        $export->export($fixture.'/absent', []);
        $check(false, 'Missing root must fail.');
    } catch (InvalidArgumentException $exception) {
        $check(
            str_contains($exception->getMessage(), 'existing directory'),
            'Missing root failure explains the root contract.',
        );
    }

    echo 'PASS: '.$checks." declarative mail Content reference checks\n";
} finally {
    foreach (['mail.php', 'broken.php', 'notes.txt', 'large.php', 'vendor/autoload.php'] as $file) {
        if (file_exists($fixture.'/'.$file)) {
            unlink($fixture.'/'.$file);
        }
    }
    if (file_exists($fixture.'/executed')) {
        unlink($fixture.'/executed');
    }
    rmdir($fixture.'/vendor');
    rmdir($fixture);
}
