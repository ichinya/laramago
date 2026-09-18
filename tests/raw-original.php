<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-raw-original-'.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns';
mkdir($framework, 0777, true);
$trait = <<<'PHP'
    <?php
    namespace Illuminate\Database\Eloquent\Concerns;
    use Illuminate\Support\Arr;
    trait HasAttributes {
        /** @var array<string, mixed> */ protected $original = [];
        /** @param string|null $key
         * @param mixed $default
         * @return ($key is null ? array<string, mixed> : mixed)
         */
        public function getRawOriginal($key = null, $default = null) {
            return Arr::get($this->original, $key, $default);
        }
        /** @param array<array-key, mixed> $attributes */
        public function setRawAttributes(array $attributes, bool $sync = false): static { return $this; }
    }
    PHP;
file_put_contents($framework.'/HasAttributes.php', $trait);
mkdir($workspace.'/database/migrations', 0777, true);
file_put_contents($workspace.'/database/migrations/001_records.php', <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Database\Schema\Blueprint;
    Schema::create('records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->json('payload')->nullable();
        $table->timestamps();
    });
    PHP);
file_put_contents($workspace.'/models.php', <<<'PHP'
    <?php
    namespace Illuminate\Database\Eloquent;
    class Model { use \Illuminate\Database\Eloquent\Concerns\HasAttributes; }
    namespace Illuminate\Support;
    class Arr { public static function get(mixed $array, mixed $key, mixed $default): mixed {} }
    namespace App;
    class Record extends \Illuminate\Database\Eloquent\Model { protected $casts = ['payload' => 'array', 'created_at' => 'datetime']; }
    class Custom extends Record { /** @return int */ public function getRawOriginal($key = null, $default = null): int { return 1; } }
    class CustomDoc extends Record { /** @return int */ public function getRawOriginal($key = null, $default = null) { return 1; } }
    class CustomOriginal extends Record { /** @var mixed */ protected $original; }
    PHP);
$cases = [
    'function whole(Record $m): int { return count($m->getRawOriginal()); }',
    'function nullKey(Record $m): int { return count($m->getRawOriginal(null)); }',
    'function named(Record $m): int { return count($m->getRawOriginal(default: new \\stdClass, key: null)); }',
    'function defaultOnly(Record $m): int { return count($m->getRawOriginal(default: 123)); }',
    'function fresh(): int { return count((new Record)->getRawOriginal()); }',
    'function field(Record $m): string { return $m->getRawOriginal("name"); }',
    'function missing(Record $m): string { return $m->getRawOriginal("missing", "fallback"); }',
    'function rawMutation(Record $m): string { return $m->setRawAttributes(["name" => new \\stdClass], true)->getRawOriginal("name"); }',
    'function mutatedContainer(Record $m): int { return count($m->setRawAttributes([123 => new \\stdClass], true)->getRawOriginal()); }',
    'function rawElement(Record $m): string { return $m->getRawOriginal()["name"]; }',
    'function custom(Custom $m): int { return $m->getRawOriginal(); }',
    'function customDoc(CustomDoc $m): int { return $m->getRawOriginal(); }',
    'function customOriginal(CustomOriginal $m): int { return count($m->getRawOriginal()); }',
    'function dynamic(Record $m, ?string $key): int { return count($m->getRawOriginal($key)); }',
    'function wrongReturn(Record $m): string { return $m->getRawOriginal(); }',
    'function unpacked(Record $m, array $args): int { return count($m->getRawOriginal(...$args)); }',
    'function unknownNamed(Record $m): int { return count($m->getRawOriginal(other: null)); }',
    'function wrongArity(Record $m): int { return count($m->getRawOriginal(null, null, null)); }',
    'function jsonRaw(Record $m): array { return $m->getRawOriginal("payload"); }',
    'function dateRaw(Record $m): \\DateTimeInterface { return $m->getRawOriginal("created_at"); }',
    'function unsaved(): string { return (new Record)->getRawOriginal("name"); }',
    'function nullableKey(Record $m, ?string $key): string { return $m->getRawOriginal($key, "fallback"); }',
    'function customWrong(Custom $m): string { return $m->getRawOriginal(); }',
    'function customDocWrong(CustomDoc $m): string { return $m->getRawOriginal(); }',
];
file_put_contents($workspace.'/cases.php', "<?php\nnamespace App;\n".implode("\n", $cases)."\n");
foreach (['enabled', 'disabled', 'native-doc', 'changed-body'] as $mode) {
    file_put_contents($framework.'/HasAttributes.php', match ($mode) {
        'native-doc' => str_replace('($key is null ? array<string, mixed> : mixed)', 'mixed', $trait),
        'changed-body' => str_replace('return Arr::get($this->original, $key, $default);', 'return null;', $trait),
        default => $trait,
    });
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['vendor', 'models.php']],
        'extension-hosts' => $mode !== 'disabled'
            ? [
                'laramago' => [
                    'command' => [
                        PHP_BINARY,
                        $package.'/bin/laramago-worker.php',
                        $package.'/vendor/autoload.php',
                        $workspace,
                    ],
                    'workers' => 1,
                ],
            ] : new stdClass,
    ];
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open(
        [PHP_BINARY, $package.'/vendor/bin/mago', '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
            2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
        ],
        $pipes,
    );
    fclose($pipes[0]);
    $exit = proc_close($process);
    if (
        $exit > 1
        || preg_match('/provider failed|rejected request|parse error/i', file_get_contents($workspace.'/'.$mode.'.log'))
    ) {
        throw new RuntimeException('Analyzer failed: '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $actual = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $a) {
            if ($a['kind'] === 'Primary') {
                $actual[$a['span']['start']['line'] - 2][] = $issue['code'];
                break;
            }
        }
    }
    foreach ($actual as &$codes) {
        sort($codes);
    }
    unset($codes);
    ksort($actual);
    $expected = [
        5 => ['mixed-return-statement'],
        6 => ['mixed-return-statement'],
        7 => ['mixed-return-statement'],
        9 => ['mixed-return-statement'],
        13 => ['mixed-argument'],
        14 => ['invalid-return-statement'],
        16 => ['invalid-named-argument'],
        17 => ['too-many-arguments'],
        18 => ['mixed-return-statement'],
        19 => ['mixed-return-statement'],
        20 => ['mixed-return-statement'],
        21 => ['mixed-return-statement'],
        22 => ['invalid-return-statement'],
        23 => ['invalid-return-statement'],
    ];
    if ($mode === 'native-doc') {
        foreach ([0, 1, 2, 3, 4, 8, 12, 15] as $case) {
            $expected[$case] = ['mixed-argument'];
        }
        $expected[9] = ['mixed-array-access', 'mixed-return-statement'];
        unset($expected[10]);
        $expected[14] = ['mixed-return-statement'];
        $expected[16] = ['invalid-named-argument', 'mixed-argument'];
        $expected[17] = ['mixed-argument', 'too-many-arguments'];
    }
    ksort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException($mode.': '.json_encode($actual).' workspace '.$workspace);
    }
    echo 'PASS: raw original '.$mode.' ('.count($cases).' cases)'."\n";
}
echo 'workspace '.$workspace."\n";
