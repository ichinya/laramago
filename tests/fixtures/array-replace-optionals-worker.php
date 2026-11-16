<?php

declare(strict_types=1);

require $argv[1];

use Ichinya\Laramago\Analyzer\ArrayReplaceProvider;
use Mago\Sdk\Analyzer\{FunctionReturnTypeProvider, FunctionTarget, Plugin, PluginDefinition, PluginRegistry, ReturnTypeProviderContext, Type};
use Mago\Sdk\{Extension, Worker};

$plugin = new class ($argv[2]) implements Plugin {
    public function __construct(private string $receipt) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('tests/array-replace-optionals', 'Optional replacements', 'Actual SDK return-type decisions.'); }
    public function register(PluginRegistry $registry): void
    {
        $registry->registerFunctionReturnTypeProvider(new class ($this->receipt) implements FunctionReturnTypeProvider {
            public function __construct(private string $receipt) {}
            public function getTargets(): array { return [FunctionTarget::exact('array_replace')]; }
            public function getReturnType(ReturnTypeProviderContext $context): ?Type
            {
                $result = (new ArrayReplaceProvider)->getReturnType($context);
                file_put_contents($this->receipt, json_encode([
                    'offset' => $context->invocation->span->start,
                    'result' => $result === null ? null : (string) $result,
                ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

                return $result;
            }
        });
    }
};

(new Worker(new Extension(identifier: 'tests/array-replace-optionals', name: 'Optional replacements', version: '1.0.0', analyzerPlugins: [$plugin])))->run();
