<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\InstalledMethodTokens;
use Mago\Sdk\Analyzer\{InvocationKind, MethodReturnTypeProvider, MethodTarget, ReturnTypeProviderContext, Type};
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Supplied patterns select Laravel's boolean branch; the getter keeps its native type. */
final class ApplicationEnvironmentProvider implements MethodReturnTypeProvider
{
    private const APPLICATION = 'Illuminate\\Foundation\\Application';
    private const STR_IS = <<<'PHP'
        public static function is($pattern, $value, $ignoreCase = false)
        {
            $value = (string) $value;
            if (! is_iterable($pattern)) { $pattern = [$pattern]; }
            foreach ($pattern as $pattern) {
                $pattern = (string) $pattern;
                if ($pattern === '*' || $pattern === $value) { return true; }
                if ($ignoreCase && mb_strtolower($pattern) === mb_strtolower($value)) { return true; }
                $pattern = preg_quote($pattern, '#');
                $pattern = str_replace('\*', '.*', $pattern);
                if (preg_match('#^'.$pattern.'\z#'.($ignoreCase ? 'isu' : 'su'), $value) === 1) { return true; }
            }
            return false;
        }
        PHP;
    private readonly InstalledMethodTokens $native;
    public function __construct(string $root) { $this->native = new InstalledMethodTokens($root); }
    public function getTargets(): array { return [MethodTarget::exact(self::APPLICATION, 'environment')]; }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $object = $call->receiverType?->atomicTypes[0] ?? null;
        if ($call->kind !== InvocationKind::InstanceMethod || ! $object instanceof NamedObjectType
            || count($call->receiverType->atomicTypes) !== 1 || $call->arguments === []) { return null; }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder || $argument->name !== null) { return null; }
        }
        if (! $this->native->proves($context->codebase, $object->name, 'environment', self::APPLICATION,
            '/laravel/framework/src/Illuminate/Foundation/Application.php',
            'public function environment(...$environments) { if ($environments !== []) { $patterns = is_array($environments[0]) ? $environments[0] : $environments; return Str::is($patterns, $this[\'env\']); } return $this[\'env\']; }')) { return null; }
        if (! $this->native->references($context->codebase, $object->name, 'environment', 'Illuminate\\Support\\Str', 'is')
            || ! $this->native->proves($context->codebase, 'Illuminate\\Support\\Str', 'is', 'Illuminate\\Support\\Str',
                '/laravel/framework/src/Illuminate/Support/Str.php', self::STR_IS)) { return null; }
        return Type::bool();
    }
}
