<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
use Ichinya\Laramago\Analyzer\StaticAnalysis\{PhpSource, SchemaIndex};

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago schema reasons '.bin2hex(random_bytes(8));
mkdir($workspace.'/database/migrations', recursive: true);
file_put_contents($workspace.'/composer.json', '{}');
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
    'chained helpers keep schema' => ['$this->stepA();', true, true, [], 'private function stepA(): void { $this->stepB(); } private function stepB(): void {}'],
    'self helper keeps schema' => ['self::bootstrap();', true, true, [], 'private static function bootstrap(): void {}'],
    'helper DML keeps schema' => ['$this->backfill();', true, true, [], 'private function backfill(): void { DB::table("people")->where("id", 1)->update(["email" => "x"]); }'],
    'helper builtins keep schema' => ['$this->derive();', true, true, [], 'private function derive(): void { $m = []; if (is_string("x") && preg_match("/x/", "x", $m)) { substr("x", 0, 1); } }'],
    'helper schema callback stays local' => ['$this->addColumn();', true, false, ['tables'], 'private function addColumn(): void { Schema::table("tokens", static function (Blueprint $table): void { $table->unknownColumnType("x"); }); }'],
    'helper raw SQL stays global' => ['$this->migrate();', false, false, ['all-tables'], 'private function migrate(): void { DB::statement("ALTER TABLE people ADD x TEXT"); }'],
    'recursive helpers stay global' => ['$this->a();', false, false, ['all-tables'], 'private function a(): void { $this->b(); } private function b(): void { $this->a(); }'],
    'dynamic helper name stays global' => ['$m = "emptyHelper"; $this->{$m}();', false, false, ['all-tables'], 'private function emptyHelper(): void {}'],
    'missing helper stays global' => ['$this->missing();', false, false, ['all-tables'], ''],
    'raw SQL stays global' => ['DB::statement("ALTER TABLE tokens ADD hidden TEXT");', false, false, ['all-tables'], ''],
    'unknown table callback stays local' => ['Schema::table("tokens", static function (Blueprint $table): void { $table->unknownColumnType("x"); });', true, false, ['tables'], ''],
    'dynamic schema table stays global' => ['Schema::table($this->tableName(), static function (Blueprint $table): void { $table->integer("x"); });', false, false, ['all-tables'], ''],
];
foreach ($cases as $name => [$body, $peopleKnown, $tokensKnown, $scopes, $extra]) {
    file_put_contents($workspace.'/database/migrations/001.php', $prefix."\n".$body."\n} ".$methods.' '.$extra." };\n");
    $source = new PhpSource($workspace); $index = new SchemaIndex($source); $index->load();
    if (($index->column('people', 'email') !== null) !== $peopleKnown || ($index->column('tokens', 'expires_at') !== null) !== $tokensKnown
        || array_column($index->uncertainties(), 'scope') !== $scopes || $source->warnings !== []) { throw new RuntimeException('Failed '.$name); }
    foreach ($index->uncertainties() as $reason) {
        if ($reason['file'] !== realpath($workspace.'/database/migrations/001.php') || $reason['line'] < 1) { throw new RuntimeException('Missing source anchor.'); }
    }
    echo 'PASS: '.$name."\n";
}
