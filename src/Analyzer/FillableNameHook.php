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

/** Validate literal $fillable names only against explicit complete field catalogs. */
final class FillableNameHook implements ClassLikeAnalysisHook
{
    private const NATIVE_FILLABLE_OWNERS = [
        ModelReflection::MODEL,
        'Illuminate\\Database\\Eloquent\\Concerns\\GuardsAttributes',
    ];

    /** @var array<string, array{owners: list<string>, file: string, visibility: Visibility}> */
    private const NATIVE_DISPATCH = [
        'fill' => [
            'owners' => [ModelReflection::MODEL],
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
        if ($class === null || $model === null) {
            return;
        }
        $metadata = $context->codebase->getClass($model);
        if ($metadata === null || $metadata->hasIncompleteHierarchy()) {
            return;
        }
        foreach (self::NATIVE_DISPATCH as $method => $contract) {
            $declaration = $context->codebase->getDeclaringMethod($model, $method);
            if (! self::nativeMethod($declaration, $contract)) {
                return;
            }
        }
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\Property) {
                continue;
            }
            foreach ($statement->props as $property) {
                if (
                    $property->name->toString() !== 'fillable'
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
                foreach ($property->default->items as $item) {
                    if ($item->unpack || ! $item->value instanceof Node\Scalar\String_) {
                        continue;
                    }
                    $name = $item->value->value;
                    if ($this->fields->contains($model, $name) !== false) {
                        continue;
                    }
                    $context->report(
                        Level::Warning,
                        'laramago-missing-model-field',
                        Issue::at(
                            'Fillable name '.$name.' is absent from the complete field catalog for '.$model.'.',
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
