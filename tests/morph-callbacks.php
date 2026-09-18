<?php

declare(strict_types=1);

// Contextual morph constraints through the real worker; application code never runs.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago morph callbacks '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/fixtures/analysis/framework.php.stub'));
$framework = str_replace(
    "class Builder\n{",
    "class Builder\n{\n use \\Illuminate\\Database\\Eloquent\\Concerns\\QueriesRelationships;",
    $framework,
);
$queries = file_get_contents(__DIR__.'/fixtures/analysis/morph-callbacks-framework.php.stub');
$queryPath = in_array('--custom-source', $argv, true)
    ? 'custom-queries.php'
    : 'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/QueriesRelationships.php';
if (! is_dir(dirname($workspace.'/'.$queryPath))) {
    mkdir(dirname($workspace.'/'.$queryPath), 0777, true);
}
if (in_array('--custom-helper', $argv, true)) {
    $framework = str_replace(
        "class Builder\n{",
        "class Builder\n{\n public function hasMorph(\$relation, \$types, \$operator = '>=', \$count = 1, \$boolean = 'and', ?\\Closure \$callback = null) { return \$this; }",
        $framework,
    );
}

if (in_array('--custom-owner', $argv, true)) {
    $methodAt = strpos($queries, 'public function whereHasMorph(');
    $methodStart = strrpos(substr($queries, 0, $methodAt), '/**');
    $methodEnd = strpos($queries, '}', $methodAt) + 1;
    $framework = str_replace(
        "class Builder\n{",
        "class Builder\n{\n".substr($queries, $methodStart, $methodEnd - $methodStart),
        $framework,
    );
}
if (in_array('--custom-contract', $argv, true)) {
    $queries = str_replace(
        '\\Closure(\\Illuminate\\Database\\Eloquent\\Builder<TRelatedModel>, string)',
        '\\Closure(int, string)',
        $queries,
    );
}
file_put_contents($workspace.'/'.$queryPath, $queries);
file_put_contents($workspace.'/framework.php', $framework);
file_put_contents($workspace.'/models.php', <<<'PHP'
    <?php
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\MorphTo;
    class MorphOwner extends Model {
        /** @return MorphTo<Model, $this> */
        public function subject(): MorphTo { throw new LogicException('Never execute'); }
    }
    class MorphPost extends Model { public string $title; }
    class MorphVideo extends Model { public int $duration; }
    /** @template T */ class MorphGeneric extends Model {}
    class MorphCustom extends Model {
        public function newEloquentBuilder($query) { throw new LogicException('Never execute'); }
    }
    class MorphOverride extends MorphOwner {
        public static function whereHasMorph($relation, $types, ?Closure $callback = null): int { return 1; }
    }
    /** @method static int whereHasMorph(string $relation, mixed $types, ?Closure $callback = null) */
    class MorphDocumented extends MorphOwner {}
    PHP);
file_put_contents(
    $workspace.'/bootstrap.php',
    '<?php throw new RuntimeException("Application bootstrap must never execute.");',
);
$identities = ['MorphPost', 'MorphVideo', 'MorphCustom', 'MorphGeneric', 'MissingMorphTarget'];
if (in_array('--unconfigured', $argv, true)) {
    $identities = null;
} elseif (in_array('--partial-identity', $argv, true)) {
    $identities = ['MorphPost'];
} elseif (in_array('--malformed-identity', $argv, true)) {
    $identities = ['MorphPost', true];
}
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
    'extra' => ['laramago' => ['morph-class-identity' => $identities]],
], JSON_THROW_ON_ERROR));
$cases = [
    'single explicit target' => [
        'MorphOwner::whereHasMorph("subject", \\MorphPost::class, fn ($q) => acceptPosts($q));',
        'void',
        [],
    ],
    'class-string second parameter' => [
        'MorphOwner::whereHasMorph("subject", \\MorphPost::class, fn ($q, $type) => acceptPostClass($type));',
        'void',
        [],
    ],
    'multiple targets' => [
        'MorphOwner::query()->whereHasMorph("subject", [\\MorphPost::class, \\MorphVideo::class], fn ($q) => acceptBoth($q));',
        'void',
        [],
    ],
    'multiple class strings' => [
        'MorphOwner::whereHasMorph("subject", [\\MorphPost::class, \\MorphVideo::class], fn ($q, $type) => acceptBothClasses($type));',
        'void',
        [],
    ],
    'multiple targets cannot narrow to first' => [
        'MorphOwner::whereHasMorph("subject", [\\MorphPost::class, \\MorphVideo::class], fn ($q) => acceptPosts($q));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'wrong scalar retained' => [
        'MorphOwner::whereHasMorph("subject", \\MorphPost::class, fn ($q) => acceptInt($q->firstOrFail()->title));',
        'void',
        ['invalid-argument'],
    ],
    'missing property retained' => [
        'MorphOwner::whereHasMorph("subject", \\MorphPost::class, fn ($q) => $q->firstOrFail()->absent);',
        'void',
        ['non-documented-property'],
    ],
    'nullable result retained' => [
        'MorphOwner::whereHasMorph("subject", \\MorphPost::class, fn ($q) => $q->first()->title);',
        'void',
        ['possibly-null-property-access'],
    ],
    'second parameter wrong scalar retained' => [
        'MorphOwner::whereHasMorph("subject", \\MorphPost::class, fn ($q, $type) => acceptInt($type));',
        'void',
        ['invalid-argument'],
    ],
    'native override wins' => ['return MorphOverride::whereHasMorph("subject", \\MorphPost::class);', 'int', []],
    'PHPDoc wins' => ['return MorphDocumented::whereHasMorph("subject", \\MorphPost::class);', 'int', []],
];
foreach (['orWhereHasMorph', 'whereDoesntHaveMorph', 'orWhereDoesntHaveMorph'] as $method) {
    $cases[$method] = [
        'MorphOwner::query()->'.$method.'("subject", [\\MorphPost::class], fn ($q, $type) => acceptPosts($q));',
        'void',
        [],
    ];
}
$cases['named arguments'] = [
    'MorphOwner::query()->whereHasMorph(types: \\MorphPost::class, callback: fn ($q) => acceptPosts($q), relation: "subject");',
    'void',
    [],
];
foreach ([
    'wildcard' => '"*"',
    'wildcard list' => '["*"]',
    'morph alias' => '"post"',
    'imported name' => 'ImportedPost::class',
    'relative name' => 'MorphPost::class',
    'empty list' => '[]',
    'keyed array' => '["post" => \\MorphPost::class]',
    'custom builder' => '\\MorphCustom::class',
    'generic model' => '\\MorphGeneric::class',
] as $name => $types) {
    $cases[$name.' deferred'] = [
        'MorphOwner::query()->whereHasMorph("subject", '.$types.', fn ($q) => acceptPosts($q));',
        'void',
        $name === 'keyed array' ? ['less-specific-argument', 'possibly-invalid-argument'] : ['less-specific-argument'],
    ];
}
$cases['explicit typed callback too narrow'] = [
    'MorphOwner::whereHasMorph("subject", [\\MorphPost::class, \\MorphVideo::class], /** @param Builder<MorphPost> $q */ fn (Builder $q) => null);',
    'void',
    ['invalid-argument'],
];
$cases['dynamic types deferred'] = [
    '$types = [\\MorphPost::class]; MorphOwner::query()->whereHasMorph("subject", $types, fn ($q) => acceptPosts($q));',
    'void',
    ['less-specific-argument'],
];
$cases['unpacked types deferred'] = [
    'MorphOwner::query()->whereHasMorph("subject", [...[\\MorphPost::class]], fn ($q) => acceptPosts($q));',
    'void',
    ['less-specific-argument'],
];
$cases['unknown target class retained'] = [
    'MorphOwner::query()->whereHasMorph("subject", \\MissingMorphTarget::class, fn ($q) => acceptPosts($q));',
    'void',
    ['non-existent-class-like', 'less-specific-argument'],
];
$cases['native relation generic priority'] = [
    '/** @var \\Illuminate\\Database\\Eloquent\\Relations\\MorphTo<MorphPost, MorphOwner> $relation */ $relation = new \\Illuminate\\Database\\Eloquent\\Relations\\MorphTo; MorphOwner::query()->whereHasMorph($relation, \\MorphPost::class, fn ($q) => acceptPosts($q));',
    'void',
    ['less-specific-nested-argument-type'],
];
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php', $queryPath]],
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
            'workers' => 1,
        ],
    ],
];
$nativeGenericCase = $cases['native relation generic priority'];
if (in_array('--disabled', $argv, true)) {
    $config['analyzer'] = ['disable-default-plugins' => true];
    $cases = [
        'native explicit types remain broad' => [
            'MorphOwner::query()->whereHasMorph("subject", \\MorphPost::class, fn ($q) => acceptPosts($q));',
            'void',
            ['less-specific-argument'],
        ],
    ];
}
$cases['native relation generic priority'] = $nativeGenericCase;
if (in_array('--custom-contract', $argv, true)) {
    $cases = [
        'changed callback PHPDoc preserved' => [
            'MorphOwner::query()->whereHasMorph("subject", \\MorphPost::class, fn ($q) => acceptPosts($q));',
            'void',
            ['invalid-argument'],
        ],
    ];
}
if (in_array('--unconfigured', $argv, true) || in_array('--malformed-identity', $argv, true)) {
    $cases = [
        'class identity not asserted' => [
            'MorphOwner::query()->whereHasMorph("subject", \\MorphPost::class, fn ($q) => acceptPosts($q));',
            'void',
            ['less-specific-argument'],
        ],
        'native return independent of identity assertion' => [
            'acceptOwners(MorphOwner::whereHasMorph("subject", \\MorphPost::class));',
            'void',
            [],
        ],
    ];
}
if (in_array('--partial-identity', $argv, true)) {
    $cases = [
        'asserted class retained' => [
            'MorphOwner::whereHasMorph("subject", \\MorphPost::class, fn ($q) => acceptPosts($q));',
            'void',
            [],
        ],
        'one unasserted class keeps full list native' => [
            'MorphOwner::query()->whereHasMorph("subject", [\\MorphPost::class, \\MorphVideo::class], fn ($q) => acceptBoth($q));',
            'void',
            ['less-specific-argument'],
        ],
        'identity spelling is exact' => [
            'MorphOwner::query()->whereHasMorph("subject", \\MORPHPOST::class, fn ($q) => acceptPosts($q));',
            'void',
            ['less-specific-argument'],
        ],
    ];
}
if (
    in_array('--custom-source', $argv, true)
    || in_array('--custom-helper', $argv, true)
    || in_array('--custom-owner', $argv, true)
) {
    $cases = [
        'custom implementation retains callback contract' => [
            'MorphOwner::query()->whereHasMorph("subject", \\MorphPost::class, fn ($q) => acceptPosts($q));',
            'void',
            ['less-specific-argument'],
        ],
        'custom implementation has no synthetic model return' => [
            'acceptOwners(MorphOwner::whereHasMorph("subject", \\MorphPost::class));',
            'void',
            ['mixed-argument', 'non-documented-method'],
        ],
    ];
}
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_morph_callbacks($cases, $command, $workspace);
$root = realpath($workspace);
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($files as $file) {
    $path = $file->getRealPath();
    if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Unsafe fixture cleanup');
    }
    if ($file->isDir()) {
        rmdir($path);
    } else {
        unlink($path);
    }
}
rmdir($workspace);

/** @param array<string, array{string, string, list<string>}> $cases
 * @param list<string> $command */
function check_morph_callbacks(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Database\Eloquent\Builder;
        use MorphPost as ImportedPost;
        /** @param Builder<MorphPost> $q */ function acceptPosts($q): void {}
        /** @param Builder<MorphOwner> $q */ function acceptOwners($q): void {}
        /** @param Builder<MorphPost>|Builder<MorphVideo> $q */ function acceptBoth($q): void {}
        /** @param class-string<MorphPost> $q */ function acceptPostClass($q): void {}
        /** @param class-string<MorphPost>|class-string<MorphVideo> $q */ function acceptBothClasses($q): void {}
        function acceptString(string $q): void {}
        function acceptInt(int $q): void {}
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
