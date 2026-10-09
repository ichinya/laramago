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
$cases = [
    'complete declarative schema' => ['', true, true, []],
    'unknown helper stays global' => ['$this->modifySchema();', false, false, ['all-tables']],
    'raw SQL stays global' => ['DB::statement("ALTER TABLE tokens ADD hidden TEXT");', false, false, ['all-tables']],
    'unknown table callback stays local' => ['Schema::table("tokens", static function (Blueprint $table): void { $table->unknownColumnType("x"); });', true, false, ['tables']],
    'dynamic schema table stays global' => ['Schema::table($this->tableName(), static function (Blueprint $table): void { $table->integer("x"); });', false, false, ['all-tables']],
];
foreach ($cases as $name => [$body, $peopleKnown, $tokensKnown, $scopes]) {
    file_put_contents($workspace.'/database/migrations/001.php', $prefix."\n".$body."\n} private function modifySchema(): void { throw new RuntimeException('Never execute migrations.'); } private function tableName(): string { return 'tokens'; } };\n");
    $source = new PhpSource($workspace); $index = new SchemaIndex($source); $index->load();
    if (($index->column('people', 'email') !== null) !== $peopleKnown || ($index->column('tokens', 'expires_at') !== null) !== $tokensKnown
        || array_column($index->uncertainties(), 'scope') !== $scopes || $source->warnings !== []) { throw new RuntimeException('Failed '.$name); }
    foreach ($index->uncertainties() as $reason) {
        if ($reason['file'] !== realpath($workspace.'/database/migrations/001.php') || $reason['line'] < 1) { throw new RuntimeException('Missing source anchor.'); }
    }
    echo 'PASS: '.$name."\n";
}
