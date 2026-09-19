<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeComponentAliasCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago blade aliases '.bin2hex(random_bytes(8));
mkdir($workspace.'/app/Providers', 0777, true);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$load = static function (array $files, array $sources, bool $complete = false) use (
    $workspace,
): BladeComponentAliasCatalog {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => ['blade-component-aliases' => [
                'files' => $files,
                'complete' => $complete,
            ]],
        ],
    ], JSON_THROW_ON_ERROR));
    foreach ($sources as $file => $source) {
        file_put_contents($workspace.'/'.$file, $source);
    }

    return new BladeComponentAliasCatalog($workspace);
};

$provider = <<<'PHP'
    <?php
    namespace App\Providers;
    use App\View\Components\Alert as AlertComponent;
    use Illuminate\Support\Facades\Blade;
    use Illuminate\Support\ServiceProvider;
    class AppServiceProvider extends ServiceProvider {
    public function register(): void {}
    public function boot(): void {
            Blade::component(AlertComponent::class, 'notice');
            Blade::component(prefix: 'ui', alias: 'card', class: \App\View\Components\Card::class);
            Blade::components(['badge' => \App\View\Components\Badge::class], prefix: 'kit');
        }
    }
    PHP;
$selected = [['file' => 'app/Providers/AppServiceProvider.php', 'provider' => 'App\\Providers\\AppServiceProvider']];
$catalog = $load($selected, ['app/Providers/AppServiceProvider.php' => $provider]);
$assert(
    $catalog->aliases() === [
        'notice' => 'App\\View\\Components\\Alert',
        'ui-card' => 'App\\View\\Components\\Card',
        'kit-badge' => 'App\\View\\Components\\Badge',
    ],
    'selected provider boot registrations preserve Laravel alias and prefix semantics',
);
$assert(
    $catalog->target('notice') === 'App\\View\\Components\\Alert',
    'exact alias lookup returns the registered class',
);
$assert(
    $catalog->contains('missing') === null && ! $catalog->isComplete(),
    'partial registrations do not prove absence',
);
$complete = $load($selected, ['app/Providers/AppServiceProvider.php' => $provider], true);
$assert(
    $complete->contains('missing') === false && $complete->contains('notice') === true,
    'explicit complete registry distinguishes absent names',
);
$uncertainProvider = $load(
    $selected,
    [
        'app/Providers/AppServiceProvider.php' => str_replace(
            'public function register(): void {}',
            'public function register(): void { Blade::component(\\App\\Hidden::class, "hidden"); }',
            $provider,
        ),
    ],
    true,
);
$assert($uncertainProvider->aliases() === null, 'nonempty provider registration methods remain unknown');

$second = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Blade;
    Blade::component(\App\View\Components\NewAlert::class, 'notice');
    Blade::component('App\\View\\Components\\Literal', alias: 'literal', prefix: '0');
    Blade::component('components.alert', alias: 'view-alert');
    PHP;
$overlaid = $load([...$selected, 'aliases.php'], [
    'app/Providers/AppServiceProvider.php' => $provider,
    'aliases.php' => $second,
]);
$assert(
    $overlaid->target('notice') === 'App\\View\\Components\\NewAlert'
    && $overlaid->target('literal') === 'App\\View\\Components\\Literal'
    && $overlaid->target('view-alert') === 'components.alert',
    'later selected sources preserve raw class or view targets and PHP empty prefix zero',
);

$unsupported = [
    '<?php Blade::component(Foo::class, "foo");',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::component(\App\Foo::class);',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::component(\App\Foo::class, $alias);',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::components([\App\Foo::class]);',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::components(["foo" => \App\Foo::class, ...$dynamic]);',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::components(["foo" => "Foo"]);',
    '<?php use Illuminate\\Support\\Facades\\Blade; if ($enabled) Blade::component(\App\Foo::class, "foo");',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::component(\App\Foo::class, "foo"); file_put_contents(__DIR__."/executed", "bad");',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::component(self::class, "foo");',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::component(\App\Foo::class, "App\\\\Other");',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::component(\App\Foo::class, "foo", prefix: $dynamic);',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::component(\App\Foo::class, "foo", invalid: "x");',
];
foreach ($unsupported as $source) {
    $unknown = $load(['aliases.php'], ['aliases.php' => $source], true);
    $assert(
        $unknown->aliases() === null && $unknown->contains('foo') === null,
        'unresolved registration invalidates the selected registry',
    );
}
$assert(! file_exists($workspace.'/executed'), 'selected application PHP was not executed');
$unknown = $load(['../outside.php'], ['aliases.php' => $second], true);
$assert($unknown->aliases() === null && ! $unknown->isComplete(), 'paths escaping the project are rejected');

foreach (['app/Providers/AppServiceProvider.php', 'aliases.php', 'composer.json'] as $file) {
    unlink($workspace.'/'.$file);
}
rmdir($workspace.'/app/Providers');
rmdir($workspace.'/app');
rmdir($workspace);
