<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
use Ichinya\Laramago\Analyzer\StaticAnalysis\{PhpSource, SchemaIndex};

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago schema reasons '.bin2hex(random_bytes(8));
mkdir($workspace.'/database/migrations', recursive: true);
mkdir($workspace.'/app/Services', recursive: true);
file_put_contents($workspace.'/composer.json', '{}');
file_put_contents($workspace.'/app/Services/Backfill.php', <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Str;
    final class Backfill {
        private const array TABLES = ["people", "tokens"];
        public function run(): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->whereNull("public_id")->chunkById(100, function (Collection $rows): void { $rows->count(); });
            }
            $this->audit();
        }
        private function audit(): void { Log::info("backfill", ["uuid" => Str::isUuid("x")]); }
        public function alter(): void { Schema::table("tokens", static function ($table): void { $table->string("extra"); }); }
        public function danger(): void { DB::statement("ALTER TABLE people DROP email"); }
    }
    PHP);
file_put_contents($workspace.'/app/Services/Mutable.php', <<<'PHP'
    <?php
    namespace App\Services;
    class Mutable {
        public function run(): void {}
    }
    PHP);
file_put_contents($workspace.'/app/Services/Constructed.php', <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades\Schema;
    final class Constructed {
        public function __construct() { Schema::drop("people"); }
        public function run(): void {}
    }
    PHP);
file_put_contents($workspace.'/app/Services/Warm.php', <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades\DB;
    final class Warm {
        public function __construct() { DB::table("people")->where("id", 1)->update(["email" => "x"]); }
        public function run(): void {}
    }
    PHP);
file_put_contents($workspace.'/app/Services/Inherited.php', <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades\Schema;
    class ParentEffect {
        public function __construct() { Schema::drop("people"); }
    }
    final class Inherited extends ParentEffect {}
    PHP);
file_put_contents($workspace.'/app/Services/WithTrait.php', <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades\Schema;
    trait ConstructorEffect {
        public function __construct() { Schema::drop("people"); }
    }
    final class WithTrait { use ConstructorEffect; }
    PHP);
file_put_contents($workspace.'/app/Services/Injected.php', <<<'PHP'
    <?php
    namespace App\Services;
    final class Injected {
        public function __construct(Constructed $dependency) {}
    }
    PHP);
file_put_contents($workspace.'/app/Services/ScalarConstructor.php', <<<'PHP'
    <?php
    namespace App\Services;
    final class ScalarConstructor {
        public function __construct(int $value = 1) {}
    }
    PHP);
file_put_contents($workspace.'/app/Services/DefaultConstruction.php', <<<'PHP'
    <?php
    namespace App\Services;
    final class DefaultConstruction {
        public function __construct($value = new Constructed) {}
    }
    PHP);
$prefix = <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\DB;
    final class SchemaTrap {
        public static function SchemaTrap(): void { Schema::drop('tokens'); }
        public static function run(mixed $value): string { Schema::drop('tokens'); return 'changed'; }
    }
    return new class extends Migration {
        public function up(): void {
            Schema::create('people', static function (Blueprint $table): void { $table->id(); $table->string('email'); });
            Schema::create('tokens', static function (Blueprint $table): void { $table->id(); $table->timestamp('expires_at'); });
    PHP;
$methods = 'private function modifySchema(): void { throw new RuntimeException(\'Never execute migrations.\'); } private function tableName(): string { return \'tokens\'; }';
$cases = [
    'complete declarative schema' => ['', true, true, [], ''],
    'unknown helper stays global' => ['$this->modifySchema();', false, false, ['all-tables'], ''],
    'empty helper keeps schema' => ['$this->emptyHelper();', true, true, [], 'private function emptyHelper(): void {}'],
    'unused helper literal argument is neutral' => ['$this->emptyHelper("people");', true, true, [], 'private function emptyHelper(): void {}'],
    'unused helper raw SQL argument stays global' => ['$this->emptyHelper(DB::statement("ALTER TABLE people DROP email"));', false, false, ['all-tables'], 'private function emptyHelper(): void {}'],
    'unused helper argument include stays global' => ['$this->emptyHelper(include "unknown-schema.php");', false, false, ['all-tables'], 'private function emptyHelper(): void {}'],
    'chained helpers keep schema' => ['$this->stepA();', true, true, [], 'private function stepA(): void { $this->stepB(); } private function stepB(): void {}'],
    'self helper keeps schema' => ['self::bootstrap();', true, true, [], 'private static function bootstrap(): void {}'],
    'helper DML keeps schema' => ['$this->backfill();', true, true, [], 'private function backfill(): void { DB::table("people")->where("id", 1)->update(["email" => "x"]); }'],
    'query builder connection escape stays global' => ['DB::table("people")->getConnection()->statement("ALTER TABLE tokens ADD hidden TEXT");', false, false, ['all-tables'], ''],
    'query builder schema builder escape stays global' => ['DB::table("people")->getConnection()->getSchemaBuilder()->dropIfExists("tokens");', false, false, ['all-tables'], ''],
    'query builder mixed-case escape stays global' => ['DB::table("people")->GeTcOnNeCtIoN()->unprepared("ALTER TABLE tokens ADD hidden TEXT");', false, false, ['all-tables'], ''],
    'dynamic query builder dispatch stays global' => ['$method = "getConnection"; DB::table("people")->{$method}()->statement("ALTER TABLE tokens ADD hidden TEXT");', false, false, ['all-tables'], ''],
    'connection escape beside schema stays global' => ['if (PHP_VERSION_ID > 80000) { Schema::table("tokens", static function (Blueprint $table): void { $table->string("extra"); }); DB::table("people")->getConnection()->statement("ALTER TABLE people DROP email"); }', false, false, ['all-tables'], ''],
    'dynamic builder beside schema stays global' => ['if (PHP_VERSION_ID > 80000) { Schema::table("tokens", static function (Blueprint $table): void { $table->string("extra"); }); $method = "getConnection"; DB::table("people")->{$method}()->statement("ALTER TABLE people DROP email"); }', false, false, ['all-tables'], ''],
    'helper builtins keep schema' => ['$this->derive();', true, true, [], 'private function derive(): void { $m = []; if (is_string("x") && preg_match("/x/", "x", $m)) { substr("x", 0, 1); } }'],
    'helper schema callback stays local' => ['$this->addColumn();', true, false, ['tables'], 'private function addColumn(): void { Schema::table("tokens", static function (Blueprint $table): void { $table->string($unknown); }); }'],
    'helper raw SQL stays global' => ['$this->migrate();', false, false, ['all-tables'], 'private function migrate(): void { DB::statement("ALTER TABLE people ADD x TEXT"); }'],
    'recursive helpers scanned once' => ['$this->a();', true, true, [], 'private function a(): void { $this->b(); } private function b(): void { $this->a(); }'],
    'dynamic helper name stays global' => ['$m = "emptyHelper"; $this->{$m}();', false, false, ['all-tables'], 'private function emptyHelper(): void {}'],
    'missing helper stays global' => ['$this->missing();', false, false, ['all-tables'], ''],
    'raw SQL stays global' => ['DB::statement("ALTER TABLE tokens ADD hidden TEXT");', false, false, ['all-tables'], ''],
    'unknown column name stays local' => ['Schema::table("tokens", static function (Blueprint $table): void { $table->string($unknown); });', true, false, ['tables'], ''],
    'dynamic schema table stays global' => ['Schema::table($this->tableName(), static function (Blueprint $table): void { $table->integer("x"); });', false, false, ['all-tables'], ''],
    'missing-column guard is evaluated' => ['if (! Schema::hasColumn("tokens", "extra")) { Schema::table("tokens", static function (Blueprint $table): void { $table->string("extra"); }); }', true, true, [], ''],
    'existing-column guard skips body' => ['if (! Schema::hasColumn("tokens", "expires_at")) { DB::statement("ALTER TABLE people DROP email"); }', true, true, [], ''],
    'unknown-table guard stays uncertain' => ['if (! Schema::hasColumn("unknown", "extra")) { Schema::table("tokens", static function (Blueprint $table): void { $table->string("extra"); }); }', true, false, ['tables'], ''],
    'unknown guard with else stays uncertain' => ['if (! Schema::hasColumn("tokens", "extra")) { Schema::table("tokens", static function (Blueprint $table): void { $table->string("extra"); }); } else { DB::statement("ALTER TABLE people DROP email"); }', false, false, ['all-tables'], ''],
    'guard depth bound' => [str_repeat('if (! Schema::hasColumn("tokens", "extra")) { ', 65).'Schema::table("tokens", static function (Blueprint $table): void { $table->string("extra"); });'.str_repeat('}', 65), true, false, ['tables'], ''],
    'column metadata read is neutral' => ['Schema::getColumns("people");', true, true, [], ''],
    'dynamic metadata table is neutral' => ['$name = $this->tableName(); Schema::getIndexes($name);', true, true, [], ''],
    'metadata argument helper is inspected' => ['Schema::getColumns($this->tableName());', true, true, [], ''],
    'raw SQL inside metadata argument stays global' => ['Schema::getColumns(DB::statement("ALTER TABLE people DROP email"));', false, false, ['all-tables'], ''],
    'include inside metadata argument stays global' => ['Schema::getColumns(include "unknown-schema.php");', false, false, ['all-tables'], ''],
    'foreign metadata facade stays global' => ['OtherSchema::getColumns("people");', false, false, ['all-tables'], ''],
    'metadata collection lookup is neutral' => ['collect(Schema::getColumns("tokens"))->firstWhere("name", "expires_at");', true, true, [], ''],
    'unknown collection lookup stays global' => ['collect($this->unknownValues())->firstWhere("name", "expires_at");', false, false, ['all-tables'], ''],
    'metadata collection callback SQL stays global' => ['collect(Schema::getColumns("tokens"))->each(function (): void { DB::statement("ALTER TABLE people DROP email"); });', false, false, ['all-tables'], ''],
    'connection metadata guard is neutral' => ['$this->inspect();', true, true, [], 'private function inspect(): void { if (! in_array(DB::connection()->getDriverName(), ["sqlite", "mysql"], true)) { throw new RuntimeException("Unsupported driver"); } if (DB::connection()->transactionLevel() !== 0) { throw new LogicException("Active transaction"); } }'],
    'connection SQL still stays global' => ['DB::connection()->statement("ALTER TABLE people DROP email");', false, false, ['all-tables'], ''],
    'custom exception guard stays global' => ['if (true) { throw new CustomMigrationException("unsafe"); }', false, false, ['all-tables'], ''],
    'exception argument effects stay global' => ['if (true) { throw new RuntimeException(changeSchema()); }', false, false, ['all-tables'], ''],
    'app service dml keeps schema' => ['app(\App\Services\Backfill::class)->run();', true, true, [], ''],
    'app service static call keeps schema' => ['\App\Services\Backfill::alter();', true, false, ['tables'], ''],
    'app service schema call scopes table' => ['app(\App\Services\Backfill::class)->alter();', true, false, ['tables'], ''],
    'app service raw sql stays global' => ['app(\App\Services\Backfill::class)->danger();', false, false, ['all-tables'], ''],
    'app service missing method stays global' => ['app(\App\Services\Backfill::class)->missing();', false, false, ['all-tables'], ''],
    'app service non-final dispatch stays global' => ['app(\App\Services\Mutable::class)->run();', false, false, ['all-tables'], ''],
    'container without arguments is neutral' => ['app();', true, true, [], ''],
    'container constructor schema call scopes table' => ['app(\App\Services\Constructed::class);', false, true, ['tables'], ''],
    'container constructor beside method scopes table' => ['app(\App\Services\Constructed::class)->run();', false, true, ['tables'], ''],
    'container constructor dml keeps schema' => ['app(\App\Services\Warm::class);', true, true, [], ''],
    'container inherited constructor stays global' => ['app(\App\Services\Inherited::class);', false, false, ['all-tables'], ''],
    'container trait constructor stays global' => ['app(\App\Services\WithTrait::class);', false, false, ['all-tables'], ''],
    'container injected constructor stays global' => ['app(\App\Services\Injected::class);', false, false, ['all-tables'], ''],
    'container default construction stays global' => ['app(\App\Services\DefaultConstruction::class);', false, false, ['all-tables'], ''],
    'container scalar constructor keeps schema' => ['app(\App\Services\ScalarConstructor::class);', true, true, [], ''],
    'resolve constructor schema call scopes table' => ['resolve(\App\Services\Constructed::class);', false, true, ['tables'], ''],
    'container non-final construction stays global' => ['app(\App\Services\Mutable::class);', false, false, ['all-tables'], ''],
    'container string alias stays global' => ['app("cache.store");', false, false, ['all-tables'], ''],
    'container variable abstract stays global' => ['$abstract = \App\Services\Backfill::class; app($abstract);', false, false, ['all-tables'], ''],
    'collection param calls keep schema' => ['$this->collectRows();', true, true, [], 'private function collectRows(): void { DB::table("people")->chunkById(10, function (\Illuminate\Support\Collection $rows): void { $rows->count(); }); }'],
    'collection nullsafe calls keep schema' => ['$this->collectRows();', true, true, [], 'private function collectRows(): void { DB::table("people")->chunkById(10, function (\Illuminate\Support\Collection $rows): void { $rows?->count(); }); }'],
    'higher-order builtin keeps schema' => ['array_filter([1], static fn ($x): bool => true);', true, true, [], ''],
    'string callback stays global' => ['array_filter([1], "strlen");', false, false, ['all-tables'], ''],
];
$schema = 'Schema::table("people", static function (Blueprint $table): void { $table->string("extra"); });';
$magic = 'public function __call($method, $arguments) { Schema::drop("tokens"); }';
foreach ([
    'opaque alone' => '$this->opaque();',
    'opaque before schema' => '$this->opaque(); '.$schema,
    'opaque after schema' => $schema.' $this->opaque();',
    'opaque before schema in conditional' => 'if (true) { $this->opaque(); '.$schema.' }',
    'opaque after schema in conditional' => 'if (true) { '.$schema.' $this->opaque(); }',
    'opaque in loop' => 'foreach (["people"] as $name) { '.$schema.' $this->opaque(); }',
    'opaque in blueprint callback' => 'Schema::table("people", function (Blueprint $table): void { $table->string("extra"); $this->opaque(); });',
    'opaque in table loop callback' => 'foreach (["people"] as $name) { Schema::table($name, function (Blueprint $table): void { $this->opaque(); }); }',
    'opaque blueprint macro' => 'Schema::table("people", static function (Blueprint $table): void { $table->unknownColumnType("extra"); });',
    'opaque blueprint modifier' => 'Schema::table("people", static function (Blueprint $table): void { $table->string("extra")->opaque(); });',
    'opaque blueprint argument' => 'Schema::table("people", function (Blueprint $table): void { $table->string("extra")->default($this->opaque()); });',
    'opaque callback' => 'Schema::table("people", $callback);',
    'escaped blueprint' => 'Schema::table("people", static function (Blueprint $table): void { changeSchema($table); });',
    'reassigned blueprint' => 'Schema::table("people", static function (Blueprint $table): void { $table = $other; $table->string("extra"); });',
    'dynamic assignment may rebind blueprint' => 'Schema::table("people", static function (Blueprint $table): void { $$name = $other; $table->string("extra"); });',
    'shadowed blueprint' => 'Schema::table("people", static function (Blueprint $table): void { $callback = function ($table) { $table->string("extra"); }; $callback($other); });',
    'nested callback effect' => 'Schema::table("people", static function (Blueprint $table): void { array_map(function () { changeSchema(); }, [1]); });',
    'schema argument effect' => 'Schema::drop("people", changeSchema());',
    'opaque schema macro' => 'Schema::opaque("people");',
    'collection binding does not leak' => 'if (true) { DB::table("people")->chunkById(1, function (\\Illuminate\\Support\\Collection $rows) { $rows->count(); }); '.$schema.' $rows->opaque(); }',
    'nullsafe collection binding does not leak' => 'if (true) { DB::table("people")->chunkById(1, function (\\Illuminate\\Support\\Collection $rows) { $rows?->count(); }); '.$schema.' $rows?->opaque(); }',
    'nested collection binding does not leak' => 'if (true) { DB::table("people")->chunkById(1, function (\\Illuminate\\Support\\Collection $rows) { $f = function ($rows) { $rows->opaque(); }; $f($other); }); '.$schema.' }',
    'table binding does not leak after loop' => 'if (true) { foreach (["people"] as $name) { Schema::table($name, function (Blueprint $table) { $table->string("extra"); }); } $name = $other; Schema::table($name, function (Blueprint $table) { $table->string("extra"); }); }',
    'table binding does not leak into nested callback' => 'if (true) { foreach (["people"] as $name) { Schema::table($name, function (Blueprint $table) { $table->string("extra"); }); } $callback = function ($name) { Schema::table($name, function (Blueprint $table) { $table->string("extra"); }); }; $callback($other); }',
    'nested loop rebinds table variable' => 'if (true) { foreach (["people"] as $name) { foreach ($other as [$name]) { Schema::table($name, function (Blueprint $table) { $table->string("extra"); }); } } }',
] as $name => $body) {
    $cases[$name] = [$body, false, false, ['all-tables'], $magic];
}
$cases['supported conditional is local'] = ['if (true) { '.$schema.' }', false, true, ['tables'], ''];
$cases['native environment condition is local'] = ['if (getenv("DYNAMIC_SCHEMA")) { '.$schema.' }', false, true, ['tables'], ''];
$cases['environment argument effect is global'] = ['if (getenv($this->opaque())) { '.$schema.' }', false, false, ['all-tables'], $magic];
$cases['qualified unknown environment function is global'] = ['if (Other\\getenv("DYNAMIC_SCHEMA")) { '.$schema.' }', false, false, ['all-tables'], ''];
$cases['supported helper applies schema'] = ['$this->addColumn();', true, true, [], 'private function addColumn(): void { Schema::table("people", static function (Blueprint $table): void { $table->string("extra"); }); }'];
$referenceMethods = <<<'PHP'
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
    private function replaceCopy($receiver): void { $receiver = $this->replacement; }
    private function replaceItems($items): void { $items[0] = $this->replacement; }
    private function invokeArgument($action): void { $action(); }
    PHP;
file_put_contents($workspace.'/app/Services/ReferenceService.php', <<<'PHP'
    <?php
    namespace App\Services;
    final class ReferenceService {
        public static function retarget(&$name): void { $name = 'tokens'; }
        public function ignore(&$unused): void {}
    }
    PHP);
foreach ([
    'blueprint helper reference escape' => 'Schema::table("people", function (Blueprint $table) { $this->replace($table); $table->string("extra"); });',
    'blueprint named helper reference escape' => 'Schema::table("people", function (Blueprint $table) { $this->replace(receiver: $table); $table->string("extra"); });',
    'blueprint reference array escape' => 'Schema::table("people", function (Blueprint $table) { $this->replaceItems([&$table]); $table->string("extra"); });',
    'collection helper reference escape' => 'DB::table("people")->chunkById(10, function (\\Illuminate\\Support\\Collection $rows) { $this->replace($rows); $rows->count(); });',
    'loop helper reference escape' => 'if (true) { foreach (["people"] as $name) { $this->retarget($name); Schema::table($name, function (Blueprint $table) { $table->dropColumn("expires_at"); }); } }',
    'app static helper reference escape' => 'foreach (["people"] as $name) { \\App\\Services\\ReferenceService::retarget($name); Schema::table($name, function (Blueprint $table) { $table->dropColumn("expires_at"); }); }',
    'app unpacked reference escape' => '$refs = [&$name]; foreach (["people"] as $name) { \\App\\Services\\ReferenceService::retarget(...$refs); Schema::table($name, function (Blueprint $table) { $table->dropColumn("expires_at"); }); }',
    'app container helper reference escape' => 'foreach (["people"] as $name) { app(\\App\\Services\\ReferenceService::class)->retarget($name); Schema::table($name, function (Blueprint $table) { $table->dropColumn("expires_at"); }); }',
    'nested closure does not bind outer callable' => '$action = $this->replacement; if (true) { $register = function () { $action = function () {}; }; $action(); }',
    'closure parameter does not inherit outer binding' => '$action = function () {}; $invoke = function ($action) { $action(); }; $invoke($this->replacement);',
    'helper parameter does not inherit caller binding' => '$action = function () {}; $this->invokeArgument($this->replacement);',
    'closure overwrite stays opaque' => '$action = function () {}; $action = $this->replacement; $action();',
    'closure forward call stays opaque' => '$action(); $action = function () {};',
    'conditional closure does not bind outside' => 'if (true) { $action = function () {}; } $action();',
    'loop closure overwrite stays opaque' => '$action = function () {}; foreach ([1, 2] as $n) { $action(); $action = $this->replacement; }',
    'closure reference carrier stays opaque' => '$action = function () {}; $carrier = [&$action]; $carrier[0] = $this->replacement; $action();',
    'closure reference capture stays opaque' => '$action = function () {}; $change = function () use (&$action) { $action = $this->replacement; }; $change(); $action();',
    'closure unset stays opaque' => '$action = function () {}; unset($action); $action();',
    'dynamic closure overwrite stays opaque' => '$action = function () {}; $$name = $this->replacement; $action();',
    'closure goto cannot skip binding' => 'goto invoke; $action = function () {}; invoke: $action();',
    'closure capture cannot regain binding after overwrite' => '$change = function () use (&$action) { $action = $this->replacement; }; if (true) { $action = function () {}; $change(); $action(); }',
    'unknown transaction callback stays opaque' => '$action = $this->replacement; DB::transaction($action);',
    'nested closure does not bind transaction' => '$action = $this->replacement; $register = function () { $action = function () {}; }; DB::transaction($action);',
    'called closure reference escape' => 'Schema::table("people", function (Blueprint $table) { $replace = function (&$receiver) { $receiver = $this->replacement; }; $replace($table); $table->string("extra"); });',
    'builtin output overwrites called closure' => '$action = function () {}; preg_match("/^(SchemaTrap)$/", "SchemaTrap", $action); $action();',
    'builtin named output overwrites called closure' => '$action = function () {}; \\PREG_MATCH(subject: "SchemaTrap", matches: $action, pattern: "/^(SchemaTrap)$/"); $action();',
    'builtin output overwrites arrow closure' => '$action = static fn () => true; preg_match("/^(SchemaTrap)$/", "SchemaTrap", $action); $action();',
    'builtin output overwrites transaction callback' => '$action = function () {}; preg_match("/^(SchemaTrap)$/", "SchemaTrap", $action); DB::transaction($action);',
    'builtin named output overwrites transaction callback' => '$action = function () {}; preg_match(pattern: "/^(SchemaTrap)$/", subject: "SchemaTrap", matches: $action); DB::transaction(callback: $action);',
    'nested builtin output overwrites callable' => 'if (true) { $action = function () {}; preg_match("/^(SchemaTrap)$/", "SchemaTrap", $action); $action(); }',
    'helper builtin output overwrites callable' => '$this->matchAndInvoke();',
    'unpacked map callback is opaque' => 'array_map(...["SchemaTrap::run", [1]]);',
    'unpacked named filter callback is opaque' => 'array_filter(...["array" => [1], "callback" => "SchemaTrap::run"]);',
    'unpacked replacement callback is opaque' => 'preg_replace_callback(...["/x/", "SchemaTrap::run", "x"]);',
    'unpack cannot hide later named callback' => 'array_map(...[], callback: "SchemaTrap::run", array: [1]);',
    'builder tap opaque callback' => 'DB::table("people")->tap("SchemaTrap::run");',
    'builder chunk opaque callback' => 'DB::table("people")->insert(["email" => "x"]); DB::table("people")->chunkById(10, "SchemaTrap::run");',
    'collection each opaque callback' => 'DB::table("people")->insert(["email" => "x"]); DB::table("people")->chunkById(10, function (\\Illuminate\\Support\\Collection $rows) { $rows->each("SchemaTrap::run"); });',
    'builder variable callback' => 'DB::table("people")->tap($this->replacement);',
    'collection array callback' => 'DB::table("people")->chunkById(10, function (\\Illuminate\\Support\\Collection $rows) { $rows->each([SchemaTrap::class, "run"]); });',
    'builder opaque method is not DML' => 'DB::table("people")->unknownMacro();',
    'collection opaque method is not DML' => 'DB::table("people")->chunkById(10, function (\\Illuminate\\Support\\Collection $rows) { $rows->unknownMacro(); });',
    'builder result callback is checked' => 'DB::table("people")->get()->each("SchemaTrap::run");',
    'collection mapped result callback is checked' => 'DB::table("people")->chunkById(10, function (\\Illuminate\\Support\\Collection $rows) { $rows->map(static fn ($row) => $row)->each("SchemaTrap::run"); });',
    'builder terminal result is not a builder' => 'DB::table("people")->first()->where("id", 1);',
    'opaque root subquery is not DML' => 'DB::table($this->subquery)->count();',
    'opaque orderBy subquery is not DML' => 'DB::table("people")->orderBy($this->subquery);',
    'opaque orderByDesc subquery is not DML' => 'DB::table("people")->orderByDesc($this->subquery);',
    'opaque latest subquery is not DML' => 'DB::table("people")->latest($this->subquery);',
    'opaque oldest subquery is not DML' => 'DB::table("people")->oldest($this->subquery);',
    'opaque reorder subquery is not DML' => 'DB::table("people")->reorder($this->subquery);',
    'opaque named orderBy subquery is not DML' => 'DB::table("people")->orderBy(column: $this->subquery);',
    'opaque named orderByDesc subquery is not DML' => 'DB::table("people")->orderByDesc(column: $this->subquery);',
    'opaque named latest subquery is not DML' => 'DB::table("people")->latest(column: $this->subquery);',
    'opaque named oldest subquery is not DML' => 'DB::table("people")->oldest(column: $this->subquery);',
    'opaque named reorder subquery is not DML' => 'DB::table("people")->reorder(column: $this->subquery);',
    'opaque variable orderBy subquery is not DML' => '$column = $this->subquery; DB::table("people")->orderBy($column);',
    'opaque variable orderByDesc subquery is not DML' => '$column = $this->subquery; DB::table("people")->orderByDesc($column);',
    'opaque variable latest subquery is not DML' => '$column = $this->subquery; DB::table("people")->latest($column);',
    'opaque variable oldest subquery is not DML' => '$column = $this->subquery; DB::table("people")->oldest($column);',
    'opaque variable reorder subquery is not DML' => '$column = $this->subquery; DB::table("people")->reorder($column);',
] as $name => $body) {
    $extra = $name === 'helper builtin output overwrites callable'
        ? $referenceMethods.' private function matchAndInvoke(): void { $action = function () {}; preg_match("/^(SchemaTrap)$/", "SchemaTrap", $action); $action(); }'
        : $referenceMethods;
    $cases[$name] = [$body, false, false, ['all-tables'], $extra];
}
foreach ([
    'unused reference helper is neutral' => '$name = "people"; $this->ignoreReference($name);',
    'unused app reference helper is neutral' => '$name = "people"; app(\\App\\Services\\ReferenceService::class)->ignore($name);',
    'plain closure binding is neutral' => '$action = function () {}; $action();',
    'nested write does not invalidate outer binding' => '$action = function () {}; $register = function () { $action = $this->replacement; }; $action();',
    'arrow closure binding is neutral' => '$action = static fn () => true; $action();',
    'transaction closure binding is neutral' => '$action = function () {}; DB::transaction($action);',
    'conditional local closure binding is neutral' => 'if (true) { $action = function () {}; $action(); }',
    'nested local closure binding is neutral' => '$outer = function () { $action = function () {}; $action(); }; $outer();',
    'builtin unrelated output preserves closure' => '$action = function () {}; preg_match("/x/", "x", $matches); $action();',
    'builtin named unrelated output preserves closure' => '$action = function () {}; preg_match(matches: $matches, subject: "x", pattern: "/x/"); DB::transaction($action);',
    'builtin without output preserves closure' => '$action = function () {}; preg_match("/x/", "x"); $action();',
    'builtin callable creation preserves closure' => '$action = function () {}; $matcher = preg_match(...); $action();',
    'inline array callback is neutral' => 'array_map(static fn ($value) => $value, [1]);',
    'optional array callback may be absent' => 'array_filter([1]);',
    'iterating builtin callable creation is neutral' => '$mapper = array_map(...);',
    'inline builder callback is neutral' => 'DB::table("people")->tap(function ($query) {});',
    'inline collection callback is neutral' => 'DB::table("people")->chunkById(10, function (\\Illuminate\\Support\\Collection $rows) { $rows->each(static fn ($row) => true); $rows->count(); });',
    'collection result preserves collection context' => 'DB::table("people")->chunkById(10, function (\\Illuminate\\Support\\Collection $rows) { $rows->map(static fn ($row) => $row)->count(); });',
    'builder result has collection context' => 'DB::table("people")->get()->count();',
    'known data method callable creation is neutral' => '$chunk = DB::table("people")->chunkById(...);',
    'literal table loop keeps DML' => 'foreach (["people"] as $name) { DB::table($name)->whereNull("email")->update(["email" => "x"]); }',
    'literal orderBy column is neutral' => '$direction = "desc"; DB::table("people")->orderBy(column: "email", direction: $direction);',
    'literal orderByDesc column is neutral' => 'DB::table("people")->orderByDesc("email");',
    'literal latest column is neutral' => 'DB::table("people")->latest("email");',
    'literal oldest column is neutral' => 'DB::table("people")->oldest(column: "email");',
    'literal reorder column is neutral' => '$direction = "desc"; DB::table("people")->reorder(column: "email", direction: $direction);',
    'omitted ordering column is neutral' => 'DB::table("people")->latest()->oldest()->reorder();',
    'inline orderBy column is neutral' => 'DB::table("people")->orderBy(static function ($query) {});',
    'inline orderByDesc column is neutral' => 'DB::table("people")->orderByDesc(column: static function ($query) {});',
    'inline latest column is neutral' => 'DB::table("people")->latest(static fn ($query) => null);',
    'inline oldest column is neutral' => 'DB::table("people")->oldest(column: static fn ($query) => null);',
    'inline reorder column is neutral' => 'DB::table("people")->reorder(static function ($query) {});',
] as $name => $body) {
    $cases[$name] = [$body, true, true, [], $referenceMethods];
}
$cases['by-value helper does not escape Blueprint'] = ['Schema::table("people", function (Blueprint $table) { $this->replaceCopy($table); $table->string("extra"); });', false, true, ['tables'], $referenceMethods];
$cases['helper transaction closure is neutral'] = ['$this->transact();', true, true, [], 'private function transact(): void { $action = function () {}; DB::transaction($action); }'];
foreach ($cases as $name => [$body, $peopleKnown, $tokensKnown, $scopes, $extra]) {
    file_put_contents($workspace.'/database/migrations/001.php', $prefix."\n".$body."\n} ".$methods.' '.$extra." };\n");
    $source = new PhpSource($workspace); $index = new SchemaIndex($source); $index->load();
    if (($index->column('people', 'email') !== null) !== $peopleKnown || ($index->column('tokens', 'expires_at') !== null) !== $tokensKnown
        || array_column($index->uncertainties(), 'scope') !== $scopes || $source->warnings !== []) { throw new RuntimeException('Failed '.$name); }
    foreach ($index->uncertainties() as $reason) {
        if ($reason['file'] !== realpath($workspace.'/database/migrations/001.php') || $reason['line'] < 1) { throw new RuntimeException('Missing source anchor.'); }
    }
    if ($name === 'missing-column guard is evaluated' && $index->column('tokens', 'extra') === null) {
        throw new RuntimeException('Guarded column was not added.');
    }
    echo 'PASS: '.$name."\n";
}

$namespaced = str_replace('<?php', '<?php namespace Opaque; function getenv($name) { \\Illuminate\\Support\\Facades\\Schema::drop("tokens"); }', $prefix);
file_put_contents($workspace.'/database/migrations/001.php', $namespaced.' if (getenv("DYNAMIC_SCHEMA")) { '.$schema.' } } };');
$index = new SchemaIndex(new PhpSource($workspace));
$index->load();
if ($index->column('people', 'email') !== null || $index->column('tokens', 'expires_at') !== null
    || array_column($index->uncertainties(), 'scope') !== ['all-tables']) {
    throw new RuntimeException('A namespaced getenv replacement is not a native read.');
}
echo "PASS: namespaced environment replacement stays global\n";

$declaration = 'Schema::table($name, static function (Blueprint $table): void { $table->uuid("public_id")->nullable()->unique(); });';
$loops = [
    'literal tables' => ['["people", "tokens"]', $declaration, '', true],
    'self constant tables' => ['self::TABLES', $declaration, 'private const array TABLES = ["people", "tokens"];', true],
    'constant alias' => ['self::TABLES', $declaration, 'private const TABLES = self::NAMES; private const NAMES = ["people", "tokens"];', true],
    'named schema arguments' => ['["people", "tokens"]', 'Schema::table(callback: static function (Blueprint $table): void { $table->uuid("public_id"); }, table: $name);', '', true],
    'runtime table list' => ['$this->tables()', $declaration, '', false],
    'late static binding' => ['static::TABLES', $declaration, 'private const TABLES = ["people", "tokens"];', false],
    'external constant' => ['Other::TABLES', $declaration, '', false],
    'recursive constant' => ['self::TABLES', $declaration, 'private const TABLES = self::TABLES;', false],
    'non-string table' => ['["people", 1]', $declaration, '', false],
    'unpacked tables' => ['[...["people", "tokens"]]', $declaration, '', false],
    'keyed tables' => ['["a" => "people"]', $declaration, '', false],
    'too many tables' => ['['.implode(',', array_fill(0, 65, '"people"')).']', $declaration, '', false],
    'assignment in loop' => ['["people", "tokens"]', '$name = "other"; '.$declaration, '', false],
    'conditional loop' => ['["people", "tokens"]', 'if (true) { '.$declaration.' }', '', false, true],
    'break in loop' => ['["people", "tokens"]', $declaration.' break;', '', false, true],
    'unknown helper in loop' => ['["people", "tokens"]', '$this->changeTables(); '.$declaration, '', false, false],
    'callback changes loop variable' => ['["people", "tokens"]', 'Schema::table($name, function (Blueprint $table) use (&$name): void { $name = "other"; $table->uuid("public_id"); });', '', false],
    'known loop unknown blueprint is global' => ['["people", "tokens"]', 'Schema::table($name, function (Blueprint $table): void { $table->unknownColumnType("public_id"); });', '', false, false],
    'known loop unknown column is local' => ['["people", "tokens"]', 'Schema::table($name, function (Blueprint $table): void { $table->string($unknown); });', '', false, true],
];
foreach ($loops as $name => $case) {
    [$list, $body, $constants, $known] = $case;
    $unrelatedKnown = $case[4] ?? $known;
    $unrelated = 'Schema::create("unrelated", function (Blueprint $table): void { $table->id(); });';
    file_put_contents($workspace.'/database/migrations/001.php', $prefix.$unrelated.' foreach ('.$list.' as $name) { '.$body.' } } '.$constants.' };');
    $index = new SchemaIndex(new PhpSource($workspace));
    $index->load();
    foreach (['people', 'tokens'] as $table) {
        if (($index->column($table, 'public_id') !== null) !== $known
            || ($index->column($table, 'id') !== null) !== $known) {
            throw new RuntimeException('Failed table loop: '.$name);
        }
    }
    if ($known && $index->uncertainties() !== []) {
        throw new RuntimeException('Unexpected table loop uncertainty: '.$name);
    }
    if (($index->column('unrelated', 'id') !== null) !== $unrelatedKnown) {
        throw new RuntimeException('Unrelated table changed: '.$name);
    }
    echo 'PASS table loop: '.$name."\n";
}
