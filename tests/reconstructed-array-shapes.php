<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\ReconstructedArrayShapes;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\Internal\Syntax\NodeStore;
use Mago\Sdk\Internal\Syntax\ResolvedNameStore;
use Mago\Sdk\Internal\Syntax\TriviaStore;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;

// Invented declarations are analyzed without running application or fixture bodies.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago reconstructed shapes '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php', '<?php file_put_contents(__DIR__."/executed", "bootstrap"); throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$mode = $argv[3];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/reconstructed-shapes', 'Reconstructed shapes', 'Independent scalar record proof');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ReconstructedArrayShapes($this->root);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $registry->registerIssueFilterHook(new \Ichinya\Laramago\Analyzer\ReconstructedArrayShapeIssueFilter($index));
    }
};
$plugins = $mode === 'standalone' ? [] : [new \Ichinya\Laramago\Analyzer\LaravelPlugin($argv[2])];
if (in_array($mode, ['standalone', 'proven'], true)) { array_unshift($plugins, $plugin); }
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/reconstructed-shapes', name: 'Reconstructed shapes', version: '1', analyzerPlugins: $plugins)))->run();
PHP);

$origin = '$record = $input["record"] ?? null; ';
$guard = 'if (!is_array($record) || !in_array($record["mode"] ?? null, ["all", "one"], true) || ($record["mode"] === "one" && !is_int($record["entry"] ?? null))) { throw new RuntimeException("Invalid record."); } ';
$rebuild = '$record = $record["mode"] === "all" ? ["mode" => "all"] : ["mode" => "one", "entry" => $record["entry"]]; ';
$opaque = 'self::inspect($input["sibling"] ?? null); ';
$finish = 'return new self($record);';
$normal = $origin.$guard.$rebuild.$opaque.$finish;
$contract = "array{mode: 'all'}|array{mode: 'one', entry: int}";
$cases = [
    'checked scalar reconstruction' => [$normal, true],
    'reordered literal fields' => [str_replace('["mode" => "one", "entry" => $record["entry"]]', '["entry" => $record["entry"], "mode" => "one"]', $normal), true],
    'multiline guard' => [str_replace(' || ', "\n            || ", $normal), true],
    'fully qualified native helpers' => [str_replace(['is_array(', 'in_array(', 'is_int('], ['\\is_array(', '\\in_array(', '\\is_int('], $normal), true],
    'additional native scalar comparison' => [str_replace('!is_int($record["entry"] ?? null)))', '(!is_int($record["entry"] ?? null) || $record["entry"] <= 0)))', $normal), true],
    'additional defined scalar parameter' => [str_replace('!is_int($record["entry"] ?? null)))', '(!is_int($record["entry"] ?? null) || $enabled === false)))', $normal), true, null, 'array $input, bool $enabled'],
    'reversed discriminator ternary' => [str_replace($rebuild, '$record = $record["mode"] === "one" ? ["mode" => "one", "entry" => $record["entry"]] : ["mode" => "all"]; ', $normal), true],
    'primitive input reference changed after copy' => [$origin.$guard.$rebuild.'self::invokeAfterCopy($afterCopy); '.$finish, true, null, 'array $input, Closure $afterCopy'],
    'opaque preceding constructor argument' => [$origin.$guard.$rebuild.'return new self(self::inspect($input["sibling"] ?? null), $record);', true, null, null, 'prefix'],
    'unrelated argument report retained' => [$origin.$guard.$rebuild.'return new self($record, $input["count"] ?? null);', true, null, null, 'suffix'],
    'missing native array guard' => [str_replace('!is_array($record) || ', '', $normal), false],
    'missing integer guard' => [str_replace(' || ($record["mode"] === "one" && !is_int($record["entry"] ?? null))', '', $normal), false],
    'wrong integer field' => [str_replace('is_int($record["entry"]', 'is_int($record["other"]', $normal), false],
    'wrong integer branch' => [str_replace('($record["mode"] === "one" &&', '($record["mode"] === "all" &&', $normal), false],
    'wrong checked record' => [str_replace('is_int($record["entry"]', 'is_int($input["entry"]', $normal), false],
    'loose membership check' => [str_replace('["all", "one"], true)', '["all", "one"], false)', $normal), false],
    'nonexhaustive discriminator' => [str_replace(' || !in_array($record["mode"] ?? null, ["all", "one"], true)', '', $normal), false],
    'additional discriminator accepted' => [str_replace('["all", "one"], true)', '["all", "one", "third"], true)', $normal), false],
    'duplicate discriminator set' => [str_replace('["all", "one"], true)', '["all", "all"], true)', $normal), false],
    'wrong discriminator guard field' => [str_replace('in_array($record["mode"]', 'in_array($record["other"]', $normal), false],
    'loose discriminator reconstruction' => [str_replace('$record["mode"] === "all" ?', '$record["mode"] == "all" ?', $normal), false],
    'wrong reconstruction discriminator' => [str_replace('$record["mode"] === "all" ?', '$record["other"] === "all" ?', $normal), false],
    'wrong reconstruction tag' => [str_replace('["mode" => "one",', '["mode" => "third",', $normal), false],
    'wrong reconstruction identifier' => [str_replace('"entry" => $record["entry"]', '"entry" => $record["other"]', $normal), false],
    'missing identifier field' => [str_replace(', "entry" => $record["entry"]', '', $normal), false],
    'whole record branch copy' => [str_replace('["mode" => "one", "entry" => $record["entry"]]', '$record', $normal), false],
    'reference array item' => [str_replace('"entry" => $record["entry"]', '"entry" => &$record["entry"]', $normal), false],
    'unpacked record branch' => [str_replace('["mode" => "one", "entry" => $record["entry"]]', '[...$record]', $normal), false],
    'unknown nested object field' => [str_replace('"entry" => $record["entry"]', '"entry" => $record["entry"], "object" => $input["object"]', $normal), false],
    'opaque guard evaluation' => [str_replace(' || ($record["mode"]', ' || self::inspect($input) || ($record["mode"]', $normal), false],
    'undefined sibling guard local' => [str_replace('!is_int($record["entry"] ?? null)))', '(!is_int($record["entry"] ?? null) || $missingFlag !== null)))', $normal), false],
    'unset sibling guard parameter' => ['unset($enabled); '.str_replace('!is_int($record["entry"] ?? null)))', '(!is_int($record["entry"] ?? null) || $enabled === false)))', $normal), false, null, 'array $input, bool $enabled'],
    'opaque callback before reconstruction' => [$origin.$guard.$opaque.$rebuild.$finish, false],
    'nonthrowing rejection' => [str_replace('throw new RuntimeException("Invalid record.");', 'self::inspect($input);', $normal), false],
    'conditional rejection' => [$origin.'if ($enabled) { '.$guard.'} '.$rebuild.$finish, false, null, 'array $input, bool $enabled'],
    'caught rejection' => [$origin.'try { '.$guard.'} catch (RuntimeException) {} '.$rebuild.$finish, false],
    'existing local reference before origin' => ['$alias =& $record; '.$normal, false],
    'source parameter slot' => [$normal, false, null, 'array $input, mixed $record'],
    'source reference escape' => [$origin.$guard.$rebuild.'$alias =& $record; '.$opaque.$finish, false],
    'source closure capture' => [$origin.$guard.$rebuild.'$callback = static function () use (&$record): void {}; '.$opaque.$finish, false],
    'source arrow capture' => [$origin.$guard.$rebuild.'$callback = static fn (): array => $record; '.$opaque.$finish, false],
    'source passed to opaque call' => [$origin.$guard.$rebuild.'self::inspect($record); '.$finish, false],
    'source rebinding' => [$origin.$guard.$rebuild.'$record = $input; '.$finish, false],
    'source field mutation' => [$origin.$guard.$rebuild.'$record["entry"] = $input["replacement"] ?? null; '.$finish, false],
    'source global binding' => ['global $record; '.$normal, false],
    'source static binding' => ['static $record; '.$normal, false],
    'hidden local snapshot' => [$origin.$guard.$rebuild.'self::inspect(get_defined_vars()); '.$finish, false],
    'hidden local compact' => [$origin.$guard.$rebuild.'self::inspect(compact("record")); '.$finish, false],
    'dynamic local mutation' => [$origin.$guard.$rebuild.'${$name} = $input; '.$finish, false, null, 'array $input, string $name'],
    'extract local mutation' => [$origin.$guard.$rebuild.'extract($input); '.$finish, false],
    'indirect extract local mutation' => [$origin.$guard.$rebuild.'call_user_func("extract", ["record" => ["mode" => "one", "entry" => "changed"]]); '.$finish, false],
    'indirect extract argument array' => [$origin.$guard.$rebuild.'call_user_func_array("extract", [["record" => ["mode" => "one", "entry" => "changed"]]]); '.$finish, false],
    'dynamic intrinsic attempt deferred' => [$origin.$guard.$rebuild.'$callback = "extract"; $callback(["record" => ["mode" => "one", "entry" => "changed"]]); '.$finish, false],
    'dynamic closure invocation deferred' => [$origin.$guard.$rebuild.'$afterCopy(["record" => ["mode" => "one", "entry" => "changed"]]); '.$finish, false, null, 'array $input, Closure $afterCopy'],
    'source explicit documentation' => [$origin.$guard.'/** @var array{mode: "one", entry: string} $record */ '.$rebuild.$finish, false],
    'source implicit documentation' => [$origin.$guard.'/** @var array{mode: "one", entry: string} */ '.$rebuild.$finish, false],
    'source annotation after sibling annotation' => [$origin.$guard."/** @var int \$sibling\n * @var array{mode: 'one', entry: string} \$record */ ".$rebuild.$finish, false],
    'named consumer argument' => [$origin.$guard.$rebuild.'return new self(record: $record);', false],
    'unpacked consumer argument' => [$origin.$guard.$rebuild.'return new self(...[$record]);', false],
    'stronger identifier range' => [$normal, false, "array{mode: 'all'}|array{mode: 'one', entry: positive-int}"],
    'stronger identifier literal' => [$normal, false, "array{mode: 'all'}|array{mode: 'one', entry: 7}"],
    'contradictory identifier type' => [$normal, false, "array{mode: 'all'}|array{mode: 'one', entry: string}"],
    'different declared structure' => [$normal, false, "array{mode: 'all'}|array{mode: 'one', identity: int}"],
    'constructor by reference parameter' => [$normal, false, null, null, 'reference'],
    'caller by reference input' => [$normal, false, null, 'array &$input'],
];
$source = "<?php\ndeclare(strict_types=1);\n";
$ranges = [];
$classSource = static function (string $name, string $body, string $type, string $parameters = 'array $input', string $layout = ''): string {
    $constructorParameters = match ($layout) {
        'prefix' => 'mixed $prefix, array $record',
        'suffix' => 'array $record, int $count',
        'reference' => 'array &$record',
        default => 'array $record',
    };
    return 'final class '.$name.' { /** @param '.$type.' $record */ private function __construct('.$constructorParameters.') {} '
        .'private static function inspect(mixed $value): null { return null; } private static function invokeAfterCopy(\\Closure $callback): void { $callback(); } public static function build('.$parameters.'): self { '.$body." } }\n";
};
foreach ($cases as $label => [$body, $positive]) {
    $start = strlen($source);
    $source .= $classSource('ScalarSelection'.count($ranges), $body, $cases[$label][2] ?? $contract, $cases[$label][3] ?? 'array $input', $cases[$label][4] ?? '');
    $ranges[$label] = [$start, strlen($source), $positive];
}
file_put_contents($workspace.'/cases.php', $source);
$proofSource = "<?php\ndeclare(strict_types=1);\n".$classSource('ShapeProof', $normal, $contract);
file_put_contents($workspace.'/proof.php', $proofSource);
file_put_contents($workspace.'/shadows.php', "<?php\nnamespace ShapeShadow;\nfunction is_array(mixed \$value): bool { return true; } function is_int(mixed \$value): bool { return true; } function in_array(mixed \$needle, array \$haystack, bool \$strict): bool { return true; }\n".$classSource('ShadowSelection', str_replace('new RuntimeException(', 'new \\RuntimeException(', $normal), $contract));
file_put_contents($workspace.'/aliases.php', "<?php\n/** @phpstan-type RecordAlias ".$contract." */\n".$classSource('AliasSelection', $normal, 'RecordAlias'));
file_put_contents($workspace.'/native-aliases.php', "<?php\nnamespace NativeAliases;\nuse function is_array as nativeArray;\nuse function in_array as nativeMembership;\nuse function is_int as nativeInteger;\n"
    .$classSource('NativeAliasSelection', str_replace(['is_array(', 'in_array(', 'is_int(', 'new RuntimeException('], ['nativeArray(', 'nativeMembership(', 'nativeInteger(', 'new \\RuntimeException('], $normal), $contract));
file_put_contents($workspace.'/dispatcher-aliases.php', "<?php\nnamespace DispatcherAliases;\nuse function call_user_func as invokeSymbol;\nuse function call_user_func_array as invokeSymbolArray;\n"
    .$classSource('AliasedDispatcher', str_replace('new RuntimeException(', 'new \\RuntimeException(', $origin.$guard.$rebuild.'invokeSymbol("extract", ["record" => ["mode" => "one", "entry" => "changed"]]); '.$finish), $contract)
    .$classSource('AliasedDispatcherArray', str_replace('new RuntimeException(', 'new \\RuntimeException(', $origin.$guard.$rebuild.'invokeSymbolArray("extract", [["record" => ["mode" => "one", "entry" => "changed"]]]); '.$finish), $contract));

file_put_contents($workspace.'/proof-worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/shape-context', 'Shape context', 'Source and metadata identity controls');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ReconstructedArrayShapes($this->root);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $filter = new \Ichinya\Laramago\Analyzer\ReconstructedArrayShapeIssueFilter($index);
        $registry->registerIssueFilterHook(new class($filter, $this->root) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            public function __construct(private $filter, private string $root) {}
            public function getCodes(): array { return $this->filter->getCodes(); }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                $result = $this->filter->filterIssue($context);
                if ($result !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { return $result; }
                $checks = 0;
                foreach (['span-start', 'span-end', 'foreign', 'duplicate', 'kind', 'primary-message', 'code', 'message', 'context-source', 'context-file', 'secondary-message', 'secondary-span', 'secondary-file', 'notes', 'help'] as $variant) {
                    $annotations = $context->issue->annotations;
                    foreach ($annotations as $position => $annotation) {
                        $primary = $annotation->kind === \Mago\Sdk\Reporting\AnnotationKind::Primary;
                        if (!$primary && !str_starts_with($variant, 'secondary-')) { continue; }
                        if ($primary && str_starts_with($variant, 'secondary-')) { continue; }
                        $annotations[$position] = new \Mago\Sdk\Reporting\Annotation(
                            $variant === 'kind' ? \Mago\Sdk\Reporting\AnnotationKind::Secondary : $annotation->kind,
                            new \Mago\Sdk\Span($annotation->span->start + ($variant === 'span-start' || $variant === 'secondary-span' ? 1 : 0), $annotation->span->end + ($variant === 'span-end' ? 1 : 0)),
                            in_array($variant, ['primary-message', 'secondary-message'], true) ? 'Unknown shape annotation.' : $annotation->message,
                            $variant === 'foreign' || $variant === 'secondary-file' ? 'other.php' : $annotation->file);
                        if ($variant === 'duplicate') { $annotations[] = $annotation; }
                        break;
                    }
                    $issue = new \Mago\Sdk\Reporting\ReportedIssue($context->issue->level,
                        $variant === 'code' ? 'invalid-argument' : $context->issue->code,
                        $variant === 'message' ? 'Unknown shape issue.' : $context->issue->message,
                        $variant === 'notes' ? [] : $context->issue->notes, $variant === 'help' ? 'Different declared contract.' : $context->issue->help, $context->issue->link, $annotations, $context->issue->edits);
                    $changed = new \Mago\Sdk\Analyzer\IssueFilterContext($context->phpVersion, $context->codebase, $context->types, $context->cancellation,
                        $variant === 'context-file' ? 'other.php' : $context->file,
                        $variant === 'context-source' ? str_replace('entry', 'other', $context->contents) : $context->contents, $issue);
                    if ($this->filter->filterIssue($changed) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Shape context accepted '.$variant); }
                    $checks++;
                }
                $bytes = file_get_contents($this->root.'/proof.php');
                file_put_contents($this->root.'/proof.php', str_replace('entry', 'other', $bytes));
                try {
                    if ($this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Shape context accepted changed disk bytes.'); }
                    $checks++;
                } finally { file_put_contents($this->root.'/proof.php', $bytes); }
                $targets = [
                    'caller' => $context->codebase->getMethod('ShapeProof', 'build'),
                    'constructor' => $context->codebase->getMethod('ShapeProof', '__construct'),
                    'sibling' => $context->codebase->getMethod('ShapeProof', 'inspect'),
                    'native array' => $context->codebase->getFunction('is_array'),
                    'native membership' => $context->codebase->getFunction('in_array'),
                    'native integer' => $context->codebase->getFunction('is_int'),
                ];
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                foreach ($targets as $role => $metadata) {
                    $variants = in_array($role, ['caller', 'constructor'], true)
                        ? ['file', 'identifier', 'identifier-class', 'name-span', 'name-file', 'body-span', 'body-start', 'reference', 'parameter-name', 'parameter-reference', 'parameter-variadic', 'parameter-span', 'parameter-name-file', 'parameter-file', 'parameter-body-span', 'kind', 'static', 'method-role']
                        : ['builtin', 'reference', 'parameter-reference'];
                    if ($role === 'sibling') { $variants = ['identifier', 'identifier-class', 'kind', 'builtin-flag', 'user-defined-flag']; }
                    if ($role === 'constructor') { $variants[] = 'parameter-type'; }
                    foreach ($variants as $variant) {
                        $values = get_object_vars($metadata);
                        if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('other.php', $metadata->location->span); }
                        if ($variant === 'identifier') { $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($metadata->identifier->kind, 'other', $metadata->identifier->class); }
                        if ($variant === 'identifier-class') { $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($metadata->identifier->kind, $metadata->identifier->name, 'OtherOwner'); }
                        if ($variant === 'name-span') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation($metadata->nameLocation->file, new \Mago\Sdk\Span($metadata->nameLocation->span->start + 1, $metadata->nameLocation->span->end)); }
                        if ($variant === 'body-span') { $values['location'] = new \Mago\Sdk\SourceLocation($metadata->location->file, new \Mago\Sdk\Span($metadata->location->span->start, $metadata->location->span->end - 1)); }
                        if ($variant === 'body-start') { $values['location'] = new \Mago\Sdk\SourceLocation($metadata->location->file, new \Mago\Sdk\Span(0, $metadata->location->span->end)); }
                        if ($variant === 'name-file') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation('other.php', $metadata->nameLocation->span); }
                        if ($variant === 'kind') { $values['kind'] = \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Closure; }
                        if ($variant === 'static') { $values['static'] = !$metadata->static; }
                        if ($variant === 'method-role') { $values['constructor'] = !$metadata->constructor; }
                        if ($variant === 'reference') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                        if ($variant === 'builtin') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                        if ($variant === 'builtin-flag') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                        if ($variant === 'user-defined-flag') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::USER_DEFINED); }
                        if (str_starts_with($variant, 'parameter-')) {
                            $parameter = get_object_vars($metadata->parameters[0]);
                            if ($variant === 'parameter-name') { $parameter['name'] = '$other'; }
                            if ($variant === 'parameter-reference') { $parameter['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter['flags']->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                            if ($variant === 'parameter-variadic') { $parameter['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter['flags']->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC); }
                            if ($variant === 'parameter-span') { $parameter['nameLocation'] = new \Mago\Sdk\SourceLocation($parameter['nameLocation']->file, new \Mago\Sdk\Span($parameter['nameLocation']->span->start + 1, $parameter['nameLocation']->span->end)); }
                            if ($variant === 'parameter-name-file') { $parameter['nameLocation'] = new \Mago\Sdk\SourceLocation('other.php', $parameter['nameLocation']->span); }
                            if ($variant === 'parameter-file') { $parameter['location'] = new \Mago\Sdk\SourceLocation('other.php', $parameter['location']->span); }
                            if ($variant === 'parameter-body-span') { $parameter['location'] = new \Mago\Sdk\SourceLocation($parameter['location']->file, new \Mago\Sdk\Span($parameter['location']->span->start + 1, $parameter['location']->span->end)); }
                            if ($variant === 'parameter-type') {
                                $type = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter['type']->location, \Mago\Sdk\Analyzer\Type::string(), true, false);
                                $parameter['type'] = $type;
                                $parameter['declaredType'] = $type;
                            }
                            $values['parameters'][0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$parameter);
                        }
                        $changed = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                        $replaced = 0;
                        foreach ($snapshot as $operation => $entries) {
                            foreach ($entries as $key => $entry) {
                                if ($entry === $metadata) { $cache->values[$operation][$key] = $changed; $replaced++; }
                            }
                        }
                        try {
                            if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Shape metadata accepted '.$role.' '.$variant); }
                            $checks++;
                        } finally { $cache->values = $snapshot; }
                    }
                }
                $classMetadata = $context->codebase->getClass('ShapeProof');
                $classSnapshot = $cache->values;
                foreach (['name', 'file', 'name-file', 'name-span', 'body-span', 'body-start'] as $variant) {
                    $values = get_object_vars($classMetadata);
                    if ($variant === 'name') { $values['name'] = 'OtherOwner'; }
                    if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('other.php', $classMetadata->location->span); }
                    if ($variant === 'name-file') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation('other.php', $classMetadata->nameLocation->span); }
                    if ($variant === 'name-span') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation($classMetadata->nameLocation->file, new \Mago\Sdk\Span($classMetadata->nameLocation->span->start + 1, $classMetadata->nameLocation->span->end)); }
                    if ($variant === 'body-span') { $values['location'] = new \Mago\Sdk\SourceLocation($classMetadata->location->file, new \Mago\Sdk\Span($classMetadata->location->span->start, $classMetadata->location->span->end - 1)); }
                    if ($variant === 'body-start') { $values['location'] = new \Mago\Sdk\SourceLocation($classMetadata->location->file, new \Mago\Sdk\Span(0, $classMetadata->location->span->end)); }
                    $changed = new \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$values);
                    $replaced = 0;
                    foreach ($classSnapshot as $operation => $entries) {
                        foreach ($entries as $key => $entry) {
                            if ($entry === $classMetadata) { $cache->values[$operation][$key] = $changed; $replaced++; }
                        }
                    }
                    try {
                        if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Shape class metadata accepted '.$variant); }
                        $checks++;
                    } finally { $cache->values = $classSnapshot; }
                }
                if ($this->filter->filterIssue($context) !== $result) { throw new \RuntimeException('Shape context controls mutated a valid proof.'); }
                file_put_contents($this->root.'/context-checks.log', $checks."\n", FILE_APPEND);
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/shape-context', name: 'Shape context', version: '1', analyzerPlugins: [$plugin])))->run();
PHP);

$analyze = static function (string $mode, array $paths = ['cases.php', 'shadows.php', 'aliases.php', 'native-aliases.php', 'dispatcher-aliases.php'], int $workers = 1) use ($workspace, $package): array {
    $configuration = $mode === 'external' ? $workspace.' external configuration' : $workspace;
    if (!is_dir($configuration)) { mkdir($configuration); }
    $host = $mode === 'native' ? new stdClass : ['fixture' => ['command' => $mode === 'integrated'
        ? [PHP_BINARY, '-d', 'opcache.enable_cli=0', $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]
        : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/'.($mode === 'contexts' ? 'proof-worker.php' : 'worker.php'), $package.'/vendor/autoload.php', $workspace, $mode === 'external' ? 'proven' : $mode], 'workers' => $workers]];
    file_put_contents($configuration.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $paths], 'extension-hosts' => $host,
    ], JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $name = $mode.'-'.$workers;
    $process = proc_open([...$command, '--workspace', $workspace, '--config', $configuration.'/mago.json', 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$name.'.json', 'w'], 2 => ['file', $workspace.'/'.$name.'.log', 'w'],
    ], $pipes, $configuration);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$name.'.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request|hook .* failed|parse error|worker .* exited/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    return json_decode(file_get_contents($workspace.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$signature = static function (array $issues): array {
    $result = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] !== 'Primary') { continue; }
            $result[] = [$issue['code'], $issue['message'], $annotation['span']['file_id']['name'], $annotation['span']['start']['offset'], $annotation['span']['end']['offset']];
            break;
        }
    }
    sort($result);
    return $result;
};
$group = static function (array $issues) use ($ranges, $signature): array {
    $grouped = [];
    foreach ($signature($issues) as $issue) {
        if ($issue[2] !== 'cases.php') { continue; }
        foreach ($ranges as $label => [$start, $end]) {
            if ($issue[3] >= $start && $issue[3] < $end) { $grouped[$label][] = $issue; break; }
        }
    }
    return $grouped;
};
$native = $analyze('native');
$control = $analyze('control');
if (in_array('--baseline', $argv, true)) {
    foreach (['native' => $native, 'control' => $control] as $mode => $reports) {
        $grouped = $group($reports);
        foreach ($cases as $label => [, $positive]) {
            echo $mode.' '.$label.': '.implode(',', array_column($grouped[$label] ?? [], 0))."\n";
        }
    }
    echo 'Reconstructed shape baseline: native='.count($native).', control='.count($control).', workspace='.$workspace.".\n";
    exit(0);
}
$standalone = $analyze('standalone');
$proven = $analyze('proven');
$corrected = 0;
$diagnosticNegatives = 0;
foreach ([[$native, $standalone], [$control, $proven]] as [$beforeReports, $afterReports]) {
    $before = $group($beforeReports);
    $after = $group($afterReports);
    foreach ($cases as $label => [, $positive]) {
        $original = $before[$label] ?? [];
        $actual = $after[$label] ?? [];
        if ($original === []) { throw new RuntimeException('Empty reconstructed shape baseline: '.$label.' '.$workspace); }
        if (!$positive) {
            if ($actual !== $original) { throw new RuntimeException('Unsafe reconstructed shape correction: '.$label.' '.json_encode([$original, $actual]).' '.$workspace); }
            if ($original !== []) { $diagnosticNegatives++; }
            continue;
        }
        $removed = array_values(array_filter($original, static fn (array $issue): bool => $issue[0] === 'less-specific-nested-argument-type'));
        $expected = array_values(array_filter($original, static fn (array $issue): bool => $issue[0] !== 'less-specific-nested-argument-type'));
        if (count($removed) !== 1 || $actual !== $expected) { throw new RuntimeException('Missing exact shape correction: '.$label.' '.json_encode([$original, $actual]).' '.$workspace); }
        $corrected++;
    }
    $beforeShadows = array_values(array_filter($signature($beforeReports), static fn (array $issue): bool => $issue[2] === 'shadows.php'));
    $afterShadows = array_values(array_filter($signature($afterReports), static fn (array $issue): bool => $issue[2] === 'shadows.php'));
    if ($beforeShadows === [] || $beforeShadows !== $afterShadows) { throw new RuntimeException('Native helper shadow correction changed diagnostics. '.$workspace); }
    $beforeDispatchers = array_values(array_filter($signature($beforeReports), static fn (array $issue): bool => $issue[2] === 'dispatcher-aliases.php'));
    $afterDispatchers = array_values(array_filter($signature($afterReports), static fn (array $issue): bool => $issue[2] === 'dispatcher-aliases.php'));
    if ($beforeDispatchers === [] || $beforeDispatchers !== $afterDispatchers) { throw new RuntimeException('Imported dispatcher correction changed diagnostics. '.$workspace); }
    foreach (['aliases.php', 'native-aliases.php'] as $file) {
        $beforeAlias = array_values(array_filter($signature($beforeReports), static fn (array $issue): bool => $issue[2] === $file));
        $afterAlias = array_values(array_filter($signature($afterReports), static fn (array $issue): bool => $issue[2] === $file));
        if (count(array_filter($beforeAlias, static fn (array $issue): bool => $issue[0] === 'less-specific-nested-argument-type')) !== 1
            || $afterAlias !== array_values(array_filter($beforeAlias, static fn (array $issue): bool => $issue[0] !== 'less-specific-nested-argument-type'))) {
            throw new RuntimeException('Declared or native shape alias correction differs: '.$file.' '.json_encode([$beforeAlias, $afterAlias]).' '.$workspace);
        }
        $corrected++;
    }
}
if ($signature($analyze('external')) !== $signature($proven)) { throw new RuntimeException('External configuration lost reconstructed shape source root. '.$workspace); }
$contextReports = $analyze('contexts', ['proof.php']);
$contextChecks = file_exists($workspace.'/context-checks.log') ? array_sum(array_map('intval', file($workspace.'/context-checks.log', FILE_IGNORE_NEW_LINES))) : 0;
if ($contextChecks !== 73 || in_array('less-specific-nested-argument-type', array_column($contextReports, 'code'), true)) { throw new RuntimeException('Missing real-Mago shape context controls: '.$contextChecks.' '.$workspace); }
if (in_array('--integrated', $argv, true)) {
    foreach ([1, 3] as $workers) {
        if ($signature($analyze('integrated', workers: $workers)) !== $signature($proven)) { throw new RuntimeException('Integrated reconstructed shape diagnostics differ: '.$workers.' workers. '.$workspace); }
    }
}
if (file_exists($workspace.'/executed')) { throw new RuntimeException('Analyzed bootstrap executed.'); }

file_put_contents($workspace.'/runtime-control.php', <<<'PHP'
<?php
declare(strict_types=1);
function rebuild(array $input, Closure $after): array {
    $record = $input['record'] ?? null;
    if (!is_array($record) || !in_array($record['mode'] ?? null, ['all', 'one'], true) || ($record['mode'] === 'one' && !is_int($record['entry'] ?? null))) { throw new RuntimeException; }
    $record = $record['mode'] === 'all' ? ['mode' => 'all'] : ['mode' => 'one', 'entry' => $record['entry']];
    $after();
    return $record;
}
function escape(array $input, Closure $after): array {
    $record = $input['record'] ?? null;
    if (!is_array($record) || !in_array($record['mode'] ?? null, ['all', 'one'], true) || ($record['mode'] === 'one' && !is_int($record['entry'] ?? null))) { throw new RuntimeException; }
    $record = $record['mode'] === 'all' ? ['mode' => 'all'] : ['mode' => 'one', 'entry' => $record['entry']];
    $after($record);
    return $record;
}
function indirectEscape(array $input): array {
    $record = $input['record'] ?? null;
    if (!is_array($record) || !in_array($record['mode'] ?? null, ['all', 'one'], true) || ($record['mode'] === 'one' && !is_int($record['entry'] ?? null))) { throw new RuntimeException; }
    $record = $record['mode'] === 'all' ? ['mode' => 'all'] : ['mode' => 'one', 'entry' => $record['entry']];
    call_user_func('extract', ['record' => ['mode' => 'one', 'entry' => 'changed indirectly']]);
    return $record;
}
function warningGuard(array $input): array {
    $record = $input['record'] ?? null;
    if (!is_array($record) || !in_array($record['mode'] ?? null, ['all', 'one'], true) || ($record['mode'] === 'one' && (!is_int($record['entry'] ?? null) || $missingFlag !== null))) { throw new RuntimeException; }
    return $record['mode'] === 'all' ? ['mode' => 'all'] : ['mode' => 'one', 'entry' => $record['entry']];
}
$entry = 7;
$input = ['record' => ['mode' => 'one', 'entry' => &$entry]];
$copied = rebuild($input, static function () use (&$entry): void { $entry = 'changed input'; });
$changedInput = $input['record']['entry'];
$entry = 7;
$escaped = escape($input, static function (array &$record): void { $record['entry'] = 'changed escaped record'; });
$indirect = indirectEscape($input);
$warningCount = 0;
set_error_handler(static function (int $level, string $message) use (&$entry, &$warningCount): bool { $entry = 'changed by warning'; $warningCount++; return true; });
try { $warning = warningGuard($input); } finally { restore_error_handler(); }
echo json_encode(['copied' => $copied, 'changedInput' => $changedInput, 'escaped' => $escaped, 'indirect' => $indirect, 'warning' => $warning, 'warningCount' => $warningCount], JSON_THROW_ON_ERROR);
PHP);
$process = proc_open([PHP_BINARY, $workspace.'/runtime-control.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workspace);
if (!is_resource($process)) { throw new RuntimeException('Cannot start standalone reconstructed shape control.'); }
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]); fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
$exit = proc_close($process);
$runtime = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
if ($exit !== 0 || $runtime['copied'] !== ['mode' => 'one', 'entry' => 7] || $runtime['changedInput'] !== 'changed input'
    || $runtime['escaped']['entry'] !== 'changed escaped record' || $runtime['indirect']['entry'] !== 'changed indirectly'
    || $runtime['warning']['entry'] !== 'changed by warning' || $runtime['warningCount'] !== 1) {
    throw new RuntimeException('Standalone reconstructed shape boundaries failed: '.$output.' '.$stderr);
}

require $package.'/vendor/autoload.php';
$scanChecks = (static function (string $root, string $source): int {
    mkdir($root);
    file_put_contents($root.'/proof.php', $source);
    $version = PHPVersion::fromParts(8, 5);
    $cancellation = new class implements CancellationTokenInterface {
        public function isCancelled(): bool { return false; }
        public function throwIfCancelled(): void {}
        public function subscribe(Closure $callback): int { return 0; }
        public function unsubscribe(int $subscription): void {}
    };
    $index = new ReconstructedArrayShapes($root);
    $file = static fn (string $path, string $contents): SourceFile => new SourceFile(
        $version, $path, $contents, [], new NodeStore([], '', 0),
        new ResolvedNameStore('', '', '', 0), new TriviaStore('', 0), null,
    );
    $scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $cancellation): void {
        $index->scan(new CodebaseScanContext($version, $cancellation, $files, $first, $last));
    };
    $checks = 0;
    $expect = static function (string $label, bool $actual) use (&$checks): void {
        if (!$actual) { throw new RuntimeException('Reconstructed shape scan failed: '.$label); }
        $checks++;
    };
    $host = $file('proof.php', $source);
    $expect('absent scan', $index->proofs('proof.php', $source) === []);
    $scan([$host], last: false);
    $expect('incomplete scan', $index->proofs('proof.php', $source) === []);
    $scan([], first: false);
    $proofs = $index->proofs('proof.php', $source);
    $expect('completed positive proof', count($proofs) === 1);
    $proof = $proofs[0];
    $expect('relative root current bytes', ReconstructedArrayShapes::current($proof));
    $expect('wrong source file', $index->proofs('other.php', $source) === []);
    $expect('wrong context bytes', $index->proofs('proof.php', $source."\n// changed source") === []);
    $changed = str_replace('entry', 'other', $source);
    $expect('equal length source control', $changed !== $source && strlen($changed) === strlen($source));
    file_put_contents($root.'/proof.php', $changed);
    try { $expect('changed current disk bytes', !ReconstructedArrayShapes::current($proof)); }
    finally { file_put_contents($root.'/proof.php', $source); }
    $expect('restored current bytes', ReconstructedArrayShapes::current($proof));
    $scan([$file('proof.php', $changed)]);
    $overlay = $index->proofs('proof.php', $changed);
    $expect('overlay independently indexed', count($overlay) === 1);
    $expect('overlay differs from disk', !ReconstructedArrayShapes::current($overlay[0]));
    $scan([$host, $host]);
    $expect('duplicate path veto', $index->proofs('proof.php', $source) === []);
    $scan([$host, $file('broken.php', '<?php function broken( {')]);
    $expect('failed parse veto', $index->proofs('proof.php', $source) === []);
    $scan([$host, $file('oversized.php', str_repeat(' ', 2_000_001))]);
    $expect('oversized source veto', $index->proofs('proof.php', $source) === []);
    $scan([$host]);
    $expect('first batch resets veto', count($index->proofs('proof.php', $source)) === 1);
    $argument = $proof['argument'];
    $prefix = '<?php ';
    $collision = $prefix.str_repeat(' ', $argument->getStartFilePos() - strlen($prefix)).'$record;';
    $scan([$host, $file('unrelated.php', $collision)]);
    $expect('same-span foreign argument has no proof', $index->proofs('unrelated.php', $collision) === []);
    $expect('same-span foreign argument keeps original proof', count($index->proofs('proof.php', $source)) === 1);
    $index->initialize(new InitializationContext($version, $cancellation));
    $expect('initialization clears state', $index->proofs('proof.php', $source) === []);
    $scan([$host]);
    $expect('completed after initialization', count($index->proofs('proof.php', $source)) === 1);
    return $checks;
})($workspace.'/scan root', $proofSource);
echo 'Reconstructed shape checks passed: '.count($cases).' source cases, '.$corrected.' native/control corrections, '.$diagnosticNegatives.' negative diagnostic groups, '.$scanChecks.' scan and '.$contextChecks.' issue/metadata controls, 4 runtime boundaries'.(in_array('--integrated', $argv, true) ? ', one and three integrated workers' : '').".\n";
