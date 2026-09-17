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
    "class Model\n{",
    "class Model\n{\n    use \\Illuminate\\Database\\Eloquent\\Concerns\\HasRelationships;",
    $framework,
);
foreach (['HasMany', 'HasOne', 'BelongsTo'] as $kind) {
    $framework = str_replace(
        '/** @template TRelatedModel of \\Illuminate\\Database\\Eloquent\\Model */'."\n".'class '.$kind.' {}',
        '/** @template TRelatedModel of \\Illuminate\\Database\\Eloquent\\Model'
        ."\n"
        .' * @template TDeclaringModel of \\Illuminate\\Database\\Eloquent\\Model'
        ."\n"
        .' * @extends Relation<TRelatedModel, TDeclaringModel> */'
        ."\n"
        .'class '
        .$kind
        .' extends Relation {}',
        $framework,
    );
}
$framework .= substr(file_get_contents(__DIR__.'/fixtures/analysis/relation-methods-framework.php.stub'), 5);
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
$framework = str_replace(
    'class Builder'."\n".'{',
    'class Builder'
    ."\n"
    .'{'
    ."\n"
    .'/** @param string $relation @param (\\Closure(self<\\Illuminate\\Database\\Eloquent\\Model>|\\Illuminate\\Database\\Eloquent\\Relations\\Relation<\\Illuminate\\Database\\Eloquent\\Model, \\Illuminate\\Database\\Eloquent\\Model>): mixed)|null $callback @param string $operator @param int|\\Illuminate\\Contracts\\Database\\Query\\Expression $count @return $this */ public function withWhereHas($relation, $callback = null, $operator = ">=", $count = 1) { return $this; }',
    $framework,
);
$framework = preg_replace_callback(
    '~/\*\*.*?\*/~s',
    static fn (array $match): string => str_contains($match[0], "\n")
        ? $match[0]
        : str_replace(' @', "\n * @", $match[0]),
    $framework,
);
file_put_contents($workspace.'/framework.php', $framework);
file_put_contents(
    $workspace.'/bootstrap.php',
    '<?php throw new RuntimeException("Application bootstrap must never execute.");',
);
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$cases = [
    'syntax relation callback' => ['CallbackInferred::whereHas("posts", fn ($q) => acceptPosts($q));', 'void', []],
    'syntax inherited callback' => [
        'CallbackInferredChild::whereHas("posts", fn ($q) => acceptPosts($q));',
        'void',
        [],
    ],
    'mixed syntax documented nested callback' => [
        'CallbackInferred::whereHas("records.posts", fn ($q) => acceptPosts($q));',
        'void',
        [],
    ],
    'documented return wins over body' => [
        'CallbackInferred::whereHas("documented", fn ($q) => acceptRecords($q));',
        'void',
        [],
    ],
    'syntax property' => [
        'CallbackInferred::whereHas("posts", fn ($q) => acceptString($q->firstOrFail()->title));',
        'void',
        [],
    ],
    'syntax wrong property type' => [
        'CallbackInferred::whereHas("posts", fn ($q) => acceptInt($q->firstOrFail()->title));',
        'void',
        ['invalid-argument'],
    ],
    'syntax missing property' => [
        'CallbackInferred::whereHas("posts", fn ($q) => $q->firstOrFail()->missing);',
        'void',
        ['non-documented-property'],
    ],
    'syntax nullable result' => [
        'CallbackInferred::whereHas("posts", fn ($q) => $q->first()->title);',
        'void',
        ['possibly-null-property-access'],
    ],
    'private relation deferred' => [
        'CallbackInferred::whereHas("hidden", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'relation parameter deferred' => [
        'CallbackInferred::whereHas("parameterized", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'custom factory deferred' => [
        'CallbackCustomFactory::whereHas("posts", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'custom related builder deferred' => [
        'CallbackInferred::whereHas("customPosts", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'withWhereHas both contexts' => ['CallbackInferred::withWhereHas("posts", fn ($q) => acceptBoth($q));', 'void', []],
    'withWhereHas builder only rejected' => [
        'CallbackInferred::withWhereHas("posts", fn ($q) => acceptPosts($q));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'withWhereHas annotated builder only rejected' => [
        'CallbackInferred::withWhereHas("posts", fn (Builder $q) => null);',
        'void',
        ['invalid-argument'],
    ],
    'withWhereHas concrete relation only rejected' => [
        'CallbackInferred::withWhereHas("posts", fn ($q) => acceptRelation($q));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'withWhereHas null callback' => [
        'return CallbackInferred::withWhereHas("posts", null);',
        'Builder<CallbackInferred>',
        [],
    ],
    'withWhereHas unknown relation deferred' => [
        'CallbackInferred::withWhereHas("missing", fn ($q) => acceptBoth($q));',
        'void',
        ['less-specific-argument'],
    ],
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
foreach (['orWhereHas', 'whereDoesntHave', 'orWhereDoesntHave'] as $method) {
    $cases['syntax '.$method] = [
        'CallbackInferred::'.$method.'("posts", fn ($q) => acceptPosts($q));',
        'void',
        [],
    ];
}
foreach (['one', 'parent', 'members', 'through', 'throughOne', 'image', 'images', 'tags', 'inverseTags'] as $relation) {
    $cases['syntax '.$relation] = [
        'CallbackInferred::whereHas("'.$relation.'", fn ($q) => acceptPosts($q));',
        'void',
        [],
    ];
}
$cases += [
    'withWhereHas native trailing arguments' => [
        'return CallbackInferred::withWhereHas("posts", null, ">=", 2);',
        'Builder<CallbackInferred>',
        [],
    ],
    'withWhereHas invalid native operator' => [
        'CallbackInferred::withWhereHas("posts", null, 123);',
        'void',
        ['invalid-argument'],
    ],
    'withWhereHas invalid native count' => [
        'CallbackInferred::withWhereHas("posts", null, ">=", "two");',
        'void',
        ['invalid-argument'],
    ],
    'withWhereHas extra argument rejected' => [
        'CallbackInferred::withWhereHas("posts", null, ">=", 2, false);',
        'void',
        ['too-many-arguments'],
    ],
    'protected relation deferred' => [
        'CallbackInferred::whereHas("protectedPosts", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'static relation deferred' => [
        'CallbackInferred::whereHas("staticPosts", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'broad PHPDoc stays authoritative' => [
        'CallbackInferred::whereHas("broadDocumented", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'arbitrary relation chain deferred' => [
        'CallbackInferred::whereHas("chained", fn ($q) => acceptPosts($q));',
        'void',
        ['less-specific-argument'],
    ],
    'syntax builder receiver' => [
        'CallbackInferred::query()->whereHas("posts", fn ($q) => acceptPosts($q));',
        'void',
        [],
    ],
    'syntax instance receiver' => [
        '(new CallbackInferred)->whereHas("posts", fn ($q) => acceptPosts($q));',
        'void',
        [],
    ],
    'syntax named arguments' => [
        'CallbackInferred::whereHas(callback: fn ($q) => acceptPosts($q), relation: "posts");',
        'void',
        [],
    ],
    'related local scope' => [
        'CallbackInferred::whereHas("posts", fn ($q) => acceptPosts($q->titled("name")));',
        'void',
        [],
    ],
    'related scope invalid parameter' => [
        'CallbackInferred::whereHas("posts", fn ($q) => $q->titled(123));',
        'void',
        ['invalid-argument'],
    ],
    'withWhereHas narrowed builder' => [
        'CallbackInferred::withWhereHas("posts", fn ($q) => $q instanceof Builder ? acceptPosts($q) : acceptRelation($q));',
        'void',
        [],
    ],
    'withWhereHas explicit union' => [
        'CallbackInferred::withWhereHas("posts", fn (Builder|\\Illuminate\\Database\\Eloquent\\Relations\\HasMany $q) => null);',
        'void',
        [],
    ],
    'withWhereHas builder receiver' => [
        'CallbackInferred::query()->withWhereHas("posts", fn ($q) => acceptBoth($q));',
        'void',
        [],
    ],
    'withWhereHas nested path' => [
        'CallbackInferred::withWhereHas("records.posts", fn ($q) => acceptNestedBoth($q));',
        'void',
        [],
    ],
    'withWhereHas colon selector deferred' => [
        'CallbackInferred::withWhereHas("posts:id", fn ($q) => acceptBoth($q));',
        'void',
        ['less-specific-argument'],
    ],
    'withWhereHas dynamic path deferred' => [
        '$path = "posts"; CallbackInferred::withWhereHas($path, fn ($q) => acceptBoth($q));',
        'void',
        ['less-specific-argument'],
    ],
];
$config = [
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
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_relation_callbacks($cases, $command, $workspace);
$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_relation_callbacks(
    [
        'native syntax callback remains broad' => [
            'CallbackInferred::query()->whereHas("posts", fn ($q) => acceptPosts($q));',
            'void',
            ['less-specific-argument'],
        ],
        'native dual callback remains broad' => [
            'CallbackInferred::query()->withWhereHas("posts", fn ($q) => acceptBoth($q));',
            'void',
            ['less-specific-argument'],
        ],
    ],
    $command,
    $workspace,
);
if (is_file($workspace.'/.env')) {
    throw new RuntimeException('The offline fixture must not have an environment file.');
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

/** @param array<string, array{string, string, list<string>}> $cases
 * @param list<string> $command */
function check_relation_callbacks(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Database\Eloquent\Builder;
        /** @param Builder<CallbackPost> $q */ function acceptPosts($q): void {}
        /** @param Builder<CallbackRecord> $q */ function acceptRecords($q): void {}
        function acceptString(string $value): void {}
        function acceptInt(int $value): void {}
        /** @param Builder<CallbackPost>|\Illuminate\Database\Eloquent\Relations\HasMany<CallbackPost, CallbackInferred> $q */ function acceptBoth($q): void {}
        /** @param \Illuminate\Database\Eloquent\Relations\HasMany<CallbackPost, CallbackInferred> $q */ function acceptRelation($q): void {}
        /** @param Builder<CallbackPost>|\Illuminate\Database\Eloquent\Relations\HasMany<CallbackPost> $q */ function acceptNestedBoth($q): void {}
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
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
        throw new RuntimeException('Unexpected diagnostics outside relation callback scenarios; inspect '.$workspace);
    }
}
