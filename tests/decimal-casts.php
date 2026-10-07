<?php

declare(strict_types=1);

// Laravel's decimal cast returns a formatted string, so Laramago must type decimal
// cast reads as numeric-string: string comparisons and (float) casts stay sound,
// other casts keep their mappings, and non-literal cast declarations defer natively.

$strict = in_array('--strict', $argv, true);
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago decimal casts '.bin2hex(random_bytes(8));
$framework = $workspace.'/laravel/framework/src/Illuminate';
foreach (['Database/Eloquent', 'Database/Migrations', 'Support', 'Support/Facades'] as $folder) {
    if (! is_dir($framework.'/'.$folder)) {
        mkdir($framework.'/'.$folder, 0777, true);
    }
}
mkdir($workspace.'/testo', 0777, true);
mkdir($workspace.'/database/migrations', 0777, true);
mkdir($workspace.'/bootstrap', 0777, true);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $framework.'/model-framework.php');
file_put_contents($workspace.'/testo/assert-framework.php', <<<'PHP'
    <?php

    namespace Testo;

    final class Assert
    {
        /**
         * @template ExpectedType
         *
         * @param mixed $actual
         * @param ExpectedType $expected
         *
         * @phpstan-assert =ExpectedType $actual
         */
        public static function same(mixed $actual, mixed $expected, string $message = ''): void {}
    }
    PHP);
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Bootstrap executed.");');
file_put_contents($workspace.'/database/migrations/001_create.php', <<<'PHP'
    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('ledgers', function (Blueprint $table) {
                $table->id();
                $table->decimal('hours', 8, 2);
                $table->decimal('documented', 8, 2);
                $table->integer('count');
                $table->boolean('flag');
                $table->json('payload')->nullable();
                $table->float('ratio');
                $table->string('label');
            });
            Schema::create('metrics', function (Blueprint $table) {
                $table->id();
                $table->decimal('value', 10, 4);
                $table->string('name');
            });
            Schema::create('dynamics', function (Blueprint $table) {
                $table->id();
                $table->decimal('whatever', 8, 2);
            });
        }
    };
    PHP);
file_put_contents($workspace.'/models.php', <<<'PHP'
    <?php

    namespace Example;

    use Illuminate\Database\Eloquent\Model;

    class Ledger extends Model
    {
        protected $casts = [
            'hours' => 'decimal:2',
            'documented' => 'decimal:2',
            'count' => 'integer',
            'flag' => 'boolean',
            'payload' => 'array',
            'ratio' => 'float',
            'label' => 'string',
        ];
    }

    /** @property-read float $documented */
    class DocumentedLedger extends Model
    {
        protected $casts = ['documented' => 'decimal:2', 'name' => 'string'];
    }

    class Metric extends Model
    {
        protected function casts(): array
        {
            return ['value' => 'decimal:4'];
        }
    }

    class Dynamic extends Model
    {
        protected function casts(): array
        {
            return $this->loadCasts();
        }

        private function loadCasts(): array
        {
            return [];
        }
    }
    PHP);
$cases = [
    'assert-same-decimal' => 'function assertSameDecimal(Example\Ledger $m): void { \Testo\Assert::same($m->hours, "8.00"); }',
    'strict-compare-decimal' => 'function strictCompareDecimal(Example\Ledger $m): bool { return $m->hours === "8.00"; }',
    'float-cast-decimal' => 'function floatCastDecimal(Example\Ledger $m): float { return (float) $m->hours; }',
    'decimal-weak-float-conversion' => 'function decimalWeakFloatConversion(Example\Ledger $m): float { return $m->hours; }',
    'decimal-is-string-subtype' => 'function decimalIsStringSubtype(Example\Ledger $m): string { return $m->hours; }',
    'method-casts-decimal' => 'function methodCastsDecimal(Example\Metric $m): string { return $m->value; }',
    'integer-cast-unchanged' => 'function integerCastUnchanged(Example\Ledger $m): int { return $m->count; }',
    'integer-float-cast-unchanged' => 'function integerFloatCastUnchanged(Example\Ledger $m): float { return (float) $m->count; }',
    'boolean-cast-unchanged' => 'function booleanCastUnchanged(Example\Ledger $m): bool { return $m->flag; }',
    'array-cast-unchanged' => 'function arrayCastUnchanged(Example\Ledger $m): ?array { return $m->payload; }',
    'float-cast-unchanged' => 'function floatCastUnchanged(Example\Ledger $m): float { return $m->ratio; }',
    'string-cast-unchanged' => 'function stringCastUnchanged(Example\Ledger $m): string { return $m->label; }',
    'float-cast-on-string-cast' => 'function floatCastOnStringCast(Example\Ledger $m): float { return (float) $m->label; }',
    'documented-float-wins-comparison' => 'function documentedFloatWinsComparison(Example\DocumentedLedger $m): void { \Testo\Assert::same($m->documented, "8.00"); }',
    'documented-float-wins-return' => 'function documentedFloatWinsReturn(Example\DocumentedLedger $m): float { return $m->documented; }',
    'dynamic-comparison-defers' => 'function dynamicComparisonDefers(Example\Dynamic $m): void { \Testo\Assert::same($m->whatever, "8.00"); }',
    'dynamic-float-cast-defers' => 'function dynamicFloatCastDefers(Example\Dynamic $m): float { return (float) $m->whatever; }',
];
$source = "<?php\n".($strict ? "declare(strict_types=1);\n" : "\n").implode("\n", array_values($cases))."\n";
file_put_contents($workspace.'/cases.php', $source);
$lines = [];
$line = 3;
foreach ($cases as $name => $_) {
    $lines[$name] = $line++;
}

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (bool $disabled) use ($workspace, $package, $command): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.5',
        'source' => [
            'paths' => ['cases.php'],
            'includes' => [$workspace.'/laravel', $workspace.'/testo', 'models.php'],
        ],
        'extension-hosts' => $disabled ? new stdClass : [
            'laramago' => [
                'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => 1,
            ],
        ],
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
    return json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'] ?? [];
};
$summarize = static function (array $issues): array {
    $result = [];
    foreach ($issues as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
        if ($primary['span']['file_id']['name'] === 'cases.php') {
            $result[$primary['span']['start']['line'] + 1][] = $issue['code'];
        }
    }
    foreach ($result as &$codes) {
        sort($codes);
    }
    return $result;
};
$native = $summarize($run(true));
$adapted = $summarize($run(false));

// Decimal casts read as numeric-string. Explicit directional read declarations
// retain priority; weak native float returns permit PHP's numeric conversion.
$documented = ['documented-float-wins-comparison'];
$deferred = [
    'documented-float-wins-return',
    'dynamic-comparison-defers',
    'dynamic-float-cast-defers',
];
$expected = [
    'assert-same-decimal' => [],
    'strict-compare-decimal' => [],
    'float-cast-decimal' => [],
    'decimal-weak-float-conversion' => $strict ? ['invalid-return-statement'] : [],
    'decimal-is-string-subtype' => [],
    'method-casts-decimal' => [],
    'integer-cast-unchanged' => [],
    'integer-float-cast-unchanged' => [],
    'boolean-cast-unchanged' => [],
    'array-cast-unchanged' => [],
    'float-cast-unchanged' => [],
    'string-cast-unchanged' => [],
    'float-cast-on-string-cast' => [],
];
foreach ($lines as $name => $line) {
    $actual = $adapted[$line] ?? [];
    $baseline = $native[$line] ?? [];
    if (in_array($name, $documented, true)) {
        if ($actual !== $baseline || ! in_array('impossible-type-comparison', $actual, true)) {
            throw new RuntimeException(
                $name.' must keep the documented float diagnostics of native Mago; native '
                .json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace,
            );
        }
    } elseif (in_array($name, $deferred, true)) {
        if ($actual !== $baseline) {
            throw new RuntimeException(
                $name.' must retain native diagnostics for non-literal casts; native '
                .json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace,
            );
        }
    } elseif ($actual !== $expected[$name]) {
        throw new RuntimeException(
            $name.': expected '.json_encode($expected[$name]).', got '.json_encode($actual)
            .'; native '.json_encode($baseline).'; inspect '.$workspace,
        );
    }
    echo 'PASS: '.$name."\n";
}

$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($resolved);
