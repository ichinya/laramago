<?php

declare(strict_types=1);

$package = str_replace('\\', '/', getenv('LARAMAGO_TEST_PACKAGE_ROOT') ?: dirname(__DIR__));
require $package.'/vendor/autoload.php';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago factory declarations '.bin2hex(random_bytes(8));
foreach (['cases', 'database/migrations', 'packages/composer'] as $directory) { mkdir($workspace.'/'.$directory, recursive: true); }
file_put_contents($workspace.'/contracts.php', '<?php
namespace Illuminate\\Database\\Eloquent;
file_put_contents(__DIR__.\'/declaration-executed\', \'Declaration bodies must never execute.\');
class Model {}
namespace FactoryDocFixture;
class Invoice extends \\Illuminate\\Database\\Eloquent\\Model {}
class Receipt extends \\Illuminate\\Database\\Eloquent\\Model {}
class Foreign {}
trait ParentNestedTrait { public function inheritedLabel(): string { return \'factory\'; } }
trait ParentTrait { use ParentNestedTrait; public function inheritedMutation(): void { $this->model = \'mutable parent\'; } }
namespace Illuminate\\Database\\Eloquent\\Factories;
/** @template TModel of \\Illuminate\\Database\\Eloquent\\Model */
abstract class Factory {
    use \\FactoryDocFixture\\ParentTrait { inheritedLabel as protected inheritedAlias; }
    /** @var class-string<TModel> */
    protected $model;
    /** @return class-string<TModel> */
    public function modelName() { return $this->model; }
}');
$cases = array (
  'literal own factory model' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => true,
  ),
  'own benign trait with inherited trait closure' => 
  array (
    'source' => 'trait LabelTrait { public function label(): string { return "example"; } } /** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { use LabelTrait; /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => true,
  ),
  'own nested benign trait with inherited trait closure' => 
  array (
    'source' => 'trait LeafTrait { public function label(): string { return "example"; } } trait LabelTrait { use LeafTrait; } /** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { use LabelTrait; /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => true,
  ),
  'own trait model storage shadow' => 
  array (
    'source' => 'trait ShadowTrait { /** @var string */ protected $model = "wrong"; } /** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { use ShadowTrait; /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'own trait method alias' => 
  array (
    'source' => 'trait LabelTrait { public function label(): string { return "example"; } } /** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { use LabelTrait { label as alternate; } /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'own nested trait model write' => 
  array (
    'source' => 'trait LeafTrait { public function replace(): void { $this->model = "wrong"; } } trait ModelReplacement { use LeafTrait; } /** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { use ModelReplacement; /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'own trait reference exposure' => 
  array (
    'source' => 'trait ReferenceTrait { public function &expose(): string { return $this->model; } } /** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { use ReferenceTrait; /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'fully qualified imported factory model' => 
  array (
    'source' => 'use Illuminate\\Database\\Eloquent\\Factories\\Factory as Factory; use FactoryDocFixture\\Invoice as Row; /** @extends Factory<Row> */ class Example extends Factory { /** @var string */ protected $model = Row::class; }',
    'candidate' => true,
  ),
  'innocent definition body and residual Error' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; public function definition(): array { return []; } public function unsafe(): void { $this->missing(); } }',
    'candidate' => true,
  ),
  'wrong literal model' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Receipt::class; }',
    'candidate' => false,
  ),
  'missing generic' => 
  array (
    'source' => 'class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'different generic model' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Receipt> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'nonmodel generic and default' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Foreign> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Foreign::class; }',
    'candidate' => false,
  ),
  'unknown model class' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<UnknownModel> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = UnknownModel::class; }',
    'candidate' => false,
  ),
  'native string property contract' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected string $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'narrow scalar doc' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var non-empty-string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'nullable scalar doc' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string|null */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'wrong class-string doc' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var class-string<\\FactoryDocFixture\\Receipt> */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'general string dynamic default' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = "dynamic class"; }',
    'candidate' => false,
  ),
  'general string null default' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = null; }',
    'candidate' => false,
  ),
  'multiple fields single declaration' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class, $other = "value"; }',
    'candidate' => false,
  ),
  'constructor model mutation' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; public function __construct() { $this->model = \\FactoryDocFixture\\Receipt::class; } }',
    'candidate' => false,
  ),
  'method model mutation' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; public function replace(): void { $this->model = \\FactoryDocFixture\\Receipt::class; } }',
    'candidate' => false,
  ),
  'reference model exposure' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; public function &expose(): string { return $this->model; } }',
    'candidate' => false,
  ),
  'reference model alias' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; public function replace(): void { $alias =& $this->model; $alias = "wrong"; } }',
    'candidate' => false,
  ),
  'dynamic field mutation' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; public function replace(string $name): void { $this->{$name} = "wrong"; } }',
    'candidate' => false,
  ),
  'dynamic receiver alias mutation' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; public function replace(): void { $alias = $this; $alias->model = "wrong"; } }',
    'candidate' => false,
  ),
  'custom trait mutation' => 
  array (
    'source' => 'trait ModelReplacement { public function replace(): void { $this->model = "wrong"; } } /** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { use ModelReplacement; /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
  'known descendant mutation' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Example extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; } class Derived extends Example { public function replace(): void { $this->model = "wrong"; } }',
    'candidate' => false,
  ),
  'intermediate factory inheritance' => 
  array (
    'source' => '/** @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\FactoryDocFixture\\Invoice> */ class Middle extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory {} /** @extends Middle<\\FactoryDocFixture\\Invoice> */ class Example extends Middle { /** @var string */ protected $model = \\FactoryDocFixture\\Invoice::class; }',
    'candidate' => false,
  ),
); $files = [];
foreach ($cases as $label => $case) {
    $file = 'cases/case'.count($files).'.php';
    $bytes = "<?php\nnamespace FactoryDeclarationCase".count($files).";\n".$case['source']."\nfunction independentUnsafe(): void { (new \\Illuminate\\Database\\Eloquent\\Model)->missing(); }\n"
        .'file_put_contents(__DIR__."/case-executed", "Analyzed declaration body must never execute.");'."\n";
    file_put_contents($workspace.'/'.$file, $bytes); $files[$file] = ['label' => $label, 'correct' => $case['candidate']];
}
file_put_contents($workspace.'/focus.php', <<<'PHP'
<?php
namespace FactoryDeclarationFocus;
use Illuminate\Database\Eloquent\Factories\Factory as Factory;
use FactoryDocFixture\Invoice as Model;
/** @extends Factory<Model> */
class Example extends Factory {
    /** @var string */
    protected $model = Model::class;
    public function unsafe(): void { $this->missing(); }
}
file_put_contents(__DIR__.'/focus-executed', 'Analyzed factory bodies must never execute.');
PHP);
$trap = '<?php file_put_contents(__DIR__."/executed", "executed"); throw new RuntimeException("Source bodies must never execute.");';
file_put_contents($workspace.'/bootstrap.php', $trap); file_put_contents($workspace.'/database/migrations/001_trap.php', $trap);
file_put_contents($workspace.'/packages/composer/trap.php', $trap);
file_put_contents($workspace.'/packages/composer/autoload_files.php', '<?php return ["trap" => __DIR__."/trap.php"];');
file_put_contents($workspace.'/packages/composer/installed.json', '{"packages":[]}');
file_put_contents($workspace.'/composer.json', '{"config":{"vendor-dir":"packages"},"autoload":{"files":["bootstrap.php"]}}');
$header = '<?php require '.var_export($package.'/vendor/autoload.php', true).';';
file_put_contents($workspace.'/controls.php', '<?php

declare(strict_types=1);

namespace Ichinya\\Laramago\\Analyzer;

use Mago\\Sdk\\Analyzer\\IssueFilterContext;
use Mago\\Sdk\\Analyzer\\IssueFilterDecision;
use Mago\\Sdk\\Analyzer\\IssueFilterHook;
use Mago\\Sdk\\Analyzer\\Metadata\\ClassLikeKind;
use Mago\\Sdk\\Analyzer\\Metadata\\MetadataFlags;
use Mago\\Sdk\\Analyzer\\Plugin;
use Mago\\Sdk\\Analyzer\\PluginDefinition;
use Mago\\Sdk\\Analyzer\\PluginRegistry;
use Mago\\Sdk\\Analyzer\\Type;
use Mago\\Sdk\\Analyzer\\Type\\Variance;
use Mago\\Sdk\\Analyzer\\Type\\Visibility;
use Mago\\Sdk\\Reporting\\Annotation;
use Mago\\Sdk\\Reporting\\AnnotationKind;
use Mago\\Sdk\\Reporting\\Level;
use Mago\\Sdk\\Reporting\\ReportedIssue;
use Mago\\Sdk\\Reporting\\TextEdit;
use Mago\\Sdk\\SourceLocation;
use Mago\\Sdk\\Span;

/** Actual single-file native cache mutations, never a simulated positive semantic context. */
final class FactoryModelDeclarationControls implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition(\'draft/factory-declaration-native-controls\', \'Factory declaration controls\', \'Genuine source/native/envelope mutation controls\'); }
    public function register(PluginRegistry $registry): void
    {
        $registry->registerIssueFilterHook(new class($this->root) implements IssueFilterHook {
            private bool $checked = false;
            private FactoryModelDeclarationIssueFilter $filter;
            public function __construct(private readonly string $root) { $this->filter = new FactoryModelDeclarationIssueFilter($root, \'Illuminate\\\\Database\\\\Eloquent\\\\Factories\\\\Factory\', \'Illuminate\\\\Database\\\\Eloquent\\\\Model\'); }
            public function getCodes(): array { return [\'incompatible-property-type\']; }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $result = $this->filter->filterIssue($context);
                if ($this->checked || basename($context->file) !== \'focus.php\') { return $result; }
                $class = $context->codebase->getClass(\'FactoryDeclarationFocus\\\\Example\'); $parent = $context->codebase->getClass(\'Illuminate\\\\Database\\\\Eloquent\\\\Factories\\\\Factory\');
                $model = $context->codebase->getClass(\'FactoryDocFixture\\\\Invoice\'); $base = $context->codebase->getClass(\'Illuminate\\\\Database\\\\Eloquent\\\\Model\');
                $field = $context->codebase->getProperty(\'FactoryDeclarationFocus\\\\Example\', \'$model\');
                $parentField = $context->codebase->getProperty(\'Illuminate\\\\Database\\\\Eloquent\\\\Factories\\\\Factory\', \'$model\');
                $cache = (new \\ReflectionProperty($context->codebase, \'cache\'))->getValue($context->codebase);
                file_put_contents($this->root.\'/native-declaration-metadata.txt\', var_export([\'issue\' => $context->issue, \'class\' => $class, \'parent\' => $parent,
                    \'model\' => $model, \'base\' => $base, \'field\' => $field, \'parentField\' => $parentField, \'values\' => $cache->values, \'relations\' => $cache->relations], true));
                file_put_contents($this->root.\'/native-declaration-stages.json\', json_encode($this->filter->proof->stages, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $checks = [];
                $expect = function (string $label, IssueFilterContext $input, bool $remove) use (&$checks): void {
                    $actual = $this->filter->filterIssue($input) === IssueFilterDecision::Remove;
                    if ($actual !== $remove) {
                        file_put_contents($this->root.\'/native-declaration-first-failure.json\', json_encode([\'label\' => $label, \'actualRemove\' => $actual,
                            \'expectedRemove\' => $remove, \'stages\' => $this->filter->proof->stages, \'passed\' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                        throw new \\RuntimeException(\'Factory declaration native control: \'.$label);
                    }
                    $checks[$label] = true; file_put_contents($this->root.\'/native-declaration-progress.json\', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                };
                $expect(\'genuine declaration eligibility before mutations\', $context, true);
                if ($class === null || $parent === null || $model === null || $base === null || $field === null || $parentField === null) { throw new \\RuntimeException(\'Missing genuine native declaration metadata.\'); }
                $this->checked = true;
                $issue = $context->issue; [$primary, $secondary] = $issue->annotations;
                $alter = static fn (array $changes): ReportedIssue => new ReportedIssue(...array_replace(get_object_vars($issue), $changes));
                $with = static fn (ReportedIssue $report, ?string $bytes = null, ?string $file = null): IssueFilterContext => new IssueFilterContext(
                    $context->phpVersion, $context->codebase, $context->types, $context->cancellation, $file ?? $context->file, $bytes ?? $context->contents, $report);
                $annotation = static fn (object $original, array $changes): Annotation => new Annotation(...array_replace(get_object_vars($original), $changes));
                $envelopes = [\'Warning severity\' => [\'level\' => Level::Warning], \'different code\' => [\'code\' => \'invalid-property-default-value\'],
                    \'physical PHP invariant message\' => [\'message\' => str_replace(\'from docblock.\', \'from PHP.\', $issue->message)],
                    \'foreign child class\' => [\'message\' => str_replace(\'FactoryDeclarationFocus\\\\Example\', \'Foreign\\\\Example\', $issue->message)],
                    \'missing notes\' => [\'notes\' => []], \'additional note\' => [\'notes\' => [...$issue->notes, \'Extra note.\']],
                    \'unknown help\' => [\'help\' => null], \'stronger generic in help\' => [\'help\' => str_replace(\'Invoice>\', \'Receipt>\', $issue->help)],
                    \'foreign link\' => [\'link\' => \'https://example.invalid\'], \'edit\' => [\'edits\' => [TextEdit::delete($primary->span)]],
                    \'missing annotations\' => [\'annotations\' => []], \'extra annotation\' => [\'annotations\' => [$primary, $secondary, $primary]],
                    \'reversed annotations\' => [\'annotations\' => [$secondary, $primary]],
                    \'nearby Primary\' => [\'annotations\' => [$annotation($primary, [\'span\' => new Span($primary->span->start + 1, $primary->span->end)]), $secondary]],
                    \'foreign Primary file\' => [\'annotations\' => [$annotation($primary, [\'file\' => \'foreign.php\']), $secondary]],
                    \'narrower Primary type\' => [\'annotations\' => [$annotation($primary, [\'message\' => "This type `non-empty-string` is incompatible with the parent\'s type."]), $secondary]],
                    \'nearby parent Secondary\' => [\'annotations\' => [$primary, $annotation($secondary, [\'span\' => new Span($secondary->span->start + 1, $secondary->span->end)])]],
                    \'missing parent source file\' => [\'annotations\' => [$primary, $annotation($secondary, [\'file\' => null])]],
                    \'foreign parent source file\' => [\'annotations\' => [$primary, $annotation($secondary, [\'file\' => \'foreign.php\'])]],
                    \'different Secondary specialization\' => [\'annotations\' => [$primary, $annotation($secondary, [\'message\' => str_replace(\'Invoice>\', \'Receipt>\', $secondary->message)])]],
                ];
                foreach ($envelopes as $label => $changes) { $expect($label, $with($alter($changes)), false); }
                foreach ([\'stale analyzed bytes\' => $context->contents.\' \', \'malformed analyzed source\' => \'<?php class broken {\', \'oversized analyzed source\' => $context->contents.str_repeat(\' \', 1024 * 1024)] as $label => $bytes) { $expect($label, $with($issue, $bytes), false); }
                $expect(\'foreign current source path\', $with($issue, file: \'foreign.php\'), false);
                $copy = static function (object $original, array $changes): object { $class = $original::class; return new $class(...array_replace(get_object_vars($original), $changes)); };
                $shift = static fn (SourceLocation $location): SourceLocation => new SourceLocation($location->file, new Span($location->span->start + 1, $location->span->end));
                $values = $cache->values; $relations = $cache->relations; $mutations = [];
                foreach ([\'child\' => $class, \'parent\' => $parent, \'default model\' => $model, \'model base\' => $base] as $label => $metadata) {
                    foreach ([\'missing\' => null, \'kind\' => $copy($metadata, [\'kind\' => ClassLikeKind::Interface]),
                        \'incomplete\' => $copy($metadata, [\'unresolvedHierarchyDependencies\' => [\'UnknownParent\']]),
                        \'nearby class scope\' => $copy($metadata, [\'location\' => $shift($metadata->location)]),
                        \'nearby native class name\' => $copy($metadata, [\'nameLocation\' => $shift($metadata->nameLocation)]),
                        \'wrong direct parent\' => $copy($metadata, [\'directParentClass\' => \'Unknown\\\\Parent\']),
                        \'builtin class\' => $copy($metadata, [\'flags\' => new MetadataFlags($metadata->flags->bits | MetadataFlags::BUILTIN)]),
                        \'readonly class\' => $copy($metadata, [\'flags\' => new MetadataFlags($metadata->flags->bits | MetadataFlags::READONLY)])] as $change => $replacement) {
                        $mutations[\'native \'.$label.\' \'.$change] = [$metadata, $replacement];
                    }
                }
                $mutations[\'native child extra mixin\'] = [$class, $copy($class, [\'mixins\' => [Type::namedObject(\'stdClass\')]])];
                $mutations[\'native child unresolved trait\'] = [$class, $copy($class, [\'usedTraits\' => [\'Unknown\\\\TraitName\']])];
                $mutations[\'native child lost inherited traits\'] = [$class, $copy($class, [\'usedTraits\' => []])];
                $mutations[\'native parent lost source-owned traits\'] = [$parent, $copy($parent, [\'usedTraits\' => []])];
                foreach ([\'FactoryDocFixture\\\\ParentTrait\', \'FactoryDocFixture\\\\ParentNestedTrait\'] as $traitName) {
                    $trait = $context->codebase->getTrait($traitName);
                    if ($trait === null) { throw new \\RuntimeException(\'Missing genuine inherited trait metadata.\'); }
                    foreach ([\'missing\' => null, \'wrong kind\' => $copy($trait, [\'kind\' => ClassLikeKind::Class_]),
                        \'incomplete\' => $copy($trait, [\'unresolvedHierarchyDependencies\' => [\'UnknownTrait\']]),
                        \'nearby source scope\' => $copy($trait, [\'location\' => $shift($trait->location)]),
                        \'nearby source name\' => $copy($trait, [\'nameLocation\' => $shift($trait->nameLocation)]),
                        \'unexpected native closure\' => $copy($trait, [\'usedTraits\' => [\'UnknownTrait\']])] as $change => $replacement) {
                        $mutations[\'native inherited trait \'.$traitName.\' \'.$change] = [$trait, $replacement];
                    }
                }
                // Snapshot after querying the genuine trait slots used by the proof.
                $values = $cache->values; $relations = $cache->relations;
                $template = $parent->templates[0];
                foreach ([\'missing\' => [], \'wrong name\' => [$copy($template, [\'name\' => \'TOther\'])], \'wrong constraint\' => [$copy($template, [\'constraint\' => Type::string()])],
                    \'wrong variance\' => [$copy($template, [\'variance\' => Variance::Covariant])], \'invented default\' => [$copy($template, [\'default\' => Type::namedObject(\'FactoryDocFixture\\\\Invoice\')])]] as $label => $templates) {
                    $mutations[\'native parent template \'.$label] = [$parent, $copy($parent, [\'templates\' => $templates])];
                }
                foreach ([\'child\' => $field, \'parent\' => $parentField] as $label => $property) {
                    foreach ([\'missing\' => null, \'wrong name\' => $copy($property, [\'name\' => \'$other\']), \'physical PHP type\' => $copy($property, [\'declaredType\' => $property->type]),
                        \'different write type\' => $copy($property, [\'writeType\' => $property->type]), \'typed native location\' => $copy($property, [\'location\' => $property->nameLocation]),
                        \'public read\' => $copy($property, [\'readVisibility\' => Visibility::Public]), \'public write\' => $copy($property, [\'writeVisibility\' => Visibility::Public]),
                        \'nearby native name\' => $copy($property, [\'nameLocation\' => $shift($property->nameLocation)]),
                        \'missing doc type\' => $copy($property, [\'type\' => null]), \'wrong doc type\' => $copy($property, [\'type\' => $copy($property->type, [\'type\' => Type::int()])]),
                        \'not docblock\' => $copy($property, [\'type\' => $copy($property->type, [\'fromDocblock\' => false])]),
                        \'inferred doc type\' => $copy($property, [\'type\' => $copy($property->type, [\'inferred\' => true])]),
                        \'nearby source doc token\' => $copy($property, [\'type\' => $copy($property->type, [\'location\' => $shift($property->type->location)])])] as $change => $replacement) {
                        $mutations[\'native \'.$label.\' field \'.$change] = [$property, $replacement];
                    }
                    foreach ([\'virtual\' => MetadataFlags::VIRTUAL_PROPERTY, \'static\' => MetadataFlags::STATIC, \'reference\' => MetadataFlags::BY_REFERENCE,
                        \'readonly\' => MetadataFlags::READONLY, \'asymmetric\' => MetadataFlags::ASYMMETRIC_PROPERTY, \'writeonly\' => MetadataFlags::WRITEONLY] as $change => $flag) {
                        $mutations[\'native \'.$label.\' field \'.$change] = [$property, $copy($property, [\'flags\' => new MetadataFlags($property->flags->bits | $flag)])];
                    }
                }
                $wrongClass = Type::fromAtomic(new \\Mago\\Sdk\\Analyzer\\Type\\ScalarType(\\Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind::ClassLikeString,
                    new \\Mago\\Sdk\\Analyzer\\Type\\ClassLikeStringType(\\Mago\\Sdk\\Analyzer\\Type\\ClassLikeStringVariant::Literal, \\Mago\\Sdk\\Analyzer\\Type\\ClassLikeStringKind::Class_, \'FactoryDocFixture\\\\Receipt\')));
                foreach ([\'missing\' => null, \'wrong model default\' => $copy($field->defaultType, [\'type\' => $wrongClass]),
                    \'ordinary string\' => $copy($field->defaultType, [\'type\' => Type::string()]), \'docblock provenance\' => $copy($field->defaultType, [\'fromDocblock\' => true]),
                    \'not expression inferred\' => $copy($field->defaultType, [\'inferred\' => false]), \'nearby source constant\' => $copy($field->defaultType, [\'location\' => $shift($field->defaultType->location)])] as $label => $default) {
                    $mutations[\'native child default \'.$label] = [$field, $copy($field, [\'defaultType\' => $default])];
                }
                foreach ($mutations as $label => [$original, $replacement]) {
                    $replaced = 0;
                    foreach ($values as $operation => $entries) { foreach ($entries as $key => $entry) { if ($entry === $original) { $cache->values[$operation][$key] = $replacement; $replaced++; } } }
                    try { if ($replaced === 0) { throw new \\RuntimeException(\'Vacuous native cache mutation: \'.$label); } $expect($label, $context, false); }
                    finally { $cache->values = $values; $cache->relations = $relations; }
                }
                $relation = \\Mago\\Sdk\\Internal\\Analyzer\\Protocol::ALL_DESCENDANTS; $key = strtolower($class->name);
                if (! array_key_exists($key, $relations[$relation] ?? [])) { throw new \\RuntimeException(\'Missing actual queried descendant cache.\'); }
                try { $cache->relations[$relation][$key] = [\'Unknown\\\\FactoryDescendant\']; $expect(\'unknown known descendant\', $context, false); }
                finally { $cache->relations = $relations; }
                $disk = $this->filter->proof->path($context->file); $original = file_get_contents($disk);
                try { file_put_contents($disk, $original.\' \'); $expect(\'current caller disk differs from native snapshot\', $context, false); }
                finally { file_put_contents($disk, $original); }
                foreach ([\'current default literal changed\' => [\'Model::class\', \'Other::class\'], \'current general var doc changed\' => [\'@var string\', \'@var mixed \'],
                    \'current generic source changed\' => [\'Factory<Model>\', \'Factory<Other>\']] as $label => [$find, $replacement]) {
                    if (substr_count($original, $find) !== 1 || strlen($find) !== strlen($replacement)) { throw new \\RuntimeException(\'Invalid same-length current source mutation.\'); }
                    $mutant = str_replace($find, $replacement, $original);
                    try { file_put_contents($disk, $mutant); $expect($label, $with($issue, $mutant), false); }
                    finally { file_put_contents($disk, $original); }
                }
                $cancelled = new class implements \\Mago\\Sdk\\CancellationTokenInterface {
                    public function isCancelled(): bool { return true; }
                    public function throwIfCancelled(): void { throw new \\Mago\\Sdk\\Exception\\CancelledException; }
                    public function subscribe(\\Closure $callback): int { return 0; }
                    public function unsubscribe(int $subscription): void {}
                };
                $expect(\'cancelled native context\', new IssueFilterContext($context->phpVersion, $context->codebase, $context->types,
                    $cancelled, $context->file, $context->contents, $issue), false);
                $expect(\'native current source/cache restored\', $context, true);
                file_put_contents($this->root.\'/native-declaration-controls.json\', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                return $result;
            }
        });
    }
}
');
file_put_contents($workspace.'/worker.php', $header.' (new Mago\\Sdk\\Worker(new Mago\\Sdk\\Extension("fixture/factory-declaration", "Factory declaration compatibility", "1", analyzerPlugins: [new Ichinya\\Laramago\\Analyzer\\FactoryModelDeclarationPlugin('.var_export($workspace, true).', "Illuminate\\\\Database\\\\Eloquent\\\\Factories\\\\Factory", "Illuminate\\\\Database\\\\Eloquent\\\\Model")])))->run();');
file_put_contents($workspace.'/guard-worker.php', $header.' require '.var_export($workspace.'/controls.php', true).';'
    .' (new Mago\\Sdk\\Worker(new Mago\\Sdk\\Extension("fixture/factory-declaration-controls", "Factory declaration controls", "1", analyzerPlugins: [new Ichinya\\Laramago\\Analyzer\\FactoryModelDeclarationControls('.var_export($workspace, true).')])))->run();');
$config = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5', 'source' => ['paths' => ['cases', 'focus.php'], 'includes' => ['contracts.php']]];
file_put_contents($workspace.'/native-config.json', json_encode($config, JSON_THROW_ON_ERROR));
$config['extension-hosts'] = ['factory-declaration' => ['command' => [PHP_BINARY, $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace], 'workers' => 1]];
file_put_contents($workspace.'/isolated-config.json', json_encode($config, JSON_THROW_ON_ERROR));
$config['extension-hosts']['factory-declaration']['workers'] = 3;
file_put_contents($workspace.'/isolated-three-config.json', json_encode($config, JSON_THROW_ON_ERROR));
$config['extension-hosts']['factory-declaration']['workers'] = 1; $config['source']['paths'] = ['focus.php'];
file_put_contents($workspace.'/single-isolated-config.json', json_encode($config, JSON_THROW_ON_ERROR));
$config['extension-hosts']['factory-declaration']['command'][1] = $workspace.'/guard-worker.php';
file_put_contents($workspace.'/single-guard-config.json', json_encode($config, JSON_THROW_ON_ERROR));
unset($config['extension-hosts']); file_put_contents($workspace.'/single-native-config.json', json_encode($config, JSON_THROW_ON_ERROR));
$parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion(); $receipts = [];
foreach ([...array_keys($files), 'contracts.php', 'focus.php', 'worker.php', 'guard-worker.php', 'controls.php', 'bootstrap.php', 'database/migrations/001_trap.php', 'packages/composer/trap.php', 'packages/composer/autoload_files.php'] as $file) {
    $bytes = file_get_contents($workspace.'/'.$file); $parser->parse($bytes); $receipts[$file] = ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
}
$traps = [$workspace.'/executed', $workspace.'/database/migrations/executed', $workspace.'/packages/composer/executed', $workspace.'/declaration-executed', $workspace.'/cases/case-executed', $workspace.'/focus-executed'];
foreach ($traps as $marker) { if (file_exists($marker)) { throw new RuntimeException('Source body executed.'); } }
if (file_exists($workspace.'/.env')) { throw new RuntimeException('Unexpected environment.'); }
file_put_contents($workspace.'/source-readiness.json', json_encode(['sourceOnly' => true, 'analyzerJobsStarted' => 0,
    'root' => $workspace, 'cases' => $files, 'files' => $receipts, 'noPrivateFixtureSource' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Prepared '.count($files).' invented declaration cases and '.count($receipts)." parsed files; no analyzer or application bodies.\n";

// Parent owns analyzer execution. Every revision uses a fresh fixture/report root.
if (in_array('--fixtures-only', $argv, true)) { exit(0); }
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (string $label, string $config) use ($workspace, $command): array {
    copy($workspace.'/'.$config, $workspace.'/mago.json');
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$label.'.json', 'w'], 2 => ['file', $workspace.'/'.$label.'.stderr.log', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start declaration native gate: '.$label); }
    fclose($pipes[0]); $exit = proc_close($process); $stderr = file_get_contents($workspace.'/'.$label.'.stderr.log');
    file_put_contents($workspace.'/'.$label.'.process.json', json_encode(['label' => $label, 'exit' => $exit, 'processClosed' => true], JSON_THROW_ON_ERROR));
    if (! in_array($exit, [0, 1], true) || preg_match('/provider[^\r\n]*failed|rejected request|protocol error|panicked|fallback|falling back|invalid[^\r\n]*extension[^\r\n]*frame|hook[^\r\n]*failed|fatal|orchestrat(?:or|ion)[^\r\n]*error|extension[^\r\n]*error|worker[^\r\n]*error|timed? out|timeout|parse error|PHP Warning/i', $stderr) === 1) {
        throw new RuntimeException('Declaration gate failed; original report/stderr retained: '.$label);
    }
    $report = json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! isset($report['issues']) || ! is_array($report['issues'])) { throw new RuntimeException('Malformed declaration native report: '.$label); }
    return $report['issues'];
};
$signature = static function (array $issues): array { $rows = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues); sort($rows); return $rows; };
$fileOf = static function (array $issue): string {
    foreach ($issue['annotations'] as $annotation) { if ($annotation['kind'] === 'Primary') { return str_replace('\\', '/', $annotation['span']['file_id']['name']); } }
    throw new RuntimeException('Missing native Primary.');
};
$native = $run('native-declarations', 'native-config.json');
$isolated = $run('isolated-declarations', 'isolated-config.json');
$counts = []; $remove = [];
foreach ($files as $file => $case) {
    $before = array_values(array_filter($native, static fn (array $issue): bool => $fileOf($issue) === $file));
    $after = array_values(array_filter($isolated, static fn (array $issue): bool => $fileOf($issue) === $file));
    if (! in_array('Error', array_column($before, 'level'), true)) { throw new RuntimeException('Vacuous genuine declaration baseline: '.$case['label']); }
    $expected = $before;
    if ($case['correct']) {
        $target = array_values(array_filter($before, static fn (array $issue): bool => $issue['code'] === 'incompatible-property-type' && $issue['level'] === 'Error'));
        if (count($target) !== 1) { throw new RuntimeException('Missing unique positive native declaration Error: '.$case['label']); }
        $expected = array_values(array_filter($before, static fn (array $issue): bool => $issue['code'] !== 'incompatible-property-type' || $issue['level'] !== 'Error'));
        if (! in_array('Error', array_column($expected, 'level'), true)) { throw new RuntimeException('Vacuous retained unsafe-use Error: '.$case['label']); }
        $remove = [...$remove, ...$target];
    }
    if ($signature($expected) !== $signature($after)) { throw new RuntimeException('Complete native declaration signatures differ: '.$case['label']); }
    $counts[$file] = ['label' => $case['label'], 'correct' => $case['correct'], 'before' => count($before), 'after' => count($after)];
}
$focusNative = array_values(array_filter($native, static fn (array $issue): bool => $fileOf($issue) === 'focus.php'));
$focusTarget = array_values(array_filter($focusNative, static fn (array $issue): bool => $issue['code'] === 'incompatible-property-type' && $issue['level'] === 'Error'));
if (count($focusTarget) !== 1) { throw new RuntimeException('Vacuous actual positive focus.'); }
$focusExpected = array_values(array_filter($focusNative, static fn (array $issue): bool => $issue['code'] !== 'incompatible-property-type' || $issue['level'] !== 'Error'));
$remove = [...$remove, ...$focusTarget]; $removeSignatures = $signature($remove);
$expectedAll = array_values(array_filter($native, static fn (array $issue): bool => ! in_array(json_encode($issue, JSON_THROW_ON_ERROR), $removeSignatures, true)));
if ($signature($expectedAll) !== $signature($isolated)) { throw new RuntimeException('Whole declaration policy diff exceeds exact native target records.'); }
$three = $run('isolated-three-declarations', 'isolated-three-config.json');
if ($signature($three) !== $signature($isolated)) { throw new RuntimeException('One/three declaration workers differ.'); }
$singleNative = $run('single-native-declarations', 'single-native-config.json');
$single = $run('single-isolated-declarations', 'single-isolated-config.json');
if ($signature($singleNative) !== $signature($focusNative) || $signature($single) !== $signature($focusExpected)) { throw new RuntimeException('Single-file complete declaration records differ.'); }
$guard = $run('single-guard-declarations', 'single-guard-config.json');
if ($signature($guard) !== $signature($single)) { throw new RuntimeException('Genuine native declaration mutation controls changed complete records.'); }
$controls = json_decode(file_get_contents($workspace.'/native-declaration-controls.json'), true, flags: JSON_THROW_ON_ERROR);
if (count($controls) < 100) { throw new RuntimeException('Missing or vacuous actual declaration controls.'); }
if (in_array('--integrated', $argv, true)) {
    $currentWorker = file_get_contents($package.'/bin/laramago-worker.php');
    $anchor = 'new FactoryModelDeclarationPlugin($projectRoot),';
    if (substr_count($currentWorker, $anchor) !== 1) { throw new RuntimeException('Expected one current Factory declaration registration.'); }
    $controlWorker = str_replace($anchor, '', $currentWorker);
    file_put_contents($workspace.'/full-control-worker.php', $controlWorker);
    $fullConfig = json_decode(file_get_contents($workspace.'/isolated-config.json'), true, flags: JSON_THROW_ON_ERROR);
    $fullConfig['extension-hosts']['factory-declaration']['command'] = [PHP_BINARY, $workspace.'/full-control-worker.php', $package.'/vendor/autoload.php', $workspace];
    $fullConfig['extension-hosts']['factory-declaration']['workers'] = 1;
    file_put_contents($workspace.'/full-control-config.json', json_encode($fullConfig, JSON_THROW_ON_ERROR));
    $fullControl = $run('full-control-declarations', 'full-control-config.json');
    $fullConfig['extension-hosts']['factory-declaration']['command'][1] = $package.'/bin/laramago-worker.php';
    file_put_contents($workspace.'/full-one-config.json', json_encode($fullConfig, JSON_THROW_ON_ERROR));
    $fullOne = $run('full-one-declarations', 'full-one-config.json');
    $fullConfig['extension-hosts']['factory-declaration']['workers'] = 3;
    file_put_contents($workspace.'/full-three-config.json', json_encode($fullConfig, JSON_THROW_ON_ERROR));
    $fullThree = $run('full-three-declarations', 'full-three-config.json');
    $targetKeys = [];
    foreach ($remove as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'));
        if (count($primary) !== 1) { throw new RuntimeException('Expected exact native positive annotation.'); }
        $targetKeys[] = [$fileOf($issue), $primary[0]['span']['start']['offset'], $primary[0]['span']['end']['offset']];
    }
    $fullExpected = $fullControl; $fullRemoved = [];
    foreach ($targetKeys as $key) {
        $matches = [];
        foreach ($fullExpected as $index => $issue) {
            if ($issue['level'] !== 'Error' || $issue['code'] !== 'incompatible-property-type') { continue; }
            foreach ($issue['annotations'] as $annotation) {
                if ($annotation['kind'] === 'Primary' && [$fileOf($issue), $annotation['span']['start']['offset'], $annotation['span']['end']['offset']] === $key) { $matches[] = $index; }
            }
        }
        if (count($matches) !== 1) { throw new RuntimeException('Missing exact full-control positive declaration Error.'); }
        $fullRemoved[] = $fullExpected[$matches[0]]; unset($fullExpected[$matches[0]]);
    }
    if ($signature(array_values($fullExpected)) !== $signature($fullOne) || $signature($fullOne) !== $signature($fullThree)) {
        throw new RuntimeException('Full worker complete native field delta exceeds certified declarations or differs across workers.');
    }
    $integrated = ['currentWorkerSha256' => hash('sha256', $currentWorker), 'controlWorkerSha256' => hash('sha256', $controlWorker),
        'exactSingleRegistrationRemoved' => true, 'controlCount' => count($fullControl), 'oneCount' => count($fullOne), 'threeCount' => count($fullThree),
        'exactCompleteRemovedRecords' => $fullRemoved, 'allOtherFullNativeFieldsPreserved' => true, 'oneThreeFullWorkerIdentity' => true];
    file_put_contents($workspace.'/full-integration-result.json', json_encode($integrated, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
}
foreach ($traps as $marker) { if (file_exists($marker)) { throw new RuntimeException('Analyzed factory/model/application body executed.'); } }
file_put_contents($workspace.'/declaration-compatibility-result.json', json_encode(['cases' => count($cases), 'exactRemovedRecords' => count($remove),
    'wholeOtherRecordsPreserved' => true, 'oneThreeWorkerIdentity' => true, 'singleFileIdentity' => true,
    'nativeControls' => count($controls), 'sourceCases' => $counts, 'rootSpaces' => true, 'customVendorDir' => true,
    'noEnv' => ! file_exists($workspace.'/.env'), 'executionTrapsAbsent' => true, 'propertyTypesAndDocsUnchanged' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'PASS: '.count($cases).' declaration cases; '.count($remove).' exact doc compatibility corrections; '.count($controls)." actual controls; every other complete record retained.\nEvidence: $workspace\n";
