<?php

declare(strict_types=1);

// Exercise bounded eager-load projection checks through the real Mago SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago related projections '.bin2hex(random_bytes(8));
mkdir($workspace);

$framework = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/fixtures/analysis/framework.php.stub'));
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
$eagerMethods = <<<'PHP'

        /** @return $this */
        public function with($relations, $callback = null)
        {
            if ($callback instanceof \Closure) {
                $eagerLoad = $this->parseWithRelations([$relations => $callback]);
            } else {
                $eagerLoad = $this->parseWithRelations(is_string($relations) ? func_get_args() : $relations);
            }
            return $this;
        }

        protected function parseWithRelations(array $relations)
        {
            $results = [];
            foreach ($relations as $key => $value) {
                if (is_numeric($key) && is_string($value)) {
                    [$key, $value] = $this->parseNameAndAttributeSelectionConstraint($value);
                }
                $results[$key] = $value;
            }
            return $results;
        }

        protected function parseNameAndAttributeSelectionConstraint($name)
        {
            return str_contains($name, ':')
                ? $this->createSelectWithConstraint($name)
                : [$name, static function () {}];
        }

        protected function createSelectWithConstraint($name)
        {
            return [explode(':', $name)[0], static function ($query) use ($name) {
                $query->select(array_map(static function ($column) use ($query) {
                    return $query instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany
                        ? $query->getRelated()->qualifyColumn($column)
                        : $column;
                }, explode(',', explode(':', $name)[1])));
            }];
        }

    PHP;
$needle = "class Builder\n{\n    /** @return TModel */";
$framework = str_replace($needle, "class Builder\n{".$eagerMethods.'    /** @return TModel */', $framework);
file_put_contents($workspace.'/framework.php', $framework);
copy(__DIR__.'/fixtures/analysis/related-projection-models.php.stub', $workspace.'/models.php');
copy(__DIR__.'/fixtures/analysis/related-projection-worker.php.stub', $workspace.'/worker.php');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application must not boot.");');

$native = static fn (string $related, array $columns): array => [
    'complete' => true,
    'native-column-semantics' => true,
    'related-model' => $related,
    'columns' => $columns,
];
$contracts = [
    'RelatedProjectionFixtures\\Owner::entries' => $native(
        'RelatedProjectionFixtures\\Entry',
        ['id', 'title', 'entries.id'],
    ),
    'RelatedProjectionFixtures\\Owner::entry' => $native('RelatedProjectionFixtures\\Entry', ['id']),
    'RelatedProjectionFixtures\\Owner::parentEntry' => $native('RelatedProjectionFixtures\\Entry', ['id']),
    'RelatedProjectionFixtures\\Owner::images' => $native('RelatedProjectionFixtures\\Entry', ['id']),
    'RelatedProjectionFixtures\\Owner::image' => $native('RelatedProjectionFixtures\\Entry', ['id']),
    'RelatedProjectionFixtures\\Owner::members' => $native('RelatedProjectionFixtures\\Entry', ['id']),
    'RelatedProjectionFixtures\\Owner::distantEntries' => $native('RelatedProjectionFixtures\\Entry', ['id']),
    'RelatedProjectionFixtures\\Owner::modified' => $native('RelatedProjectionFixtures\\Entry', ['id']),
    'RelatedProjectionFixtures\\Owner::documented' => $native('RelatedProjectionFixtures\\OtherEntry', ['id']),
    'RelatedProjectionFixtures\\Owner::customQueryEntries' => $native(
        'RelatedProjectionFixtures\\CustomQueryEntry',
        ['id'],
    ),
    'RelatedProjectionFixtures\\CustomOwner::entries' => $native('RelatedProjectionFixtures\\Entry', ['id']),
    'RelatedProjectionFixtures\\NewInstanceOwner::entries' => $native('RelatedProjectionFixtures\\Entry', ['id']),
    'RelatedProjectionFixtures\\ResolverOwner::entries' => $native('RelatedProjectionFixtures\\Entry', ['id']),
    'RelatedProjectionFixtures\\Owner::mismatchedEntries' => $native(
        'RelatedProjectionFixtures\\OtherEntry',
        ['id'],
    ),
    'RelatedProjectionFixtures\\Owner::incompleteEntries' => [
        'complete' => false,
        'native-column-semantics' => true,
        'related-model' => 'RelatedProjectionFixtures\\IncompleteEntry',
        'columns' => ['id'],
    ],
    'RelatedProjectionFixtures\\Owner::unassertedEntries' => [
        'complete' => true,
        'related-model' => 'RelatedProjectionFixtures\\UnassertedEntry',
        'columns' => ['id'],
    ],
    'RelatedProjectionFixtures\\Owner::malformedEntries' => $native(
        'RelatedProjectionFixtures\\MalformedEntry',
        ['id', false],
    ),
    'RelatedProjectionFixtures\\Owner::duplicateEntries' => $native(
        'RelatedProjectionFixtures\\DuplicateEntry',
        ['id'],
    ),
    'relatedprojectionfixtures\\owner::duplicateentries' => $native(
        'RelatedProjectionFixtures\\DuplicateEntry',
        ['id'],
    ),
];
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
    'extra' => ['laramago' => ['relation-query-sources' => $contracts]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                in_array('--full-plugin', $argv, true) ? $package.'/bin/laramago-worker.php' : $workspace.'/worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 1,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$missing = ['ichinya/laramago/laramago-related-projection-missing-column'];
$cases = [
    'missing has-many column' => ['Owner::query()->with("entries:missing")->get();', $missing],
    'valid has-many columns' => ['Owner::query()->with("entries:id,title")->get();', []],
    'exact qualified column' => ['Owner::query()->with("entries:entries.id")->first();', []],
    'case-sensitive column' => ['Owner::query()->with("entries:ID")->firstOrFail();', $missing],
    'relation method case is insensitive' => ['Owner::query()->with("Entries:missing")->get();', $missing],
    'missing has-one column' => ['Owner::query()->with("entry:missing")->sole();', $missing],
    'missing belongs-to column' => ['Owner::query()->with("parentEntry:missing")->get();', $missing],
    'missing morph-many column' => ['Owner::query()->with("images:missing")->get();', $missing],
    'missing morph-one column' => ['Owner::query()->with("image:missing")->get();', $missing],
    'import alias resolves exact owner' => ['OwnerAlias::query()->with("entries:missing")->get();', $missing],
    'named relations argument' => ['Owner::query()->with(relations: "entries:missing")->get();', $missing],
    'pivot relation defers' => ['Owner::query()->with("members:missing")->get();', []],
    'through relation defers' => ['Owner::query()->with("distantEntries:missing")->get();', []],
    'modified relation query defers' => ['Owner::query()->with("modified:missing")->get();', []],
    'PHPDoc related model precedence defers' => ['Owner::query()->with("documented:missing")->get();', []],
    'custom related query factory defers' => ['Owner::query()->with("customQueryEntries:missing")->get();', []],
    'custom owner query factory defers' => ['CustomOwner::query()->with("entries:missing")->get();', []],
    'custom new instance defers' => ['NewInstanceOwner::query()->with("entries:missing")->get();', []],
    'custom relation resolver defers' => ['ResolverOwner::query()->with("entries:missing")->get();', []],
    'owner relation contract is not inherited' => ['ChildOwner::query()->with("entries:missing")->get();', []],
    'relation source contracts are exact' => ['Owner::query()->with("childEntries:missing")->get();', []],
    'related model mismatch defers' => ['Owner::query()->with("mismatchedEntries:missing")->get();', []],
    'incomplete relation source defers' => ['Owner::query()->with("incompleteEntries:missing")->get();', []],
    'unasserted semantics defer' => ['Owner::query()->with("unassertedEntries:missing")->get();', []],
    'malformed source defers' => ['Owner::query()->with("malformedEntries:missing")->get();', []],
    'duplicate source defers' => ['Owner::query()->with("duplicateEntries:missing")->get();', []],
    'nested relation path defers' => ['Owner::query()->with("entries.owner:missing")->get();', []],
    'wildcard defers' => ['Owner::query()->with("entries:*")->get();', []],
    'qualified wildcard defers' => ['Owner::query()->with("entries:entries.*")->get();', []],
    'alias defers' => ['Owner::query()->with("entries:id as key")->get();', []],
    'SQL expression defers' => ['Owner::query()->with("entries:count(*)")->get();', []],
    'dynamic projection defers' => ['$name = "entries:missing"; Owner::query()->with($name)->get();', []],
    'array projection defers' => ['Owner::query()->with(["entries:missing"])->get();', []],
    'callback overload defers' => ['Owner::query()->with("entries:missing", fn ($query) => $query)->get();', []],
    'nonterminal builder defers' => ['Owner::query()->with("entries:missing");', []],
    'variable builder defers' => ['$query = Owner::query(); $query->with("entries:missing")->get();', []],
    'terminal arguments defer' => ['Owner::query()->with("entries:missing")->get(["id"]);', []],
    'later query mutation defers' => ['Owner::query()->with("entries:missing")->where("id", 1)->get();', []],
    'repeated eager constraints defer' => [
        'Owner::query()->with("entries:missing")->with("entries:id")->get();',
        [],
    ],
    'static with stays native' => [
        'Owner::with("entries:missing")->get();',
        ['mixed-method-access', 'non-documented-method'],
    ],
];

$source = <<<'PHP'
    <?php
    use RelatedProjectionFixtures\Owner;
    use RelatedProjectionFixtures\Owner as OwnerAlias;
    use RelatedProjectionFixtures\CustomOwner;
    use RelatedProjectionFixtures\ChildOwner;
    use RelatedProjectionFixtures\NewInstanceOwner;
    use RelatedProjectionFixtures\ResolverOwner;
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $codes]) {
    $source .= 'function scenario'.count($lines).'(): void { '.$body.' }'."\n";
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
if (! in_array($exit, [0, 1], true) || preg_match('/provider failed|rejected request/i', $log)) {
    throw new RuntimeException('Expected extension analysis without fallback; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
$failures = [];
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    unset($actual[$line]);
    if ($codes !== $expected) {
        $failures[] = $name.': expected '.json_encode($expected).', got '.json_encode($codes);
        continue;
    }
    echo 'PASS: '.$name."\n";
}
if ($failures !== [] || $actual !== []) {
    throw new RuntimeException(
        implode("\n", $failures).'; extra diagnostics: '.json_encode($actual).'; inspect '.$workspace,
    );
}
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
