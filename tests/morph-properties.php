<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago morph properties '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = file_get_contents(__DIR__.'/fixtures/analysis/framework.php.stub');
$framework = str_replace(
    "class Model\r\n{",
    "class Model\r\n{\r\n    public function morphTo(): \\Illuminate\\Database\\Eloquent\\Relations\\MorphTo {}",
    $framework,
);
$framework = str_replace(
    "class Model\n{",
    "class Model\n{\n    public function morphTo(): \\Illuminate\\Database\\Eloquent\\Relations\\MorphTo {}",
    $framework,
);
$framework .= <<<'PHP'

    namespace Illuminate\Database\Eloquent\Relations;
    /** @template TRelatedModel of \Illuminate\Database\Eloquent\Model */
    class MorphTo {
        /** @return $this */
        public function withDefault($callback = true) {}
    }
    PHP;
file_put_contents($workspace.'/framework.php', $framework);
file_put_contents($workspace.'/models.php', <<<'PHP'
    <?php
    namespace RelationFixtures;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\MorphTo;
    class Entry extends Model { public string $title; }
    class OtherEntry extends Model { public string $label; }
    class CustomMorph extends MorphTo { public function getResults(): string { return "custom"; } }
    class Owner extends Model {
        /** @return MorphTo<string> */
    public function malformed(): MorphTo { return $this->morphTo(); }
    public function customSubject(): CustomMorph { return new CustomMorph(); }
    public function subject(): MorphTo { return $this->morphTo(); }
        /** @return MorphTo<Entry|OtherEntry> */
        public function documented(): MorphTo { return $this->morphTo(); }
        public function defaultSubject(): MorphTo { return $this->morphTo()->withDefault(); }
        public function emptyDefault(): MorphTo { return $this->morphTo()->withDefault([]); }
        public function disabledDefault(): MorphTo { return $this->morphTo()->withDefault()->withDefault(false); }
        public function callbackDefault(): MorphTo { return $this->morphTo()->withDefault(fn () => 'custom'); }
        public function callableDefault(): MorphTo { return $this->morphTo()->withDefault([1 => 'make', 0 => self::class]); }
        public function attributesDefault(): MorphTo { return $this->morphTo()->withDefault(['name' => 'Guest', 'active' => true]); }
    }
    class FactoryOverride extends Owner { public function morphTo(): MorphTo {} }
    class ResultOverride extends Owner { public function getRelationshipFromMethod($method): mixed {} }
    class ConstructorOverride extends Owner { protected function newMorphTo(): MorphTo {} }
    /** @property-read string $subject */
    class DocumentedOwner extends Owner {}
    PHP);
file_put_contents($workspace.'/composer.json', '{}');
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
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
check_relation_contracts(
    [
        'malformed doc stays unknown' => ['return $owner->malformed;', 'mixed', ['non-documented-property']],
        'custom result relation stays unknown' => [
            'return $owner->customSubject;',
            'mixed',
            ['non-documented-property'],
        ],
        'native nullable model' => ['return $owner->subject;', 'Model|null', []],
        'instanceof refines model' => [
            '$subject = $owner->subject; return $subject instanceof Entry ? $subject->title : "none";',
            'string',
            [],
        ],
        'explicit union survives' => ['return $owner->documented;', 'Entry|OtherEntry|null', []],
        'default model' => ['return $owner->defaultSubject;', 'Model', []],
        'empty array remains nullable' => [
            'return $owner->emptyDefault;',
            'Model',
            ['invalid-return-statement', 'nullable-return-statement'],
        ],
        'last default false remains nullable' => [
            'return $owner->disabledDefault;',
            'Model',
            ['invalid-return-statement', 'nullable-return-statement'],
        ],
        'nullable cannot become concrete' => [
            'return $owner->subject;',
            'Entry',
            ['less-specific-return-statement', 'nullable-return-statement'],
        ],
        'unknown property remains error' => ['return $owner->subjet;', 'mixed', ['non-documented-property']],
        'unknown model attr remains error' => ['return $owner->subject?->title;', 'mixed', ['non-documented-property']],
        'factory override stays unknown' => [
            'return (new FactoryOverride)->subject;',
            'mixed',
            ['non-documented-property'],
        ],
        'constructor override stays unknown' => [
            'return (new ConstructorOverride)->subject;',
            'mixed',
            ['non-documented-property'],
        ],
        'result override stays unknown' => [
            'return (new \\RelationFixtures\\ResultOverride)->subject;',
            'mixed',
            ['non-documented-property'],
        ],
        'callback default stays unknown' => ['return $owner->callbackDefault;', 'mixed', ['non-documented-property']],
        'callable array default stays unknown' => [
            'return $owner->callableDefault;',
            'mixed',
            ['non-documented-property'],
        ],
        'attribute array default preserves model' => ['return $owner->attributesDefault;', 'Model', []],
        'explicit property wins' => ['return (new \\RelationFixtures\\DocumentedOwner)->subject;', 'string', []],
    ],
    $command,
    $workspace,
);
$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
check_relation_contracts(
    ['disabled morph property' => ['return $owner->subject;', 'mixed', ['non-documented-property']]],
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
/** @param array<string, array{string, string, list<string>}> $cases
 * @param list<string> $command */
function check_relation_contracts(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use RelationFixtures\{Owner, Entry, OtherEntry, FactoryOverride, ConstructorOverride};
        use Illuminate\Database\Eloquent\{Model, Builder, Collection};

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
