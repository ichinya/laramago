<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago iterable property '.bin2hex(random_bytes(8));
mkdir($workspace, 0o777, true);
$declarations = <<<'PHP'
    <?php
    namespace Illuminate\Database\Eloquent;
    class Model {
        public function __get(string $name): mixed { return null; }
        public function __set(string $name, mixed $value): void {}
    }
    /** @template TKey of array-key
     * @template TValue */
    class Collection implements \IteratorAggregate {
        public function getIterator(): \Traversable { return new \ArrayIterator([]); }
        public function contains(object $item): bool { return true; }
    }

    namespace Example;
    file_put_contents(__DIR__.'/source-executed', 'Source must not run');
    use Illuminate\Database\Eloquent\Collection;
    use Illuminate\Database\Eloquent\Collection as AliasedCollection;
    use Illuminate\Database\Eloquent\{Collection as GroupedCollection};
    use Example\Item as AliasedItem;
    use Illuminate\Database\Eloquent\Model;
    class Item extends Model {}
    /** @template TKey of array-key
     * @template TValue */
    class PlainCollection { public function contains(object $item): bool { return true; } }
    /**
     * @property Collection|Item[] $items
     * @property Collection|Item[]|null $maybe
     * @property-read Collection|Item[] $readOnly
     * @property Collection|array<Item> $explicitArray
     * @property PlainCollection|Item[] $plainUnion
     * @property Collection|Missing[] $unknownItem
     * @property Collection|int[] $nonModelItem
     * @property Collection|Item[]|string $otherUnion
     * @property string $text
     */
    class Owner extends Model { public array $nativeItems = []; }
    class ChildOwner extends Owner {}
    /** @property string $items */
    class ChildOverride extends Owner {}
    /** @property Collection|Item[] $items */
    class SingleLineOwner extends Model {}
    /** @property AliasedCollection|AliasedItem[] $items */
    class AliasedOwner extends Model {}
    /** @property GroupedCollection|Item[] $items */
    class GroupedOwner extends Model {}
    PHP;
$cases = [
    'iterable shorthand' => 'function items(Owner $owner): bool { return $owner->items->contains(new Item); }',
    'inherited shorthand' => 'function inherited(ChildOwner $owner): bool { return $owner->items->contains(new Item); }',
    'single-line shorthand' => 'function singleLine(SingleLineOwner $owner): bool { return $owner->items->contains(new Item); }',
    'imported aliases' => 'function aliased(AliasedOwner $owner): bool { return $owner->items->contains(new Item); }',
    'grouped import' => 'function grouped(GroupedOwner $owner): bool { return $owner->items->contains(new Item); }',
    'child override priority' => 'function childOverride(ChildOverride $owner): bool { return $owner->items->contains(new Item); }',
    'nullable shorthand' => 'function maybe(Owner $owner): ?bool { return $owner->maybe?->contains(new Item); }',
    'nullable direct access' => 'function maybeDirect(Owner $owner): ?bool { return $owner->maybe->contains(new Item); }',
    'read-only shorthand' => 'function readOnly(Owner $owner): bool { return $owner->readOnly->contains(new Item); }',
    'explicit array branch' => 'function explicitArray(Owner $owner): bool { return $owner->explicitArray->contains(new Item); }',
    'non-traversable branch' => 'function plainUnion(Owner $owner): bool { return $owner->plainUnion->contains(new Item); }',
    'unknown item class' => 'function unknownItem(Owner $owner): bool { return $owner->unknownItem->contains(new Item); }',
    'non-model item' => 'function nonModelItem(Owner $owner): bool { return $owner->nonModelItem->contains(new Item); }',
    'unrelated union branch' => 'function otherUnion(Owner $owner): bool { return $owner->otherUnion->contains(new Item); }',
    'native property priority' => 'function nativeItems(Owner $owner): bool { return $owner->nativeItems->contains(new Item); }',
    'unrelated documented type' => 'function text(Owner $owner): bool { return $owner->text->contains(new Item); }',
    'unknown property' => 'function typo(Owner $owner): bool { return $owner->typo->contains(new Item); }',
    'read-only write' => 'function readOnlyWrite(Owner $owner): void { $owner->readOnly = [new Item]; }',
    'array write to shorthand' => 'function arrayWrite(Owner $owner): void { $owner->items = [new Item]; }',
];
$caseLines = [];
$line = substr_count($declarations, "\n") + 2;
foreach ($cases as $name => $_) {
    $caseLines[$name] = $line++;
}
file_put_contents($workspace.'/cases.php', $declarations."\n".implode("\n", $cases)."\n");
file_put_contents($workspace.'/worker.php', <<<'PHP'
    <?php
    require $argv[1];
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
        identifier: 'iterable-property-probe',
        name: 'Iterable property probe',
        version: '0.0.1',
        analyzerPlugins: [new class implements \Mago\Sdk\Analyzer\Plugin {
            public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
                return new \Mago\Sdk\Analyzer\PluginDefinition('iterable-property-probe', 'Iterable property probe', 'Probe property hook precedence.');
            }
            public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
                $provider = new \Ichinya\Laramago\Analyzer\IterablePropertyDocProvider($GLOBALS['argv'][2]);
                $registry->registerPropertyTypeProvider($provider);
                $registry->registerInitializationHook($provider);
            }
        }],
    )))->run();
    PHP);

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (bool $enabled) use ($workspace, $package, $command): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.5',
        'source' => ['paths' => ['cases.php']],
        'extension-hosts' => $enabled ? ['probe' => [
            'command' => [PHP_BINARY, $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 1,
        ]] : new stdClass,
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/report.json', 'w'],
        2 => ['file', $workspace.'/stderr.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Mago.');
    }
    fclose($pipes[0]);
    proc_close($process);
    $stderr = file_get_contents($workspace.'/stderr.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)) {
        throw new RuntimeException('Provider failure: '.$stderr);
    }
    return json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};

$summarize = static function (array $issues): array {
    $result = [];
    foreach ($issues as $issue) {
        $annotation = $issue['annotations'][0] ?? null;
        if (($annotation['span']['file_id']['name'] ?? null) !== 'cases.php') {
            continue;
        }
        $result[$annotation['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($result as &$codes) {
        sort($codes);
    }
    return $result;
};
$native = $summarize($run(false));
$adapted = $summarize($run(true));
foreach ($caseLines as $name => $line) {
    $before = $native[$line] ?? [];
    $after = $adapted[$line] ?? [];
    if (in_array($name, [
        'iterable shorthand', 'inherited shorthand', 'single-line shorthand', 'imported aliases', 'grouped import',
        'nullable shorthand', 'read-only shorthand',
    ], true)) {
        if (! in_array('invalid-method-access', $before, true) || $after !== []) {
            throw new RuntimeException($name.' must resolve the iterable shorthand without new diagnostics; inspect '.$workspace);
        }
    } elseif ($name === 'nullable direct access') {
        if (! in_array('invalid-method-access', $before, true)
            || in_array('invalid-method-access', $after, true)
            || ! in_array('possible-method-access-on-null', $after, true)) {
            throw new RuntimeException('Nullable shorthand must retain its null-access diagnostic; inspect '.$workspace);
        }
    } elseif ($name === 'array write to shorthand') {
        if ($before !== [] || $after !== ['invalid-property-assignment-value']) {
            throw new RuntimeException('The iterable shorthand must reject an array write; inspect '.$workspace);
        }
    } elseif ($before !== $after || $after === []) {
        throw new RuntimeException($name.' must retain its native diagnostic; native '.json_encode($before).'; adapted '.json_encode($after).'; inspect '.$workspace);
    }
    echo 'PASS: '.$name."\n";
}
if (is_file($workspace.'/source-executed')) {
    throw new RuntimeException('Application source was executed; inspect '.$workspace);
}
$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($items as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($resolved);
