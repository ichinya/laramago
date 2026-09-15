<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago validation '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/validation.php.stub', $workspace.'/requests.php');
file_put_contents(
    $workspace.'/bootstrap.php',
    '<?php throw new RuntimeException("Do not bootstrap the application.");',
);
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['requests.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 3,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$cases = [
    'all validated data' => ['return $request->validated();', 'array', []],
    'explicit null key' => ['return $request->validated(null);', 'array', []],
    'named null key' => ['return $request->validated(key: null);', 'array', []],
    'default without key' => ['return $request->validated(default: "unused");', 'array', []],
    'null key with default' => ['return $request->validated(null, "unused");', 'array', []],
    'named arguments reordered' => ['return $request->validated(default: [], key: null);', 'array', []],
    'null variable key' => ['$key = null; return $request->validated($key);', 'array', []],
    'narrowed nullable key' => [
        'if ($nullableKey !== null) { return []; } return $request->validated($nullableKey);',
        'array',
        [],
    ],
    'case insensitive method' => ['return $request->VALIDATED();', 'array', []],
    'base form request' => ['return $base->validated();', 'array', []],
    'inherited request' => ['return (new ChildRecordRequest)->validated();', 'array', []],
    'array argument' => ['acceptArray($request->validated());', 'void', []],
    'integer keys remain possible' => [
        'acceptNamedFields($request->validated());',
        'void',
        ['possibly-invalid-argument'],
    ],
    'array read leaves values unknown' => ['acceptString($request->validated()["title"]);', 'void', ['mixed-argument']],
    'array value remains mixed' => [
        '$data = $request->validated(); acceptInt($data["count"]);',
        'void',
        ['mixed-argument'],
    ],
    'array is not string' => ['acceptString($request->validated());', 'void', ['invalid-argument']],
    'array is not object' => ['$request->validated()->missing();', 'void', ['invalid-method-access']],
    'array is not nullable' => ['return $request->validated() === null;', 'bool', ['redundant-comparison']],
    'string key remains unknown' => ['acceptArray($request->validated("title"));', 'void', ['mixed-argument']],
    'integer key remains unknown' => ['acceptArray($request->validated(0));', 'void', ['mixed-argument']],
    'dot key remains unknown' => ['acceptArray($request->validated("profile.title"));', 'void', ['mixed-argument']],
    'path array remains unknown' => [
        'acceptArray($request->validated(["profile", "title"]));',
        'void',
        ['mixed-argument'],
    ],
    'named field remains unknown' => ['acceptArray($request->validated(key: "title"));', 'void', ['mixed-argument']],
    'default does not type field' => [
        'acceptString($request->validated("title", "fallback"));',
        'void',
        ['mixed-argument'],
    ],
    'callback default does not type field' => [
        'acceptString($request->validated("title", fn (): string => "fallback"));',
        'void',
        ['mixed-argument'],
    ],
    'unknown key remains unknown' => [
        'acceptArray($request->validated($unknown));',
        'void',
        ['mixed-argument', 'mixed-argument'],
    ],
    'nullable key keeps unknown branch' => [
        'acceptArray($request->validated($nullableKey));',
        'void',
        ['mixed-argument'],
    ],
    'unpacked null stays conservative' => ['acceptArray($request->validated(...[null]));', 'void', ['mixed-argument']],
    'unpacked empty stays conservative' => ['acceptArray($request->validated(...[]));', 'void', ['mixed-argument']],
    'invalid key type' => ['$request->validated(new stdClass);', 'void', ['invalid-argument']],
    'invalid named argument' => ['$request->validated(typo: null);', 'void', ['invalid-named-argument']],
    'extra argument' => ['$request->validated(null, null, null);', 'void', ['too-many-arguments']],
    'method typo' => ['$request->validateed();', 'void', ['non-existent-method']],
    'declared override' => ['return (new OverrideRequest)->validated();', 'string', []],
    'inherited override' => ['return (new InheritedOverrideRequest)->validated();', 'string', []],
    'declared shape preserved' => ['return (new ShapeRequest)->validated()["title"];', 'string', []],
    'trait override' => ['return (new TraitRequest)->validated();', 'int', []],
    'documented request preserved' => ['return (new DocumentedRequest)->validated();', 'string', []],
    'inherited documentation preserved' => ['return (new InheritedDocumentedRequest)->validated();', 'string', []],
    'documented result is not array' => [
        'acceptArray((new DocumentedRequest)->validated());',
        'void',
        ['invalid-argument'],
    ],
    'custom validator property deferred' => [
        'acceptArray((new ShadowValidatorRequest)->validated());',
        'void',
        ['mixed-argument'],
    ],
    'inherited validator property deferred' => [
        'acceptArray((new InheritedShadowValidatorRequest)->validated());',
        'void',
        ['mixed-argument'],
    ],
    'unrelated method' => ['return (new UnrelatedValidation)->validated();', 'string', []],
    'first class validated' => ['return $request->validated(...);', 'Closure', []],
    'ordinary input unchanged' => ['acceptArray($request->input());', 'void', ['mixed-argument']],
    'request property unchanged' => [
        'acceptString($request->title);',
        'void',
        ['mixed-argument', 'non-documented-property'],
    ],
    'rules do not cast fields' => [
        'acceptInt((new DeclaredRulesRequest)->validated("count"));',
        'void',
        ['mixed-argument'],
    ],
    'rules do not type array entries' => [
        'acceptInt((new DeclaredRulesRequest)->validated()["count"]);',
        'void',
        ['mixed-argument'],
    ],
];
function check_validation(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Foundation\Http\FormRequest;
        function acceptArray(array $value): void {}
        function acceptString(string $value): void {}
        function acceptInt(int $value): void {}
        /** @param array<string, mixed> $value */
        function acceptNamedFields(array $value): void {}
        class InlineRequest extends StoreRecordRequest {
            public function data(): array { return $this->validated(); }
        }

        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .=
            'function scenario'
            .count($lines)
            .'(StoreRecordRequest $request, FormRequest $base, mixed $unknown, ?string $nullableKey) { '
            .$body
            .' }'
            ."\n";
        $lines[substr_count($source, "\n")] = [$name, $codes];
    }
    file_put_contents($workspace.'/cases.php', $source);
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
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '
        .$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics outside validation scenarios; inspect '.$workspace);
    }
}

check_validation($cases, $command, $workspace);

// Preserve more precise contracts if the installed framework supplies them.
$framework = file_get_contents($workspace.'/requests.php');
$framework = str_replace('@return mixed', '@return array{title: string}', $framework);
file_put_contents($workspace.'/requests.php', $framework);
check_validation(
    [
        'framework shape preserved' => ['return $request->validated()["title"];', 'string', []],
        'framework shape rejects wrong value type' => [
            'acceptInt($request->validated()["title"]);',
            'void',
            ['invalid-argument'],
        ],
    ],
    $command,
    $workspace,
);

$resolvedWorkspace = realpath($workspace);
foreach (glob($workspace.'/*') ?: [] as $file) {
    $resolvedFile = realpath($file);
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    unlink($resolvedFile);
}
rmdir($workspace);
