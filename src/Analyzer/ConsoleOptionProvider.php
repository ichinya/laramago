<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Infer literal console inputs from an unchanged Laravel command definition. */
final class ConsoleOptionProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const COMMAND = 'Illuminate\\Console\\Command';
    private const IO = 'Illuminate\\Console\\Concerns\\InteractsWithIO';
    private const PARSER = 'Illuminate\\Console\\Parser';
    private const INPUT_OPTION = 'Symfony\\Component\\Console\\Input\\InputOption';
    private const INPUT_ARGUMENT = 'Symfony\\Component\\Console\\Input\\InputArgument';
    private const INPUT = 'Symfony\\Component\\Console\\Input\\Input';
    private const RESERVED = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'];

    private PhpSource $source;
    /** @var array<string, bool> */
    private array $native = [];
    /** @var array<string, array{option: array<string, Type>, argument: array<string, Type>}|null> */
    private array $definitions = [];

    public function __construct(private readonly string $root)
    {
        $this->source = new PhpSource($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->source = new PhpSource($this->root);
        $this->native = [];
        $this->definitions = [];
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::COMMAND, 'option'),
            MethodTarget::exact(self::IO, 'option'),
            MethodTarget::exact(self::COMMAND, 'argument'),
            MethodTarget::exact(self::IO, 'argument'),
        ];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $method = strtolower($call->name);
        $receiver = $call->receiverType;
        $class = $receiver?->atomicTypes[0] ?? null;
        if (
            $call->kind !== InvocationKind::InstanceMethod
            || $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $class instanceof NamedObjectType
            || $class->name === self::COMMAND
            || ($class->parameters ?? []) !== []
            || ($class->intersections ?? []) !== []
            || count($call->arguments) !== 1
            || $call->arguments[0]->unpacked
            || $call->arguments[0]->placeholder
            || $call->arguments[0]->name !== null && $call->arguments[0]->name !== 'key'
            || ($name = $call->arguments[0]->type?->getLiteralString()) === null
            || $name === ''
            || ($method === 'option' && in_array($name, self::RESERVED, true))
            || ! in_array(strtolower(self::COMMAND), array_map(strtolower(...), $context->codebase->getClassAncestors($class->name)), true)
            || ! $this->native($context->codebase, $method)
        ) {
            return null;
        }
        $className = $class->name;
        if (! array_key_exists($className, $this->definitions)) {
            $this->definitions[$className] = $this->classDefinitions($context->codebase, $className);
        }

        return $this->definitions[$className][$method][$name] ?? null;
    }

    /** @return array{option: array<string, Type>, argument: array<string, Type>}|null */
    private function classDefinitions(Codebase $codebase, string $class): ?array
    {
        foreach ([
            '__construct' => self::COMMAND,
            'configureFromAttributes' => self::COMMAND,
            'configureUsingFluentDefinition' => self::COMMAND,
            'configureDefaults' => self::COMMAND,
            'configureIsolation' => self::COMMAND,
            'option' => self::IO,
            'argument' => self::IO,
        ] as $method => $expected) {
            $declaration = $codebase->getDeclaringMethod($class, $method);
            if ($declaration === null || strcasecmp($declaration->identifier->class ?? '', $expected) !== 0) {
                return null;
            }
        }
        $metadata = $codebase->getClassLike($class);
        $path = $metadata?->location->file;
        if (
            $path === null
            || str_starts_with($path, '@')
            || strcasecmp($metadata->directParentClass ?? '', self::COMMAND) !== 0
        ) {
            return null;
        }
        foreach ([...$metadata->pseudoMethods, ...$metadata->staticPseudoMethods] as $method) {
            if (in_array(strtolower($method), ['option', 'argument'], true)) {
                return null;
            }
        }
        foreach ($metadata->attributes as $attribute) {
            if (strcasecmp($attribute->name, 'Illuminate\\Console\\Attributes\\Signature') === 0) {
                return null;
            }
        }
        $classNode = null;
        foreach ((new NodeFinder)->findInstanceOf($this->source->read($path) ?? [], Node\Stmt\Class_::class) as $node) {
            if (strcasecmp($node->namespacedName?->toString() ?? '', $class) === 0) {
                $classNode = $node;
                break;
            }
        }
        if ($classNode === null) {
            return null;
        }
        $signature = null;
        foreach ($classNode->stmts as $statement) {
            if ($statement instanceof Node\Stmt\TraitUse) {
                return null;
            }
            if ($statement instanceof Node\Stmt\ClassMethod) {
                if (in_array(strtolower($statement->name->toString()), [
                    'getdefinition', 'setdefinition', 'addoption', 'addoptions', 'getoptions',
                    'addargument', 'addarguments', 'getarguments',
                    'bind', 'run', 'configure', 'specifyparameters',
                ], true)) {
                    return null;
                }
                foreach ((new NodeFinder)->findInstanceOf($statement->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
                    if ($call->name instanceof Node\Identifier && in_array(strtolower($call->name->toString()), [
                        'setoption', 'setargument', 'setdefinition', 'addoption', 'addoptions',
                        'addargument', 'addarguments', 'getdefinition',
                        'mergeapplicationdefinition', 'specifyparameters', 'configureusingfluentdefinition',
                    ], true)) {
                        return null;
                    }
                }
                foreach ((new NodeFinder)->findInstanceOf($statement->stmts ?? [], Node\Expr\Assign::class) as $assign) {
                    if (
                        $assign->var instanceof Node\Expr\PropertyFetch
                        && $assign->var->var instanceof Node\Expr\Variable
                        && $assign->var->var->name === 'this'
                        && $assign->var->name instanceof Node\Identifier
                        && in_array($assign->var->name->toString(), ['signature', 'input'], true)
                    ) {
                        return null;
                    }
                }
                foreach ((new NodeFinder)->findInstanceOf($statement->stmts ?? [], Node\Expr\PropertyFetch::class) as $fetch) {
                    if (
                        $fetch->var instanceof Node\Expr\Variable
                        && $fetch->var->name === 'this'
                        && $fetch->name instanceof Node\Identifier
                        && $fetch->name->toString() === 'input'
                    ) {
                        return null;
                    }
                }
            }
            if (! $statement instanceof Node\Stmt\Property) {
                continue;
            }
            foreach ($statement->props as $property) {
                if ($property->name->toString() === 'signature') {
                    if ($signature !== null || ! $property->default instanceof Node\Scalar\String_) {
                        return null;
                    }
                    $signature = $property->default->value;
                }
            }
        }
        if ($signature === null || ! preg_match('/^[^\s{}]+/', $signature)) {
            return null;
        }
        preg_match_all('/\{\s*(.*?)\s*\}/', $signature, $matches);
        $result = ['option' => [], 'argument' => []];
        foreach ($matches[1] as $token) {
            $token = preg_split('/\s+:\s+/', trim($token), 2)[0];
            if (! str_starts_with($token, '--')) {
                if (preg_match('/^([a-zA-Z][a-zA-Z0-9_-]*)(\?|\*|\?\*|=(?!\*)[^{}\s]+|=\*[^{}\s]+)?$/D', $token, $parts) !== 1) {
                    return null;
                }
                $name = $parts[1];
                if (isset($result['argument'][$name])) {
                    return null;
                }
                $suffix = $parts[2] ?? '';
                $result['argument'][$name] = match (true) {
                    $suffix === '?' => Type::union(Type::string(), Type::null()),
                    $suffix === '*' || $suffix === '?*' || str_starts_with($suffix, '=*') => Type::list(Type::string()),
                    default => Type::string(),
                };
                continue;
            }
            if (! preg_match('/^--([a-zA-Z][a-zA-Z0-9_-]*)(=)?$/D', $token, $parts)) {
                return null;
            }
            $name = $parts[1];
            if (isset($result['option'][$name])) {
                return null;
            }
            $result['option'][$name] = isset($parts[2]) ? Type::union(Type::string(), Type::null()) : Type::bool();
        }

        return $result;
    }

    private function native(Codebase $codebase, string $method): bool
    {
        if (isset($this->native[$method])) {
            return $this->native[$method];
        }
        $methods = [
            [self::COMMAND, '__construct', 'Console/Command.php'],
            [self::COMMAND, 'configureUsingFluentDefinition', 'Console/Command.php'],
            [self::COMMAND, 'configureDefaults', 'Console/Command.php'],
            [self::PARSER, 'parse', 'Console/Parser.php'],
            [self::PARSER, 'parameters', 'Console/Parser.php'],
            [self::PARSER, 'extractDescription', 'Console/Parser.php'],
        ];
        $methods = [...$methods, ...($method === 'argument' ? [
            [self::PARSER, 'parseArgument', 'Console/Parser.php'],
            [self::IO, 'argument', 'Console/Concerns/InteractsWithIO.php'],
            [self::INPUT_ARGUMENT, 'setDefault', 'Input/InputArgument.php'],
            [self::INPUT, 'getArgument', 'Input/Input.php'],
        ] : [
            [self::PARSER, 'parseOption', 'Console/Parser.php'],
            [self::IO, 'option', 'Console/Concerns/InteractsWithIO.php'],
            [self::INPUT_OPTION, 'setDefault', 'Input/InputOption.php'],
            [self::INPUT, 'getOption', 'Input/Input.php'],
        ])];
        foreach ($methods as [$class, $nativeMethod, $suffix]) {
            $metadata = $codebase->getDeclaringMethod($class, $nativeMethod);
            if (
                $metadata === null
                || strcasecmp($metadata->identifier->class ?? '', $class) !== 0
                || ! str_ends_with(
                    str_replace('\\', '/', $this->source->path($metadata->location->file ?? '')),
                    str_starts_with($class, 'Symfony\\')
                        ? '/symfony/console/'.$suffix
                        : '/laravel/framework/src/Illuminate/'.$suffix,
                )
            ) {
                return $this->native[$method] = false;
            }
        }
        $parser = $codebase->getDeclaringMethod(self::PARSER, 'parseOption');
        $parse = $codebase->getDeclaringMethod(self::PARSER, 'parse');
        $parameters = $codebase->getDeclaringMethod(self::PARSER, 'parameters');
        $extractDescription = $codebase->getDeclaringMethod(self::PARSER, 'extractDescription');
        $command = $codebase->getDeclaringMethod(self::COMMAND, 'configureUsingFluentDefinition');
        $constructor = $codebase->getDeclaringMethod(self::COMMAND, '__construct');
        $defaults = $codebase->getDeclaringMethod(self::COMMAND, 'configureDefaults');
        $option = $codebase->getDeclaringMethod(self::IO, 'option');
        $inputOption = $codebase->getDeclaringMethod(self::INPUT_OPTION, 'setDefault');
        $input = $codebase->getDeclaringMethod(self::INPUT, 'getOption');
        $parseArgument = $codebase->getDeclaringMethod(self::PARSER, 'parseArgument');
        $argument = $codebase->getDeclaringMethod(self::IO, 'argument');
        $inputArgument = $codebase->getDeclaringMethod(self::INPUT_ARGUMENT, 'setDefault');
        $getArgument = $codebase->getDeclaringMethod(self::INPUT, 'getArgument');
        $reflection = new ModelReflection($codebase, $this->source);
        $node = static fn ($method) => $method === null ? null : $reflection->methodNode($method);
        $printer = new Standard;
        $printed = static fn ($method): string => $node($method) === null ? '' : $printer->prettyPrint([$node($method)]);
        $parserText = $printed($parser);
        $parseText = $printed($parse);
        $parametersText = $printed($parameters);
        $descriptionText = $printed($extractDescription);
        $commandText = $printed($command);
        $constructorText = $printed($constructor);
        $optionText = $printed($option);
        $inputOptionText = $printed($inputOption);
        $inputText = $printed($input);
        $parseArgumentText = $printed($parseArgument);
        $argumentText = $printed($argument);
        $inputArgumentText = $printed($inputArgument);
        $getArgumentText = $printed($getArgument);
        $defaultsNode = $node($defaults);

        $common = str_contains($parseText, "preg_match_all('/\\{\\s*(.*?)\\s*\\}/', \$expression, \$matches)")
            && str_contains($parseText, 'static::parameters($matches[1])')
            && str_contains($commandText, 'Parser::parse($this->signature)')
            && str_contains($constructorText, '$this->configureFromAttributes()')
            && str_contains($constructorText, 'isset($this->signature)')
            && str_contains($constructorText, '$this->configureUsingFluentDefinition()')
            && str_contains($constructorText, '$this->configureDefaults()')
            && $defaultsNode !== null
            && (new NodeFinder)->findFirst($defaultsNode->stmts ?? [], static fn (Node $node): bool => ! $node instanceof Node\Stmt\Nop) === null;
        if (! $common) {
            return $this->native[$method] = false;
        }
        if ($method === 'argument') {
            return $this->native[$method] = str_contains($parametersText, "preg_match('/^-{2,}(.*)/', \$token, \$matches)")
                && str_contains($parametersText, 'static::parseOption($matches[1])')
                && str_contains($parametersText, 'static::parseArgument($token)')
                && str_contains($parseArgumentText, 'static::extractDescription($token)')
                && str_contains($descriptionText, "preg_split('/\\s+:\\s+/', trim(\$token), 2)")
                && self::nativeArgumentBranches($parseArgumentText)
                && str_contains($commandText, 'addArguments($arguments)')
                && str_contains($argumentText, '$this->input->getArgument($key)')
                && str_contains($inputArgumentText, '$this->isArray()')
                && str_contains($inputArgumentText, '$default = []')
                && str_contains($inputArgumentText, '$this->default = $default')
                && str_contains($getArgumentText, '$this->definition->getArgument($name)->getDefault()');
        }

        return $this->native[$method] = str_contains($parserText, "str_ends_with(\$token, '=')")
            && str_contains($parserText, 'InputOption::VALUE_OPTIONAL')
            && str_contains($parserText, 'InputOption::VALUE_NONE')
            && str_contains($commandText, 'addOptions($options)')
            && str_contains($optionText, '$this->input->getOption($key)')
            && str_contains($inputOptionText, '$this->acceptValue() || $this->isNegatable() ? $default : false')
            && str_contains($inputText, '$this->definition->getOption($name)->getDefault()');
    }

    private static function nativeArgumentBranches(string $source): bool
    {
        $source = str_replace('\\'.self::INPUT_ARGUMENT, 'InputArgument', $source);
        $branches = [
            "str_ends_with(\$token, '?*') => new InputArgument(trim(\$token, '?*'), InputArgument::IS_ARRAY",
            "str_ends_with(\$token, '*') => new InputArgument(trim(\$token, '*'), InputArgument::IS_ARRAY | InputArgument::REQUIRED",
            "str_ends_with(\$token, '?') => new InputArgument(trim(\$token, '?'), InputArgument::OPTIONAL",
            'new InputArgument($matches[1], InputArgument::IS_ARRAY',
            'new InputArgument($matches[1], InputArgument::OPTIONAL',
            'default => new InputArgument($token, InputArgument::REQUIRED',
        ];
        $position = -1;
        foreach ($branches as $branch) {
            $next = strpos($source, $branch, $position + 1);
            if ($next === false) {
                return false;
            }
            $position = $next;
        }

        return str_contains($source, "preg_match('/(.+)\\=\\*(.+)/', \$token, \$matches)")
            && str_contains($source, "preg_match('/(.+)\\=(.+)/', \$token, \$matches)");
    }
}
