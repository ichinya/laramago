<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\LivewireComponentCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago livewire catalog '.bin2hex(random_bytes(8));
mkdir($workspace.'/app/Providers', 0777, true);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$load = static function (int $version, array $files, array $sources, bool $complete = false) use (
    $workspace,
): LivewireComponentCatalog {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => ['livewire-components' => [
                'version' => $version,
                'files' => $files,
                'complete' => $complete,
            ]],
        ],
    ], JSON_THROW_ON_ERROR));
    foreach ($sources as $file => $source) {
        file_put_contents($workspace.'/'.$file, $source);
    }

    return new LivewireComponentCatalog($workspace);
};

$provider = <<<'PHP'
    <?php
    namespace App\Providers;
    use App\Livewire\Posts\ShowPost as Post;
    use Illuminate\Support\ServiceProvider;
    use Livewire\Livewire;
    class LivewireServiceProvider extends ServiceProvider {
        public function register(): void {}
        public function boot(): void {
            Livewire::component('posts.show', Post::class);
            Livewire::component(class: \App\Livewire\Dashboard::class, name: 'dashboard');
        }
    }
    PHP;
$selected = [[
    'file' => 'app/Providers/LivewireServiceProvider.php',
    'provider' => 'App\\Providers\\LivewireServiceProvider',
]];
$v3 = $load(3, $selected, ['app/Providers/LivewireServiceProvider.php' => $provider]);
$post = $v3->component('posts.show');
$assert(
    $post !== null
    && $post['class'] === 'App\\Livewire\\Posts\\ShowPost'
    && $post['viewPath'] === null
    && str_ends_with(str_replace('\\', '/', $post['file']), '/app/Providers/LivewireServiceProvider.php')
    && $post['line'] === 9,
    'selected v3 provider preserves alias, resolved class, and registration provenance',
);
$assert(
    $v3->component('dashboard')['class'] === 'App\\Livewire\\Dashboard'
    && $v3->contains('dashboard') === true
    && $v3->contains('missing') === null,
    'named arguments work and incomplete catalogs do not prove absence',
);
$complete = $load(3, $selected, ['app/Providers/LivewireServiceProvider.php' => $provider], true);
$assert(
    $complete->isComplete() && $complete->contains('missing') === false,
    'complete selected registry proves registry absence only',
);

$v4Source = <<<'PHP'
    <?php
    use Livewire\Livewire;
    Livewire::addComponent(name: 'ui.button', viewPath: 'resources/views/ui/button.blade.php');
    Livewire::addComponent('panel', class: \App\Livewire\Panel::class);
    Livewire::addComponent('combined', 'resources/views/combined.blade.php', \App\Livewire\Combined::class);
    Livewire::component('legacy', \App\Livewire\Legacy::class);
    PHP;
$v4 = $load(4, ['components.php'], ['components.php' => $v4Source]);
$assert(
    $v4->component('ui.button')['viewPath'] === 'resources/views/ui/button.blade.php'
    && $v4->component('ui.button')['class'] === null
    && $v4->component('panel')['class'] === 'App\\Livewire\\Panel'
    && $v4->component('combined')['class'] === 'App\\Livewire\\Combined'
    && $v4->component('combined')['viewPath'] === 'resources/views/combined.blade.php'
    && $v4->component('legacy')['class'] === 'App\\Livewire\\Legacy',
    'v4 addComponent preserves class and view targets with native argument positions',
);
$override = $load(
    4,
    ['components.php', 'later.php'],
    [
        'components.php' => $v4Source,
        'later.php' => '<?php use Livewire\\Livewire; Livewire::addComponent("panel", class: \\App\\Livewire\\NewPanel::class);',
    ],
);
$assert(
    $override->component('panel')['class'] === 'App\\Livewire\\NewPanel'
    && str_ends_with(str_replace('\\', '/', $override->component('panel')['file']), '/later.php'),
    'later selected source replaces the target and provenance',
);

$invalid = [
    '<?php use Livewire\\Livewire; Livewire::component("a", $class);',
    '<?php use Livewire\\Livewire; Livewire::component("a");',
    '<?php use Livewire\\Livewire; Livewire::component($name, \\App\\A::class);',
    '<?php use Livewire\\Livewire; Livewire::component("a", self::class);',
    '<?php use Livewire\\Livewire; if ($flag) Livewire::component("a", \\App\\A::class);',
    '<?php use Livewire\\Livewire; Livewire::component("a", \\App\\A::class); file_put_contents(__DIR__."/executed", "bad");',
    '<?php use Livewire\\Livewire; Livewire::component(name: "a", class: \\App\\A::class, unknown: true);',
    '<?php use Livewire\\Livewire; Livewire::component("a", \\App\\A::class, ...$arguments);',
];
foreach ($invalid as $source) {
    $unknown = $load(3, ['components.php'], ['components.php' => $source], true);
    $assert(
        $unknown->components() === null && $unknown->contains('a') === null,
        'unsupported v3 source remains unknown',
    );
}
$assert(! file_exists($workspace.'/executed'), 'application source is never executed');
$unsupportedV4 = $load(
    4,
    ['components.php'],
    [
        'components.php' => '<?php use Livewire\\Livewire; Livewire::addComponent("a", resource_path("a.blade.php"));',
    ],
    true,
);
$assert($unsupportedV4->components() === null, 'computed v4 view path remains unknown');
$v3Add = $load(3, ['components.php'], ['components.php' => $v4Source], true);
$assert($v3Add->components() === null, 'v4 registration is not interpreted as v3');
$badPath = $load(3, ['../outside.php'], [], true);
$assert($badPath->components() === null, 'source paths escaping the project are rejected');
$badProvider = $load(
    3,
    $selected,
    [
        'app/Providers/LivewireServiceProvider.php' => str_replace(
            'public function register(): void {}',
            'public function register(): void { Livewire::component("hidden", \\App\\Hidden::class); }',
            $provider,
        ),
    ],
    true,
);
$assert($badProvider->components() === null, 'nonempty provider registration method invalidates activation claim');

foreach (['app/Providers/LivewireServiceProvider.php', 'components.php', 'later.php', 'composer.json'] as $file) {
    unlink($workspace.'/'.$file);
}
rmdir($workspace.'/app/Providers');
rmdir($workspace.'/app');
rmdir($workspace);
