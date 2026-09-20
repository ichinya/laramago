<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\BuiltinValidationRuleCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LiteralValidationDatabaseRules;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LiteralValidationRules;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeValidationDatabaseContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeValidationMethodContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\SchemaIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidationRuleDeclarations;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidationRuleNames;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Warn about absent literal rule names only when the effective rule set is asserted complete. */
final class ValidationRuleNamesHook implements MethodCallAnalysisHook, InitializationHook
{
    private const FACTORY = 'Illuminate\\Validation\\Factory';
    private const VALIDATOR = 'Illuminate\\Validation\\Validator';

    private ?ValidationRuleNames $names = null;
    private ?BuiltinValidationRuleCatalog $builtins = null;
    private ?NativeValidationMethodContract $native = null;
    private ?ValidationDatabaseCatalog $database = null;
    private ?SchemaIndex $schema = null;
    private ?bool $nativeDatabase = null;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->names = null;
        $this->builtins = null;
        $this->native = null;
        $this->database = null;
        $this->schema = null;
        $this->nativeDatabase = null;
        $this->sourceHash = '';
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::FACTORY, 'make'),
            MethodTarget::exact(self::FACTORY, 'validate'),
            MethodTarget::exact(self::VALIDATOR, 'setRules'),
            MethodTarget::exact(self::VALIDATOR, 'addRules'),
            MethodTarget::exact(self::VALIDATOR, 'sometimes'),
        ];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $names = $this->names ??= new ValidationRuleNames($this->root);
        $database = $this->database ??= new ValidationDatabaseCatalog($this->root);
        if (! $names->complete() && ! $database->enabled()) {
            return;
        }
        $receiver = $context->receiverType?->atomicTypes[0] ?? null;
        if (
            $context->receiverType === null
            || count($context->receiverType->atomicTypes) !== 1
            || ! $receiver instanceof NamedObjectType
            || ! in_array($receiver->name, [self::FACTORY, self::VALIDATOR], true)
        ) {
            return;
        }
        $call = $this->call($context);
        if ($call === null || ! $call->name instanceof Node\Identifier) {
            return;
        }
        $methodName = $call->name->toString();
        $method = $context->codebase->getDeclaringMethod($receiver->name, $methodName);
        $parameters = $receiver->name === self::FACTORY
            ? ['$data', '$rules', '$messages', '$attributes']
            : match (strtolower($methodName)) {
                'sometimes' => ['$attribute', '$rules', '$callback'],
                default => ['$rules'],
            };
        if (
            $method === null
            || strcasecmp($method->identifier->class ?? '', $receiver->name) !== 0
            || $method->static
            || $method->visibility !== Visibility::Public
            || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || array_map(static fn ($parameter): string => $parameter->name, $method->parameters) !== $parameters
            || ! ($this->builtins ??= new BuiltinValidationRuleCatalog($this->root))->containsMethodSource(
                $receiver->name,
                $method->location->file ?? '',
            )
            || ! ($this->native ??= new NativeValidationMethodContract($this->root))->matches(
                $receiver->name,
                $methodName,
                $method->location->file ?? '',
            )
        ) {
            return;
        }
        foreach ($method->parameters as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE)) {
                return;
            }
        }
        $rules = ValidationRuleDeclarations::callRules($call, $receiver->name);
        if ($rules === null) {
            return;
        }
        $literals = strtolower($methodName) === 'sometimes'
            ? LiteralValidationRules::fromValue($rules)
            : LiteralValidationRules::from($rules);
        if ($names->complete()) {
            $builtins = $this->builtins;
            foreach ($literals as [$name, $literal]) {
                if ($names->contains($name) || $builtins->methodFor($name) !== null) {
                    continue;
                }
                $this->report(
                    $context,
                    'laramago-unknown-validation-rule',
                    'Validation rule "'.$name.'" is absent from the explicitly complete effective rule-name catalog.',
                    $literal,
                );
            }
        }
        if (! $database->enabled()) {
            return;
        }
        if ($this->nativeDatabase === null) {
            $this->nativeDatabase = (new NativeValidationDatabaseContract($this->root))->matches();
        }
        if (! $this->nativeDatabase) {
            return;
        }
        $references = strtolower($methodName) === 'sometimes'
            ? LiteralValidationDatabaseRules::fromValue($rules, '')
            : LiteralValidationDatabaseRules::from($rules);
        foreach ($references as [$table, $column, $ignore, $tableNode, $ignoreNode]) {
            $connection = 'default';
            if (str_contains($table, '.')) {
                [$connection, $table] = explode('.', $table, 2);
            }
            // A class string can resolve a model table and connection at runtime.
            if (str_contains($table, '\\')) {
                continue;
            }
            if ($table === '' || $connection === '' || str_contains($table, '*')) {
                continue;
            }
            if ($database->table($connection, $table) === false) {
                $this->report(
                    $context,
                    'laramago-unknown-validation-table',
                    'Validation table "'
                    .$table
                    .'" is absent from the explicitly complete "'
                    .$connection
                    .'" connection catalog.',
                    $tableNode,
                );
                continue;
            }
            if (
                $column !== ''
                && $database->column($connection, $table, $column) === false
                && ! $this->schemaColumn($connection, $table, $column)
            ) {
                $this->report(
                    $context,
                    'laramago-unknown-validation-column',
                    'Validation column "'
                    .$column
                    .'" is absent from the explicitly complete "'
                    .$table
                    .'" table catalog.',
                    $tableNode,
                );
            }
            if (
                $ignore !== null
                && $database->column($connection, $table, $ignore) === false
                && ! $this->schemaColumn($connection, $table, $ignore)
            ) {
                $this->report(
                    $context,
                    'laramago-unknown-validation-column',
                    'Validation ignore column "'
                    .$ignore
                    .'" is absent from the explicitly complete "'
                    .$table
                    .'" table catalog.',
                    $ignoreNode ?? $tableNode,
                );
            }
        }
    }

    private function schemaColumn(string $connection, string $table, string $column): bool
    {
        if ($connection !== 'default') {
            return false;
        }
        if ($this->schema === null) {
            $source = new PhpSource($this->root);
            $this->schema = new SchemaIndex($source);
            $this->schema->load();
        }

        return $this->schema->column($table, $column) !== null;
    }

    private function report(NodeAnalysisContext $context, string $code, string $message, Node $node): void
    {
        $context->report(Level::Warning, $code, Issue::at($message, new SourceLocation(
            $context->source->path,
            new Span($node->getStartFilePos(), $node->getEndFilePos() + 1),
        )));
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\MethodCall
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
