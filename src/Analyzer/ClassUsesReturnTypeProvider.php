<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringKind;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use PhpParser\Node;

/** An existing object, or an autoloadable known class, has a defined trait list. */
final class ClassUsesReturnTypeProvider implements FunctionReturnTypeProvider, InitializationHook
{
    private ?PhpSource $source = null;
    /** @var array<string, bool> */
    private array $declarations = [];

    public function __construct(private readonly string $root = '.') {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->declarations = [];
    }

    public function getTargets(): array
    {
        return [FunctionTarget::exact('class_uses')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $function = $context->codebase->getFunction('class_uses');
        $subject = $call->getArgument(0, 'object_or_class');
        $autoload = $call->getArgument(1, 'autoload');
        if ($call->kind !== InvocationKind::Function || strcasecmp($call->name, 'class_uses') !== 0
            || $function === null || ! $function->flags->contains(MetadataFlags::BUILTIN)
            || $subject?->type === null || count($call->arguments) < 1 || count($call->arguments) > 2) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder
                || $argument->name !== null && ! in_array($argument->name, ['object_or_class', 'autoload'], true)) {
                return null;
            }
        }
        $loadsClasses = $autoload === null;
        if ($autoload !== null) {
            $boolean = count($autoload->type?->atomicTypes ?? []) === 1 ? $autoload->type->atomicTypes[0] : null;
            if (! $boolean instanceof ScalarType || $boolean->kind !== ScalarTypeKind::Boolean) {
                return null;
            }
            $loadsClasses = $boolean->refinement === true;
        }
        foreach ($subject->type->atomicTypes as $atom) {
            if ($atom instanceof AnyObjectType || $atom instanceof NamedObjectType) {
                continue;
            }
            $classString = $atom instanceof ScalarType && $atom->kind === ScalarTypeKind::ClassLikeString ? $atom->refinement : null;
            if (! $loadsClasses || ! $classString instanceof ClassLikeStringType || $classString->variant !== ClassLikeStringVariant::Literal
                || $classString->literal === null || ($class = $context->codebase->getClassLike($classString->literal)) === null
                || $class->hasIncompleteHierarchy() || strcasecmp($class->name, $classString->literal) !== 0
                || ! $this->unconditionalDeclaration($class)) {
                return null;
            }
        }
        $values = null;
        foreach ($function->returnType?->type->atomicTypes ?? [] as $atom) {
            if (($atom instanceof KeyedArrayType || $atom instanceof ListType) && $values === null) {
                $values = $atom instanceof ListType ? $atom->elementType : $atom->valueType;
            } elseif (! $atom instanceof ScalarType || $atom->kind !== ScalarTypeKind::Boolean || $atom->refinement !== false) {
                return null;
            }
        }
        $trait = count($values?->atomicTypes ?? []) === 1 ? $values->atomicTypes[0] : null;
        if (! $trait instanceof ScalarType || $trait->kind !== ScalarTypeKind::ClassLikeString
            || ! $trait->refinement instanceof ClassLikeStringType || $trait->refinement->kind !== ClassLikeStringKind::Trait) {
            return null;
        }
        // PHP returns trait names as both keys and values; its result is not a list.
        return Type::array(Type::string(), $values);
    }

    private function unconditionalDeclaration(ClassLikeMetadata $class): bool
    {
        if ($class->flags->contains(MetadataFlags::BUILTIN)) {
            return true;
        }
        $file = $class->location->file;
        $name = $class->nameLocation;
        if ($file === null || ! str_ends_with(strtolower($file), '.php') || $name === null || $name->file !== $file) {
            return false;
        }
        $key = strtolower($class->name)."\0".$file."\0".$class->location->span->start.':'.$class->location->span->end;
        if (array_key_exists($key, $this->declarations)) {
            return $this->declarations[$key];
        }
        $nodes = ($this->source ??= new PhpSource($this->root))->read($file) ?? [];
        $topLevel = [];
        foreach ($nodes as $node) {
            // Do not descend into functions, conditionals or declaration blocks.
            array_push($topLevel, ...($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node]));
        }
        foreach ($topLevel as $node) {
            if ($node instanceof Node\Stmt\ClassLike && $node->name !== null && isset($node->namespacedName)
                && strcasecmp($node->namespacedName->toString(), $class->name) === 0
                && match (true) {
                    $node instanceof Node\Stmt\Class_ => $class->kind === ClassLikeKind::Class_,
                    $node instanceof Node\Stmt\Interface_ => $class->kind === ClassLikeKind::Interface,
                    $node instanceof Node\Stmt\Trait_ => $class->kind === ClassLikeKind::Trait,
                    $node instanceof Node\Stmt\Enum_ => $class->kind === ClassLikeKind::Enum,
                    default => false,
                }
                && $node->name->getStartFilePos() === $name->span->start
                && $node->name->getEndFilePos() + 1 === $name->span->end
                && $node->getStartFilePos() >= $class->location->span->start
                && $node->getEndFilePos() + 1 <= $class->location->span->end) {
                return $this->declarations[$key] = true;
            }
        }
        return $this->declarations[$key] = false;
    }
}
