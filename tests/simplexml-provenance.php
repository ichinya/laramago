<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\Internal\Syntax\NodeStore;
use Mago\Sdk\Internal\Syntax\ResolvedNameStore;
use Mago\Sdk\Internal\Syntax\TriviaStore;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\SourceFile;

// All analyzed declarations are independently invented and are never executed.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago XML provenance '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$mode = $argv[3];
$fallback = new class implements \Mago\Sdk\Analyzer\Plugin {
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/xml-fallback', 'XML fallback', 'Opaque native XML properties');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $registry->registerPropertyTypeProvider(new \Ichinya\Laramago\Analyzer\SimpleXmlPropertyProvider);
    }
};
$plugins = $mode === 'full-control' ? [new \Ichinya\Laramago\Analyzer\LaravelPlugin($argv[2])] : [$fallback];
if ($mode === 'proven') { array_unshift($plugins, new \Ichinya\Laramago\Analyzer\SimpleXmlProvenancePlugin($argv[2])); }
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/xml', name: 'XML fixture', version: '1', analyzerPlugins: $plugins)))->run();
PHP);

$root = '$document = \\simplexml_load_string($text); if ($document === false) { throw new \\RuntimeException("Malformed record document."); } ';
$guard = 'if (count($document->group) !== 1) { throw new \\RuntimeException("One group required."); } ';
$finish = 'return (string) $document->group["size"];';
$normal = $root.$guard.$finish;
$orNormal = '$document = \\simplexml_load_string($text); if ($document === false || $document->getName() !== "records" || count($document->group) !== 1) { throw new \\RuntimeException; } '.$finish;
$orStart = substr($orNormal, 0, -strlen($finish));
$pipeline = <<<'PHP'
$text = file_get_contents($path);
if ($text === false || stripos($text, '<!DOCTYPE') !== false) { throw new RuntimeException('Unsupported input.'); }
$previous = libxml_use_internal_errors(true);
try { $document = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET); }
finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
if ($document === false || $document->getName() !== 'records' || count($document->group) !== 1) { throw new RuntimeException('Invalid structure.'); }
$entries = $document->xpath('/records/group//entry');
if (!is_array($entries) || $entries === []) { throw new RuntimeException('No entries.'); }
foreach ($entries as $entry) {
    if (count($entry->totals) !== 1) { throw new RuntimeException('One total required.'); }
    $size = (string) $entry->totals['size'];
    $rows = $entry->xpath('row[@kind="data"]');
    if (!is_array($rows)) { throw new RuntimeException('Invalid rows.'); }
    foreach ($rows as $row) { $label = (string) $row['label']; }
    $rowCount = count($entry->xpath('row[@kind="data"]') ?: []);
}
$summary = $document->group->summary;
if (count($summary) !== 1 || (string) $summary['size'] === '') { throw new RuntimeException('Missing summary.'); }
return (string) $summary['size'];
PHP;
$cases = [
    'guarded parser' => [$normal, 'positive'],
    'guarded constructor' => [str_replace('\\simplexml_load_string($text)', 'new \\SimpleXMLElement($text)', $normal), 'positive'],
    'zero parser options' => [str_replace('\\simplexml_load_string($text)', '\\simplexml_load_string($text, \\SimpleXMLElement::class, 0)', $normal), 'positive'],
    'native NONET constructor options' => [str_replace('\\simplexml_load_string($text)', 'new \\SimpleXMLElement($text, \\LIBXML_NONET)', $normal), 'positive'],
    'left to right false guard' => ['$document = \\simplexml_load_string($text); if ($document === false || $document->getName() !== "records" || count($document->group) !== 1) { throw new \\RuntimeException("Invalid structure."); } '.$finish, 'positive'],
    'native file input and nested XPath' => [$pipeline, 'positive', 'string $path'],
    'native count and unrelated mixed argument' => [$root.$guard.'acceptInteger($opaque); '.$finish, 'positive', 'string $text, mixed $opaque'],
    'native count and unrelated mixed count' => [$root.$guard.'count($opaque); '.$finish, 'positive', 'string $text, mixed $opaque'],
    'known literal payload' => [str_replace('\\simplexml_load_string($text)', '\\simplexml_load_string("<records/>")', $orNormal), 'cardinality'],
    'literal input restriction' => ['if ($text !== "<records/>") { throw new \\RuntimeException; } '.$orNormal, 'cardinality'],
    'inline literal input annotation' => ['/** @var \'<records/>\' $text */ '.$orNormal, 'cardinality'],
    'standalone literal input annotation' => ['/** @var \'<records/>\' $text */ ; '.$orNormal, 'cardinality'],
    'phpstan literal input annotation' => ['/** @phpstan-var \'<records/>\' $text */ '.$orNormal, 'cardinality'],
    'psalm literal input annotation' => ['/** @psalm-var \'<records/>\' $text */ '.$orNormal, 'cardinality'],
    'annotated input then fresh file read' => ['/** @var \'<records/>\' $text */ ; $fresh = file_get_contents($text); if ($fresh === false) { throw new \\RuntimeException; } '.str_replace('$text', '$fresh', $orNormal), 'cardinality'],
    'aliased input restriction' => ['$copy = $text; if ($copy !== "<records/>") { throw new \\RuntimeException; } '.$orNormal, 'cardinality'],
    'opaque caller argument assertion' => ['insistCallerLiteral(); '.$orNormal, 'cardinality'],
    'stream callback assertion' => ['file_get_contents("xmlproof://guard"); '.$orNormal, 'cardinality'],
    'producer read constrains prior XML' => [$root.'$unused = file_get_contents("xmlproof://guard"); if ($document->getName() !== "records" || count($document->group) !== 1) { throw new \\RuntimeException; } '.$finish, 'cardinality'],
    'static input assertion' => ['InputGuard::forceEmpty($text); '.$orNormal, 'cardinality'],
    'method input assertion' => ['$guard = new InputGuard; $guard->forceEmptyMethod($text); '.$orNormal, 'cardinality'],
    'constructor input assertion' => ['new InputGuard($text); '.$orNormal, 'cardinality'],
    'derived input restriction' => [$root.'$group = $document->group; if ($text !== "<records/>") { throw new \\RuntimeException; } if ($document->getName() !== "records" || count($group) !== 1) { throw new \\RuntimeException; } return (string) $group["size"];', 'cardinality'],
    'opaque static caller assertion' => ['InputGuard::callerLiteral(); '.$orNormal, 'cardinality'],
    'opaque method caller assertion' => ['$checker = new InputGuard; $checker->callerLiteralMethod(); '.$orNormal, 'cardinality'],
    'opaque constructor caller assertion' => ['new InputGuard; '.$orNormal, 'cardinality'],
    'literal documented input' => [$normal, 'unchanged', 'string $text', '/** @param \'<records/>\' $text */'],
    'nonempty documented input' => [$normal, 'unchanged', 'string $text', '/** @param non-empty-string $text */'],
    'opaque XML parameter' => [$guard.$finish, 'unchanged', 'SimpleXMLElement $document'],
    'uncontrolled parse failure' => ['$document = \\simplexml_load_string($text); '.$guard.$finish, 'unchanged'],
    'false guard in wrong order' => ['$document = \\simplexml_load_string($text); if (count($document->group) !== 1 || $document === false) { throw new \\RuntimeException; } '.$finish, 'unchanged'],
    'conditional guard' => [$root.'if ($flag) { '.$guard.' } '.$finish, 'unchanged', 'string $text, bool $flag'],
    'caught guard' => [$root.'try { '.$guard.' } catch (\\RuntimeException) {} '.$finish, 'unchanged'],
    'native count zero then one' => [$root.'if (count($document->group) !== 0) { throw new \\RuntimeException; } '.$guard.$finish, 'unchanged'],
    'native count one then two' => [$root.$guard.'if (count($document->group) !== 2) { throw new \\RuntimeException; } '.$finish, 'unchanged'],
    'repeated exact guard' => [$orStart.'if (count($document->group) !== 1 || (string) $document->group["size"] === "") { throw new \\RuntimeException; } '.$finish, 'repeat'],
    'repeated disjunct guard' => [$orStart.'if (count($document->group) !== 1 || count($document->group) !== 1) { throw new \\RuntimeException; } '.$finish, 'repeat'],
    'indexed XML alias' => [$root.$guard.'$alias = $document->group[0]; mutateXml($alias); '.$finish, 'unchanged'],
    'indexed XML escape' => [$root.$guard.'mutateXml($document->group[0]); '.$finish, 'unchanged'],
    'attribute XML alias' => [$root.$guard.'$alias = $document["label"]; mutateXml($alias); '.$finish, 'unchanged'],
    'attribute XML escape' => [$root.$guard.'mutateXml($document["label"]); '.$finish, 'unchanged'],
    'reference XML alias' => [$root.$guard.'$alias =& $document; '.$finish, 'unchanged'],
    'XML in array escape' => [$root.$guard.'mutateBag([$document]); '.$finish, 'unchanged'],
    'XML application argument' => [$root.$guard.'mutateXml($document); '.$finish, 'unchanged'],
    'XML property mutation' => [$root.$guard.'$document->group = 17; '.$finish, 'unchanged'],
    'XML method mutation' => [$root.$guard.'$document->addChild("group"); '.$finish, 'unchanged'],
    'XML local reset' => [$root.$guard.'$document = null; '.$finish, 'unchanged'],
    'captured XML' => [$root.$guard.'$mutate = function () use ($document): void {}; '.$finish, 'unchanged'],
    'hidden compact alias' => [$root.$guard.'mutateBag(compact("document")); '.$finish, 'unchanged'],
    'hidden local array alias' => [$root.$guard.'mutateBag(get_defined_vars()); '.$finish, 'unchanged'],
    'dynamic local mutation' => [$root.$guard.'${$name} = null; '.$finish, 'unchanged', 'string $text, string $name'],
    'extract local mutation' => [$root.$guard.'extract([]); '.$finish, 'unchanged'],
    'parse str local mutation' => [$root.$guard.'parse_str("document=bad", $output); '.$finish, 'unchanged'],
    'HTTP implicit local' => [str_replace('$document', '$http_response_header', $normal), 'unchanged'],
    'HTTP implicit XPath item' => [$root.$guard.'$entries = $document->xpath("/records/group"); if (!is_array($entries)) { throw new \\RuntimeException; } foreach ($entries as $http_response_header) { if (count($http_response_header->totals) !== 1) { throw new \\RuntimeException; } } '.$finish, 'unchanged'],
    'custom XML class loader' => [str_replace('\\simplexml_load_string($text)', '\\simplexml_load_string($text, CustomXml::class)', $normal), 'unchanged'],
    'custom XML constructor' => [str_replace('\\simplexml_load_string($text)', 'new CustomXml($text)', $normal), 'unchanged'],
    'URL constructor callback' => [str_replace('\\simplexml_load_string($text)', 'new \\SimpleXMLElement($text, 0, true)', $orNormal), 'unchanged'],
    'namespace constructor coercion' => [str_replace('\\simplexml_load_string($text)', 'new \\SimpleXMLElement($text, 0, false, $namespace)', $orNormal), 'unchanged', 'string $text, Stringable $namespace'],
    'external DTD callback options' => [str_replace('\\simplexml_load_string($text)', '\\simplexml_load_string($text, \\SimpleXMLElement::class, \\LIBXML_DTDLOAD)', $orNormal), 'unchanged'],
    'external entity callback options' => [str_replace('\\simplexml_load_string($text)', '\\simplexml_load_string($text, \\SimpleXMLElement::class, \\LIBXML_NONET | \\LIBXML_NOENT)', $orNormal), 'unchanged'],
    'dynamic parser options' => [str_replace('\\simplexml_load_string($text)', '\\simplexml_load_string($text, \\SimpleXMLElement::class, $options)', $orNormal), 'unchanged', 'string $text, int $options'],
    'custom XPath text nodes' => [$root.$guard.'$entries = $document->xpath("/records/text()"); if (!is_array($entries)) { throw new \\RuntimeException; } foreach ($entries as $entry) { if (count($entry->totals) !== 1) { throw new \\RuntimeException; } } '.$finish, 'unchanged'],
    'custom XPath attributes' => [$root.$guard.'$entries = $document->xpath("/records/@label"); if (!is_array($entries)) { throw new \\RuntimeException; } foreach ($entries as $entry) { if (count($entry->totals) !== 1) { throw new \\RuntimeException; } } '.$finish, 'unchanged'],
    'custom XPath union' => [$root.$guard.'$entries = $document->xpath("/records/group|/records/entry"); if (!is_array($entries)) { throw new \\RuntimeException; } foreach ($entries as $entry) { if (count($entry->totals) !== 1) { throw new \\RuntimeException; } } '.$finish, 'unchanged'],
    'unguarded XPath failure' => [$root.$guard.'$entries = $document->xpath("/records/group"); foreach ($entries as $entry) { if (count($entry->totals) !== 1) { throw new \\RuntimeException; } } '.$finish, 'unchanged'],
    'wrong XML attribute index' => [$root.$guard.'return (string) $document->group[true];', 'unchanged'],
    'invalid XML return' => [$root.$guard.'return $document->group;', 'unchanged'],
    'missing nested child' => [$root.'if (count($document->missing->nested) !== 1) { throw new \\RuntimeException; } return (string) $document->missing->nested["size"];', 'unsafe'],
];
$source = <<<'PHP'
<?php
declare(strict_types=1);
function acceptInteger(int $value): string { return (string) $value; }
function mutateXml(mixed $value): void {}
function mutateBag(array $value): void {}
function insistCallerLiteral(): void {
    $frame = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)[1];
    if (($frame['args'][0] ?? null) !== '<records><group size="1"/></records>') { throw new RuntimeException; }
}
class InputGuard {
    /** @phpstan-assert '<records/>' $text */
    public static function forceEmpty(string $text): void {}
    /** @phpstan-assert '<records/>' $text */
    public function forceEmptyMethod(string $text): void {}
    public static function callerLiteral(): void { insistCallerLiteral(); }
    public function callerLiteralMethod(): void { insistCallerLiteral(); }
    public function __construct(string $text = '') {}
}
class CustomXml extends SimpleXMLElement {
    public function count(): int { return 7; }
    public function __get(string $name): int { return 3; }
}

PHP;
$ranges = [];
foreach ($cases as $label => [$body, $expectation]) {
    $name = 'case'.count($ranges);
    $parameters = $cases[$label][2] ?? 'string $text';
    $doc = $cases[$label][3] ?? '';
    $start = strlen($source);
    $source .= $doc."\nfunction ".$name.'('.$parameters.'): string { '.$body." }\n";
    $ranges[$label] = [$start, strlen($source), $expectation];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/shadows.php', <<<'PHP'
<?php
namespace XmlOptions {
    const LIBXML_NONET = \LIBXML_DTDLOAD;
    function shadowedOptions(string $text): string {
        $document = \simplexml_load_string($text, \SimpleXMLElement::class, LIBXML_NONET);
        if ($document === false || $document->getName() !== 'records' || \count($document->group) !== 1) { throw new \RuntimeException; }
        return (string) $document->group['size'];
    }
}
namespace XmlParser {
    function simplexml_load_string(string $text): \SimpleXMLElement|false { return \simplexml_load_string('<records/>'); }
    function shadowedParser(string $text): string {
        $document = simplexml_load_string($text);
        if ($document === false || $document->getName() !== 'records' || \count($document->group) !== 1) { throw new \RuntimeException; }
        return (string) $document->group['size'];
    }
}
namespace XmlCounter {
    function count(\SimpleXMLElement|null $value): int { return 0; }
    function shadowedCount(string $text): string {
        $document = \simplexml_load_string($text);
        if ($document === false || $document->getName() !== 'records' || count($document->group) !== 1) { throw new \RuntimeException; }
        return (string) $document->group['size'];
    }
}
PHP);

// A single-file host exercises real SDK contexts and frozen metadata identities.
file_put_contents($workspace.'/guard.php', '<?php function guarded(string $text): string { '.str_replace('\\simplexml_load_string($text)', '\\simplexml_load_string($text, \\SimpleXMLElement::class, \\LIBXML_NONET)', $orNormal).' }');
file_put_contents($workspace.'/guard-worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/xml-guards', 'XML guards', 'Exact source, context, and metadata boundaries');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance($this->root);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $provider = new \Ichinya\Laramago\Analyzer\SimpleXmlProvenancePropertyProvider($index);
        $filter = new \Ichinya\Laramago\Analyzer\SimpleXmlProvenanceIssueFilter($index);
        $registry->registerPropertyTypeProvider(new class($provider, $this->root) implements \Mago\Sdk\Analyzer\PropertyTypeProvider {
            public function __construct(private $provider, private string $root) {}
            public function getTargets(): array { return $this->provider->getTargets(); }
            public function getPropertyType(\Mago\Sdk\Analyzer\PropertyTypeProviderContext $context): ?\Mago\Sdk\Analyzer\PropertyType {
                $result = $this->provider->getPropertyType($context);
                if ($result === null) { return null; }
                $a = $context->access;
                foreach (['span', 'name', 'class', 'write', 'nullable', 'subclass'] as $variant) {
                    $access = new \Mago\Sdk\Analyzer\PropertyAccess(
                        $variant === 'class' ? 'UnrelatedXml' : $a->class,
                        $variant === 'name' ? 'other' : $a->property,
                        $variant === 'write' ? \Mago\Sdk\Analyzer\PropertyAccessKind::Write : $a->kind,
                        match ($variant) {
                            'nullable' => \Mago\Sdk\Analyzer\Type::union($a->receiverType, \Mago\Sdk\Analyzer\Type::null()),
                            'subclass' => \Mago\Sdk\Analyzer\Type::namedObject('CustomXml'),
                            default => $a->receiverType,
                        },
                        new \Mago\Sdk\Span($a->span->start + ($variant === 'span' ? 1 : 0), $a->span->end),
                    );
                    $changed = new \Mago\Sdk\Analyzer\PropertyTypeProviderContext($context->phpVersion, $context->codebase, $access, $context->types, $context->cancellation);
                    if ($this->provider->getPropertyType($changed) !== null) { throw new \RuntimeException('XML property guard accepted '.$variant); }
                }
                $bytes = file_get_contents($this->root.'/guard.php');
                file_put_contents($this->root.'/guard.php', str_replace('group', 'other', $bytes));
                try {
                    if ($this->provider->getPropertyType($context) !== null) { throw new \RuntimeException('XML property accepted changed disk bytes.'); }
                } finally { file_put_contents($this->root.'/guard.php', $bytes); }
                file_put_contents($this->root.'/provider-guards.log', "7\n", FILE_APPEND);
                return $result;
            }
        });
        $registry->registerPropertyTypeProvider(new \Ichinya\Laramago\Analyzer\SimpleXmlPropertyProvider);
        $registry->registerIssueFilterHook(new class($filter, $this->root) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            public function __construct(private $filter, private string $root) {}
            public function getCodes(): array { return $this->filter->getCodes(); }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                $result = $this->filter->filterIssue($context);
                if ($result !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { return $result; }
                foreach (['span', 'foreign', 'duplicate', 'primary-message', 'code', 'message', 'context-source', 'context-file'] as $variant) {
                    $annotations = $context->issue->annotations;
                    foreach ($annotations as $key => $annotation) {
                        if ($annotation->kind !== \Mago\Sdk\Reporting\AnnotationKind::Primary) { continue; }
                        $annotations[$key] = new \Mago\Sdk\Reporting\Annotation($annotation->kind,
                            new \Mago\Sdk\Span($annotation->span->start + ($variant === 'span' ? 1 : 0), $annotation->span->end),
                            $variant === 'primary-message' ? 'Unknown XML annotation.' : $annotation->message,
                            $variant === 'foreign' ? 'elsewhere.php' : $annotation->file);
                        if ($variant === 'duplicate') { $annotations[] = $annotation; }
                    }
                    $issue = new \Mago\Sdk\Reporting\ReportedIssue($context->issue->level,
                        $variant === 'code' ? 'invalid-argument' : $context->issue->code,
                        $variant === 'message' ? 'Unknown XML issue.' : $context->issue->message,
                        $context->issue->notes, $context->issue->help, $context->issue->link, $annotations, $context->issue->edits);
                    $changed = new \Mago\Sdk\Analyzer\IssueFilterContext($context->phpVersion, $context->codebase, $context->types, $context->cancellation,
                        $variant === 'context-file' ? 'elsewhere.php' : $context->file,
                        $variant === 'context-source' ? str_replace('group', 'other', $context->contents) : $context->contents, $issue);
                    if ($this->filter->filterIssue($changed) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('XML issue guard accepted '.$variant); }
                }
                $bytes = file_get_contents($this->root.'/guard.php');
                file_put_contents($this->root.'/guard.php', str_replace('group', 'other', $bytes));
                try {
                    if ($this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('XML issue accepted changed disk bytes.'); }
                } finally { file_put_contents($this->root.'/guard.php', $bytes); }
                $caller = $context->codebase->getFunction('guarded');
                $targets = [
                    'caller' => $caller,
                    'native parser' => $context->codebase->getFunction('simplexml_load_string'),
                    'native count' => $context->codebase->getFunction('count'),
                    'native XML count method' => $context->codebase->getDeclaringMethod('SimpleXMLElement', 'count'),
                    'native XML name method' => $context->codebase->getDeclaringMethod('SimpleXMLElement', 'getName'),
                    'native XML class' => $context->codebase->getClass('SimpleXMLElement'),
                    'native count interface' => $context->codebase->getInterface('Countable'),
                    'native NONET constant' => $context->codebase->getConstant('LIBXML_NONET'),
                ];
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                foreach ($targets as $role => $metadata) {
                    $variants = $role === 'caller' ? ['file', 'identifier', 'name-span', 'body-span', 'reference', 'parameter-name', 'parameter-reference', 'parameter-type'] : ['builtin'];
                    foreach ($variants as $variant) {
                        $values = get_object_vars($metadata);
                        if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('other.php', $metadata->location->span); }
                        if ($variant === 'identifier') { $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($metadata->identifier->kind, 'other'); }
                        if ($variant === 'name-span') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation($metadata->nameLocation->file, new \Mago\Sdk\Span($metadata->nameLocation->span->start + 1, $metadata->nameLocation->span->end)); }
                        if ($variant === 'body-span') { $values['location'] = new \Mago\Sdk\SourceLocation($metadata->location->file, new \Mago\Sdk\Span($metadata->location->span->start, $metadata->location->span->end - 1)); }
                        if ($variant === 'reference') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                        if ($variant === 'builtin') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                        if (str_starts_with($variant, 'parameter-')) {
                            $parameter = get_object_vars($metadata->parameters[0]);
                            if ($variant === 'parameter-name') { $parameter['name'] = '$other'; }
                            if ($variant === 'parameter-reference') { $parameter['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter['flags']->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                            if ($variant === 'parameter-type') {
                                $t = get_object_vars($parameter['type']);
                                $t['type'] = \Mago\Sdk\Analyzer\Type::literalString('<records/>');
                                $parameter['type'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(...$t);
                            }
                            $values['parameters'][0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$parameter);
                        }
                        $class = $metadata::class;
                        $changed = new $class(...$values);
                        $replaced = 0;
                        foreach ($snapshot as $operation => $entries) {
                            foreach ($entries as $key => $entry) {
                                if ($entry === $metadata) { $cache->values[$operation][$key] = $changed; $replaced++; }
                            }
                        }
                        try {
                            if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('XML metadata guard accepted '.$role.' '.$variant); }
                        } finally { $cache->values = $snapshot; }
                    }
                }
                if ($this->filter->filterIssue($context) !== $result) { throw new \RuntimeException('XML guard mutated a valid proof.'); }
                file_put_contents($this->root.'/issue-guards.log', "24\n", FILE_APPEND);
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/xml-guards', name: 'XML guards', version: '1', analyzerPlugins: [$plugin])))->run();
PHP);

$analyze = static function (string $mode, array $paths = ['cases.php']) use ($workspace, $package): array {
    $configDirectory = $mode === 'external-proven' ? $workspace.' configuration outside' : $workspace;
    if (!is_dir($configDirectory)) { mkdir($configDirectory); }
    $host = $mode === 'native' ? new stdClass : [
        'xml' => ['command' => $mode === 'integrated'
            ? [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]
            : [PHP_BINARY, $workspace.'/'.($mode === 'guard' ? 'guard-worker.php' : 'worker.php'), $package.'/vendor/autoload.php', $workspace, $mode === 'external-proven' ? 'proven' : $mode], 'workers' => 1],
    ];
    file_put_contents($configDirectory.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $mode === 'external-proven' ? array_map(static fn (string $path): string => $workspace.'/'.$path, $paths) : $paths], 'extension-hosts' => $host,
    ], JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $process = proc_open([...$command, '--workspace', $workspace, '--config', $configDirectory.'/mago.json', 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes, $configDirectory);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request|hook .* failed|parse error|worker .* exited/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    return json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$group = static function (array $issues) use ($ranges): array {
    $grouped = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] !== 'Primary' || $annotation['span']['file_id']['name'] !== 'cases.php') { continue; }
            $offset = $annotation['span']['start']['offset'];
            foreach ($ranges as $label => [$start, $end]) {
                if ($offset >= $start && $offset < $end) {
                    $grouped[$label][] = [$issue['code'], $issue['message'], $offset, $annotation['span']['end']['offset']];
                    break;
                }
            }
            break;
        }
    }
    foreach ($grouped as &$issues) { sort($issues); }
    unset($issues);
    return $grouped;
};
$codes = static fn (array $issues): array => array_column($issues, 0);
$comparisons = static function (array $issues): array {
    $result = [];
    foreach ($issues as [$code, $message, $start, $end]) {
        if (!in_array($code, ['impossible-type-comparison', 'redundant-type-comparison'], true)) { continue; }
        // Narrowing the actual receiver may remove null from the displayed type.
        // The diagnostic code, expression, condition, and exact span must survive.
        $result[] = [$code, str_replace('SimpleXMLElement|null', 'SimpleXMLElement', $message), $start, $end];
    }
    return $result;
};
$redundant = static fn (array $issues): array => array_values(array_filter($comparisons($issues), static fn (array $issue): bool => $issue[0] === 'redundant-type-comparison'));
$arguments = static fn (array $issues): array => array_values(array_filter($issues, static fn (array $issue): bool => in_array($issue[0], ['mixed-argument', 'invalid-argument'], true)));
$nullableArguments = static fn (array $issues): array => array_values(array_filter($issues, static fn (array $issue): bool => $issue[0] === 'possibly-null-argument'));
$before = $group($analyze('fallback'));
$after = $group($analyze('proven'));
if ($after !== $group($analyze('external-proven'))) { throw new RuntimeException('External configuration changed source identity '.$workspace); }
$guardIssues = $analyze('guard', ['guard.php']);
if ($guardIssues !== [] || !is_file($workspace.'/provider-guards.log') || !is_file($workspace.'/issue-guards.log')) {
    throw new RuntimeException('Native XML context/metadata guards did not run: '.json_encode($guardIssues).' '.$workspace);
}
$shadowBefore = $analyze('fallback', ['shadows.php']);
$shadowAfter = $analyze('proven', ['shadows.php']);
if ($shadowBefore === [] || $shadowBefore !== $shadowAfter) { throw new RuntimeException('Changed native symbol shadow controls '.$workspace); }
$corrected = 0;
foreach ($ranges as $label => [$start, $end, $expectation]) {
    $old = $before[$label] ?? [];
    $new = $after[$label] ?? [];
    $oldCodes = $codes($old);
    $newCodes = $codes($new);
    if ($expectation === 'unchanged' && ($old === [] || $old !== $new)) {
        throw new RuntimeException('Changed unsupported '.$label.': '.json_encode([$old, $new]).' '.$workspace);
    }
    if ($expectation === 'positive') {
        if (array_intersect(['possibly-null-argument', 'impossible-type-comparison'], $oldCodes) === []
            || array_intersect(['possibly-null-argument', 'impossible-type-comparison', 'redundant-type-comparison', 'mixed-array-access', 'mixed-property-access'], $newCodes) !== []) {
            throw new RuntimeException('Wrong native XML correction in '.$label.': '.json_encode([$old, $new]).' '.$workspace);
        }
        if (str_contains($label, 'unrelated') && ($arguments($old) === [] || $arguments($old) !== $arguments($new))) {
            throw new RuntimeException('Lost application argument diagnostic in '.$label.' '.$workspace);
        }
        $corrected += count($old) - count($new);
    }
    if ($expectation === 'cardinality' && ($comparisons($old) === [] || $comparisons($old) !== $comparisons($new))) {
        throw new RuntimeException('Broadened known input cardinality in '.$label.': '.json_encode([$old, $new]).' '.$workspace);
    }
    if ($expectation === 'repeat' && ($redundant($old) === [] || $redundant($old) !== $redundant($new))) {
        throw new RuntimeException('Lost actual repeated cardinality guard in '.$label.': '.json_encode([$old, $new]).' '.$workspace);
    }
    if ($expectation === 'unsafe' && ($nullableArguments($old) === [] || $nullableArguments($old) !== $nullableArguments($new))) {
        throw new RuntimeException('Lost empty-proxy nested null in '.$label.' '.$workspace);
    }
}

if (in_array('--integrated', $argv, true)) {
    $control = $group($analyze('full-control'));
    $integrated = $group($analyze('integrated'));
    foreach ($ranges as $label => [$start, $end, $expectation]) {
        if ($expectation === 'unchanged' && (($control[$label] ?? []) === [] || ($control[$label] ?? []) !== ($integrated[$label] ?? []))) {
            throw new RuntimeException('Integrated change in unsupported '.$label.' '.$workspace);
        }
        if ($expectation === 'positive' && (array_intersect(['possibly-null-argument', 'impossible-type-comparison'], $codes($control[$label] ?? [])) === []
            || array_intersect(['possibly-null-argument', 'impossible-type-comparison', 'redundant-type-comparison', 'mixed-array-access', 'mixed-property-access'], $codes($integrated[$label] ?? [])) !== [])) {
            throw new RuntimeException('Integrated XML correction failed: '.$label.' '.$workspace);
        }
        if ($expectation === 'cardinality'
            && ($comparisons($control[$label] ?? []) === [] || $comparisons($control[$label] ?? []) !== $comparisons($integrated[$label] ?? []))) {
            throw new RuntimeException('Integrated known XML cardinality changed: '.$label.' '.$workspace);
        }
        if ($expectation === 'repeat' && ($redundant($control[$label] ?? []) === [] || $redundant($control[$label] ?? []) !== $redundant($integrated[$label] ?? []))) {
            throw new RuntimeException('Integrated real redundancy changed: '.$label.' '.$workspace);
        }
        if ($expectation === 'unsafe' && ($nullableArguments($control[$label] ?? []) === [] || $nullableArguments($control[$label] ?? []) !== $nullableArguments($integrated[$label] ?? []))) {
            throw new RuntimeException('Integrated empty-proxy warning disappeared: '.$label.' '.$workspace);
        }
    }
}

// Verify the native contract directly, without loading any application file.
foreach ([0, 1, 2] as $count) {
    $xml = new SimpleXMLElement('<records>'.str_repeat('<group><leaf/></group>', $count).'</records>');
    if (!$xml->group instanceof SimpleXMLElement || count($xml->group) !== $count) { throw new RuntimeException('Wrong native child proxy cardinality.'); }
    if ($count === 0 && $xml->group->leaf !== null) { throw new RuntimeException('Empty proxy must retain nested null.'); }
    if ($count > 0 && !$xml->group->leaf instanceof SimpleXMLElement) { throw new RuntimeException('Actual parent must expose a child proxy.'); }
    $items = $xml->xpath('/records/group');
    if (!is_array($items) || count($items) !== $count) { throw new RuntimeException('Wrong native element XPath.'); }
}
if (function_exists('dom_import_simplexml')) {
    $xml = new SimpleXMLElement('<records><group><leaf/></group></records>');
    $alias = $xml->group[0];
    $dom = dom_import_simplexml($alias);
    $dom->parentNode->removeChild($dom);
    if (count($xml->group) !== 0 || $xml->group->leaf !== null) { throw new RuntimeException('Indexed XML alias must invalidate the document.'); }
    try { count($xml->group->leaf); throw new RuntimeException('Nested empty proxy accepted by native count.'); }
    catch (TypeError) {}
}

// Native stream reads can invoke user code before the parser sees its argument.
class XmlProvenanceStreamConstraint {
    public mixed $context = null;
    private int $offset = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        foreach (debug_backtrace() as $frame) {
            if (($frame['function'] ?? null) === 'xmlProvenanceCallerConstraint'
                && ($frame['args'][0] ?? null) !== '<records><group/></records>') {
                throw new RuntimeException('Caller input is constrained by a stream callback.');
            }
        }
        return true;
    }
    public function stream_read(int $count): string {
        if ($this->offset > 0) { return ''; }
        $this->offset += $count;
        return substr('ok', 0, $count);
    }
    public function stream_eof(): bool { return $this->offset > 0; }
    public function stream_stat(): array { return []; }
}
function xmlProvenanceCallerConstraint(string $text): int {
    file_get_contents('xmlproof://guard');
    $xml = new SimpleXMLElement($text);
    return count($xml->group);
}
stream_wrapper_register('xmlproof', XmlProvenanceStreamConstraint::class);
try {
    if (xmlProvenanceCallerConstraint('<records><group/></records>') !== 1) { throw new RuntimeException('Stream control did not reach its literal input.'); }
    try { xmlProvenanceCallerConstraint('<records/>'); throw new LogicException('Stream callback accepted unconstrained input.'); }
    catch (RuntimeException) {}
} finally { stream_wrapper_unregister('xmlproof'); }

require $package.'/vendor/autoload.php';
$scanChecks = (static function (string $root): int {
    mkdir($root);
    $source = <<<'PHP'
<?php
function guardedElement(string $text): string {
    $document = \simplexml_load_string($text);
    if ($document === false) { throw new \RuntimeException; }
    if (count($document->group) !== 1) { throw new \RuntimeException; }
    return (string) $document->group['size'];
}
PHP;
    file_put_contents($root.'/cases.php', $source);
    $version = PHPVersion::fromParts(8, 5);
    $cancellation = new class implements CancellationTokenInterface {
        public function isCancelled(): bool { return false; }
        public function throwIfCancelled(): void {}
        public function subscribe(Closure $callback): int { return 0; }
        public function unsubscribe(int $subscription): void {}
    };
    $index = new SimpleXmlProvenance($root);
    $file = static fn (string $path, string $contents): SourceFile => new SourceFile(
        $version, $path, $contents, [], new NodeStore([], '', 0),
        new ResolvedNameStore('', '', '', 0), new TriviaStore('', 0), null,
    );
    $scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $cancellation): void {
        $index->scan(new CodebaseScanContext($version, $cancellation, $files, $first, $last));
    };
    $checks = 0;
    $expect = static function (string $label, bool $actual) use (&$checks): void {
        if (!$actual) { throw new RuntimeException('XML index guard failed: '.$label); }
        $checks++;
    };
    $host = $file('cases.php', $source);
    $expect('absent scan', $index->proofs('cases.php', $source) === []);
    $scan([$host], last: false);
    $expect('incomplete scan', $index->proofs('cases.php', $source) === []);
    $scan([], first: false);
    $proofs = $index->proofs('cases.php', $source);
    $expect('completed positive proof', count($proofs) === 1 && $proofs[0]['properties'] !== []);
    $proof = $proofs[0];
    $property = array_values($proof['properties'])[0];
    $span = new Span($property->name->getStartFilePos(), $property->name->getEndFilePos() + 1);
    $expect('positive property candidate', $index->property($span) !== null);
    $expect('relative root resolves current bytes', SimpleXmlProvenance::current($proof));
    $expect('wrong context file', $index->proofs('other.php', $source) === []);
    $expect('wrong context source bytes', $index->proofs('cases.php', $source."\n// changed context") === []);
    $changed = str_replace("['size']", "['unit']", $source);
    $expect('equal length source mutation', $changed !== $source && strlen($changed) === strlen($source));
    file_put_contents($root.'/cases.php', $changed);
    try { $expect('changed disk bytes', !SimpleXmlProvenance::current($proof)); }
    finally { file_put_contents($root.'/cases.php', $source); }
    $expect('restored disk positive', SimpleXmlProvenance::current($proof));
    $scan([$file('cases.php', $changed)]);
    $overlay = $index->proofs('cases.php', $changed);
    $expect('overlay indexed independently', count($overlay) === 1);
    $expect('overlay differs from disk', !SimpleXmlProvenance::current($overlay[0]));
    $scan([$host, $host]);
    $expect('duplicate path invalidates proof', $index->proofs('cases.php', $source) === [] && $index->property($span) === null);
    $scan([$host, $file('broken.php', '<?php function broken( {')]);
    $expect('parse failure invalidates scan', $index->proofs('cases.php', $source) === [] && $index->property($span) === null);
    $scan([$host, $file('oversized.php', str_repeat(' ', 2_000_001))]);
    $expect('oversized file invalidates scan', $index->proofs('cases.php', $source) === []);
    $scan([$host]);
    $expect('first batch resets veto', $index->property($span) !== null);
    foreach ([
        'ordinary collision' => ['<?php $other->', 'group', ';'],
        'nullsafe collision' => ['<?php $other?->', 'group', ';'],
        'dynamic collision' => ['<?php $other->{', '$name', '};'],
    ] as $label => [$prefix, $name, $suffix]) {
        $collision = $prefix.str_repeat(' ', $span->start - strlen($prefix)).$name.$suffix;
        $scan([$host, $file('collision.php', $collision)]);
        $expect($label.' poisons provider span', $index->property($span) === null);
        $expect($label.' keeps file proof', count($index->proofs('cases.php', $source)) === 1);
    }
    $scan([$host]);
    $expect('completed before initialization', $index->property($span) !== null);
    $index->initialize(new InitializationContext($version, $cancellation));
    $expect('initialization clears state', $index->property($span) === null && $index->proofs('cases.php', $source) === []);
    $scan([$host]);
    $expect('completed after initialization', $index->property($span) !== null);
    return $checks;
})($workspace.'/scan root');
echo 'SimpleXML provenance checks passed: '.count($cases).' source cases, '.$corrected.' corrected reports, '.$scanChecks.' scan controls, 7 provider and 24 issue/metadata guards'.(in_array('--integrated', $argv, true) ? ', integrated worker' : '').".\n";
