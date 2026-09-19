<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\GateDefinitionCatalog;
use PhpParser\Node;

require dirname(__DIR__).'/vendor/autoload.php';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-gate-'.bin2hex(random_bytes(8));
mkdir($workspace);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$options = ['files' => ['gates.php']];
$load = static function (mixed $options, string $source) use ($workspace): GateDefinitionCatalog {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['gate-definitions' => $options]],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($workspace.'/gates.php', $source);

    return new GateDefinitionCatalog($workspace);
};
$source = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Gate as G;
    use App\User as U;
    G::define('edit', /** @return bool */ function (U $user): bool { throw new \RuntimeException('never execute'); });
    G::define(callback: [App\Policy::class, 'publish'], ability: 'publish');
    G::define('invokable', 'App\InvokablePolicy');
    G::define('duplicate', 'Old@check');
    G::define('duplicate', fn (U $user): bool => true);
    PHP;
$catalog = $load($options, $source);
$definition = $catalog->definition('edit');
$callback = $definition['callback'] ?? null;
$assert(
    $callback instanceof Node\Expr\Closure
    && $callback->params[0]->type instanceof Node\Name
    && $callback->params[0]->type->toString() === 'App\\User'
    && $callback->returnType instanceof Node\Identifier
    && $definition['registration']->expr->args[1]->getDocComment()?->getText() === '/** @return bool */',
    'resolved callback AST preserves native and PHPDoc provenance',
);
$assert(
    $definition !== null && $definition['line'] === 4 && $definition['file'] === realpath($workspace.'/gates.php'),
    'registration file and line retained',
);
$assert(
    $catalog->definition('publish')['callback'] instanceof Node\Expr\Array_,
    'named arguments and class callback arrays retained without loading targets',
);
$assert(
    $catalog->definition('duplicate')['callback'] instanceof Node\Expr\ArrowFunction,
    'last literal registration wins',
);
$assert($catalog->contains('missing') === null && ! $catalog->isComplete(), 'partial absence remains unknown');
$catalog = $load($options + ['complete' => true], $source);
$assert(
    $catalog->contains('EDIT') === false && $catalog->contains('edit') === true && $catalog->isComplete(),
    'explicit completeness and case-sensitive definition lookup',
);
$catalog = $load(['files' => [], 'complete' => true], '<?php');
$assert($catalog->definitions() === [] && $catalog->contains('edit') === false, 'explicit empty definition universe');
$provider = '<?php declare(strict_types=1); namespace App; use Illuminate\\Support\\ServiceProvider; use Illuminate\\Support\\Facades\\Gate; class Provider extends ServiceProvider { public function boot(): void { Gate::define("view", fn (): bool => true); } }';
$providerOptions = ['files' => [['file' => 'gates.php', 'provider' => 'App\\Provider']], 'complete' => true];
$assert($load($providerOptions, $provider)->contains('view') === true, 'explicitly selected direct provider boot body');
$assert($load($options, $provider)->definitions() === null, 'unselected provider activation remains unknown');
$assert(
    $load($providerOptions, str_replace('ServiceProvider {', 'CustomProvider {', $provider))->definitions() === null,
    'custom provider ancestry remains unknown',
);
$assert(
    $load($providerOptions, str_replace('Gate::define', 'if ($enabled) Gate::define', $provider))->definitions()
    === null,
    'conditional provider registrations remain unknown',
);
foreach ([
    '<?php use Illuminate\\Support\\Facades\\Gate; Gate::define($ability, fn () => true);',
    '<?php use Illuminate\\Support\\Facades\\Gate; Gate::define("known", fn () => true); Gate::define("unknown", $callback);',
    '<?php use Illuminate\\Support\\Facades\\Gate; if ($enabled) { Gate::define("x", fn () => true); }',
    '<?php use Illuminate\\Support\\Facades\\Gate; Gate::resource("post", Policy::class);',
    '<?php use Illuminate\\Support\\Facades\\Gate; Gate::define("x", [new Policy, "check"]);',
    '<?php use Illuminate\\Support\\Facades\\Gate; Gate::define(ability: "x", wrong: fn () => true);',
    '<?php use Illuminate\\Support\\Facades\\Gate; Gate::define(...$args);',
    '<?php use App\\Gate; Gate::define("x", fn () => true);',
    '<?php file_put_contents(__DIR__."/executed", "bad");',
    '<?php invalid source !!',
] as $unsupported) {
    $catalog = $load($options + ['complete' => true], $unsupported);
    $assert(
        $catalog->definitions() === null && ! $catalog->isComplete() && $catalog->contains('known') === null,
        'unsupported source invalidates effective catalog',
    );
}
foreach ([
    null,
    ['files' => ['../gates.php']],
    ['files' => ['missing.php']],
    ['files' => ['gates.php'], 'complete' => 'true'],
    ['files' => [['file' => 'gates.php']]],
] as $invalid) {
    $assert($load($invalid, $source)->definitions() === null, 'malformed or unavailable selection stays unknown');
}
file_put_contents(
    $workspace.'/override.php',
    '<?php \\Illuminate\\Support\\Facades\\Gate::define("edit", "New@edit");',
);
$catalog = $load(['files' => ['gates.php', 'override.php']], $source);
$assert(
    $catalog->definition('edit')['callback'] instanceof Node\Scalar\String_,
    'explicit file order overlays registrations',
);
$assert(! file_exists($workspace.'/executed'), 'application source and callback bodies never execute');
unlink($workspace.'/override.php');
unlink($workspace.'/gates.php');
unlink($workspace.'/composer.json');
rmdir($workspace);
