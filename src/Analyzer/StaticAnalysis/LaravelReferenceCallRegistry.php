<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\MethodTarget;
use PhpParser\Node;

/** Audited call shapes shared by the literal view and translation reference hooks. */
final class LaravelReferenceCallRegistry
{
    public const VIEW_MAKE = 'view.make';
    public const VIEW_FIRST = 'view.first';
    public const RESPONSE_VIEW = 'response.view';
    public const TRANSLATION_GET = 'translation.get';

    /** @var array<string, array{method: string, receivers: array{string, string}, parameters: list<string>, allowByRef: bool}> */
    private const CALLS = [
        self::VIEW_MAKE => [
            'method' => 'make',
            'receivers' => ['Illuminate\\Support\\Facades\\View', 'Illuminate\\View\\Factory'],
            'parameters' => ['view', 'data', 'mergeData'],
            'allowByRef' => true,
        ],
        self::VIEW_FIRST => [
            'method' => 'first',
            'receivers' => ['Illuminate\\Support\\Facades\\View', 'Illuminate\\View\\Factory'],
            'parameters' => ['views', 'data', 'mergeData'],
            'allowByRef' => false,
        ],
        self::RESPONSE_VIEW => [
            'method' => 'view',
            'receivers' => ['Illuminate\\Support\\Facades\\Response', 'Illuminate\\Routing\\ResponseFactory'],
            'parameters' => ['view', 'data', 'status', 'headers'],
            'allowByRef' => false,
        ],
        self::TRANSLATION_GET => [
            'method' => 'get',
            'receivers' => ['Illuminate\\Support\\Facades\\Lang', 'Illuminate\\Translation\\Translator'],
            'parameters' => ['key', 'replace', 'locale', 'fallback'],
            'allowByRef' => false,
        ],
    ];

    /** @return non-empty-list<MethodTarget> */
    public static function targets(string $reference): array
    {
        $call = self::CALLS[$reference];

        return [
            MethodTarget::exact($call['receivers'][0], $call['method']),
            MethodTarget::exact($call['receivers'][1], $call['method']),
        ];
    }

    public static function method(string $reference): string
    {
        return self::CALLS[$reference]['method'];
    }

    /**
     * Map literal-call arguments by their native parameter names. Invalid or ambiguous
     * argument shapes defer to Mago; this does not prove a native dispatch target.
     *
     * @return array<string, Node\Expr>|null
     */
    public static function arguments(string $reference, Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?array
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }
        $shape = self::CALLS[$reference];
        $arguments = [];
        $named = false;
        foreach ($call->getArgs() as $offset => $argument) {
            $parameter = $argument->name?->toString() ?? $shape['parameters'][$offset] ?? null;
            if (
                $argument->unpack
                || ! $shape['allowByRef']
                && $argument->byRef
                || $parameter === null
                || ! in_array($parameter, $shape['parameters'], true)
                || isset($arguments[$parameter])
                || $named
                && $argument->name === null
            ) {
                return null;
            }
            $named = $argument->name !== null;
            $arguments[$parameter] = $argument->value;
        }

        return $arguments;
    }
}
