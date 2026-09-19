<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\MiddlewareAliasCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago middleware aliases '.bin2hex(random_bytes(8));
mkdir($workspace.'/bootstrap', 0777, true);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$load = static function (mixed $configuration, string $php) use ($workspace): MiddlewareAliasCatalog {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['middleware-aliases' => $configuration]],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($workspace.'/bootstrap/app.php', $php);

    return new MiddlewareAliasCatalog($workspace);
};
$options = ['files' => ['bootstrap/app.php']];
$absolute = $load($options, '<?php return ["absolute" => "\\\\App\\\\Middleware"];');
$assert(
    $absolute->target('absolute') === '\\App\\Middleware',
    'literal target spelling preserves container binding identity',
);
$literal = '<?php use App\\Http\\Middleware\\Authenticate as Auth; return ["auth" => Auth::class, "audit-log" => "App\\\\Audit"];';
$partial = $load($options, $literal);
$assert(
    $partial->aliases() === ['auth' => 'App\\Http\\Middleware\\Authenticate', 'audit-log' => 'App\\Audit'],
    'imports and class strings resolve without class loading',
);
$assert(
    $partial->contains('auth') === true && $partial->contains('missing') === null && ! $partial->isComplete(),
    'partial catalog never proves missing names',
);
$complete = $load($options + ['complete' => true], $literal);
$assert(
    $complete->contains('Auth') === false && $complete->contains('auth:web') === false && $complete->isComplete(),
    'complete lookup is case-sensitive and uses exact alias names',
);
$bootstrap = static fn (string $body): string => (
    '<?php use Illuminate\\Foundation\\Application; use Illuminate\\Foundation\\Configuration\\Middleware as M; return Application::configure(basePath: dirname(__DIR__))->withRouting(web: __DIR__."/../routes/web.php")->withMiddleware(function (M $middleware): void {'
    .$body
    .'})->create();'
);
$native = $load(
    $options,
    $bootstrap(
        '$middleware->alias(["old" => OldMiddleware::class]); $middleware->alias(aliases: ["auth" => \\App\\Auth::class]);',
    ),
);
$assert(
    $native->aliases() === ['auth' => 'App\\Auth'],
    'native alias calls replace previous custom aliases, preserving named arguments',
);
$assert(
    $native->contains('guest') === null && $native->target('auth') === 'App\\Auth',
    'framework defaults and class existence are not inferred',
);
$empty = $load($options + ['complete' => true], '<?php return [];');
$assert(
    $empty->aliases() === [] && $empty->contains('auth') === false,
    'explicit empty complete catalog remains distinguishable from unknown',
);
foreach ([
    null,
    ['complete' => true],
    $options + ['complete' => null],
    $options + ['complete' => 'true'],
    ['files' => ["bootstrap/app.php\0.php"]],
    ['files' => ['C:/outside.php']],
    ['files' => '../app.php'],
    ['files' => ['bootstrap/../app.php']],
    ['files' => ['bootstrap/missing.php']],
    ['files' => ['bootstrap/app.php', 1]],
] as $invalid) {
    $catalog = $load($invalid, $literal);
    $assert(
        $catalog->aliases() === null && $catalog->contains('auth') === null && ! $catalog->isComplete(),
        'absent or malformed sources remain unknown',
    );
}
foreach ([
    '<?php return ["auth" => getenv("MIDDLEWARE")];',
    '<?php return ["auth" => Auth::class, ...$dynamic];',
    '<?php return [$dynamic => Auth::class];',
    '<?php return ["auth" => Auth::class, "auth" => $dynamic];',
    '<?php return ["auth:web" => Auth::class];',
    '<?php return ["1" => Auth::class];',
    '<?php return ["auth" => "App\\\\Auth:parameter"];',
    '<?php return ["auth" => self::class];',
    '<?php return ["auth" => parent::class];',
    '<?php return ["auth" => function () {}];',
    '<?php file_put_contents(__DIR__."/executed", "bad"); return ["auth" => Auth::class];',
    '<?php if ($enabled) { return ["auth" => Auth::class]; }',
    '<?php declare(ticks=1); return ["auth" => Auth::class];',
    '<?php return [',
    $bootstrap('if ($enabled) { $middleware->alias(["auth" => Auth::class]); }'),
    $bootstrap('$middleware->alias(["auth" => Auth::class]); $middleware->append(Other::class);'),
    $bootstrap('$other->alias(["auth" => Auth::class]);'),
    $bootstrap('$middleware->alias(...$dynamic);'),
    str_replace(
        'Illuminate\\Foundation\\Application',
        'App\\Application',
        $bootstrap('$middleware->alias(["auth" => Auth::class]);'),
    ),
    str_replace(
        'function (M $middleware)',
        'function ($middleware)',
        $bootstrap('$middleware->alias(["auth" => Auth::class]);'),
    ),
] as $unsupported) {
    $catalog = $load($options + ['complete' => true], $unsupported);
    $assert(
        $catalog->aliases() === null && ! $catalog->isComplete() && $catalog->contains('missing') === null,
        'unsupported syntax cannot establish names or absence',
    );
}
$assert(! file_exists($workspace.'/bootstrap/executed'), 'application PHP was never executed');
file_put_contents($workspace.'/aliases with spaces.php', '<?php return ["space" => \\App\\Space::class];');
$spaced = $load(['files' => ['aliases with spaces.php']], $literal);
$assert(
    $spaced->target('space') === 'App\\Space',
    'project-relative sources may live outside bootstrap and contain spaces',
);
unlink($workspace.'/aliases with spaces.php');
file_put_contents($workspace.'/bootstrap/override.php', '<?php return ["auth" => \\App\\OverrideAuth::class];');
$overlay = $load(['files' => ['bootstrap/app.php', 'bootstrap/override.php']], $literal);
$assert(
    $overlay->target('auth') === 'App\\OverrideAuth' && $overlay->contains('audit-log') === true,
    'explicit source order overlays effective maps',
);
file_put_contents($workspace.'/bootstrap/override.php', '<?php return $dynamic;');
$invalidOverlay = new MiddlewareAliasCatalog($workspace);
$assert($invalidOverlay->aliases() === null, 'unknown later sources cannot leave stale targets');
foreach (['bootstrap/app.php', 'bootstrap/override.php', 'composer.json'] as $file) {
    unlink($workspace.'/'.$file);
}
rmdir($workspace.'/bootstrap');
rmdir($workspace);
