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
    'unasserted-choice',
    'malformed-choice',
    'changed-choice',
    'changed-helper',
    'helper-doc',
    'helper-signature',
    'custom-helper',
    'changed-app',
    'app-doc',
    'custom-app',
    'custom-vendor',
] as $mode) {
    $workspace =
        str_replace('\\', '/', sys_get_temp_dir()).'/laramago translation choice references '.bin2hex(random_bytes(8));
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
        'vendor/laravel/framework/src/Illuminate/Foundation',
    ] as $directory) {
        mkdir($workspace.'/'.$directory, 0777, true);
    }
    $framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
    $helpers = $framework.'/Foundation/helpers.php';
    copy(__DIR__.'/fixtures/analysis/translation-choice-helpers.php.stub', $helpers);
    if ($mode === 'changed-helper') {
        file_put_contents($helpers, str_replace(
            '->choice($key, $number, $replace, $locale)',
            '->get($key, $replace, $locale)',
            file_get_contents($helpers),
        ));
    }
    if ($mode === 'helper-doc') {
        file_put_contents($helpers, str_replace(
            '@param  string  $key',
            '@param  non-empty-string  $key',
            file_get_contents($helpers),
        ));
    }
    if ($mode === 'helper-signature') {
        file_put_contents($helpers, str_replace('$locale = \\null', '$locale = "en"', file_get_contents($helpers)));
    }
    if ($mode === 'changed-app') {
        file_put_contents($helpers, str_replace(
            '->make($abstract, $parameters)',
            '->make("custom", $parameters)',
            file_get_contents($helpers),
        ));
    }
    if ($mode === 'app-doc') {
        file_put_contents($helpers, str_replace(
            '@template TClass of object',
            '@template TClass of \\stdClass',
            file_get_contents($helpers),
        ));
    }
    if (in_array($mode, ['custom-helper', 'custom-app'], true)) {
        $contents = file_get_contents($helpers);
        $split = strpos($contents, '/**', strpos($contents, 'function app('));
        $app = substr($contents, 0, $split);
        $choice = substr($contents, $split);
        file_put_contents($helpers, $mode === 'custom-app' ? "<?php\n".$choice : $app);
        file_put_contents($workspace.'/custom-helpers.php', $mode === 'custom-app' ? $app : "<?php\n".$choice);
    }
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
        namespace ChoiceShadow {
            function trans_choice($key, $number, array $replace = [], $locale = null): string { return 'custom'; }
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
    if ($mode === 'changed-choice') {
        file_put_contents($translator, str_replace(
            '$locale = $this->localeForChoice($key, $locale)',
            '$locale = "other"',
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
        '<?php return ["plural" => "{1} One :name|[2,*] Many :name", "exists" => "Present", "nested" => ["exists" => "Present"]];',
    );
    file_put_contents($workspace.'/lang/fr/messages.php', '<?php return ["fallback" => "Present"];');
    file_put_contents($workspace.'/lang/en.json', $mode === 'malformed-json' ? '{' : '{"messages.json":"Present"}');
    file_put_contents($workspace.'/packages/billing/lang/en/messages.php', '<?php return ["exists" => "Present"];');
    file_put_contents($workspace.'/packages/billing/lang/fr/messages.php', '<?php return ["fallback" => "Present"];');
    file_put_contents($workspace.'/lang/vendor/billing/en/override.php', '<?php return ["exists" => "Present"];');
    file_put_contents($workspace.'/lang/fr.json', '{"messages.fallbackjson":"Fallback JSON"}');
    file_put_contents(
        $workspace.'/lang/en/dynamic.php',
        '<?php throw new \RuntimeException("Translation files must not execute"); return ["exists" => "Dynamic"];',
    );
    $settings = [
        'reference-catalogs' => [
            'translations' => [
                'complete' => $mode !== 'incomplete',
                'path' => 'lang',
                'namespaces' => ['billing' => 'packages/billing/lang'],
                'locales' => ['en' => ['en', 'fr'], 'fr' => ['fr', 'en']],
                'choice-locales' => $mode === 'unasserted-choice'
                    ? []
                    : ['en' => $mode === 'malformed-choice' ? ['en', 'unknown'] : ['en', 'fr'], 'fr' => ['fr', 'en']],
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
    $on = in_array(
        $mode,
        [
            'enabled',
            'custom-vendor',
            'changed-helper',
            'helper-doc',
            'helper-signature',
            'custom-helper',
            'changed-app',
            'app-doc',
            'custom-app',
        ],
        true,
    );
    $helperOn = in_array($mode, ['enabled', 'custom-vendor'], true);
    $cases = [
        ['Lang::choice("messages.absent", 2, [], "en");', $on ? $missing : []],
        ['$translator->choice(locale: "en", number: 2, key: "messages.absent");', $on ? $missing : []],
        ['Lang::choice("messages.exists", 2, [], "en");', []],
        ['Lang::choice("messages.plural", 2, [], "en");', []],
        ['Lang::choice("dynamic.absent", 2, [], "en");', []],
        ['Lang::choice("messages.fallback", 2, [], "en");', []],
        ['Lang::choice("messages.fallbackjson", 2, [], "en");', []],
        ['Lang::choice("billing::messages.absent", 2, [], "en");', $on ? $missing : []],
        ['Lang::choice("billing::messages.exists", 2, [], "en");', []],
        ['Lang::choice("billing::messages.fallback", 2, [], "en");', []],
        ['Lang::choice("messages.absent", 2);', []],
        ['Lang::choice("messages.absent", 2, [], $locale);', []],
        ['Lang::choice($key, 2, [], "en");', []],
        ['Lang::choice("messages.absent", 2, [], "de");', []],
        ['Lang::choice("messages.absent", 2, [], "0");', []],
        ['Lang::choice("messages.absent", 2, [], "");', []],
        ['$custom->choice("messages.absent", 2, [], "en");', []],
        ['CustomLang::choice("messages.absent", 2, [], "en");', []],
        ['Lang::choice(...["messages.absent", 2, [], "en"]);', []],
        ['Lang::choice(...);', ['unused-statement']],
        ['$translator->get(42, [], "en");', ['invalid-argument']],
        ['trans_choice("messages.absent", 2, [], "en");', $helperOn ? $missing : []],
        ['\\trans_choice(locale: "en", number: 2, key: "messages.absent");', $helperOn ? $missing : []],
        ['pluralize("billing::messages.absent", 2, locale: "en");', $helperOn ? $missing : []],
        ['TRANS_CHOICE("messages.absent", 2, locale: "en");', $helperOn ? $missing : []],
        ['trans_choice("messages.exists", 2, locale: "en");', []],
        ['trans_choice("messages.json", 2, locale: "en");', []],
        ['trans_choice("messages.fallback", 2, locale: "en");', []],
        ['trans_choice("messages.fallbackjson", 2, locale: "en");', []],
        ['trans_choice("billing::messages.fallback", 2, locale: "en");', []],
        ['trans_choice("messages.plural", 2, locale: "en");', []],
        ['trans_choice("dynamic.absent", 2, locale: "en");', []],
        ['trans_choice("messages.absent", 2);', []],
        ['trans_choice("messages.absent", 2, locale: null);', []],
        ['trans_choice("messages.absent", 2, locale: "");', []],
        ['trans_choice("messages.absent", 2, locale: "0");', []],
        ['trans_choice("messages.absent", 2, locale: "de");', []],
        ['trans_choice("messages.absent", 2, locale: $locale);', []],
        ['trans_choice($key, 2, locale: "en");', $mode === 'helper-doc' ? ['possibly-invalid-argument'] : []],
        ['trans_choice(...["messages.absent", 2, [], "en"]);', []],
        ['trans_choice(...);', ['unused-statement']],
        ['trans_choice(42, 2, locale: "en");', ['invalid-argument']],
        ['\\ChoiceShadow\\trans_choice("messages.absent", 2, locale: "en");', []],
    ];
    $source = "<?php\nnamespace {\nuse Illuminate\\Support\\Facades\\Lang;\nuse Illuminate\\Translation\\Translator;\nuse Custom\\Lang as CustomLang;\nuse function trans_choice as pluralize;\nconst TRANSLATION_KEY = 'messages.absent';\n";
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
    $source .= "}\nnamespace ChoiceFallback {\n";
    $source .= "function check(): void { trans_choice('messages.absent', 2, locale: 'en'); }\n";
    $lines[substr_count($source, "\n")] = ['namespaced native fallback', $helperOn ? $missing : []];
    $source .= "}\nnamespace ChoiceShadow {\n";
    $source .= "function check(): void { trans_choice('messages.absent', 2, locale: 'en'); }\n";
    $lines[substr_count($source, "\n")] = ['namespaced local helper', []];
    $source .= "}\nnamespace ChoiceImported {\nuse function ChoiceShadow\\trans_choice as pluralize;\n";
    $source .= "function check(): void { pluralize('messages.absent', 2, locale: 'en'); }\n";
    $lines[substr_count($source, "\n")] = ['imported local helper', []];
    $source .= "}\n";
    $vendor = 'vendor';
    if ($mode === 'custom-vendor') {
        rename($workspace.'/vendor', $workspace.'/custom dependencies');
        $vendor = 'custom dependencies';
    }
    file_put_contents($workspace.'/cases.php', $source);
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => [
            'paths' => ['cases.php'],
            'includes' => array_values(array_filter([
                $vendor,
                'dependencies.php',
                in_array($mode, ['custom-helper', 'custom-app'], true) ? 'custom-helpers.php' : null,
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
            if (! str_contains(
                implode(' ', $issue['notes'] ?? []),
                'Explicit exhaustive effective choice locales: en, fr',
            )) {
                throw new RuntimeException('Missing explicit choice contract provenance; inspect '.$workspace);
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
