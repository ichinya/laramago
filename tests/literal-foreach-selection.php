<?php

declare(strict_types=1);

// Analyze synthetic declarations only. Application autoloading is never executed.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago literal selection '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
$nativeRoot = '/vendor/laravel/framework/src/';
$factoryFixtures = [
    'Factory' => 'Illuminate/Database/Eloquent/Factories/Factory.php',
    'HasFactory' => 'Illuminate/Database/Eloquent/Factories/HasFactory.php',
    'GuardsAttributes' => 'Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
    'helpers' => 'Illuminate/Support/helpers.php',
];
foreach ($factoryFixtures as $name => $path) {
    if (! is_dir(dirname($workspace.$nativeRoot.$path))) { mkdir(dirname($workspace.$nativeRoot.$path), recursive: true); }
    copy(__DIR__.'/fixtures/analysis/factory-result-'.$name.'.php.stub', $workspace.$nativeRoot.$path);
}
file_put_contents($workspace.'/types.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent {
    class Model { use \Illuminate\Database\Eloquent\Concerns\GuardsAttributes; }
    /** @template TKey of array-key @template TModel of Model */ class Collection {}
}
namespace Illuminate\Support {
    /** @template TKey of array-key @template TValue */ class Collection {}
}
namespace Illuminate\Support\Traits {
    trait Conditionable {}
    trait ForwardsCalls {}
    trait Macroable { public function __call(string $method, array $parameters): mixed {} }
}
namespace App\Models {
    class Record extends \Illuminate\Database\Eloquent\Model {
        /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<\Database\Factories\RecordFactory> */
        use \Illuminate\Database\Eloquent\Factories\HasFactory;
    }
    class OverriddenRecord extends Record {
        public static function factory(): ?\Database\Factories\RecordFactory { return null; }
    }
    class OtherRecord extends \Illuminate\Database\Eloquent\Model {}
}
namespace Database\Factories {
    /** @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Record> */
    class RecordFactory extends \Illuminate\Database\Eloquent\Factories\Factory {
        public function definition(): array { return []; }
    }
}
namespace {
    function buildNativeRecord(): \App\Models\Record { return new \App\Models\Record(); }
    throw new \RuntimeException('Application fixtures must never be executed.');
}
PHP);
$guardWorker = <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('literal-selection-fixture', 'Literal selection fixture', 'Independent literal loop return proof');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $filter = new \Ichinya\Laramago\Analyzer\LiteralForeachSelectionReturnFilter($this->root);
        $registry->registerInitializationHook($filter);
        $registry->registerCodebaseScanHook($filter);
        $registry->registerIssueFilterHook(new class($filter, $this->root) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            public function __construct(private \Ichinya\Laramago\Analyzer\LiteralForeachSelectionReturnFilter $filter, private string $root) {}
            // Corrupt frozen metadata only in this one-file, one-code worker. The SDK
            // may interleave hook requests while a host metadata query is pending.
            public function getCodes(): array { return ['nullable-return-statement']; }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                $result = $this->filter->filterIssue($context);
                if ($result !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { return $result; }
                $probe = new \Ichinya\Laramago\Analyzer\LiteralForeachSelectionReturnFilter($this->root);
                if ($probe->filterIssue($context) !== $result) { throw new \RuntimeException('Fresh proof disagrees with the registered filter.'); }
                foreach (['span', 'foreign', 'duplicate', 'missing-primary', 'primary-message', 'code', 'message', 'message-union',
                    'source-guard', 'source-return', 'source-producer', 'source-native-return', 'source-producer-native-return',
                    'source-parameter-name', 'context-file', 'source-syntax', 'source-size', 'message-size'] as $variant) {
                    $annotations = $context->issue->annotations;
                    foreach ($annotations as $index => $annotation) {
                        if ($annotation->kind !== \Mago\Sdk\Reporting\AnnotationKind::Primary) { continue; }
                        if ($variant === 'missing-primary') { unset($annotations[$index]); continue; }
                        $annotations[$index] = new \Mago\Sdk\Reporting\Annotation(
                            $annotation->kind,
                            new \Mago\Sdk\Span($annotation->span->start + ($variant === 'span' ? 1 : 0), $annotation->span->end),
                            $variant === 'primary-message' ? 'Unknown return annotation.' : $annotation->message,
                            $variant === 'foreign' ? 'other.php' : $annotation->file,
                        );
                        if ($variant === 'duplicate') { $annotations[] = $annotation; }
                    }
                    $contents = match ($variant) {
                        'source-guard' => str_replace('=== -3.25', '=== -7.25', $context->contents),
                        'source-return' => str_replace('return $selected;', 'return $candidate;', $context->contents),
                        'source-producer' => str_replace('produceRecord(', 'maybeRecord(', $context->contents),
                        'source-native-return' => str_replace('selectRecord(bool $flag = false): SelectedRecord', 'selectRecord(bool $flag = false): OtherRecord   ', $context->contents),
                        'source-producer-native-return' => str_replace('produceRecord(array $attributes = []): SelectedRecord', 'produceRecord(array $attributes = []): OtherRecord   ', $context->contents),
                        'source-parameter-name' => str_replace('selectRecord(bool $flag = false)', 'selectRecord(bool $flog = false)', $context->contents),
                        'source-syntax' => $context->contents.'(',
                        'source-size' => $context->contents.str_repeat(' ', 1024 * 1024),
                        default => $context->contents,
                    };
                    // The selected fixture makes each source mutation observable.
                    if (str_starts_with($variant, 'source-') && $contents === $context->contents) { continue; }
                    $issue = new \Mago\Sdk\Reporting\ReportedIssue(
                        $context->issue->level, $variant === 'code' ? 'less-specific-nested-return-statement' : $context->issue->code,
                        match ($variant) {
                            'message' => 'Unknown return diagnostic.',
                            'message-union' => str_replace('|null', '|OtherRecord|null', $context->issue->message),
                            'message-size' => str_repeat('x', 16385),
                            default => $context->issue->message,
                        },
                        $context->issue->notes, $context->issue->help, $context->issue->link, array_values($annotations), $context->issue->edits,
                    );
                    $changed = new \Mago\Sdk\Analyzer\IssueFilterContext(
                        $context->phpVersion, $context->codebase, $context->types, $context->cancellation,
                        $variant === 'context-file' ? '/different-file.php' : $context->file, $contents, $issue,
                    );
                    if ($probe->filterIssue($changed) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) {
                        throw new \RuntimeException('Unsafe source or issue identity accepted: '.$variant);
                    }
                }
                preg_match('/(?:for function|Function) `([^`]+)`/', $context->issue->message, $target);
                preg_match('/is declared to return `([^`]+)`.*inferred as `([^`]+)`/', $context->issue->message, $nativeTypes);
                $subclass = str_replace('SelectedRecord', 'ChildRecord', $nativeTypes[1]);
                $redundant = str_replace('|null', '|'.$subclass.'|null', $nativeTypes[2]);
                // A parent and its subclass have the same containment result as the
                // parent alone. Neither diagnostic may accept that larger raw union.
                foreach (['nullable-return-statement', 'invalid-return-statement'] as $code) {
                    $annotations = $context->issue->annotations;
                    foreach ($annotations as $index => $annotation) {
                        if ($annotation->kind !== \Mago\Sdk\Reporting\AnnotationKind::Primary) { continue; }
                        $annotations[$index] = new \Mago\Sdk\Reporting\Annotation(
                            $annotation->kind, $annotation->span,
                            $code === 'invalid-return-statement' ? 'This has type `'.$redundant.'`' : 'Nullable value returned here.',
                            $annotation->file,
                        );
                    }
                    $message = $code === 'invalid-return-statement'
                        ? 'Invalid return type for function `'.$target[1].'`: expected `'.$nativeTypes[1].'`, but found `'.$redundant.'`.'
                        : str_replace($nativeTypes[2], $redundant, $context->issue->message);
                    $issue = new \Mago\Sdk\Reporting\ReportedIssue(
                        $context->issue->level, $code, $message, $context->issue->notes, $context->issue->help,
                        $context->issue->link, $annotations, $context->issue->edits,
                    );
                    $changed = new \Mago\Sdk\Analyzer\IssueFilterContext(
                        $context->phpVersion, $context->codebase, $context->types, $context->cancellation,
                        $context->file, $context->contents, $issue,
                    );
                    if ($probe->filterIssue($changed) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) {
                        throw new \RuntimeException('Redundant subclass union accepted: '.$code);
                    }
                }
                $caller = str_contains($target[1], '::')
                    ? $context->codebase->getDeclaringMethod(...explode('::', $target[1], 2))
                    : $context->codebase->getFunction($target[1]);
                $producers = json_decode(file_get_contents($this->root.'/producers.json'), true, flags: JSON_THROW_ON_ERROR);
                $producer = $context->codebase->getFunction($producers[strtolower($target[1])]);
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                foreach (['caller' => $caller, 'producer' => $producer] as $role => $metadata) {
                    $variants = ['name-span', 'body-span', 'file', 'identifier', 'reference-return', 'native-return'];
                    if ($role === 'caller') {
                        $variants = [...$variants, 'parameter-count', 'parameter-name', 'parameter-span', 'parameter-reference'];
                    }
                    foreach ($variants as $variant) {
                        $values = get_object_vars($metadata);
                        if ($variant === 'name-span') {
                            $values['nameLocation'] = new \Mago\Sdk\SourceLocation($metadata->nameLocation->file,
                                new \Mago\Sdk\Span($metadata->nameLocation->span->start + 1, $metadata->nameLocation->span->end));
                        } elseif ($variant === 'body-span') {
                            $values['location'] = new \Mago\Sdk\SourceLocation($metadata->location->file,
                                new \Mago\Sdk\Span($metadata->location->span->start, $metadata->location->span->end - 1));
                        } elseif ($variant === 'file') {
                            $values['location'] = new \Mago\Sdk\SourceLocation('/different-file.php', $metadata->location->span);
                        } elseif ($variant === 'identifier') {
                            $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier(
                                $metadata->identifier->kind, 'unrelated', $metadata->identifier->class);
                        } elseif ($variant === 'reference-return') {
                            $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags(
                                $metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE);
                        } elseif ($variant === 'native-return') {
                            $values['declaredReturnType'] = null;
                        } elseif ($variant === 'parameter-count') {
                            $values['parameters'] = [];
                        } else {
                            $parameter = get_object_vars($metadata->parameters[0]);
                            if ($variant === 'parameter-name') { $parameter['name'] = '$other'; }
                            if ($variant === 'parameter-span') {
                                $location = $parameter['nameLocation'];
                                $parameter['nameLocation'] = new \Mago\Sdk\SourceLocation($location->file,
                                    new \Mago\Sdk\Span($location->span->start + 1, $location->span->end));
                            }
                            if ($variant === 'parameter-reference') {
                                $parameter['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags(
                                    $parameter['flags']->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE);
                            }
                            $values['parameters'][0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$parameter);
                        }
                        $changedMetadata = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                        $replaced = 0;
                        foreach ($snapshot as $operation => $entries) {
                            foreach ($entries as $key => $entry) {
                                if ($entry === $metadata) { $cache->values[$operation][$key] = $changedMetadata; $replaced++; }
                            }
                        }
                        try {
                            if ($replaced === 0 || $probe->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) {
                                throw new \RuntimeException('Stale '.$role.' metadata accepted for '.$target[1].': '.$variant.' ('.$replaced.' cache entries)');
                            }
                        } finally { $cache->values = $snapshot; }
                    }
                }
                if ($probe->filterIssue($context) !== $result || $this->filter->filterIssue($context) !== $result) {
                    throw new \RuntimeException('Changed cached source identity.');
                }
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'literal-selection-fixture', name: 'Literal selection fixture', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP;
file_put_contents($workspace.'/guard-worker.php', $guardWorker);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('literal-selection-fixture', 'Literal selection fixture', 'Independent literal loop return proof');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $filter = new \Ichinya\Laramago\Analyzer\LiteralForeachSelectionReturnFilter($this->root);
        $registry->registerInitializationHook($filter);
        $registry->registerCodebaseScanHook($filter);
        $registry->registerIssueFilterHook($filter);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'literal-selection-fixture', name: 'Literal selection fixture', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP);
file_put_contents($workspace.'/control-worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('literal-selection-control', 'Literal selection control', 'Existing Laravel policies without the tested rule');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        (new \Ichinya\Laramago\Analyzer\LaravelPlugin($this->root))->register($registry);
        $property = new \ReflectionProperty($registry, 'issueFilterHooks');
        $hooks = array_filter($property->getValue($registry), static fn ($hook): bool => ! $hook instanceof \Ichinya\Laramago\Analyzer\LiteralForeachSelectionReturnFilter);
        $property->setValue($registry, array_values($hooks));
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'literal-selection-control', name: 'Literal selection control', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP);
$loop = 'foreach ([12.5, -3.25, 8.0] as $index => $amount) { $candidate = produceRecord(); if ($amount === -3.25) { $selected = $candidate; } }';
$body = '$selected = null; '.$loop.' return $selected;';
// Every unsupported case retains its complete native diagnostic set.
$cases = [
    'native function' => [$body, true],
    'fully qualified producer' => [str_replace('produceRecord()', '\\%NS%\\produceRecord()', $body), true],
    'imported producer alias' => [str_replace('produceRecord()', 'build()', $body), true, 'use function %NS%\\produceRecord as build;'],
    'native class alias' => [$body, true, 'use %NS%\\SelectedRecord as RecordAlias;', 'RecordAlias'],
    'native method caller' => [$body, true],
    'no key variable' => [str_replace('$index => ', '', $body), true],
    'reversed strict comparison' => [str_replace('$amount === -3.25', '-3.25 === $amount', $body), true],
    'integer literal selection' => [str_replace(['[12.5, -3.25, 8.0]', '$amount === -3.25'], ['[12, -3, 8]', '$amount === -3'], $body), true],
    'string literal selection' => [str_replace(['[12.5, -3.25, 8.0]', '$amount === -3.25'], ['["red", "blue", "green"]', '$amount === "blue"'], $body), true],
    'boolean literal selection' => [str_replace(['[12.5, -3.25, 8.0]', '$amount === -3.25'], ['[false, true]', '$amount === true'], $body), true],
    'signed float literal' => [str_replace(['[12.5, -3.25, 8.0]', '$amount === -3.25'], ['[+12.5, -3.25, -8.0]', '$amount === -3.25'], $body), true],
    'fresh attributes and builtin formatting' => [str_replace('produceRecord()', 'produceRecord(["weight" => $amount, "label" => sprintf("row %d", $index + 1)])', $body), true],
    'imported builtin formatting' => [str_replace('produceRecord()', 'produceRecord(["weight" => $amount, "label" => format("row %d", $index + 1)])', $body), true, 'use function sprintf as format;'],
    'unrelated call after selection' => [str_replace('return $selected;', 'unrelated(); return $selected;', $body), true],
    'ordinary sentinel survives HTTP header local overwrite' => [str_replace('return $selected;', 'file_get_contents("http://127.0.0.1/unused"); return $selected;', $body), true],
    'HTTP response header sentinel can be overwritten implicitly' => [str_replace('$selected', '$http_response_header', str_replace('return $selected;', 'file_get_contents("http://127.0.0.1/unused"); return $selected;', $body)), false],
    'unrelated closure before selection' => ['$callback = static function (): void {}; '.$body, true],
    'wrong producer argument retained' => [str_replace('produceRecord()', 'produceRecord("wrong")', $body), true],
    'global producer fallback' => [str_replace('produceRecord()', 'buildNativeRecord()', $body), true, '', '\\App\\Models\\Record'],
    'namespace producer shadows global function' => [str_replace('produceRecord()', 'buildNativeRecord()', $body), false, 'function buildNativeRecord(): ?\\App\\Models\\Record { return null; }', '\\App\\Models\\Record'],
    'external namespace producer can use nullable global fallback' => [str_replace('produceRecord()', 'buildExternalRecord()', $body), false, '', '\\App\\Models\\Record'],
    // Native Mago retains the factory's collection union. Its existing Laravel
    // provider proves the uncounted model type before this rule sees the return.
    'factory create model' => [str_replace('produceRecord()', 'Record::factory()->create()', $body), 'integrated', 'use App\\Models\\Record;', '\\App\\Models\\Record'],
    'factory fresh attributes and formatting' => [str_replace('produceRecord()', 'Record::factory()->create(["weight" => $amount, "label" => sprintf("row %d", $index + 1)])', $body), 'integrated', 'use App\\Models\\Record;', '\\App\\Models\\Record'],
    'factory named fresh attributes' => [str_replace('produceRecord()', 'Record::factory()->create(attributes: ["weight" => $amount])', $body), 'integrated', 'use App\\Models\\Record;', '\\App\\Models\\Record'],
    'counted factory deferred' => [str_replace('produceRecord()', 'Record::factory(1)->create()', $body), false, 'use App\\Models\\Record;', '\\App\\Models\\Record'],
    'factory custom dispatch deferred' => [str_replace('produceRecord()', 'OverriddenRecord::factory()->create()', $body), false, 'use App\\Models\\OverriddenRecord;', '\\App\\Models\\Record'],
    'factory predecessor model resolver mutation' => ['Factory::guessModelNamesUsing(static fn (): string => \\App\\Models\\OtherRecord::class); '.str_replace('produceRecord()', 'Record::factory()->create()', $body), false, 'use App\\Models\\Record; use Illuminate\\Database\\Eloquent\\Factories\\Factory;', '\\App\\Models\\Record'],
    'wrong declared object' => [$body, false, '', 'OtherRecord'],
    'wrong documented object' => [$body, false, '', 'SelectedRecord', '/** @return OtherRecord */'],
    'wrong native with matching documented object' => [$body, false, '', 'OtherRecord', '/** @return SelectedRecord */'],
    'nullable native producer' => [str_replace('produceRecord()', 'maybeRecord()', $body), false],
    'mixed native producer' => [str_replace('produceRecord()', 'mixedRecord()', $body), false],
    'docs only producer' => [str_replace('produceRecord()', 'documentedRecord()', $body), false],
    'unknown producer' => [str_replace('produceRecord()', 'unknownRecord()', $body), false],
    'union object producer' => [str_replace('produceRecord()', 'unionRecord()', $body), false],
    'generic producer contract' => [str_replace('produceRecord()', 'genericRecord(new SelectedRecord())', $body), false],
    'empty literal loop' => [str_replace('[12.5, -3.25, 8.0]', '[]', $body), false],
    'dynamic array source' => [str_replace('[12.5, -3.25, 8.0]', '$values', $body), false],
    'dynamic array item' => [str_replace('[12.5, -3.25, 8.0]', '[12.5, $flag, 8.0]', $body), false],
    'duplicate explicit keys' => [str_replace('[12.5, -3.25, 8.0]', '["same" => -3.25, "same" => 8.0]', $body), false],
    'unique explicit keys' => [str_replace('[12.5, -3.25, 8.0]', '[4 => 12.5, 5 => -3.25, 6 => 8.0]', $body), false],
    'spread literal array' => [str_replace('[12.5, -3.25, 8.0]', '[...[12.5, -3.25], 8.0]', $body), false],
    'reference array item' => [str_replace('[12.5, -3.25, 8.0]', '[&$flag, -3.25, 8.0]', $body), false],
    'reference iteration' => [str_replace('=> $amount', '=> &$amount', $body), false],
    'strict integer float mismatch' => [str_replace(['[12.5, -3.25, 8.0]', '$amount === -3.25'], ['[1, 2, 3]', '$amount === 2.0'], $body), false],
    'selector missing from list' => [str_replace('=== -3.25', '=== -7.25', $body), false],
    'loose equality guard' => [str_replace('===', '==', $body), false],
    'nonfinite float literal' => [str_replace('[12.5, -3.25, 8.0]', '[1e999, -3.25, 8.0]', $body), false],
    'break before selection' => [str_replace('if ($amount', 'break; if ($amount', $body), false],
    'continue before selection' => [str_replace('if ($amount', 'continue; if ($amount', $body), false],
    'break after selection' => [str_replace('$selected = $candidate;', '$selected = $candidate; break;', $body), false],
    'return before selection' => [str_replace('if ($amount', 'if ($flag) { return $selected; } if ($amount', $body), false],
    'conditional literal loop' => ['$selected = null; if ($flag) {'.$loop.'} return $selected;', false],
    'nested iteration' => [str_replace('$candidate =', 'foreach ([1] as $nested) {} $candidate =', $body), false],
    'caught producer exception' => ['$selected = null; try {'.$loop.'} catch (\\Throwable) {} return $selected;', false],
    'else resets sentinel' => [str_replace('$selected = $candidate; }', '$selected = $candidate; } else { $selected = null; }', $body), false],
    'sentinel reset after loop' => [str_replace('return $selected;', '$selected = null; return $selected;', $body), false],
    'sentinel reference alias before loop' => [str_replace('$selected = null;', '$selected = null; $alias =& $selected;', $body), false],
    'sentinel reference alias after loop' => [str_replace('return $selected;', '$alias =& $selected; $alias = null; return $selected;', $body), false],
    'candidate reference alias' => [str_replace('if ($amount', '$alias =& $candidate; if ($amount', $body), false],
    'value reference alias' => [str_replace('if ($amount', '$alias =& $amount; if ($amount', $body), false],
    'sentinel captured by reference' => [str_replace('return $selected;', '$callback = static function () use (&$selected): void { $selected = null; }; $callback(); return $selected;', $body), false],
    'candidate captured before loop' => ['$callback = static function () use (&$candidate): void {}; '.$body, false],
    'amount captured before loop' => ['$callback = static function () use (&$amount): void {}; '.$body, false],
    'preexisting loop value' => ['$amount = new ResetOnDestruct(); '.$body, false],
    'preexisting loop key' => ['$index = new ResetOnDestruct(); '.$body, false],
    'preexisting candidate' => ['$candidate = new ResetOnDestruct(); '.$body, false],
    'preexisting sentinel' => ['$selected = new ResetOnDestruct(); '.$body, false],
    'loop value parameter' => [$body, false, '', 'SelectedRecord', '', 'float $amount = 0.0'],
    'loop key parameter' => [$body, false, '', 'SelectedRecord', '', 'int $index = 0'],
    'candidate parameter' => [$body, false, '', 'SelectedRecord', '', '?SelectedRecord $candidate = null'],
    'sentinel parameter' => [$body, false, '', 'SelectedRecord', '', '?SelectedRecord $selected = null'],
    'unrelated reference parameter' => [$body, false, '', 'SelectedRecord', '', 'bool &$flag'],
    'reference caller return' => [$body, false],
    'reference producer return' => [str_replace('produceRecord()', 'referenceRecord()', $body), false],
    'by reference amount producer' => [str_replace('produceRecord()', 'changeAmount($amount)', $body), false],
    'direct amount argument deferred' => [str_replace('produceRecord()', 'takeAmount($amount)', $body), false],
    'sentinel exposed after loop' => [str_replace('return $selected;', 'resetSelected($selected); return $selected;', $body), false],
    'dynamic local mutation' => [str_replace('return $selected;', '$name = "selected"; $$name = null; return $selected;', $body), false],
    'extract local aliases' => [str_replace('return $selected;', 'extract(["selected" => null]); return $selected;', $body), false],
    'imported extract local aliases' => [str_replace('return $selected;', 'hydrate(["selected" => null]); return $selected;', $body), false, 'use function extract as hydrate;'],
    'parse string local mutation' => [str_replace('return $selected;', 'parse_str("selected=", $selected); return $selected;', $body), false],
    'shadowed builtin formatting' => [str_replace('produceRecord()', 'produceRecord(["label" => sprintf("row %d", $index + 1)])', $body), false, 'function sprintf(string $format, int $index): string { return $format; }'],
    'assignment inside guard' => [str_replace('$candidate = produceRecord(); if ($amount === -3.25) { $selected = $candidate; }', 'if ($amount === -3.25) { $candidate = produceRecord(); $selected = $candidate; }', $body), false],
    'different returned local' => [str_replace('return $selected;', 'return $other;', $body), false],
    'additional nullable assignment' => [str_replace('$selected = $candidate;', '$selected = $flag ? $candidate : null;', $body), false],
];
$helpers = <<<'PHP'
class SelectedRecord {}
class ChildRecord extends SelectedRecord {}
class OtherRecord {}
class ResetOnDestruct { public function __destruct() {} }
/** @param array<string, mixed> $attributes */
function produceRecord(array $attributes = []): SelectedRecord { return new SelectedRecord(); }
function maybeRecord(): ?SelectedRecord { return null; }
function mixedRecord(): mixed { return null; }
/** @return SelectedRecord */
function documentedRecord() { return new SelectedRecord(); }
function unionRecord(): SelectedRecord|OtherRecord { return new OtherRecord(); }
/** @template T of SelectedRecord
 * @param T $record
 * @return T */
function genericRecord(SelectedRecord $record): SelectedRecord { return $record; }
function &referenceRecord(): SelectedRecord { $record = new SelectedRecord(); return $record; }
function changeAmount(float &$amount): SelectedRecord { $amount = 99.0; return new SelectedRecord(); }
function takeAmount(float $amount): SelectedRecord { return new SelectedRecord(); }
function resetSelected(?SelectedRecord &$selected): void { $selected = null; }
function unrelated(): void {}
PHP;
$files = $producers = [];
$configurationFile = null;
$external = "<?php\nnamespace { function buildExternalRecord(): ?\\App\\Models\\Record { return null; } }\n";
foreach ($cases as $name => $case) {
    [$code, $accepted] = $case;
    $index = count($files);
    $namespace = 'Fixture\\Case'.$index;
    $file = 'case-'.$index.'.php';
    if ($name === 'factory predecessor model resolver mutation') { $configurationFile = $file; }
    if ($name === 'external namespace producer can use nullable global fallback') {
        $external .= 'namespace '.$namespace.' { function buildExternalRecord(): \\App\\Models\\Record { return new \\App\\Models\\Record(); } }'."\n";
    }
    $extra = str_replace('%NS%', $namespace, $case[2] ?? '');
    $returnType = $case[3] ?? 'SelectedRecord';
    $doc = $case[4] ?? '';
    $parameters = $case[5] ?? 'bool $flag = false';
    // Unknown inputs are parameters so native analysis can retain their own diagnostics.
    if ($name === 'dynamic array source') { $parameters .= ', array $values = []'; }
    $reference = $name === 'reference caller return' ? '&' : '';
    $function = $doc.' function '.$reference.'selectRecord('.$parameters.'): '.$returnType.' {'.str_replace('%NS%', $namespace, $code).'}';
    $target = $namespace.'\\selectRecord';
    if ($name === 'native method caller') {
        $function = 'class Reader { public '.$function.' }';
        $target = $namespace.'\\Reader::selectRecord';
    }
    file_put_contents($workspace.'/'.$file, "<?php\nnamespace ".$namespace.";\n".$extra."\n".$helpers."\n".$function."\n");
    $files[$file] = [$name, $accepted];
    $producers[strtolower($target)] = $namespace.'\\produceRecord';
}
file_put_contents($workspace.'/producers.json', json_encode($producers, JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/external.php', $external);
$reports = [];
$includes = ['types.php', 'external.php', ...array_map(static fn (string $path): string => 'vendor/laravel/framework/src/'.$path, array_values($factoryFixtures))];
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$modes = in_array('--native', $argv, true) ? ['native'] : ['native', 'isolated', 'guards'];
if (in_array('--integrated', $argv, true)) { $modes = [...$modes, 'control', 'integrated']; }
$runs = [...$modes, ...array_map(static fn (string $mode): string => 'configuration-'.$mode, array_values(array_filter($modes, static fn (string $mode): bool => $mode !== 'guards')))];
foreach ($runs as $run) {
    $configuration = str_starts_with($run, 'configuration-');
    $mode = $configuration ? substr($run, strlen('configuration-')) : $run;
    // Visible configuration calls poison factory proofs across the scanned source.
    // Keep that counterexample in a separate analysis from the supported controls.
    $sourceFiles = $configuration ? [$configurationFile]
        : ($mode === 'guards' ? ['case-0.php'] : array_values(array_diff(array_keys($files), [$configurationFile])));
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $sourceFiles, 'includes' => $includes],
        'extension-hosts' => $mode === 'native' ? new stdClass : ['fixture' => [
            'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', match ($mode) {
                'integrated' => $package.'/bin/laramago-worker.php',
                'control' => $workspace.'/control-worker.php',
                'guards' => $workspace.'/guard-worker.php',
                default => $workspace.'/worker.php',
            }, $package.'/vendor/autoload.php', $workspace],
            'workers' => 1,
        ]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$run.'.json', 'w'], 2 => ['file', $workspace.'/'.$run.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$run.'.log');
    if ($exit !== 1 || preg_match('/failed|rejected request|parse error/i', $stderr)) {
        throw new RuntimeException('Mago '.$run.' failed: '.$stderr.' '.$workspace);
    }
    $reports[$mode] ??= [];
    foreach (json_decode(file_get_contents($workspace.'/'.$run.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'] as $issue) {
        if (str_contains($issue['code'], 'extension')) { throw new RuntimeException('Extension failure: '.json_encode($issue)); }
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary') {
                $reports[$mode][$annotation['span']['file_id']['name']][] = $issue;
                break;
            }
        }
    }
}
$normalize = static function (array $issues): array {
    $values = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues);
    sort($values);
    return $values;
};
$returnCodes = ['nullable-return-statement', 'invalid-return-statement'];
foreach ($files as $file => [$name, $accepted]) {
    $native = $reports['native'][$file] ?? [];
    if (($accepted || in_array($name, ['external namespace producer can use nullable global fallback', 'factory predecessor model resolver mutation', 'HTTP response header sentinel can be overwritten implicitly'], true)) && array_diff($returnCodes, array_column($native, 'code')) !== []) {
        throw new RuntimeException('Missing native return controls for '.$name.'; inspect '.$workspace);
    }
    if ($name === 'wrong producer argument retained' && ! in_array('invalid-argument', array_column($native, 'code'), true)) {
        throw new RuntimeException('Missing native argument control; inspect '.$workspace);
    }
    foreach ($modes as $mode) {
        if ($mode === 'control') { continue; }
        if ($mode === 'guards' && $file !== 'case-0.php') { continue; }
        $baseline = $mode === 'integrated' ? ($reports['control'][$file] ?? []) : $native;
        if (in_array($name, ['factory predecessor model resolver mutation', 'HTTP response header sentinel can be overwritten implicitly'], true)
            && array_diff($returnCodes, array_column($baseline, 'code')) !== []) {
            throw new RuntimeException('Missing '.$mode.' unsafe sentinel controls for '.$name.'; inspect '.$workspace);
        }
        $remove = $accepted === true || $accepted === 'integrated' && $mode === 'integrated';
        if ($remove && array_diff($returnCodes, array_column($baseline, 'code')) !== []) {
            throw new RuntimeException('Missing '.$mode.' return controls for '.$name.'; inspect '.$workspace);
        }
        $removedCodes = $mode === 'guards' ? ['nullable-return-statement'] : $returnCodes;
        $expected = $remove && $mode !== 'native'
            ? array_values(array_filter($baseline, static fn (array $issue): bool => ! in_array($issue['code'], $removedCodes, true))) : $baseline;
        if ($normalize($reports[$mode][$file] ?? []) !== $normalize($expected)) {
            throw new RuntimeException('Unexpected '.$mode.' delta for '.$name.'; inspect '.$workspace);
        }
        echo 'PASS: '.$mode.' '.$name.PHP_EOL;
    }
}
