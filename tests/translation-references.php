<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach ([
    'enabled',
    'disabled',
    'native',
    'incomplete',
    'custom',
    'changed-get',
    'class-doc',
    'method-doc',
    'binding',
    'loader-binding',
    'changed-facade',
    'changed-accessor',
    'shadow',
    'malformed-json',
] as $mode) {
    $workspace =
        str_replace('\\', '/', sys_get_temp_dir()).'/laramago translation references '.bin2hex(random_bytes(8));
    foreach ([
        'lang/en',
        'lang/fr',
        'packages/billing/lang/en',
        'packages/billing/lang/fr',
        'lang/vendor/billing/en',
        'app',
        'vendor/laravel/framework/src/Illuminate/Translation',
        'vendor/laravel/framework/src/Illuminate/Support/Facades',
        'vendor/laravel/framework/src/Illuminate/Collections',
    ] as $directory) {
        mkdir($workspace.'/'.$directory, 0777, true);
    }
    $framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
    foreach ([
        'Translator' => 'Translation',
        'NamespacedItemResolver' => 'Support',
        'Lang' => 'Support/Facades',
        'Facade' => 'Support/Facades',
        'Arr' => 'Collections',
    ] as $class => $directory) {
        copy(
            __DIR__.'/fixtures/analysis/translation-reference-'.$class.'.php.stub',
            $framework.'/'.$directory.'/'.$class.'.php',
        );
    }
    file_put_contents($workspace.'/dependencies.php', <<<'PHP'
        <?php
        namespace Illuminate\Contracts\Translation { interface Translator {} interface Loader {} }
        namespace Illuminate\Support\Traits { trait Macroable {} trait ReflectsClosures {} }
        namespace Custom {
            class Translator extends \Illuminate\Translation\Translator {}
            class Lang extends \Illuminate\Support\Facades\Lang {}
        }
        PHP);
    $translator = $framework.'/Translation/Translator.php';
    if ($mode === 'custom') {
        rename($translator, $workspace.'/Translator.php');
    }
    if ($mode === 'changed-get') {
        file_put_contents($translator, str_replace(
            '$locale = $locale ?: $this->locale;',
            '$locale = "fr";',
            file_get_contents($translator),
        ));
    }
    if ($mode === 'class-doc') {
        file_put_contents($translator, str_replace(
            'class Translator',
            '/** @method string get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true) */ class Translator',
            file_get_contents($translator),
        ));
    }
    if ($mode === 'method-doc') {
        file_put_contents($translator, str_replace(
            '@return string|array',
            '@return mixed',
            file_get_contents($translator),
        ));
    }
    if ($mode === 'changed-facade') {
        $path = $framework.'/Support/Facades/Facade.php';
        file_put_contents($path, str_replace(
            'return $instance->$method(...$args);',
            'return "custom";',
            file_get_contents($path),
        ));
    }
    if ($mode === 'changed-accessor') {
        $path = $framework.'/Support/Facades/Lang.php';
        file_put_contents($path, str_replace("return 'translator';", "return 'other';", file_get_contents($path)));
    }
    if ($mode === 'shadow') {
        file_put_contents(
            $workspace.'/shadow.php',
            '<?php namespace Illuminate\Translation; function is_null($value): bool { return true; }',
        );
    }
    file_put_contents(
        $workspace.'/lang/en/messages.php',
        '<?php return ["exists" => "Present", "nested" => ["exists" => "Present"]];',
    );
    file_put_contents($workspace.'/lang/fr/messages.php', '<?php return ["fallback" => "Present"];');
    file_put_contents($workspace.'/lang/en.json', $mode === 'malformed-json' ? '{' : '{"messages.json":"Present"}');
    file_put_contents($workspace.'/packages/billing/lang/en/messages.php', '<?php return ["exists" => "Present"];');
    file_put_contents($workspace.'/packages/billing/lang/fr/messages.php', '<?php return ["fallback" => "Present"];');
    file_put_contents($workspace.'/lang/vendor/billing/en/override.php', '<?php return ["exists" => "Present"];');
    $settings = [
        'reference-catalogs' => [
            'translations' => [
                'complete' => $mode !== 'incomplete',
                'path' => 'lang',
                'namespaces' => ['billing' => 'packages/billing/lang'],
                'locales' => ['en' => ['en', 'fr'], 'fr' => ['fr', 'en']],
            ],
        ],
    ];
    if (in_array($mode, ['binding', 'loader-binding'], true)) {
        $settings['binding-files'] = ['app/bindings.php'];
        file_put_contents(
            $workspace.'/app/bindings.php',
            '<?php app()->bind("'
            .($mode === 'binding' ? 'translator' : 'translation.loader')
            .'", Custom\\Translator::class);',
        );
    }
    file_put_contents($workspace.'/composer.json', json_encode(
        $mode === 'disabled' ? [] : ['extra' => ['laramago' => $settings]],
        JSON_THROW_ON_ERROR,
    ));
    $missing = ['ichinya/laramago/laramago-missing-translation'];
    $on = $mode === 'enabled';
    $cases = [
        ['Lang::get("messages.absent", [], "fr");', $on || $mode === 'malformed-json' ? $missing : []],
        ['$translator->get(locale: "fr", key: "messages.absent");', $on || $mode === 'malformed-json' ? $missing : []],
        ['Lang::get("billing::messages.exists", [], "en");', []],
        ['Lang::get("billing::messages.fallback", [], "en");', []],
        ['Lang::get("billing::override.exists", [], "en");', []],
        ['Lang::get("billing::messages.absent", [], "en");', $on ? $missing : []],
        ['$translator->get("billing::messages.absent", [], "en");', $on ? $missing : []],
        ['Lang::get("billing::messages.absent", [], "en", false);', []],
        ['Lang::has("billing::messages.absent", "en");', []],

        ['Lang::get("messages.exists", [], "en");', []],
        ['Lang::get("messages.nested.exists", [], "en");', []],
        ['Lang::get("messages.fallback", [], "en");', []],
        ['Lang::get("messages.json", [], "en");', []],
        ['Lang::get("A missing phrase", [], "en");', $on ? $missing : []],
        ['$translator->get("A missing phrase", [], "en");', $on ? $missing : []],
        ['Lang::get("messages", [], "en");', []],
        ['Lang::get("messages.absent", [], "en");', $on ? $missing : []],
        ['Lang::get("messages.ab\\x73ent", [], "en");', $on ? $missing : []],
        ['Lang::get("messages." . "absent", [], "en");', []],
        ['Lang::get(TRANSLATION_KEY, [], "en");', []],
        ['Lang::get(locale: "en", key: "messages.absent", fallback: true);', $on ? $missing : []],
        ['$translator->get("messages.absent", [], "en");', $on ? $missing : []],
        ['$translator->get(locale: "en", key: "messages.absent");', $on ? $missing : []],
        ['Lang::get("messages.absent");', []],
        ['Lang::get("messages.absent", [], "de");', []],
        ['Lang::get("messages.absent", [], "0");', []],
        ['Lang::get("messages.absent", [], "");', []],
        ['Lang::get("messages.absent", [], $locale);', []],
        ['Lang::get("messages.fallback", [], "en", false);', []],
        ['Lang::get("messages.absent", [], "en", false);', []],
        ['Lang::get("messages.absent", [], "en", $fallback);', []],
        ['Lang::get(...["messages.absent", [], "en"]);', []],
        ['Lang::get($key, [], "en");', []],
        ['Lang::get("pkg::messages.absent", [], "en");', []],
        ['Lang::has("messages.absent", "en");', []],
        ['Lang::hasForLocale("messages.absent", "en");', []],
        ['$translator->has("messages.absent", "en");', []],
        ['$translator->hasForLocale("messages.absent", "en");', []],
        ['$custom->get("messages.absent", [], "en");', []],
        ['CustomLang::get("messages.absent", [], "en");', []],
        ['$translator->get(42, [], "en");', ['invalid-argument']],
    ];
    $source = "<?php\nuse Illuminate\\Support\\Facades\\Lang;\nuse Illuminate\\Translation\\Translator;\nuse Custom\\Lang as CustomLang;\nconst TRANSLATION_KEY = 'messages.absent';\n";
    $lines = [];
    foreach ($cases as $index => [$body, $expected]) {
        $source .=
            'function scenario'
            .$index
            .'(Translator $translator, \\Custom\\Translator $custom, string $key, string $locale, bool $fallback): void { '
            .$body
            .' }'
            ."\n";
        $lines[substr_count($source, "\n")] = [$body, $expected];
    }
    file_put_contents($workspace.'/cases.php', $source);
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => [
            'paths' => ['cases.php'],
            'includes' => array_values(array_filter([
                'vendor',
                'dependencies.php',
                $mode === 'custom' ? 'Translator.php' : null,
                $mode === 'shadow' ? 'shadow.php' : null,
            ])),
        ],
    ];
    if ($mode !== 'native') {
        $config['extension-hosts'] = [
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
        ];
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
    $log = file_get_contents($workspace.'/stderr.log');
    if (
        $exit !== 1
        || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
    ) {
        throw new RuntimeException('Unexpected worker result; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
        if ($issue['code'] === 'ichinya/laramago/laramago-missing-translation') {
            $body = $lines[$primary['span']['start']['line'] + 1][0];
            $locale = str_contains($body, '"fr"') ? 'fr' : 'en';
            $chain = $locale === 'fr' ? 'fr -> en' : 'en -> fr';
            $expectedNotes = [
                'Catalog JSON lookup: lang/'.$locale.'.json (requested locale only).',
                'Configured PHP locale chain: '
                    .$chain
                    .'; declared in composer.json at '
                    .'extra.laramago.reference-catalogs.translations.locales.'
                    .$locale
                    .'. '
                    .'This describes the catalog contract, not an observed runtime fallback.',
            ];
            if (($issue['notes'] ?? []) !== $expectedNotes) {
                throw new RuntimeException('Incorrect translation provenance; inspect '.$workspace);
            }
        }
    }
    foreach ($lines as $line => [$body, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $mode.' '.$body.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$mode.' '.$body."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics or catalog execution; inspect '.$workspace);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    $resolvedRoot = realpath($workspace);
    foreach ($iterator as $entry) {
        $resolved = realpath($entry->getPathname());
        if (
            $resolvedRoot === false
            || $resolved === false
            || ! str_starts_with($resolved, $resolvedRoot.DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Refusing cleanup outside temporary workspace.');
        }
        $entry->isDir() ? rmdir($resolved) : unlink($resolved);
    }
    rmdir($workspace);
}
