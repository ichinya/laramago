<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\MailMessageViewReferenceExport;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-mail-message-views-'.bin2hex(random_bytes(8));
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

        use Illuminate\Notifications\Messages\MailMessage as NotificationMail;

        (new NotificationMail)->view('mail.invoice');
        (new NotificationMail)->markdown('mail::message');
        (new NotificationMail)->view(['html' => 'mail.html', 'text' => 'mail.text', 'raw' => 'PRIVATE_RAW']);
        (new NotificationMail)->view(['raw' => 'PRIVATE_CONTENT']);
        (new NotificationMail)->view(view: 'named.view', data: []);
        (new NotificationMail)->view($dynamic)->markdown('after.dynamic');
        (new NotificationMail)->markdown('first')->view('second');
        (new NotificationMail)->view(data: [], view: 'reordered.view');
        (new \Illuminate\Notifications\Messages\MailMessage)->view(['list.html', 'list.text']);

        $message->view('variable.receiver');
        (new CustomMailMessage)->markdown('custom.receiver');
        (new NotificationMail)->subject('Subject')->view('unknown.fluent.return');
        (new NotificationMail('constructor argument'))->view('invalid.construction');
        (new NotificationMail)->view('extra.argument', [], true);
        (new NotificationMail)->markdown($dynamic);

        throw new \RuntimeException('Selected source must never execute.');
        PHP;
    file_put_contents($root.'/notifications.php', $source);
    file_put_contents($root.'/broken.php', '<?php (new MailMessage)->view("PRIVATE_PARSE";');
    file_put_contents($root.'/not-php.txt', 'PRIVATE_TEXT');

    $exporter = new MailMessageViewReferenceExport;
    $result = $exporter->export($root, ['notifications.php', './notifications.php']);
    $assert($result['schemaVersion'] === 1, 'schema version is stable');
    $assert(
        $result['scope'] === [
            'kind' => 'mail-message-view-reference-candidates',
            'evidence' => 'source-only',
            'semantics' => 'navigation-candidates',
            'exhaustive' => false,
            'runtimeLookupProven' => false,
            'missingNameDiagnostic' => false,
        ],
        'scope states non-diagnostic source reference semantics',
    );
    $assert($result['errors'] === [], 'valid duplicate source has no errors');
    $assert(! $result['truncated'] && $result['truncationReasons'] === [], 'small export is not truncated');
    $assert(
        $result['selection'] === [
            'selectedFiles' => 1,
            'nativeSetterCalls' => 13,
            'literalReferences' => 11,
            'nonReferenceSetterCalls' => 1,
            'unsupportedSetterCalls' => 3,
        ],
        'selection counts only receiver-proven native setter syntax',
    );

    $references = $result['references'];
    $assert(
        array_column($references, 'name') === [
            'mail.invoice',
            'mail::message',
            'mail.html',
            'mail.text',
            'named.view',
            'after.dynamic',
            'first',
            'second',
            'reordered.view',
            'list.html',
            'list.text',
        ],
        'literal references retain source order and exclude raw content and unresolved receivers',
    );
    $assert(
        array_column($references, 'viewSlot') === [
            'single',
            'markdown',
            'html',
            'text',
            'single',
            'markdown',
            'markdown',
            'single',
            'single',
            'html',
            'text',
        ],
        'single, Markdown, associative, and positional mail view slots remain distinct',
    );
    $assert(
        array_column($references, 'referenceKind') === [
            'view',
            'markdown',
            'view',
            'view',
            'view',
            'markdown',
            'markdown',
            'view',
            'view',
            'view',
            'view',
        ],
        'view and Markdown references are classified independently',
    );
    $assert(
        array_column($references, 'receiverProof') === [
            'direct-native-construction',
            'direct-native-construction',
            'direct-native-construction',
            'direct-native-construction',
            'direct-native-construction',
            'audited-native-view-setter-chain',
            'direct-native-construction',
            'audited-native-view-setter-chain',
            'direct-native-construction',
            'direct-native-construction',
            'direct-native-construction',
        ],
        'only direct construction and audited native view-setter chaining prove the receiver',
    );

    $view = $references[0];
    $assert(
        $view['finderContexts'] === [[
            'renderer' => 'mailer-view-finder',
            'output' => null,
            'mailNamespaceVariant' => null,
        ]],
        'ordinary mail views retain the mailer finder context',
    );
    $markdown = $references[1];
    $assert(
        $markdown['finderContexts'] === [
            [
                'renderer' => 'markdown-view-finder',
                'output' => 'html',
                'mailNamespaceVariant' => 'html-components',
            ],
            [
                'renderer' => 'markdown-view-finder',
                'output' => 'text',
                'mailNamespaceVariant' => 'text-components',
            ],
        ],
        'Markdown references disclose separate HTML and text mail namespace variants',
    );
    $assert(
        ! in_array(true, array_column($references, 'runtimeLookupProven'), true)
        && ! in_array(true, array_column($references, 'selectedForRenderProven'), true)
        && ! in_array(true, array_column($references, 'required'), true),
        'mutable setters never become required runtime lookups',
    );

    $hash = hash('sha256', $source);
    foreach ($references as $reference) {
        $assert($reference['file'] === $root.'/notifications.php', 'canonical source file is retained');
        $assert($reference['contentHash'] === $hash, 'exact source hash is retained');
        $assert(
            str_contains(
                substr($source, $reference['start'], $reference['end'] - $reference['start']),
                $reference['name'],
            ),
            'reference byte span selects its original literal token',
        );
        $assert(
            $reference['line'] === (substr_count(substr($source, 0, $reference['start']), "\n") + 1),
            'reference line matches the original byte position',
        );
        $assert(
            $reference['callStart'] <= $reference['start'] && $reference['callEnd'] >= $reference['end'],
            'call span contains the literal reference span',
        );
    }

    $errors = $exporter->export($root, [
        '../notifications.php',
        'missing.php',
        'not-php.txt',
        'broken.php',
    ]);
    $assert(
        array_column($errors['errors'], 'code') === [
            'invalid-source',
            'unreadable-source',
            'invalid-source',
            'parse-failure',
        ],
        'unsafe, missing, unsupported, and malformed source selections remain explicit',
    );
    $assert($errors['references'] === [], 'source errors cannot create references');
    $assert(
        ! str_contains(json_encode($errors, JSON_THROW_ON_ERROR), 'PRIVATE_PARSE'),
        'parse errors do not leak source contents',
    );

    try {
        $exporter->export($root, ['named' => 'notifications.php']);
        $assert(false, 'non-list source selection must throw');
    } catch (InvalidArgumentException $exception) {
        $assert(str_contains($exception->getMessage(), 'list'), 'non-list source error explains the contract');
    }

    echo 'PASS: '.$assertions." MailMessage view reference checks\n";
} finally {
    foreach (glob($root.'/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($root);
}
