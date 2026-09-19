<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeDirectiveCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago blade directives '.bin2hex(random_bytes(8));
mkdir($workspace.'/app/Providers', 0777, true);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$load = static function (array $files, array $sources, bool $complete = false) use ($workspace): BladeDirectiveCatalog {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['blade-directives' => ['files' => $files, 'complete' => $complete]]],
    ], JSON_THROW_ON_ERROR));
    foreach ($sources as $file => $source) {
        file_put_contents($workspace.'/'.$file, $source);
    }

    return new BladeDirectiveCatalog($workspace);
};

$provider = <<<'PHP'
    <?php
    namespace App\Providers;
    use Illuminate\Support\Facades\Blade as Template;
    use Illuminate\Support\ServiceProvider;
    class AppServiceProvider extends ServiceProvider {
        public function register(): void {}
        public function boot(): void {
            Template::directive('uppercase', fn ($expression) => strtoupper($expression));
            Template::if(name: 'subscribed', callback: fn ($plan) => true);
            Template::directive('unlesssubscribed', function () { file_put_contents(__DIR__.'/executed', 'bad'); });
        }
    }
    PHP;
$selected = [['file' => 'app/Providers/AppServiceProvider.php', 'provider' => 'App\\Providers\\AppServiceProvider']];
$catalog = $load($selected, ['app/Providers/AppServiceProvider.php' => $provider]);
$directives = $catalog->directives();
$assert(
    $directives !== null
    && array_keys($directives) === [
        'uppercase',
        'subscribed',
        'unlesssubscribed',
        'elsesubscribed',
        'endsubscribed',
    ],
    'selected provider boot yields direct and generated condition directive names',
);
$assert(
    $catalog->get('uppercase')?->kind === 'directive'
    && $catalog->get('uppercase')?->declaresExpressionParameter === true
    && $catalog->get('subscribed')?->kind === 'if'
    && $catalog->get('elsesubscribed')?->kind === 'else'
    && $catalog->get('endsubscribed')?->kind === 'end'
    && $catalog->get('endsubscribed')?->declaresExpressionParameter === false
    && $catalog->get('unlesssubscribed')?->kind === 'directive'
    && $catalog->get('unlesssubscribed')?->declaresExpressionParameter === false,
    'later direct registrations override generated conditions and retain their own kinds',
);
$name = $catalog->get('subscribed');
$source = file_get_contents($workspace.'/app/Providers/AppServiceProvider.php');
$assert(
    $name !== null
    && $name->path === $workspace.'/app/Providers/AppServiceProvider.php'
    && substr($source, $name->start, $name->end - $name->start) === "'subscribed'"
    && $catalog->get('endsubscribed')?->start === $name->start,
    'source location uses original file byte offsets even for generated names',
);
$assert($catalog->contains('unknown') === null && ! $catalog->isComplete(), 'partial catalog does not prove absence');
$assert(! file_exists($workspace.'/app/Providers/executed'), 'registration and callback bodies are never executed');

$overlay = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Blade;
    Blade::directive('subscribed', function ($expression) { return $expression; }, bind: true);
    Blade::directive('csrf', fn () => 'custom');
    Blade::directive('switch::mode', fn ($expression) => $expression);
    Blade::directive('0', fn () => 'zero');
    PHP;
$catalog = $load(
    [...$selected, 'directives.php'],
    [
        'app/Providers/AppServiceProvider.php' => $provider,
        'directives.php' => $overlay,
    ],
    true,
);
$assert(
    $catalog->get('subscribed')?->kind === 'directive'
    && $catalog->get('subscribed')?->path === $workspace.'/directives.php'
    && $catalog->get('csrf')?->kind === 'directive'
    && $catalog->contains('switch::mode') === true
    && $catalog->contains('unknown') === false
    && $catalog->isComplete(),
    'ordered files replace prior names, retain built-in overrides, and permit asserted negative lookup',
);

$assert(
    $catalog->get('0')?->name === '0' && $catalog->contains('0') === true && isset($catalog->directives()[0]),
    'numeric directive names survive PHP array-key coercion',
);

$unsupported = [
    '<?php Blade::directive("foo", fn () => "");',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::directive($name, fn () => "");',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::directive("foo", $handler);',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::directive("bad-name", fn () => "");',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::if("foo", $callback);',
    '<?php use Illuminate\\Support\\Facades\\Blade; if ($enabled) Blade::directive("foo", fn () => "");',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::directive("foo", fn () => ""); touch(__DIR__."/executed");',
    '<?php use Illuminate\\Support\\Facades\\Blade; Blade::directive("foo", fn () => "", bind: $dynamic);',
];
foreach ($unsupported as $source) {
    $unknown = $load(['directives.php'], ['directives.php' => $source], true);
    $assert(
        $unknown->directives() === null && $unknown->contains('foo') === null,
        'unsupported or dynamic selected source leaves the whole catalog unknown',
    );
}
$assert(! file_exists($workspace.'/executed'), 'unsupported application statements are not executed');
$unknown = $load(['../outside.php'], [], true);
$assert($unknown->directives() === null && ! $unknown->isComplete(), 'escaping project paths are rejected');

foreach (['app/Providers/AppServiceProvider.php', 'directives.php', 'composer.json'] as $file) {
    unlink($workspace.'/'.$file);
}
rmdir($workspace.'/app/Providers');
rmdir($workspace.'/app');
rmdir($workspace);
