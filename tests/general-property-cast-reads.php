<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
require $package.'/vendor/autoload.php';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago cast reads '.bin2hex(random_bytes(8));
foreach (['packages', 'bootstrap', 'database/migrations'] as $directory) {
    mkdir($workspace.'/'.$directory, 0777, true);
}
file_put_contents($workspace.'/composer.json', json_encode(['name' => 'fixture/cast-reads', 'config' => ['vendor-dir' => 'packages']], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/bootstrap/app.php', '<?php file_put_contents(__DIR__."/../executed", "bootstrap"); throw new RuntimeException("Unexpected bootstrap.");');
$framework = <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
class Model {
    protected $casts = [];
    protected $table;
    protected $connection;
    protected $primaryKey = 'id';
    protected $keyType = 'int';
    public $incrementing = true;
    public function __get(string $name): mixed { throw new \RuntimeException('Never execute models.'); }
    public function __set(string $name, mixed $value): void { throw new \RuntimeException('Never execute models.'); }
}
namespace Illuminate\Database\Eloquent\Casts;
/** @template TGet
 * @template TSet */
class Attribute {}
namespace Illuminate\Database\Migrations;
abstract class Migration {}
namespace Illuminate\Support;
/** @template TKey of array-key
 * @template TValue
 * @property-read HigherOrderCollectionProxy<'map', TValue, static> $map */
class Collection {
    /** @template TMapValue
     * @param callable(TValue, TKey): TMapValue $callback
     * @return static<TKey, TMapValue> */
    public function map(callable $callback): static { throw new \RuntimeException('Never execute collections.'); }
    /** @return array<TKey, TValue> */
    public function all(): array { throw new \RuntimeException('Never execute collections.'); }
    public function __get(string $name): mixed { throw new \RuntimeException('Never execute collections.'); }
}
/** @template TMethod of string
 * @template TValue
 * @template TCollection */
class HigherOrderCollectionProxy {
    public function __get(string $name): mixed { throw new \RuntimeException('Never execute proxies.'); }
}
namespace Illuminate\Database\Eloquent;
/** @template TKey of array-key
 * @template TModel of Model
 * @extends \Illuminate\Support\Collection<TKey, TModel> */
class Collection extends \Illuminate\Support\Collection {}
namespace Carbon;
interface CarbonInterface extends \DateTimeInterface {}
class CarbonImmutable extends \DateTimeImmutable implements CarbonInterface {}
PHP;
file_put_contents($workspace.'/framework.php', $framework);
$models = <<<'PHP'
<?php
namespace Fixture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
file_put_contents(__DIR__.'/executed', 'model');
throw new \RuntimeException('Never load source.');
enum EntryState: string { case Open = 'open'; }
/**
 * @property float $amount
 * @property float|null $maybe
 * @property float $schemaMaybe
 * @property float $noColumn
 * @property string $flag
 * @property string $count
 * @property string $state
 * @property float $text
 * @property string $items
 * @property string $date
 * @property float $opaque
 * @property float $physical
 * @property-read float $strict
 * @property-write int|float|string $strict
 * @property float $stan
 * @phpstan-property-read float $stan
 * @property-write int|float|string $stan
 * @property float $psalm
 * @psalm-property-read float $psalm
 * @property-write int|float|string $psalm
 * @property float $separate
 * @property-write int|float|string $separate
 */
class GeneralEntry extends Model {
    protected $table = 'entries';
    protected $casts = ['amount' => 'decimal:2', 'maybe' => 'decimal:2', 'schemaMaybe' => 'decimal:2', 'noColumn' => 'decimal:2',
        'flag' => 'boolean', 'count' => 'integer', 'state' => EntryState::class, 'text' => 'string', 'items' => 'array',
        'date' => 'immutable_datetime', 'opaque' => 'UnavailableCaster', 'physical' => 'decimal:2', 'strict' => 'decimal:2',
        'stan' => 'decimal:2', 'psalm' => 'decimal:2', 'separate' => 'decimal:2'];
    public float $physical = 1.0;
}
class InheritedEntry extends GeneralEntry {}
/** @property-read float $amount */
class StrongChild extends GeneralEntry {}
/** @property float $amount */
trait GeneralAmount {}
class TraitEntry extends Model { use GeneralAmount; protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property-read float $amount */
trait StrongAmount {}
class StrongTraitEntry extends Model { use StrongAmount; protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @phpstan-property float $amount */
class StanGeneral extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @psalm-property float $amount */
class PsalmGeneral extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property float|null $amount */
class GetterEntry extends Model {
    protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2'];
    public function getAmountAttribute(): int { return 17; }
}
/** @property float $amount */
class SetterEntry extends Model {
    protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2'];
    public function setAmountAttribute(string $value): void {}
}
/** @property float $amount */
class ModernEntry extends Model {
    protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2'];
    /** @return Attribute<int, string> */
    protected function amount(): Attribute { throw new \RuntimeException('Never execute accessors.'); }
}
/** @property float $amount */
class DynamicEntry extends Model {
    protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2'];
    public function getCasts(): array { throw new \RuntimeException('Never execute cast configuration.'); }
}
/** @property float $amount */
class DispatchEntry extends Model {
    protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2'];
    public function getAttribute(string $name): mixed { return 1.0; }
}
/** @property float $amount
 * @property float $amount */
class DuplicateEntry extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property \DateTimeImmutable $amount */
class NamedGeneral extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property float $amount */
class MethodEntry extends Model { protected $table = 'entries'; protected function casts(): array { return ['amount' => 'decimal:2']; } }
/** @property float $amount
 * @property-write int|float|string $amount
 * @property-write int|float|string $amount */
class DuplicateWriter extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @phpstan-type WritableAmount int|float|string
 * @property float $amount
 * @property-write WritableAmount $amount */
class AliasWriter extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property float $amount
 * @property-write mixed $amount */
class MixedWriter extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property float $amount
 * @property-write never $amount */
class NeverWriter extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property-write int|float|string $amount */
trait ForeignWrite {}
/** @property float $amount */
class ForeignWriter extends Model { use ForeignWrite; protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/**
 * @property list<string> $labels
 * @property list<string> $jsonLabels
 * @property array<int, string> $keyed
 * @property array{'name':string,'active'?:bool} $shape
 * @property list<string>|array{'name':string} $alternatives
 * @property list<string>|null $docMaybe
 * @property list<string> $schemaMaybe
 * @property list<string> $missing
 * @property list<string|null> $nullableItems
 * @property array{'name':string,'note'?:string|null} $optionalItems
 * @property list<string>|bool $incompatible
 * @property list<string> $opaque
 */
class ArrayGeneral extends Model {
    protected $table = 'entries';
    protected $casts = ['labels' => 'array', 'jsonLabels' => 'json', 'keyed' => 'array', 'shape' => 'json',
        'alternatives' => 'array', 'docMaybe' => 'array', 'schemaMaybe' => 'array', 'missing' => 'array',
        'nullableItems' => 'array', 'optionalItems' => 'array', 'incompatible' => 'array', 'opaque' => 'UnavailableCaster'];
}
/** @property-write list<string> $labels */
class ArrayWriteOnly extends Model { protected $table = 'entries'; protected $casts = ['labels' => 'array']; }
/** @property-read list<int> $labels */
class ArrayExplicitRead extends Model { protected $table = 'entries'; protected $casts = ['labels' => 'array']; }
/** @property list<string> $labels */
class ArrayGetter extends Model {
    protected $table = 'entries'; protected $casts = ['labels' => 'array'];
    /** @return list<int> */
    public function getLabelsAttribute(): array { return [17]; }
}
/** @property list<string> $labels */
class ArrayPhysical extends Model {
    protected $table = 'entries'; protected $casts = ['labels' => 'array'];
    /** @var list<int> */
    public array $labels = [17];
}
/** @property float $amount
 * @property-write float $amount */
class DecimalDirectionalWriter extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property float $amount
 * @phpstan-property-write float $amount */
class DecimalStanWriter extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property float $amount
 * @psalm-property-write float $amount */
class DecimalPsalmWriter extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property-write float $amount */
class DecimalWriteOnly extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property-write float $amount */
class DecimalParentWriter extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
/** @property float $amount */
class DecimalChildWriter extends DecimalParentWriter {}
/** @property float|int $amount */
class DecimalNumericUnion extends Model { protected $table = 'entries'; protected $casts = ['amount' => 'decimal:2']; }
PHP;
file_put_contents($workspace.'/models.php', $models);
file_put_contents($workspace.'/database/migrations/0001_entries.php', <<<'PHP'
<?php
file_put_contents(__DIR__.'/../../executed', 'migration');
throw new RuntimeException('Never execute migrations.');
class EntryMigration extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::create('entries', function ($table) {
            foreach (['amount', 'maybe', 'physical', 'strict', 'stan', 'psalm', 'separate'] as $name) { $table->decimal($name, 10, 2); }
            $table->decimal('schemaMaybe', 10, 2)->nullable();
            $table->boolean('flag'); $table->integer('count'); $table->string('state'); $table->string('text');
            $table->text('items'); $table->dateTime('date'); $table->decimal('opaque', 10, 2);
            $table->json('labels'); $table->json('jsonLabels'); $table->json('keyed'); $table->json('shape');
            $table->json('alternatives'); $table->json('docMaybe'); $table->json('nullableItems');
            $table->json('optionalItems'); $table->json('incompatible');
        });
    }
}
PHP);
// Static schema readers deliberately do not evaluate a dynamic foreach.
$migration = file_get_contents($workspace.'/database/migrations/0001_entries.php');
$columns = implode(' ', array_map(static fn (string $name): string => '$table->decimal("'.$name.'", 10, 2);', ['amount', 'maybe', 'physical', 'strict', 'stan', 'psalm', 'separate']));
$migration = str_replace('foreach ([\'amount\', \'maybe\', \'physical\', \'strict\', \'stan\', \'psalm\', \'separate\'] as $name) { $table->decimal($name, 10, 2); }', $columns, $migration);
file_put_contents($workspace.'/database/migrations/0001_entries.php', $migration);
$cases = [
    'decimal read' => ['GeneralEntry', 'return $row->amount;', 'string', false],
    'decimal wrong numeric read' => ['GeneralEntry', 'return $row->amount;', 'float', true],
    'general float write retained' => ['GeneralEntry', '$row->amount = 12.5;', 'void', false],
    'general decimal string write' => ['GeneralEntry', '$row->amount = "12.50";', 'void', false],
    'general object write rejected' => ['GeneralEntry', '$row->amount = new \stdClass;', 'void', true],
    'explicit write union retained' => ['GeneralEntry', '$row->separate = "12.50";', 'void', false],
    'explicit write object rejected' => ['GeneralEntry', '$row->separate = new \stdClass;', 'void', true],
    'explicit write does not replace cast read' => ['GeneralEntry', 'return $row->separate;', 'string', false],
    'explicit write cannot claim numeric read' => ['GeneralEntry', 'return $row->separate;', 'float', true],
    'duplicate write remains native' => ['DuplicateWriter', '$row->amount = "12.50";', 'void', true],
    'write alias remains native' => ['AliasWriter', '$row->amount = "12.50";', 'void', true],
    'mixed write remains native' => ['MixedWriter', '$row->amount = "12.50";', 'void', true],
    'never write remains native' => ['NeverWriter', '$row->amount = "12.50";', 'void', true],
    'different owner write remains native' => ['ForeignWriter', '$row->amount = new \stdClass;', 'void', true],
    'nullable general retained' => ['GeneralEntry', 'return $row->maybe;', '?string', false],
    'nullable general wrong return' => ['GeneralEntry', 'return $row->maybe;', 'string', true],
    'nullable schema retained' => ['GeneralEntry', 'return $row->schemaMaybe;', '?string', false],
    'nullable schema wrong return' => ['GeneralEntry', 'return $row->schemaMaybe;', 'string', true],
    'unknown column remains nullable' => ['GeneralEntry', 'return $row->noColumn;', '?string', false],
    'unknown column nonnull rejected' => ['GeneralEntry', 'return $row->noColumn;', 'string', true],
    'boolean read' => ['GeneralEntry', 'return $row->flag;', 'bool', false],
    'integer read' => ['GeneralEntry', 'return $row->count;', 'int', false],
    'enum read' => ['GeneralEntry', 'return $row->state;', 'EntryState', false],
    'enum scalar read rejected' => ['GeneralEntry', 'return $row->state;', 'string', true],
    'string read' => ['GeneralEntry', 'return $row->text;', 'string', false],
    'array read' => ['GeneralEntry', 'return $row->items;', 'array<array-key, mixed>', false],
    'date read' => ['GeneralEntry', 'return $row->date;', '\Carbon\CarbonImmutable', false],
    'unknown cast documented fallback' => ['GeneralEntry', 'return $row->opaque;', 'float', false],
    'physical property authoritative' => ['GeneralEntry', 'return $row->physical;', 'string', true],
    'explicit read authoritative' => ['GeneralEntry', 'return $row->strict;', 'string', true],
    'phpstan read with merged write' => ['GeneralEntry', 'return $row->stan;', 'string', true],
    'psalm read with merged write' => ['GeneralEntry', 'return $row->psalm;', 'string', true],
    'inherited general' => ['InheritedEntry', 'return $row->amount;', 'string', false],
    'inherited strong read' => ['StrongChild', 'return $row->amount;', 'string', true],
    'trait general' => ['TraitEntry', 'return $row->amount;', 'string', false],
    'trait strong read' => ['StrongTraitEntry', 'return $row->amount;', 'string', true],
    'phpstan general' => ['StanGeneral', 'return $row->amount;', 'string', false],
    'psalm general' => ['PsalmGeneral', 'return $row->amount;', 'string', false],
    'typed getter authoritative and nonnull' => ['GetterEntry', 'return $row->amount;', 'int', false],
    'typed getter excludes cast string' => ['GetterEntry', 'return $row->amount;', 'string', true],
    'typed setter write' => ['SetterEntry', '$row->amount = "12.50";', 'void', false],
    'typed setter rejects float' => ['SetterEntry', '$row->amount = 12.5;', 'void', true],
    'modern accessor read' => ['ModernEntry', 'return $row->amount;', 'int', false],
    'modern accessor write' => ['ModernEntry', '$row->amount = "12.50";', 'void', false],
    'dynamic casts defer' => ['DynamicEntry', 'return $row->amount;', 'string', true],
    'custom dispatch defer' => ['DispatchEntry', 'return $row->amount;', 'string', true],
    'duplicate general defers' => ['DuplicateEntry', 'return $row->amount;', 'string', true],
    'unresolved named general defers' => ['NamedGeneral', 'return $row->amount;', 'string', true],
    'static casts method' => ['MethodEntry', 'return $row->amount;', 'string', false],
    'typo remains an error' => ['GeneralEntry', 'return $row->ammount;', 'string', true],
    'property names are case sensitive' => ['GeneralEntry', 'return $row->Amount;', 'string', true],
    'unrelated argument error' => ['GeneralEntry', 'needInteger("text"); return $row->amount;', 'string', true],
    'general string list retained' => ['ArrayGeneral', 'return $row->labels;', 'list<string>', false],
    'json string list retained' => ['ArrayGeneral', 'return $row->jsonLabels;', 'list<string>', false],
    'general keyed array retained' => ['ArrayGeneral', 'return $row->keyed;', 'array<int, string>', false],
    'general shape retained' => ['ArrayGeneral', 'return $row->shape;', "array{'name':string,'active'?:bool}", false],
    'general array alternatives retained' => ['ArrayGeneral', 'return $row->alternatives;', "list<string>|array{'name':string}", false],
    'general nullable list retained' => ['ArrayGeneral', 'return $row->docMaybe;', 'list<string>|null', false],
    'general nullable list nonnull rejected' => ['ArrayGeneral', 'return $row->docMaybe;', 'list<string>', true],
    'schema nullable list retained' => ['ArrayGeneral', 'return $row->schemaMaybe;', 'list<string>|null', false],
    'schema nullable list nonnull rejected' => ['ArrayGeneral', 'return $row->schemaMaybe;', 'list<string>', true],
    'unknown column list remains nullable' => ['ArrayGeneral', 'return $row->missing;', 'list<string>|null', false],
    'unknown column list nonnull rejected' => ['ArrayGeneral', 'return $row->missing;', 'list<string>', true],
    'nullable list elements retained' => ['ArrayGeneral', 'return $row->nullableItems;', 'list<string|null>', false],
    'optional nullable shape member retained' => ['ArrayGeneral', 'return $row->optionalItems;', "array{'name':string,'note'?:string|null}", false],
    'wrong list element rejected' => ['ArrayGeneral', 'return $row->labels;', 'list<int>', true],
    'wrong keyed array element rejected' => ['ArrayGeneral', 'return $row->keyed;', 'array<int, int>', true],
    'wrong shape key rejected' => ['ArrayGeneral', 'return $row->shape;', "array{'other':string}", true],
    'wrong nullable element rejected' => ['ArrayGeneral', 'return $row->nullableItems;', 'list<string>', true],
    'array scalar union cannot refine reads' => ['ArrayGeneral', 'return $row->incompatible;', 'array<int|string, mixed>', false],
    'unknown array cast retains native declaration' => ['ArrayGeneral', 'return $row->opaque;', 'list<string>', false],
    'array write detail retained' => ['ArrayGeneral', '$row->labels = ["first"];', 'void', false],
    'wrong array write element rejected' => ['ArrayGeneral', '$row->labels = [17];', 'void', true],
    'write-only list cannot refine read' => ['ArrayWriteOnly', 'return $row->labels;', 'list<string>', true],
    'explicit array read retained' => ['ArrayExplicitRead', 'return $row->labels;', 'list<int>', false],
    'explicit array wrong item rejected' => ['ArrayExplicitRead', 'return $row->labels;', 'list<string>', true],
    'actual array getter retained' => ['ArrayGetter', 'return $row->labels;', 'list<int>', false],
    'actual array getter wrong item rejected' => ['ArrayGetter', 'return $row->labels;', 'list<string>', true],
    'physical array declaration retained' => ['ArrayPhysical', 'return $row->labels;', 'list<int>', false],
    'physical array wrong item rejected' => ['ArrayPhysical', 'return $row->labels;', 'list<string>', true],
    'general decimal integer write' => ['GeneralEntry', '$row->amount = 12;', 'void', false],
    'general decimal union write' => ['GeneralEntry', '$row->amount = random_int(0, 1) === 1 ? 1.0 : "12.50";', 'void', false],
    'general decimal array write rejected' => ['GeneralEntry', '$row->amount = [12];', 'void', true],
    'general decimal nonnull write rejected' => ['GeneralEntry', '$row->amount = null;', 'void', true],
    'general decimal doc nullable write' => ['GeneralEntry', '$row->maybe = null;', 'void', false],
    'general decimal schema nullable write' => ['GeneralEntry', '$row->schemaMaybe = null;', 'void', false],
    'general decimal unknown column nullable write' => ['GeneralEntry', '$row->noColumn = null;', 'void', false],
    'decimal directional float write retained' => ['DecimalDirectionalWriter', '$row->amount = "12.50";', 'void', true],
    'decimal phpstan float write retained' => ['DecimalStanWriter', '$row->amount = "12.50";', 'void', true],
    'decimal psalm float write retained' => ['DecimalPsalmWriter', '$row->amount = "12.50";', 'void', true],
    'decimal write-only float retained' => ['DecimalWriteOnly', '$row->amount = "12.50";', 'void', true],
    'decimal ancestor directional write retained' => ['DecimalChildWriter', '$row->amount = "12.50";', 'void', true],
    'decimal stronger numeric union write retained' => ['DecimalNumericUnion', '$row->amount = "12.50";', 'void', true],
    'decimal physical float write retained' => ['GeneralEntry', '$row->physical = "12.50";', 'void', true],
    'decimal unknown cast write retained' => ['GeneralEntry', '$row->opaque = "12.50";', 'void', true],
    'decimal read remains string after write' => ['GeneralEntry', '$row->amount = "12.50"; return $row->amount;', 'string', false],
    'decimal storage does not validate numeric text' => ['GeneralEntry', '$row->amount = "not-number";', 'void', false],
];
$source = '<?php namespace Fixture; function needInteger(int $value): void {}' ."\n";
$ranges = [];
$nativeReturn = static function (string $type): string {
    return str_contains($type, '<') || str_contains($type, '{')
        ? (str_starts_with($type, '?') || str_ends_with($type, '|null') ? '?array' : 'array') : $type;
};
foreach ($cases as $label => [$class, $body, $return, $error]) {
    $name = 'castCase'.count($ranges);
    $line = substr_count($source, "\n") + 1;
    $source .= '/** @return '.$return.' */ function '.$name.'('.$class.' $row): '.$nativeReturn($return).' { '.$body." }\n";
    $ranges[$name] = ['label' => $label, 'line' => $line, 'error' => $error];
}
$collectionCases = [
    'collection general cast read' => ['GeneralEntry', 'return $rows->map->amount->all();', 'array<int, string>', false],
    'collection precise wrong read' => ['GeneralEntry', 'return $rows->map->amount->all();', 'array<int, float>', true],
    'collection explicit read retained' => ['StrongChild', 'return $rows->map->amount->all();', 'array<int, string>', true],
    'collection is not a model' => ['GeneralEntry', 'return $rows->amount;', 'string', true],
];
foreach ($collectionCases as $label => [$class, $body, $return, $error]) {
    $name = 'castCase'.count($ranges);
    $source .= "/**\n * @param \\Illuminate\\Database\\Eloquent\\Collection<int, ".$class.'> $rows'."\n * @return ".$return."\n */\n";
    $line = substr_count($source, "\n") + 1;
    $source .= 'function '.$name.'(\\Illuminate\\Database\\Eloquent\\Collection $rows): '.(str_contains($return, '<') ? 'array' : $return).' { '.$body." }\n";
    $ranges[$name] = ['label' => $label, 'line' => $line, 'error' => $error];
}
$expectedRefinements = [
    'decimal wrong numeric read' => 'invalid-return-statement',
    'explicit write object rejected' => 'invalid-property-assignment-value',
    'explicit write cannot claim numeric read' => 'invalid-return-statement',
    'nullable general wrong return' => 'nullable-return-statement',
    'nullable schema wrong return' => 'nullable-return-statement',
    'unknown column nonnull rejected' => 'nullable-return-statement',
    'enum scalar read rejected' => 'invalid-return-statement',
    'typed getter excludes cast string' => 'invalid-return-statement',
    'typed setter rejects float' => 'invalid-property-assignment-value',
    'collection precise wrong read' => 'invalid-return-statement',
    'collection explicit read retained' => 'invalid-return-statement',
    'unrelated argument error' => 'invalid-argument',
    'general nullable list nonnull rejected' => 'nullable-return-statement',
    'schema nullable list nonnull rejected' => 'nullable-return-statement',
    'unknown column list nonnull rejected' => 'nullable-return-statement',
    'wrong list element rejected' => 'invalid-return-statement',
    'wrong keyed array element rejected' => 'invalid-return-statement',
    'wrong shape key rejected' => 'invalid-return-statement',
    'wrong array write element rejected' => 'invalid-property-assignment-value',
    'explicit array wrong item rejected' => 'invalid-return-statement',
    'actual array getter wrong item rejected' => 'invalid-return-statement',
    'physical array wrong item rejected' => 'invalid-return-statement',
    'general object write rejected' => 'invalid-property-assignment-value',
    'general decimal array write rejected' => 'invalid-property-assignment-value',
    'general decimal nonnull write rejected' => 'native-assignment-code',
];
$caseNodes = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($source);
foreach ($caseNodes as $namespace) {
    foreach ($namespace->stmts ?? [] as $function) {
        if (!$function instanceof \PhpParser\Node\Stmt\Function_ || !isset($ranges[$function->name->name])) { continue; }
        $case = &$ranges[$function->name->name];
        $code = $expectedRefinements[$case['label']] ?? null;
        if ($code === null) { continue; }
        $statement = $function->stmts[0];
        $expression = $statement->expr;
        if ($expression instanceof \PhpParser\Node\Expr\Assign) { $expression = $expression->expr; }
        if ($expression instanceof \PhpParser\Node\Expr\FuncCall) { $expression = $expression->args[0]->value; }
        $case['expectedRefinement'] = ['codes' => $code === 'nullable-return-statement' ? ['invalid-return-statement', 'nullable-return-statement'] : ($code === 'native-assignment-code' ? [] : [$code]),
            'start' => $expression->getStartFilePos(), 'end' => $expression->getEndFilePos() + 1];
        if ($code === 'native-assignment-code') { $case['expectedRefinement']['nativeCodeOracle'] = true; }
        unset($case);
    }
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/context.php', '<?php namespace Fixture; function observeContracts(GeneralEntry $row): void { $row->amount; }');
file_put_contents($workspace.'/case-matrix.json', json_encode($ranges, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$root = $argv[2];
$observe = ($argv[3] ?? '') === 'observe';
$plugin = new class($root, $observe) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root, private bool $observe) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/cast-reads', 'Cast read contracts', 'Observe native declarations or apply source-certified model cast reads.'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        if (!$this->observe) {
            $provider = new \Ichinya\Laramago\Analyzer\EloquentPropertyProvider($this->root);
            $registry->registerPropertyTypeProvider($provider);
            $registry->registerInitializationHook($provider);
            $registry->registerBeforeAnalysisHook($provider);
            $registry->registerPropertyTypeProvider(new \Ichinya\Laramago\Analyzer\HigherOrderMapPropertyProvider($provider));
        }
        $registry->registerAfterFileAnalysisHook(new class($this->root) implements \Mago\Sdk\Analyzer\AfterFileAnalysisHook {
            public function __construct(private string $root) {}
            public function getRequirements(): array { return []; }
            public function afterFileAnalysis(\Mago\Sdk\Analyzer\AfterFileAnalysisContext $context): void {
                if (basename($context->analysis->file) !== 'context.php') { return; }
                $source = new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root);
                $contracts = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts($source);
                $rows = [];
                foreach (['amount', 'maybe', 'schemaMaybe', 'noColumn', 'flag', 'count', 'state', 'text', 'items', 'date', 'opaque', 'physical', 'strict', 'stan', 'psalm', 'separate'] as $name) {
                    $rows[$name] = ['native' => $context->codebase->getDeclaringMagicProperty('Fixture\\GeneralEntry', '$'.$name),
                        'general' => $contracts->general($context->codebase, $context->types, 'Fixture\\GeneralEntry', $name)];
                }
                foreach (['InheritedEntry', 'StrongChild', 'TraitEntry', 'StrongTraitEntry', 'StanGeneral', 'PsalmGeneral', 'GetterEntry', 'SetterEntry', 'ModernEntry', 'DynamicEntry', 'DispatchEntry', 'DuplicateEntry', 'NamedGeneral', 'MethodEntry', 'DuplicateWriter', 'AliasWriter', 'MixedWriter', 'NeverWriter', 'ForeignWriter'] as $class) {
                    $rows[$class] = ['native' => $context->codebase->getDeclaringMagicProperty('Fixture\\'.$class, '$amount'),
                        'general' => $contracts->general($context->codebase, $context->types, 'Fixture\\'.$class, 'amount')];
                }
                $stages = [];
                $classMetadata = [];
                $configuration = [];
                foreach (['GeneralEntry', 'InheritedEntry', 'TraitEntry', 'StanGeneral', 'GetterEntry', 'MethodEntry'] as $class) {
                    $name = 'Fixture\\'.$class;
                    $owner = $context->codebase->getClassLike($name);
                    $classMetadata[$class] = $owner;
                    $nodes = $owner === null ? null : $source->read($owner->location->file);
                    $declaration = null;
                    foreach ((new \PhpParser\NodeFinder)->findInstanceOf($nodes ?? [], \PhpParser\Node\Stmt\ClassLike::class) as $node) {
                        if (strcasecmp($node->namespacedName?->toString() ?? '', $name) === 0) { $declaration = $node; break; }
                    }
                    foreach ($declaration?->getProperties() ?? [] as $field) { foreach ($field->props as $item) {
                        if (!in_array($item->name->name, ['casts', 'table', 'connection', 'primaryKey', 'keyType', 'incrementing'], true)) { continue; }
                        $native = $context->codebase->getDeclaringProperty($name, '$'.$item->name->name);
                        $sourceDefault = \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource::value($item->default, $name, $name);
                        $nativeDefault = (new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection($context->codebase, $source))->default($name, $item->name->name);
                        $configuration[$class][$item->name->name] = ['native' => $native, 'sourceDefault' => $sourceDefault,
                            'nativeDefault' => $nativeDefault, 'equalDefault' => $sourceDefault === $nativeDefault,
                            'normalizedCastMapEqual' => $item->name->name !== 'casts' ? null : (new \ReflectionMethod($contracts, 'sameCastMap'))->invoke(null, $sourceDefault, $nativeDefault),
                            'sourceNameSpan' => [$item->name->getStartFilePos(), $item->name->getEndFilePos() + 1]];
                    } }
                    $tags = (new \ReflectionMethod($contracts, 'tags'))->invoke($contracts, $context->codebase, $name, 'amount');
                    $native = $rows[$class === 'GeneralEntry' ? 'amount' : $class]['native'];
                    $tag = $native?->type === null || $tags === null ? null : (new \ReflectionMethod($contracts, 'tagAt'))->invoke($contracts, $tags, $native->type);
                    $matches = $tag === null ? null : (new \ReflectionMethod($contracts, 'matches'))->invoke($contracts, $tag['type'], $native->type->type, $context->types);
                    $stages[$class] = ['tags' => $tags, 'nativeTag' => $tag, 'parsedNativeTypeMatches' => $matches,
                        'sourceClassSpan' => $declaration === null ? null : [$declaration->getStartFilePos(), $declaration->getEndFilePos() + 1],
                        'sourceClassNameSpan' => $declaration?->name === null ? null : [$declaration->name->getStartFilePos(), $declaration->name->getEndFilePos() + 1],
                        'sourceHash' => $owner === null ? null : $source->contentHash($owner->location->file)];
                }
                file_put_contents($this->root.'/native-initial-metadata.txt', var_export(['rows' => $rows, 'classes' => $classMetadata, 'configuration' => $configuration, 'stages' => $stages], true));
                $checks = [];
                $genuine = $rows['amount']['general'];
                if ($genuine === null) { throw new RuntimeException('Genuine multi-field general declaration was not admitted.'); }
                if ($genuine !== null) {
                    $context->codebase->getMagicProperty('Fixture\\GeneralEntry', '$amount');
                    $owner = $context->codebase->getClassLike('Fixture\\GeneralEntry');
                    $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                    $snapshot = $cache->values;
                    $type = $genuine->type;
                    $typeVariants = [
                        'read inferred' => ['inferred' => true],
                        'read not from docblock' => ['fromDocblock' => false],
                        'read wrong type' => ['type' => \Mago\Sdk\Analyzer\Type::bool()],
                        'read wrong file' => ['location' => new \Mago\Sdk\SourceLocation('foreign.php', $type->location->span)],
                        'read wrong span' => ['location' => new \Mago\Sdk\SourceLocation($type->location->file, new \Mago\Sdk\Span($type->location->span->start + 1, $type->location->span->end))],
                    ];
                    $variants = [
                        'missing native general' => [$genuine, null],
                        'wrong property case' => [$genuine, ['name' => '$Amount']],
                        'native physical declaration' => [$genuine, ['declaredType' => $type]],
                        'private read visibility' => [$genuine, ['readVisibility' => \Mago\Sdk\Analyzer\Type\Visibility::Private]],
                        'private write visibility' => [$genuine, ['writeVisibility' => \Mago\Sdk\Analyzer\Type\Visibility::Private]],
                        'native static flag' => [$genuine, ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($genuine->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::STATIC)]],
                        'native read-only flag' => [$genuine, ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($genuine->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::READONLY)]],
                        'wrong declaring class name' => [$owner, ['name' => 'Fixture\\OtherEntry']],
                        'wrong original class name' => [$owner, ['originalName' => 'Fixture\\OtherEntry']],
                        'wrong declaring class kind' => [$owner, ['kind' => \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface]],
                        'incomplete class hierarchy' => [$owner, ['unresolvedHierarchyDependencies' => ['Unknown']]],
                        'wrong class name file' => [$owner, ['nameLocation' => new \Mago\Sdk\SourceLocation('foreign.php', $owner->nameLocation->span)]],
                        'wrong class name span' => [$owner, ['nameLocation' => new \Mago\Sdk\SourceLocation($owner->nameLocation->file, new \Mago\Sdk\Span($owner->nameLocation->span->start + 1, $owner->nameLocation->span->end))]],
                    ];
                    foreach ($typeVariants as $label => $changes) {
                        $variants[$label] = [$genuine, ['type' => new ($type::class)(...array_replace(get_object_vars($type), $changes))]];
                    }
                    foreach ($variants as $label => [$originalMetadata, $changes]) {
                        $replacement = $changes === null ? null : new ($originalMetadata::class)(...array_replace(get_object_vars($originalMetadata), $changes));
                        $replaced = 0;
                        foreach ($snapshot as $operation => $entries) { foreach ($entries as $key => $entry) {
                            if (is_object($entry) && $entry == $originalMetadata) { $cache->values[$operation][$key] = $replacement; $replaced++; }
                        } }
                        try {
                            if ($replaced === 0) { throw new RuntimeException('Vacuous cast metadata control: '.$label); }
                            $accepted = (new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts(new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root)))->general($context->codebase, $context->types, 'Fixture\\GeneralEntry', 'amount') !== null;
                            $checks[$label] = ['replaced' => $replaced, 'accepted' => $accepted];
                            file_put_contents($this->root.'/native-controls.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                            if ($accepted) { throw new RuntimeException('Cast metadata control accepted: '.$label); }
                        } finally { $cache->values = $snapshot; }
                    }
                    $checks['restored genuine general'] = ['accepted' => (new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts(new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root)))->general($context->codebase, $context->types, 'Fixture\\GeneralEntry', 'amount') !== null];
                    if (!$checks['restored genuine general']['accepted']) { throw new RuntimeException('Restored cast metadata no longer admitted.'); }
                    file_put_contents($this->root.'/native-controls.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                }
                $actualCast = \Ichinya\Laramago\Analyzer\StaticAnalysis\AttributeTypes::cast('decimal:2', $context->codebase);
                $paired = $contracts->metadataCastContract($context->codebase, $context->types, 'Fixture\\GeneralEntry', 'separate', $actualCast);
                $expectedWrite = \Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes::parse('int|float|string');
                $writeChecks = ['genuine source directional write' => $paired?->writeType !== null && $context->types->equals($paired->writeType, $expectedWrite)];
                if (!$writeChecks['genuine source directional write']) { throw new RuntimeException('Source directional write was not admitted.'); }
                $nativeSeparate = $rows['separate']['native'];
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                foreach (['provided native write is authoritative' => $nativeSeparate->type,
                    'foreign native write location is rejected' => new ($nativeSeparate->type::class)(...array_replace(get_object_vars($nativeSeparate->type), ['location' => new \Mago\Sdk\SourceLocation('foreign.php', $nativeSeparate->type->location->span)]))] as $label => $writeMetadata) {
                    $replacement = new ($nativeSeparate::class)(...array_replace(get_object_vars($nativeSeparate), ['writeType' => $writeMetadata]));
                    $replaced = 0;
                    foreach ($snapshot as $operation => $entries) { foreach ($entries as $key => $entry) { if (is_object($entry) && $entry == $nativeSeparate) { $cache->values[$operation][$key] = $replacement; $replaced++; } } }
                    try {
                        if ($replaced === 0) { throw new RuntimeException('Vacuous directional native write control: '.$label); }
                        $contract = (new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts(new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root)))->metadataCastContract($context->codebase, $context->types, 'Fixture\\GeneralEntry', 'separate', $actualCast);
                        $writeChecks[$label] = str_starts_with($label, 'provided') ? $contract?->writeType !== null && $context->types->equals($contract->writeType, $nativeSeparate->type->type) : $contract === null;
                        if (!$writeChecks[$label]) { throw new RuntimeException('Directional native write control failed: '.$label); }
                    } finally { $cache->values = $snapshot; }
                }
                file_put_contents($this->root.'/write-controls.json', json_encode($writeChecks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $decimalActual = \Ichinya\Laramago\Analyzer\StaticAnalysis\AttributeTypes::cast('decimal:2', $context->codebase);
                if ($decimalActual?->writeType === null) { throw new RuntimeException('Missing genuine decimal writer domain.'); }
                $decimalContract = function (string $class, string $property, ?\Mago\Sdk\Analyzer\PropertyType $actual = null, bool $setter = false) use ($context, $decimalActual): ?\Mago\Sdk\Analyzer\PropertyType {
                    return (new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts(new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root)))->metadataCastContract(
                        $context->codebase, $context->types, 'Fixture\\'.$class, $property, $actual ?? $decimalActual, $setter,
                    );
                };
                $decimalChecks = [];
                $decimalChecks['genuine general decimal writer'] = $context->types->equals($decimalContract('GeneralEntry', 'amount')->writeType, $decimalActual->writeType);
                $decimalChecks['genuine nullable general writer'] = $context->types->equals($decimalContract('GeneralEntry', 'maybe')->writeType,
                    \Mago\Sdk\Analyzer\Type::union($decimalActual->writeType, \Mago\Sdk\Analyzer\Type::null()));
                $nullableDecimal = \Ichinya\Laramago\Analyzer\StaticAnalysis\AttributeTypes::nullable($decimalActual, true);
                $decimalChecks['actual nullable writer retained'] = $context->types->equals($decimalContract('GeneralEntry', 'amount', $nullableDecimal)->writeType, $nullableDecimal->writeType);
                $decimalChecks['both nullable writer retained once'] = $context->types->equals($decimalContract('GeneralEntry', 'maybe', $nullableDecimal)->writeType, $nullableDecimal->writeType);
                $setterActual = new \Mago\Sdk\Analyzer\PropertyType($decimalActual->readType, \Mago\Sdk\Analyzer\Type::string());
                $decimalChecks['typed setter direction retained'] = $context->types->equals($decimalContract('GeneralEntry', 'amount', $setterActual, true)->writeType, $setterActual->writeType);
                $narrowWriter = new \Mago\Sdk\Analyzer\PropertyType($decimalActual->readType, \Mago\Sdk\Analyzer\Type::float());
                $decimalChecks['contradictory actual writer not broadened'] = $context->types->equals($decimalContract('GeneralEntry', 'amount', $narrowWriter)->writeType, \Mago\Sdk\Analyzer\Type::float());
                foreach (['DecimalDirectionalWriter', 'DecimalStanWriter', 'DecimalPsalmWriter', 'DecimalWriteOnly', 'DecimalChildWriter', 'DynamicEntry'] as $class) {
                    $nativeWrite = $context->codebase->getDeclaringMagicProperty('Fixture\\'.$class, '$amount') ?? $context->codebase->getMagicProperty('Fixture\\'.$class, '$amount');
                    if ($nativeWrite === null) { throw new RuntimeException('Missing native directional or dynamic writer: '.$class); }
                    $contract = $decimalContract($class, 'amount');
                    $decimalChecks['authoritative '.$class] = $contract === null || $context->types->equals($contract->writeType, \Mago\Sdk\Analyzer\Type::float());
                }
                $unionMetadata = $context->codebase->getDeclaringMagicProperty('Fixture\\DecimalNumericUnion', '$amount');
                if ($unionMetadata?->type === null) { throw new RuntimeException('Missing stronger numeric general domain.'); }
                $decimalChecks['non-plain numeric general writer retained'] = $context->types->equals($decimalContract('DecimalNumericUnion', 'amount')->writeType, $unionMetadata->type->type);
                $nativeAmount = $context->codebase->getDeclaringMagicProperty('Fixture\\GeneralEntry', '$amount');
                $context->codebase->getMagicProperty('Fixture\\GeneralEntry', '$amount');
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                foreach (['provided native writer' => $nativeAmount->type,
                    'foreign provided native writer' => new ($nativeAmount->type::class)(...array_replace(get_object_vars($nativeAmount->type), ['location' => new \Mago\Sdk\SourceLocation('foreign.php', $nativeAmount->type->location->span)]))] as $label => $provided) {
                    $replacement = new ($nativeAmount::class)(...array_replace(get_object_vars($nativeAmount), ['writeType' => $provided]));
                    $replaced = 0;
                    foreach ($snapshot as $operation => $entries) { foreach ($entries as $key => $entry) {
                        if (is_object($entry) && $entry == $nativeAmount) { $cache->values[$operation][$key] = $replacement; ++$replaced; }
                    } }
                    try {
                        if ($replaced === 0) { throw new RuntimeException('Vacuous native decimal write control: '.$label); }
                        $contract = $decimalContract('GeneralEntry', 'amount');
                        $decimalChecks[$label] = str_starts_with($label, 'provided')
                            ? $contract !== null && $context->types->equals($contract->writeType, $nativeAmount->type->type) : $contract === null;
                    } finally { $cache->values = $snapshot; }
                }
                $decimalChecks['restored general decimal writer'] = $context->types->equals($decimalContract('GeneralEntry', 'amount')->writeType, $decimalActual->writeType);
                file_put_contents($this->root.'/decimal-writer-controls.json', json_encode($decimalChecks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                if (in_array(false, $decimalChecks, true)) { throw new RuntimeException('Decimal writer native contract control failed.'); }
                $broadArray = \Ichinya\Laramago\Analyzer\StaticAnalysis\AttributeTypes::cast('array', $context->codebase);
                if ($broadArray?->readType === null || $broadArray->writeType === null) { throw new RuntimeException('Missing native builtin array category.'); }
                $arrayContract = function (string $property, ?\Mago\Sdk\Analyzer\Type $read = null, string $class = 'ArrayGeneral', bool $getter = false) use ($context, $broadArray): ?\Mago\Sdk\Analyzer\PropertyType {
                    return (new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts(new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root)))->metadataCastContract(
                        $context->codebase, $context->types, 'Fixture\\'.$class, $property,
                        new \Mago\Sdk\Analyzer\PropertyType($read ?? $broadArray->readType, $broadArray->writeType), false, $getter,
                    );
                };
                $arrayChecks = [];
                $arrayMetadata = [];
                foreach (['labels', 'jsonLabels', 'keyed', 'shape', 'alternatives', 'docMaybe', 'nullableItems', 'optionalItems'] as $property) {
                    $nativeArray = $context->codebase->getDeclaringMagicProperty('Fixture\\ArrayGeneral', '$'.$property);
                    $contract = $arrayContract($property);
                    $arrayMetadata[$property] = $nativeArray;
                    $arrayChecks['genuine '.$property] = $nativeArray?->type !== null && $contract?->readType !== null
                        && $context->types->equals($contract->readType, $nativeArray->type->type);
                    if (!$arrayChecks['genuine '.$property]) {
                        $proof = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts(new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root));
                        $tags = (new \ReflectionMethod($proof, 'tags'))->invoke($proof, $context->codebase, 'Fixture\\ArrayGeneral', $property);
                        $tag = $nativeArray?->type === null || $tags === null ? null : (new \ReflectionMethod($proof, 'tagAt'))->invoke($proof, $tags, $nativeArray->type);
                        $parsed = $tag === null ? null : \Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes::parse(preg_replace('/\s+/', '', $tag['type']));
                        $variants = [];
                        $record = $parsed?->atomicTypes[0] ?? null;
                        if ($record instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType && $record->knownItems !== null) {
                            foreach (['sorted source keys' => [true, false], 'source optional undefined flags' => [false, true], 'sorted source keys and optional flags' => [true, true]] as $label => [$sort, $flags]) {
                                $items = $record->knownItems;
                                foreach ($items as $index => $item) {
                                    if ($flags && $item->optional) {
                                        $itemType = $item->type->withFlags(new \Mago\Sdk\Analyzer\Type\TypeFlags(...array_replace(get_object_vars($item->type->flags), ['possiblyUndefined' => true])));
                                        $items[$index] = new \Mago\Sdk\Analyzer\Type\ArrayItem($item->key, $item->optional, $itemType);
                                    }
                                }
                                if ($sort) { usort($items, static fn ($left, $right): int => strcmp((string) $left->key->value, (string) $right->key->value)); }
                                $variant = \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\KeyedArrayType($items, $record->keyType, $record->valueType, $record->nonEmpty))->withFlags($parsed->flags);
                                $variants[$label] = ['type' => $variant, 'equalsNative' => $nativeArray?->type !== null && $context->types->equals($variant, $nativeArray->type->type)];
                            }
                        }
                        $stages = ['tags' => $tags, 'matchedSourceTag' => $tag, 'parsedSource' => $parsed,
                            'generalAdmitted' => $proof->general($context->codebase, $context->types, 'Fixture\\ArrayGeneral', $property) !== null,
                            'parsedEqualsNative' => $parsed !== null && $nativeArray?->type !== null && $context->types->equals($parsed, $nativeArray->type->type),
                            'nativeContainedByBroad' => $nativeArray?->type !== null && $context->types->isContainedBy($nativeArray->type->type, $broadArray->readType),
                            'sourceRepresentationVariants' => $variants];
                        file_put_contents($this->root.'/array-refinement-metadata.txt', var_export(['native' => $nativeArray, 'contract' => $contract, 'stages' => $stages], true));
                        throw new RuntimeException('Genuine array refinement did not admit '.$property);
                    }
                }
                $list = $arrayMetadata['labels']->type->type;
                $nullableList = \Mago\Sdk\Analyzer\Type::union($list, \Mago\Sdk\Analyzer\Type::null());
                $nullableActual = \Mago\Sdk\Analyzer\Type::union($broadArray->readType, \Mago\Sdk\Analyzer\Type::null());
                $arrayChecks['cast nullable retained'] = $context->types->equals($arrayContract('labels', $nullableActual)->readType, $nullableList);
                $arrayChecks['both nullable retained'] = $context->types->equals($arrayContract('docMaybe', $nullableActual)->readType, $nullableList);
                $arrayChecks['typed getter not refined'] = $context->types->equals($arrayContract('labels', $broadArray->readType, getter: true)->readType, $broadArray->readType);
                $narrowActual = \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ListType(\Mago\Sdk\Analyzer\Type::int(), null, null, false));
                $arrayChecks['non-builtin actual category not refined'] = $context->types->equals($arrayContract('labels', $narrowActual)->readType, $narrowActual);
                $arrayChecks['scalar alternative not discarded'] = $context->types->equals($arrayContract('incompatible')->readType, $broadArray->readType);
                $arrayChecks['unknown cast cannot certify detail'] = $context->types->equals($arrayContract('opaque')->readType, $broadArray->readType);
                $writeOnly = $context->codebase->getDeclaringMagicProperty('Fixture\\ArrayWriteOnly', '$labels');
                if ($writeOnly === null) { throw new RuntimeException('Missing genuine write-only array declaration.'); }
                $writeOnlyRead = $arrayContract('labels', class: 'ArrayWriteOnly')?->readType;
                $arrayChecks['write-only detail not used for reads'] = $writeOnlyRead === null || $context->types->equals($writeOnlyRead, $broadArray->readType);
                $arrayChecks['genuine list contained by native array'] = $context->types->isContainedBy($list, $broadArray->readType);
                $arrayChecks['native array not contained by documented list'] = !$context->types->isContainedBy($broadArray->readType, $list);
                if (in_array(false, $arrayChecks, true)) { file_put_contents($this->root.'/array-refinement-controls.json', json_encode($arrayChecks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); throw new RuntimeException('Array category or nullable control failed.'); }
                $context->codebase->getMagicProperty('Fixture\\ArrayGeneral', '$labels');
                $context->codebase->getMagicProperty('Fixture\\ArrayGeneral', '$shape');
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                $shape = $arrayMetadata['shape']->type->type->atomicTypes[0] ?? null;
                if (!$shape instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType || count($shape->knownItems ?? []) !== 2) { throw new RuntimeException('Missing genuine native keyed shape.'); }
                $shapeOptional = $shape->knownItems;
                $shapeOptional[0] = new \Mago\Sdk\Analyzer\Type\ArrayItem($shapeOptional[0]->key, !$shapeOptional[0]->optional, $shapeOptional[0]->type);
                $shapeUndefined = $shape->knownItems;
                $optionalIndex = null;
                foreach ($shapeUndefined as $index => $item) { if ($item->optional) { $optionalIndex = $index; break; } }
                if ($optionalIndex === null || !$shapeUndefined[$optionalIndex]->type->flags->possiblyUndefined) { throw new RuntimeException('Missing actual optional-member undefined flag.'); }
                $optional = $shapeUndefined[$optionalIndex];
                $undefinedType = $optional->type->withFlags(new \Mago\Sdk\Analyzer\Type\TypeFlags(...array_replace(get_object_vars($optional->type->flags), ['possiblyUndefined' => false])));
                $shapeUndefined[$optionalIndex] = new \Mago\Sdk\Analyzer\Type\ArrayItem($optional->key, true, $undefinedType);
                $arrayVariants = [
                    'changed list item' => ['labels', $narrowActual],
                    'changed list key category' => ['labels', \Mago\Sdk\Analyzer\Type::array(\Mago\Sdk\Analyzer\Type::string(), \Mago\Sdk\Analyzer\Type::string())],
                    'changed list nonempty flag' => ['labels', \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ListType(\Mago\Sdk\Analyzer\Type::string(), null, null, true))],
                    'changed shape optionality' => ['shape', \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\KeyedArrayType($shapeOptional, $shape->keyType, $shape->valueType, $shape->nonEmpty))],
                    'missing native optional-member undefined flag' => ['shape', \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\KeyedArrayType($shapeUndefined, $shape->keyType, $shape->valueType, $shape->nonEmpty))],
                ];
                foreach ($arrayVariants as $label => [$property, $replacementType]) {
                    $originalMetadata = $arrayMetadata[$property];
                    $originalType = $originalMetadata->type;
                    $replacement = new ($originalMetadata::class)(...array_replace(get_object_vars($originalMetadata), [
                        'type' => new ($originalType::class)(...array_replace(get_object_vars($originalType), ['type' => $replacementType])),
                    ]));
                    $replaced = 0;
                    foreach ($snapshot as $operation => $entries) { foreach ($entries as $key => $entry) {
                        if (is_object($entry) && $entry == $originalMetadata) { $cache->values[$operation][$key] = $replacement; ++$replaced; }
                    } }
                    try {
                        if ($replaced === 0) { throw new RuntimeException('Vacuous native array control: '.$label); }
                        $arrayChecks[$label] = $arrayContract($property) === null;
                        if (!$arrayChecks[$label]) { throw new RuntimeException('Changed native array detail admitted: '.$label); }
                    } finally { $cache->values = $snapshot; }
                }
                $arrayChecks['restored genuine list'] = $context->types->equals($arrayContract('labels')->readType, $list);
                file_put_contents($this->root.'/array-refinement-controls.json', json_encode($arrayChecks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                file_put_contents($this->root.'/array-refinement-metadata.txt', var_export(['properties' => $arrayMetadata, 'writeOnly' => $writeOnly, 'broadNativeCast' => $broadArray, 'checks' => $arrayChecks], true));
                if (in_array(false, $arrayChecks, true)) { throw new RuntimeException('Restored array refinement was not admitted.'); }
                $original = file_get_contents($this->root.'/models.php');
                $sourceChecks = [];
                $mapVariants = [
                    'changed cast key' => ["'amount' => 'decimal:2', 'maybe'", "'amouNt' => 'decimal:2', 'maybe'"],
                    'changed cast value' => ["'amount' => 'decimal:2', 'maybe'", "'amount' => 'decimal:3', 'maybe'"],
                    'integer cast key is not a string map' => ["'amount' => 'decimal:2', 'maybe'", "12345678 => 'decimal:2', 'maybe'"],
                    'integer cast value is not a cast contract' => ["'amount' => 'decimal:2', 'maybe'", "'amount' => 12345678901, 'maybe'"],
                    'mixed source directional write rejected' => ['int|float|string $separate', str_pad('mixed', strlen('int|float|string')).' $separate'],
                    'never source directional write rejected' => ['int|float|string $separate', str_pad('never', strlen('int|float|string')).' $separate'],
                    'unresolved source directional write rejected' => ['int|float|string $separate', 'UnresolvedAmount $separate'],
                    'changed array cast value' => ["'labels' => 'array', 'jsonLabels'", "'labels' => 'float', 'jsonLabels'", 'ArrayGeneral', 'labels'],
                    'changed array cast key' => ["'labels' => 'array', 'jsonLabels'", "'Labels' => 'array', 'jsonLabels'", 'ArrayGeneral', 'labels'],
                ];
                foreach ($mapVariants as $label => $variant) {
                    [$before, $after] = $variant;
                    if (strlen($before) !== strlen($after)) { throw new RuntimeException('Cast control changes source coordinates: '.$label); }
                    $changedSource = str_replace($before, $after, $original, $replaced);
                    if ($replaced !== 1) { throw new RuntimeException('Vacuous cast map source control: '.$label); }
                    file_put_contents($this->root.'/models.php', $changedSource);
                    try {
                        $sourceChecks[$label] = (new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts(new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root)))->general(
                            $context->codebase, $context->types, 'Fixture\\'.($variant[2] ?? 'GeneralEntry'),
                            $variant[3] ?? (str_contains($label, 'directional write') ? 'separate' : 'amount'),
                        ) === null;
                        file_put_contents($this->root.'/source-controls.json', json_encode($sourceChecks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                        if (!$sourceChecks[$label]) { throw new RuntimeException('Changed cast map admitted: '.$label); }
                    } finally { file_put_contents($this->root.'/models.php', $original); }
                }
                $nodes = (new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root))->read('models.php');
                $map = null;
                foreach ((new \PhpParser\NodeFinder)->findInstanceOf($nodes ?? [], \PhpParser\Node\Stmt\Class_::class) as $declaration) {
                    if (strcasecmp($declaration->namespacedName?->toString() ?? '', 'Fixture\\GeneralEntry') !== 0) { continue; }
                    foreach ($declaration->getProperties() as $field) { foreach ($field->props as $item) { if ($item->name->name === 'casts') { $map = $item->default; } } }
                }
                if (!$map instanceof \PhpParser\Node\Expr\Array_) { throw new RuntimeException('Missing genuine source casts map.'); }
                $nonMap = "'no-map'".str_repeat(' ', $map->getEndFilePos() + 1 - $map->getStartFilePos() - strlen("'no-map'"));
                file_put_contents($this->root.'/models.php', substr_replace($original, $nonMap, $map->getStartFilePos(), $map->getEndFilePos() + 1 - $map->getStartFilePos()));
                try {
                    $sourceChecks['casts scalar is not a map'] = (new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts(new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root)))->general($context->codebase, $context->types, 'Fixture\\GeneralEntry', 'amount') === null;
                    if (!$sourceChecks['casts scalar is not a map']) { throw new RuntimeException('Scalar casts declaration admitted.'); }
                } finally { file_put_contents($this->root.'/models.php', $original); }
                $sourceChecks['restored genuine casts map'] = $contracts->general($context->codebase, $context->types, 'Fixture\\GeneralEntry', 'amount') !== null;
                if (!$sourceChecks['restored genuine casts map']) { throw new RuntimeException('Restored casts map no longer admitted.'); }
                file_put_contents($this->root.'/source-controls.json', json_encode($sourceChecks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                file_put_contents($this->root.'/models.php', str_replace('@property float $amount', '@property bool $amount', $original));
                $changed = $contracts->current();
                $changedContract = $contracts->general($context->codebase, $context->types, 'Fixture\\GeneralEntry', 'amount');
                file_put_contents($this->root.'/models.php', str_replace('@property float $amount', '@property float $Amount', $original));
                $nameContract = (new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts(new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root)))->general($context->codebase, $context->types, 'Fixture\\GeneralEntry', 'amount');
                file_put_contents($this->root.'/models.php', $original);
                $domain = (new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelAttributeReadDomains($this->root))->resolve($context->codebase, 'Fixture\\StanGeneral', 'amount', '12.50', false, $context->types);
                $expectedDomain = \Ichinya\Laramago\Analyzer\StaticAnalysis\AttributeTypes::cast('decimal:2', $context->codebase)?->readType;
                file_put_contents($this->root.'/native-metadata.txt', var_export(['rows' => $rows, 'nativeControls' => $checks, 'changedSourceCurrent' => $changed, 'changedContract' => $changedContract, 'changedSourceNameContract' => $nameContract, 'restoredSourceCurrent' => $contracts->current(), 'generalDecimalReadDomain' => $domain], true));
                if ($changed || $changedContract !== null || $nameContract !== null || !$contracts->current()) { throw new RuntimeException('Source-current controls failed.'); }
                if ($domain === null || $expectedDomain === null || !$context->types->equals($domain, $expectedDomain)) { throw new RuntimeException('Independent schema-backed general decimal domain was not admitted.'); }
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/cast-reads', name: 'Cast read contracts', version: '1', analyzerPlugins: [$plugin])))->run();
PHP);
foreach (['framework.php', 'models.php', 'cases.php', 'context.php', 'worker.php', 'bootstrap/app.php', 'database/migrations/0001_entries.php'] as $file) {
    try { (new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse(file_get_contents($workspace.'/'.$file)); }
    catch (\PhpParser\Error $error) { throw new RuntimeException('Invalid cast fixture '.$file.': '.$error->getMessage().' '.$workspace); }
}
if (in_array('--fixtures-only', $argv, true)) {
    echo 'Cast read fixtures prepared without analyzer or source execution. Workspace: '.$workspace."\n";
    exit(0);
}
$observe = in_array('--observe', $argv, true);
$integrated = in_array('--integrated', $argv, true);
$run = static function (string $mode, int $workers = 1) use ($package, $workspace): array {
    $settings = ['php-version' => '8.5', 'source' => ['paths' => in_array($mode, ['observe', 'observe-native'], true) ? ['context.php'] : ['cases.php'], 'includes' => ['framework.php', 'models.php']], 'analyzer' => ['ignore' => []]];
    if (!in_array($mode, ['native', 'observe-native'], true)) {
        $command = $mode === 'integrated' ? [PHP_BINARY, '-d', 'opcache.enable_cli=0', $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]
            : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace, $mode];
        $settings['extension-hosts'] = ['fixture' => ['command' => $command, 'workers' => $workers, 'request-timeout-ms' => 120000]];
    }
    file_put_contents($workspace.'/mago-'.$mode.'-'.$workers.'.json', json_encode($settings, JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $process = proc_open([...$command, '--workspace', $workspace, '--config', $workspace.'/mago-'.$mode.'-'.$workers.'.json', 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'-'.$workers.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'-'.$workers.'.stderr', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start cast read analyzer.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$mode.'-'.$workers.'.stderr');
    if (!in_array($exit, [0, 1], true) || preg_match('/failed|fatal error|rejected request|pars(?:e|ing) errors?/i', $stderr)) { throw new RuntimeException('Cast read analyzer failed: '.$stderr.' '.$workspace); }
    file_put_contents($workspace.'/report-scopes.jsonl', json_encode(['mode' => $mode, 'workers' => $workers, 'exit' => $exit, 'paths' => $settings['source']['paths']], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    return json_decode(file_get_contents($workspace.'/'.$mode.'-'.$workers.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$signature = static function (array $issues): array {
    $rows = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues);
    sort($rows, SORT_STRING);
    return $rows;
};
$nativeContext = $run('observe-native');
$observed = $run('observe');
if ($signature($nativeContext) !== $signature($observed)) { throw new RuntimeException('Native cast declaration observer changed diagnostics: '.$workspace); }
if ($observe) {
    if (file_exists($workspace.'/executed') || file_exists($workspace.'/.env')) { throw new RuntimeException('Fixture bodies or environment were loaded.'); }
    echo 'Native cast declaration observation completed with unchanged diagnostics; metadata: '.$workspace.'/native-metadata.txt'."\n";
    exit(0);
}
$native = $run('native');
$isolated = $run('isolated');
$byCase = static function (array $issues, int $line): array {
    return array_values(array_filter($issues, static function (array $issue) use ($line): bool {
        if ($issue['level'] !== 'Error') { return false; }
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'cases.php' && $annotation['span']['start']['line'] + 1 === $line) { return true; }
        }
        return false;
    }));
};
foreach ($ranges as &$case) {
    if (!($case['expectedRefinement']['nativeCodeOracle'] ?? false)) { continue; }
    $nativeErrors = $byCase($native, $case['line']);
    if ($nativeErrors === []) { throw new RuntimeException('Native nonnull assignment witness is missing. '.$workspace); }
    foreach ($nativeErrors as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'));
        if (count($primary) !== 1 || $primary[0]['span']['file_id']['name'] !== 'cases.php'
            || $primary[0]['span']['start']['offset'] !== $case['expectedRefinement']['start']
            || $primary[0]['span']['end']['offset'] !== $case['expectedRefinement']['end']) {
            throw new RuntimeException('Native nonnull assignment witness primary differs. '.$workspace);
        }
    }
    $case['expectedRefinement']['codes'] = array_column($nativeErrors, 'code');
}
unset($case);
file_put_contents($workspace.'/case-matrix.json', json_encode($ranges, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$results = [];
$preserved = [
    'duplicate write remains native', 'write alias remains native', 'mixed write remains native', 'never write remains native', 'different owner write remains native',
    'physical property authoritative', 'explicit read authoritative', 'phpstan read with merged write', 'psalm read with merged write',
    'inherited strong read', 'trait strong read', 'dynamic casts defer', 'custom dispatch defer', 'duplicate general defers',
    'unresolved named general defers', 'typo remains an error', 'property names are case sensitive', 'collection is not a model'];
$preserved = [...$preserved, 'unknown array cast retains native declaration', 'wrong array write element rejected',
    'wrong list element rejected', 'wrong keyed array element rejected', 'wrong shape key rejected',
    'wrong nullable element rejected', 'explicit array wrong item rejected', 'physical array wrong item rejected'];
$preserved = [...$preserved, 'decimal directional float write retained', 'decimal phpstan float write retained',
    'decimal psalm float write retained', 'decimal write-only float retained', 'decimal ancestor directional write retained',
    'decimal stronger numeric union write retained', 'decimal physical float write retained', 'decimal unknown cast write retained'];
$checkRefinement = static function (array $errors, array $case) use ($workspace): void {
    if (!isset($case['expectedRefinement'])) { return; }
    $expected = $case['expectedRefinement'];
    $actualCodes = array_column($errors, 'code');
    $expectedCodes = $expected['codes'];
    sort($actualCodes, SORT_STRING);
    sort($expectedCodes, SORT_STRING);
    if ($actualCodes !== $expectedCodes) { throw new RuntimeException('Refined Error code multiset differs: '.$case['label'].' '.$workspace); }
    foreach ($errors as $issue) {
        $found = false;
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'cases.php'
                && $annotation['span']['start']['offset'] === $expected['start'] && $annotation['span']['end']['offset'] === $expected['end']) { $found = true; break; }
        }
        if (!$found) { throw new RuntimeException('Expected refined Error primary span missing: '.$case['label'].' '.json_encode($expected, JSON_THROW_ON_ERROR).' '.$workspace); }
    }
};
foreach ($ranges as $name => $case) {
    $errors = $byCase($isolated, $case['line']);
    $results[$name] = ['label' => $case['label'], 'nativeErrors' => $byCase($native, $case['line']), 'errors' => $errors];
    if (($errors !== []) !== $case['error']) { file_put_contents($workspace.'/case-results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); throw new RuntimeException('Unexpected cast read case: '.$case['label'].' '.$workspace); }
    $checkRefinement($errors, $case);
    if (in_array($case['label'], $preserved, true) && $signature($byCase($native, $case['line'])) !== $signature($errors)) {
        throw new RuntimeException('An authoritative native Error signature changed: '.$case['label'].' '.$workspace);
    }
}
file_put_contents($workspace.'/case-results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$nativeSignatures = $signature($native);
$isolatedSignatures = $signature($isolated);
$delta = static function (array $before, array $after): array {
    $removed = $added = [];
    foreach (array_unique(array_merge($before, $after)) as $row) {
        $count = count(array_keys($before, $row, true)) - count(array_keys($after, $row, true));
        for ($index = 0; $index < abs($count); $index++) { if ($count > 0) { $removed[] = json_decode($row, true, flags: JSON_THROW_ON_ERROR); } else { $added[] = json_decode($row, true, flags: JSON_THROW_ON_ERROR); } }
    }
    return ['removed' => $removed, 'added' => $added];
};
file_put_contents($workspace.'/native-isolated-delta.json', json_encode($delta($nativeSignatures, $isolatedSignatures), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
if ($integrated) {
    $fullOne = null;
    foreach ([1, 3] as $workers) {
        $full = $run('integrated', $workers);
        foreach ($ranges as $case) {
            if (($byCase($full, $case['line']) !== []) !== $case['error']) { throw new RuntimeException('Integrated cast case differs: '.$case['label'].' '.$workspace); }
            $checkRefinement($byCase($full, $case['line']), $case);
            if (in_array($case['label'], $preserved, true) && $signature($byCase($native, $case['line'])) !== $signature($byCase($full, $case['line']))) {
                throw new RuntimeException('Integrated authoritative native Error changed: '.$case['label'].' '.$workspace);
            }
        }
        if ($workers === 1) { $fullOne = $signature($full); }
        elseif ($fullOne !== $signature($full)) { throw new RuntimeException('Full one/three worker issue signatures differ: '.$workspace); }
    }
    file_put_contents($workspace.'/isolated-integrated-delta.json', json_encode($delta($isolatedSignatures, $fullOne), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
if (file_exists($workspace.'/executed') || file_exists($workspace.'/.env')) { throw new RuntimeException('Fixture bodies or environment were loaded.'); }
echo 'Cast read contracts passed '.count($ranges).' source cases; workspace: '.$workspace."\n";
