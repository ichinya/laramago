<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago request nested '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/request-fields.php.stub', $workspace.'/requests.php');
copy(__DIR__.'/fixtures/analysis/request-nested.php.stub', $workspace.'/nested.php');
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
    'source' => ['paths' => ['cases.php'], 'includes' => ['requests.php', 'nested.php']],
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
    'nested required selector' => ['return $request->validated("profile.name");', 'string', []],
    'nested required shape' => ['return $request->validated()["profile"]["name"];', 'string', []],
    'nested wrong type' => ['acceptInt($request->validated("profile.name"));', 'void', ['invalid-argument']],
    'nested optional selector' => ['return $request->validated("profile.alias");', '?string', []],
    'nested optional default' => ['return $request->validated("profile.alias", 8);', 'int|string', []],
    'nested nullable selector' => ['return $request->validated("profile.note", 8);', '?string', []],
    'nested depth' => ['return $request->validated()["profile"]["options"]["mode"];', 'string', []],
    'optional parent default' => ['return $request->validated("preferences.theme", "plain");', 'string', []],
    'optional parent stays optional' => [
        'acceptString($request->validated("preferences.theme"));',
        'void',
        ['possibly-null-argument'],
    ],
    'nullable parent default' => ['return $request->validated("nullable.label", 8);', 'int|string', []],
    'parent required input may be absent from output' => [
        'acceptArray($request->validated("emptyable"));',
        'void',
        ['possibly-null-argument'],
    ],
    'unknown nested key stays mixed' => [
        'acceptString($request->validated()["profile"]["extra"]);',
        'void',
        ['mixed-argument'],
    ],
    'unknown nested selector stays mixed' => [
        'acceptString($request->validated("profile.extra"));',
        'void',
        ['mixed-argument'],
    ],
    'wildcard element shape' => [
        'foreach ($request->validated("rows", []) as $row) { acceptString($row["name"]); }',
        'void',
        [],
    ],
    'wildcard optional field' => [
        'foreach ($request->validated("rows", []) as $row) { acceptString($row["label"] ?? "none"); }',
        'void',
        [],
    ],
    'wildcard element wrong type' => [
        'foreach ($request->validated("rows", []) as $row) { acceptInt($row["name"]); }',
        'void',
        ['invalid-argument'],
    ],
    'wildcard unknown element key stays mixed' => [
        'foreach ($request->validated("rows", []) as $row) { acceptString($row["extra"]); }',
        'void',
        ['mixed-argument'],
    ],
    'wildcard selector deferred' => ['acceptArray($request->validated("rows.*.name"));', 'void', ['mixed-argument']],
    'wildcard index not guaranteed' => [
        'acceptString($request->validated("rows.0.name"));',
        'void',
        ['mixed-argument'],
    ],
    'wildcard numeric boolean representation' => [
        'foreach ($request->validated("flags", []) as $flag) { acceptBool($flag); }',
        'void',
        ['possibly-invalid-argument'],
    ],
    'wildcard integer not cast' => [
        'foreach ($request->validated("codes", []) as $code) { acceptInt($code); }',
        'void',
        ['mixed-argument', 'mixed-assignment'],
    ],
    'wildcard empty result not nonempty' => [
        'acceptNonEmpty($request->validated("rows", []));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'wildcard keys are not a list' => [
        'acceptList($request->validated("rows", []));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'ISO date format proves string' => ['return $request->validated("start_on");', 'string', []],
    'numeric date format keeps input number' => [
        'acceptString($request->validated("year"));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'Rule in alone does not prove string' => [
        'acceptString($request->validated("interval"));',
        'void',
        ['mixed-argument'],
    ],
    'Rule in with string proof' => ['return $request->validated("choice");', 'string', []],
    'Rule in integer values are not cast' => [
        'acceptInt($request->validated("numeric_choice"));',
        'void',
        ['mixed-argument'],
    ],
    'Rule in optional keeps null' => ['return $request->validated("optional_choice");', '?string', []],
    'uuid proves string' => ['return $request->validated("identifier");', 'string', []],
    'ulid proves string' => ['return $request->validated("sortable_id");', 'string', []],
    'alpha proves string' => ['return $request->validated("letters");', 'string', []],
    'alpha num keeps numeric representation' => [
        'acceptString($request->validated("alphanumeric"));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'url proves string' => ['return $request->validated("destination");', 'string', []],
    'email permits stringable values' => ['acceptString($request->validated("email"));', 'void', ['mixed-argument']],
    'json retains unproven representation' => [
        'acceptString($request->validated("json"));',
        'void',
        ['mixed-argument'],
    ],
    'IP retains unproven representation' => [
        'acceptString($request->validated("address"));',
        'void',
        ['mixed-argument'],
    ],
    'unknown nested rule deferred' => [
        'acceptString((new UnknownNestedInputRequest)->validated("profile.name"));',
        'void',
        ['mixed-argument'],
    ],
    'conditional nested rule deferred' => [
        'acceptString((new ConditionalNestedInputRequest)->validated("profile.name"));',
        'void',
        ['mixed-argument'],
    ],
    'missing wildcard parent deferred' => [
        'acceptString((new MissingWildcardParentRequest)->validated("rows.0.name"));',
        'void',
        ['mixed-argument'],
    ],
    'overlapping wildcard rule deferred' => [
        'acceptString((new OverlappingWildcardRequest)->validated("rows.name"));',
        'void',
        ['mixed-argument'],
    ],
    'dynamic Rule in deferred' => [
        'acceptString((new DynamicInRequest)->validated("choice"));',
        'void',
        ['mixed-argument'],
    ],
    'custom validation hook deferred' => [
        'acceptString((new CustomNestedInputRequest)->validated("profile.name"));',
        'void',
        ['mixed-argument'],
    ],
    'native method override preserved' => ['return (new OverrideNestedInputRequest)->validated();', 'int', []],
    'documented override preserved' => ['return (new DocumentedNestedInputRequest)->validated();', 'int', []],
    'raw input remains unknown' => ['acceptString($request->input("start_on"));', 'void', ['mixed-argument']],
    'magic property remains unknown' => [
        'acceptString($request->start_on);',
        'void',
        ['mixed-argument', 'non-documented-property'],
    ],
];
function check_request_nested(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Foundation\Http\FormRequest;
        function acceptArray(array $value): void {}
        function acceptString(string $value): void {}
        function acceptInt(int $value): void {}
        function acceptBool(bool $value): void {}
        /** @param non-empty-array<array-key, mixed> $value */
        function acceptNonEmpty(array $value): void {}
        /** @param list<mixed> $value */
        function acceptList(array $value): void {}
        /** @param array{nickname: string, ...} $value */
        function acceptRequiredNickname(array $value): void {}
        /** @param array<string, mixed> $value */
        function acceptNamedFields(array $value): void {}
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .=
            'function scenario'
            .count($lines)
            .'(NestedInputRequest $request, FormRequest $base, mixed $unknown, ?string $nullableKey) { '
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

check_request_nested($cases, $command, $workspace);
mkdir($workspace.'/bootstrap');
file_put_contents($workspace.'/bootstrap/bindings.php', '<?php app()->bind("validator", stdClass::class);');
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['binding-files' => ['bootstrap/bindings.php']]],
], JSON_THROW_ON_ERROR));
check_request_nested(
    [
        'custom validator binding keeps fields broad' => [
            'acceptString($request->validated("profile.name"));',
            'void',
            ['mixed-argument'],
        ],
        'custom validator binding keeps full result broad' => [
            'acceptArray($request->validated());',
            'void',
            ['mixed-argument'],
        ],
        'custom validator binding preserves native override' => [
            'return (new OverrideNestedInputRequest)->validated();',
            'int',
            [],
        ],
    ],
    $command,
    $workspace,
);
unlink($workspace.'/bootstrap/bindings.php');
rmdir($workspace.'/bootstrap');
file_put_contents($workspace.'/composer.json', '{}');
$config = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
unset($config['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_request_nested(
    [
        'disabled nested refinement' => [
            'acceptString($request->validated("profile.name"));',
            'void',
            ['mixed-argument'],
        ],
        'disabled date refinement' => ['acceptString($request->validated("start_on"));', 'void', ['mixed-argument']],
        'disabled native override preserved' => ['return (new OverrideNestedInputRequest)->validated();', 'int', []],
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
