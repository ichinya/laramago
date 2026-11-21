<?php

declare(strict_types=1);

// Shared only by the migration contract suites. Each opaque scenario gets its
// own process/workspace; a global effect must not poison unrelated controls.
function schema_effects_partition(array $isolated): ?string
{
    $selected = $_SERVER['argv'][1] ?? null;
    if ($selected !== null) {
        if (! in_array($selected, $isolated, true)) { throw new RuntimeException('Unknown isolated scenario.'); }

        return $selected;
    }
    foreach ($isolated as $name) {
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', $_SERVER['SCRIPT_FILENAME'], $name],
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (! is_resource($process)) { throw new RuntimeException('Cannot start isolated migration scenario.'); }
        fclose($pipes[0]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Failed isolated scenario: '.$name); }
    }

    return null;
}

function schema_effects_selected(string $name, array $isolated, ?string $selected): bool
{
    return $selected === null ? ! in_array($name, $isolated, true) : $name === $selected;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) { return; }

require __DIR__.'/../vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\{PhpSource, SchemaIndex};

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$base = str_replace('\\', '/', sys_get_temp_dir()).'/laramago helper effects '.bin2hex(random_bytes(8));
$create = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    final class SchemaTrap {
        public static function SchemaTrap(): void { Schema::drop('tokens'); }
        public static function run(mixed $value): string { Schema::drop('tokens'); return 'changed'; }
    }
    file_put_contents(__DIR__.'/executed', 'Migration must not execute.');
    throw new RuntimeException('Source only.');
    return new class extends Migration {
        public function up(): void {
            Schema::create('people', function (Blueprint $table) { $table->string('email'); });
            Schema::create('tokens', function (Blueprint $table) { $table->string('value'); });
        }
    };
    PHP;
$change = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    return new class extends Migration {
        public function up(): void { BODY }
        public function __call($method, $args) { Schema::drop('tokens'); }
        private function emptyHelper(): void {}
        private function addColumn(): void {
            Schema::table('people', function (Blueprint $table) { $table->string('extra'); });
        }
        private $replacement;
        private $subquery;
        public function __construct() {
            $this->subquery = static function ($query): void { Schema::drop('tokens'); };
            $this->replacement = new class {
                public function __call($method, $arguments) { Schema::drop('tokens'); }
                public function __invoke() { Schema::drop('tokens'); }
            };
        }
        private function replace(&$receiver): void { $receiver = $this->replacement; }
        private function retarget(&$name): void { $name = 'tokens'; }
        private function ignoreReference(&$unused): void {}
    };
    PHP;
$schema = 'Schema::table("people", function (Blueprint $table) { $table->string("extra"); });';
$scenarios = [
    'empty helper' => ['$this->emptyHelper();', true, true],
    'resolved helper' => ['$this->addColumn();', true, true],
    'local effect' => ['if (true) { '.$schema.' }', false, true],
    'opaque alone' => ['$this->opaque();', false, false],
    'opaque before schema' => ['if (true) { $this->opaque(); '.$schema.' }', false, false],
    'opaque after schema' => ['if (true) { '.$schema.' $this->opaque(); }', false, false],
    'opaque macro' => ['Schema::table("people", function (Blueprint $table) { $table->unknownColumnType("extra"); });', false, false],
    'opaque callback' => ['Schema::table("people", function (Blueprint $table) { $this->opaque(); });', false, false],
    'warning limit' => ['if (true) { '.$schema.' }', false, true],
    'blueprint reference escape' => ['Schema::table("people", function (Blueprint $table) { $this->replace($table); $table->string("extra"); });', false, false],
    'collection reference escape' => ['\\Illuminate\\Support\\Facades\\DB::table("people")->chunkById(10, function (\\Illuminate\\Support\\Collection $rows) { $this->replace($rows); $rows->count(); });', false, false],
    'table loop reference escape' => ['foreach (["people"] as $name) { $this->retarget($name); Schema::table($name, function (Blueprint $table) { $table->dropColumn("value"); }); }', false, false],
    'nested closure binding escape' => ['$action = $this->replacement; if (true) { $register = function () { $action = function () {}; }; $action(); }', false, false],
    'unknown transaction callback' => ['$action = $this->replacement; \\Illuminate\\Support\\Facades\\DB::transaction($action);', false, false],
    'unused reference helper' => ['$name = "people"; $this->ignoreReference($name);', true, true],
    'safe closure binding' => ['$action = function () {}; $action();', true, true],
    'safe transaction binding' => ['$action = function () {}; \\Illuminate\\Support\\Facades\\DB::transaction($action);', true, true],
    'builtin output overwrites callable' => ['$action = function () {}; preg_match("/^(SchemaTrap)$/", "SchemaTrap", $action); $action();', false, false],
    'builtin named output overwrites callable' => ['$action = function () {}; \\PREG_MATCH(subject: "SchemaTrap", matches: $action, pattern: "/^(SchemaTrap)$/"); $action();', false, false],
    'builtin output overwrites transaction' => ['$action = function () {}; preg_match("/^(SchemaTrap)$/", "SchemaTrap", $action); \\Illuminate\\Support\\Facades\\DB::transaction($action);', false, false],
    'builtin unrelated output preserves callable' => ['$action = function () {}; preg_match("/x/", "x", $matches); $action();', true, true],
    'builtin without output preserves callable' => ['$action = function () {}; preg_match("/x/", "x"); \\Illuminate\\Support\\Facades\\DB::transaction($action);', true, true],
    'unpacked map callback' => ['array_map(...["SchemaTrap::run", [1]]);', false, false],
    'unpacked named filter callback' => ['array_filter(...["array" => [1], "callback" => "SchemaTrap::run"]);', false, false],
    'unpacked replacement callback' => ['preg_replace_callback(...["/x/", "SchemaTrap::run", "x"]);', false, false],
    'opaque builder callback' => ['\\Illuminate\\Support\\Facades\\DB::table("people")->tap("SchemaTrap::run");', false, false],
    'opaque chunk callback' => ['\\Illuminate\\Support\\Facades\\DB::table("people")->chunkById(10, "SchemaTrap::run");', false, false],
    'opaque collection callback' => ['\\Illuminate\\Support\\Facades\\DB::table("people")->chunkById(10, function (\\Illuminate\\Support\\Collection $rows) { $rows->each("SchemaTrap::run"); });', false, false],
    'safe inline array callback' => ['array_map(static fn ($value) => $value, [1]); array_filter([1]);', true, true],
    'safe inline data callback' => ['\\Illuminate\\Support\\Facades\\DB::table("people")->chunkById(10, function (\\Illuminate\\Support\\Collection $rows) { $rows->each(static fn ($row) => true); $rows->count(); });', true, true],
    'opaque ordering subquery' => ['\\Illuminate\\Support\\Facades\\DB::table("people")->orderBy($this->subquery);', false, false],
    'opaque named variable ordering subquery' => ['$column = $this->subquery; \\Illuminate\\Support\\Facades\\DB::table("people")->reorder(column: $column);', false, false],
    'safe literal and inline ordering' => ['$direction = "desc"; \\Illuminate\\Support\\Facades\\DB::table("people")->orderBy(column: "email", direction: $direction)->orderByDesc("email")->latest()->oldest("email")->reorder("email"); \\Illuminate\\Support\\Facades\\DB::table("people")->orderBy(static function ($query) {})->orderByDesc(static function ($query) {})->latest(static fn ($query) => null)->oldest(static fn ($query) => null)->reorder(static function ($query) {});', true, true],
];
foreach (['people', 'tokens'] as $subject) {
foreach ($scenarios as $name => [$body, $peopleKnown, $tokensKnown]) {
    $workspace = $base.'/'.$subject.'/'.str_replace(' ', '-', $name);
    mkdir($workspace.'/database/migrations', recursive: true);
    mkdir($workspace.'/bootstrap', recursive: true);
    file_put_contents($workspace.'/bootstrap/app.php', '<?php file_put_contents(__DIR__."/executed", "booted"); throw new RuntimeException("Must not boot.");');
    file_put_contents($workspace.'/composer.json', json_encode(['autoload' => ['files' => ['bootstrap/app.php']]], JSON_THROW_ON_ERROR));
    copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
    file_put_contents($workspace.'/database/migrations/001.php', $create);
    file_put_contents($workspace.'/database/migrations/002.php', str_replace('BODY', $body, $change));
    if ($name === 'warning limit') {
        for ($n = 3; $n <= 6; ++$n) {
            file_put_contents($workspace.'/database/migrations/00'.$n.'.php', str_replace('BODY', str_replace('"people"', '"uncertain_'.$n.'"', $body), $change));
        }
    }
    $models = <<<'PHP'
        <?php
        /** @property bool $documented */
        class SubjectModel extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'SUBJECT';
            public bool $native = true;
        }
        PHP;
    file_put_contents($workspace.'/models.php', str_replace('SUBJECT', $subject, $models));
    $known = $subject === 'people' ? $peopleKnown : $tokensKnown;
    $property = $subject === 'people' ? 'email' : 'value';
    $cases = [
        ['return (new SubjectModel)->'.$property.';', 'string', $known ? [] : ['non-documented-property', 'mixed-return-statement']],
        ['(new SubjectModel)->'.$property.' = new stdClass;', 'void', $known ? ['invalid-property-assignment-value'] : ['non-documented-property']],
        ['return (new SubjectModel)->native;', 'bool', []],
        ['return (new SubjectModel)->documented;', 'bool', []],
        ['return "wrong";', 'int', ['invalid-return-statement']],
    ];
    if ($name === 'resolved helper' && $subject === 'people') { $cases[] = ['return (new SubjectModel)->extra;', 'string', []]; }
    $source = "<?php\n";
    $expected = [];
    foreach ($cases as $n => [$code, $type, $codes]) {
        $source .= '/** @return '.$type.' */'."\n".'function example'.$n.'() { '.$code.' }'."\n";
        sort($codes);
        $expected[substr_count($source, "\n")] = $codes;
    }
    file_put_contents($workspace.'/cases.php', $source);
    $index = new SchemaIndex(new PhpSource($workspace));
    $index->load();
    $reasons = $index->uncertainties();
    foreach ($reasons as $n => $reason) {
        if ($reason['file'] !== realpath($workspace.'/database/migrations/00'.($n + 2).'.php') || $reason['line'] !== 6) { throw new RuntimeException('Incorrect migration source anchor.'); }
    }
    $reasonCount = $name === 'warning limit' ? 5 : ($peopleKnown ? 0 : 1);
    if (count($reasons) !== $reasonCount
        || ($index->column('people', 'email') !== null) !== $peopleKnown
        || ($index->column('tokens', 'value') !== null) !== $tokensKnown) {
        throw new RuntimeException('Unexpected source effects: '.$name.' '.json_encode($reasons));
    }
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
        'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]]],
    ];
    $previous = null;
    foreach ([1, 3] as $workers) {
        $config['extension-hosts']['laramago']['workers'] = $workers;
        file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
        // Explicitly disable runtime evaluation and metadata snapshots, including
        // when the developer shell has enabled them for another investigation.
        $env = getenv();
        $env['LARAMAGO_EVALUATE_RUNTIME'] = '0';
        $env['LARAMAGO_RUNTIME_METADATA'] = '';
        $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
            [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']], $pipes, env_vars: $env);
        if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
        fclose($pipes[0]);
        $deadline = microtime(true) + 60;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) { break; }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                throw new RuntimeException('Mago timeout: '.$workspace);
            }
            usleep(20000);
        } while (true);
        $closed = proc_close($process);
        $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        if ($exit !== 1 || preg_match('/provider failed|rejected request|timed? ?out|timeout|fatal error/i', file_get_contents($workspace.'/stderr.log'))) {
            throw new RuntimeException('Native analysis failed: '.$workspace);
        }
        $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! isset($report['issues']) || ! is_array($report['issues']) || $report['issues'] === []) { throw new RuntimeException('Missing diagnostics.'); }
        $actual = [];
        $warnings = [];
        foreach ($report['issues'] as $issue) {
            $primary = array_values(array_filter($issue['annotations'], static fn (array $a): bool => $a['kind'] === 'Primary'))[0];
            if ($issue['code'] === 'ichinya/laramago/schema-uncertain') {
                $warnings[] = $issue;
                if ($issue['level'] !== 'Warning' || $primary['span']['start']['line'] !== 2 || ! str_ends_with(str_replace('\\', '/', $primary['span']['file_id']['name']), 'models.php')) {
                    throw new RuntimeException('Incorrect schema warning anchor/level: '.json_encode($issue));
                }
            } else {
                $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
            }
        }
        foreach ($expected as $line => $codes) {
            $got = $actual[$line] ?? [];
            sort($got);
            if ($codes !== $got) { throw new RuntimeException($name.' line '.$line.': expected '.json_encode($codes).', got '.json_encode($got).'; '.$workspace); }
            unset($actual[$line]);
        }
        if ($actual !== [] || count($warnings) !== min(3, $reasonCount) + ($reasonCount > 3 ? 1 : 0)) { throw new RuntimeException('Unexpected diagnostics: '.$workspace); }
        $details = [];
        $summaries = [];
        foreach ($warnings as $warning) {
            if (str_contains($warning['message'], 'further schema uncertainties')) { $summaries[] = $warning['message']; }
            else { $details[] = $warning; }
        }
        foreach (array_slice($reasons, 0, 3) as $reason) {
            $note = $reason['file'].':'.$reason['line'];
            $matches = array_values(array_filter($details, static fn (array $warning): bool => in_array($note, $warning['notes'], true)));
            if (count($matches) !== 1 || ($reason['scope'] === 'all-tables'
                ? ! str_contains($matches[0]['message'], 'contracts are disabled')
                : ! str_contains($matches[0]['message'], 'uncertain: '.implode(', ', $reason['tables']).'.'))) {
                throw new RuntimeException('Incorrect schema warning source/scope: '.$workspace);
            }
        }
        if ($reasonCount > 3 && $summaries !== [($reasonCount - 3).' further schema uncertainties were suppressed.']) { throw new RuntimeException('Incorrect suppressed count.'); }
        // Canonicalize only the temporary root and diagnostic ordering. Retain
        // every field and every duplicate to catch worker-dependent differences.
        $multiset = array_map(static fn (array $issue): string => str_replace([$workspace, str_replace('/', '\\', $workspace)], '<workspace>', json_encode($issue, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $report['issues']);
        sort($multiset);
        if ($previous !== null && $previous !== $multiset) { throw new RuntimeException('Worker diagnostics differ: '.$workspace); }
        $previous = $multiset;
        foreach (['database/migrations/executed', 'bootstrap/executed', '.env'] as $file) {
            if (file_exists($workspace.'/'.$file)) { throw new RuntimeException('Application executed: '.$file); }
        }
        echo 'PASS: '.$subject.' '.$name.' ('.$workers.' workers)'."\n";
    }
}

}

$resolvedBase = realpath($base);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($files as $file) {
    $resolved = $file->getRealPath();
    if ($resolvedBase === false || $resolved === false || strncasecmp($resolved, $resolvedBase.DIRECTORY_SEPARATOR, strlen($resolvedBase) + 1) !== 0) { throw new RuntimeException('Unsafe cleanup path.'); }
    if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
}
rmdir($base);
