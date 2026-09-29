<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Accept extra string keys when every required documented return field is proven. */
final class OpenArrayShapeReturnFilter implements IssueFilterHook, InitializationHook
{
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_MESSAGE_BYTES = 16 * 1024;

    /** @var array<string, list<Node>> */
    private array $parsed = [];

    public function initialize(InitializationContext $context): void
    {
        $this->parsed = [];
    }

    public function getCodes(): array
    {
        return ['invalid-return-statement'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if (
            $context->issue->code !== 'invalid-return-statement'
            || strlen($context->contents) > self::MAX_FILE_BYTES
            || strlen($context->issue->message) > self::MAX_MESSAGE_BYTES
        ) {
            return IssueFilterDecision::Keep;
        }
        if (! preg_match(
            '/^Invalid return type for function `([^`]+)`: expected `(array\{[^`]+\})`, but found `(array\{[^`]+\})`\.$/sD',
            $context->issue->message,
            $match,
        )) {
            return IssueFilterDecision::Keep;
        }
        $expected = self::shape($match[2], false);
        $found = self::shape($match[3], true);
        if ($expected === null || $found === null || ! $found['open'] || $expected['items'] === []) {
            return IssueFilterDecision::Keep;
        }
        $primary = $this->primary($context);
        if ($primary === null || ! $this->isReturnExpression($context, $primary->span->start, $primary->span->end)) {
            return IssueFilterDecision::Keep;
        }
        $declared = $this->declaredShape($context, $match[1]);
        if ($declared === null || count($declared->knownItems ?? []) !== count($expected['items'])) {
            return IssueFilterDecision::Keep;
        }
        foreach ($declared->knownItems ?? [] as $item) {
            if ($item->optional || $item->key->kind === ArrayKeyKind::ClassLikeConstant) {
                return IssueFilterDecision::Keep;
            }
            $key = ($item->key->kind === ArrayKeyKind::String ? 's:' : 'i:').$item->key->value;
            if (
                ! isset($expected['items'][$key], $found['items'][$key])
                || ! $this->sameType($expected['items'][$key], $item->type, $context)
            ) {
                return IssueFilterDecision::Keep;
            }
            $foundType = $this->valueType($found['items'][$key]);
            if ($foundType === null || $this->unsafeMixed($foundType, $item->type)
                || ! $context->types->isContainedBy($foundType, $item->type)) {
                return IssueFilterDecision::Keep;
            }
        }

        return IssueFilterDecision::Remove;
    }

    private function primary(IssueFilterContext $context): ?\Mago\Sdk\Reporting\Annotation
    {
        $primary = null;
        foreach ($context->issue->annotations as $annotation) {
            if ($annotation->kind !== AnnotationKind::Primary) {
                continue;
            }
            if ($primary !== null || $annotation->file !== null && $annotation->file !== '') {
                return null;
            }
            $primary = $annotation;
        }

        return $primary;
    }

    private function isReturnExpression(IssueFilterContext $context, int $start, int $end): bool
    {
        if ($start < 0 || $end <= $start || $end > strlen($context->contents)) {
            return false;
        }
        $key = hash('sha256', $context->file."\0".$context->contents);
        if (! isset($this->parsed[$key])) {
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? [];
            } catch (Error) {
                return false;
            }
            if (count($this->parsed) >= 16) {
                unset($this->parsed[array_key_first($this->parsed)]);
            }
            $this->parsed[$key] = (new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Return_::class);
        }
        foreach ($this->parsed[$key] as $return) {
            if (
                $return->expr !== null
                && $return->expr->getStartFilePos() === $start
                && $return->expr->getEndFilePos() + 1 === $end
            ) {
                return true;
            }
        }

        return false;
    }

    private function declaredShape(IssueFilterContext $context, string $target): ?KeyedArrayType
    {
        if (str_contains($target, '::')) {
            [$class, $method] = explode('::', $target, 2);
            if ($class === '' || $method === '') {
                return null;
            }
            $metadata = $context->codebase->getMethod($class, $method)
                ?? $context->codebase->getDeclaringMethod($class, $method);
        } else {
            $metadata = $context->codebase->getFunction($target);
        }
        $return = $metadata?->returnType?->type;
        if ($return === null || count($return->atomicTypes) !== 1) {
            return null;
        }
        $array = $return->atomicTypes[0];

        return $array instanceof KeyedArrayType && $array->keyType === null && $array->valueType === null
            ? $array
            : null;
    }

    /** @return array{items: array<string, string>, open: bool}|null */
    private static function shape(string $expression, bool $allowOpen): ?array
    {
        if (! str_starts_with($expression, 'array{') || ! str_ends_with($expression, '}')) {
            return null;
        }
        $body = substr($expression, 6, -1);
        $parts = trim($body) === '' ? [] : self::split($body, ',');
        if ($parts === null) {
            return null;
        }
        $items = [];
        $open = false;
        foreach ($parts as $index => $part) {
            if ($part === '...<string, mixed>') {
                if (! $allowOpen || $index !== count($parts) - 1) {
                    return null;
                }
                $open = true;
                continue;
            }
            $pair = self::split($part, ':');
            if ($pair === null || count($pair) !== 2) {
                return null;
            }
            $key = null;
            if (preg_match("/^'([A-Za-z_][A-Za-z0-9_]*)'$/D", $pair[0], $matches)) {
                $key = 's:'.$matches[1];
            } elseif (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $pair[0])) {
                $key = 'i:'.$pair[0];
            }
            if ($key === null || isset($items[$key])) {
                return null;
            }
            $items[$key] = $pair[1];
        }

        return ['items' => $items, 'open' => $open];
    }

    private function unsafeMixed(Type $found, Type $expected): bool
    {
        foreach ($found->atomicTypes as $atom) {
            if ($atom instanceof MixedType) {
                $allowed = false;
                foreach ($expected->atomicTypes as $expectedAtom) {
                    $allowed = $allowed || $expectedAtom instanceof MixedType;
                }
                if (! $allowed) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sameType(string $description, Type $declared, IssueFilterContext $context): bool
    {
        $parsed = $this->valueType($description);

        return $parsed !== null
            && $context->types->isContainedBy($parsed, $declared)
            && $context->types->isContainedBy($declared, $parsed);
    }

    private function valueType(string $expression, int $depth = 0): ?Type
    {
        if ($depth > 12 || strlen($expression) > 4096) {
            return null;
        }
        $parts = self::split($expression, '|');
        if ($parts === null) {
            return null;
        }
        if (count($parts) > 1) {
            $result = null;
            foreach ($parts as $part) {
                $value = $this->valueType($part, $depth + 1);
                if ($value === null) {
                    return null;
                }
                $result = $result === null ? $value : Type::union($result, $value);
            }

            return $result;
        }
        $type = match ($expression) {
            'int' => Type::int(),
            'string' => Type::string(),
            'float' => Type::float(),
            'bool' => Type::bool(),
            'true' => Type::true(),
            'false' => Type::false(),
            'null' => Type::null(),
            'mixed' => Type::mixed(),
            'non-negative-int' => Type::nonNegativeInt(),
            default => null,
        };
        if ($type !== null) {
            return $type;
        }
        if (preg_match('/^int\((-?(?:0|[1-9][0-9]*))\)$/D', $expression, $match)
            && (string) (int) $match[1] === $match[1]) {
            return Type::literalInt((int) $match[1]);
        }
        if (preg_match("/^string\\('([^'\\\\]*)'\\)$/D", $expression, $match)) {
            return Type::literalString($match[1]);
        }
        if (preg_match('/^array<(.*)>$/sD', $expression, $match)) {
            $arguments = self::split($match[1], ',');
            if ($arguments === null || count($arguments) !== 2) {
                return null;
            }
            $key = $this->valueType($arguments[0], $depth + 1);
            $value = $this->valueType($arguments[1], $depth + 1);

            return $key !== null && $value !== null ? Type::array($key, $value) : null;
        }
        if (preg_match('/^list<(.*)>$/sD', $expression, $match)) {
            $value = $this->valueType($match[1], $depth + 1);

            return $value === null ? null : Type::list($value);
        }
        return null;
    }

    /** @return list<string>|null */
    private static function split(string $expression, string $delimiter): ?array
    {
        $parts = [];
        $stack = [];
        $quote = null;
        $escape = false;
        $start = 0;
        $closers = ['<' => '>', '{' => '}', '(' => ')', '[' => ']'];
        for ($index = 0, $length = strlen($expression); $index < $length; ++$index) {
            $char = $expression[$index];
            if ($quote !== null) {
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif (isset($closers[$char])) {
                $stack[] = $closers[$char];
                if (count($stack) > 16) {
                    return null;
                }
            } elseif (in_array($char, $closers, true)) {
                if (array_pop($stack) !== $char) {
                    return null;
                }
            } elseif ($char === $delimiter && $stack === []) {
                $parts[] = trim(substr($expression, $start, $index - $start));
                $start = $index + 1;
            }
        }
        $parts[] = trim(substr($expression, $start));

        return $stack === [] && $quote === null && ! in_array('', $parts, true) ? $parts : null;
    }
}
