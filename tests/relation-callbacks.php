<?php

declare(strict_types=1);

// Check relationship callback signatures and return types through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago relation callbacks '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/relation-callbacks.php.stub', $workspace.'/models.php');
$framework = str_replace("\r\n", "\n", file_get_contents($workspace.'/framework.php'));
$framework = str_replace(
    'class Builder'."\n".'{',
    'class Builder'
    ."\n"
    .'{'
    ."\n"
    .'/** @param string $relation @param (\\Closure(self<\\Illuminate\\Database\\Eloquent\\Model>): mixed)|null $callback @return $this */ public function whereHas($relation, $callback = null) { return $this; }',
    $framework,
);
foreach (['orWhereHas', 'whereDoesntHave', 'orWhereDoesntHave'] as $method) {
    $framework = str_replace(
        'class Builder'."\n".'{',
        'class Builder'
        ."\n"
        .'{'
        ."\n"
        .'/** @param string $relation @param (\\Closure(self<\\Illuminate\\Database\\Eloquent\\Model>): mixed)|null $callback @return $this */ public function '
        .$method
        .'($relation, $callback = null) { return $this; }',
        $framework,
    );
}
$framework = preg_replace_callback(
    '~/\*\*.*?\*/~s',
    static fn (array $match): string => str_contains($match[0], "\n")
        ? $match[0]
        : str_replace(' @', "\n * @", $match[0]),
    $framework,
);
file_put_contents($workspace.'/framework.php', $framework);
$cases = [
    'instance callback' => ['(new CallbackRecord)->whereHas("posts", fn ($q) => acceptPosts($q));', 'void', []],
    'nested builder callback' => [
        'CallbackNested::query()->whereHas("records.posts", fn ($q) => acceptPosts($q));',
        'void',
        [],
    ],
    'custom builder preserved' => ['return (new CallbackCustomBuilder)->whereHas("posts");', 'string', []],
    'undocumented relation stays unknown' => [
        'CallbackUndocumented::whereHas("posts", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'dynamic relation stays unknown' => [
        '$relation = "posts"; CallbackRecord::whereHas($relation, fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'static nested relation' => ['CallbackNested::whereHas("records.posts", fn ($q) => acceptPosts($q));', 'void', []],
    'static inherited relation' => ['CallbackChild::whereHas("posts", fn ($q) => acceptPosts($q));', 'void', []],
    'named arguments' => [
        'CallbackRecord::whereHas(callback: fn ($q) => acceptPosts($q), relation: "posts");',
        'void',
        [],
    ],
    'orWhereHas callback' => ['CallbackRecord::orWhereHas("posts", fn ($q) => acceptPosts($q));', 'void', []],
    'whereDoesntHave callback' => ['CallbackRecord::whereDoesntHave("posts", fn ($q) => acceptPosts($q));', 'void', []],
    'orWhereDoesntHave callback' => [
        'CallbackRecord::orWhereDoesntHave("posts", fn ($q) => acceptPosts($q));',
        'void',
        [],
    ],
    'null callback' => ['return CallbackRecord::whereHas("posts", null);', 'Builder<CallbackRecord>', []],
    'no callback' => ['return CallbackRecord::whereHas("posts");', 'Builder<CallbackRecord>', []],
    'invalid callback argument' => ['CallbackRecord::whereHas("posts", 123);', 'void', ['invalid-argument']],
    'extra argument preserved' => ['CallbackRecord::whereHas("posts", null, 123);', 'void', ['too-many-arguments']],
    'custom method preserved' => ['return CallbackCustom::whereHas("posts");', 'string', []],
    'unknown static relation' => [
        'CallbackRecord::whereHas("missing", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'custom dispatch preserved' => [
        'CallbackOverride::whereHas("posts", fn ($q) => acceptPosts($q));',
        'void',
        ['non-documented-method', 'mixed-argument'],
    ],
    'declared builder callback' => [
        'CallbackRecord::query()->whereHas("posts", fn ($q) => acceptPosts($q));',
        'void',
        [],
    ],
    'static related callback' => ['CallbackRecord::whereHas("posts", fn ($q) => acceptPosts($q));', 'void', []],
    'static wrong callback model' => [
        'CallbackRecord::whereHas("posts", fn ($q) => acceptRecords($q));',
        'void',
        ['invalid-argument'],
    ],
    'wrong callback model' => [
        'CallbackRecord::query()->whereHas("posts", fn ($q) => acceptRecords($q));',
        'void',
        ['invalid-argument'],
    ],
    'unknown relation retains broad callback' => [
        'CallbackRecord::query()->whereHas("missing", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Database\Eloquent\Builder;
    /** @param Builder<CallbackPost> $q */ function acceptPosts($q): void {}
    /** @param Builder<CallbackRecord> $q */ function acceptRecords($q): void {}
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
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
    throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '.$workspace);
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
    throw new RuntimeException('Unexpected diagnostics outside relation callback scenarios; inspect '.$workspace);
}
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
