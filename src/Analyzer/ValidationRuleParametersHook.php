<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\BuiltinValidationRuleCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LiteralValidationRules;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeValidationMethodContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidationRuleDeclarations;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidationRuleParameters;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Opt-in warning for literal rules with a source-proven native minimum. */
final class ValidationRuleParametersHook implements MethodCallAnalysisHook, InitializationHook
{
    private const FACTORY = 'Illuminate\\Validation\\Factory';
    private const VALIDATOR = 'Illuminate\\Validation\\Validator';

    private ?bool $enabled = null;
    private ?ValidationRuleParameters $parameters = null;
    private ?BuiltinValidationRuleCatalog $builtins = null;
    private ?NativeValidationMethodContract $native = null;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->enabled = null;
        $this->parameters = null;
        $this->builtins = null;
        $this->native = null;
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
        $enabled = $this->enabled;
        if ($enabled === null) {
            $enabled = $this->enabled = $this->nativeAsserted();
        }
        if (! $enabled) {
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
        if (
            $method === null
            || strcasecmp($method->identifier->class ?? '', $receiver->name) !== 0
            || $method->static
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
        $rules = ValidationRuleDeclarations::callRules($call, $receiver->name);
        if ($rules === null) {
            return;
        }
        $parameters = $this->parameters ??= new ValidationRuleParameters($this->builtins);
        $literals = strcasecmp($methodName, 'sometimes') === 0
            ? LiteralValidationRules::fromValue($rules)
            : LiteralValidationRules::from($rules);
        $pipelines = [];
        if (strcasecmp($methodName, 'sometimes') === 0) {
            if ($rules instanceof Node\Scalar\String_) {
                $pipelines[spl_object_id($rules)] = true;
            }
        } elseif ($rules instanceof Node\Expr\Array_) {
            foreach ($rules->items as $field) {
                if ($field->value instanceof Node\Scalar\String_) {
                    $pipelines[spl_object_id($field->value)] = true;
                }
            }
        }
        $seen = [];
        foreach ($literals as [$name, $literal]) {
            $id = spl_object_id($literal);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $segments = isset($pipelines[$id]) ? explode('|', $literal->value) : [$literal->value];
            // Regex splitting belongs to the separate regex analyzer.
            if (preg_match('/(?:^|\|)\s*(?:regex|not[_-]?regex)\s*(?::|\||$)/i', $literal->value)) {
                continue;
            }
            foreach ($segments as $segment) {
                $missing = $parameters->missing(trim($segment));
                if ($missing === null) {
                    continue;
                }
                $context->report(
                    Level::Warning,
                    'laramago-validation-rule-parameters',
                    Issue::at(
                        'Native validation rule "'
                        .trim(explode(':', $segment, 2)[0])
                        .'" requires at least '
                        .$missing['required']
                        .' parameters when run; '
                        .$missing['provided']
                        .' provided.',
                        new SourceLocation(
                            $context->source->path,
                            new Span($literal->getStartFilePos(), $literal->getEndFilePos() + 1),
                        ),
                    ),
                );
            }
        }
    }

    private function nativeAsserted(): bool
    {
        $json = @file_get_contents(rtrim($this->root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $json === false ? null : json_decode($json, true);

        return (
            is_array($composer)
            && ($composer['extra']['laramago']['validation-rule-parameters']['native'] ?? null) === true
        );
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
