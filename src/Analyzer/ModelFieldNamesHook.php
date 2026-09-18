<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Mago\Sdk\Analyzer\ClassLikeAnalysisHook;
use Mago\Sdk\Analyzer\ClassLikeTarget;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
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

/** Validate safe literal model-property names only against explicit complete catalogs. */
final class ModelFieldNamesHook implements ClassLikeAnalysisHook
{
    private const MODEL = ModelReflection::MODEL;
    private const HAS_ATTRIBUTES = 'Illuminate\\Database\\Eloquent\\Concerns\\HasAttributes';
    private const HIDES_ATTRIBUTES = 'Illuminate\\Database\\Eloquent\\Concerns\\HidesAttributes';

    private const NATIVE_FILLABLE_OWNERS = [
        self::MODEL,
        'Illuminate\\Database\\Eloquent\\Concerns\\GuardsAttributes',
    ];

    /** @var array<string, array{owners: list<string>, file: string, visibility: Visibility}> */
    private const NATIVE_DISPATCH = [
        'fill' => [
            'owners' => [self::MODEL],
            'file' => 'Illuminate/Database/Eloquent/Model.php',
            'visibility' => Visibility::Public,
        ],
        'getFillable' => [
            'owners' => self::NATIVE_FILLABLE_OWNERS,
            'file' => 'Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
            'visibility' => Visibility::Public,
        ],
        'isFillable' => [
            'owners' => self::NATIVE_FILLABLE_OWNERS,
            'file' => 'Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
            'visibility' => Visibility::Public,
        ],
        'fillableFromArray' => [
            'owners' => self::NATIVE_FILLABLE_OWNERS,
            'file' => 'Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
            'visibility' => Visibility::Protected,
        ],
    ];

    /** @var array<string, array{owners: list<string>, file: string, visibility: Visibility}> */
    private const NATIVE_GUARDED_DISPATCH = [
        ...self::NATIVE_DISPATCH,
        'getGuarded' => [
            'owners' => self::NATIVE_FILLABLE_OWNERS,
            'file' => 'Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
            'visibility' => Visibility::Public,
        ],
        'isGuarded' => [
            'owners' => self::NATIVE_FILLABLE_OWNERS,
            'file' => 'Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
            'visibility' => Visibility::Public,
        ],
    ];

    /** @var array<string, array{owners: list<string>, file: string, visibility: Visibility}> */
    private const NATIVE_HIDDEN_DISPATCH = [
        'toArray' => [
            'owners' => [self::MODEL],
            'file' => 'Illuminate/Database/Eloquent/Model.php',
            'visibility' => Visibility::Public,
        ],
        'attributesToArray' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Public,
        ],
        'relationsToArray' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Public,
        ],
        'getArrayableAttributes' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Protected,
        ],
        'getArrayableAppends' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Protected,
        ],
        'getArrayableRelations' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Protected,
        ],
        'getArrayableItems' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Protected,
        ],
        'getAppends' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Public,
        ],
        'getHidden' => [
            'owners' => [self::MODEL, self::HIDES_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HidesAttributes.php',
            'visibility' => Visibility::Public,
        ],
        'getVisible' => [
            'owners' => [self::MODEL, self::HIDES_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HidesAttributes.php',
            'visibility' => Visibility::Public,
        ],
    ];

    /** @var array<string, array{owners: list<string>, file: string, visibility: Visibility}> */
    private const NATIVE_APPENDS_DISPATCH = [
        'toArray' => [
            'owners' => [self::MODEL],
            'file' => 'Illuminate/Database/Eloquent/Model.php',
            'visibility' => Visibility::Public,
        ],
        'attributesToArray' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Public,
        ],
        'getArrayableAppends' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Protected,
        ],
        'getArrayableItems' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Protected,
        ],
        'getAppends' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Public,
        ],
        'mutateAttributeForArray' => [
            'owners' => [self::MODEL, self::HAS_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'visibility' => Visibility::Protected,
        ],
        'getHidden' => [
            'owners' => [self::MODEL, self::HIDES_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HidesAttributes.php',
            'visibility' => Visibility::Public,
        ],
        'getVisible' => [
            'owners' => [self::MODEL, self::HIDES_ATTRIBUTES],
            'file' => 'Illuminate/Database/Eloquent/Concerns/HidesAttributes.php',
            'visibility' => Visibility::Public,
        ],
    ];

    private readonly ModelFieldCatalog $fields;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Stmt\Class_> */
    private array $classes = [];

    public function __construct(string $projectRoot = '.')
    {
        $this->fields = new ModelFieldCatalog($projectRoot);
    }

    public function getTargets(): array
    {
        return [ClassLikeTarget::descendantsOf(ModelReflection::MODEL)];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $class = $this->classNode($context);
        $model = $class?->namespacedName?->toString();
        if ($class === null || $model === null || ! $this->fields->hasAny($model)) {
            return;
        }
        $metadata = $context->codebase->getClass($model);
        if ($metadata === null || $metadata->hasIncompleteHierarchy()) {
            return;
        }
        $nativeFillable = self::hasNativeDispatch($context, $model, self::NATIVE_DISPATCH);
        $nativeGuarded = self::hasNativeDispatch($context, $model, self::NATIVE_GUARDED_DISPATCH);
        $nativeHidden = self::hasNativeDispatch($context, $model, self::NATIVE_HIDDEN_DISPATCH);
        $nativeAppends = self::hasNativeDispatch($context, $model, self::NATIVE_APPENDS_DISPATCH);
        if (! $nativeFillable && ! $nativeGuarded && ! $nativeHidden && ! $nativeAppends) {
            return;
        }
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\Property) {
                continue;
            }
            foreach ($statement->props as $property) {
                $propertyName = $property->name->toString();
                $serializationFilter = $propertyName === 'hidden' || $propertyName === 'visible';
                $native = match ($propertyName) {
                    'fillable' => $nativeFillable,
                    'guarded' => $nativeGuarded,
                    'hidden', 'visible' => $nativeHidden,
                    'appends' => $nativeAppends,
                    default => null,
                };
                if (
                    $native !== true
                    || $statement->isPrivate()
                    || $statement->isStatic()
                    || ! $property->default instanceof Node\Expr\Array_
                    || array_filter(
                        $property->default->items,
                        static fn (Node\ArrayItem $item): bool => $item->unpack
                        || $item->key !== null,
                    ) !== []
                ) {
                    continue;
                }
                if ($propertyName === 'guarded' && self::isTotalGuard($property->default)) {
                    continue;
                }
                foreach ($property->default->items as $item) {
                    if ($item->unpack || ! $item->value instanceof Node\Scalar\String_) {
                        continue;
                    }
                    $name = $item->value->value;
                    // In a mixed array Laravel does not treat "*" as the total-guard sentinel.
                    // Defer that unusual literal itself while still checking ordinary names.
                    if ($propertyName === 'guarded' && $name === '*') {
                        continue;
                    }
                    $contains = match ($propertyName) {
                        'guarded' => $this->fields->containsCaseInsensitive($model, $name),
                        'hidden', 'visible' => $this->fields->containsSerializationKey($model, $name),
                        'appends' => $this->fields->containsAppendableKey($model, $name),
                        default => $this->fields->contains($model, $name),
                    };
                    if ($contains !== false) {
                        continue;
                    }
                    $context->report(
                        Level::Warning,
                        match (true) {
                            $propertyName === 'appends' => 'laramago-missing-model-appendable-key',
                            $serializationFilter => 'laramago-missing-model-serialization-key',
                            default => 'laramago-missing-model-field',
                        },
                        Issue::at(
                            ucfirst($propertyName).' name '.$name.' is absent from the complete '.match (true) {
                                $propertyName === 'appends' => 'appendable key',
                                $serializationFilter => 'serialization key',
                                default => 'field',
                            }
                            .' catalog for '
                            .$model
                            .'.',
                            new SourceLocation(
                                $context->source->path,
                                new Span($item->value->getStartFilePos(), $item->value->getEndFilePos() + 1),
                            ),
                        ),
                    );
                }
            }
        }
    }

    /**
     * @param array<string, array{owners: list<string>, file: string, visibility: Visibility}> $dispatch
     */
    private static function hasNativeDispatch(NodeAnalysisContext $context, string $model, array $dispatch): bool
    {
        foreach ($dispatch as $method => $contract) {
            $declaration = $context->codebase->getDeclaringMethod($model, $method);
            if (! self::nativeMethod($declaration, $contract)) {
                return false;
            }
        }

        return true;
    }

    private static function isTotalGuard(Node\Expr\Array_ $array): bool
    {
        $item = $array->items[0] ?? null;

        return (
            count($array->items) === 1
            && $item instanceof Node\ArrayItem
            && ! $item->unpack
            && $item->key === null
            && $item->value instanceof Node\Scalar\String_
            && $item->value->value === '*'
        );
    }

    /**
     * @param array{owners: list<string>, file: string, visibility: Visibility} $contract
     */
    private static function nativeMethod(?FunctionLikeMetadata $method, array $contract): bool
    {
        $owner = $method?->identifier->class;
        if (
            $method === null
            || $owner === null
            || $method->static
            || $method->visibility !== $contract['visibility']
            || ! in_array(strtolower($owner), array_map(strtolower(...), $contract['owners']), true)
        ) {
            return false;
        }
        $file = str_replace('\\', '/', $method->location->file ?? '');

        return str_ends_with($file, '/laravel/framework/src/'.$contract['file']);
    }

    private function classNode(NodeAnalysisContext $context): ?Node\Stmt\Class_
    {
        $hash = hash('sha256', $context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->classes = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $node) {
                if ($node->isAnonymous()) {
                    continue;
                }
                $this->classes[$node->getStartFilePos().':'.($node->getEndFilePos() + 1)] = $node;
            }
        }

        return $this->classes[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
