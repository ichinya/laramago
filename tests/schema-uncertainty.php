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
    'helper schema callback stays local' => ['$this->addColumn();', true, false, ['tables'], 'private function addColumn(): void { Schema::table("tokens", static function (Blueprint $table): void { $table->unknownColumnType("x"); }); }'],
    'helper raw SQL stays global' => ['$this->migrate();', false, false, ['all-tables'], 'private function migrate(): void { DB::statement("ALTER TABLE people ADD x TEXT"); }'],
    'recursive helpers scanned once' => ['$this->a();', true, true, [], 'private function a(): void { $this->b(); } private function b(): void { $this->a(); }'],
    'dynamic helper name stays global' => ['$m = "emptyHelper"; $this->{$m}();', false, false, ['all-tables'], 'private function emptyHelper(): void {}'],
    'missing helper stays global' => ['$this->missing();', false, false, ['all-tables'], ''],
    'raw SQL stays global' => ['DB::statement("ALTER TABLE tokens ADD hidden TEXT");', false, false, ['all-tables'], ''],
    'unknown table callback stays local' => ['Schema::table("tokens", static function (Blueprint $table): void { $table->unknownColumnType("x"); });', true, false, ['tables'], ''],
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
    'higher-order builtin keeps schema' => ['array_filter([1], static fn ($x): bool => true);', true, true, [], ''],
    'string callback stays global' => ['array_filter([1], "strlen");', false, false, ['all-tables'], ''],
];
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
    'unknown helper in loop' => ['["people", "tokens"]', '$this->changeTables(); '.$declaration, '', false, true],
    'callback changes loop variable' => ['["people", "tokens"]', 'Schema::table($name, function (Blueprint $table) use (&$name): void { $name = "other"; $table->uuid("public_id"); });', '', false],
    'known loop unknown blueprint is local' => ['["people", "tokens"]', 'Schema::table($name, function (Blueprint $table): void { $table->unknownColumnType("public_id"); });', '', false, true],
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
