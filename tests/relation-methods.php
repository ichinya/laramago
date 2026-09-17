<?php

declare(strict_types=1);

// Exercise relationship syntax inference in a separate, offline analyzer workspace.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago relation methods '.bin2hex(random_bytes(8));
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
file_put_contents($workspace.'/framework.php', $framework);
copy(__DIR__.'/fixtures/analysis/relation-methods.php.stub', $workspace.'/models.php');
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
$cases = [];
foreach ([
    'entries' => 'HasMany',
    'featured' => 'HasOne',
    'parentEntry' => 'BelongsTo',
    'members' => 'BelongsToMany',
    'distantEntries' => 'HasManyThrough',
    'distantEntry' => 'HasOneThrough',
    'image' => 'MorphOne',
    'images' => 'MorphMany',
    'tags' => 'MorphToMany',
] as $method => $kind) {
    $call = '$owner->'.$method.'()';
    $cases[$kind.' related model'] = ['return '.$call.'->getRelated();', 'Entry', []];
    $cases[$kind.' declaring model'] = ['return '.$call.'->getParent();', 'Owner', []];
    $parameters = match ($kind) {
        'HasManyThrough', 'HasOneThrough' => 'Entry, Intermediate, Owner',
        'BelongsToMany' => "Entry, Owner, Pivot, 'pivot'",
        'MorphToMany' => "Entry, Owner, MorphPivot, 'pivot'",
        default => 'Entry, Owner',
    };
    $cases[$kind.' generic relation'] = ['return '.$call.';', $kind.'<'.$parameters.'>', []];
    $cases[$kind.' generic query'] = ['return '.$call.'->getQuery();', 'Builder<Entry>', []];
    $cases[$kind.' nullable result'] = ['return '.$call.'->first();', '?Entry', []];
    $cases[$kind.' non-null result'] = ['return '.$call.'->firstOrFail();', 'Entry', []];
    $cases[$kind.' collection'] = ['return '.$call.'->get();', 'Collection<int, Entry>', []];
    $cases[$kind.' property'] = ['return '.$call.'->firstOrFail()->title;', 'string', []];
    $cases[$kind.' wrong result'] = ['return '.$call.'->firstOrFail();', 'OtherEntry', ['invalid-return-statement']];
}
$cases += [
    'expanded PHPDoc wins' => ['return $owner->documentedMembers()->getRelated();', 'OtherEntry', []],
    'inverse morph factory' => [
        'return $owner->taggedEntries();',
        "MorphToMany<Entry, Owner, MorphPivot, 'pivot'>",
        [],
    ],
    'native timestamp modifier chain' => [
        'return $owner->timestamped();',
        "BelongsToMany<Entry, Owner, Pivot, 'pivot'>",
        [],
    ],
    'inherited native modifier' => [
        'return $owner->timestampedTags();',
        "MorphToMany<Entry, Owner, MorphPivot, 'pivot'>",
        [],
    ],
    'through native modifier' => ['return $owner->trashedDistant();', 'HasManyThrough<Entry, Intermediate, Owner>', []],
    'through intermediate model' => ['return $owner->distantEntries()->getThroughParent();', 'Intermediate', []],
    'wrong intermediate model' => [
        'return $owner->distantEntries()->getThroughParent();',
        'Entry',
        ['invalid-return-statement'],
    ],
    'named factory arguments' => ['return $owner->named();', 'HasMany<Entry, Owner>', []],
    'descriptive comment' => ['return $owner->described();', 'HasMany<Entry, Owner>', []],
    'method case' => ['return $owner->ENTRIES();', 'HasMany<Entry, Owner>', []],
    'inherited relation owner' => ['return (new ChildOwner)->entries();', 'HasMany<Entry, ChildOwner>', []],
    'self target uses declaring model' => ['return (new ChildOwner)->selfRelation();', 'HasOne<Owner, ChildOwner>', []],
    'static target uses receiver model' => [
        'return (new ChildOwner)->staticRelation();',
        'HasOne<ChildOwner, ChildOwner>',
        [],
    ],
    'trait dispatch resolves' => [
        'return (new TraitOwner)->entries()->getRelated();',
        'Entry',
        [],
    ],
    'override relation' => ['return (new OverrideOwner)->entries();', 'HasMany<OtherEntry, OverrideOwner>', []],
    'PHPDoc wins' => ['return $owner->documented()->getRelated();', 'OtherEntry', []],
    'PHPStan PHPDoc wins' => ['return $owner->phpstanDocumented()->getRelated();', 'OtherEntry', []],
    'wrong property' => ['return $owner->entries()->firstOrFail()->missing;', 'mixed', ['non-documented-property']],
    'invalid write' => ['$owner->entries()->firstOrFail()->title = 42;', 'void', ['invalid-property-assignment-value']],
    'nullable property' => ['return $owner->entries()->first()->title;', '?string', ['possibly-null-property-access']],
    'collection property remains invalid' => [
        'return $owner->entries()->get()->title;',
        'mixed',
        ['non-existent-property'],
    ],
    'method typo' => ['$owner->entires();', 'void', ['non-documented-method']],
    'native arity check' => ['$owner->entries(42);', 'void', ['too-many-arguments']],
    'native named argument check' => ['$owner->entries(unknown: 42);', 'void', ['invalid-named-argument']],
    'native query signature' => ['$owner->entries()->first(42);', 'void', ['invalid-argument']],
    'first class callable' => ['return $owner->entries(...);', '\\Closure', []],
    'nullable declared relation defers' => ['return $owner->nullable()?->getRelated();', '?Model', []],
    'nullable declaration is not narrowed' => [
        'return $owner->nullable()?->getRelated();',
        '?Entry',
        ['less-specific-return-statement'],
    ],
    'mismatched relation defers' => [
        'return $owner->mismatched()->getRelated();',
        'Entry',
        ['less-specific-return-statement'],
    ],
    'protected visibility' => ['$owner->hidden();', 'void', ['invalid-method-access']],
    'private visibility' => ['$owner->secret();', 'void', ['invalid-method-access']],
];
foreach ([
    'dynamicMorphTarget',
    'missingThrough',
    'unknownThrough',
    'genericThrough',
    'dynamicThrough',
    'missingMorphName',
    'dynamicMorphName',
    'nullMorphName',
    'badInverse',
    'customPivot',
    'dynamicTimestamp',
    'invalidTimestampName',
    'unsupportedModifier',
    'conditional',
    'local',
    'dynamic',
    'literalString',
    'modified',
    'parameterized',
    'unrelated',
    'unpacked',
    'dynamicKey',
    'missingTarget',
    'invalidName',
    'duplicate',
    'broadContract',
] as $method) {
    $cases[$method.' defers'] = ['return $owner->'.$method.'()->getRelated();', 'Model', []];
    $cases[$method.' does not invent Entry'] = [
        'return $owner->'.$method.'()->getRelated();',
        'Entry',
        ['less-specific-return-statement'],
    ];
}
foreach ([
    'FactoryOverride',
    'ConstructorOverride',
    'RelatedOverride',
    'InheritedFactoryOverride',
    'TraitFactoryOverride',
    'DocumentedFactory',
    'GenericOwner',
    'GenericRelatedOwner',
    'UndefinedKeyOwner',
    'ParentTargetOwner',
    'CustomRelationOwner',
] as $class) {
    $cases[$class.' defers'] = [
        'return (new \\RelationFixtures\\'.$class.')->entries()->getRelated();',
        'Entry',
        ['less-specific-return-statement'],
    ];
}
foreach ([
    'ThroughFactoryOverride' => 'distantEntries',
    'ManyFactoryOverride' => 'members',
    'MorphFactoryOverride' => 'taggedEntries',
    'JoiningTableOverride' => 'members',
] as $class => $method) {
    $cases[$class.' expanded factory defers'] = [
        'return (new \\RelationFixtures\\'.$class.')->'.$method.'()->getRelated();',
        'Entry',
        ['less-specific-return-statement'],
    ];
}
check_relation_methods($cases, $command, $workspace);

// A changed installed template layout or factory contract must not be guessed.
file_put_contents($workspace.'/framework.php', str_replace('TDeclaringModel', 'TOwnerModel', $framework));
check_relation_methods(
    [
        'changed installed templates defer' => [
            'return $owner->entries()->getRelated();',
            'Entry',
            ['less-specific-return-statement'],
        ],
    ],
    $command,
    $workspace,
);
file_put_contents($workspace.'/framework.php', str_replace(
    'function hasMany($related,',
    'function hasMany($target,',
    $framework,
));
check_relation_methods(
    [
        'changed installed parameter names defer' => [
            'return $owner->entries()->getRelated();',
            'Entry',
            ['less-specific-return-statement'],
        ],
    ],
    $command,
    $workspace,
);
// Native modifiers must still return the same relation in the installed metadata.
file_put_contents($workspace.'/framework.php', str_replace(
    '/** @return $this */'."\n".'    public function withTimestamps',
    '/** @return string */'."\n".'    public function withTimestamps',
    $framework,
));
check_relation_methods(
    [
        'changed modifier return defers' => [
            'return $owner->timestamped()->getRelated();',
            'Entry',
            ['less-specific-return-statement'],
        ],
    ],
    $command,
    $workspace,
);
file_put_contents($workspace.'/framework.php', str_replace('TIntermediateModel', 'TThroughModel', $framework));
check_relation_methods(
    [
        'changed through template layout defers' => [
            'return $owner->distantEntries()->getRelated();',
            'Entry',
            ['less-specific-return-statement'],
        ],
    ],
    $command,
    $workspace,
);
file_put_contents($workspace.'/framework.php', str_replace(
    "TAccessor of string = 'pivot'",
    "TAccessor of string = 'link'",
    $framework,
));
check_relation_methods(
    [
        'changed pivot accessor default defers' => [
            'return $owner->members()->getRelated();',
            'Entry',
            ['less-specific-return-statement'],
        ],
    ],
    $command,
    $workspace,
);
file_put_contents($workspace.'/framework.php', $framework);

// A new worker run reads changed relationship bodies from the same workspace.
$models = file_get_contents($workspace.'/models.php');
file_put_contents($workspace.'/models.php', str_replace(
    'public function entries(): HasMany { return $this->hasMany(Item::class); }',
    'public function entries(): HasMany { return $this->hasMany(OtherEntry::class); }',
    $models,
));
check_relation_methods(
    [
        'changed source is reread' => ['return $owner->entries()->getRelated();', 'OtherEntry', []],
    ],
    $command,
    $workspace,
);
file_put_contents($workspace.'/models.php', $models);

// Compare the same concrete contracts to the native analyzer without the plugin.
$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_relation_methods(
    [
        'native relation lacks related model' => [
            'return $owner->entries()->getRelated();',
            'Entry',
            ['less-specific-return-statement'],
        ],
        'native relation lacks declaring model' => [
            'return $owner->entries()->getParent();',
            'Owner',
            ['less-specific-return-statement'],
        ],
        'native explicit contract works' => ['return $owner->documented()->getRelated();', 'OtherEntry', []],
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
function check_relation_methods(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use RelationFixtures\{Intermediate, Owner, ChildOwner, TraitOwner, OverrideOwner, Entry, OtherEntry, FactoryOverride, ConstructorOverride, RelatedOverride};
        use Illuminate\Database\Eloquent\{Model, Builder, Collection};
        use Illuminate\Database\Eloquent\Relations\{HasMany, HasOne, BelongsTo, BelongsToMany, HasManyThrough, HasOneThrough, MorphOne, MorphMany, MorphToMany, Pivot, MorphPivot};
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
