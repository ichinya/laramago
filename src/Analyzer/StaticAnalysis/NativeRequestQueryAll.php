<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\PrettyPrinter\Standard;

/** Verifies the installed Laravel and Symfony implementation without executing it. */
final class NativeRequestQueryAll
{
    private const REQUEST = 'Illuminate\\Http\\Request';
    private const INPUT_BAG = 'Symfony\\Component\\HttpFoundation\\InputBag';
    private const PARAMETER_BAG = 'Symfony\\Component\\HttpFoundation\\ParameterBag';

    private const QUERY_METHOD = <<<'PHP'
        public function query($key = null, $default = null)
        {
            return $this->retrieveItem('query', $key, $default);
        }
        PHP;

    private const RETRIEVE_FIRST_BRANCH = <<<'PHP'
        protected function retrieveItem($source, $key, $default)
        {
            if (is_null($key)) {
                return $this->{$source}->all();
            }
        }
        PHP;

    private readonly PhpSource $source;

    public function __construct(string $root)
    {
        $this->source = new PhpSource($root);
    }

    public function proves(ReturnTypeProviderContext $context, Type $array): bool
    {
        $codebase = $context->codebase;
        $request = $codebase->getClassLike(self::REQUEST);
        $query = $codebase->getDeclaringMethod(self::REQUEST, 'query');
        $retrieve = $codebase->getDeclaringMethod(self::REQUEST, 'retrieveItem');
        $property = $codebase->getDeclaringProperty(self::REQUEST, '$query');
        $all = $codebase->getDeclaringMethod(self::INPUT_BAG, 'all');
        if ($request === null || $query === null || $retrieve === null || $property === null || $all === null) {
            return false;
        }
        if (count($all->parameters) !== 1) {
            return false;
        }

        $verified = [
            ! $request->hasIncompleteHierarchy(),
            $this->sourceFile($request->location->file, '/laravel/framework/src/Illuminate/Http/Request.php'),
            ! $query->static,
            $query->visibility === Visibility::Public,
            $this->sourceFile(
                $query->location->file,
                '/laravel/framework/src/Illuminate/Http/Concerns/InteractsWithInput.php',
            ),
            $query->returnType === null || $context->types->isContainedBy($array, $query->returnType->type),
            ! $retrieve->static,
            $retrieve->visibility === Visibility::Protected,
            $this->sourceFile(
                $retrieve->location->file,
                '/laravel/framework/src/Illuminate/Http/Concerns/InteractsWithInput.php',
            ),
            $property->readVisibility === Visibility::Public,
            (string) $property->declaredType?->type === self::INPUT_BAG,
            ! $all->static,
            $all->visibility === Visibility::Public,
            $all->identifier->class === self::PARAMETER_BAG,
            $all->parameters[0]->flags->contains(MetadataFlags::HAS_DEFAULT),
            (string) $all->declaredReturnType?->type === 'array',
            $this->sourceFile($all->location->file, '/symfony/http-foundation/ParameterBag.php'),
        ];
        if (in_array(false, $verified, true)) {
            return false;
        }

        $reflection = new ModelReflection($codebase, $this->source);

        return $this->matches($reflection, $query, self::QUERY_METHOD)
            && $this->matches($reflection, $retrieve, self::RETRIEVE_FIRST_BRANCH, firstBranchOnly: true);
    }

    private function matches(
        ModelReflection $reflection,
        FunctionLikeMetadata $method,
        string $expected,
        bool $firstBranchOnly = false,
    ): bool {
        $node = $reflection->methodNode($method);
        if (! $node instanceof ClassMethod) {
            return false;
        }
        $copy = clone $node;
        $copy->setAttribute('comments', []);
        if ($firstBranchOnly) {
            $first = $node->stmts[0] ?? null;
            if (! $first instanceof If_) {
                return false;
            }
            $copy->stmts = [$first];
        }
        $printed = (new Standard)->prettyPrint([$copy]);

        // PhpParser preserves quote style; the two spellings are identical here.
        return str_replace('"query"', "'query'", $printed) === $expected;
    }

    private function sourceFile(?string $path, string $suffix): bool
    {
        return $path !== null
            && str_ends_with(
                strtolower(str_replace('\\', '/', $this->source->path($path))),
                strtolower($suffix),
            );
    }
}
