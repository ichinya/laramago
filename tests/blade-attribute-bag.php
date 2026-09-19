<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeAttributeBagPartitioner;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeClassComponentCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladePropsParser;

require dirname(__DIR__).'/vendor/autoload.php';

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};

$partitioner = new BladeAttributeBagPartitioner;
$props = (new BladePropsParser)->parse(
    "@props(['subTitle', 'tone' => 'quiet', 'URL'])\n<div {{ \$attributes }}></div>",
);
if ($props === null) {
    throw new RuntimeException('Literal @props source was not parsed.');
}
$attributes = ['sub-title', 'tone', 'u-r-l', 'class', 'style', 'disabled', 'x-data', '@click', ':class'];
$anonymous = $partitioner->anonymous($props, $attributes, true);
$check(
    $anonymous?->propAttributes === ['sub-title' => 'subTitle', 'tone' => 'tone', 'u-r-l' => 'URL']
    && $anonymous->bagAttributes === ['class', 'style', 'disabled', 'x-data', '@click', ':class'],
    'anonymous props consume exact and kebab names while HTML, boolean and Alpine attributes remain in the bag',
);
$check(
    $partitioner->anonymous($props, ['class', 'disabled'], true)?->bagAttributes === ['class', 'disabled'],
    'a @props default supplies no synthetic attribute and class/style merge remains a later bag operation',
);
$collidingProps = (new BladePropsParser)->parse("@props(['fooBar', 'FooBar'])");
$check(
    $collidingProps !== null
    && $partitioner->anonymous($collidingProps, ['foo-bar'], true) === null
    && $partitioner->anonymous($collidingProps, ['fooBar'], true)?->propAttributes === ['fooBar' => 'fooBar'],
    'a shared kebab alias cannot be assigned to one declaration, while an unambiguous exact key remains known',
);

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago blade bag '.bin2hex(random_bytes(8));
mkdir($workspace.'/components', 0777, true);
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'blade-class-components' => [
                'roots' => [
                    ['namespace' => 'Example\\Widgets', 'path' => 'components'],
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/components/Card.php', <<<'PHP'
    <?php
    namespace Example\Widgets;
    class Card extends \Illuminate\View\Component {
        public function __construct(string $subTitle, string $URL, string $tone = 'quiet') {
            throw new \RuntimeException('Never construct application components.');
        }
        public function render() { throw new \RuntimeException('Never render application components.'); }
    }
    PHP);
$components = (new BladeClassComponentCatalog($workspace))->components();
if ($components === null || count($components) !== 1) {
    throw new RuntimeException('Proven class source was not cataloged.');
}
$class = $partitioner->componentClass($components[0], $attributes, true);
$check(
    $class?->propAttributes === ['sub-title' => 'subTitle', 'tone' => 'tone']
    && $class->bagAttributes === ['u-r-l', 'class', 'style', 'disabled', 'x-data', '@click', ':class'],
    'class tags use constructor camel names; other keys, including escaped syntax, remain in the bag',
);
$check(
    $partitioner->anonymous($props, $attributes, false) === null
    && $partitioner->componentClass($components[0], $attributes, false) === null
    && $partitioner->anonymous($props, ['class', 'class'], true) === null,
    'incomplete or non-final parsed attribute keys keep the partition unknown',
);
$noDirective = (new BladePropsParser)->parse('<div {{ $attributes }}></div>');
$check(
    $noDirective !== null && $partitioner->anonymous($noDirective, ['class'], true) === null,
    'no @props directive does not assert prop extraction',
);
