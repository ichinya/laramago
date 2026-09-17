<?php

declare(strict_types=1);

// Exercise relationship syntax inference in a separate, offline analyzer workspace.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago relation contracts '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = file_get_contents(__DIR__.'/fixtures/analysis/framework.php.stub');
$framework = str_replace(
    "class Model\n{",
    "class Model\n{\n    use \\Illuminate\\Database\\Eloquent\\Concerns\\HasRelationships;",
    str_replace("\r\n", "\n", $framework),
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
$framework = str_replace("\r\n", "\n", $framework);
$framework = str_replace('class BelongsToMany extends Relation {', <<<'PHP'
    class BelongsToMany extends Relation {
        /** @template TNewPivotModel of Pivot
         * @param class-string<TNewPivotModel> $class
         * @return $this */
        public function using($class) {}
        /** @template TNewAccessor of string
         * @param TNewAccessor $accessor
         * @return $this */
        public function as($accessor) {}
        /** @return class-string<TPivotModel> */
        public function getPivotClass() {}
    PHP, $framework);
$framework = str_replace('class Builder'."\n".'{', <<<'PHP'
    class Builder
    {
        /** @param string $relation
         * @param (\Closure(self<\Illuminate\Database\Eloquent\Model>): mixed)|null $callback
         * @return $this */
        public function whereHas($relation, $callback = null) { return $this; }
    PHP, $framework);
file_put_contents($workspace.'/framework.php', $framework);
file_put_contents(
    $workspace.'/models.php',
    file_get_contents(__DIR__.'/fixtures/analysis/relation-methods.php.stub')
        .substr(file_get_contents(__DIR__.'/fixtures/analysis/relation-contracts.php.stub'), 5),
);
file_put_contents(
    $workspace.'/bootstrap.php',
    '<?php throw new RuntimeException("Application bootstrap must never execute.");',
);
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 3,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$cases = [
    'trait callback receives related builder' => [
        'TraitOwner::whereHas("entries", fn ($q) => acceptEntry($q->firstOrFail()));',
        'void',
        [],
    ],
    'pivot callback receives related builder' => [
        '\\RelationFixtures\\ContractOwner::whereHas("linked", fn ($q) => acceptEntry($q->firstOrFail()));',
        'void',
        [],
    ],
    'trait callback null safety' => [
        'TraitOwner::whereHas("entries", fn ($q) => acceptEntry($q->first()));',
        'void',
        ['possibly-null-argument'],
    ],
    'trait self reimported in child' => [
        'return (new \\RelationFixtures\\ReimportedChild)->selfEntries()->getRelated();',
        '\\RelationFixtures\\ReimportedChild',
        [],
    ],
    'generic trait defers' => [
        'return (new \\RelationFixtures\\GenericTraitOwner)->genericEntries()->getRelated();',
        'Entry',
        ['less-specific-return-statement'],
    ],
    'explicit generic model contract' => [
        'return (new \\RelationFixtures\\ConcreteGenericOwner)->documentedGeneric()->getRelated();',
        'Entry',
        [],
    ],

    'trait related model' => ['return (new TraitOwner)->entries()->getRelated();', 'Entry', []],
    'trait lexical self' => ['return (new TraitOwner)->selfEntries()->getRelated();', 'TraitOwner', []],
    'inherited trait lexical self' => [
        'return (new \\RelationFixtures\\TraitChild)->selfEntries()->getRelated();',
        'TraitOwner',
        [],
    ],
    'nested trait relation' => ['return (new \\RelationFixtures\\NestedOwner)->entries()->getRelated();', 'Entry', []],
    'trait alias' => ['return (new \\RelationFixtures\\AliasedOwner)->aliasEntries()->getRelated();', 'Entry', []],
    'trait late static' => [
        'return (new \\RelationFixtures\\TraitChild)->lateEntries()->getRelated();',
        '\\RelationFixtures\\TraitChild',
        [],
    ],
    'trait override wins' => [
        'return (new \\RelationFixtures\\OverrideTraitOwner)->entries()->getRelated();',
        'OtherEntry',
        [],
    ],
    'trait PHPDoc wins' => [
        'return (new \\RelationFixtures\\ContractOwner)->documentedEntries()->getRelated();',
        'OtherEntry',
        [],
    ],
    'trait broad PHPDoc is not narrowed' => [
        'return (new \\RelationFixtures\\ContractOwner)->broadEntries()->getRelated();',
        'Entry',
        ['less-specific-return-statement'],
    ],
    'trait custom factory defers' => [
        'return (new \\RelationFixtures\\FactoryTraitOwner)->entries()->getRelated();',
        'Entry',
        ['less-specific-return-statement'],
    ],
    'trait nullable result remains nullable' => [
        'return (new TraitOwner)->entries()->first()->title;',
        '?string',
        ['possibly-null-property-access'],
    ],
    'custom pivot generic' => [
        'return (new \\RelationFixtures\\ContractOwner)->linked();',
        "BelongsToMany<Entry, \\RelationFixtures\\ContractOwner, \\RelationFixtures\\CustomPivot, 'membership'>",
        [],
    ],
    'custom pivot class' => [
        'return (new \\RelationFixtures\\ContractOwner)->linked()->getPivotClass();',
        'class-string<\\RelationFixtures\\CustomPivot>',
        [],
    ],
    'custom pivot related' => ['return (new \\RelationFixtures\\ContractOwner)->linked()->getRelated();', 'Entry', []],
    'custom morph pivot' => [
        'return (new \\RelationFixtures\\ContractOwner)->morphLinked();',
        "MorphToMany<Entry, \\RelationFixtures\\ContractOwner, \\RelationFixtures\\CustomMorphPivot, 'tag'>",
        [],
    ],
    'last pivot modifier wins' => [
        'return (new \\RelationFixtures\\ContractOwner)->relinked();',
        "BelongsToMany<Entry, \\RelationFixtures\\ContractOwner, \\RelationFixtures\\SecondPivot, 'second'>",
        [],
    ],
    'wrong pivot rejected' => [
        'return (new \\RelationFixtures\\ContractOwner)->linked()->getPivotClass();',
        'class-string<\\RelationFixtures\\SecondPivot>',
        ['invalid-return-statement'],
    ],
];
foreach ([
    'dynamicPivot',
    'nonPivot',
    'invalidUsingName',
    'missingUsing',
    'dynamicAccessor',
    'emptyAccessor',
    'invalidMorphPivot',
] as $method) {
    $cases[$method.' defers'] = [
        'return (new \\RelationFixtures\\ContractOwner)->'.$method.'()->getRelated();',
        'Entry',
        ['less-specific-return-statement'],
    ];
}
check_relation_contracts($cases, $command, $workspace);
file_put_contents($workspace.'/framework.php', str_replace(
    'function using($class)',
    'function using($pivot)',
    $framework,
));
check_relation_contracts(
    [
        'changed pivot signature defers' => [
            'return (new \\RelationFixtures\\ContractOwner)->linked()->getRelated();',
            'Entry',
            ['less-specific-return-statement'],
        ],
    ],
    $command,
    $workspace,
);
file_put_contents($workspace.'/framework.php', $framework);
file_put_contents($workspace.'/framework.php', str_replace(
    '* @return $this */'."\n".'    public function using',
    '* @return string */'."\n".'    public function using',
    $framework,
));
check_relation_contracts(
    [
        'changed pivot return defers' => [
            'return (new \\RelationFixtures\\ContractOwner)->linked()->getRelated();',
            'Entry',
            ['less-specific-return-statement'],
        ],
    ],
    $command,
    $workspace,
);
file_put_contents($workspace.'/framework.php', $framework);
$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_relation_contracts(
    [
        'disabled trait callback' => [
            'TraitOwner::whereHas("entries", fn ($q) => acceptEntry($q->firstOrFail()));',
            'void',
            ['mixed-argument', 'mixed-method-access', 'non-documented-method'],
        ],
        'disabled trait inference' => [
            'return (new TraitOwner)->entries()->getRelated();',
            'Entry',
            ['less-specific-return-statement'],
        ],
        'disabled pivot inference' => [
            'return (new \\RelationFixtures\\ContractOwner)->linked()->getRelated();',
            'Entry',
            ['less-specific-return-statement'],
        ],
        'disabled explicit PHPDoc still wins' => [
            'return (new \\RelationFixtures\\ContractOwner)->documentedEntries()->getRelated();',
            'OtherEntry',
            [],
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
function check_relation_contracts(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use RelationFixtures\{Intermediate, Owner, ChildOwner, TraitOwner, OverrideOwner, Entry, OtherEntry, FactoryOverride, ConstructorOverride, RelatedOverride};
        use Illuminate\Database\Eloquent\{Model, Builder, Collection};
        use Illuminate\Database\Eloquent\Relations\{HasMany, HasOne, BelongsTo, BelongsToMany, HasManyThrough, HasOneThrough, MorphOne, MorphMany, MorphToMany, Pivot, MorphPivot};
        function acceptEntry(Entry $entry): void {}
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .= 'function scenario'.count($lines).'(Owner $owner) { '.$body.' }'."\n";
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
    if (
        ! in_array($exit, [0, 1], true)
        || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
    ) {
        throw new RuntimeException('Unexpected analyzer failure; inspect '.$workspace);
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
        throw new RuntimeException('Unexpected diagnostics outside relation scenarios; inspect '.$workspace);
    }
}
