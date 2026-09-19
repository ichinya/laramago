<?php

declare(strict_types=1);

// Check Laravel 13 FormRequest redirect attributes through the real Mago worker.
// The native UrlGenerator excerpt is covered by fixtures/analysis/named-route-LICENSE.md.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago named route attributes '.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
mkdir($framework.'/Foundation/Http/Attributes', 0777, true);
mkdir($framework.'/Routing', 0777, true);
mkdir($workspace.'/bootstrap', 0777, true);
file_put_contents($framework.'/Foundation/Http/Attributes/RedirectToRoute.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation\Http\Attributes;
    use Attribute;
    #[Attribute(Attribute::TARGET_CLASS)]
    class RedirectToRoute {
        public function __construct(public string $route) {}
    }
    PHP);
file_put_contents($framework.'/Foundation/Http/Attributes/RedirectTo.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation\Http\Attributes;
    #[\Attribute(\Attribute::TARGET_CLASS)]
    class RedirectTo { public function __construct(public string $url) {} }
    PHP);
$urlGeneratorPath = $framework.'/Routing/UrlGenerator.php';
$urlGenerator = file_get_contents(__DIR__.'/fixtures/analysis/signed-route-UrlGenerator.php.stub');
file_put_contents($urlGeneratorPath, $urlGenerator);
$redirector = <<<'PHP'
    <?php
    namespace Illuminate\Routing;
    class Redirector {
        protected $generator;
        public function __construct(UrlGenerator $generator) {
            $this->generator = $generator;
        }
        public function getUrlGenerator() {
            return $this->generator;
        }
    }
    PHP;
$redirectorPath = $framework.'/Routing/Redirector.php';
file_put_contents($redirectorPath, $redirector);
$request = <<<'PHP'
    <?php
    namespace Illuminate\Foundation\Http;
    use Illuminate\Foundation\Http\Attributes\RedirectToRoute;
    use Illuminate\Routing\UrlGenerator;
    use Illuminate\Routing\Redirector;
    use ReflectionClass;
    class FormRequest {
        protected $redirectRoute;
        protected $redirector;
        protected function getValidatorInstance() {
            $this->configureFromAttributes();
        }
        protected function configureFromAttributes() {
            $reflection = new ReflectionClass($this);
            $redirectToRoute = $reflection->getAttributes(RedirectToRoute::class);
            if ($redirectToRoute !== []) {
                $this->redirectRoute = $redirectToRoute[0]->newInstance()->route;
            }
        }
        protected function failedValidation() {
            throw new \RuntimeException($this->getRedirectUrl());
        }
        protected function getRedirectUrl() {
            $url = $this->redirector->getUrlGenerator();
            return match (true) {
                ! empty($this->redirectRoute) => $url->route($this->redirectRoute),
                default => '/',
            };
        }
        public function setRedirector(Redirector $redirector) {
            $this->redirector = $redirector;
            return $this;
        }
    }
    PHP;
$requestPath = $framework.'/Foundation/Http/FormRequest.php';
file_put_contents($requestPath, $request);
$catalog = [
    'complete' => true,
    'missing-route-resolver' => false,
    'names' => ['home'],
];
$compose = static function (?array $names, array $bindings = []) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => [
            'named-routes' => $names,
            'binding-files' => $bindings,
        ]],
    ], JSON_THROW_ON_ERROR));
};
$compose($catalog);
$cases = [
    'known route' => ['#[RedirectToRoute("home")] class Known extends FormRequest {}', []],
    'missing positional route' => [
        '#[RedirectToRoute("typo")] class Missing extends FormRequest {}',
        ['ichinya/laramago/laramago-missing-named-route'],
    ],
    'missing named route' => [
        '#[RedirectToRoute(route: "typo")] class Named extends FormRequest {}',
        ['ichinya/laramago/laramago-missing-named-route'],
    ],
    'dynamic name deferred' => ['#[RedirectToRoute(Names::MISSING)] class Dynamic extends FormRequest {}', []],
    'empty route skipped by handler' => ['#[RedirectToRoute("")] class EmptyRoute extends FormRequest {}', []],
    'zero route skipped by handler' => ['#[RedirectToRoute("0")] class ZeroRoute extends FormRequest {}', []],
    'custom attribute deferred' => ['#[CustomRedirectToRoute("typo")] class Custom extends FormRequest {}', []],
    'unrelated class deferred' => ['#[RedirectToRoute("typo")] class Unrelated {}', []],
    'override deferred' => [
        '#[RedirectToRoute("typo")] class Override extends FormRequest { protected function getRedirectUrl() { return "/"; } }',
        [],
    ],
    'property override deferred' => [
        '#[RedirectToRoute("typo")] class PropertyOverride extends FormRequest { protected $redirectRoute; }',
        [],
    ],
    'competing attribute deferred' => [
        '#[RedirectToRoute("typo"), RedirectTo("/somewhere")] class Competing extends FormRequest {}',
        [],
    ],
    'native invalid argument retained' => [
        '#[RedirectToRoute(route: 42)] class Invalid extends FormRequest {}',
        ['invalid-argument'],
    ],
];
$source = <<<'PHP'
    <?php
    namespace App;
    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Foundation\Http\Attributes\RedirectToRoute;
    use Illuminate\Foundation\Http\Attributes\RedirectTo;
    #[\Attribute(\Attribute::TARGET_CLASS)]
    class CustomRedirectToRoute { public function __construct(public string $route) {} }
    class Names { const MISSING = 'typo'; }
    PHP;
$lines = [];
foreach ($cases as $label => [$statement, $expected]) {
    $source .= $statement."\n";
    $lines[substr_count($source, "\n")] = [$label, $expected];
}
file_put_contents($workspace.'/cases.php', $source);
$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Foundation/Http/Attributes/RedirectToRoute.php',
            'vendor/laravel/framework/src/Illuminate/Foundation/Http/Attributes/RedirectTo.php',
            'vendor/laravel/framework/src/Illuminate/Foundation/Http/FormRequest.php',
            'vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php',
            'vendor/laravel/framework/src/Illuminate/Routing/Redirector.php',
        ],
    ],
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
];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$analyze = static function (string $label) use ($workspace, $command): array {
    $report = $workspace.'/'.$label.'.json';
    $log = $workspace.'/'.$label.'.log';
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $report, 'w'], 2 => ['file', $log, 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    proc_close($process);
    if (preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($log),
    )) {
        throw new RuntimeException('Worker failed; inspect '.$workspace);
    }

    return json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR)['issues'] ?? [];
};
$issues = $analyze('native');
$actual = [];
$spans = [];
foreach ($issues as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $actual[$line][] = $issue['code'];
    if ($issue['code'] === 'ichinya/laramago/laramago-missing-named-route') {
        $spans[$line][] = substr(
            $source,
            $primary['span']['start']['offset'],
            $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
        );
    }
}
foreach ($lines as $line => [$label, $expected]) {
    $found = $actual[$line] ?? [];
    sort($found);
    sort($expected);
    if ($found !== $expected) {
        throw new RuntimeException(
            $label.': expected '.json_encode($expected).', got '.json_encode($found).'; inspect '.$workspace,
        );
    }
    if (
        in_array('ichinya/laramago/laramago-missing-named-route', $expected, true)
        && ($spans[$line] ?? []) !== ['"typo"']
    ) {
        throw new RuntimeException($label.': warning must point to route literal; inspect '.$workspace);
    }
    unset($actual[$line]);
    echo 'PASS: '.$label."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics: '.json_encode($actual).'; inspect '.$workspace);
}
$compose(null);
$withoutCatalog = $analyze('without-catalog');
if (array_column($withoutCatalog, 'code') !== ['invalid-argument']) {
    throw new RuntimeException('Incomplete route catalog must leave native errors; inspect '.$workspace);
}
echo "PASS: absent catalog leaves native errors\n";
$compose($catalog);
file_put_contents($requestPath, str_replace(
    '$url->route($this->redirectRoute)',
    '$url->to($this->redirectRoute)',
    $request,
));
$changed = $analyze('changed-handler');
if (array_column($changed, 'code') !== ['invalid-argument']) {
    throw new RuntimeException('Changed FormRequest redirect handler must defer; inspect '.$workspace);
}
echo "PASS: changed handler defers\n";
file_put_contents($requestPath, $request);
file_put_contents($redirectorPath, str_replace('return $this->generator;', 'return null;', $redirector));
$changed = $analyze('changed-redirector');
if (array_column($changed, 'code') !== ['invalid-argument']) {
    throw new RuntimeException('Changed Redirector generator must defer; inspect '.$workspace);
}
echo "PASS: changed Redirector defers\n";
file_put_contents($redirectorPath, $redirector);
$changedUrl = str_replace(
    'throw new RouteNotFoundException("Route [{$name}] not defined.");',
    'return $name;',
    $urlGenerator,
);
file_put_contents($urlGeneratorPath, $changedUrl);
$changed = $analyze('changed-url-route');
if (array_column($changed, 'code') !== ['invalid-argument']) {
    throw new RuntimeException('Changed URL generator route lookup must defer; inspect '.$workspace);
}
echo "PASS: changed URL generator route defers\n";
file_put_contents($urlGeneratorPath, $urlGenerator);
file_put_contents(
    $workspace.'/bootstrap/bindings.php',
    '<?php \\app()->bind("url", \\Illuminate\\Routing\\UrlGenerator::class);',
);
$compose($catalog, ['bootstrap/bindings.php']);
$bound = $analyze('custom-url-binding');
if (array_column($bound, 'code') !== ['invalid-argument']) {
    throw new RuntimeException('Configured URL service must defer; inspect '.$workspace);
}
echo "PASS: configured URL service defers\n";
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($iterator as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
}
rmdir($workspace);
