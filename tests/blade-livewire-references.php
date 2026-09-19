<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeLivewireReferenceParser;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeSourceDiagnostics;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeSourceDocument;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LivewireComponentCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago livewire references '.bin2hex(random_bytes(8));
mkdir($workspace, 0777, true);
file_put_contents($workspace.'/registrations.php', <<<'PHP'
    <?php
    \Livewire\Livewire::component('registered', \App\Livewire\Registered::class);
    PHP);
$configuration = [
    'extra' => [
        'laramago' => [
            'livewire-components' => [
                'version' => 3,
                'files' => ['registrations.php'],
                'complete' => true,
            ],
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
$registrations = new LivewireComponentCatalog($workspace);
$assert($registrations->isComplete(), 'selected explicit registrations are independently complete');

$lines = [
    '{{-- @livewire("ghost") <livewire:ghost /> --}}',
    '<!-- <livewire:ghost /> -->',
    '@verbatim @livewire("ghost") <livewire:ghost /> @endverbatim',
    '@php $literal = "<livewire:ghost />"; @endphp',
    '<?php $literal = "?> <livewire:ghost />"; ?>',
    '@@livewire("ghost") @<livewire:ghost />',
    '{{ "<livewire:ghost />" }}',
    '<div title="<livewire:ghost />"></div>',
    '@livewire("registered") <livewire:conventional />',
    'é @livewire("missing\\x2eone") <livewire:missing.two class="wide" />',
    '@livewire($dynamic) <livewire:is :component="$dynamic" />',
    '<livewire:{$dynamic} />',
];
$source = implode("\r\n", $lines);
$path = $workspace.'/views/original.blade.php';
$document = new BladeSourceDocument($path, $source);
$scan = (new BladeLivewireReferenceParser)->parse($source);
$assert($scan !== null && ! $scan->complete, 'dynamic names make the source scan partial');
$names = array_map(static fn ($reference): string => $reference->name, $scan->references);
$assert(
    $names === ['registered', 'conventional', 'missing.one', 'missing.two'],
    'only active direct literal references are extracted from original Blade markup',
);
foreach ($scan->references as $reference) {
    $assert(
        substr($source, $reference->start, $reference->end - $reference->start) !== '',
        'reference '.$reference->name.' preserves a nonempty original byte span',
    );
}
$assert(
    substr($source, $scan->references[2]->start, $scan->references[2]->end - $scan->references[2]->start)
    === 'missing\\x2eone',
    'escaped PHP literal reports its raw original span',
);

$consumer = new BladeSourceDiagnostics;
$result = $consumer->livewire($document, $registrations, ['conventional'], true, true, true);
$assert(
    $result !== null && count($result->diagnostics) === 2 && ! $result->sourceScanComplete,
    'complete effective names diagnose two missing literals',
);
$assert(
    $result->diagnostics[0]->code === 'blade-missing-livewire-component'
    && $result->diagnostics[0]->path === $path
    && $result->diagnostics[0]->line === 10
    && substr($source, $result->diagnostics[0]->start, $result->diagnostics[0]->end - $result->diagnostics[0]->start)
        === 'missing\\x2eone'
    && $result->diagnostics[1]->line === 10
    && substr($source, $result->diagnostics[1]->start, $result->diagnostics[1]->end - $result->diagnostics[1]->start)
        === 'missing.two',
    'findings use the original file identity, line and raw byte ranges',
);
foreach ([
    [null, false, true, true],
    [[], false, true, true],
    [[], true, false, true],
] as [$names, $conventionalComplete, $resolverComplete, $native]) {
    $assert(
        $consumer->livewire(
            $document,
            $registrations,
            $names,
            $conventionalComplete,
            $resolverComplete,
            $native,
        )?->diagnostics === [],
        'an incomplete effective name universe cannot prove an absent component',
    );
}
$assert(
    $consumer->livewire($document, $registrations, [], true, true, false) === null,
    'unverified native version and compiler semantics defer the whole check',
);
$classSource = <<<'BLADE'
    @livewire('UnknownClass')
    @livewire('App\\Livewire\\Missing')
    <livewire:unknown />
    BLADE;
$classDocument = new BladeSourceDocument($path, $classSource);
$classScan = (new BladeLivewireReferenceParser)->parse($classSource);
$assert(
    $classScan !== null
    && array_map(static fn ($reference): string => $reference->name, $classScan->references) === [
        'UnknownClass',
        'App\\Livewire\\Missing',
        'unknown',
    ]
    && $consumer->livewire($classDocument, $registrations, [], true, true, true)?->diagnostics === [],
    'bare and qualified class-capable strings remain positive references without missing-name claims',
);
$commentedArguments = "@livewire('registered' // ) @livewire('ghost')\n) <livewire:missing.two />";
$commentScan = (new BladeLivewireReferenceParser)->parse($commentedArguments);
$assert(
    $commentScan !== null
    && array_map(static fn ($reference): string => $reference->name, $commentScan->references) === [
        'registered',
        'missing.two',
    ],
    'line-comment parentheses and fake nested directives cannot end a call early',
);
$hashComment = "@livewire('registered' # ) @livewire('ghost')\n)";
$hashScan = (new BladeLivewireReferenceParser)->parse($hashComment);
$assert(
    $hashScan !== null
    && array_map(static fn ($reference): string => $reference->name, $hashScan->references) === ['registered'],
    'hash-comment parentheses are ignored until the real closing parenthesis',
);
$configuration['extra']['laramago']['livewire-components']['complete'] = false;
file_put_contents($workspace.'/composer.json', json_encode($configuration, JSON_THROW_ON_ERROR));
$assert(
    $consumer->livewire($document, new LivewireComponentCatalog($workspace), [], true, true, true)?->diagnostics === [],
    'complete conventional mapping cannot compensate for incomplete registrations',
);
$parser = new BladeLivewireReferenceParser;
$assert(
    $parser->parse(str_repeat('x', 262_145)) === null
    && $parser->parse('@verbatim <livewire:ghost />') === null
    && $parser->parse('@livewire("ghost"') === null
    && $parser->parse('<livewire:registered {{ $attributes }} />') === null
    && $parser->parse("@livewire('registered' // )") === null,
    'oversized and structurally ambiguous sources defer',
);
$assert(
    $parser->parse('@php $x="@endphp <livewire:missing.one />"; @endphp <livewire:missing.two />') === null
    && $parser->parse('@php /* @endphp <livewire:missing.one /> */ @endphp <livewire:missing.two />') === null,
    'first textual raw-PHP terminator inside a string or comment makes the source ambiguous',
);
