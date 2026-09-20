<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
require $package.'/vendor/autoload.php';
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago config keys '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/bootstrap');
mkdir($workspace.'/config');
$framework = $workspace.'/dependencies with spaces/laravel/framework/src/Illuminate';
$helpers = 'dependencies with spaces/laravel/framework/src/Illuminate/Foundation/helpers.php';
mkdir(dirname($workspace.'/'.$helpers), 0777, true);
mkdir($framework.'/Support/Facades', 0777, true);
mkdir($framework.'/Config', 0777, true);
copy(__DIR__.'/fixtures/analysis/configuration-facade-base.php.stub', $framework.'/Support/Facades/Facade.php');
copy(__DIR__.'/fixtures/analysis/configuration-facade.php.stub', $framework.'/Support/Facades/Config.php');
copy(__DIR__.'/fixtures/analysis/configuration-arr.php.stub', $framework.'/Support/Arr.php');
copy(__DIR__.'/fixtures/analysis/configuration-collection.php.stub', $framework.'/Support/Collection.php');
copy(__DIR__.'/fixtures/analysis/configuration-repository.php.stub', $framework.'/Config/Repository.php');
$nativeHelpers = <<<'PHP'
    <?php
    function app(mixed $abstract = null, array $parameters = []): mixed
    {
        throw new RuntimeException('Application helper executed.');
    }
    /** @param array<string, mixed>|string|null $key */
    function config(array|string|null $key = null, mixed $default = null): mixed
    {
        if (is_null($key)) {
            return app('config');
        }
        if (is_array($key)) {
            return app('config')->set($key);
        }
        return app('config')->get($key, $default);
    }
    PHP;
file_put_contents($workspace.'/'.$helpers, $nativeHelpers);
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Bootstrap executed.");');
file_put_contents($workspace.'/config/example.php', <<<'PHP'
    <?php
    return [
        'name' => 'Example',
        'brand' => 'Brand',
        'grand' => 'Grand',
        'dollar$' => 'Dollar',
        "it's" => 'Apostrophe',
        'path\\name' => 'Backslash',
        'dynamic' => env('EXAMPLE_NAME'),
        'nested' => ['count' => 2],
        'open' => ['known' => true, ...runtimeConfiguration()],
        'with.dot' => 'literal',
    ];
    PHP);
file_put_contents($workspace.'/config/dynamic.php', '<?php return runtimeConfiguration();');
file_put_contents(
    $workspace.'/config/conditional.php',
    '<?php if (true) { return ["known" => true]; } return ["other" => true];',
);
$contract = [
    'complete' => true,
    'runtime-configuration-unchanged' => true,
];
$writeComposer = static function (mixed $configuration, ?array $bindingFiles = null) use ($workspace): void {
    $laramago = ['configuration-keys' => $configuration];
    if ($bindingFiles !== null) {
        $laramago['binding-files'] = $bindingFiles;
    }
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => $laramago],
    ], JSON_THROW_ON_ERROR));
};
$writeComposer($contract);
$missing = ['ichinya/laramago/laramago-missing-configuration-key'];
$cases = [
    'known helper key' => ['config("example.name");', []],
    'helper typo offers edit after UTF-8 bytes' => ['/* Café */ config("example.nmae");', $missing],
    'dollar key edit keeps PHP literal semantics' => ['config("example.dollar#");', $missing],
    'apostrophe key edit escapes the replacement' => ['config("example.its");', $missing],
    'backslash key edit escapes the replacement' => ['config("example.pathname");', $missing],
    'case-only difference has no edit' => ['config("example.Name");', $missing],
    'ambiguous closest names have no edit' => ['config("example.frand");', $missing],
    'known dynamic value key' => ['config("example.dynamic");', []],
    'missing helper key' => ['config("example.missing");', $missing],
    // Adapted from laravel/lsp's literal/interpolated argument scenarios
    // (tests/Unit/InterpolatedStringTest.php, 557d8c959, MIT).
    'escaped dollar remains a literal key' => ['config("example.\$broker");', $missing],
    'interpolated helper key deferred' => ['$broker = "missing"; config("example.$broker");', []],
    'missing helper key with default' => ['config("example.missing", 42);', $missing],
    'named helper key' => ['config(default: 42, key: "example.missing");', $missing],
    'known nested helper key' => ['config("example.nested.count");', []],
    'missing nested helper key' => ['config("example.nested.missing");', $missing],
    'dynamic parent deferred' => ['config("example.dynamic.missing");', []],
    'source-incomplete parent deferred' => ['config("example.open.missing");', []],
    'unknown namespace deferred' => ['config("unknown.missing");', []],
    'dynamic namespace deferred' => ['config("dynamic.missing");', []],
    'conditional namespace deferred' => ['config("conditional.missing");', []],
    'top-level namespace absence deferred' => ['config("unknown");', []],
    'dotted literal ambiguity deferred' => ['config("example.with.dot");', []],
    'dynamic helper key deferred' => ['$key = (string) random_int(1, 2); config($key);', []],
    'unpacked helper call deferred' => ['config(...["example.missing"]);', []],
    'invalid helper argument remains native' => ['config(42);', ['invalid-argument']],
    'invalid helper name remains native' => ['config(Key: "example.missing");', ['invalid-named-argument']],
    'known facade key' => ['Config::get("example.name");', []],
    'missing facade key' => ['Config::get("example.missing");', $missing],
    'named facade key' => ['Config::get(default: 42, key: "example.missing");', $missing],
    'dynamic facade key deferred' => ['$key = (string) random_int(1, 2); Config::get($key);', []],
    'unpacked facade call deferred' => ['Config::get(...["example.missing"]);', []],
    'invalid facade name remains native' => ['Config::get(Key: "example.missing");', ['invalid-named-argument']],
    'known getMany list' => ['Config::getMany(["example.name", "example.nested.count"]);', []],
    'missing getMany list key' => ['Config::getMany(["example.missing"]);', $missing],
    'getMany skips an interpolated member but checks literal siblings' => [
        '$broker = "missing"; Config::getMany(["example.name", "example.$broker", "example.missing"]);',
        $missing,
    ],
    'multiple missing getMany keys' => [
        'Config::getMany(["example.missing", "example.nested.missing"]);',
        [...$missing, ...$missing],
    ],
    'known getMany key with default' => ['Config::getMany(["example.name" => 42]);', []],
    'missing getMany key with default' => ['Config::getMany(["example.missing" => 42]);', $missing],
    'numeric getMany key selects value' => ['Config::getMany([4 => "example.missing"]);', $missing],
    'numeric string getMany key selects value' => ['Config::getMany(["08" => "example.missing"]);', $missing],
    'named getMany keys' => ['Config::getMany(keys: ["example.missing"]);', $missing],
    'dynamic getMany entry deferred' => ['$key = (string) random_int(1, 2); Config::getMany([$key]);', []],
    'computed getMany array key deferred' => [
        '$offset = random_int(1, 2); Config::getMany([$offset => "example.missing"]);',
        [],
    ],
    'dynamic getMany array deferred' => ['$keys = ["example.missing"]; Config::getMany($keys);', []],
    'unpacked getMany array deferred' => [
        '$keys = ["example.name"]; Config::getMany(["example.missing", ...$keys]);',
        [],
    ],
    'duplicate getMany array key deferred' => [
        'Config::getMany([0 => "example.missing", 0 => "example.name"]);',
        ['duplicate-array-key'],
    ],
    'negative string getMany key sequence deferred' => [
        'Config::getMany(["-2" => "example.missing", "example.name", 0 => "example.name"]);',
        ['duplicate-array-key'],
    ],
    'referenced getMany item deferred' => [
        '$default = 42; Config::getMany(["example.missing" => &$default]);',
        [],
    ],
    'maximum implicit getMany index deferred' => [
        'Config::getMany([9223372036854775806 => "example.name", "example.missing"]);',
        [],
    ],
    'invalid getMany name remains native' => [
        'Config::getMany(Keys: ["example.missing"]);',
        ['invalid-named-argument'],
    ],
    'repository getMany instance excluded' => ['(new Repository)->getMany(["example.missing"]);', []],
    'known string getter key' => ['needsString(Config::string("example.name"));', []],
    'missing string getter key' => ['needsString(Config::string("example.missing"));', $missing],
    'known integer getter key' => ['needsInt(Config::integer("example.nested.count"));', []],
    'missing integer getter key' => ['needsInt(Config::integer("example.nested.missing"));', $missing],
    'known float getter key' => ['needsFloat(Config::float("example.dynamic"));', []],
    'missing float getter key' => ['needsFloat(Config::float("example.missing"));', $missing],
    'known boolean getter key' => ['needsBool(Config::boolean("example.dynamic"));', []],
    'missing boolean getter key' => ['needsBool(Config::boolean("example.missing"));', $missing],
    'known array getter key' => ['needsArray(Config::array("example.nested"));', []],
    'missing array getter key' => ['needsArray(Config::array("example.missing"));', $missing],
    'known collection getter key' => ['needsCollection(Config::collection("example.nested"));', []],
    'missing collection getter key' => ['needsCollection(Config::collection("example.missing"));', $missing],
    'named typed getter key' => ['Config::string(default: "fallback", key: "example.missing");', $missing],
    'dynamic typed getter key deferred' => ['$key = (string) random_int(1, 2); Config::string($key);', []],
    'unpacked typed getter call deferred' => ['Config::string(...["example.missing"]);', []],
    'invalid typed getter name remains native' => [
        'Config::string(Key: "example.missing");',
        ['invalid-named-argument'],
    ],
    'native typed getter result remains authoritative' => [
        'needsInt(Config::string("example.name"));',
        ['invalid-argument'],
    ],
    'repository instance excluded' => ['(new Repository)->get("example.missing");', []],
    'repository typed getter excluded' => ['(new Repository)->string("example.missing");', []],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Config\Repository;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Facades\Config;
    function needsString(string $value): void {}
    function needsInt(int $value): void {}
    function needsFloat(float $value): void {}
    function needsBool(bool $value): void {}
    function needsArray(array $value): void {}
    function needsCollection(Collection $value): void {}
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $expected]) {
    $source .= 'function scenario'.count($lines).'(): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $expected];
}
file_put_contents($workspace.'/cases.php', $source);
$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            $helpers,
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Facades/Config.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Arr.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Collection.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Config/Repository.php',
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

$analyze = static function (string $label) use ($command, $workspace): array {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$label.'.json', 'w'],
            2 => ['file', $workspace.'/'.$label.'.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$label.'.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException($label.': external analyzer failed; inspect '.$workspace);
    }

    return [
        $exit,
        json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR),
    ];
};

[$exit, $report] = $analyze('enabled');
if ($exit !== 1) {
    throw new RuntimeException('Expected warnings from enabled configuration key contracts; inspect '.$workspace);
}
$editCases = [
    'helper typo offers edit after UTF-8 bytes' => ['"example.nmae"', 'example.name'],
    'dollar key edit keeps PHP literal semantics' => ['"example.dollar#"', 'example.dollar$'],
    'apostrophe key edit escapes the replacement' => ['"example.its"', "example.it's"],
    'backslash key edit escapes the replacement' => ['"example.pathname"', 'example.path\\name'],
];
$noEditCases = ['case-only difference has no edit', 'ambiguous closest names have no edit'];
$caseByLine = [];
foreach ($lines as $line => [$name]) {
    $caseByLine[$line] = $name;
}
foreach ($report['issues'] ?? [] as $issue) {
    if ($issue['code'] !== $missing[0]) {
        continue;
    }
    $primary = $issue['annotations'][0]['span'];
    $case = $caseByLine[$primary['start']['line'] + 1] ?? null;
    if (isset($editCases[$case])) {
        [$original, $replacement] = $editCases[$case];
        $edits = $issue['edits'] ?? [];
        if (count($edits) !== 1 || count($edits[0][1] ?? []) !== 1) {
            throw new RuntimeException($case.': expected one Mago edit; inspect '.$workspace);
        }
        $file = $edits[0][0];
        $edit = $edits[0][1][0];
        $start = $edit['range']['start'];
        $end = $edit['range']['end'];
        $newText = implode('', array_map(chr(...), $edit['new_text']));
        $parsed = (new PhpParser\ParserFactory)
            ->createForNewestSupportedVersion()
            ->parse('<?php return '.$newText.';');
        if (
            $file['name'] !== 'cases.php'
            || $file['size'] !== strlen($source)
            || $start !== $primary['start']['offset']
            || $end !== $primary['end']['offset']
            || substr($source, $start, $end - $start) !== $original
            || $edit['safety'] !== 'potentiallyunsafe'
            || ! $parsed[0] instanceof PhpParser\Node\Stmt\Return_
            || Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource::value($parsed[0]->expr) !== $replacement
        ) {
            throw new RuntimeException(
                $case.': edit must replace original bytes with an equivalent PHP literal; inspect '.$workspace,
            );
        }
        unset($editCases[$case]);
    } elseif (in_array($case, $noEditCases, true) && isset($issue['edits'])) {
        throw new RuntimeException($case.': ambiguous or case-only names must not offer an edit; inspect '.$workspace);
    }
}
if ($editCases !== []) {
    throw new RuntimeException('Missing expected configuration edits: '.implode(', ', array_keys($editCases)));
}
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException(
            $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics outside configuration key scenarios; inspect '.$workspace);
}

$nativeOnly = [
    'invalid-argument',
    'invalid-argument',
    'invalid-named-argument',
    'invalid-named-argument',
    'invalid-named-argument',
    'invalid-named-argument',
    'duplicate-array-key',
    'duplicate-array-key',
];
$guards = [
    'no-contract' => null,
    'incomplete-contract' => ['complete' => false, 'runtime-configuration-unchanged' => true],
    'runtime-assertion-omitted' => ['complete' => true],
    'runtime-mutation-allowed' => ['complete' => true, 'runtime-configuration-unchanged' => false],
    'malformed-contract' => 'complete',
];
foreach ($guards as $label => $guard) {
    $writeComposer($guard);
    [$guardExit, $guardReport] = $analyze($label);
    $codes = array_column($guardReport['issues'] ?? [], 'code');
    sort($codes);
    $expected = $nativeOnly;
    sort($expected);
    if ($guardExit !== 1 || $codes !== $expected) {
        throw new RuntimeException($label.': expected native diagnostics only; inspect '.$workspace);
    }
    echo 'PASS: '.str_replace('-', ' ', $label)."\n";
}

$writeComposer($contract);
file_put_contents($workspace.'/config/example.php', <<<'PHP'
    <?php
    return ['name' => 'Example', ...runtimeConfiguration()];
    PHP);
[$guardExit, $guardReport] = $analyze('source-incomplete');
$codes = array_column($guardReport['issues'] ?? [], 'code');
sort($codes);
$expected = $nativeOnly;
sort($expected);
if ($guardExit !== 1 || $codes !== $expected) {
    throw new RuntimeException('Source-incomplete root must defer; inspect '.$workspace);
}
echo "PASS: source-incomplete root defers\n";
file_put_contents($workspace.'/config/example.php', <<<'PHP'
    <?php
    return ['name' => 'Example', 'nested' => ['count' => 2]];
    PHP);

file_put_contents(
    $workspace.'/bootstrap/bindings.php',
    '<?php \\app()->singleton("config", CustomConfiguration::class);',
);
$writeComposer($contract, ['bootstrap/bindings.php']);
[$guardExit, $guardReport] = $analyze('custom-config-binding');
$codes = array_column($guardReport['issues'] ?? [], 'code');
sort($codes);
$expected = $nativeOnly;
sort($expected);
if ($guardExit !== 1 || $codes !== $expected) {
    throw new RuntimeException('Custom config binding must defer; inspect '.$workspace);
}
echo "PASS: custom config binding defers\n";

file_put_contents(
    $workspace.'/bootstrap/bindings.php',
    '<?php \\app()->scoped("config", CustomConfiguration::class);',
);
[$guardExit, $guardReport] = $analyze('custom-scoped-config-binding');
$codes = array_column($guardReport['issues'] ?? [], 'code');
sort($codes);
$expected = $nativeOnly;
sort($expected);
if ($guardExit !== 1 || $codes !== $expected) {
    throw new RuntimeException('Custom scoped config binding must defer; inspect '.$workspace);
}
echo "PASS: custom scoped config binding defers\n";

$writeComposer($contract);
$originalSource = file_get_contents($workspace.'/cases.php');
$originalConfiguration = $configuration;
file_put_contents($workspace.'/custom-helper.php', <<<'PHP'
    <?php
    function config(?string $key = null, mixed $default = null): mixed { return $default; }
    PHP);
file_put_contents($workspace.'/cases.php', '<?php config("example.missing");');
$configuration['source']['includes'] = ['custom-helper.php'];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
[$guardExit, $guardReport] = $analyze('custom-helper');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Custom helper must defer; inspect '.$workspace);
}
echo "PASS: custom helper defers\n";

copy(__DIR__.'/fixtures/analysis/configuration-repository.php.stub', $workspace.'/custom-repository.php');
$configuration = $originalConfiguration;
$configuration['source']['includes'][5] = 'custom-repository.php';
file_put_contents(
    $workspace.'/cases.php',
    '<?php config("example.missing"); \\Illuminate\\Support\\Facades\\Config::string("example.missing");',
);
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
[$guardExit, $guardReport] = $analyze('custom-repository-helper-dispatch');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Custom repository must defer config helper diagnostics; inspect '.$workspace);
}
echo "PASS: custom repository helper dispatch defers\n";

file_put_contents(
    $workspace.'/'.$helpers,
    str_replace('$key', '$name', $nativeHelpers),
);
file_put_contents($workspace.'/cases.php', '<?php config("example.missing");');
$configuration = $originalConfiguration;
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
[$guardExit, $guardReport] = $analyze('altered-helper-parameters');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Altered helper parameters must retain priority; inspect '.$workspace);
}
echo "PASS: altered helper parameters retain priority\n";
file_put_contents($workspace.'/'.$helpers, $nativeHelpers);

file_put_contents(
    $workspace.'/'.$helpers,
    str_replace('function app(', 'function framework_app(', $nativeHelpers),
);
file_put_contents($workspace.'/custom-app.php', <<<'PHP'
    <?php
    function app(mixed $abstract = null, array $parameters = []): mixed { return null; }
    PHP);
$configuration['source']['includes'][] = 'custom-app.php';
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
[$guardExit, $guardReport] = $analyze('custom-app-helper-dispatch');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Custom app helper must defer config helper diagnostics; inspect '.$workspace);
}
echo "PASS: custom app helper dispatch defers\n";
file_put_contents($workspace.'/'.$helpers, $nativeHelpers);

file_put_contents(
    $workspace.'/cases.php',
    '<?php \\Illuminate\\Support\\Facades\\Config::get("example.missing");'
    .' \\Illuminate\\Support\\Facades\\Config::getMany(["example.missing"]);'
    .' \\Illuminate\\Support\\Facades\\Config::string("example.missing");',
);
$configuration = $originalConfiguration;
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
$facadePath = $framework.'/Support/Facades/Config.php';
$facadeSource = file_get_contents($facadePath);
file_put_contents($facadePath, str_replace("return 'config'", "return 'custom.config'", $facadeSource));
[$guardExit, $guardReport] = $analyze('custom-facade');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Custom facade accessor must defer; inspect '.$workspace);
}
echo "PASS: custom facade dispatch defers\n";
file_put_contents($facadePath, $facadeSource);
$concreteMethods = [
    'get' => [
        '<?php \\Illuminate\\Support\\Facades\\Config::get("example.missing");',
        'public static function get(array|string $key, mixed $default = null): mixed { return 42; }',
    ],
    'getMany' => [
        '<?php \\Illuminate\\Support\\Facades\\Config::getMany(["example.missing"]);',
        'public static function getMany(array $keys): array { return []; }',
    ],
    'string' => [
        '<?php \\Illuminate\\Support\\Facades\\Config::string("example.missing");',
        'public static function string(string $key, mixed $default = null): string { return "custom"; }',
    ],
];
foreach ($concreteMethods as $method => [$call, $nativeMethod]) {
    file_put_contents($workspace.'/cases.php', $call);
    foreach (['direct', 'inherited', 'inherited-trait'] as $dispatch) {
        $alteredFacade = str_replace("\r\n", "\n", $facadeSource);
        if ($dispatch === 'direct') {
            $alteredFacade = str_replace("{\n", "{\n    ".$nativeMethod."\n", $alteredFacade);
        } else {
            $parentBody = $dispatch === 'inherited-trait' ? 'use CustomConfigMethods;' : $nativeMethod;
            $ancestors = $dispatch === 'inherited-trait' ? 'trait CustomConfigMethods { '.$nativeMethod.' }' : '';
            $ancestors .=
                'class IntermediateConfig extends Facade { '
                .$parentBody
                .' } class BridgeConfig extends IntermediateConfig {}';
            $alteredFacade = str_replace(
                'final class Config extends Facade',
                'final class Config extends BridgeConfig',
                $alteredFacade,
            );
            // Keep the facade PHPDoc attached to Config so pseudo metadata can mask the method.
            $alteredFacade = str_replace('/**', $ancestors."\n/**", $alteredFacade);
        }
        file_put_contents($facadePath, $alteredFacade);
        [$guardExit, $guardReport] = $analyze('concrete-facade-'.$method.'-'.$dispatch);
        if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
            throw new RuntimeException('Concrete '.$dispatch.' facade '.$method.' must defer; inspect '.$workspace);
        }
        echo 'PASS: concrete '.$dispatch.' facade '.$method." retains priority\n";
    }
}
file_put_contents($facadePath, $facadeSource);
file_put_contents($workspace.'/cases.php', $originalSource);

$repositoryPath = $framework.'/Config/Repository.php';
$repositorySource = file_get_contents($repositoryPath);
file_put_contents(
    $repositoryPath,
    str_replace(
        'return Arr::get($this->items, $key, $default);',
        'return $default;',
        $repositorySource,
    ),
);
file_put_contents($workspace.'/cases.php', '<?php \\Illuminate\\Support\\Facades\\Config::string("example.missing");');
[$guardExit, $guardReport] = $analyze('altered-repository-get-chain');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Altered Repository::get chain must defer typed getter diagnostics; inspect '
    .$workspace);
}
echo "PASS: altered Repository::get chain defers typed getters\n";
file_put_contents(
    $repositoryPath,
    str_replace(
        '$value = $this->get($key, $default);',
        '$value = $default;',
        $repositorySource,
    ),
);
file_put_contents(
    $workspace.'/cases.php',
    '<?php \\Illuminate\\Support\\Facades\\Config::collection("example.missing");',
);
[$guardExit, $guardReport] = $analyze('altered-repository-array-chain');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Altered Repository::array chain must defer collection diagnostics; inspect '
    .$workspace);
}
echo "PASS: altered Repository::array chain defers collection getter\n";
file_put_contents($repositoryPath, $repositorySource);
file_put_contents($workspace.'/cases.php', $originalSource);

file_put_contents($workspace.'/custom-configuration-types.php', <<<'PHP'
    <?php
    namespace CustomConfiguration;
    class Arr
    {
        public static function get(array $array, string $key, mixed $default = null): mixed
        {
            return $default;
        }
    }
    class Collection
    {
        public function __construct(array $items = []) {}
    }
    class InvalidArgumentException extends \InvalidArgumentException {}
    PHP);
$changedImports = str_replace(
    [
        'use Illuminate\\Support\\Arr;',
        'use Illuminate\\Support\\Collection;',
        'use InvalidArgumentException;',
    ],
    [
        'use CustomConfiguration\\Arr;',
        'use CustomConfiguration\\Collection;',
        'use CustomConfiguration\\InvalidArgumentException;',
    ],
    $repositorySource,
);
file_put_contents($repositoryPath, $changedImports);
file_put_contents(
    $workspace.'/cases.php',
    '<?php \\Illuminate\\Support\\Facades\\Config::string("example.missing");'
    .' \\Illuminate\\Support\\Facades\\Config::collection("example.missing");',
);
$configuration = $originalConfiguration;
$configuration['source']['includes'][] = 'custom-configuration-types.php';
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
[$guardExit, $guardReport] = $analyze('changed-repository-imports');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Changed Repository imports must defer typed getter diagnostics; inspect '.$workspace);
}
echo "PASS: changed Repository imports defer typed getters\n";

file_put_contents($workspace.'/custom-repository-get.php', <<<'PHP'
    <?php
    namespace Illuminate\Config;
    use Illuminate\Support\Arr;
    trait CustomRepositoryGet
    {
        public function get($key, $default = null)
        {
            if (is_array($key)) {
                return $this->getMany($key);
            }
            return Arr::get($this->items, $key, $default);
        }
    }
    PHP);
$traitRepository = str_replace(
    "class Repository\n{",
    "class Repository\n{\n    use CustomRepositoryGet;",
    $repositorySource,
);
$traitRepository = preg_replace(
    '/public function get\(\$key, \$default = null\)/',
    'public function originalGet($key, $default = null)',
    $traitRepository,
    1,
);
file_put_contents($repositoryPath, $traitRepository);
file_put_contents($workspace.'/cases.php', '<?php \\Illuminate\\Support\\Facades\\Config::string("example.missing");');
$configuration = $originalConfiguration;
$configuration['source']['includes'][] = 'custom-repository-get.php';
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
[$guardExit, $guardReport] = $analyze('trait-repository-get');
if ($guardExit !== 0 || ($guardReport['issues'] ?? []) !== []) {
    throw new RuntimeException('Trait-provided Repository::get must defer typed getter diagnostics; inspect '
    .$workspace);
}
echo "PASS: trait-provided Repository::get defers typed getters\n";
file_put_contents($repositoryPath, $repositorySource);
file_put_contents($workspace.'/cases.php', $originalSource);

echo "PASS: complete configuration key diagnostics preserve native and dynamic boundaries\n";
