<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeClassComponentCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeComponentTagResolver;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeDirectiveCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeDirectiveReferenceChecker;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeSourceDiagnostics;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeSourceDocument;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago blade source '.bin2hex(random_bytes(8));
mkdir($workspace.'/views', 0777, true);
mkdir($workspace.'/components', 0777, true);
file_put_contents($workspace.'/views/exists.blade.php', '@php(throw new RuntimeException("Never execute"))');
file_put_contents($workspace.'/components/Card.php', <<<'PHP'
    <?php namespace Example\Widgets;
    class Card extends \Illuminate\View\Component {
        public function __construct(string $title, string $subTitle = '') { throw new \RuntimeException('Never instantiate'); }
        public function render() { throw new \RuntimeException('Never render'); }
    }
    PHP);
file_put_contents($workspace.'/directives.php', <<<'PHP'
    <?php
    \Illuminate\Support\Facades\Blade::directive('custom', function () { throw new \RuntimeException('Never execute'); });
    PHP);
$configuration = [
    'extra' => [
        'laramago' => [
            'reference-catalogs' => ['views' => ['paths' => ['views'], 'complete' => true]],
            'blade-directives' => ['files' => ['directives.php'], 'complete' => true],
            'blade-class-components' => ['roots' => [['namespace' => 'Example\\Widgets', 'path' => 'components']]],
            'blade-component-tags' => ['complete' => true, 'default-class-namespace' => 'Example\\Widgets'],
        ],
    ],
];
file_put_contents($workspace.'/composer.json', json_encode($configuration, JSON_THROW_ON_ERROR));
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$lines = [
    '{{-- @ghost @include("comment.missing") --}}',
    'Привет 😀 @include("missing.view")',
    '@include("exists") @includeIf("optional.missing") @includeWhen($ok, "conditional.missing")',
    'é @absent @custom',
    '<x-card',
    ' class="wide" />',
    '@include($dynamic)',
    '@component("GlobalComponent") @endcomponent',
    '@lang("messages.missing")',
];
$source = implode("\r\n", $lines);
$path = $workspace.'/views/original.blade.php';
file_put_contents($path, $source);
$document = new BladeSourceDocument($path, $source);
$consumer = new BladeSourceDiagnostics;
$catalogs = new ReferenceCatalogs($workspace);
$views = $consumer->references($document, $catalogs, true);
$assert(
    $views !== null && count($views->diagnostics) === 1 && ! $views->sourceScanComplete,
    'complete view catalog checks required references but preserves dynamic scan incompleteness',
);
$view = $views->diagnostics[0];
$assert(
    $view->path === $path
    && $view->code === 'blade-missing-view'
    && substr($source, $view->start, $view->end - $view->start) === 'missing.view'
    && $view->line === 2
    && $view->column === (strlen('Привет 😀 @include("') + 1)
    && $view->endLine === 2
    && $view->endColumn === ($view->column + strlen('missing.view')),
    'view finding addresses original UTF-8 bytes after comments and CRLF with exact file identity',
);
$assert(
    $consumer->references($document, $catalogs, false) === null,
    'unproven native reference semantics cannot produce missing-view diagnostics',
);
$custom = new BladeDirectiveCatalog($workspace);
$native = ['include', 'includeif', 'includewhen', 'component', 'endcomponent', 'lang'];
$checker = new BladeDirectiveReferenceChecker($native, $custom->directives(), $custom->isComplete());
$directives = $consumer->directives($document, $checker);
$assert(
    $directives !== null && count($directives->diagnostics) === 1,
    'directive catalog and scanner feed a mapped diagnostic while comments and registered names stay quiet',
);
$directive = $directives->diagnostics[0];
$assert(
    $directive->path === $path
    && $directive->line === 4
    && $directive->column === 4
    && substr($source, $directive->start, $directive->end - $directive->start) === '@absent',
    'directive finding maps the original at-sign and one-based byte column',
);
$assert(
    $consumer->directives($document, new BladeDirectiveReferenceChecker($native, [], false)) === null,
    'incomplete directive registry remains an unknown check',
);
$classes = (new BladeClassComponentCatalog($workspace))->components();
$resolver = new BladeComponentTagResolver($workspace, $classes, [], [], true);
$tagStart = strpos($source, '<x-card');
$tagEnd = strpos($source, ' />', $tagStart) + 3;
$props = $consumer->requiredClassProps($document, $tagStart, $tagEnd, $resolver, $classes, ['title'], true);
$assert(
    $props !== null && count($props->diagnostics) === 1,
    'selected original tag composes class resolution, opening attributes and explicit required contract',
);
$prop = $props->diagnostics[0];
$assert(
    $prop->path === $path
    && $prop->line === 5
    && $prop->column === 1
    && $prop->endLine === 6
    && $prop->endColumn === (strlen($lines[5]) + 1)
    && substr($source, $prop->start, $prop->end - $prop->start) === "<x-card\r\n class=\"wide\" />",
    'missing prop highlights the selected multiline opening tag with an exclusive end position',
);
$assert(
    $consumer->requiredClassProps($document, $tagStart, $tagEnd, $resolver, $classes, [], true)?->diagnostics === [],
    'constructor candidates alone never become missing props',
);
$assert(
    $consumer->requiredClassProps($document, $tagStart, $tagEnd, $resolver, $classes, ['stale'], true) === null
    && $consumer->requiredClassProps($document, $tagStart, $tagEnd, $resolver, $classes, ['title'], false) === null
    && $consumer->requiredClassProps($document, $tagStart, $tagEnd + 1, $resolver, $classes, ['title'], true) === null,
    'stale contracts, unproven active markup and nonisolated ranges remain unknown',
);
$other = new BladeSourceDocument($workspace.'/other/original.blade.php', '@include("missing.view")');
$otherViews = $consumer->references($other, $catalogs, true);
$assert(
    $otherViews?->diagnostics[0]->path === $other->path
    && $otherViews->diagnostics[0]->line === 1
    && $view->path !== $otherViews->diagnostics[0]->path,
    'same basename in another directory never reuses a previous document path or line mapping',
);
$configuration['extra']['laramago']['reference-catalogs']['views']['complete'] = false;
file_put_contents($workspace.'/composer.json', json_encode($configuration, JSON_THROW_ON_ERROR));
$assert(
    $consumer->references($document, new ReferenceCatalogs($workspace), true)?->diagnostics === [],
    'unasserted view catalog never turns an absent file into a diagnostic',
);
$escaped = new BladeSourceDocument($path, '@include("missing\\x2eview")');
$escapedResult = $consumer->references($escaped, $catalogs, true);
$escapedFinding = $escapedResult?->diagnostics[0] ?? null;
$assert(
    $escapedFinding !== null
    && substr($escaped->source, $escapedFinding->start, $escapedFinding->end - $escapedFinding->start)
        === 'missing\\x2eview'
    && str_contains($escapedFinding->message, 'missing.view'),
    'decoded names retain the raw escaped literal range rather than the decoded string length',
);
$assert(
    $consumer->references(new BladeSourceDocument($path, "\0"), $catalogs, true) === null,
    'unscannable buffers remain unknown rather than successful empty results',
);
$invalid = false;
try {
    $document->diagnostic('invalid', 'invalid', 0, strlen($source) + 1);
} catch (InvalidArgumentException) {
    $invalid = true;
}
$assert($invalid, 'out-of-buffer diagnostic spans are rejected');
$lf = new BladeSourceDocument($path, "a\nb\rc\r\n@absent");
$lfResult = $consumer->directives($lf, $checker);
$assert(
    $lfResult?->diagnostics[0]->line === 4 && $lfResult->diagnostics[0]->column === 1,
    'LF, lone CR and CRLF each count as one line boundary',
);
foreach (['<x-card {{ $attributes }} />', '<x-unknown />'] as $unknownTag) {
    $unknownDocument = new BladeSourceDocument($path, $unknownTag);
    $assert($consumer->requiredClassProps(
        $unknownDocument,
        0,
        strlen($unknownTag),
        $resolver,
        $classes,
        ['title'],
        true,
    ) === null, 'unknown target or dynamic attribute set cannot prove missing props: '.$unknownTag);
}
$suppliedDocument = new BladeSourceDocument($path, '<x-card title="yes" sub-title="ready" />');
$assert(
    $consumer->requiredClassProps(
        $suppliedDocument,
        0,
        strlen($suppliedDocument->source),
        $resolver,
        $classes,
        ['title', 'subTitle'],
        true,
    )?->diagnostics === [],
    'original parsed attributes satisfy explicit camel-case prop names',
);
