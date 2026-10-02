<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Match the installed native implementation and its effective PHPDoc contract. */
final class NativeModelRefresh
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const FRESH = <<<'PHP'
        public function fresh($with = [])
        {
            if (! $this->exists) { return; }
            return $this->setKeysForSelectQuery($this->newQueryWithoutScopes())
                ->useWritePdo()->with(is_string($with) ? func_get_args() : $with)->first();
        }
        PHP;
    private const REFRESH = <<<'PHP'
        public function refresh()
        {
            if (! $this->exists) { return $this; }
            return $this->refreshUsingQuery($this->newQueryWithoutScopes());
        }
        PHP;

    /** @var array<string, bool> */
    private array $verified = [];

    public function __construct(private readonly string $root) {}

    public function proves(ReturnTypeProviderContext $context, string $name): bool
    {
        if (array_key_exists($name, $this->verified)) {
            return $this->verified[$name];
        }
        return $this->verified[$name] = $this->verify($context, $name);
    }

    private function verify(ReturnTypeProviderContext $context, string $name): bool
    {
        if (! in_array($name, ['fresh', 'refresh'], true)) {
            return false;
        }
        $method = $context->codebase->getDeclaringMethod(self::MODEL, $name);
        if ($method === null || strcasecmp($method->identifier->class ?? '', self::MODEL) !== 0
            || $method->static || $method->abstract || $method->visibility !== Visibility::Public
            || $method->flags->contains(MetadataFlags::BY_REFERENCE) || $method->declaredReturnType !== null
            || $method->templates !== [] || $method->assertions !== [] || $method->ifTrueAssertions !== [] || $method->ifFalseAssertions !== []
            || $method->returnType === null || ! $method->returnType->fromDocblock
            || count($method->parameters) !== ($name === 'fresh' ? 1 : 0)) {
            return false;
        }
        $object = false;
        $nullable = false;
        foreach ($method->returnType->type->atomicTypes as $atomic) {
            if ($atomic instanceof NamedObjectType && ($name === 'refresh' ? $atomic->name === '$this' : strcasecmp($atomic->name, self::MODEL) === 0)
                && ($atomic->parameters ?? []) === [] && ($atomic->intersections ?? []) === []
                && ($name === 'fresh' ? $atomic->static : $atomic->isThis)) {
                $object = true;
            } elseif ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null) {
                $nullable = true;
            } else {
                return false;
            }
        }
        if (! $object || $nullable !== ($name === 'fresh')) {
            return false;
        }
        if ($name === 'fresh') {
            $parameter = $method->parameters[0];
            if ($parameter->name !== '$with' || $parameter->declaredType !== null || $parameter->outType !== null
                || $parameter->closureThisType !== null || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC)) {
                return false;
            }
        }
        $file = str_replace('\\', '/', $method->location->file ?? '');
        if (preg_match('~^//\\?/[A-Za-z]:/~', $file) === 1) {
            $file = substr($file, 4);
        }
        if (! str_ends_with($file, '/laravel/framework/src/Illuminate/Database/Eloquent/Model.php')) {
            return false;
        }
        if (! str_starts_with($file, '/') && preg_match('~^[A-Za-z]:/~', $file) !== 1) {
            $file = $this->root.'/'.$file;
        }
        $size = @filesize($file);
        if ($size === false || $size > 2_000_000) {
            return false;
        }
        $source = @file_get_contents($file);
        if ($source === false || $method->location->span->end > strlen($source)) {
            return false;
        }
        $body = substr($source, $method->location->span->start, $method->location->span->length());
        return self::tokens($body) === self::tokens($name === 'fresh' ? self::FRESH : self::REFRESH);
    }

    private static function tokens(string $source): string
    {
        $parts = [];
        foreach (token_get_all('<?php '.$source) as $token) {
            if (! is_array($token)) {
                $parts[] = $token;
            } elseif (! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $parts[] = $token[1];
            }
        }
        return implode('', $parts);
    }
}
