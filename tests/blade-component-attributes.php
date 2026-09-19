<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeClassComponent;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeClassRequiredProps;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeComponentAttributeParser;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeComponentOpeningTag;

require dirname(__DIR__).'/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo 'PASS: '.$message."\n";
};

$parser = new BladeComponentAttributeParser;
$tag = <<<'BLADE'
    <x-card title="Literal text" :sub-title="$subTitle" :$theme ::x-on:click="open = true" disabled path=/a/b/>
    BLADE;
$parsed = $parser->parse($tag);
$assert(
    $parsed instanceof BladeComponentOpeningTag
    && $parsed->complete
    && $parsed->selfClosing
    && $parsed->tag === 'card'
    && $parsed->rawTag === 'x-card'
    && $parsed->attributeNames() === ['title', 'sub-title', 'theme', 'disabled', 'path'],
    'literal, bound, short, escaped and boolean attributes retain their component roles',
);

$attributes = $parsed->attributes;
$assert(
    array_column($attributes, 'kind') === ['literal', 'bound', 'short', 'escaped', 'boolean', 'literal']
    && $attributes[1]['value'] === '$subTitle'
    && $attributes[2]['value'] === '$theme'
    && $attributes[2]['valueStart'] === null
    && $attributes[3]['name'] === ':x-on:click'
    && $attributes[4]['value'] === null,
    'binding kinds and raw expressions remain distinct for later type checks',
);

foreach ($attributes as $index => $attribute) {
    $expected = [
        'title="Literal text"',
        ':sub-title="$subTitle"',
        ':$theme',
        '::x-on:click="open = true"',
        'disabled',
        'path=/a/b',
    ][$index];
    $assert(
        substr($tag, $attribute['start'], $attribute['end'] - $attribute['start']) === $expected,
        'attribute source span preserves the original bytes: '.$attribute['rawName'],
    );
    if ($attribute['valueStart'] !== null) {
        $assert(
            substr($tag, $attribute['valueStart'], $attribute['valueEnd'] - $attribute['valueStart'])
            === $attribute['value'],
            'value source span preserves the original bytes: '.$attribute['rawName'],
        );
    }
}

$component = new BladeClassComponent(
    'App\\View\\Components\\Card',
    '/unused',
    [
        [
            'name' => 'title',
            'type' => 'string',
            'hasDefault' => false,
            'variadic' => false,
            'promoted' => true,
            'declaredIn' => 'App\\View\\Components\\Card',
        ],
        [
            'name' => 'subTitle',
            'type' => 'string',
            'hasDefault' => false,
            'variadic' => false,
            'promoted' => true,
            'declaredIn' => 'App\\View\\Components\\Card',
        ],
        [
            'name' => 'theme',
            'type' => 'string',
            'hasDefault' => false,
            'variadic' => false,
            'promoted' => true,
            'declaredIn' => 'App\\View\\Components\\Card',
        ],
        [
            'name' => 'missing',
            'type' => 'string',
            'hasDefault' => false,
            'variadic' => false,
            'promoted' => true,
            'declaredIn' => 'App\\View\\Components\\Card',
        ],
    ],
    [],
);
$validator = new BladeClassRequiredProps;
$assert(
    $validator->missingExplicit(
        $component,
        ['title', 'subTitle', 'theme', 'missing'],
        $parsed->attributeNames(),
        $parsed->complete,
    ) === ['missing'],
    'parsed names feed the existing explicit contract without treating escaped Alpine bindings as props',
);
$escapedTitle = $parser->parse('<x-card ::title="message">');
$assert(
    $escapedTitle?->complete === true
    && $validator->missingExplicit($component, ['title'], $escapedTitle->attributeNames(), true) === ['title'],
    'an escaped Alpine title binding does not satisfy a constructor title',
);

$plain = $parser->parse('<x:kit.card title="a > b" checked>');
$assert(
    $plain instanceof BladeComponentOpeningTag
    && $plain->complete
    && ! $plain->selfClosing
    && $plain->tag === 'kit.card'
    && $plain->attributes[0]['value'] === 'a > b',
    'quoted greater-than signs do not terminate an opening tag',
);

foreach ([
    '<x-card title="known" {{ $attributes->merge(["class" => "x"]) }}>',
    '<x-card title="known" @class(["active" => $active])>',
    '<x-card title="{{ $dynamic }}">',
    '<x-card :attributes="$attributes">',
    '<x-card title="unfinished>',
    '<x-card title = "unsupported">',
    '<x-card :title>',
    '<x-card :title%="value">',
    '<x-card title="@auth">',
    '<x-card title="<?php echo $title; ?>">',
] as $unknownTag) {
    $unknown = $parser->parse($unknownTag);
    $assert(
        $unknown instanceof BladeComponentOpeningTag
        && ! $unknown->complete
        && $validator->missingExplicit($component, ['missing'], $unknown->attributeNames(), $unknown->complete)
            === null,
        'dynamic or unsupported syntax cannot prove absence: '.$unknownTag,
    );
}

$oversized = $parser->parse('<x-card '.str_repeat(' ', 65_536).'>');
$tooMany = $parser->parse('<x-card '.str_repeat('a ', 257).'>');
$assert(
    $oversized?->complete === false && $tooMany?->complete === false,
    'size and attribute limits retain explicit incompleteness',
);

$assert(
    $parser->parse('</x-card>') === null && $parser->parse('<div title="x">') === null,
    'closing and ordinary HTML tags are outside the parser',
);
