<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeReferenceParser;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$source = <<<'BLADE'
    Привет
    {{-- @include('comment.blade') --}}
    <!-- @lang('html.comment') -->
    @verbatim @include('verbatim.blade') @endverbatim
    @@include('escaped.blade')
    @{{ __('escaped.echo') }}
    @extends('layouts.app')
    @include('partials.card', ['title' => __('cards.title')])
    @includeIf('optional.missing')
    @includeWhen($show, 'conditional.card')
    @includeUnless($hide, 'conditional.footer')
    @includeIsolated('isolated.part')
    @component('widgets.panel') @endcomponent
    @component(\App\View\Components\Panel::class) @endcomponent
    @each('rows.item', $rows, 'row', 'rows.empty')
    @lang('messages.title') @choice('messages.items', $count)
    {{ __('echo.title') }} {!! trans('raw.title') !!}
    @php $label = __('php.block'); @endphp
    <?php echo trans_choice('php.tag', 2); ?>
    BLADE;

$scan = (new BladeReferenceParser)->parse($source);
$assert($scan !== null && $scan->complete, 'supported literal Blade and PHP references are complete');
$actual = array_map(
    static fn ($reference): array => [$reference->kind, $reference->name, $reference->origin, $reference->requirement],
    $scan->references,
);
$assert(
    $actual === [
        ['view',        'layouts.app',        '@extends',         'required'],
        ['view',        'partials.card',      '@include',         'required'],
        ['translation', 'cards.title',        'directive:__',     'conditional'],
        ['view',        'optional.missing',   '@includeIf',       'optional'],
        ['view',        'conditional.card',   '@includeWhen',     'conditional'],
        ['view',        'conditional.footer', '@includeUnless',   'conditional'],
        ['view',        'isolated.part',      '@includeIsolated', 'required'],
        ['view',        'widgets.panel',      '@component',       'required'],
        ['view',        'rows.item',          '@each',            'conditional'],
        ['view',        'rows.empty',         '@each:empty',      'conditional'],
        ['translation', 'messages.title',     '@lang',            'conditional'],
        ['translation', 'messages.items',     '@choice',          'conditional'],
        ['translation', 'echo.title',         'echo:__',          'conditional'],
        ['translation', 'raw.title',          'echo:trans',       'conditional'],
        ['translation', 'php.block',          'php:__',           'conditional'],
        ['translation', 'php.tag',            'php:trans_choice', 'conditional'],
    ],
    'native directives retain literal targets and lookup conditions',
);
foreach ($scan->references as $reference) {
    $assert(
        substr($source, $reference->start, $reference->end - $reference->start) === $reference->name,
        'byte span points into original UTF-8 Blade source: '.$reference->name,
    );
}

$partial = (new BladeReferenceParser)->parse(
    "@include(\$dynamic) @include('known') @includeFirst(['first', 'second'])",
);
$assert(
    $partial !== null
    && ! $partial->complete
    && count($partial->references) === 1
    && $partial->references[0]->name === 'known',
    'dynamic and fallback expressions never become negative evidence',
);
$malformed = (new BladeReferenceParser)->parse(
    "@include('fake'; // )\n@include('real') {{ __('fake'); // }}\n{{ __('real') }}",
);
$assert(
    $malformed !== null
    && ! $malformed->complete
    && array_map(static fn ($reference): string => $reference->name, $malformed->references) === ['real', 'real'],
    'suffixes and comments cannot escape synthetic PHP wrappers',
);
$escapedSource = <<<'BLADE'
    @lang('it\'s ready')
    BLADE;
$escaped = (new BladeReferenceParser)->parse($escapedSource);
$assert(
    $escaped !== null
    && $escaped->complete
    && count($escaped->references) === 1
    && $escaped->references[0]->name === "it's ready"
    && substr(
        $escapedSource,
        $escaped->references[0]->start,
        $escaped->references[0]->end - $escaped->references[0]->start,
    ) === "it\\'s ready",
    'decoded names and original escaped byte spans remain distinct',
);
$nestedDirective = <<<'BLADE'
    @if($value === '@include("fake.if")')
    @custom('@include("fake.custom")')
    @@include('@include("fake.escaped")')
    @include('real.view')
    BLADE;
$nested = (new BladeReferenceParser)->parse($nestedDirective);
$assert(
    $nested !== null
    && ! $nested->complete
    && array_map(static fn ($reference): string => $reference->name, $nested->references) === ['real.view'],
    'unknown and escaped directive argument strings cannot produce nested false references',
);
$opaquePhp = <<<'BLADE'
    @php $text = "@endphp @include('fake.php')"; // @endphp @include('fake.comment')
    $more = __('inside.php'); @endphp @include('after.php')
    BLADE;
$opaque = (new BladeReferenceParser)->parse($opaquePhp);
$assert(
    $opaque !== null && ! $opaque->complete && $opaque->references === [],
    'a raw-block delimiter inside PHP strings or comments remains ambiguous',
);
$assert((new BladeReferenceParser)->parse(str_repeat('x', 262_145)) === null, 'oversized input is not scanned');

echo "Blade source references passed.\n";
