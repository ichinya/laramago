<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));

foreach (['native', 'custom-helper', 'custom-constructor'] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago collect keys '.bin2hex(random_bytes(8));
    $framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Collections';
    mkdir($framework.'/Traits', 0777, true);
    file_put_contents($framework.'/helpers.php', <<<'PHP'
        <?php
        use Illuminate\Support\Collection;

        if (! function_exists('collect')) {
            /**
             * Create a collection from the given value.
             *
             * @template TKey of array-key
             * @template TValue
             *
             * @param  \Illuminate\Contracts\Support\Arrayable<TKey, TValue>|iterable<TKey, TValue>|null  $value
             * @return \Illuminate\Support\Collection<TKey, TValue>
             */
            function collect($value = []): Collection
            {
                return new Collection($value);
            }
        }
        PHP);
    file_put_contents($framework.'/Collection.php', <<<'PHP'
        <?php
        namespace Illuminate\Support;

        use Illuminate\Support\Traits\EnumeratesValues;

        /**
         * @template TKey of array-key
         * @template-covariant TValue
         */
        class Collection
        {
            use EnumeratesValues;

            /** @var array<TKey, TValue> */
            protected $items = [];

            /** @param iterable<TKey, TValue>|null $items */
            public function __construct($items = [])
            {
                $this->items = $this->getArrayableItems($items);
            }
        }
        PHP);
    file_put_contents($framework.'/Traits/EnumeratesValues.php', <<<'PHP'
        <?php
        namespace Illuminate\Support\Traits;

        use Illuminate\Support\Arr;
        use UnitEnum;

        trait EnumeratesValues
        {
            protected function getArrayableItems($items)
            {
                return is_null($items) || is_scalar($items) || $items instanceof UnitEnum
                    ? Arr::wrap($items)
                    : Arr::from($items);
            }
        }
        PHP);
    file_put_contents($framework.'/Arr.php', <<<'PHP'
        <?php
        namespace Illuminate\Support;

        use Illuminate\Contracts\Support\Arrayable;
        use Illuminate\Contracts\Support\Jsonable;
        use InvalidArgumentException;
        use JsonSerializable;
        use Traversable;
        use WeakMap;

        class Arr
        {
            public static function from($items)
            {
                return match (true) {
                    is_array($items) => $items,
                    $items instanceof Enumerable => $items->all(),
                    $items instanceof Arrayable => $items->toArray(),
                    $items instanceof WeakMap => iterator_to_array($items, false),
                    $items instanceof Traversable => iterator_to_array($items),
                    $items instanceof Jsonable => json_decode($items->toJson(), true),
                    $items instanceof JsonSerializable => (array) $items->jsonSerialize(),
                    is_object($items) => (array) $items,
                    default => throw new InvalidArgumentException('Items cannot be represented by a scalar value.'),
                };
            }
        }
        PHP);
    if ($mode === 'custom-helper') {
        $helper = file_get_contents($framework.'/helpers.php');
        file_put_contents($framework.'/helpers.php', str_replace('return new Collection($value);', 'return new Collection(array_values($value));', $helper));
    }
    if ($mode === 'custom-constructor') {
        $collection = file_get_contents($framework.'/Collection.php');
        file_put_contents($framework.'/Collection.php', str_replace(
            '$this->items = $this->getArrayableItems($items);',
            '$this->items = array_values($this->getArrayableItems($items));',
            $collection,
        ));
    }
    $native = $mode === 'native';
    $cases = [
        'list keys widen to int' => [
            '/** @param list<Record> $records @return Collection<int, Record> */',
            'function scenario0(array $records) { return collect($records); }',
            $native ? [] : ['invalid-return-statement'],
        ],
        'nested array items retain their type' => [
            '/** @param list<array<string, string|null>> $rows @return Collection<int, array<string, string|null>> */',
            'function scenario1(array $rows) { return collect($rows); }',
            $native ? [] : ['invalid-return-statement'],
        ],
        'literal list argument' => [
            '/** @param Collection<int, Record> $records */',
            'function scenario2(Collection $records): void {} function call2(Record $record): void { scenario2(collect([$record])); }',
            $native ? [] : ['invalid-argument'],
        ],
        'named helper argument' => [
            '/** @param list<Record> $records @return Collection<int, Record> */',
            'function scenario3(array $records) { return collect(value: $records); }',
            $native ? [] : ['invalid-return-statement'],
        ],
        'string keys stay string' => [
            '/** @param array{first: Record} $records @return Collection<string, Record> */',
            'function scenario4(array $records) { return collect($records); }',
            $native ? [] : ['invalid-return-statement'],
        ],
        'integer shape keys widen to int' => [
            '/** @param array{5: Record} $records @return Collection<int, Record> */',
            'function scenario5(array $records) { return collect($records); }',
            $native ? [] : ['invalid-return-statement'],
        ],
        'string keys are not converted to int' => [
            '/** @param array{first: Record} $records @return Collection<int, Record> */',
            'function scenario6(array $records) { return collect($records); }',
            ['invalid-return-statement'],
        ],
        'wrong item class remains diagnostic' => [
            '/** @param list<OtherRecord> $records @return Collection<int, Record> */',
            'function scenario7(array $records) { return collect($records); }',
            ['invalid-return-statement'],
        ],
        'unknown items stay unknown' => [
            '/** @param list<mixed> $records @return Collection<int, Record> */',
            'function scenario8(array $records) { return collect($records); }',
            ['invalid-return-statement'],
        ],
        'unknown iterable defers' => [
            '/** @param iterable<string, Record> $records @return Collection<int, Record> */',
            'function scenario9(iterable $records) { return collect($records); }',
            ['invalid-return-statement'],
        ],
    ];
    $source = "<?php\nuse Illuminate\\Support\\Collection;\nclass Record {}\nclass OtherRecord {}\n";
    $lines = [];
    foreach ($cases as $name => [$doc, $code, $expected]) {
        $doc = str_replace(' @return ', "\n * @return ", $doc);
        $source .= $doc."\n".$code."\n";
        $lines[substr_count($source, "\n")] = [$name, $expected];
    }
    file_put_contents($workspace.'/cases.php', $source);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => [
            'vendor/laravel/framework/src/Illuminate/Collections/helpers.php',
            'vendor/laravel/framework/src/Illuminate/Collections/Collection.php',
            'vendor/laravel/framework/src/Illuminate/Collections/Traits/EnumeratesValues.php',
            'vendor/laravel/framework/src/Illuminate/Collections/Arr.php',
        ]],
        'extension-hosts' => ['laramago' => ['command' => [
            PHP_BINARY,
            $package.'/bin/laramago-worker.php',
            $package.'/vendor/autoload.php',
            $workspace,
        ], 'workers' => 2]],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/stderr.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Unexpected Mago failure in '.$mode.'; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $a): bool => $a['kind'] === 'Primary'))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException($mode.' / '.$name.': expected '.json_encode($expected)
                .', got '.json_encode($codes).'; inspect '.$workspace);
        }
        unset($actual[$line]);
        echo 'PASS ['.$mode.']: '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics outside collection scenarios; inspect '.$workspace);
    }
    $resolvedWorkspace = realpath($workspace);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $entry) {
        $path = realpath($entry->getPathname());
        if ($resolvedWorkspace === false || $path === false || ! str_starts_with($path, $resolvedWorkspace.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing cleanup outside test workspace.');
        }
        is_dir($path) ? rmdir($path) : unlink($path);
    }
    rmdir($workspace);
}
