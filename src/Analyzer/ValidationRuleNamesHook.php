<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\BuiltinValidationRuleCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LiteralValidationRules;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeValidationMethodContract;
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
        if (! $names->complete()) {
            return;
        }
        $builtins = $this->builtins ??= new BuiltinValidationRuleCatalog($this->root);
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
            || ! str_ends_with(
                str_replace('\\', '/', $method->location->file ?? ''),
                '/laravel/framework/src/'.str_replace('\\', '/', $receiver->name).'.php',
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
        foreach ($literals as [$name, $literal]) {
            if ($names->contains($name) || $builtins->methodFor($name) !== null) {
                continue;
            }
            $context->report(
                Level::Warning,
                'laramago-unknown-validation-rule',
                Issue::at(
                    'Validation rule "'.$name.'" is absent from the explicitly complete effective rule-name catalog.',
                    new SourceLocation(
                        $context->source->path,
                        new Span($literal->getStartFilePos(), $literal->getEndFilePos() + 1),
                    ),
                ),
            );
        }
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
