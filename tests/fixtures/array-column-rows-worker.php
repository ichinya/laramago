<?php

declare(strict_types=1);

require $argv[1];

use Ichinya\Laramago\Analyzer\ArrayColumnRowsProvider;
use Mago\Sdk\Analyzer\{FunctionReturnTypeProvider, FunctionTarget, Plugin, PluginDefinition, PluginRegistry, ReturnTypeProviderContext, Type};
use Mago\Sdk\{Extension, Worker};

$receipt = $argv[2];
$plugin = new class ($receipt) implements Plugin {
    public function __construct(private string $receipt) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('tests/array-column-rows', 'Array rows', 'Real native call controls.'); }
    public function register(PluginRegistry $registry): void
    {
        $registry->registerFunctionReturnTypeProvider(new class ($this->receipt) implements FunctionReturnTypeProvider {
            public function __construct(private string $receipt) {}
            public function getTargets(): array { return [FunctionTarget::exact('array_column')]; }
            public function getReturnType(ReturnTypeProviderContext $context): ?Type
            {
                $result = (new ArrayColumnRowsProvider)->getReturnType($context);
                file_put_contents($this->receipt, json_encode([
                    'line' => $context->invocation->span->start,
                    'input' => (string) $context->invocation->getArgument(0, 'array')?->type,
                    'result' => $result === null ? null : (string) $result,
                ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

                return $result;
            }
        });
    }
};

(new Worker(new Extension(identifier: 'tests/array-column-rows', name: 'Array row controls', version: '1.0.0', analyzerPlugins: [$plugin])))->run();
