<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\ViteEnvironmentReferences;

require dirname(__DIR__).'/vendor/autoload.php';

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-vite-env-'.bin2hex(random_bytes(8));
mkdir($fixture.'/resources', 0777, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$source = <<<'JS'
    #!/usr/bin/env node
    const api = import.meta.env.VITE_API_URL;
    const mode = import /* safe gap */ . meta . env . MODE;
    const money = import.meta.env.$SPECIAL;
    const string = "import.meta.env.STRING_FAKE";
    const single = 'import.meta.env.SINGLE_FAKE';
    // import.meta.env.LINE_FAKE
    /* import.meta.env.BLOCK_FAKE */
    const shadow = object.import.meta.env.SHADOW_FAKE;
    JS;
file_put_contents($fixture.'/resources/app.js', $source);
file_put_contents($fixture.'/resources/types.ts', "const flag: boolean = import.meta.env.VITE_FLAG;\r\n");
file_put_contents(
    $fixture.'/resources/hostile.js',
    <<<'JS'
        const chain = object.import.meta.env.PROPERTY_CHAIN_FAKE;
        const optional = object?.import.meta.env.OPTIONAL_CHAIN_FAKE;
        const suffix = suffiximport.meta.env.KEYWORD_SUFFIX_FAKE;
        <!-- import.meta.env.HTML_OPEN_FAKE
        --> import.meta.env.HTML_CLOSE_FAKE
        const visible = import.meta.env.VISIBLE;
        JS,
);
file_put_contents(
    $fixture.'/resources/escaped.js',
    <<<'JS'
        const \u0069mport = value;
        const hidden = import.meta.env.AFTER_ESCAPED_IDENTIFIER;
        JS,
);
file_put_contents($fixture.'/resources/computed.js', "const key = import.meta.env['VITE_COMPUTED'];\n");
file_put_contents(
    $fixture.'/resources/template.js',
    'const value = `outer ${`inner ${import.meta.env.TEMPLATE_FAKE}`} tail`; '
    ."const after = import.meta.env.AFTER_TEMPLATE;\n",
);
file_put_contents(
    $fixture.'/resources/regex.js',
    "const pattern = /import.meta.env.REGEX_FAKE/; const after = import.meta.env.AFTER_REGEX;\n",
);
file_put_contents(
    $fixture.'/resources/broken.js',
    "const before = import.meta.env.BEFORE_BROKEN; const broken = 'PRIVATE_LEXICAL_TOKEN",
);
file_put_contents(
    $fixture.'/resources/jsx-in-js.js',
    "const element = <section>import.meta.env.JSX_TEXT_FAKE</section>;\n"."const after = import.meta.env.AFTER_JSX;\n",
);
file_put_contents($fixture.'/resources/component.tsx', 'const hidden = import.meta.env.TSX_UNSUPPORTED;');
file_put_contents($fixture.'/.env', 'VITE_API_URL=private-value');
file_put_contents($fixture.'/bootstrap.php', "<?php file_put_contents(__DIR__.'/executed', 'yes');");

$exporter = new ViteEnvironmentReferences;
try {
    foreach (['import.meta.env.PREFIX\\u0061;', 'import.meta.env.PREFIX'."\xC3\xA9".';'] as $partialName) {
        file_put_contents($fixture.'/resources/partial-name.js', $partialName);
        $partial = $exporter->export($fixture, ['resources/partial-name.js']);
        $check(
            $partial['references'] === [] && $partial['uncertainties'] !== [],
            'Unsupported identifier tails never export a truncated name.',
        );
    }
    $result = $exporter->export($fixture, ['resources/app.js', 'resources/types.ts']);
    $check($result['schemaVersion'] === 1, 'Versioned contract.');
    $check(
        $result['scope'] === [
            'kind' => 'vite-environment-references',
            'evidence' => 'source-lexer',
            'syntax' => 'direct-dot-property',
            'completeness' => 'non-exhaustive',
        ],
        'Scope discloses lexical direct-property evidence.',
    );
    $check($result['errors'] === [] && $result['uncertainties'] === [], 'Supported sources scan completely.');
    $check(
        array_column($result['references'], 'name') === ['VITE_API_URL', 'MODE', '$SPECIAL', 'VITE_FLAG'],
        'Only direct import.meta.env property tokens are exported in source order.',
    );
    foreach ($result['references'] as $reference) {
        $contents = file_get_contents($reference['file']);
        $check(
            substr($contents, $reference['start'], $reference['end'] - $reference['start']) === $reference['name'],
            'Reference uses an exact half-open name span.',
        );
        $check($reference['contentHash'] === hash('sha256', $contents), 'Reference records the exact source hash.');
        $check($reference['confidence'] === 'completion-candidate', 'Completion-only confidence is explicit.');
    }
    $check($result['references'][3]['line'] === 1, 'CRLF source line is preserved.');
    $check(
        ! str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'private-value'),
        'No environment value is exported.',
    );
    $check(! file_exists($fixture.'/executed'), 'Project PHP and Composer bootstrap are not executed.');

    $hostile = $exporter->export($fixture, ['resources/hostile.js']);
    $check(
        array_column($hostile['references'], 'name') === ['VISIBLE'],
        'Property chains, optional property chains, identifier suffixes, and HTML comments do not impersonate import.meta.',
    );
    $escaped = $exporter->export($fixture, ['resources/escaped.js']);
    $check(
        $escaped['references'] === [],
        'Escaped identifiers stop scanning before their context can be misclassified.',
    );
    $check(
        $escaped['truncationReasons'] === ['unsupported-lexical-token'],
        'Escaped identifier uncertainty is explicit.',
    );

    $computed = $exporter->export($fixture, ['resources/computed.js']);
    $check($computed['references'] === [], 'Computed access is outside the direct-property subset.');
    $check(
        array_column($computed['uncertainties'], 'code') === ['unsupported-environment-access'],
        'Computed access reports uncertainty.',
    );
    $check($computed['truncated'], 'Uncertainty makes completeness false.');

    $template = $exporter->export($fixture, ['resources/template.js']);
    $check($template['references'] === [], 'Template text and all following source are not scanned as references.');
    $check($template['truncationReasons'] === ['template-literal'], 'Template stop reason is explicit.');

    $regex = $exporter->export($fixture, ['resources/regex.js']);
    $check($regex['references'] === [], 'Regular-expression text and following source are not scanned as references.');
    $check($regex['truncationReasons'] === ['ambiguous-slash-token'], 'Ambiguous slash stop reason is explicit.');

    $jsx = $exporter->export($fixture, ['resources/jsx-in-js.js']);
    $check($jsx['references'] === [], 'Possible JSX text and following source are not scanned as references.');
    $check($jsx['truncationReasons'] === ['ambiguous-angle-token'], 'Ambiguous angle stop reason is explicit.');

    $broken = $exporter->export($fixture, ['resources/broken.js']);
    $check($broken['references'] === [], 'Lexically malformed source exports no stale candidates.');
    $check(
        array_column($broken['errors'], 'code') === ['lexical-failure'],
        'Malformed source reports a generic error.',
    );
    $check(
        ! str_contains(json_encode($broken, JSON_THROW_ON_ERROR), 'PRIVATE_LEXICAL_TOKEN'),
        'Lexical errors do not expose source text.',
    );

    $bad = $exporter->export($fixture, [
        'resources/missing.js',
        'resources/component.tsx',
        '../outside.js',
        '/absolute.js',
        'php://filter.js',
        "bad\0.js",
    ]);
    $check(
        array_column($bad['errors'], 'code') === [
            'unreadable-source',
            'unsupported-source-format',
            'invalid-source-path',
            'invalid-source-path',
            'invalid-source-path',
            'invalid-source-path',
        ],
        'Missing, unsupported, and unsafe paths are rejected.',
    );
    $duplicate = $exporter->export($fixture, ['resources/app.js', 'resources/./app.js']);
    $check(count($duplicate['references']) === 3, 'Canonical duplicate sources are read once.');
    $check(
        $exporter->export($fixture, array_fill(0, 257, 'resources/app.js'))['truncationReasons'] === ['file-limit'],
        'Explicit source count is bounded.',
    );
    file_put_contents($fixture.'/resources/large.js', str_repeat(' ', (1024 * 1024) + 1));
    $large = $exporter->export($fixture, ['resources/large.js']);
    $check(
        $large['truncated'] && array_column($large['errors'], 'code') === ['source-byte-limit'],
        'Reads are bounded.',
    );

    $invalidList = false;
    try {
        $exporter->export($fixture, ['source' => 'resources/app.js']);
    } catch (InvalidArgumentException) {
        $invalidList = true;
    }
    $check($invalidList, 'Associative source selections are rejected.');
} finally {
    foreach (glob($fixture.'/resources/*') ?: [] as $path) {
        unlink($path);
    }
    foreach (['.env', 'bootstrap.php', 'executed'] as $name) {
        if (is_file($fixture.'/'.$name)) {
            unlink($fixture.'/'.$name);
        }
    }
    rmdir($fixture.'/resources');
    rmdir($fixture);
}

echo "Vite environment references: {$checks} checks passed.\n";
