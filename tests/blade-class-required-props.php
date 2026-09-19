<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeClassComponentCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeClassRequiredProps;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago blade required '.bin2hex(random_bytes(8));
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
        public function __construct(
            string $title,
            \Example\Service $service,
            ?string $subTitle,
            string $theme = 'light',
            string ...$labels,
        ) { throw new \RuntimeException('Never instantiate source components.'); }
        public function render() { throw new \RuntimeException('Never render source components.'); }
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

$catalog = (new BladeClassComponentCatalog($workspace))->components();
if ($catalog === null || count($catalog) !== 2) {
    throw new RuntimeException('Explicit source catalog was not populated.');
}
$byClass = [];
foreach ($catalog as $component) {
    $byClass[$component->class] = $component;
}
$card = $byClass['Example\\Widgets\\Card'];
$opaque = $byClass['Example\\Widgets\\Opaque'];
$validator = new BladeClassRequiredProps;
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};

$check(
    $validator->candidates($card) === ['title', 'service', 'subTitle'],
    'no-default constructor parameters are candidates, including container and nullable parameters',
);
$check(
    $validator->candidates($opaque) === null,
    'unresolved trait composition keeps constructor candidates unknown',
);
$check(
    $validator->missingExplicit($card, [], [], true) === [],
    'constructor candidates alone never become missing-prop reports',
);
$check(
    $validator->missingExplicit($card, ['title'], ['title', 'class'], true) === [],
    'a supplied literal attribute satisfies an explicit contract',
);
$check(
    $validator->missingExplicit($card, ['title', 'subTitle'], ['title'], true) === ['subTitle'],
    'only author-declared required names can be reported missing',
);
$check(
    $validator->missingExplicit($card, ['title'], ['TITLE'], true) === ['title'],
    'attribute spelling follows Laravel camel conversion',
);
$check(
    $validator->missingExplicit($card, ['subTitle'], ['sub-title'], true) === [],
    'hyphenated attributes map to constructor camel names',
);
$check(
    $validator->missingExplicit($card, ['service'], [], false) === null,
    'incomplete dynamic attribute lists never prove a missing prop',
);
$check(
    $validator->missingExplicit($card, ['missing'], [], true) === null
    && $validator->missingExplicit($card, ['labels'], [], true) === null
    && $validator->missingExplicit($opaque, ['title'], [], true) === null,
    'stale, variadic and unknown constructor contracts remain unknown',
);
$check(
    $validator->missingExplicit($card, ['title'], ['::title'], true) === null,
    'unparsed Blade attribute syntax is rejected instead of guessed',
);
$check(
    $validator->missingExplicit($card, [123], [], true) === null,
    'malformed explicit required names remain unknown',
);
