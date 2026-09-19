<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeClassComponentCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeComponentTagResolver;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago blade tags '.bin2hex(random_bytes(8));
mkdir($workspace.'/app/View/Components/Panel', 0777, true);
mkdir($workspace.'/app/View/Components/Card', 0777, true);
mkdir($workspace.'/vendor-views', 0777, true);
mkdir($workspace.'/vendor-views-later', 0777, true);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$writeConfig = static function (bool $complete = true) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'blade-class-components' => [
                    'roots' => [
                        ['namespace' => 'App\\View\\Components', 'path' => 'app/View/Components'],
                    ],
                ],
                'blade-component-tags' => [
                    'complete' => $complete,
                    'default-class-namespace' => 'App\\View\\Components',
                    'class-namespaces' => ['ui' => 'App\\View\\Components\\Ui'],
                    'anonymous-roots' => [
                        ['path' => 'resources/views/components', 'mode' => 'default'],
                        ['path' => 'vendor-views', 'mode' => 'path', 'prefix' => 'ui'],
                        ['path' => 'vendor-views-later', 'mode' => 'path', 'prefix' => 'late'],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
};
$writeConfig();
file_put_contents($workspace.'/app/View/Components/Button.php', <<<'PHP'
    <?php namespace App\View\Components;
    throw new RuntimeException('Do not execute application source.');
    class Button extends \Illuminate\View\Component { public function render() { throw new RuntimeException(); } }
    PHP);
mkdir($workspace.'/app/View/Components/Ui', 0777, true);
file_put_contents(
    $workspace.'/app/View/Components/Ui/Button.php',
    '<?php namespace App\\View\\Components\\Ui; class Button extends \\Illuminate\\View\\Component {}',
);
file_put_contents(
    $workspace.'/app/View/Components/Panel/Panel.php',
    '<?php namespace App\\View\\Components\\Panel; class Panel extends \\Illuminate\\View\\Component {}',
);
file_put_contents(
    $workspace.'/app/View/Components/Card/Card.php',
    '<?php namespace App\\View\\Components\\Card; class Card extends \\Illuminate\\View\\Component {}',
);
file_put_contents(
    $workspace.'/app/View/Components/URL.php',
    '<?php namespace App\\View\\Components; class URL extends \\Illuminate\\View\\Component {}',
);
$classes = (new BladeClassComponentCatalog($workspace))->components();
$assert($classes !== null && count($classes) === 5, 'real class catalog supplies proven source declarations');
$anonymous = [
    (object) [
        'path' => 'resources/views/components/button.blade.php',
        'rootPath' => 'resources/views/components',
        'prefix' => null,
        'relativeName' => 'button',
        'candidateNames' => ['button'],
    ],
    (object) [
        'path' => 'resources/views/components/badge/index.blade.php',
        'rootPath' => 'resources/views/components',
        'prefix' => null,
        'relativeName' => 'badge.index',
        'candidateNames' => ['badge.index', 'badge'],
    ],
    (object) [
        'path' => 'resources/views/components/url.blade.php',
        'rootPath' => 'resources/views/components',
        'prefix' => null,
        'relativeName' => 'url',
        'candidateNames' => ['url'],
    ],
    (object) [
        'path' => 'vendor-views/button.blade.php',
        'rootPath' => 'vendor-views',
        'prefix' => 'ui',
        'relativeName' => 'button',
        'candidateNames' => ['button'],
    ],
    (object) [
        'path' => 'vendor-views/badge.blade.php',
        'rootPath' => 'vendor-views',
        'prefix' => 'ui',
        'relativeName' => 'badge',
        'candidateNames' => ['badge'],
    ],
    (object) [
        'path' => 'vendor-views/tray/index.blade.php',
        'rootPath' => 'vendor-views',
        'prefix' => 'ui',
        'relativeName' => 'tray.index',
        'candidateNames' => ['tray.index', 'tray'],
    ],
    (object) [
        'path' => 'vendor-views-later/tray.blade.php',
        'rootPath' => 'vendor-views-later',
        'prefix' => 'late',
        'relativeName' => 'tray',
        'candidateNames' => ['tray'],
    ],
];
$aliases = [
    'button' => 'App\\View\\Components\\Ui\\Button',
    'panel' => 'components.panel',
    'logo' => 'App\\View\\Components\\Button',
];
$resolver = new BladeComponentTagResolver($workspace, $classes, $anonymous, $aliases, true);
$assert($resolver->isComplete(), 'explicit effective-registration assertion activates resolution');
$assert(
    $resolver->resolve('button')?->kind === 'alias'
    && $resolver->resolve('button')?->name === 'App\\View\\Components\\Ui\\Button',
    'exact registered alias precedes conventional class and anonymous view',
);
$assert(
    $resolver->resolveTag('<x-ui::button />')?->kind === 'class'
    && $resolver->resolve('ui::button')?->name === 'App\\View\\Components\\Ui\\Button',
    'registered class namespace precedes matching anonymous path',
);
$assert(
    $resolver->resolve('ui.button')?->kind === 'class'
    && $resolver->resolve('card')?->name === 'App\\View\\Components\\Card\\Card',
    'conventional dotted class and nested repeated-name fallback follow Laravel class lookup',
);
$assert(
    $resolver->resolve('url')?->name === 'App\\View\\Components\\URL'
    && $resolver->resolve('u-r-l')?->name === 'App\\View\\Components\\URL',
    'case-insensitive native class lookup lets acronym classes beat anonymous views',
);
$assert(
    $resolver->resolve('ui::badge')?->path === 'vendor-views/badge.blade.php',
    'registered anonymous path resolves when no higher-priority class exists',
);
$assert(
    $resolver->resolve('badge')?->path === 'resources/views/components/badge/index.blade.php',
    'default anonymous view namespace wins before an unprefixed registered path',
);
$assert(
    $resolver->resolve('tray')?->path === 'vendor-views/tray/index.blade.php'
    && $resolver->resolve('late::tray')?->path === 'vendor-views-later/tray.blade.php',
    'registered paths accept plain tags and earlier-root index beats later-root direct',
);
$assert(
    $resolver->resolve('</x-badge>')?->path === null
    && $resolver->resolveTag('</x-badge>')?->path === 'resources/views/components/badge/index.blade.php',
    'nested index view resolves through the compiler fallback when the closing tag is supplied',
);
$assert(
    $resolver->resolve('panel') === null && $resolver->candidates('panel')[0]->kind === 'alias',
    'view alias target blocks lower-priority class shorthand without false resolution',
);
$assert(
    $resolver->resolve('missing') === null && $resolver->candidates('missing') === [],
    'missing names remain unknown rather than producing a diagnostic',
);
$assert(
    $resolver->resolveTag('<x-logo title="hi">') === null && $resolver->resolveTag('<x-logo>')?->kind === 'alias',
    'isolated tag API does not parse attributes or arbitrary Blade text',
);
$writeConfig(false);
$unasserted = new BladeComponentTagResolver($workspace, $classes, $anonymous, $aliases, true);
$assert(
    ! $unasserted->isComplete()
    && $unasserted->resolve('button') === null
    && count($unasserted->candidates('button')) === 4,
    'unasserted catalog remains source candidates only, even for a known alias',
);
$writeConfig();
$missingCatalog = new BladeComponentTagResolver($workspace, $classes, null, $aliases, true);
$assert(
    ! $missingCatalog->isComplete() && $missingCatalog->resolve('button') === null,
    'unavailable competing catalog prevents effective resolution',
);
$duplicate = [
    ...$anonymous,
    (object) [
        'path' => 'resources/views/components/other/badge.blade.php',
        'rootPath' => 'resources/views/components',
        'prefix' => null,
        'relativeName' => 'badge.index',
        'candidateNames' => ['badge'],
    ],
];
$ambiguous = new BladeComponentTagResolver($workspace, $classes, $duplicate, $aliases, true);
$assert($ambiguous->resolve('badge') === null, 'equal-precedence source collisions remain ambiguous');
$partialAliases = new BladeComponentTagResolver($workspace, $classes, $anonymous, $aliases, false);
$assert(
    ! $partialAliases->isComplete() && $partialAliases->resolve('card') === null,
    'incomplete alias registration prevents resolving lower-priority class names',
);
