<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeAnonymousComponentCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladePropsParser;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$parser = new BladePropsParser;
$metadata = $parser->parse(<<<'BLADE'
        {{-- @props(['hidden']) --}}
    @verbatim @props(['alsoHidden']) @endverbatim
    @props(['title', 'tone' => 'quiet', 'count' => null, 'calculated' => expensive()])
    BLADE);
$assert(
    $metadata !== null
    && $metadata->hasDirective
    && array_map(static fn ($prop): array => [$prop->name, $prop->hasDefault], $metadata->declarations) === [
        ['title', false],
        ['tone', true],
        ['count', true],
        ['calculated', true],
    ]
    && $metadata->offset !== null,
    'literal declarations retain source names and default presence without evaluating expressions',
);
$assert($parser->parse('{{-- @props(["x"]) --}}')?->hasDirective === false, 'Blade comments do not declare props');
$assert(
    $parser->parse('@verbatim @props(["x"]) @endverbatim')?->hasDirective === false,
    'verbatim blocks do not declare props',
);
$assert(
    $parser->parse('<?php $x = "@props([\'x\'])"; ?>')?->hasDirective === false,
    'PHP strings do not declare props',
);
$assert(
    $parser->parse('@php $x = "@props([\'x\'])"; @endphp')?->hasDirective === false,
    'PHP directive strings do not declare props',
);
$assert($parser->parse('@@props(["x"])')?->hasDirective === false, 'escaped directives do not declare props');
$assert($parser->parse('<!-- @props(["x"]) -->') === null, 'HTML comments are not mistaken for Blade comments');
$assert(
    $parser->parse('<div title="@props([\'x\'])"></div>') === null,
    'a directive in HTML quotes is not silently omitted',
);

foreach ([
    '@props($props)',
    '@props(["name", ...$more])',
    '@props([$key => 1])',
    '@props(["name", 123])',
    '@props(["0" => "name"])',
    '@props(["name", "name" => "override"])',
    '@props(["name"]) @props(["other"])',
    '@if($active) @props(["name"]) @endif',
    '@php if ($active): @endphp @props(["name"])',
    '@props(["unterminated"',
] as $unknown) {
    $assert(
        $parser->parse($unknown) === null,
        'dynamic, duplicate or conditional declaration remains unknown: '.$unknown,
    );
}

$workspace = sys_get_temp_dir().'/laramago-blade-props-'.bin2hex(random_bytes(6));
mkdir($workspace.'/resources/views/components', 0777, true);
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'blade-anonymous-components' => [
                'roots' => [['path' => 'resources/views/components']],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/resources/views/components/card.blade.php', '@props(["title", "tone" => "quiet"])');
file_put_contents($workspace.'/resources/views/components/opaque.blade.php', '@props($runtime)');
$components = (new BladeAnonymousComponentCatalog($workspace))->components();
$assert($components !== null && count($components) === 2, 'selected Blade files remain source catalog entries');
$assert(
    $components[0]->path === 'resources/views/components/card.blade.php'
    && $components[0]->props?->declarations[0]->name === 'title'
    && $components[0]->props?->declarations[1]->hasDefault === true,
    'catalog exports literal props on its source file',
);
$assert($components[1]->props === null, 'dynamic props do not become a complete declaration');
