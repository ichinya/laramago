<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\TemplateReferencePolicyHook;

require dirname(__DIR__).'/vendor/autoload.php';

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago template policy '.bin2hex(random_bytes(8));
mkdir($workspace, 0777, true);
$source = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Route;
    use Illuminate\Notifications\Messages\MailMessage;
    use Illuminate\Mail\Mailables\Content;
    use Illuminate\Pagination\Paginator;
    function declarations(string $dynamic, App\Custom $custom): void {
        Route::view('/ok', 'allowed');
        Route::view('/bad', 'route.bad');
        Route::view('/dynamic', $dynamic);
        Route::view('/wrong', 42);
        (new MailMessage)->view(['html' => 'mail.bad', 'text' => 'text.ok', 'raw' => 'raw.not.a.view']);
        (new MailMessage)->markdown('markdown.only.html');
        (new MailMessage)->view($dynamic);
        new Content(view: 'content.bad', text: 'text.bad', htmlString: 'raw.not.a.view');
        new Content(markdown: 'markdown.bad');
        new Content(view: '', html: '0');
        Paginator::defaultView('pagination.bad');
        Paginator::defaultSimpleView('pagination.ok');
        $custom->render('custom.not.a.template');
        $custom->links('custom.not.a.template');
    }
    throw new RuntimeException('Application source must never execute.');
    PHP;
file_put_contents($workspace.'/selected.php', $source);
file_put_contents(
    $workspace.'/unselected.php',
    '<?php \Illuminate\Support\Facades\Route::view("/", "unselected.bad");',
);
file_put_contents($workspace.'/framework.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades {
        final class Route { public static function view(string $uri, string $view): void {} }
    }
    namespace Illuminate\Notifications\Messages {
        final class MailMessage {
            public function view(string|array $view, array $data = []): self { return $this; }
            public function markdown(string $view, array $data = []): self { return $this; }
        }
    }
    namespace Illuminate\Mail\Mailables {
        final class Content {
            public function __construct(?string $view = null, ?string $html = null, ?string $text = null,
                ?string $markdown = null, array $with = [], ?string $htmlString = null) {}
        }
    }
    namespace Illuminate\Pagination {
        final class Paginator {
            public static function defaultView(string $view): void {}
            public static function defaultSimpleView(string $view): void {}
        }
    }
    namespace App {
        final class Custom {
            public function render(string $view): void {}
            public function links(string $view): void {}
        }
    }
    PHP);

$policy = [
    'enabled' => true,
    'files' => ['selected.php', 'unselected.php'],
    'permitted' => [
        'route' => ['allowed'],
        'mail-html' => [],
        'mail-text' => ['text.ok'],
        'markdown-html' => ['markdown.only.html'],
        'markdown-text' => [],
        'pagination' => ['pagination.ok'],
    ],
];
$configure = static function (mixed $configuration) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => ['template-reference-policy' => $configuration]],
    ], JSON_THROW_ON_ERROR));
};
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$analyze = static function () use ($package, $workspace, $command): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['selected.php'], 'includes' => ['framework.php']],
        'extension-hosts' => [
            'laramago' => [
                'command' => [
                    PHP_BINARY,
                    '-d',
                    'opcache.enable_cli=0',
                    $package.'/bin/laramago-worker.php',
                    $package.'/vendor/autoload.php',
                    $workspace,
                ],
                'workers' => 2,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/report.json', 'w'],
            2 => ['file', $workspace.'/stderr.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/stderr.log');
    if (
        ! in_array($exit, [0, 1], true)
        || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)
    ) {
        throw new RuntimeException('Mago worker failed; inspect '.$workspace);
    }

    return json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'] ?? [];
};
$notes = static fn (array $issues): array => array_values(array_filter(
    $issues,
    static fn (array $issue): bool => (
        ($issue['code'] ?? null) === 'ichinya/laramago/laramago-template-reference-outside-policy'
    ),
));

try {
    $configure($policy);
    $issues = $analyze();
    $actual = [];
    foreach ($notes($issues) as $issue) {
        $annotation = $issue['annotations'][0];
        $span = $annotation['span'];
        $literal = substr($source, $span['start']['offset'], $span['end']['offset'] - $span['start']['offset']);
        preg_match('/explicit ([a-z-]+) source-reference/', $issue['message'], $context);
        $actual[] = $literal.':'.($context[1] ?? '');
        if (
            strtolower($issue['level']) !== 'note'
            || ! str_contains($issue['message'], 'does not prove a missing runtime template')
        ) {
            throw new RuntimeException('Policy diagnostics must not claim a runtime failure.');
        }
    }
    $expected = [
        "'route.bad':route",
        "'mail.bad':mail-html",
        "'markdown.only.html':markdown-text",
        "'content.bad':mail-html",
        "'text.bad':mail-text",
        "'markdown.bad':markdown-html",
        "'markdown.bad':markdown-text",
        "'pagination.bad':pagination",
    ];
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException(
            'Unexpected template policy locations: '.json_encode($actual).'; inspect '.$workspace,
        );
    }
    if (! in_array('invalid-argument', array_column($issues, 'code'), true)) {
        throw new RuntimeException('Native invalid argument diagnostic was lost.');
    }
    echo "PASS: eight exact real Mago notes across route, mail, Content, markdown contexts and pagination\n";
    echo "PASS: raw HTML, falsey Content, dynamics, arbitrary render/links and unindexed sources defer\n";
    echo "PASS: native invalid argument diagnostic remains authoritative\n";

    foreach (['disabled', 'absent', 'malformed', 'unasserted-context'] as $mode) {
        $changed = $policy;
        if ($mode === 'disabled') {
            $changed['enabled'] = false;
        } elseif ($mode === 'absent') {
            $changed = null;
        } elseif ($mode === 'malformed') {
            $changed['permitted']['route'][] = 42;
        } else {
            $changed['permitted'] = ['route' => ['allowed', 'route.bad']];
        }
        $configure($changed);
        if ($notes($analyze()) !== []) {
            throw new RuntimeException('Unexpected note in '.$mode.'; inspect '.$workspace);
        }
        echo 'PASS: '.$mode." defers\n";
    }
    $matches = new ReflectionMethod(TemplateReferencePolicyHook::class, 'matches');
    foreach ([
        ['contentHash' => 'stale', 'start' => 0, 'end' => 2],
        ['contentHash' => 'current', 'start' => -1, 'end' => 2],
        ['contentHash' => 'current', 'start' => 0, 'end' => 10],
    ] as $stale) {
        if ($matches->invoke(null, $stale, 'current', 5)) {
            throw new RuntimeException('Stale or invalid source span accepted.');
        }
    }
    echo "PASS: stale hashes and invalid spans defer\n";
} finally {
    foreach ([
        'selected.php',
        'unselected.php',
        'framework.php',
        'composer.json',
        'mago.json',
        'report.json',
        'stderr.log',
    ] as $file) {
        if (is_file($workspace.'/'.$file)) {
            unlink($workspace.'/'.$file);
        }
    }
    rmdir($workspace);
}
