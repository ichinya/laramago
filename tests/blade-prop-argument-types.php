<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeClassComponentCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeClassPropArgumentTypes;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago blade prop types '.bin2hex(random_bytes(8));
mkdir($workspace.'/components', 0777, true);
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'blade-class-components' => [
                'roots' => [['namespace' => 'Example\\Widgets', 'path' => 'components']],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/components/Card.php', <<<'PHP'
    <?php
    namespace Example\Widgets;
    class Card extends \Illuminate\View\Component {
        public function __construct(
            array $items,
            string $label,
            ?int $count,
            \DateTimeInterface $date,
            array|string $either,
            string $optional = 'default',
        ) { throw new \RuntimeException('Never instantiate application components.'); }
        public function render() { throw new \RuntimeException('Never render application components.'); }
    }
    PHP);
file_put_contents($workspace.'/components/Opaque.php', <<<'PHP'
    <?php
    namespace Example\Widgets;
    class Opaque extends \Illuminate\View\Component {
        use UnknownConstructorTrait;
        public function render() { return ''; }
    }
    PHP);

$classes = (new BladeClassComponentCatalog($workspace))->components();
if ($classes === null || count($classes) !== 2) {
    throw new RuntimeException('Selected source component declarations were not found.');
}
$byClass = [];
foreach ($classes as $component) {
    $byClass[$component->class] = $component;
}
$card = $byClass['Example\\Widgets\\Card'];
$opaque = $byClass['Example\\Widgets\\Opaque'];
$attributes = ['items', 'label', 'count', 'date', 'either', 'optional'];
$types = new BladeClassPropArgumentTypes;
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};

$mismatch = $types->mismatchLiteral($card, $attributes, 'items', '42', true, true, true);
$check(
    $mismatch?->parameter === 'items' && $mismatch->expected === 'array' && $mismatch->actual === 'int',
    'bound integer cannot satisfy a directly invoked array parameter',
);
$check(
    $types->mismatchLiteral($card, $attributes, 'label', '[]', true, true, true)?->actual === 'array'
    && $types->mismatchLiteral($card, $attributes, 'date', "'today'", true, true, true)?->actual === 'string',
    'literal array and string remain disjoint from string and object constructor types',
);
$check(
    $types->mismatchLiteral($card, $attributes, 'either', 'null', true, true, true)?->expected === 'array|string'
    && $types->mismatchLiteral($card, $attributes, 'count', '[]', true, true, true)?->actual === 'array',
    'every union branch must reject the literal and nullable int still rejects arrays',
);
$check(
    $types->mismatchLiteral($card, $attributes, 'count', 'null', true, true, true) === null
    && $types->mismatchLiteral($card, $attributes, 'either', "'ok'", true, true, true) === null,
    'nullable and union branches prevent a mismatch',
);
$check(
    $types->mismatchLiteral($card, $attributes, 'label', '42', true, true, true) === null
    && $types->mismatchLiteral($card, $attributes, 'count', "'42'", true, true, true) === null,
    'weak PHP scalar coercion is not reported as a strong type failure',
);
$check(
    $types->mismatchLiteral($card, $attributes, 'items', '$unknown', true, true, true) === null
    && $types->mismatchLiteral($card, $attributes, 'items', 'makeItems()', true, true, true) === null,
    'dynamic bound expressions remain unknown',
);
$check(
    $types->mismatchLiteral($card, $attributes, 'items', '42', false, true, true) === null
    && $types->mismatchLiteral($card, $attributes, 'items', '42', true, false, true) === null
    && $types->mismatchLiteral($card, $attributes, 'items', '42', true, true, false) === null,
    'HTML strings, incomplete tags and unasserted runtime resolution remain unknown',
);
$check(
    $types->mismatchLiteral($card, array_slice($attributes, 0, -1), 'items', '42', true, true, true) === null
    && $types->mismatchLiteral($card, [...$attributes, 'items'], 'items', '42', true, true, true) === null,
    'missing optional constructor names and duplicate keys cannot prove direct argument routing',
);
$check(
    $types->mismatchLiteral($card, $attributes, 'class', '42', true, true, true) === null
    && $types->mismatchLiteral($card, $attributes, '::items', '42', true, true, true) === null
    && $types->mismatchLiteral($opaque, $attributes, 'items', '42', true, true, true) === null,
    'unknown constructor props, escaped names and trait constructors defer',
);
$check(
    $types->mismatchLiteral($card, $attributes, 'items', '42; exit(1)', true, true, true) === null
    && $types->mismatchLiteral($card, $attributes, 'items', '42; //', true, true, true) === null,
    'extra statements and trailing comments never become literal argument evidence',
);
