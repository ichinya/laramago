<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeClassComponentCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago blade classes '.bin2hex(random_bytes(8));
mkdir($workspace.'/components/Nested', 0777, true);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$configure = static function (mixed $roots) use ($workspace): BladeClassComponentCatalog {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['blade-class-components' => ['roots' => $roots]]],
    ], JSON_THROW_ON_ERROR));

    return new BladeClassComponentCatalog($workspace);
};
$roots = [['namespace' => 'Example\\Widgets', 'path' => 'components']];
file_put_contents($workspace.'/components/Base.php', <<<'PHP'
    <?php
    namespace Example\Widgets;
    use Illuminate\View\Component as FrameworkComponent;
    use Domain\User as Person;
    throw new \RuntimeException('The catalog must never load source files.');
    abstract class Base extends FrameworkComponent {
        public ?Person $owner;
        public static string $cache = 'hidden';
        protected string $secret = 'hidden';
        public function __construct(public readonly string $title, protected int $internal = 1, $plain = UNKNOWN_DEFAULT) {
            throw new \RuntimeException('The catalog must never construct components.');
        }
    }
    PHP);
file_put_contents($workspace.'/components/Nested/Card.php', <<<'PHP'
    <?php
    namespace Example\Widgets\Nested;
    class Card extends \Example\Widgets\Base {
        public string $detail;
        public function render() { throw new \RuntimeException('Never render.'); }
    }
    PHP);
file_put_contents($workspace.'/components/Badge.php', <<<'PHP'
    <?php
    namespace Example\Widgets;
    class Badge extends Base {
        public function __construct(readonly int $count, string ...$labels) {}
    }
    PHP);
file_put_contents($workspace.'/components/WithTrait.php', <<<'PHP'
    <?php
    namespace Example\Widgets;
    class WithTrait extends Base { use UnknownTrait; }
    PHP);
file_put_contents($workspace.'/components/Direct.php', <<<'PHP'
    <?php
    namespace Example\Widgets;
    class Direct extends \Illuminate\View\Component {
        public (\Countable&\Iterator)|null $items;
        public function render() { return dynamicView(); }
    }
    PHP);
foreach ([
    'Unknown' => 'class Unknown extends Missing {}',
    'Plain' => 'class Plain {}',
    'CycleA' => 'class CycleA extends CycleB {}',
    'CycleB' => 'class CycleB extends CycleA {}',
    'Conditional' => 'if (true) { class Conditional extends \\Illuminate\\View\\Component {} }',
    'Wrong' => 'class OtherName extends \\Illuminate\\View\\Component {}',
] as $name => $declaration) {
    file_put_contents($workspace.'/components/'.$name.'.php', '<?php namespace Example\\Widgets; '.$declaration);
}
$autoloads = 0;
$trap = static function (string $class) use (&$autoloads): void {
    if (str_starts_with($class, 'Example\\') || str_starts_with($class, 'Illuminate\\')) {
        $autoloads++;
        throw new RuntimeException('Application autoload attempted.');
    }
};
spl_autoload_register($trap);
$assert($configure(null)->components() === null, 'source files alone do not activate discovery');
$catalog = $configure($roots);
$components = $catalog->components();
$assert(
    $components !== null && count($components) === 4,
    'only concrete unconditional classes with proven component ancestry are cataloged',
);
$byClass = [];
foreach ($components ?? [] as $component) {
    $byClass[$component->class] = $component;
}
$card = $byClass['Example\\Widgets\\Nested\\Card'];
$direct = $byClass['Example\\Widgets\\Direct'];
$assert(
    $direct->constructor === [] && $direct->properties['items']['type'] === '(Countable&Iterator)|null',
    'absent constructors and declaration-only union/intersection types need no runtime resolution',
);
$assert(
    $card->constructor !== null && array_column($card->constructor, 'name') === ['title', 'internal', 'plain'],
    'inherited constructor includes promoted and ordinary parameters in source order',
);
$assert(
    $card->constructor[0]['type'] === 'string'
    && $card->constructor[1]['hasDefault']
    && $card->constructor[2]['type'] === null,
    'declared types and syntactic defaults are preserved without inferred mixed',
);
$assert(
    $card->properties === [
        'owner' => ['type' => '?Domain\\User', 'declaredIn' => 'Example\\Widgets\\Base'],
        'title' => ['type' => 'string', 'declaredIn' => 'Example\\Widgets\\Base'],
        'detail' => ['type' => 'string', 'declaredIn' => 'Example\\Widgets\\Nested\\Card'],
    ],
    'public non-static inherited and promoted declarations resolve lexical imports',
);
$badge = $byClass['Example\\Widgets\\Badge'];
$assert(
    $badge->constructor !== null
    && array_column($badge->constructor, 'name') === ['count', 'labels']
    && $badge->constructor[1]['variadic'],
    'child constructors replace inherited signatures',
);
$assert(
    isset($badge->properties['title'], $badge->properties['count']),
    'parent promoted properties remain declarations when child constructor replaces initialization',
);
$trait = $byClass['Example\\Widgets\\WithTrait'];
$assert(
    $trait->constructor === null && $trait->properties === null,
    'unknown trait composition never manufactures complete member metadata',
);
$assert(
    $autoloads === 0 && ! class_exists('Example\\Widgets\\Badge', false),
    'application classes remain unloaded and source execution traps remain untouched',
);
spl_autoload_unregister($trap);
$assert(
    $configure([...$roots, ...$roots])->components() === null,
    'overlapping duplicate declarations invalidate ambiguous source roots',
);
foreach ([
    [['namespace' => 'Example\\Widgets', 'path' => '../outside']],
    [['namespace' => 'Example\\Widgets', 'path' => 'missing']],
    [['namespace' => '', 'path' => 'components']],
] as $invalid) {
    $assert($configure($invalid)->components() === null, 'invalid or unavailable roots remain unknown');
}
file_put_contents(
    $workspace.'/components/PrivateCtor.php',
    '<?php namespace Example\\Widgets; class PrivateCtor extends Base { private function __construct() {} }',
);
$private = $configure($roots)->components();
$privateByClass = [];
foreach ($private ?? [] as $component) {
    $privateByClass[$component->class] = $component;
}
$assert(
    $privateByClass['Example\\Widgets\\PrivateCtor']->constructor === null,
    'non-public constructors do not expose a usable public parameter list',
);
file_put_contents($workspace.'/components/Broken.php', '<?php class {');
$assert($configure($roots)->components() === null, 'parse failures invalidate discovery instead of claiming absence');
$assert(count($catalog->components() ?? []) === 4, 'catalog instances retain their parsed source snapshot');
