<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\StringType;
use PhpParser\Node;

/** String leaves from conventional PHP language catalogs; never executes application code. */
final class TranslationStringProvider implements FunctionReturnTypeProvider, InitializationHook
{
    private ?PhpSource $source = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
    }

    public function getTargets(): array
    {
        return [FunctionTarget::exact('trans'), FunctionTarget::exact('__')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $function = $context->codebase->getFunction($call->name);
        $file = str_replace('\\', '/', $function?->location->file ?? '');
        if (! str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php')) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $key = $this->literal($call->getArgument(0, 'key')?->type);
        $locale = $this->literal($call->getArgument(2, 'locale')?->type);
        if (
            $key === null
            || $locale === null
            || ! preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+$/D', $key)
            || ! preg_match('/^[A-Za-z0-9_-]+$/D', $locale)
        ) {
            return null;
        }
        // An existing JSON catalog may take priority even over a dotted PHP key.
        $roots = array_values(array_filter(
            ['lang', 'resources/lang'],
            fn (string $path): bool => is_dir($this->root.'/'.$path),
        ));
        if (count($roots) !== 1 || is_file($this->root.'/'.$roots[0].'/'.$locale.'.json')) {
            return null;
        }
        $parts = explode('.', $key);
        $group = array_shift($parts);
        $path = $roots[0].'/'.$locale.'/'.$group.'.php';
        if (! is_file($this->root.'/'.$path)) {
            return null;
        }
        $this->source ??= new PhpSource($this->root);
        $nodes = $this->source->read($path);
        if ($nodes === null) {
            return null;
        }
        $value = null;
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Return_ && $value === null) {
                $value = $node->expr;
            } elseif (! $node instanceof Node\Stmt\Nop && ! $node instanceof Node\Stmt\Declare_) {
                return null;
            }
        }
        foreach ($parts as $part) {
            if (! $value instanceof Node\Expr\Array_) {
                return null;
            }
            $next = null;
            foreach ($value->items as $item) {
                if ($item->unpack || ! $item->key instanceof Node\Scalar\String_) {
                    return null;
                }
                if ($item->key->value === $part) {
                    $next = $item->value;
                }
            }
            $value = $next;
        }
        if (! $value instanceof Node\Scalar\String_) {
            return null;
        }
        $result = Type::string();
        // Never replace a native or user-declared narrower/incompatible contract.
        if (
            $function?->declaredReturnType === null
            || ! $context->types->isContainedBy($result, $function->declaredReturnType->type)
        ) {
            return null;
        }
        $contract = $function->returnType?->type;
        $atoms = $contract?->atomicTypes ?? [];
        if (count($atoms) === 1 && $atoms[0] instanceof ConditionalType) {
            if ((string) $atoms[0]->target !== 'null' || $atoms[0]->negated) {
                return null;
            }
            $contract = $atoms[0]->otherwise;
        }
        if ($contract === null || ! $context->types->isContainedBy($result, $contract)) {
            return null;
        }

        return $result;
    }

    private function literal(?Type $type): ?string
    {
        $atoms = $type?->atomicTypes ?? [];
        if (count($atoms) !== 1 || ! $atoms[0] instanceof ScalarType || ! $atoms[0]->refinement instanceof StringType) {
            return null;
        }

        return $atoms[0]->refinement->literalValue;
    }
}
