<?php

declare(strict_types=1);

// Native excerpts are covered by fixtures/analysis/named-route-LICENSE.md.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$mode = $argv[1] ?? '';
if ($mode === '--all') {
    foreach ([
        '',
        '--changed-forwarding',
        '--changed-parse',
        '--changed-render-view',
        '--class-doc',
        '--changed-doc',
        '--changed-helper',
        '--shadow-helper',
        '--finder-binding',
        '--no-contract',
        '--incomplete-catalog',
    ] as $configuration) {
        echo 'Configuration: '.($configuration === '' ? 'default' : $configuration)."\n";
        $process = proc_open(
            [PHP_BINARY, __FILE__, $configuration],
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
            $pipes,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start mail rendering configuration '.$configuration);
        }
        fclose($pipes[0]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Mail rendering configuration failed: '.$configuration);
        }
    }
    echo "PASS: all 187 mail rendering checks\n";
    exit(0);
}
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago mail render '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
foreach (['Mail', 'View', 'Collections', 'Contracts/View'] as $directory) {
    mkdir($framework.'/'.$directory, 0777, true);
}
copy(__DIR__.'/fixtures/analysis/mail-render-Mailer.php.stub', $framework.'/Mail/Mailer.php');
copy(__DIR__.'/fixtures/analysis/mail-render-value.php.stub', $framework.'/Collections/helpers.php');
foreach (['Factory', 'ViewName', 'ViewFinderInterface'] as $name) {
    copy(__DIR__.'/fixtures/analysis/view-reference-'.$name.'.php.stub', $framework.'/View/'.$name.'.php');
}
file_put_contents(
    $framework.'/Contracts/View/Factory.php',
    '<?php namespace Illuminate\\Contracts\\View; interface Factory {}',
);
file_put_contents($workspace.'/support.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Traits {trait Macroable {}}
    namespace Illuminate\Contracts\Support {interface Htmlable {public function toHtml();}}
    namespace App {
    class CustomMailer extends \Illuminate\Mail\Mailer {}
    class OtherMailer {public function render(string $view):string{return $view;}}
    }
    PHP);
$changes = [
    '--changed-forwarding' => [
        'Mail/Mailer.php',
        '$this->renderView($view ?: $plain, $data)',
        '$this->renderView("home", $data)',
    ],
    '--changed-parse' => ['Mail/Mailer.php', 'return [$view, null, null];', 'return ["home", null, null];'],
    '--changed-render-view' => [
        'Mail/Mailer.php',
        '$this->views->make($view, $data)',
        '$this->views->make("home", $data)',
    ],
    '--class-doc' => [
        'Mail/Mailer.php',
        'class Mailer',
        '/** @method string render(string|array $view, array $data = []) */ class Mailer',
    ],
    '--changed-doc' => ['Mail/Mailer.php', '@param  string|array  $view', '@param  string|array|int  $view'],
    '--changed-helper' => [
        'Collections/helpers.php',
        'return $value instanceof Closure ? $value(...$args) : $value;',
        'return "home";',
    ],
];
if (isset($changes[$mode])) {
    [$file, $old, $new] = $changes[$mode];
    $text = file_get_contents($framework.'/'.$file);
    if (! str_contains($text, $old)) {
        throw new RuntimeException('Mutation not applied');
    }
    file_put_contents($framework.'/'.$file, str_replace($old, $new, $text));
}
if ($mode === '--shadow-helper') {
    file_put_contents(
        $workspace.'/support.php',
        "\nnamespace Illuminate\\Mail {function value(\$value,...\$args){return 'home';}}",
        FILE_APPEND,
    );
}
$binding = $mode === '--finder-binding';
if ($binding) {
    mkdir($workspace.'/bootstrap');
    file_put_contents($workspace.'/bootstrap/bindings.php', "<?php \\app()->bind('view.finder', \\stdClass::class);");
}
mkdir($workspace.'/resources/views', 0777, true);
file_put_contents($workspace.'/resources/views/home.blade.php', 'Hello');
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'mail-render-contract' => ['native-view-factory' => $mode !== '--no-contract'],
            'reference-catalogs' => [
                'views' => [
                    'complete' => $mode !== '--incomplete-catalog',
                    'paths' => ['resources/views'],
                    'namespaces' => ['billing' => ['resources/views']],
                ],
            ],
            'binding-files' => $binding ? ['bootstrap/bindings.php'] : [],
        ],
    ],
], JSON_THROW_ON_ERROR));
$warn = ['ichinya/laramago/laramago-missing-view'];
$active = $mode === '';
$cases = [
    'native literal missing' => ['$mailer->render("typo");', $active ? $warn : []],
    'native named missing' => ['$mailer->render(data: [], view: "typo");', $active ? $warn : []],
    'native present' => ['$mailer->render("home");', []],
    'namespace missing' => ['$mailer->render("billing::typo");', $active ? $warn : []],
    'namespace present' => ['$mailer->render("billing::home");', []],
    'namespace unknown' => ['$mailer->render("unknown::typo");', []],
    'dynamic deferred' => ['$mailer->render($name);', []],
    'raw array deferred' => ['$mailer->render(["raw"=>"<p>typo</p>"]);', []],
    'html array deferred' => ['$mailer->render(["html"=>"typo"]);', []],
    'empty fallback deferred' => ['$mailer->render("");', []],
    'zero fallback deferred' => ['$mailer->render("0");', []],
    'unpacked deferred' => ['$mailer->render(...["typo"]);', []],
    'callable deferred' => ['$fn=$mailer->render(...);', []],
    'subclass deferred' => ['$custom->render("typo");', []],
    'other class deferred' => ['$other->render("typo");', []],
    'invalid argument retained' => ['$mailer->render(3);', $mode === '--changed-doc' ? [] : ['invalid-argument']],
    'unknown method retained' => ['$mailer->unknownMethod();', ['non-existent-method']],
];
$source = "<?php\nnamespace App;\n";
$lines = [];
foreach ($cases as $label => [$body, $codes]) {
    $source .=
        'function scenario'
        .count($lines)
        .'(string $name, \\Illuminate\\Mail\\Mailer $mailer, CustomMailer $custom, OtherMailer $other): void { '
        .$body
        ." }\n";
    $lines[substr_count($source, "\n")] = [$label, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['vendor', 'support.php']],
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
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
    throw new RuntimeException('Cannot start Mago');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $actual[$line][] = $issue['code'];
    if ($issue['code'] === $warn[0]) {
        $literal = substr(
            $source,
            $primary['span']['start']['offset'],
            $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
        );
        if (! in_array($literal, ['"typo"', '"billing::typo"'], true)) {
            throw new RuntimeException('Expected exact literal span, got '.$literal);
        }
    }
}
foreach ($lines as $line => [$label, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException(
            $label.': expected '.json_encode($expected).' got '.json_encode($codes).'; see '.$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$label."\n";
}
if (
    $actual !== []
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/stderr.log'),
    )
) {
    throw new RuntimeException('Unexpected issues/worker failure; inspect '.$workspace);
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
$resolvedWorkspace = realpath($workspace);
foreach ($iterator as $file) {
    $resolvedFile = realpath($file->getPathname());
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);
