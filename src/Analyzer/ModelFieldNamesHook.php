<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
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
    private const PIVOT = 'Illuminate\\Database\\Eloquent\\Relations\\Pivot';
    private const UNGUARDED = 'Illuminate\\Database\\Eloquent\\Attributes\\Unguarded';
    private const GUARDS_ATTRIBUTES = 'Illuminate\\Database\\Eloquent\\Concerns\\GuardsAttributes';
    private const HAS_ATTRIBUTES = 'Illuminate\\Database\\Eloquent\\Concerns\\HasAttributes';
    private const HIDES_ATTRIBUTES = 'Illuminate\\Database\\Eloquent\\Concerns\\HidesAttributes';

    private const NATIVE_FILLABLE_OWNERS = [
        self::MODEL,
        self::GUARDS_ATTRIBUTES,
    ];

    /**
     * @var array<string, array{
     *     class: string,
     *     initializerMethod: string,
     *     initializer: array{owners: list<string>, file: string, visibility: Visibility},
     * }>
     */
    private const FIELD_ATTRIBUTES = [
        'fillable' => [
            'class' => 'Illuminate\\Database\\Eloquent\\Attributes\\Fillable',
            'initializerMethod' => 'initializeGuardsAttributes',
            'initializer' => [
                'owners' => [self::GUARDS_ATTRIBUTES],
                'file' => 'Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
                'visibility' => Visibility::Public,
            ],
        ],
        'guarded' => [
            'class' => 'Illuminate\\Database\\Eloquent\\Attributes\\Guarded',
            'initializerMethod' => 'initializeGuardsAttributes',
            'initializer' => [
                'owners' => [self::GUARDS_ATTRIBUTES],
                'file' => 'Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
                'visibility' => Visibility::Public,
            ],
        ],
        'hidden' => [
            'class' => 'Illuminate\\Database\\Eloquent\\Attributes\\Hidden',
            'initializerMethod' => 'initializeHidesAttributes',
            'initializer' => [
                'owners' => [self::HIDES_ATTRIBUTES],
                'file' => 'Illuminate/Database/Eloquent/Concerns/HidesAttributes.php',
                'visibility' => Visibility::Public,
            ],
        ],
        'visible' => [
            'class' => 'Illuminate\\Database\\Eloquent\\Attributes\\Visible',
            'initializerMethod' => 'initializeHidesAttributes',
            'initializer' => [
                'owners' => [self::HIDES_ATTRIBUTES],
                'file' => 'Illuminate/Database/Eloquent/Concerns/HidesAttributes.php',
                'visibility' => Visibility::Public,
            ],
        ],
        'appends' => [
            'class' => 'Illuminate\\Database\\Eloquent\\Attributes\\Appends',
            'initializerMethod' => 'initializeHasAttributes',
            'initializer' => [
                'owners' => [self::HAS_ATTRIBUTES],
                'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
                'visibility' => Visibility::Protected,
            ],
        ],
    ];

    /** @var array<string, array<string, array{owners: list<string>, file: string, visibility: Visibility}>> */
    private const ATTRIBUTE_MUTATORS = [
        'fillable' => [
            'mergeFillable' => [
                'owners' => [self::GUARDS_ATTRIBUTES],
                'file' => 'Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
                'visibility' => Visibility::Public,
            ],
        ],
        'guarded' => [],
        'hidden' => [
            'mergeHidden' => [
                'owners' => [self::HIDES_ATTRIBUTES],
                'file' => 'Illuminate/Database/Eloquent/Concerns/HidesAttributes.php',
                'visibility' => Visibility::Public,
            ],
        ],
        'visible' => [
            'mergeVisible' => [
                'owners' => [self::HIDES_ATTRIBUTES],
                'file' => 'Illuminate/Database/Eloquent/Concerns/HidesAttributes.php',
                'visibility' => Visibility::Public,
            ],
        ],
        'appends' => [
            'mergeAppends' => [
                'owners' => [self::HAS_ATTRIBUTES],
                'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
                'visibility' => Visibility::Public,
            ],
            'setAppends' => [
                'owners' => [self::HAS_ATTRIBUTES],
                'file' => 'Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
                'visibility' => Visibility::Public,
            ],
        ],
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
    private readonly PhpSource $source;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Stmt\Class_> */
    private array $classes = [];

    public function __construct(string $projectRoot = '.')
    {
        $this->fields = new ModelFieldCatalog($projectRoot);
        $this->source = new PhpSource($projectRoot);
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
                    $this->validateName($context, $model, $propertyName, $item->value);
                }
            }
        }
        $native = [
            'fillable' => $nativeFillable,
            'guarded' => $nativeGuarded,
            'hidden' => $nativeHidden,
            'visible' => $nativeHidden,
            'appends' => $nativeAppends,
        ];
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $propertyName = self::fieldAttribute($attribute);
                if (
                    $propertyName === null
                    || ! $native[$propertyName]
                    || ! self::hasNativeAttributeSupport($context, $model, $propertyName)
                    || $propertyName === 'guarded'
                    && ! $this->guardedAttributeApplies($context, $model)
                ) {
                    continue;
                }
                $names = self::attributeNames($attribute);
                if ($names === null || $propertyName === 'guarded' && self::isTotalGuardNames($names)) {
                    continue;
                }
                foreach ($names as $name) {
                    $this->validateName($context, $model, $propertyName, $name);
                }
            }
        }
    }

    private function guardedAttributeApplies(NodeAnalysisContext $context, string $model): bool
    {
        $pivot = in_array(
            strtolower(self::PIVOT),
            array_map(strtolower(...), $context->codebase->getClassAncestors($model)),
            true,
        );
        $default = $pivot ? [] : ['*'];

        return (
            (new ModelReflection($context->codebase, $this->source))->default($model, 'guarded', $default) === $default
            && ! self::hasAttributeInHierarchy($context, $model, self::UNGUARDED)
        );
    }

    private static function hasAttributeInHierarchy(
        NodeAnalysisContext $context,
        string $model,
        string $attribute,
    ): bool {
        $traits = [];
        foreach ([$model, ...$context->codebase->getClassAncestors($model)] as $class) {
            $metadata = $context->codebase->getClass($class);
            if ($metadata === null) {
                return true;
            }
            foreach ($metadata->attributes as $candidate) {
                if (strcasecmp($candidate->name, $attribute) === 0) {
                    return true;
                }
            }
            $traits = [...$traits, ...$metadata->usedTraits];
        }
        $seen = [];
        while (($trait = array_pop($traits)) !== null) {
            $key = strtolower($trait);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $metadata = $context->codebase->getTrait($trait);
            if ($metadata === null) {
                return true;
            }
            foreach ($metadata->attributes as $candidate) {
                if (strcasecmp($candidate->name, $attribute) === 0) {
                    return true;
                }
            }
            $traits = [...$traits, ...$metadata->usedTraits];
        }

        return false;
    }

    private function validateName(
        NodeAnalysisContext $context,
        string $model,
        string $propertyName,
        Node\Scalar\String_ $literal,
    ): void {
        $name = $literal->value;
        // In a mixed array Laravel does not treat "*" as the total-guard sentinel.
        // Defer that unusual literal itself while still checking ordinary names.
        if ($propertyName === 'guarded' && $name === '*') {
            return;
        }
        $serializationFilter = $propertyName === 'hidden' || $propertyName === 'visible';
        $contains = match ($propertyName) {
            'guarded' => $this->fields->containsCaseInsensitive($model, $name),
            'hidden', 'visible' => $this->fields->containsSerializationKey($model, $name),
            'appends' => $this->fields->containsAppendableKey($model, $name),
            default => $this->fields->contains($model, $name),
        };
        if ($contains !== false) {
            return;
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
                    new Span($literal->getStartFilePos(), $literal->getEndFilePos() + 1),
                ),
            ),
        );
    }

    private static function fieldAttribute(Node\Attribute $attribute): ?string
    {
        foreach (self::FIELD_ATTRIBUTES as $property => $contract) {
            if (strcasecmp($attribute->name->toString(), $contract['class']) === 0) {
                return $property;
            }
        }

        return null;
    }

    /** @return list<Node\Scalar\String_>|null */
    private static function attributeNames(Node\Attribute $attribute): ?array
    {
        if ($attribute->args === []) {
            return [];
        }
        foreach ($attribute->args as $argument) {
            if ($argument->name !== null || $argument->unpack) {
                return null;
            }
        }
        $first = $attribute->args[0]->value;
        if ($first instanceof Node\Expr\Array_) {
            $names = [];
            foreach ($first->items as $item) {
                if ($item->unpack || $item->byRef || $item->key !== null) {
                    return null;
                }
                if ($item->value instanceof Node\Scalar\String_) {
                    $names[] = $item->value;
                }
            }

            return $names;
        }
        $names = [];
        foreach ($attribute->args as $argument) {
            if (! $argument->value instanceof Node\Scalar\String_) {
                return null;
            }
            $names[] = $argument->value;
        }

        return $names;
    }

    /** @param list<Node\Scalar\String_> $names */
    private static function isTotalGuardNames(array $names): bool
    {
        return count($names) === 1 && $names[0]->value === '*';
    }

    private static function hasNativeAttributeSupport(
        NodeAnalysisContext $context,
        string $model,
        string $propertyName,
    ): bool {
        $contract = self::FIELD_ATTRIBUTES[$propertyName];
        $attribute = $context->codebase->getClass($contract['class']);
        $constructor = $context->codebase->getDeclaringMethod($contract['class'], '__construct');
        if (
            $attribute === null
            || $attribute->hasIncompleteHierarchy()
            || ! self::frameworkFile(
                $attribute->location->file,
                'Illuminate/Database/Eloquent/Attributes/'.ucfirst($propertyName).'.php',
            )
            || ! self::nativeMethod($constructor, [
                'owners' => [$contract['class']],
                'file' => 'Illuminate/Database/Eloquent/Attributes/'.ucfirst($propertyName).'.php',
                'visibility' => Visibility::Public,
            ])
            || ! $constructor?->constructor
            || ! self::nativeMethod(
                $context->codebase->getDeclaringMethod($model, $contract['initializerMethod']),
                $contract['initializer'],
            )
            || ! self::hasNativeDispatch($context, $model, self::ATTRIBUTE_MUTATORS[$propertyName])
            || ! self::nativeModelAttributeLifecycle($context, $model)
        ) {
            return false;
        }

        return true;
    }

    private static function nativeModelAttributeLifecycle(NodeAnalysisContext $context, string $model): bool
    {
        foreach ([
            '__construct' => Visibility::Public,
            'initializeTraits' => Visibility::Protected,
        ] as $method => $visibility) {
            if (! self::nativeMethod($context->codebase->getDeclaringMethod($model, $method), [
                'owners' => [self::MODEL],
                'file' => 'Illuminate/Database/Eloquent/Model.php',
                'visibility' => $visibility,
            ])) {
                return false;
            }
        }
        $resolver = $context->codebase->getDeclaringMethod($model, 'resolveClassAttribute');

        return (
            $resolver !== null
            && $resolver->static
            && $resolver->visibility === Visibility::Protected
            && strcasecmp($resolver->identifier->class ?? '', self::MODEL) === 0
            && self::frameworkFile($resolver->location->file, 'Illuminate/Database/Eloquent/Model.php')
        );
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

        return self::frameworkFile($method->location->file, $contract['file']);
    }

    private static function frameworkFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/'.$suffix);
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
