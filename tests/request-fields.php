<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago request fields '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/request-fields.php.stub', $workspace.'/requests.php');
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
            'command' => [
                PHP_BINARY,
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 3,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$cases = [
    'required string entry' => ['return $request->validated()["title"];', 'string', []],
    'common string constraints' => ['return $request->validated("email");', 'string', []],
    'invalid property assignment' => [
        '$target = new FieldTarget; $target->title = $request->validated("tags");',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'required string selected key' => ['return $request->validated("title");', 'string', []],
    'named selected key' => ['return $request->validated(default: 9, key: "title");', 'string', []],
    'explicit null shape' => ['return $request->validated(key: null)["title"];', 'string', []],
    'inherited rules' => ['return (new InheritedFieldsRequest)->validated("title");', 'string', []],
    'wrong entry type' => ['acceptInt($request->validated()["title"]);', 'void', ['invalid-argument']],
    'wrong selected type' => ['acceptInt($request->validated("title"));', 'void', ['invalid-argument']],
    'optional selected key includes null' => ['return $request->validated("nickname");', '?string', []],
    'optional selected key is not required' => [
        'acceptString($request->validated("nickname"));',
        'void',
        ['possibly-null-argument'],
    ],
    'optional shape cannot promise presence' => [
        'acceptRequiredNickname($request->validated());',
        'void',
        ['possibly-invalid-argument'],
    ],
    'optional offset fallback' => ['return $request->validated()["nickname"] ?? "guest";', 'string', []],
    'optional selected default' => ['return $request->validated("nickname", "guest");', 'string', []],
    'optional selected integer default' => ['return $request->validated("nickname", 7);', 'int|string', []],
    'nullable present field' => ['return $request->validated()["description"];', '?string', []],
    'nullable is not string' => [
        'acceptString($request->validated("description"));',
        'void',
        ['possibly-null-argument'],
    ],
    'present nullable ignores default' => ['return $request->validated("description", 7);', '?string', []],
    'nullable optional keeps null with default' => ['return $request->validated("note", 7);', 'int|string|null', []],
    'required nullable excludes null' => ['return $request->validated("required_nullable");', 'string', []],
    'sometimes required still optional' => ['return $request->validated("sometimes_required");', '?string', []],
    'boolean includes original representation' => ['return $request->validated("enabled");', 'bool|int|string', []],
    'boolean does not cast input' => [
        'acceptBool($request->validated("enabled"));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'optional boolean includes blank strings' => [
        'return $request->validated("optional_enabled");',
        'bool|int|string|null',
        [],
    ],
    'numeric accepts numbers and strings' => ['return $request->validated("amount");', 'int|float|string', []],
    'numeric is not a cast' => ['acceptInt($request->validated("amount"));', 'void', ['possibly-invalid-argument']],
    'integer remains unknown' => ['acceptInt($request->validated("count"));', 'void', ['mixed-argument']],
    'required array entry' => ['return $request->validated()["tags"];', 'array', []],
    'array is not string' => ['acceptString($request->validated("tags"));', 'void', ['invalid-argument']],
    'array element stays mixed' => ['acceptString($request->validated("tags")[0]);', 'void', ['mixed-argument']],
    'optional array can be blank string' => ['return $request->validated("optional_tags");', 'array|string|null', []],
    'optional array cannot be assumed array' => [
        'acceptArray($request->validated("optional_tags", []));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'filled array cannot be blank string' => ['return $request->validated("filled_tags");', '?array', []],
    'unknown selected key' => ['acceptString($request->validated("unknown"));', 'void', ['mixed-argument']],
    'unknown shape key' => ['acceptString($request->validated()["unknown"]);', 'void', ['mixed-argument']],
    'dynamic key' => ['acceptString($request->validated($nullableKey));', 'void', ['mixed-argument']],
    'callback default deferred' => [
        'acceptString($request->validated("nickname", fn (): string => "guest"));',
        'void',
        ['mixed-argument'],
    ],
    'dynamic rules deferred' => [
        'acceptString((new DynamicFieldsRequest)->validated("title"));',
        'void',
        ['mixed-argument'],
    ],
    'object rules deferred' => [
        'acceptString((new ObjectRuleFieldsRequest)->validated("title"));',
        'void',
        ['mixed-argument'],
    ],
    'unknown rules deferred' => [
        'acceptString((new UnknownRuleFieldsRequest)->validated("title"));',
        'void',
        ['mixed-argument'],
    ],
    'excluded rules deferred' => [
        'acceptString((new ExcludedFieldsRequest)->validated("title"));',
        'void',
        ['mixed-argument'],
    ],
    'nested rules deferred' => [
        'acceptString((new NestedFieldsRequest)->validated("profile.title"));',
        'void',
        ['mixed-argument'],
    ],
    'custom validator hook deferred' => [
        'acceptString((new HookFieldsRequest)->validated("title"));',
        'void',
        ['mixed-argument'],
    ],
    'custom preparation hook deferred' => [
        'acceptString((new PreparedFieldsRequest)->validated("title"));',
        'void',
        ['mixed-argument'],
    ],
    'validator property shadow deferred' => [
        'acceptString((new ShadowFieldsRequest)->validated("title"));',
        'void',
        ['mixed-argument'],
    ],
    'method override preserved' => ['return (new OverrideFieldsRequest)->validated();', 'int', []],
    'documented method preserved' => ['return (new DocumentedFieldsRequest)->validated();', 'int', []],
    'raw input stays unknown' => ['acceptString($request->input("title"));', 'void', ['mixed-argument']],
    'magic field stays unknown' => [
        'acceptString($request->title);',
        'void',
        ['mixed-argument', 'non-documented-property'],
    ],
    'validated shape is not object' => ['$request->validated()->missing();', 'void', ['invalid-method-access']],
    'unpacked selection deferred' => ['acceptString($request->validated(...["title"]));', 'void', ['mixed-argument']],
    'wrong named argument preserved' => ['$request->validated(typo: "title");', 'void', ['invalid-named-argument']],
];
function check_request_fields(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Foundation\Http\FormRequest;
        function acceptArray(array $value): void {}
        function acceptString(string $value): void {}
        function acceptInt(int $value): void {}
        function acceptBool(bool $value): void {}
        /** @param array{nickname: string, ...} $value */
        function acceptRequiredNickname(array $value): void {}
        /** @param array<string, mixed> $value */
        function acceptNamedFields(array $value): void {}
        class InlineRequest extends LiteralFieldsRequest {
            public function data(): array { return $this->validated(); }
        }

        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .=
            'function scenario'
            .count($lines)
            .'(LiteralFieldsRequest $request, FormRequest $base, mixed $unknown, ?string $nullableKey) { '
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

check_request_fields($cases, $command, $workspace);
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
